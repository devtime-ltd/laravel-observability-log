<?php

return [

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
    | Request logging
    |--------------------------------------------------------------------------
    |
    | Channel enables per-request logging via the RequestSensor middleware.
    | Comma-separated values or an array route to Log::stack(). Leave
    | unset to disable.
    |
    */

    'requests' => [

        'channel' => env('OBSERVABILITY_LOG_CHANNEL'),

        'message' => 'http.request',

        'level' => 'info',

        'obfuscate_ip' => false, // false or callable, e.g. ObfuscateIp::level(2)

        'collect_queries' => true,

        'slow_query_threshold' => 100, // null disables slow query collection

        // Cap the number of slow queries per request; a
        // ['truncated' => 'N more slow queries dropped'] marker is
        // appended when exceeded. null or 0 disables.
        'slow_queries_max_count' => 100,

        'capture_headers' => env('OBSERVABILITY_LOG_CAPTURE_HEADERS', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Exception logging
    |--------------------------------------------------------------------------
    |
    | Channel enables structured logging of every exception reported
    | through Laravel's ExceptionHandler. Respects Laravel's $dontReport
    | filter (ValidationException, AuthenticationException, etc. do not
    | appear). Leave unset to disable.
    |
    */

    'exceptions' => [

        'channel' => env('OBSERVABILITY_LOG_CHANNEL'),

        'message' => 'error.exception',

        'level' => 'error',

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

        // When trace_args is true, cap the number of frames emitted.
        // null or 0 disables; a ['truncated' => 'after N frames']
        // marker is appended when the cap is reached.
        'trace_args_max_frames' => 50,

        // Byte cap for the string trace. null or 0 disables. Applies
        // only to the string form; the trace_args array uses
        // trace_args_max_frames.
        'trace_max_bytes' => 16384,

        'capture_headers' => env('OBSERVABILITY_LOG_CAPTURE_HEADERS', false),

    ],

];
