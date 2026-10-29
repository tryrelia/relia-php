<?php

declare(strict_types=1);

namespace Relia\Laravel;

use Illuminate\Console\Command;

/**
 * php artisan relia:install — writes the Relia lines to .env (asks for the project id and key shown on the project's
 * Setup tab in Relia, or takes --project / --key), then says how to add the browser part. Existing RELIA_* lines are
 * replaced, nothing else in .env is touched.
 */
final class InstallCommand extends Command
{
    protected $signature = 'relia:install {--project= : Relia project id} {--key= : the project key (relia_live_…)} {--service= : name shown in Relia}';
    protected $description = 'Connect this Laravel app to Relia (writes RELIA_* to .env)';

    public function handle(): int
    {
        $this->info('◆ Relia for Laravel');
        $project = (string) ($this->option('project') ?: $this->ask('Project id (Relia → your project → Setup)'));
        if (!preg_match('/^[0-9a-f]{24}$/i', $project)) {
            $this->error('That is not a Relia project id (24 letters and digits, on the project\'s Setup tab).');
            return self::FAILURE;
        }
        $key = (string) ($this->option('key') ?: $this->ask('Project key (relia_live_…, same place; Enter to skip)', ''));
        if ($key !== '' && !str_starts_with($key, 'relia_')) {
            $this->error('A project key starts with relia_live_.');
            return self::FAILURE;
        }
        $service = (string) ($this->option('service') ?: \Relia\Config::slug((string) config('app.name', 'laravel')));

        $env = $this->laravel->environmentFilePath();
        $lines = array_filter(['RELIA_PROJECT_ID' => $project, 'RELIA_KEY' => $key, 'RELIA_SERVICE_NAME' => $service]);
        self::writeEnv($env, $lines);
        $this->line("  ✔ Wrote " . implode(', ', array_keys($lines)) . ' to ' . basename($env));
        if (file_exists($example = $this->laravel->basePath('.env.example'))) {
            self::writeEnv($example, ['RELIA_PROJECT_ID' => $project, 'RELIA_KEY' => '', 'RELIA_SERVICE_NAME' => $service]);
        }

        $this->newLine();
        $this->line('  Server side: done. Requests, errors, database queries, outgoing calls, queue jobs and');
        $this->line('  warning / error logs reach Relia on the next request. Add the same RELIA_* values to your');
        $this->line('  host\'s environment (Forge, Vapor, Cloud, cPanel…) when you deploy.');
        $this->newLine();
        $this->line('  Browser side (sessions, page speed, browser errors), one of:');
        if (file_exists($this->laravel->basePath('resources/js/app.js')) || file_exists($this->laravel->basePath('resources/js/app.ts'))) {
            $this->line('    • Vite: npm install relia-sdk, then in resources/js/app.js:');
            $this->line('        import { Relia } from "relia-sdk"');
            // the same line Relia's AI-assistant setup gives, so both setup paths write the same code
            $this->line('        Relia.init({ project_id: "' . $project . '", service_name: "' . $service . '-web", captureText: false, replay: false'
                . ($key ? ', key: "' . $key . '"' : '') . ' })');
            $this->line("      (the page must load it: @vite('resources/js/app.js') in the Blade layout)");
        }
        $this->line('    • or put @reliaScript in your main Blade layout, before </head>.');
        $this->newLine();
        $this->line('  Who is signed in: the Laravel user id is sent by itself (never the e-mail). To add their plan:');
        $this->line('  \Relia\Relia::setUser(auth()->id(), $user->plan) in a middleware.');
        return self::SUCCESS;
    }

    /** @param array<string, string> $values */
    public static function writeEnv(string $file, array $values): void
    {
        $text = file_exists($file) ? (string) file_get_contents($file) : '';
        foreach ($values as $k => $v) {
            $line = $k . '=' . (preg_match('/[\s#"]/', $v) ? '"' . addcslashes($v, '"\\') . '"' : $v);
            $pattern = '/^' . preg_quote($k, '/') . '=.*$/m';
            $text = preg_match($pattern, $text) ? (string) preg_replace($pattern, $line, $text) : rtrim($text, "\n") . "\n" . $line . "\n";
        }
        file_put_contents($file, ltrim($text, "\n"));
    }
}
