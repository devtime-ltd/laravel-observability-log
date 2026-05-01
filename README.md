# Laravel Observability Log

A set of sensors that emit structured events through Laravel log channels. Whatever log driver you use (stack, Axiom, Better Stack, Papertrail, stderr) doubles as your observability pipeline.

Ships five sensors today (`RequestSensor`, `ExceptionSensor`, `JobSensor`, `CommandSensor`, `ScheduledTaskSensor`), with more on the [roadmap](#roadmap).

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
    'capture_headers' => env('OBSERVABILITY_LOG_CAPTURE_HEADERS', false),
    'db_collect_queries' => true,
    'db_slow_query_threshold' => 100,
    'db_slow_queries_max_count' => 100,
    // ...
];
```

`level` is the default for every entry. `failed_level` is used instead when a sensor emits an entry with `status: failed` (a failed job attempt, a non-zero command exit, a failed scheduled task), on `RequestSensor` for 5xx responses, and on every `ExceptionSensor` entry (every unhandled exception is considered a failure). `capture_headers` only applies to sensors that read it (request and exception). The `db_*` keys only apply to sensors that track DB stats (request, job, command, scheduled task).

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
| `ip`                 | Client IP (supports obfuscation, see [below](#ip-obfuscation))       |
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

```php
'requests' => [
    'message' => 'http.request',
    'obfuscate_ip' => false, // false or callable, e.g. ObfuscateIp::level(2)
],
```

Inherits the shared `channel`, `level`, `capture_headers`, and `db_*` defaults; override any of them by setting the same key inside this section.

### IP obfuscation

Mask client IPs using the built-in `ObfuscateIp` class:

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

Or pass any callable for custom masking, e.g. `fn (?string $ip) => 'redacted'`.

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

### Options

```php
'jobs' => [
    'queued_message' => 'job.queued',
    'attempt_message' => 'job.attempt',
],
```

Inherits the shared `channel`, `level`, and `db_*` defaults. The `db_*` keys behave the same as on the request sensor, scoped per attempt.

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

Inherits the shared `channel`, `level`, and `db_*` defaults.

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

### Options

```php
'schedule' => [
    'message' => 'schedule.task',
],
```

Inherits the shared `channel`, `level`, and `db_*` defaults.

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
- [ ] `CacheSensor` (`cache.hit`, `cache.miss`, `cache.write`, `cache.delete`), cache operations
- [ ] `OutgoingHttpSensor` (`http.outgoing`), outgoing HTTP via the `Http` facade plus optional Guzzle middleware
- [ ] `MailSensor` (`mail.sent`), mail delivery
- [ ] `NotificationSensor` (`notification.sent`), notification delivery
- [ ] `observability:mark-deploy` Artisan command, deploy markers for graph overlays

## Testing

```bash
composer test
```

## Credits

Sensor class layout inspired by [Laravel Nightwatch](https://github.com/laravel/nightwatch) (MIT).

## License

MIT
