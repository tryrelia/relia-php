<?php

declare(strict_types=1);

namespace Relia;

/**
 * Sends a finished request's spans (and its WARN+ log lines) to Relia as OTLP/HTTP JSON, after the response has gone
 * to the visitor when the server allows it (fastcgi_finish_request), with a short timeout. Never throws: a Relia
 * outage must not break the app.
 */
class Exporter
{
    /** @var array<string, string> */
    private array $resource;

    public function __construct(private Config $config, string $appDir)
    {
        $host = gethostname() ?: 'unknown';
        $version = Release::version($appDir, $config->release ?: null);
        $this->resource = array_filter([
            'service.name' => $config->serviceName,
            'relia.project_id' => $config->projectId,
            'host.name' => $host,
            'os.type' => PHP_OS_FAMILY,
            'app.env' => $config->environment,
            'deployment.environment.name' => $config->environment,
            'service.version' => $version,
            // PHP workers come and go per request: one stable id per host + build, so a deploy is a new version
            // and a worker recycling is never mistaken for a restart
            'service.instance.id' => substr(sha1($host . '|' . $version . '|' . $config->serviceName), 0, 16),
            'process.runtime.name' => 'php',
            'process.runtime.version' => PHP_VERSION,
            'telemetry.sdk.name' => 'relia',
            'telemetry.sdk.language' => 'php',
            'telemetry.sdk.version' => Relia::VERSION,
        ], static fn ($v) => $v !== '');
    }

    /** @param list<Span> $spans */
    public function send(array $spans, array $logs, string $traceId, string $rootSpanId): void
    {
        try {
            $this->post($this->config->tracesEndpoint, $this->tracesPayload($spans));
            if ($logs) {
                $this->post($this->config->logsEndpoint(), $this->logsPayload($logs, $traceId, $rootSpanId));
            }
        } catch (\Throwable) {
            // never break the app
        }
    }

    /** @param list<Span> $spans @return array<string, mixed> */
    public function tracesPayload(array $spans): array
    {
        return ['resourceSpans' => [[
            'resource' => ['attributes' => self::attrs($this->resource)],
            'scopeSpans' => [[
                'scope' => ['name' => 'relia-php', 'version' => Relia::VERSION],
                'spans' => array_map(static fn (Span $s) => array_filter([
                    'traceId' => $s->traceId,
                    'spanId' => $s->spanId,
                    'parentSpanId' => $s->parentSpanId ?: null,
                    'name' => $s->name,
                    'kind' => $s->kind,
                    'startTimeUnixNano' => (string) $s->startNs,
                    'endTimeUnixNano' => (string) max($s->endNs, $s->startNs),
                    'attributes' => self::attrs($s->attributes),
                    'events' => array_map(static fn ($e) => ['timeUnixNano' => (string) $e['time'], 'name' => $e['name'],
                                                             'attributes' => self::attrs($e['attributes'])], $s->events) ?: null,
                    'status' => $s->error ? ['code' => 2, 'message' => $s->statusMessage] : ['code' => 0],
                ], static fn ($v) => $v !== null), $spans),
            ]],
        ]]];
    }

    /** @return array<string, mixed> */
    public function logsPayload(array $logs, string $traceId, string $spanId): array
    {
        return ['resourceLogs' => [[
            'resource' => ['attributes' => self::attrs($this->resource)],
            'scopeLogs' => [[
                'scope' => ['name' => 'relia-php', 'version' => Relia::VERSION],
                'logRecords' => array_map(static fn ($l) => [
                    'timeUnixNano' => (string) $l['time'],
                    'severityNumber' => $l['severity'],
                    'severityText' => $l['level'],
                    'body' => ['stringValue' => $l['body']],
                    'attributes' => self::attrs($l['attributes']), // the project is a resource attribute
                    'traceId' => $traceId,
                    'spanId' => $spanId,
                ], $logs),
            ]],
        ]]];
    }

    /** @param array<string, mixed> $attrs @return list<array<string, mixed>> */
    private static function attrs(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $value = match (true) {
                is_bool($v) => ['boolValue' => $v],
                is_int($v) => ['intValue' => (string) $v],
                is_float($v) => ['doubleValue' => $v],
                default => ['stringValue' => (string) $v],
            };
            $out[] = ['key' => (string) $k, 'value' => $value];
        }
        return $out;
    }

    /** @param array<string, mixed> $payload */
    protected function post(string $url, array $payload): void
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers = ['Content-Type: application/json'];
        if ($this->config->key !== '') {
            $headers[] = 'X-Relia-Key: ' . $this->config->key;
        }
        if (function_exists('gzencode') && strlen($body) > 1024) {
            $body = (string) gzencode($body, 6);
            $headers[] = 'Content-Encoding: gzip';
        }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => (int) ($this->config->timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => 1000, CURLOPT_NOSIGNAL => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
            return;
        }
        @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => $this->config->timeout, 'ignore_errors' => true,
        ]]));
    }
}
