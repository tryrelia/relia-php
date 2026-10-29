# Relia for PHP and Laravel

[Relia](https://tryrelia.com) watches your product (users, APIs, database, servers) and tells you in plain words what is
wrong, who it affects and how to fix it. This package sends your PHP app's requests, errors, database queries, outgoing
calls, queue jobs and warning / error logs to Relia.

Pure PHP 8.1+, no extension, no dependencies: it works on shared hosting too. Data is sent after the response.

## Laravel

```
composer require tryrelia/relia
php artisan relia:install
```

`relia:install` asks for the project id and key (your project's **Setup** tab in Relia) and writes them to `.env`.
Nothing else: the package is discovered automatically. Browser part: `@reliaScript` in your main Blade layout, or
`npm install relia-sdk` with Vite (`relia:install` prints the lines). Or run `npx relia-sdk@latest init`, which does
all of it.

## Plain PHP

```php
require __DIR__ . '/vendor/autoload.php';
\Relia\Relia::start(['projectId' => 'your project id', 'serviceName' => 'shop']);
```

## API

- `Relia::setUser($id, $plan = null)`: who made this request (an id and a plan, never an e-mail).
- `Relia::measure('name', fn () => …)`: time a piece of work as its own step.
- `Relia::traceparent()`: the header value to send on an outgoing call so the other service joins the trace.

## Environment

`RELIA_PROJECT_ID`, `RELIA_KEY`, `RELIA_SERVICE_NAME`, `RELIA_ENV`, `RELIA_RELEASE`, `RELIA_LOGS`, `RELIA_LOG_LEVEL`,
`RELIA_OTLP_ENDPOINT`; Laravel also `RELIA_QUERIES`, `RELIA_HTTP_CLIENT`, `RELIA_QUEUE`.

Query values are masked before they leave your server; log text loses e-mails, card numbers and secrets.

## Development

```
composer install
vendor/bin/phpunit
```
