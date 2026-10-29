<?php

declare(strict_types=1);

namespace Relia;

/**
 * Collects one request's (or job's) spans in memory and hands them to the exporter at the end. PHP serves one request
 * per process at a time, so there is no cross-request state: `begin` starts a trace, `finish` sends it.
 */
final class Tracer
{
    /** @var list<Span> */
    private array $spans = [];
    /** @var list<array{time: int, severity: int, level: string, body: string, attributes: array<string, string>}> */
    private array $logs = [];
    private ?Span $root = null;
    private string $traceId = '';

    public function __construct(private Config $config, private Exporter $exporter)
    {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function root(): ?Span
    {
        return $this->root;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    /** The current span id to hand to the next hop (traceparent), or ''. */
    public function currentSpanId(): string
    {
        return $this->root?->spanId ?? '';
    }

    /**
     * Start a trace with its root span. A W3C `traceparent` from the caller (Relia's browser SDK sends one) joins
     * the caller's trace, so a click and the request it made are one story in Relia.
     */
    public function begin(string $name, int $kind, ?string $traceparent = null, int $startNs = 0): Span
    {
        $this->spans = [];
        $this->logs = [];
        $parent = '';
        $this->traceId = Span::id(16);
        if ($traceparent && preg_match('/^[\da-f]{2}-([\da-f]{32})-([\da-f]{16})-[\da-f]{2}$/i', trim($traceparent), $m)
            && $m[1] !== str_repeat('0', 32)) {
            $this->traceId = strtolower($m[1]);
            $parent = strtolower($m[2]);
        }
        $this->root = new Span($name, $kind, $this->traceId, Span::id(8), $parent, $startNs);
        $this->spans[] = $this->root;
        return $this->root;
    }

    /** A child of the root span: a query, an outgoing call, a piece of work. */
    public function child(string $name, int $kind, int $startNs = 0): ?Span
    {
        if (!$this->root) {
            return null; // outside a request or job: not recorded (like the other SDKs' background calls)
        }
        if (count($this->spans) >= 1000) {
            return null; // a runaway loop shouldn't take the app's memory
        }
        $span = new Span($name, $kind, $this->traceId, Span::id(8), $this->root->spanId, $startNs);
        $this->spans[] = $span;
        return $span;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->config->logs || !self::atLeast($level, $this->config->logLevel) || count($this->logs) >= 200) {
            return;
        }
        $attrs = ['log.level' => strtoupper($level)];
        $e = $context['exception'] ?? null;
        if ($e instanceof \Throwable) {  // where it came from: the log viewer shows the code location
            $attrs['exception.type'] = $e::class;
            $attrs['code.filepath'] = $e->getFile();
            $attrs['code.lineno'] = (string) $e->getLine();
        }
        $this->logs[] = ['time' => Span::now(), 'severity' => self::severity($level), 'level' => strtoupper($level),
                         'body' => Mask::text($message), 'attributes' => $attrs];
    }

    /** End everything still open and send it. Safe to call twice. */
    public function finish(): void
    {
        if (!$this->root) {
            return;
        }
        $now = Span::now();
        foreach ($this->spans as $s) {
            $s->end($now);
        }
        $spans = $this->spans;
        $logs = $this->logs;
        $traceId = $this->traceId;
        $rootId = $this->root->spanId;
        $this->root = null;
        $this->spans = [];
        $this->logs = [];
        if ($this->config->enabled) {
            $this->exporter->send($spans, $logs, $traceId, $rootId);
        }
    }

    private const LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];

    public static function atLeast(string $level, string $min): bool
    {
        return (self::LEVELS[strtolower($level)] ?? 0) >= (self::LEVELS[strtolower($min)] ?? 3);
    }

    private static function severity(string $level): int
    {
        return match (strtolower($level)) {
            'debug' => 5, 'info' => 9, 'notice' => 10, 'warning' => 13, 'error' => 17, 'critical' => 18, 'alert' => 19,
            default => 21,
        };
    }
}
