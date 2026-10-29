<?php

declare(strict_types=1);

namespace Relia;

/** One unit of work (a request, a query, an outgoing call, a job) in OpenTelemetry's shape. */
final class Span
{
    public const SERVER = 2;
    public const CLIENT = 3;
    public const PRODUCER = 4;
    public const CONSUMER = 5;
    public const INTERNAL = 1;

    /** @var array<string, string|int|float|bool> */
    public array $attributes = [];
    /** @var list<array{name: string, time: int, attributes: array<string, string|int|float|bool>}> */
    public array $events = [];
    public int $endNs = 0;
    public bool $error = false;
    public string $statusMessage = '';

    public function __construct(
        public string $name,
        public int $kind,
        public string $traceId,
        public string $spanId,
        public string $parentSpanId = '',
        public int $startNs = 0,
    ) {
        $this->startNs = $startNs ?: self::now();
    }

    public static function now(): int
    {
        return (int) (microtime(true) * 1_000_000_000);
    }

    public static function id(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** @param array<string, mixed> $attrs */
    public function set(array $attrs): self
    {
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $this->attributes[$k] = is_scalar($v) ? $v : (string) json_encode($v);
        }
        return $this;
    }

    public function end(?int $endNs = null): void
    {
        if ($this->endNs === 0) {
            $this->endNs = $endNs ?? self::now();
        }
    }

    public function fail(string $message = ''): void
    {
        $this->error = true;
        $this->statusMessage = substr($message, 0, 500);
    }

    public function recordException(\Throwable $e): void
    {
        $this->fail(Mask::text($e->getMessage(), 500));
        $this->events[] = ['name' => 'exception', 'time' => self::now(), 'attributes' => [
            'exception.type' => $e::class,
            'exception.message' => Mask::text($e->getMessage(), 1000),
            'exception.stacktrace' => self::stack($e),
        ]];
    }

    /** "Type: message" then the frames, innermost first, like other runtimes: Relia finds the app frame in it. */
    private static function stack(\Throwable $e): string
    {
        $lines = [$e::class . ': ' . Mask::text($e->getMessage(), 500), '    at ' . $e->getFile() . ':' . $e->getLine()];
        foreach (array_slice($e->getTrace(), 0, 40) as $f) {
            $fn = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
            $lines[] = '    at ' . ($fn !== '' ? $fn . ' ' : '') . '(' . ($f['file'] ?? '[internal]') . ':' . ($f['line'] ?? 0) . ')';
        }
        return implode("\n", $lines);
    }
}
