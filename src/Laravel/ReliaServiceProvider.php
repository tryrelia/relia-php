<?php

declare(strict_types=1);

namespace Relia\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Relia\Mask;
use Relia\Relia;
use Relia\Span;
use Relia\Tracer;

/**
 * Relia for Laravel, picked up automatically (package discovery). With RELIA_PROJECT_ID in .env it records every
 * request (route template, status, time, the signed-in user's id), database queries (values masked), calls made with
 * Laravel's HTTP client, queue jobs, WARN+ log lines and the exception behind a 500 — and sends them after the
 * response. No middleware to add, no code to change.
 */
final class ReliaServiceProvider extends ServiceProvider
{
    /** @var array<int, Span> outgoing calls in flight, by request object */
    private array $calls = [];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/relia.php', 'relia');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
            $this->publishes([__DIR__ . '/../../config/relia.php' => config_path('relia.php')], 'relia-config');
        }
        Blade::directive('reliaScript', static fn () => '<?php echo \\Relia\\Laravel\\BrowserScript::tag(); ?>');

        $c = $this->app['config']->get('relia', []);
        $tracer = Relia::init(array_filter([
            'projectId' => (string) ($c['project_id'] ?? ''),
            'key' => (string) ($c['key'] ?? ''),
            'serviceName' => $c['service_name'] ?: \Relia\Config::slug((string) $this->app['config']->get('app.name', 'laravel')),
            'tracesEndpoint' => $c['traces_endpoint'] ?? null,
            'environment' => (string) ($c['environment'] ?? 'production'),
            'logs' => filter_var($c['logs'] ?? true, FILTER_VALIDATE_BOOL),
            'logLevel' => (string) ($c['log_level'] ?? 'warning'),
            'release' => $c['release'] ?? null,
        ], static fn ($v) => $v !== null && $v !== ''), $this->app->basePath());
        if (!$tracer->config()->enabled) {
            return;
        }

        $events = $this->app['events'];
        if (!$this->app->runningInConsole()) {
            $this->startRequest($tracer);
            $events->listen(RequestHandled::class, fn (RequestHandled $e) => $this->endRequest($tracer, $e));
            $this->app->terminating(static fn () => $tracer->finish());
        }
        $events->listen(MessageLogged::class, static function (MessageLogged $e) use ($tracer): void {
            if (($e->context['exception'] ?? null) instanceof \Throwable) {
                Relia::captureException($e->context['exception']);
            }
            $tracer->log($e->level, $e->message, $e->context);
        });
        if (filter_var($c['queries'] ?? true, FILTER_VALIDATE_BOOL)) {
            $events->listen(QueryExecuted::class, static fn (QueryExecuted $q) => self::query($tracer, $q));
        }
        if (filter_var($c['http_client'] ?? true, FILTER_VALIDATE_BOOL)) {
            $this->httpClient($tracer);
        }
        if (filter_var($c['queue'] ?? true, FILTER_VALIDATE_BOOL)) {
            $this->queue($tracer);
        }
    }

    private function startRequest(Tracer $tracer): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
        $start = defined('LARAVEL_START') ? (int) (LARAVEL_START * 1_000_000_000)
            : (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (int) ($_SERVER['REQUEST_TIME_FLOAT'] * 1_000_000_000) : 0);
        $tracer->begin("$method $path", Span::SERVER, $_SERVER['HTTP_TRACEPARENT'] ?? null, $start)
            ->set(Relia::requestAttributes($method, $path));
    }

    private function endRequest(Tracer $tracer, RequestHandled $e): void
    {
        $root = $tracer->root();
        if (!$root) {
            return;
        }
        $route = $e->request->route();
        $template = $route && method_exists($route, 'uri') ? '/' . ltrim((string) $route->uri(), '/') : null;
        if ($template !== null) {  // "/orders/{id}": one row per route in Relia, never one per id
            $root->name = $e->request->getMethod() . ' ' . $template;
            $root->set(['http.route' => $template]);
        }
        $status = $e->response->getStatusCode();
        $root->set(['http.response.status_code' => $status]);
        if ($status >= 500) {
            $root->fail($root->statusMessage ?: "HTTP $status");
        }
        try {  // who: an id only; never forces a database lookup (hasUser)
            $guard = $this->app['auth']->guard();
            if ($guard->hasUser()) {
                Relia::setUser($guard->id());
            }
        } catch (\Throwable) {
        }
    }

    private const DB_SYSTEMS = ['mysql' => 'mysql', 'mariadb' => 'mariadb', 'pgsql' => 'postgresql', 'sqlite' => 'sqlite',
                                'sqlsrv' => 'mssql', 'mongodb' => 'mongodb'];

    private static function query(Tracer $tracer, QueryExecuted $q): void
    {
        $ms = (float) $q->time;
        $span = $tracer->child('query', Span::CLIENT, Span::now() - (int) ($ms * 1_000_000));
        if (!$span) {
            return;
        }
        $driver = $q->connection->getDriverName();
        $sql = Mask::statement($q->sql);
        $span->name = strtoupper(strtok(ltrim($sql), " \n\t(") ?: 'QUERY');
        $span->set([
            'db.system' => self::DB_SYSTEMS[$driver] ?? $driver,
            'db.name' => $q->connection->getDatabaseName(),
            'db.statement' => $sql,
            'server.address' => (string) ($q->connection->getConfig('host') ?? ''),
        ]);
        $span->end();
    }

    private function httpClient(Tracer $tracer): void
    {
        $events = $this->app['events'];
        if (class_exists(\Illuminate\Support\Facades\Http::class)
            && method_exists(\Illuminate\Http\Client\Factory::class, 'globalRequestMiddleware')) {
            // the called service joins this request's trace (Relia SDKs there read traceparent)
            \Illuminate\Support\Facades\Http::globalRequestMiddleware(static function ($request) {
                $tp = Relia::traceparent();
                return $tp ? $request->withHeader('traceparent', $tp) : $request;
            });
        }
        $events->listen(RequestSending::class, function (RequestSending $e) use ($tracer): void {
            $url = (string) $e->request->url();
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
            $span = $tracer->child($e->request->method() . ' ' . $host, Span::CLIENT);
            $span?->set(['http.request.method' => $e->request->method(), 'url.full' => Mask::url($url), 'server.address' => $host]);
            if ($span) {
                $this->calls[spl_object_id($e->request)] = $span;
            }
        });
        $events->listen(ResponseReceived::class, function (ResponseReceived $e): void {
            $span = $this->calls[spl_object_id($e->request)] ?? null;
            unset($this->calls[spl_object_id($e->request)]);
            if ($span) {
                $status = $e->response->status();
                $span->set(['http.response.status_code' => $status]);
                if ($status >= 500) {
                    $span->fail("HTTP $status");
                }
                $span->end();
            }
        });
        $events->listen(ConnectionFailed::class, function (ConnectionFailed $e): void {
            $span = $this->calls[spl_object_id($e->request)] ?? null;
            unset($this->calls[spl_object_id($e->request)]);
            if ($span) {
                $span->fail('connection failed');
                $span->end();
            }
        });
    }

    private function queue(Tracer $tracer): void
    {
        $events = $this->app['events'];
        $events->listen(JobProcessing::class, static function (JobProcessing $e) use ($tracer): void {
            $tracer->begin('job ' . $e->job->resolveName(), Span::CONSUMER)->set([
                'messaging.system' => $e->connectionName, 'messaging.destination.name' => $e->job->getQueue(),
                'messaging.operation' => 'process', 'relia.job' => $e->job->resolveName(),
            ]);
        });
        $events->listen(JobProcessed::class, static fn () => $tracer->finish());
        $events->listen(JobFailed::class, static function (JobFailed $e) use ($tracer): void {
            $tracer->root()?->recordException($e->exception);
            $tracer->finish();
        });
    }
}
