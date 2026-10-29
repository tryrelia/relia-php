<?php

// Relia (tryrelia.com). Everything comes from .env; `php artisan relia:install` writes the lines.
return [
    'project_id' => env('RELIA_PROJECT_ID', ''),
    'key' => env('RELIA_KEY', ''),
    'service_name' => env('RELIA_SERVICE_NAME'),
    'traces_endpoint' => env('RELIA_OTLP_ENDPOINT'),
    'environment' => env('RELIA_ENV', env('APP_ENV', 'production')),
    'logs' => env('RELIA_LOGS', true),
    'log_level' => env('RELIA_LOG_LEVEL', 'warning'),
    'release' => env('RELIA_RELEASE'),

    // what is recorded (each can be switched off)
    'queries' => env('RELIA_QUERIES', true),
    'http_client' => env('RELIA_HTTP_CLIENT', true),
    'queue' => env('RELIA_QUEUE', true),

    // the browser part (@reliaScript in your layout): sessions, page speed, errors in the browser
    'api_url' => env('RELIA_API_URL', 'https://devreliaapi.tryrelia.com'),
];
