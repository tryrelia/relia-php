<?php

declare(strict_types=1);

namespace Relia;

/**
 * Settings, from the same environment variables as Relia's Node and Python SDKs (RELIA_*), overridable in code
 * (Relia::start([...])) or Laravel's config/relia.php.
 */
final class Config
{
    public const DEFAULT_TRACES = 'https://devotel.tryrelia.com/v1/traces';

    public function __construct(
        public string $projectId = '',
        public string $key = '',
        public string $serviceName = 'php-app',
        public string $tracesEndpoint = self::DEFAULT_TRACES,
        public string $environment = 'production',
        public bool $enabled = true,
        public bool $logs = true,
        public string $logLevel = 'warning',
        public string $release = '',
        public float $timeout = 2.0,
    ) {
    }

    /** @param array<string, mixed> $overrides */
    public static function fromEnv(array $overrides = []): self
    {
        $env = static fn (string $k): string => trim((string) (getenv($k) ?: ($_ENV[$k] ?? $_SERVER[$k] ?? '')));
        $endpoint = $env('RELIA_OTLP_ENDPOINT') ?: self::DEFAULT_TRACES;
        if (!str_ends_with(rtrim($endpoint, '/'), '/v1/traces')) {
            $endpoint = rtrim($endpoint, '/') . '/v1/traces';
        }
        $off = static fn (string $v): bool => in_array(strtolower($v), ['0', 'false', 'off', 'no'], true);
        $c = new self(
            projectId: $env('RELIA_PROJECT_ID'),
            key: $env('RELIA_KEY'),
            serviceName: $env('RELIA_SERVICE_NAME') ?: ($env('APP_NAME') ? self::slug($env('APP_NAME')) : 'php-app'),
            tracesEndpoint: $endpoint,
            environment: $env('RELIA_ENV') ?: ($env('APP_ENV') ?: 'production'),
            enabled: !$off($env('RELIA_ENABLED')) && $env('RELIA_TRACING_DISABLED') === '',
            logs: !$off($env('RELIA_LOGS')),
            logLevel: strtolower($env('RELIA_LOG_LEVEL') ?: 'warning'),
            release: $env('RELIA_RELEASE'),
        );
        foreach ($overrides as $k => $v) {
            if ($v !== null && $v !== '' && property_exists($c, $k)) {
                $c->{$k} = $v;
            }
        }
        if ($c->projectId === '') {
            $c->enabled = false; // nothing to send to
        }
        return $c;
    }

    public function logsEndpoint(): string
    {
        return preg_replace('#/v1/traces$#', '/v1/logs', rtrim($this->tracesEndpoint, '/')) ?? $this->tracesEndpoint;
    }

    public static function slug(string $name): string
    {
        $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        return $s !== '' ? $s : 'php-app';
    }
}
