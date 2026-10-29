<?php

declare(strict_types=1);

namespace Relia\Laravel;

/**
 * `@reliaScript` in a Blade layout: Relia's browser SDK (sessions, page speed, browser errors, and the link from a
 * click to the request it made). Loaded from jsDelivr as an ES module. Apps built with Vite can instead
 * `npm install relia-sdk` and call Relia.init in resources/js/app.js (relia:install prints the lines).
 */
final class BrowserScript
{
    public static function tag(): string
    {
        $pid = (string) config('relia.project_id', '');
        if ($pid === '') {
            return '';
        }
        $init = json_encode([
            'endpoint' => rtrim((string) config('relia.api_url', 'https://devreliaapi.tryrelia.com'), '/') . '/api/v1',
            'project_id' => $pid,
            'key' => (string) config('relia.key', ''),
            'captureText' => false,
            'replay' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return '<script type="module">import { Relia } from "https://cdn.jsdelivr.net/npm/relia-sdk@0/+esm"; Relia.init(' . $init . ');</script>';
    }
}
