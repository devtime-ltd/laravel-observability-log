# Changelog

## [Unreleased]

## [0.3.0] - 2026-04-28

### Added

- `JobSensor` listens to Laravel's queue lifecycle events and emits two structured entries: `job.queued` when a job is dispatched (one entry per dispatch), and `job.attempt` when a worker finishes or fails an attempt (one entry per attempt). `JobExceptionOccurred` and `JobFailed` are deduplicated so failed attempts produce exactly one entry. Registration is automatic through the service provider. Config section: `observability-log.jobs.*` (`channel`, `level`, `queued_message`, `attempt_message`, `collect_queries`, `slow_query_threshold`, `slow_queries_max_count`).
- Per-attempt DB query stats on `job.attempt` entries (`db_query_count`, `db_query_total_ms`, `db_slow_queries`) using the same controls as `RequestSensor`.
- `Throwable::context()` capture on `ExceptionSensor` entries, attached as `exception_context` on the root frame and `context` on each `previous[]` frame when present.

### Changed

- Extracted shared DB-query tracking logic into a `TracksDatabaseQueries` trait used by both `RequestSensor` and `JobSensor`. No behavioural change to existing request entries.

## [0.2.0] - 2026-04-20

### Breaking

- Renamed the middleware class `LogRequest` to `RequestSensor`. The static `::using()` / `::extend()` / `::message()` callbacks move to the new class unchanged.
- Env vars consolidated to two package-level toggles: `OBSERVABILITY_LOG_CHANNEL` (replaces `LOG_REQUESTS_CHANNEL`) and `OBSERVABILITY_LOG_CAPTURE_HEADERS`. `LOG_REQUESTS_MESSAGE` and `LOG_REQUESTS_LEVEL` are no longer env-backed and must be set in the published config file.
- Renamed DB-query stat fields on request entries: `query_count`, `query_total_ms`, `slow_queries` are now `db_query_count`, `db_query_total_ms`, `db_slow_queries`.

### Added

- `ExceptionSensor` hooks Laravel's exception reporter via `$handler->reportable()` and emits structured exception entries on the configured channel. Event name defaults to `error.exception`. Respects Laravel's `$dontReport` filter. Config section: `observability-log.exceptions.*`.
- Shared `trace_id` top-level field. Resolved via a configured callable, a configured header list (default `X-Request-Id`, `X-Trace-Id`, `X-Correlation-Id`), or `Context::get('trace_id')`. Capped at `observability-log.trace_id_max_length` bytes (default 128).
- Opt-in header capture on both sensors (`OBSERVABILITY_LOG_CAPTURE_HEADERS=true`). Captured headers are lowercased, emitted under `headers`, and values for `observability-log.redact_headers` entries are replaced with `[redacted]`. Default redact list: `authorization`, `cookie`, `set-cookie`, `proxy-authorization`, `x-csrf-token`, `x-xsrf-token`, `x-api-key`, `x-auth-token`, `x-access-token`, `x-session-token`. Per-value byte cap via `observability-log.header_value_max_length` (default 8192).
- Four new top-level fields on `RequestSensor` entries: `scheme`, `host`, `query_string`, `action`. `query_string` and `action` are omitted when empty.
- Per-request cap on `db_slow_queries` via `observability-log.requests.slow_queries_max_count` (default 100). A `['truncated' => 'N more slow queries dropped']` marker is appended when the cap is reached.
- Trace size controls on `ExceptionSensor`: `trace_max_bytes` (default 16384, truncates at the last frame boundary and is UTF-8 safe), `trace_args_max_frames` (default 50, appends a `['truncated' => 'after N frames']` marker), `previous_max_depth` (default 3, where `null` captures every level, `0` omits the field, positive int caps). All accept `null` or `0` to disable.
- `trace_args` config flag on `ExceptionSensor` (default `false`) to upgrade `trace` to the structured array form including argument values. Intentionally config-only. Requires `zend.exception_ignore_args=Off` in `php.ini` to observe arg values (PHP 7.4+ default is `On`).
- Channel config accepts either a comma-separated string or an array of strings. Whitespace is trimmed and empty entries are dropped.
- Long-running worker support (Octane, RoadRunner, Swoole). The DB query listener is lazily registered via a container-scoped flag so it does not accumulate across requests. User callbacks (`using`, `extend`, `message`, `trace_id` callable) run inside `try/catch`; throws fall back to the default and are logged via `Log::error()`.

### Changed

- `*Sensor` class-naming convention. Event names follow `{domain}.{event}`, lowercase dotted, two levels.

### Migrating from v0.1.0

1. Rename env vars: `LOG_REQUESTS_CHANNEL` becomes `OBSERVABILITY_LOG_CHANNEL` (package-level). `LOG_REQUESTS_MESSAGE` and `LOG_REQUESTS_LEVEL` are no longer env-backed; publish the config and edit `observability-log.requests.message` / `.level` (and the matching `exceptions` keys) there.
2. Update the middleware: replace `use DevtimeLtd\LaravelObservabilityLog\LogRequest;` with `use DevtimeLtd\LaravelObservabilityLog\RequestSensor;` and update the `->prepend(RequestSensor::class)` call. Rename any `LogRequest::using()` / `::extend()` / `::message()` calls in `AppServiceProvider`.
3. Update dashboard queries keyed on `query_count`, `query_total_ms`, or `slow_queries` to the `db_` prefixed names.
4. Republish the config if you had previously published it: `php artisan vendor:publish --tag=observability-log --force`.

## [0.1.0] - 2026-04-18

Initial release.

Extracted from [devtime-ltd/laravel-axiom-log](https://github.com/devtime-ltd/laravel-axiom-log) v0.3.0. The middleware is provider-agnostic and writes to any Laravel log channel, so it now lives in its own package.

- `LogRequest` middleware for structured request logging (method, URL, status, duration, query stats, memory, user, IP).
- `LogRequest::using()`, `LogRequest::extend()`, and `LogRequest::message()` callbacks for customising log entries.
- `ObfuscateIp` helper with four masking levels for IPv4 and IPv6.
- Database query tracking with configurable slow query threshold.
- Config moved to `config/observability-log.php` under a nested `requests` section to leave room for future sensors.

### Migrating from `devtime-ltd/laravel-axiom-log`

1. `composer require devtime-ltd/laravel-observability-log`
2. Update the middleware import from `DevtimeLtd\LaravelAxiomLog\LogRequest` to ~~`DevtimeLtd\LaravelObservabilityLog\LogRequest`~~ `DevtimeLtd\LaravelObservabilityLog\RequestSensor` (renamed in v0.2.0).
3. Republish the config: `php artisan vendor:publish --tag=observability-log` (the old `config/log-request.php` can be deleted).
4. ~~Existing `LOG_REQUESTS_*` env vars continue to work unchanged.~~ (env vars were renamed to `OBSERVABILITY_LOG_*` in v0.2.0 — see the v0.2.0 migration above.)

[0.3.0]: https://github.com/devtime-ltd/laravel-observability-log/releases/tag/v0.3.0
[0.2.0]: https://github.com/devtime-ltd/laravel-observability-log/releases/tag/v0.2.0
[0.1.0]: https://github.com/devtime-ltd/laravel-observability-log/releases/tag/v0.1.0
