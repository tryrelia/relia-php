<?php

declare(strict_types=1);

namespace Relia;

/**
 * Which build is running, so Relia tells deploys apart with no setup (same order as the Node / Python SDKs):
 * RELIA_RELEASE / SERVICE_VERSION / OTEL_SERVICE_VERSION, a CI / host commit, the checked-out commit from .git,
 * the version in composer.json.
 */
final class Release
{
    private const VERSION_ENV = ['RELIA_RELEASE', 'SERVICE_VERSION', 'OTEL_SERVICE_VERSION'];
    private const COMMIT_ENV = [
        'GITHUB_SHA', 'VERCEL_GIT_COMMIT_SHA', 'RENDER_GIT_COMMIT', 'HEROKU_SLUG_COMMIT', 'SOURCE_VERSION',
        'CF_PAGES_COMMIT_SHA', 'RAILWAY_GIT_COMMIT_SHA', 'CI_COMMIT_SHA', 'BITBUCKET_COMMIT', 'GIT_COMMIT',
        'FORGE_SITE_BRANCH_COMMIT', 'LARAVEL_CLOUD_COMMIT',
    ];

    private static ?string $cached = null;

    public static function version(string $dir, ?string $explicit = null): string
    {
        if ($explicit) {
            return $explicit;
        }
        if (self::$cached !== null) {
            return self::$cached;
        }
        foreach (self::VERSION_ENV as $k) {
            if (($v = trim((string) getenv($k))) !== '') {
                return self::$cached = $v;
            }
        }
        foreach (self::COMMIT_ENV as $k) {
            if (($v = trim((string) getenv($k))) !== '') {
                return self::$cached = self::short($v);
            }
        }
        return self::$cached = (self::gitHead($dir) ?: self::composerVersion($dir));
    }

    public static function reset(): void
    {
        self::$cached = null;
    }

    private static function short(string $sha): string
    {
        return preg_match('/^[0-9a-f]{12,40}$/i', $sha) ? substr($sha, 0, 12) : $sha;
    }

    private static function gitHead(string $dir): string
    {
        $d = $dir;
        for ($i = 0; $i < 6 && $d !== ''; $i++) {
            $head = @file_get_contents($d . '/.git/HEAD');
            if ($head !== false) {
                $head = trim($head);
                if (!str_starts_with($head, 'ref: ')) {
                    return self::short($head);
                }
                $ref = substr($head, 5);
                $sha = @file_get_contents($d . '/.git/' . $ref);
                if ($sha !== false) {
                    return self::short(trim($sha));
                }
                $packed = @file($d . '/.git/packed-refs') ?: [];
                foreach ($packed as $line) {
                    if (str_ends_with(rtrim($line), ' ' . $ref)) {
                        return self::short(strtok($line, ' ') ?: '');
                    }
                }
                return '';
            }
            $parent = dirname($d);
            if ($parent === $d) {
                break;
            }
            $d = $parent;
        }
        return '';
    }

    private static function composerVersion(string $dir): string
    {
        $json = @file_get_contents($dir . '/composer.json');
        $data = $json ? json_decode($json, true) : null;
        return is_array($data) && is_string($data['version'] ?? null) ? $data['version'] : '';
    }
}
