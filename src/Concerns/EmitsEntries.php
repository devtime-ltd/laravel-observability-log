<?php

namespace DevtimeLtd\LaravelObservabilityLog\Concerns;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

trait EmitsEntries
{
    /**
     * Resolve the PSR-3 level for an entry given its status. Sensors
     * with a binary success/failure shape (jobs, commands, scheduled
     * tasks) use "failed_level" for status === "failed" entries and
     * "level" for everything else.
     */
    protected static function levelForStatus(string $status): string
    {
        if ($status === 'failed') {
            $value = self::sensorConfig('failed_level', 'error');

            return is_string($value) && $value !== '' ? $value : 'error';
        }

        $value = self::sensorConfig('level', 'info');

        return is_string($value) && $value !== '' ? $value : 'info';
    }

    /**
     * Read a sensor-section config key, falling back to the same key at
     * the package's top level. Sensor-level wins when present even if
     * set to null or false (so an explicit override beats a top-level
     * default). The using class must declare a `CONFIG_PATH` constant
     * pointing at its config section, e.g. `'observability-log.requests'`.
     */
    protected static function sensorConfig(string $key, mixed $default = null): mixed
    {
        $sensorConfig = config(static::CONFIG_PATH);

        if (is_array($sensorConfig) && array_key_exists($key, $sensorConfig)) {
            return $sensorConfig[$key];
        }

        return config('observability-log.'.$key, $default);
    }

    /**
     * Resolve the client IP via the configured `resolve_ip` callable
     * (falling back to `$request->ip()` when it throws or returns a
     * non-string), then apply `obfuscate_ip` to the resolved value if
     * configured (its `null` return is accepted as the final value).
     * Both keys are read via sensorConfig, so a sensor-level value
     * overrides the top-level default. Returns null when there is no
     * bound request.
     */
    protected static function clientIp(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $ip = $request->ip();

        $resolver = self::sensorConfig('resolve_ip');
        if (is_callable($resolver)) {
            $resolved = self::callConfigCallable($resolver, 'resolve_ip', $request);
            if (is_string($resolved) && $resolved !== '') {
                $ip = $resolved;
            }
        }

        $obfuscate = self::sensorConfig('obfuscate_ip');
        if (is_callable($obfuscate)) {
            $masked = self::callConfigCallable($obfuscate, 'obfuscate_ip', $ip, $request);
            if (is_string($masked) || $masked === null) {
                $ip = $masked;
            }
        }

        return is_string($ip) ? $ip : null;
    }

    /** Invoke a callable read from config; log and return null on throw. */
    protected static function callConfigCallable(callable $callable, string $configKey, mixed ...$args): mixed
    {
        try {
            return $callable(...$args);
        } catch (Throwable $e) {
            try {
                Log::error(sprintf(
                    '[%s] %s callable threw: %s',
                    class_basename(static::class),
                    $configKey,
                    $e->getMessage()
                ));
            } catch (Throwable) {
            }

            return null;
        }
    }

    /**
     * Accepts a comma-separated string or an array of strings. Non-string
     * entries are dropped; null / scalar / object input resolves to [].
     *
     * @return list<string>
     */
    protected static function normaliseChannels(mixed $raw): array
    {
        if (is_string($raw)) {
            $entries = explode(',', $raw);
        } elseif (is_array($raw)) {
            $entries = $raw;
        } else {
            return [];
        }

        return collect($entries)
            ->map(static fn ($value) => is_string($value) ? trim($value) : '')
            ->filter(static fn (string $value) => $value !== '')
            ->values()
            ->all();
    }

    /**
     * No-op on empty channel list. Logger exceptions propagate to the caller.
     *
     * @param  array<string, mixed>  $entry
     */
    protected static function dispatchEntry(
        mixed $channelConfig,
        string $level,
        string $message,
        array $entry
    ): void {
        $channels = self::normaliseChannels($channelConfig);

        if ($channels === []) {
            return;
        }

        $logger = count($channels) === 1
            ? Log::channel($channels[0])
            : Log::stack($channels);

        $logger->log($level, $message, $entry);
    }

    /** Returns null and logs via Log::error when the callback throws. */
    protected static function safeCallback(Closure $callback, string $role, mixed ...$args): mixed
    {
        try {
            return $callback(...$args);
        } catch (Throwable $e) {
            try {
                Log::error(sprintf(
                    '[%s] %s callback threw: %s',
                    class_basename(static::class),
                    $role,
                    $e->getMessage()
                ));
            } catch (Throwable) {
            }

            return null;
        }
    }
}
