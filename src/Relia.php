<?php

declare(strict_types=1);

namespace Relia;

/**
 * Relia for PHP.
 *
 * Plain PHP: one line at the top of the front controller (index.php) records every request (route, status, time),
 * uncaught errors and fatals, and sends them after the response:
 *
 *     require __DIR__ . '/vendor/autoload.php';
 *     \Relia\Relia::start(['serviceName' => 'shop']);   // RELIA_PROJECT_ID / RELIA_KEY from the environment
 *
 * Laravel needs nothing: the package's service provider does it (php artisan relia:install writes the .env lines).
 */
final class Relia
{
    public const VERSION = '0.1.0';

    private static ?Tracer $tracer = null;
    private static bool $webStarted = false;

    /** @param array<string, mixed> $options Config properties (projectId, key, serviceName, tracesEndpoint, …) */
    public static function init(array $options = [], ?string $appDir = null, ?Exporter $exporter = null): Tracer
    {
        $config = Config::fromEnv($options);
        $dir = $appDir ?? (getcwd() ?: __DIR__);
        self::$tracer = new Tracer($config, $exporter ?? new Exporter($config, $dir));
        return self::$tracer;
    }

    public static function tracer(): ?Tracer
    {
        return self::$tracer;
    }

    /** Plain PHP: init + record this web request until the script ends. */
    public static function start(array $options = [], ?string $appDir = null): ?Tracer
    {
        $tracer = self::init($options, $appDir);
        if (!$tracer->config()->enabled || PHP_SAPI === 'cli' || self::$webStarted) {
            return $tracer;
        }
        self::$webStarted = true;
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (int) ($_SERVER['REQUEST_TIME_FLOAT'] * 1_000_000_000) : 0;
        $root = $tracer->begin("$method $path", Span::SERVER, $_SERVER['HTTP_TRACEPARENT'] ?? null, $start);
        $root->set(self::requestAttributes($method, $path));

        $previous = set_exception_handler(null);
        set_exception_handler(static function (\Throwable $e) use ($previous): void {
            self::captureException($e);
            if ($previous) {
                $previous($e);
            } else {
                http_response_code(500);
                throw $e;
            }
        });
        register_shutdown_function(static function () use ($tracer): void {
            $err = error_get_last();
            $root = $tracer->root();
            if ($root && $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                if (!$root->error) {  // an uncaught exception already said why, in its own words
                    $root->fail(Mask::text(strtok($err['message'], "\n") . ' in ' . $err['file'] . ':' . $err['line'], 500));
                }
                $root->set(['http.response.status_code' => 500]);
            }
            self::endRequest(http_response_code() ?: 200);
        });
        return $tracer;
    }

    /** @return array<string, string> */
    public static function requestAttributes(string $method, string $path): array
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return array_filter([
            'http.request.method' => $method,
            'url.path' => $path,
            'url.scheme' => $scheme,
            'server.address' => (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''),
            'client.address' => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent.original' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        ]);
    }

    /** End the web request's span with its status and send everything (after the response when possible). */
    public static function endRequest(int $status): void
    {
        $t = self::$tracer;
        if (!$t || !$t->root()) {
            return;
        }
        $root = $t->root();
        $root->set(['http.response.status_code' => $status]);
        if ($status >= 500) {
            $root->fail($root->statusMessage ?: "HTTP $status");
        }
        // the visitor has the page; sending to Relia costs them nothing (PHP-FPM, or LiteSpeed on shared hosting).
        // php -S / artisan serve can't do this: there the send (≤ the 2 s timeout) happens before the response ends.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        $t->finish();
    }

    public static function captureException(\Throwable $e): void
    {
        $root = self::$tracer?->root();
        if ($root && !$root->events) {  // one exception per request: the first is the cause
            $root->recordException($e);
        }
    }

    /** Who made this request: an id and a plan only, never an e-mail (Relia shows plans, not people, to AI). */
    public static function setUser(string|int|null $id, ?string $plan = null): void
    {
        self::$tracer?->root()?->set(['relia.user_id' => $id === null ? null : (string) $id, 'relia.user_plan' => $plan]);
    }

    /** The W3C traceparent for an outgoing call made during this request (joins the callee's trace). */
    public static function traceparent(): ?string
    {
        $t = self::$tracer;
        return ($t && $t->root()) ? sprintf('00-%s-%s-01', $t->traceId(), $t->currentSpanId()) : null;
    }

    /**
     * Time a piece of work as its own span: Relia::measure('charge card', fn () => $stripe->charge(...)).
     * @template T @param callable(): T $fn @return T
     */
    public static function measure(string $name, callable $fn): mixed
    {
        $span = self::$tracer?->child($name, Span::INTERNAL);
        try {
            return $fn();
        } catch (\Throwable $e) {
            $span?->recordException($e);
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /** For tests and long-running workers: forget the current tracer. */
    public static function reset(): void
    {
        self::$tracer = null;
        self::$webStarted = false;
    }
}
