# Laravel Telemetry Log

Structured telemetry for Laravel. Ships events straight to your log channels, so whatever log driver you use (stack, Axiom, Better Stack, Papertrail, stderr) doubles as your observability pipeline.

Today this is a request logging middleware. The package is structured to grow additional sensors (queued jobs, scheduled tasks, artisan commands, cache, mail, exceptions) as sibling features under the same config root.

## Installation

```bash
composer require devtime-ltd/laravel-observability-log
```

## Request logging middleware

`LogRequest` logs structured request data (method, URL, status, duration, query stats, memory) to one or more log channels.

### Setup

Register the middleware in `bootstrap/app.php`:

```php
use DevtimeLtd\LaravelObservabilityLog\LogRequest;

->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(LogRequest::class);
})
```

Set the channel to log to:

```env
LOG_REQUESTS_CHANNEL=axiom
```

Can also pass multiple channels (e.g. `axiom,betterstack`) for logging to multiple providers. Leave unset to disable, the middleware is a no-op without this env var.

> **Note:** Logging happens inline in `handle()` rather than `terminate()`. We found `terminate()` didn't fire in all hosting setups.

### Logged fields

| Field            | Description                                 |
| ---------------- | ------------------------------------------- |
| `method`         | HTTP method                                 |
| `url`            | Full URL                                    |
| `path`           | Request path                                |
| `route`          | Named route (if any)                        |
| `route_params`   | Route parameters (raw values, pre-binding)  |
| `status`         | Response status code                        |
| `content_type`   | Response Content-Type                       |
| `response_size`  | Response body size in bytes                 |
| `user_id`        | Authenticated user ID (null if guest)       |
| `ip`             | Client IP (supports obfuscation, see below) |
| `user_agent`     | User agent string                           |
| `referer`        | Referer header                              |
| `duration_ms`    | Total request time in milliseconds          |
| `memory_peak_mb` | Peak memory usage                           |
| `query_count`    | Number of database queries                  |
| `query_total_ms` | Total time spent in database queries        |
| `slow_queries`   | Queries exceeding the slow query threshold  |

### Options

Publish the config file with `php artisan vendor:publish --tag=observability-log`. Options live under `config/observability-log.php` in the `requests` section.

#### Log message

The log message used for request entries. Default: `'http.request'`.

```php
'requests' => [
    'message' => env('LOG_REQUESTS_MESSAGE', 'http.request'),
],
```

This makes it easy to distinguish request logs from application logs (e.g. `Log::error()`) when they share the same log destination.

You can also override the message at runtime via `LogRequest::message()` in your `AppServiceProvider::boot()`, either a fixed string or a callback:

```php
use DevtimeLtd\LaravelObservabilityLog\LogRequest;

// Fixed string
LogRequest::message('api.request');

// Dynamic based on request
LogRequest::message(function ($request, $response) {
    return $request->is('api/*') ? 'api.request' : 'web.request';
});
```

This takes precedence over the config value. Pass `null` to revert to the config default.

#### Log level

The PSR-3 log level that request entries are logged at. Default: `'info'`.

```php
'requests' => [
    'level' => env('LOG_REQUESTS_LEVEL', 'info'),
],
```

#### Database query collection

Disable query measurement to skip the `DB::listen()` overhead:

```php
'requests' => [
    'collect_queries' => false,
],
```

This omits `query_count`, `query_total_ms`, and `slow_queries` from the log entry. Default: `true`.

#### Slow query threshold

Threshold in milliseconds for capturing slow queries:

```php
'requests' => [
    'slow_query_threshold' => 500,
],
```

Set to `null` to disable slow query collection while still tracking `query_count` and `query_total_ms`. Default: `100`.

#### IP obfuscation

Mask client IPs using the built-in `ObfuscateIp` class. Supports four levels.

| Level | IPv4 example (`198.51.100.123`) | IPv6  |
| ----- | ------------------------------- | ----- |
| 1     | `198.51.100.0`                  | `/96` |
| 2     | `198.51.0.0`                    | `/64` |
| 3     | `198.0.0.0`                     | `/32` |
| 4     | `0.0.0.0`                       | `::`  |

```php
use DevtimeLtd\LaravelObservabilityLog\ObfuscateIp;

'requests' => [
    'obfuscate_ip' => ObfuscateIp::level(2),
],
```

You can also pass any callable for custom masking:

```php
'requests' => [
    'obfuscate_ip' => fn (?string $ip) => 'redacted',
],
```

Default: `false` (no masking).

### Extending log entries

Use `LogRequest::extend()` to add project-specific fields, or overwrite default ones. Call this in your `AppServiceProvider::boot()`:

```php
use DevtimeLtd\LaravelObservabilityLog\LogRequest;

LogRequest::extend(function ($request, $response, $entry) {
    $entry['tenant_id'] = $request->header('X-Tenant-ID');
    return $entry;
});
```

### Custom log entry

If you wish to completely replace the default log entry fields with your own, use `LogRequest::using()`. The callback receives the request, response, and a measurements array:

```php
use DevtimeLtd\LaravelObservabilityLog\LogRequest;

LogRequest::using(function ($request, $response, $measurements) {
    return [
        'method' => $request->method(),
        'path' => $request->path(),
        'status' => $response?->getStatusCode(),
        'duration_ms' => $measurements['duration_ms'],
    ];
});
```

`$measurements` contains the collected metrics based on config: `duration_ms`, `memory_peak_mb`, and when query collection is enabled, `query_count`, `query_total_ms`, `slow_queries`.

Note that `extend()` runs after `using()`, so you can utilise both in a request lifecycle.

## Testing

```bash
composer test
```

## License

MIT
