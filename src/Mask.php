<?php

declare(strict_types=1);

namespace Relia;

/** Values never leave the app: query literals become `?`, and log text loses e-mails, card numbers and secrets. */
final class Mask
{
    private const MAX = 4000;

    public static function statement(string $sql): string
    {
        $text = substr($sql, 0, self::MAX);
        $t = ltrim($text);
        if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
            return (string) preg_replace('/:\s*("(?:[^"\\\\]|\\\\.)*"|-?\d[\d.eE+-]*|true|false|null)/', ':?', $text);
        }
        return (string) preg_replace(
            "/'(?:[^'\\\\]|\\\\.|'')*'|\\b0x[0-9a-f]+\\b|(?<![\\w$:@.])-?\\d+(?:\\.\\d+)?(?:e[+-]?\\d+)?\\b/i",
            '?',
            $text,
        );
    }

    public static function text(string $text, int $max = 2000): string
    {
        $text = substr($text, 0, $max);
        $text = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[email]', $text);
        $text = (string) preg_replace('/\b(?:\d{4}[- ]?){3}\d{4}\b/', '[card]', $text);
        $text = (string) preg_replace(
            '/\b(password|passwd|secret|token|api[_-]?key|authorization)\b(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i',
            '$1$2[masked]',
            $text,
        );
        return (string) preg_replace('/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}\b|\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', '[masked]', $text);
    }

    /** A URL without its query string (tokens live there) and without credentials. */
    public static function url(string $url): string
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['host'])) {
            return strtok($url, '?') ?: $url;
        }
        return ($p['scheme'] ?? 'http') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '');
    }
}
