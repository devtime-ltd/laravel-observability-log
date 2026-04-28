<?php

namespace DevtimeLtd\LaravelObservabilityLog\Concerns;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

trait EmitsEntries
{
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
