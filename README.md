# Laravel Observability Log

A set of sensors that emit structured events through Laravel log channels. Whatever log driver you use (stack, Axiom, Better Stack, Papertrail, stderr) doubles as your observability pipeline.

Ships seven sensors today (`RequestSensor`, `ExceptionSensor`, `JobSensor`, `CommandSensor`, `ScheduledTaskSensor`, `OutgoingHttpSensor`, `ClientSensor`), with more on the [roadmap](#roadmap).

## Design philosophy

- **Lean defaults.** Every sensor ships with the fields most useful for filtering and aggregation, nothing more.
- **Opinionated top-level keys.** Common filter fields are promoted out of nested structures so dashboard queries hit a single key.
- **Richer collection via config.** Header capture, structured stack traces with argument values, slow query capture, and similar are opt-in.
- **Defaults surface what needs action.** Entries representing a failure (failed job attempts, non-zero command exits, failed scheduled tasks, 5xx responses, unhandled exceptions) log at `failed_level` (default `error`); routine traffic uses `level` (default `info`).

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

Then register the request middleware in `bootstrap/app.php`:

```php
use DevtimeLtd\LaravelObservabilityLog\RequestSensor;

->withMiddleware(function (Middleware $middleware) {
    $middleware->prepend(RequestSensor::class);
})
```

`ExceptionSensor` and `JobSensor` are registered automatically; no bootstrap change required.

Publish the config file to customise defaults:

```bash
php artisan vendor:publish --tag=observability-log
```

### Enabling and disabling sensors

Each sensor's `channel` is its on/off switch. Set it to `null` to silence one sensor; the underlying queue, console, and exception listeners stay registered but skip emission. Example, keep request and exception logging, drop job logging:

```php
'jobs' => [
    'channel' => null,
    // ...
],
```

### Shared defaults

The package defines a handful of keys at the top level of the config that every sensor inherits. Override per sensor by setting the same key inside that sensor's section.

```php
return [
    'channel' => env('OBSERVABILITY_LOG_CHANNEL'),
    'level' => 'info',
    'failed_level' => 'error',
    'failures_only' => false,
    'capture_headers' => env('OBSERVABILITY_LOG_CAPTURE_HEADERS', false),
    'db_collect_queries' => true,
    'db_slow_query_threshold' => 100,
    'db_slow_queries_max_count' => 100,
    'resolve_ip' => null,
    'obfuscate_ip' => null,
    // ...
];
```

`level` is the default for every entry. `failed_level` is used instead when a sensor emits an entry with `status: failed` (a failed job attempt, a non-zero command exit, a failed scheduled task), on `RequestSensor` for 5xx responses, on `OutgoingHttpSensor` for 5xx responses and connection failures, and on every `ExceptionSensor` entry (every unhandled exception is considered a failure). `failures_only` drops routine "success" entries across every sensor that has a failure bucket; useful when log volume is high and only error traffic is interesting. `capture_headers` applies to the request, exception, and outgoing HTTP sensors. The `db_*` keys only apply to sensors that track DB stats (request, job, command, scheduled task). The `resolve_ip` and `obfuscate_ip` keys only apply to sensors that capture an IP (request and exception); see [IP resolution and obfuscation](#ip-resolution-and-obfuscation).

> **`php artisan config:cache` and callables:** Laravel serialises the cached config via `var_export`, which cannot encode closures. Any config value that is a `Closure` (the `resolve_ip`, `obfuscate_ip`, and `trace_id` keys all accept callables) will throw `LogicException: Your configuration files are not serializable.` when caching is enabled. Use one of these cache-safe forms instead:
>
> - Static method array: `[ObfuscateIp::class, 'levelTwo']` (the method must be `public static`)
> - Static method string: `'App\Support\ResolveClientIp::resolve'`
>
> Note that invokable classes (objects with `__invoke`) are not cache-safe in this slot: `is_callable('App\Support\ResolveClientIp')` is false (PHP does not auto-instantiate from a class string), and `var_export` cannot cleanly serialise the instance. If you need an invokable or any other closure-based resolver, skip the config slot and wire it at runtime in `AppServiceProvider::boot()` via the sensor's `extend()` / `using()` callbacks.

## Request sensor

`RequestSensor` is a middleware that logs structured request data (method, URL, status, duration, DB query stats, memory) to the configured channel.

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
| `redirect_to`        | `Location` header on redirect responses (see [Redirect target](#redirect-target)) |
| `user_id`            | Authenticated user ID (null if guest)                                |
| `ip`                 | Client IP (resolution and masking, see [below](#ip-resolution-and-obfuscation)) |
| `user_agent`         | User-Agent header value                                              |
| `referer`            | Referer header value                                                 |
| `duration_ms`        | Total request time in milliseconds                                   |
| `memory_peak_mb`     | Peak memory usage                                                    |
| `db_query_count`     | Number of database queries                                           |
| `db_query_total_ms`  | Total time spent in database queries                                 |
| `db_slow_queries`    | Queries exceeding the slow query threshold                           |
| `headers`            | Full header map when capture is enabled (see [Header capture](#header-capture)) |
| `trace_id`           | Correlation id when resolvable (see [Trace ID](#trace-id))           |
| `client_id`, `client_instance_id`, `visit_id` | The browser, page instance and visit behind the entry, when resolvable (see [Client IDs](#client-ids)) |

### Options

```php
'requests' => [
    'message' => 'http.request',
],
```

Inherits the shared `channel`, `level`, `failed_level`, `failures_only`, `capture_headers`, `db_*`, `resolve_ip`, and `obfuscate_ip` defaults; override any of them by setting the same key inside this section.

### IP resolution and obfuscation

Both `resolve_ip` and `obfuscate_ip` are read from the package's top-level config and inherited by every sensor that captures a client IP (request, exception). Override per-sensor by setting the same key inside that sensor's section. `resolve_ip` runs first; `obfuscate_ip` runs on the resolved value.

#### `resolve_ip`

Override Laravel's `$request->ip()` when the client IP arrives via a custom header chain that trusted-proxy handling does not cover:

```php
'resolve_ip' => [App\Support\ResolveClientIp::class, 'resolve'],
```

Signature: `fn (Illuminate\Http\Request $request): ?string`. If it returns `null`, an empty string, a non-string value, or throws, the package falls back to `$request->ip()` (and logs the throw via `Log::error`). The package only invokes the callable with the documented argument list; callables that declare more parameters than the signature will raise `ArgumentCountError` and fall back the same way.

#### `obfuscate_ip`

Mask the resolved IP using the built-in `ObfuscateIp` class:

| Method       | IPv4 example (`198.51.100.123`) | IPv6  |
| ------------ | ------------------------------- | ----- |
| `levelOne`   | `198.51.100.0`                  | `/96` |
| `levelTwo`   | `198.51.0.0`                    | `/64` |
| `levelThree` | `198.0.0.0`                     | `/32` |
| `levelFour`  | `0.0.0.0`                       | `::`  |

```php
use DevtimeLtd\LaravelObservabilityLog\ObfuscateIp;

'obfuscate_ip' => [ObfuscateIp::class, 'levelTwo'],
```

Signature: `fn (?string $ip, ?Illuminate\Http\Request $request = null): ?string`. The request is passed for route-aware masking; one-arg callables like `fn (?string $ip) => 'redacted'` work too. Use the static-method forms listed in the [config:cache callout](#shared-defaults) to stay cache-safe.

`obfuscate_ip` is fail-closed: a `null`/non-string return or a throw collapses the logged `ip` field to `null` rather than the unmasked value. The reasoning is that a misconfigured obfuscator should not silently leak the IP it was meant to hide. If you want the raw IP, leave `obfuscate_ip` unset.

### Redirect target

When the response is a redirect (`Response::isRedirect()`: 201/301/302/303/307/308), `redirect_to` carries the raw `Location` header value. Relative paths are not normalised. When `Location` is missing on a redirect status, `redirect_to` is emitted as `null`; on non-redirect statuses, the field is absent.

> **Note:** redirect targets sometimes embed short-lived secrets (S3 pre-signed URLs, OAuth `state`/`code`, signed routes). The value is already going to the client so logging it server-side adds no new external exposure, but log-pipeline secret scanners may still flag it. Drop or rewrite the field via `RequestSensor::extend()` if needed:
>
> ```php
> RequestSensor::extend(function ($request, $response, $entry) {
>     unset($entry['redirect_to']);
>     return $entry;
> });
> ```

### Customising the entry

`RequestSensor::extend/using/message` are static, set them in `AppServiceProvider::boot()`. `using()` replaces the default entry, `extend()` runs after it (so they compose). Pass `null` to revert any of them to the config default.

```php
use DevtimeLtd\LaravelObservabilityLog\RequestSensor;

// Add or override fields on the default entry
RequestSensor::extend(function ($request, $response, $entry) {
    $entry['tenant_id'] = $request->header('X-Tenant-ID');
    return $entry;
});

// Replace the default entry. $measurements contains duration_ms,
// memory_peak_mb, and (when collection is on) db_query_count /
// db_query_total_ms / db_slow_queries.
RequestSensor::using(function ($request, $response, $measurements) {
    return [
        'method' => $request->method(),
        'path' => $request->path(),
        'status' => $response?->getStatusCode(),
        'duration_ms' => $measurements['duration_ms'],
    ];
});

// Customise the log message (string or callback).
RequestSensor::message(fn ($request, $response) => $request->is('api/*') ? 'api.request' : 'web.request');
```

## Exception sensor

`ExceptionSensor` hooks Laravel's exception reporter (`$handler->reportable(...)`) and emits a structured entry for every unhandled exception that Laravel would normally report. Registration is automatic through the service provider.

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
| `client_id`, `client_instance_id`, `visit_id` | The browser, page instance and visit behind the entry, when resolvable (see [Client IDs](#client-ids)) |

### Trace format

By default `trace` is a multi-line string from `getTraceAsString()`: every frame present, argument values replaced by their types.

Config controls:

```php
'exceptions' => [
    'trace' => true,                    // false to omit the field
    'trace_args' => false,              // true: structured array with arg values
    'trace_args_max_frames' => 50,      // null to disable the cap
    'trace_string_max_bytes' => 16384,         // null to disable the cap
    'previous_max_depth' => 3,          // null: unbounded, 0: omit, positive: cap
],
```

`trace_args` requires `zend.exception_ignore_args=Off` in `php.ini`; PHP strips arg values from `Throwable::getTrace()` when it is `On` (the PHP 7.4+ default).

When `trace_string_max_bytes` is hit, the string is cut at the last frame boundary and a `... [truncated at N bytes]` marker is appended. When `trace_args_max_frames` is hit, a `['truncated' => 'after N frames']` marker is appended to the array.

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

Exception entries log at `failed_level` (default `error`) since every unhandled exception is considered a failure. Override via `observability-log.exceptions.failed_level` or the top-level `observability-log.failed_level`.

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
- `job.attempt` when a worker finishes (or fails) a single attempt (one entry per attempt, regardless of how it ended)

Registration is automatic through the service provider; no `bootstrap/app.php` change required.

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
| `client_id`, `client_instance_id`, `visit_id` | The browser, page instance and visit behind the entry, when resolvable (see [Client IDs](#client-ids)) |

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
| `memory_peak_mb`    | Memory peak gained during the attempt window (delta from `memory_get_peak_usage()` at attempt start). Stays accurate on long-lived workers; may be 0 when the attempt did not push the process peak higher. |
| `db_query_count`    | Queries during the attempt, when query collection is on                |
| `db_query_total_ms` | Total query time during the attempt                                    |
| `db_slow_queries`   | Slow queries above threshold                                           |
| `exception`         | `{class, message, file, line, code}` when the attempt failed           |
| `trace_id`          | Correlation id when resolvable                                         |
| `client_id`, `client_instance_id`, `visit_id` | The browser, page instance and visit behind the entry, when resolvable (see [Client IDs](#client-ids)) |

### Options

```php
'jobs' => [
    'queued_message' => 'job.queued',
    'attempt_message' => 'job.attempt',
],
```

Inherits the shared `channel`, `level`, `failed_level`, `failures_only`, and `db_*` defaults. The `db_*` keys behave the same as on the request sensor, scoped per attempt. When `failures_only` is on, `job.queued` and `job.attempt` entries with `status: processed` are dropped; only failed attempts emit.

### Failed attempts

Each attempt produces exactly one `job.attempt` entry whether it succeeds, throws (and may retry), throws on the final attempt, or is failed manually via `$job->fail()`. `JobExceptionOccurred` and `JobFailed` are deduplicated so you never see two entries for the same attempt.

If a job throws and is permanently failed, you'll typically also see an `error.exception` entry from `ExceptionSensor`; the queue worker still reports the exception through Laravel's exception handler. Both entries share `trace_id` when one is resolvable.

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

## Command sensor

`CommandSensor` listens to Laravel's `CommandStarting` and `CommandFinished` events and emits one `console.command` entry per Artisan command invocation (success or failure). Registration is automatic through the service provider; no `bootstrap/app.php` change required.

### Logged fields

| Field               | Description                                                       |
| ------------------- | ----------------------------------------------------------------- |
| `command`           | Command name, e.g. `migrate`                                      |
| `exit_code`         | Integer exit code (0 on success)                                  |
| `status`            | `success` when `exit_code` is 0, otherwise `failed`               |
| `duration_ms`       | Wall-clock time for the command                                   |
| `memory_peak_mb`    | Memory peak gained during the command, delta from start           |
| `db_query_count`    | Queries during the command, when query collection is on           |
| `db_query_total_ms` | Total query time during the command                               |
| `db_slow_queries`   | Slow queries above threshold                                      |
| `trace_id`          | Correlation id when resolvable                                    |
| `client_id`, `client_instance_id`, `visit_id` | The browser, page instance and visit behind the entry, when resolvable (see [Client IDs](#client-ids)) |

### Options

```php
'commands' => [
    'message' => 'console.command',

    // Skip these command names entirely. Useful for noisy or
    // long-running commands.
    'ignore' => [
        // 'schedule:run',
        // 'queue:work',
    ],
],
```

Inherits the shared `channel`, `level`, `failed_level`, `failures_only`, and `db_*` defaults. When `failures_only` is on, only commands with a non-zero exit code emit.

### Common ignore list candidates

`schedule:run` fires every minute via cron and `queue:work` / `queue:listen` never finish under normal operation. Add them to `commands.ignore` if their entries would just be noise.

### Customising the entry

```php
use DevtimeLtd\LaravelObservabilityLog\CommandSensor;
use Illuminate\Console\Events\CommandFinished;

CommandSensor::extend(function (CommandFinished $event, array $entry) {
    $entry['env'] = app()->environment();
    return $entry;
});

CommandSensor::using(function (CommandFinished $event, array $measurements) {
    return [
        'command' => $event->command,
        'status' => $event->exitCode === 0 ? 'success' : 'failed',
        'duration_ms' => $measurements['duration_ms'],
    ];
});

CommandSensor::message(fn (CommandFinished $event) => 'cmd.'.$event->command);
```

## Scheduled task sensor

`ScheduledTaskSensor` listens to Laravel's `ScheduledTaskStarting`, `ScheduledTaskFinished`, `ScheduledTaskFailed`, and `ScheduledTaskSkipped` events and emits one `schedule.task` entry per scheduled task execution. Registration is automatic through the service provider.

`status` is one of `success`, `failed`, or `skipped`. A skipped entry is emitted when a task was due but a filter (e.g. `withoutOverlapping()`, `when(...)`, `skip(...)`) prevented it from running, so you can still see the schedule was evaluated. `failed` covers both thrown exceptions and non-zero exit codes from the underlying command.

### Background tasks

A `runInBackground()` scheduled command runs across three PHP processes: `schedule:run` kicks off the work and exits, the actual command runs detached, then `schedule:finish` fires the completion event in a fresh process. Because the three processes don't share state, `schedule.task` entries for background runs include the metadata (task, expression, status from the exit code) but not `duration_ms`, `memory_peak_mb`, or `db_*`.

If the background task is itself an Artisan command, [`CommandSensor`](#command-sensor) emits a `console.command` entry from the actual command process with all of those measurements. Correlate the two entries by command name (or set a `trace_id` via `Context::add` if you want explicit linking).

### Logged fields

| Field               | Description                                                                  |
| ------------------- | ---------------------------------------------------------------------------- |
| `task`              | Human-friendly task name (description if set, otherwise the built command)  |
| `expression`        | Cron expression                                                              |
| `timezone`          | Configured timezone, when set                                                |
| `status`            | `success`, `failed`, or `skipped`                                            |
| `run_in_background` | `true` if the task was scheduled with `runInBackground()`, otherwise `false` |
| `duration_ms`       | Wall-clock time for the task (omitted on `skipped`)                          |
| `memory_peak_mb`    | Memory peak gained during the task (omitted on `skipped`)                    |
| `db_query_count`    | Queries during the task, when query collection is on (omitted on `skipped`)  |
| `db_query_total_ms` | Total query time during the task                                             |
| `db_slow_queries`   | Slow queries above threshold                                                 |
| `exception`         | `{class, message, file, line, code}` when the task failed                    |
| `trace_id`          | Correlation id when resolvable                                               |
| `client_id`, `client_instance_id`, `visit_id` | The browser, page instance and visit behind the entry, when resolvable (see [Client IDs](#client-ids)) |

### Options

```php
'schedule' => [
    'message' => 'schedule.task',
],
```

Inherits the shared `channel`, `level`, `failed_level`, `failures_only`, and `db_*` defaults. When `failures_only` is on, only `status: failed` entries emit; `success` and `skipped` are dropped.

### Customising the entry

```php
use DevtimeLtd\LaravelObservabilityLog\ScheduledTaskSensor;
use Illuminate\Console\Events\ScheduledTaskSkipped;

ScheduledTaskSensor::extend(function ($event, array $entry) {
    $entry['env'] = app()->environment();
    return $entry;
});

// Different message for skipped vs ran
ScheduledTaskSensor::message(fn ($event) => $event instanceof ScheduledTaskSkipped ? 'schedule.skipped' : 'schedule.ran');
```

## Outgoing HTTP sensor

`OutgoingHttpSensor` listens to the `Http` facade's `RequestSending`, `ResponseReceived`, and `ConnectionFailed` events and emits one `http.outgoing` entry per outgoing request (success or failure). Registration is automatic through the service provider; no `bootstrap/app.php` change required.

### Logged fields

| Field           | Description                                                                  |
| --------------- | ---------------------------------------------------------------------------- |
| `method`        | HTTP method                                                                  |
| `url`           | Request URL (query string stripped by default, see [Query string](#query-string)) |
| `host`          | Host portion of the URL                                                      |
| `path`          | Path portion of the URL                                                      |
| `query_string`  | Raw query string when `capture_query_string` is on, omitted otherwise        |
| `status`        | Response status code (omitted on connection failure)                         |
| `response_size` | Response body size in bytes (omitted on connection failure)                  |
| `duration_ms`   | Total request time in milliseconds                                           |
| `exception`     | `{class, message, file, line, code}` when the connection failed              |
| `headers`       | Full header map when `capture_headers` is enabled (redaction applies)        |
| `trace_id`      | Correlation id when resolvable                                               |
| `client_id`, `client_instance_id`, `visit_id` | The browser, page instance and visit behind the entry, when resolvable (see [Client IDs](#client-ids)) |

### Options

```php
'outgoing_http' => [
    'message' => 'http.outgoing',
    'capture_query_string' => false,
    'ignore_hosts' => [
        // 'login.example.com',
    ],
],
```

Inherits the shared `channel`, `level`, `failed_level`, `failures_only`, and `capture_headers` defaults. `failed_level` is used for 5xx responses and every `ConnectionFailed` entry; other status codes (1xx/2xx/3xx/4xx) use `level`. When `failures_only` is on, only 5xx responses and `ConnectionFailed` entries emit.

### Query string

By default `url` is emitted without its query string and the `query_string` field is omitted entirely. Outgoing query strings frequently embed API keys, OAuth params, or signed-URL secrets you may not want in your log pipeline, so stripping is the safer default. Flip `capture_query_string` to `true` to emit the full URL and surface the raw query as a separate field:

```php
'outgoing_http' => [
    'capture_query_string' => true,
],
```

### Ignore hosts

Skip outgoing requests to specific hosts entirely:

```php
'outgoing_http' => [
    'ignore_hosts' => ['login.example.com', 'metrics.example.com'],
],
```

Matching is case-insensitive and exact (no wildcards).

### Reducing volume

Outgoing HTTP can be very high volume. Two knobs:

```php
'outgoing_http' => [
    'channel' => null,        // disable the sensor entirely
    'failures_only' => true,  // only emit 5xx and ConnectionFailed entries
],
```

Both inherit their top-level defaults, so `OBSERVABILITY_LOG_CHANNEL=null` or top-level `failures_only => true` apply globally; the sensor-level keys override.

### Customising the entry

```php
use DevtimeLtd\LaravelObservabilityLog\OutgoingHttpSensor;
use Illuminate\Http\Client\Events\ConnectionFailed;

// Add fields on top of the default entry
OutgoingHttpSensor::extend(function ($event, array $entry) {
    $entry['env'] = app()->environment();
    return $entry;
});

// Replace the default entry. $measurements contains duration_ms
// (omitted on a ConnectionFailed that never saw a RequestSending).
OutgoingHttpSensor::using(function ($event, array $measurements) {
    if ($event instanceof ConnectionFailed) {
        return [
            'host' => parse_url($event->request->url(), PHP_URL_HOST),
            'error' => $event->exception->getMessage(),
        ];
    }

    return [
        'host' => parse_url($event->request->url(), PHP_URL_HOST),
        'status' => $event->response->status(),
        'duration_ms' => $measurements['duration_ms'],
    ];
});

// Customise the log message
OutgoingHttpSensor::message(fn ($event) => $event instanceof ConnectionFailed ? 'http.outgoing.failed' : 'http.outgoing');
```

## Client sensor

`ClientSensor` collects from the browser: unhandled JS errors, Core Web Vitals, named custom events, and client-side pageviews for SPAs. Entries carry the trace ID of the request that rendered the page, so a browser error joins to its `http.request` entry, beside that request's own `duration_ms` and `db_query_count`. They also carry the [client IDs](#client-ids) that every request the page goes on to make carries too.

It is the one sensor that opens a public write endpoint, so it does **not** inherit the top-level channel. Switch it on with its own env var:

```env
OBSERVABILITY_LOG_CLIENT_CHANNEL=axiom
```

The route is registered only while the sensor is on, so run `php artisan route:clear` after enabling it on an app that caches routes.

Then add the agent to your layout:

```blade
<head>
    @observabilityClient
</head>
```

The directive inlines a ~3 KB (gzipped) script and the config it needs. It exposes `window.observability` (`track`, `pageview`, `flush`, `captureError`, `setTraceId`, `setView`, `startVisit`, `ids`) so a page with no build step can emit events:

```html
<button onclick="observability.track('copy', { field: 'countryCode' })">Copy</button>
```
It renders nothing while the sensor is off, so it is safe to leave in a shared layout. It takes an array of options:

| Option | Notes |
| --- | --- |
| `nonce` | CSP nonce for the script tag. |
| `trace_id` | Overrides the resolved trace ID. |
| `client_instance_id`, `visit_id` | Override the [client IDs](#client-ids) the directive would mint. |
| `client_id` | `false` keeps no client ID in the browser's storage and removes one kept there. A string is used as the client ID in place of a stored one. |
| `cacheable` | `true` for a page that may be served from a cache: no ids and no trace ID are written into it, a `client_id` string passed alongside included, and the browser mints its own ids. |
| `inertia` | `true` to follow Inertia visits, or `['trace_id' => 'traceId']` to also read each visit's trace ID from that page prop. See [Inertia](#inertia). |
| `release` | The build being served. Overrides `client.release`. |
| `view` | What the page shows, such as a route name. |

```blade
@observabilityClient(['nonce' => $nonce, 'view' => Route::currentRouteName()])
```

An Inertia app passes `inertia` and the agent follows its visits on its own. Any other app that routes on the client keeps the entries pointed at the right place itself: call `observability.startVisit()` when a navigation starts, and `observability.setTraceId(id)` and `observability.setView(name)` once it lands. `observability.ids()` returns the client IDs in use.

### Logged fields

Four messages, each fixed rather than built from anything the browser sent.

| Message | Fields on top of the shared set |
| --- | --- |
| `client.error` | `error_message`, `error_type`, `source`, `line`, `col`, `stack`, `handled` |
| `client.vital` | `vital` (`LCP`, `CLS`, `INP`, `TTFB`, `FCP`), `value`, `rating` |
| `client.event` | `event`, `props` |
| `client.pageview` | `from`, `to`, `nav_type` (`load` or `spa`) |

Every entry also carries `kind`, `client_id`, `client_instance_id`, `visit_id`, `trace_id`, `release`, `view`, `url`, `host`, `path`, `referrer`, `viewport`, `user_agent` and `ip`. The IP resolves and masks through the same `resolve_ip` / `obfuscate_ip` config as every other sensor.

`release` is the build the page was served from, so a minified stack can be matched to the bundle that produced it. It is read when the page renders, not when the entry arrives, because a tab can outlive a deploy.

Errors log at `failed_level`; the rest at `level`.

### Custom events

Event names are allowlisted, so a stranger cannot write arbitrary strings into your log stream:

```php
'client' => [
    'events' => ['copy', 'tab', 'field'],
],
```

An empty list accepts none, which is the default.

### Pageviews

Off by default: on a server-rendered site every page view already logs an `http.request`. Turn them on for an SPA, where client-side routing never reaches the server:

```php
'client' => [
    'collect' => ['error', 'vital', 'event', 'pageview'],
],
```

The agent emits the first pageview itself on `init()`, then one per `pageview()` call from your router. A Blade app that never turns the kind on cannot double-count what `http.request` already records.

### Options

| Key | Default | Notes |
| --- | --- | --- |
| `channel` | `env('OBSERVABILITY_LOG_CLIENT_CHANNEL')` | On/off switch. Does not inherit the top-level channel. |
| `path` | `_observability` | Where the browser POSTs. Registered outside the `web` group: `sendBeacon` cannot set headers, so there is no session or CSRF. |
| `middleware` | `['throttle:60,1']` | Applied to that route. |
| `collect` | `['error', 'vital', 'event']` | Kinds accepted. |
| `events` | `[]` | Allowlist for custom event names. |
| `release` | `env('OBSERVABILITY_LOG_CLIENT_RELEASE')` | Build identifier logged as `release`. |
| `sample_rate` | `1.0` | Fraction of vitals and pageviews the browser sends. Applied client-side, so a sampled-out entry costs no request. |
| `max_body_bytes` | `8192` | Bodies over this are dropped before they are decoded. |
| `max_entries` | `20` | Batches over this are truncated. |
| `stack_max_bytes` | `4096` | Cap on a reported stack. |
| `max_props` | `10` | Cap on event props. |
| `prop_max_length` | `200` | Cap on each prop's string value. |

Whatever it decides, the endpoint answers `204` with no body, so it cannot be used to probe what an app collects.

### Browser package

For apps with a build step, the same agent ships on npm:

```bash
npm install @devtime-ltd/observability-client
```

```js
import { init, track } from "@devtime-ltd/observability-client";

init({ endpoint: "/_observability", collect: ["error", "vital", "event"], trace_id: traceId });

track("copy", { field: "countryCode" });
```

`captureError(error)` reports one you caught yourself. `flush()` sends the buffer early; it flushes on `visibilitychange` and `pagehide` on its own.

`init()` also takes `release`, `view`, `client_id`, `client_instance_id`, `visit_id`, `headers` and `inertia`. Give it `headers: { client_id: "X-Client-Id", client_instance_id: "X-Client-Instance-Id", visit_id: "X-Visit-Id" }` to have the [client IDs](#client-ids) sent on same-origin requests. `startVisit()` begins a new visit and `currentIds()` returns the ids in use.

React:

```jsx
import { ObservabilityProvider, useTrack } from "@devtime-ltd/observability-client/react";

<ObservabilityProvider config={config}>
    <App />
</ObservabilityProvider>;

const track = useTrack();
```

Inertia, where visits change route without a full page load:

```js
import { observeInertia } from "@devtime-ltd/observability-client/inertia";

observeInertia(config, { traceId: (page) => page.props.traceId });
```

This is the same following the directive's `inertia` option switches on. See [Inertia](#inertia).

Both the npm build and the inlined script come from one TypeScript source, and CI fails if the committed `resources/client.min.js` does not match a fresh build.

### Customising the entry

`ClientSensor::extend/using/message` take the kind as their second argument. `using()` receives the raw browser payload, so anything it keeps is unvalidated.

```php
ClientSensor::extend(function ($request, string $kind, array $entry) {
    $entry['tenant'] = $request->getHost();

    return $entry;
});

ClientSensor::message(fn (string $kind) => 'browser.'.$kind);
```

## Header capture

Off by default on the request and exception sensors (job entries don't capture headers). Typical payload adds 1 to 3 KB per entry. Enable for the whole package:

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

1. **Configured callable.** Set `observability-log.trace_id` to any callable `fn (?Illuminate\Http\Request $request): ?string` for full control. Use a static-method form so `php artisan config:cache` does not choke on a closure (see the [callables and config:cache callout](#shared-defaults)):
   ```php
   'trace_id' => [App\Support\ResolveTraceId::class, 'resolve'],
   ```
   If you do not cache your config, an inline closure works the same way:
   ```php
   'trace_id' => fn (?Illuminate\Http\Request $request) => $request?->attributes->get('trace_id'),
   ```
2. **Configured header list.** The default is a first-match-wins array (always cache-safe):
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

## Client IDs

A trace ID covers one request. Three ids from the browser sit above it, each narrower than the last:

| Field | Covers | Changes when |
| --- | --- | --- |
| `client_id` | One browser, across reloads and tabs. | The site's storage is cleared. |
| `client_instance_id` | One running copy of a page in a tab. | The page loads: a reload, a new tab, a full navigation. |
| `visit_id` | One navigation within that copy. | An SPA navigates. A page that does not route on the client has one visit per instance. |
| `trace_id` | One request. | Every request. |

Each is on the browser's own `client.*` entries and on every server entry behind them: the requests the page makes, and the jobs and outgoing calls behind those. Query one field (`where visit_id == '9d2b6c1e-3f4a-4b5c-8d7e-6f5a4b3c2d1e'`) to read a browser error beside everything the server did for that visit, or widen to the instance or the browser.

All of them are version 4 UUIDs unless the app supplies its own.

It works without any wiring once [`@observabilityClient`](#client-sensor) is in the layout:

1. The directive mints the instance and its first visit as the page renders and adds them to `Context`, so the entries of the request rendering the page carry them.
2. The browser agent reads the client ID from `localStorage` (key `observability:client_id`), minting one on a browser's first page.
3. The agent sends all three as `X-Client-Id`, `X-Client-Instance-Id` and `X-Visit-Id` on every `fetch` and `XMLHttpRequest` addressed to the page's own origin. Requests to other origins are left alone: a custom header there forces a preflight the other side may refuse. A same-origin request that is redirected elsewhere keeps the headers, as any request header does. A header the caller set itself is not overwritten.
4. `RequestSensor` reads the headers when a request arrives and adds them to `Context`, which carries them into the jobs the request dispatches. Every sensor then puts them on its entry. A queue worker drops them again between jobs, so work it starts itself from a `Queue::looping` callback does not inherit the last job's.

The headers are the browser's to set, so a value that is not 1 to 64 characters of `A-Z a-z 0-9 _ -` is ignored, and nothing should trust an id for more than grouping log entries. Entries logged before the directive renders (a query in the controller, say) belong to the same request and share its `trace_id`, but not the instance or the visit. The request for a page never carries the client ID, which only the browser knows: join through `client_instance_id`.

### The client ID is a persistent identifier

It is stored on the visitor's device and links what one browser does over time, including across sign-ins. Where the law asks for consent before storing such an identifier, get it first, or switch the id off: `OBSERVABILITY_LOG_CLIENT_ID=false` for the whole app (it sets the top-level `client_id` to `null`), or `['client_id' => false]` on the directive, which can follow each visitor's consent. Switched off, the agent keeps no id, removes one it stored earlier, and entries carry a `client_id` of `null`. Entries also carry `null` where the browser blocks storage.

### Cached pages

A page stored in a full-page cache would hand the ids and the trace ID it was rendered with to every visitor it is served to. Pass `cacheable` wherever that can happen: the browser mints its own ids, and its entries carry the trace ID of the post that delivered them until the page makes a visit of its own. The only entry left without the ids is the request for the page itself.

```blade
@observabilityClient(['cacheable' => ! auth()->check()])
```

The browser keeps its own copy of a page too, and may show it again on back or forward without asking the server. The agent treats a page reached that way as cacheable whatever the directive said. A page the browser kept running and resumes from its back/forward cache is the same instance, and starts a new visit.

### Inertia

```blade
@observabilityClient(['inertia' => ['trace_id' => 'traceId']])
```

With `inertia`, the agent listens for the events Inertia dispatches on the document, so it needs no build step and no import, and is listening before the app's bundle loads. On each page it sets `view` to the page component, and the trace ID to the named page prop (share it from `HandleInertiaRequests::share()`, because router events cannot read response headers).

A visit that asks the server for a whole page and adds a history entry starts a new `visit_id`: a link, a form submission, a redirect that is followed. Its own request carries the new id, so the request that produced a page is the first entry of that page's visit. The page takes the id on when the response arrives, before the new component renders, and keeps the old one if the visit is cancelled or fails before a response. Should a poll's response land while the visit is still in flight, the page takes the new id on then, and gives it back if the visit is cancelled after all.

| Stays in the current visit | Starts a new visit without a request |
| --- | --- |
| Partial reloads (`only`, `except`, `reset`) | Back and forward, which restore a page from history |
| Async visits: `router.reload()`, polling, deferred and lazy props | A page shown from the prefetch cache |
| Prefetch requests | A client-side `router.push()` |
| Visits with `replace: true`, such as a filter form | |

Checked in a browser against Inertia 3.9.1, and in the test suite against the 2.3 router. Earlier versions say less, and the agent works from what each dispatches:

- **Before 3.9.0** a navigation does not say what kind it is. The agent takes one as unannounced when no visit that could have caused it is in flight, so going back while a poll or a partial reload is still in flight stays in the current visit.
- **Before 3.4.0** a visit has no id of its own, and the agent mints one.
- **Before 2.2.0** nothing is dispatched ahead of the update, so the page takes the new visit on once it has rendered, not before.
- **On 1.x** a listener cannot add a header to a visit's request. That request carries the `visit_id` of the page it left, and the requests after it carry the new one.

The last two are read from those versions' sources, not run against them.

### Resolution

`observability-log.client_id`, `client_instance_id` and `visit_id` each resolve like `trace_id`: a header list (the first is the one the agent sends), a callable `fn (?Illuminate\Http\Request $request): ?string`, then `Context::get()` of the same name. Set one to `null` and that id is switched off: the directive mints none, the agent sends no header for it, and no sensor logs it.

An app that does not register `RequestSensor` can share the ids from its own middleware:

```php
use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;

RequestContext::shareClientIds($request);
```

A field that does not resolve is omitted.

## Global fields via Context

Note these Trace IDs aren't special: anything on Laravel's `Context` is attached to every log record by the built-in `ContextLogProcessor`. A single `Context::add()` call (in middleware, a listener, or `AppServiceProvider::boot()`) surfaces a field on every sensor entry without any per-sensor wiring.

```php
use Illuminate\Support\Facades\Context;

Context::add('release_id', config('app.release_id'));
```

Every `http.request`, `job.attempt`, `console.command`, and `schedule.task` then carries `release_id`. Useful for deploy-line overlays (`min(_time) where release_id = X`), tenant tagging, environment markers, and similar. `Context` propagates through queue serialization, so jobs dispatched from a request keep the same value.

## Octane, RoadRunner, Swoole

Tested with repeated-request scenarios in `tests/IntegrationTest.php`. No extra setup required:

- Static callbacks set in `AppServiceProvider::boot()` persist across requests.
- A single shared `DB::listen` registers once per worker in the service provider and dispatches to whichever sensor is currently active.
- `JobSensor` snapshots query counters and `memory_get_peak_usage()` per attempt and emits the delta, so long-lived workers report attempt-specific values rather than process-cumulative ones.
- User callbacks (`using` / `extend` / `message`) run inside `try/catch`; a throw falls back to the default entry and surfaces via `Log::error()`.
- Exception re-entrance is guarded so a logger failure cannot trigger Laravel to re-report.

## Roadmap

Each row shows the event name emitted on the configured log channel.

- [x] `RequestSensor` (`http.request`), incoming HTTP requests
- [x] `ExceptionSensor` (`error.exception`), exceptions reported via Laravel's exception handler
- [x] `JobSensor` (`job.attempt`, `job.queued`), queued job attempts and enqueues
- [x] `CommandSensor` (`console.command`), Artisan command completions
- [x] `ScheduledTaskSensor` (`schedule.task`), scheduled task completions
- [x] `OutgoingHttpSensor` (`http.outgoing`), outgoing HTTP via the `Http` facade
- [x] `ClientSensor` (`client.error`, `client.vital`, `client.event`, `client.pageview`), browser telemetry
- [ ] `CacheSensor` (`cache.hit`, `cache.miss`, `cache.write`, `cache.delete`), cache operations
- [ ] `MailSensor` (`mail.sent`), mail delivery
- [ ] `NotificationSensor` (`notification.sent`), notification delivery

## Testing

```bash
composer test
```

The browser agent has its own suite:

```bash
cd js && npm ci && npm test
```

## Credits

Sensor class layout inspired by [Laravel Nightwatch](https://github.com/laravel/nightwatch) (MIT).

## License

MIT
