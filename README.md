# Laravel Observability Log

A set of sensors that emit structured events through Laravel log channels. Whatever log driver you use (stack, Axiom, Better Stack, Papertrail, stderr) doubles as your observability pipeline.

Ships three sensors today (`RequestSensor`, `ExceptionSensor`, `JobSensor`), with more on the [roadmap](#roadmap).

## Design philosophy

- **Lean defaults.** Entries ship with method, path, status, duration, user, ip, trace_id.
- **Opinionated top-level keys.** `scheme`, `host`, `query_string`, `action`, `user_agent`, `referer` are promoted to the top level for simpler filtering.
- **Richer collection via config.** Full header capture and structured stack traces with argument values are opt-in.

## Installation

```bash
composer require devtime-ltd/laravel-observability-log
```

## Quick start

Enable the whole package with one env var:

```env
OBSERVABILITY_LOG_CHANNEL=axiom
```

This tells every sensor to log to the `axiom` channel. Set comma-separated values (e.g. `axiom,betterstack`) to log to multiple channels via `Log::stack()`. To log to different channels per sensor, publish the config and set each sensor's `channel` key to a literal value or a dedicated env var of your choosing.

### Enabling and disabling sensors

Each sensor's `channel` key is its on/off switch. Setting `observability-log.{requests,exceptions,jobs}.channel` to `null` (or leaving the env var unset and the config blank) makes that sensor a no-op — entries are never built or emitted. (The underlying queue and exception listeners stay registered; they just short-circuit.) So if you want HTTP request and exception logging but not job logging, publish the config and set:

```php
'jobs' => [
    'channel' => null,
    // ...
],
```

Then register the request middleware in `bootstrap/app.php`:

```php
use DevtimeLtd\LaravelObservabilityLog\RequestSensor;

->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(RequestSensor::class);
})
```

`ExceptionSensor` is registered automatically; no bootstrap change required.

Publish the config file to customise defaults:

```bash
php artisan vendor:publish --tag=observability-log
```

## Request sensor

`RequestSensor` is a middleware that logs structured request data (method, URL, status, duration, DB query stats, memory) to one or more log channels.

The channel is driven by the package-level `OBSERVABILITY_LOG_CHANNEL` env var from the [Quick start](#quick-start) section. Leave it unset to disable the middleware; it is a no-op without a resolved channel. To log this sensor to a different channel than the rest of the package, publish the config and edit `observability-log.requests.channel`.

### Logged fields

| Field                | Description                                                          |
| -------------------- | -------------------------------------------------------------------- |
| `method`             | HTTP method                                                          |
| `url`                | Full URL                                                             |
| `scheme`             | `http` or `https`                                                    |
| `host`               | Host header value                                                    |
| `path`               | Request path                                                         |
| `query_string`       | Raw query string without the leading `?`, omitted when empty         |
| `route`              | Named route (if any)                                                 |
| `route_params`       | Route parameters (raw values, pre-binding)                           |
| `action`             | Controller action or closure marker, omitted when unavailable        |
| `status`             | Response status code                                                 |
| `content_type`       | Response Content-Type                                                |
| `response_size`      | Response body size in bytes                                          |
| `user_id`            | Authenticated user ID (null if guest)                                |
| `ip`                 | Client IP (supports obfuscation, see below)                          |
| `user_agent`         | User-Agent header value                                              |
| `referer`            | Referer header value                                                 |
| `duration_ms`        | Total request time in milliseconds                                   |
| `memory_peak_mb`     | Peak memory usage                                                    |
| `db_query_count`     | Number of database queries                                           |
| `db_query_total_ms`  | Total time spent in database queries                                 |
| `db_slow_queries`    | Queries exceeding the slow query threshold                           |
| `headers`            | Full header map when capture is enabled (see [Header capture](#header-capture)) |
| `trace_id`           | Correlation id when resolvable (see [Trace ID](#trace-id))           |

### Options

Options live under `config/observability-log.php` in the `requests` section.

#### Log message

The log message used for request entries. Default: `'http.request'`.

```php
'requests' => [
    'message' => 'http.request',
],
```

Override at runtime via `RequestSensor::message()` in your `AppServiceProvider::boot()`, either a fixed string or a callback:

```php
use DevtimeLtd\LaravelObservabilityLog\RequestSensor;

// Fixed string
RequestSensor::message('api.request');

// Dynamic based on request
RequestSensor::message(function ($request, $response) {
    return $request->is('api/*') ? 'api.request' : 'web.request';
});
```

Pass `null` to revert to the config default.

#### Log level

The PSR-3 log level that request entries are logged at. Default: `'info'`. All PSR-3 levels (`debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`) are valid.

```php
'requests' => [
    'level' => 'debug',
],
```

#### Database query collection

Disable query measurement to skip the `DB::listen()` overhead:

```php
'requests' => [
    'collect_queries' => false,
],
```

This omits `db_query_count`, `db_query_total_ms`, and `db_slow_queries` from the log entry. Default: `true`.

#### Slow query threshold

Threshold in milliseconds for capturing slow queries:

```php
'requests' => [
    'slow_query_threshold' => 500,
],
```

Set to `null` to disable slow query collection while still tracking `db_query_count` and `db_query_total_ms`. Default: `100`.

#### Slow queries per-request cap

```php
'requests' => [
    'slow_queries_max_count' => 100,
],
```

Limits how many slow queries are captured in a single request. When the cap is hit, a truncation marker (`['truncated' => 'N more slow queries dropped']`) is appended to `db_slow_queries`. Default: `100`. Set to `null` (or `0`) to disable.

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

Use `RequestSensor::extend()` to add project-specific fields, or overwrite default ones. Call this in your `AppServiceProvider::boot()`:

```php
use DevtimeLtd\LaravelObservabilityLog\RequestSensor;

RequestSensor::extend(function ($request, $response, $entry) {
    $entry['tenant_id'] = $request->header('X-Tenant-ID');
    return $entry;
});
```

### Custom log entry

To completely replace the default entry fields with your own, use `RequestSensor::using()`. The callback receives the request, response, and a measurements array:

```php
use DevtimeLtd\LaravelObservabilityLog\RequestSensor;

RequestSensor::using(function ($request, $response, $measurements) {
    return [
        'method' => $request->method(),
        'path' => $request->path(),
        'status' => $response?->getStatusCode(),
        'duration_ms' => $measurements['duration_ms'],
    ];
});
```

`$measurements` contains the collected metrics based on config: `duration_ms`, `memory_peak_mb`, and when query collection is enabled, `db_query_count`, `db_query_total_ms`, `db_slow_queries`.

`extend()` runs after `using()`, so you can use both.

## Exception sensor

`ExceptionSensor` hooks Laravel's exception reporter (`$handler->reportable(...)`) and emits a structured entry for every unhandled exception that Laravel would normally report. Registration is automatic through the service provider.

The channel is driven by the package-level `OBSERVABILITY_LOG_CHANNEL` env var. Leave it unset to disable this sensor. To log exceptions to a different channel than request entries, publish the config and edit `observability-log.exceptions.channel`.

> **PII note:** `message` and each entry in `previous[]` is logged verbatim. Some exceptions embed user data (e.g. `PDOException` on a `UNIQUE` constraint may contain `Duplicate entry 'alice@example.com' for key 'users_email'`). Strip or hash via `ExceptionSensor::extend()` if needed.

### Logged fields

| Field       | Description                                                      |
| ----------- | ---------------------------------------------------------------- |
| `class`     | Fully-qualified exception class                                  |
| `message`   | Exception message                                                |
| `file`      | File where the exception was thrown                              |
| `line`      | Line number in that file                                         |
| `code`      | Exception code                                                   |
| `trace`     | Stack trace (see [Trace format](#trace-format))                  |
| `previous`  | Up to 3 previous exceptions as `[class, message]`, when present  |
| `method`    | HTTP method, when a request is bound                             |
| `url`       | Full URL, when a request is bound                                |
| `route`     | Named route, when a request is bound                             |
| `user_id`   | Authenticated user ID, when a request is bound                   |
| `ip`        | Client IP, when a request is bound                               |
| `command`   | CLI command name, when running in console                        |
| `headers`   | Full header map when header capture is enabled                   |
| `trace_id`  | Correlation id when resolvable                                   |

### Trace format

By default `trace` is a multi-line string from `getTraceAsString()`: every frame present, argument values replaced by their types.

Config controls:

```php
'exceptions' => [
    'trace' => true,                    // false to omit the field
    'trace_args' => false,              // true: structured array with arg values
    'trace_args_max_frames' => 50,      // null to disable the cap
    'trace_max_bytes' => 16384,         // null to disable the cap
    'previous_max_depth' => 3,          // null: unbounded, 0: omit, positive: cap
],
```

`trace_args` requires `zend.exception_ignore_args=Off` in `php.ini`; PHP strips arg values from `Throwable::getTrace()` when it is `On` (the PHP 7.4+ default).

When `trace_max_bytes` is hit, the string is cut at the last frame boundary and a `... [truncated at N bytes]` marker is appended. When `trace_args_max_frames` is hit, a `['truncated' => 'after N frames']` marker is appended to the array.

### Ignore list

Suppress specific exception classes (subclasses are matched via `is_a()`):

```php
'exceptions' => [
    'ignore' => [
        \Illuminate\Auth\AuthenticationException::class,
        \App\Exceptions\KnownBenignException::class,
    ],
],
```

### Log level

Default: `'error'`. All PSR-3 levels are valid.

```php
'exceptions' => [
    'level' => 'critical',
],
```

### Customising the entry

`ExceptionSensor` mirrors `RequestSensor`'s callback surface:

```php
use DevtimeLtd\LaravelObservabilityLog\ExceptionSensor;

// Replace the default entry
ExceptionSensor::using(fn (Throwable $e) => [
    'class' => get_class($e),
    'message' => $e->getMessage(),
    'fingerprint' => md5($e->getFile().$e->getLine()),
]);

// Add fields on top of the default entry
ExceptionSensor::extend(function (Throwable $e, array $entry) {
    $entry['git_sha'] = env('GIT_SHA');
    return $entry;
});

// Customise the log message
ExceptionSensor::message(fn (Throwable $e) => 'error.'.class_basename($e));
```

## Job sensor

`JobSensor` listens to Laravel's queue lifecycle events and emits two structured entries:

- `job.queued` when a job is dispatched (one entry per dispatch)
- `job.attempt` when a worker finishes — or fails — a single attempt (one entry per attempt, regardless of how it ended)

Registration is automatic through the service provider; no `bootstrap/app.php` change required.

The channel is driven by the package-level `OBSERVABILITY_LOG_CHANNEL` env var. Leaving that unset disables every sensor; to disable only `JobSensor`, publish the config and set `observability-log.jobs.channel` to null. To log jobs to a different channel than the rest of the package, set `observability-log.jobs.channel` to a literal value.

> **Trace correlation:** `Context::add('trace_id', ...)` propagates across queue serialization, so a `trace_id` set during a request automatically appears on every job dispatched from that request and on every attempt of those jobs. See [Trace ID](#trace-id).

### Logged fields

#### `job.queued`

| Field          | Description                                                  |
| -------------- | ------------------------------------------------------------ |
| `class`        | Fully-qualified job class (or string form for raw queueing)  |
| `queue`        | Queue name                                                   |
| `connection`   | Queue connection name                                        |
| `job_id`       | Driver-assigned job id, when available                       |
| `payload_size` | Serialized payload size in bytes                             |
| `delay`        | Delay in seconds, omitted when zero or null                  |
| `trace_id`     | Correlation id when resolvable                               |

#### `job.attempt`

| Field               | Description                                                            |
| ------------------- | ---------------------------------------------------------------------- |
| `class`             | Resolved job class (handles wrapped/closure jobs)                      |
| `queue`             | Queue name                                                             |
| `connection`        | Queue connection                                                       |
| `job_id`            | Job id                                                                 |
| `attempt`           | Attempt number (1, 2, …)                                               |
| `max_tries`         | Configured max attempts, omitted when null                             |
| `status`            | `processed` or `failed`                                                |
| `duration_ms`       | Wall-clock time for the attempt                                        |
| `memory_peak_mb`    | Peak memory at attempt end                                             |
| `db_query_count`    | Queries during the attempt, when query collection is on                |
| `db_query_total_ms` | Total query time during the attempt                                    |
| `db_slow_queries`   | Slow queries above threshold                                           |
| `exception`         | `{class, message, file, line, code}` when the attempt failed           |
| `trace_id`          | Correlation id when resolvable                                         |

### Options

Options live under `config/observability-log.php` in the `jobs` section.

```php
'jobs' => [
    'channel' => env('OBSERVABILITY_LOG_CHANNEL'),
    'level' => 'info',
    'queued_message' => 'job.queued',
    'attempt_message' => 'job.attempt',
    'collect_queries' => true,
    'slow_query_threshold' => 100,
    'slow_queries_max_count' => 100,
],
```

`collect_queries`, `slow_query_threshold`, and `slow_queries_max_count` behave the same as on the request sensor, scoped per attempt.

### Failed attempts

Each attempt produces exactly one `job.attempt` entry whether it succeeds, throws (and may retry), throws on the final attempt, or is failed manually via `$job->fail()`. `JobExceptionOccurred` and `JobFailed` are deduplicated so you never see two entries for the same attempt.

If a job throws and is permanently failed, you'll typically also see an `error.exception` entry from `ExceptionSensor` — the queue worker still reports the exception through Laravel's exception handler. Both entries share `trace_id` when one is resolvable.

### Customising the entry

`JobSensor::using/extend/message` mirror the other sensors. Callbacks receive the underlying queue event so you can branch on event type:

```php
use DevtimeLtd\LaravelObservabilityLog\JobSensor;
use Illuminate\Queue\Events\JobQueued;

// Add fields on top of the default entry (queued and attempt)
JobSensor::extend(function ($event, array $entry) {
    $entry['env'] = app()->environment();
    return $entry;
});

// Replace the default entry. $measurements is empty for JobQueued and
// includes duration_ms / memory_peak_mb / db_* for attempt events.
JobSensor::using(function ($event, array $measurements) {
    if ($event instanceof JobQueued) {
        return [
            'fingerprint' => is_object($event->job) ? get_class($event->job) : (string) $event->job,
        ];
    }

    return [
        'class' => $event->job->resolveName(),
        'duration_ms' => $measurements['duration_ms'],
    ];
});

// Customise the log message
JobSensor::message(fn ($event) => $event instanceof JobQueued ? 'queue.dispatched' : 'queue.attempted');
```

## Header capture

Off by default on both sensors; typical payload adds 1 to 3 KB per entry. Enable for the whole package:

```env
OBSERVABILITY_LOG_CAPTURE_HEADERS=true
```

To enable on only one sensor, publish the config and toggle `capture_headers` on that section alone.

When enabled, headers are emitted under a `headers` key with lowercase names:

```json
"headers": {
    "accept": "application/json",
    "x-request-id": "abc-123",
    "authorization": "[redacted]",
    "cookie": "[redacted]"
}
```

### Redaction

Sensitive header values are replaced with the literal string `[redacted]` (the key is kept so queries can still filter on presence). Default redact list:

- `authorization`
- `cookie`
- `set-cookie`
- `proxy-authorization`
- `x-csrf-token`
- `x-xsrf-token`
- `x-api-key`
- `x-auth-token`
- `x-access-token`
- `x-session-token`

Matching is case-insensitive. Extend by editing the top-level `redact_headers` config array:

```php
// config/observability-log.php
'redact_headers' => [
    'authorization',
    'cookie',
    'set-cookie',
    'proxy-authorization',
    'x-csrf-token',
    'x-xsrf-token',
    'x-api-key',
    'x-auth-token',
    'x-access-token',
    'x-session-token',
    'x-internal-signing-key',
],
```

### Header value size cap

Captured header values are truncated at `observability-log.header_value_max_length` bytes (default 8192). Set to `null` or `0` to disable. Multi-value headers have each value capped independently.

### Multi-value headers

Headers with a single value are emitted as a string. Headers with multiple values (e.g. `Accept`) stay as an array.

## Trace ID

Every sensor entry can include a top-level `trace_id` so records from the same lifecycle correlate with a single-field query (for example in Axiom: `where trace_id = 'abc-123'`).

The sensor tries three sources in order:

1. **Configured callable.** Set `observability-log.trace_id` to a closure for full control:
   ```php
   'trace_id' => fn (?Illuminate\Http\Request $request) => $request?->attributes->get('trace_id'),
   ```
2. **Configured header list.** The default is a first-match-wins array:
   ```php
   'trace_id' => ['X-Request-Id', 'X-Trace-Id', 'X-Correlation-Id'],
   ```
3. **Laravel `Context` facade.** Set a correlation id once from middleware and every sensor picks it up, including queued jobs dispatched from that request (Context propagates across queue serialization):
   ```php
   use Illuminate\Support\Facades\Context;
   use Illuminate\Support\Str;

   Context::add('trace_id', Str::uuid()->toString());
   ```

When nothing resolves, the `trace_id` field is omitted.

Resolved ids are capped at `observability-log.trace_id_max_length` bytes (default 128). Set to `null` or `0` to disable.

## Octane, RoadRunner, Swoole

Tested with repeated-request scenarios in `tests/IntegrationTest.php`. No extra setup required:

- Static callbacks (set in `AppServiceProvider::boot()`) persist across requests.
- The DB query listener registers once per worker via a container-scoped flag.
- User callbacks (`using` / `extend` / `message`) run inside `try/catch`; a throw falls back to the default entry and surfaces via `Log::error()`.
- Exception re-entrance is guarded against logger failures triggering Laravel to re-report.

## Roadmap

Each row shows the event name emitted on the configured log channel.

- [x] `RequestSensor` (`http.request`), incoming HTTP requests
- [x] `ExceptionSensor` (`error.exception`), exceptions reported via Laravel's exception handler
- [x] `JobSensor` (`job.attempt`, `job.queued`), queued job attempts and enqueues
- [ ] `CommandSensor` (`console.command`), Artisan command completions
- [ ] `ScheduledTaskSensor` (`schedule.task`), scheduled task completions
- [ ] `CacheSensor` (`cache.hit`, `cache.miss`, `cache.write`, `cache.delete`), cache operations
- [ ] `OutgoingHttpSensor` (`http.outgoing`), outgoing HTTP via the `Http` facade plus optional Guzzle middleware
- [ ] `MailSensor` (`mail.sent`), mail delivery
- [ ] `NotificationSensor` (`notification.sent`), notification delivery
- [ ] `observability:deploy` Artisan command, deploy markers for graph overlays

## Testing

```bash
composer test
```

## Credits

Sensor class layout inspired by [Laravel Nightwatch](https://github.com/laravel/nightwatch) (MIT).

## License

MIT
