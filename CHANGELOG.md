# Changelog

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
2. Update the middleware import from `DevtimeLtd\LaravelAxiomLog\LogRequest` to `DevtimeLtd\LaravelObservabilityLog\LogRequest`.
3. Republish the config: `php artisan vendor:publish --tag=observability-log` (the old `config/log-request.php` can be deleted).
4. Existing `LOG_REQUESTS_*` env vars continue to work unchanged.

[0.1.0]: https://github.com/devtime-ltd/laravel-observability-log/releases/tag/v0.1.0
