<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Channel
    |--------------------------------------------------------------------------
    |
    | Default log channel for every sensor. Comma-separated values or an
    | array route to Log::stack(). Leave unset to disable the whole
    | package; override per sensor by setting "channel" inside that
    | sensor's section.
    |
    */

    'channel' => env('OBSERVABILITY_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Log level
    |--------------------------------------------------------------------------
    |
    | Default PSR-3 level used by every sensor. Override per sensor by
    | setting "level" inside that sensor's section.
    |
    */

    'level' => 'info',

    /*
    |--------------------------------------------------------------------------
    | Failure log level
    |--------------------------------------------------------------------------
    |
    | PSR-3 level used for entries that represent a failure: a failed
    | job attempt, a non-zero command exit, a failed scheduled task,
    | a 5xx HTTP response (RequestSensor), or any exception reported
    | through Laravel's exception handler (ExceptionSensor uses this
    | for every entry; every unhandled exception is considered a
    | failure). Override per sensor by setting "failed_level" inside
    | that sensor's section.
    |
    */

    'failed_level' => 'error',

    /*
    |--------------------------------------------------------------------------
    | Failures-only emission
    |--------------------------------------------------------------------------
    |
    | Only emit entries that represent a failure: 5xx requests, failed
    | job attempts, non-zero command exits, failed scheduled tasks,
    | 5xx outgoing HTTP responses, and every ConnectionFailed entry.
    | Routine "success" entries (and job.queued, scheduled skipped)
    | are dropped. Useful when overall log volume is high and only
    | error traffic is interesting. Override per sensor by setting
    | the same key inside that sensor's section.
    |
    */

    'failures_only' => false,

    /*
    |--------------------------------------------------------------------------
    | Header capture
    |--------------------------------------------------------------------------
    |
    | Default for the request and exception sensors. Header names listed
    | in "redact_headers" below have their values replaced with
    | "[redacted]". Override per sensor by setting "capture_headers"
    | inside that sensor's section.
    |
    */

    'capture_headers' => env('OBSERVABILITY_LOG_CAPTURE_HEADERS', false),

    /*
    |--------------------------------------------------------------------------
    | Header redaction
    |--------------------------------------------------------------------------
    |
    | Header names (case-insensitive) whose values are replaced with
    | "[redacted]" when a sensor captures headers.
    |
    */

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
    ],

    /*
    |--------------------------------------------------------------------------
    | Header value max length
    |--------------------------------------------------------------------------
    |
    | Byte cap for each captured header value. null or 0 disables.
    |
    */

    'header_value_max_length' => 8192,

    /*
    |--------------------------------------------------------------------------
    | Trace ID resolution
    |--------------------------------------------------------------------------
    |
    | Array of request header names (first non-empty wins) or a callable
    | fn (?Illuminate\Http\Request $request): ?string. Falls back to
    | Illuminate\Support\Facades\Context::get('trace_id') when neither
    | resolves. Emitted as the top-level "trace_id" field.
    |
    | A closure here will break `php artisan config:cache` (var_export
    | cannot serialise closures). Use a static-method callable instead,
    | e.g. [App\Support\ResolveTraceId::class, 'resolve'] or
    | 'App\Support\ResolveTraceId::resolve'. Header-list arrays are
    | always cache-safe.
    |
    */

    'trace_id' => [
        'X-Request-Id',
        'X-Trace-Id',
        'X-Correlation-Id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trace ID max length
    |--------------------------------------------------------------------------
    |
    | Byte cap for the resolved trace_id. null or 0 disables.
    |
    */

    'trace_id_max_length' => 128,

    /*
    |--------------------------------------------------------------------------
    | Client IP resolution
    |--------------------------------------------------------------------------
    |
    | Callable `fn (Illuminate\Http\Request $request): ?string` invoked to
    | resolve the client IP. Useful when the IP is carried in a custom
    | header chain that Laravel's trusted proxy handling does not cover.
    | Falls back to $request->ip() when null, when the callable returns
    | non-string, or when it throws. Override per sensor by setting
    | "resolve_ip" inside that sensor's section. Applies to the request
    | and exception sensors.
    |
    */

    'resolve_ip' => null,

    /*
    |--------------------------------------------------------------------------
    | IP obfuscation
    |--------------------------------------------------------------------------
    |
    | Callable `fn (?string $ip, ?Illuminate\Http\Request $request = null): ?string`
    | applied to the resolved IP before it is logged. The request is
    | passed as an optional second arg for route-aware masking; callables
    | that declare a single `?string` parameter still work. Use the named
    | static methods on ObfuscateIp (levelOne..levelFour) so the config
    | remains var_export-safe under `php artisan config:cache`; a closure
    | here will break config caching. Set to null/false to log IPs
    | verbatim. Override per sensor by setting "obfuscate_ip" inside
    | that sensor's section. Applies to the request and exception sensors.
    |
    | Example:
    |   use DevtimeLtd\LaravelObservabilityLog\ObfuscateIp;
    |   'obfuscate_ip' => [ObfuscateIp::class, 'levelTwo'],
    |
    */

    'obfuscate_ip' => null,

    /*
    |--------------------------------------------------------------------------
    | Database query collection
    |--------------------------------------------------------------------------
    |
    | Defaults shared by every sensor that supports DB query stats
    | (requests, jobs, commands). Override per sensor by setting the
    | same key inside that sensor's section.
    |
    */

    'db_collect_queries' => true,

    'db_slow_query_threshold' => 100, // null disables slow query collection

    // Cap the number of slow queries per window (request, attempt,
    // command). A ['truncated' => 'N more slow queries dropped']
    // marker is appended when exceeded. null or 0 disables.
    'db_slow_queries_max_count' => 100,

    /*
    |--------------------------------------------------------------------------
    | Request logging
    |--------------------------------------------------------------------------
    |
    | RequestSensor middleware. Emits "http.request" per request.
    |
    */

    'requests' => [

        'message' => 'http.request',

    ],

    /*
    |--------------------------------------------------------------------------
    | Exception logging
    |--------------------------------------------------------------------------
    |
    | Auto-registered through Laravel's ExceptionHandler. Respects
    | Laravel's $dontReport filter (ValidationException,
    | AuthenticationException, etc. do not appear).
    |
    */

    'exceptions' => [

        'message' => 'error.exception',

        'ignore' => [
            // e.g. \Illuminate\Auth\AuthenticationException::class
        ],

        // Depth of the "previous" exception chain.
        //   null: capture the full chain, unbounded.
        //   0 (or negative): omit the "previous" field entirely.
        //   positive int: cap at that many levels.
        'previous_max_depth' => 3,

        // Include the stack trace (as a string, argument values stripped).
        'trace' => true,

        // Upgrade "trace" to the structured array form from getTrace(),
        // including actual argument values. Off by default: arg values can
        // be secrets (passwords, API keys). See the README for the
        // zend.exception_ignore_args prerequisite.
        'trace_args' => false,

        // Cap on the number of frames emitted in the array form (only
        // used when trace_args is true). null or 0 disables; a
        // ['truncated' => 'after N frames'] marker is appended when
        // the cap is reached.
        'trace_args_max_frames' => 50,

        // Byte cap on the string form of the trace. null or 0 disables.
        'trace_string_max_bytes' => 16384,

    ],

    /*
    |--------------------------------------------------------------------------
    | Job logging
    |--------------------------------------------------------------------------
    |
    | Emits "job.queued" when a job is dispatched and "job.attempt"
    | once per worker attempt (whether it succeeds or fails).
    |
    */

    'jobs' => [

        'queued_message' => 'job.queued',

        'attempt_message' => 'job.attempt',

    ],

    /*
    |--------------------------------------------------------------------------
    | Console command logging
    |--------------------------------------------------------------------------
    |
    | Emits "console.command" once per Artisan command that finishes
    | (whether successfully or with a non-zero exit code).
    |
    */

    'commands' => [

        'message' => 'console.command',

        // Skip specific command names. Useful for noisy or long-running
        // commands you do not want a log entry for, e.g. schedule:run
        // (fires every minute) or queue:work (never finishes normally).
        'ignore' => [
            // 'schedule:run',
            // 'schedule:work',
            // 'queue:work',
            // 'queue:listen',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled task logging
    |--------------------------------------------------------------------------
    |
    | Emits "schedule.task" once per scheduled task execution: status
    | success, failed, or skipped (when the task was due but a filter
    | such as withoutOverlapping prevented it from running).
    |
    */

    'schedule' => [

        'message' => 'schedule.task',

    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing HTTP logging
    |--------------------------------------------------------------------------
    |
    | Emits "http.outgoing" once per outgoing HTTP request dispatched
    | through Laravel's Http facade (RequestSending / ResponseReceived /
    | ConnectionFailed events). Captures method, url, host, path,
    | status, response_size, duration_ms, and exception on
    | connection failure.
    |
    */

    'outgoing_http' => [

        'message' => 'http.outgoing',

        // Include the query string portion of the URL. Off by default
        // because outgoing query strings often carry API keys, OAuth
        // params, or signed-URL secrets you may not want logged.
        // When true, the full URL is emitted and the raw query is
        // also surfaced as a separate "query_string" field.
        'capture_query_string' => false,

        // Skip outgoing requests to these hosts entirely. Matches
        // the host portion case-insensitively. Useful for noisy or
        // sensitive destinations (e.g. an auth provider).
        'ignore_hosts' => [
            // 'login.example.com',
        ],

    ],

];
