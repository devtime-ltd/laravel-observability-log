<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Closure;
use DevtimeLtd\LaravelObservabilityLog\Concerns\EmitsEntries;
use DevtimeLtd\LaravelObservabilityLog\Concerns\TracksDatabaseQueries;
use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Throwable;

class CommandSensor
{
    use EmitsEntries;
    use TracksDatabaseQueries;

    protected const CONFIG_PATH = 'observability-log.commands';

    /** @var (Closure(CommandFinished, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $usingCallback = null;

    /** @var (Closure(CommandFinished, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $extendCallback = null;

    /** @var (Closure(CommandFinished): string)|string|null */
    private static Closure|string|null $messageOverride = null;

    /**
     * Per-command state keyed by spl_object_hash() of the InputInterface.
     * The same input instance is passed to both CommandStarting and
     * CommandFinished, so the hash matches across the pair. Nested
     * Artisan::call() invocations get distinct entries.
     *
     * @var array<string, array{startedAt: float, memoryPeakBaseline: int, queryCountBaseline: int, queryTotalMsBaseline: float, slowQueriesBaseline: int, slowDroppedBaseline: int}>
     */
    private array $commands = [];

    /**
     * Replace the default entry. Throw or non-array return falls back to default.
     *
     * @param  (Closure(CommandFinished, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function using(?Closure $callback): void
    {
        self::$usingCallback = $callback;
    }

    /**
     * Add or override fields on the entry. Throw or non-array return keeps the previous entry.
     *
     * @param  (Closure(CommandFinished, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function extend(?Closure $callback): void
    {
        self::$extendCallback = $callback;
    }

    /**
     * Override the log message. Pass null to revert to the config default.
     *
     * @param  (Closure(CommandFinished): string)|string|null  $message
     */
    public static function message(Closure|string|null $message): void
    {
        self::$messageOverride = $message;
    }

    public static function recordStarting(CommandStarting $event): void
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return;
        }

        if (self::isIgnored($event->command)) {
            return;
        }

        app(self::class)->onStarting($event);
    }

    public static function recordFinished(CommandFinished $event): void
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return;
        }

        if (self::isIgnored($event->command)) {
            return;
        }

        app(self::class)->emitFinished($event);
    }

    public static function recordQuery(QueryExecuted $query): void
    {
        $instance = app(self::class);

        if ($instance->commands === []) {
            return;
        }

        $instance->trackQuery($query);
    }

    private function onStarting(CommandStarting $event): void
    {
        $this->loadQueryConfig();

        if ($this->commands === []) {
            $this->resetQueryStats();
        }

        $this->commands[spl_object_hash($event->input)] = [
            'startedAt' => microtime(true),
            'memoryPeakBaseline' => memory_get_peak_usage(true),
            'queryCountBaseline' => $this->dbQueryCount,
            'queryTotalMsBaseline' => $this->dbQueryTotalMs,
            'slowQueriesBaseline' => count($this->dbSlowQueries),
            'slowDroppedBaseline' => $this->dbSlowQueriesDropped,
        ];
    }

    private function emitFinished(CommandFinished $event): void
    {
        $key = spl_object_hash($event->input);
        if (! isset($this->commands[$key])) {
            return;
        }

        $command = $this->commands[$key];
        unset($this->commands[$key]);

        try {
            $measurements = $this->measurements(microtime(true) - $command['startedAt'], $command);
            $entry = self::resolveEntry(
                $event,
                $measurements,
                fn () => self::buildEntry($event, $measurements),
            );

            self::dispatchEntry(
                self::sensorConfig('channel'),
                self::sensorConfig('level', 'info'),
                self::resolveMessage($event, self::sensorConfig('message', 'console.command')),
                $entry
            );
        } catch (Throwable $e) {
            self::reportInternalError($e);
        } finally {
            if ($this->commands === []) {
                $this->resetQueryStats();
            }
        }
    }

    private static function isIgnored(string $command): bool
    {
        $ignore = self::sensorConfig('ignore', []);

        if (! is_array($ignore)) {
            return false;
        }

        foreach ($ignore as $entry) {
            if (is_string($entry) && $entry === $command) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $measurements
     * @param  callable(): array<string, mixed>  $defaultBuilder
     * @return array<string, mixed>
     */
    private static function resolveEntry(CommandFinished $event, array $measurements, callable $defaultBuilder): array
    {
        if (self::$usingCallback) {
            $result = self::safeCallback(self::$usingCallback, 'using', $event, $measurements);
            $entry = is_array($result) ? $result : $defaultBuilder();
        } else {
            $entry = $defaultBuilder();
        }

        if (self::$extendCallback) {
            $result = self::safeCallback(self::$extendCallback, 'extend', $event, $entry);
            if (is_array($result)) {
                $entry = $result;
            }
        }

        return $entry;
    }

    private static function resolveMessage(CommandFinished $event, mixed $default): string
    {
        if (self::$messageOverride instanceof Closure) {
            $result = self::safeCallback(self::$messageOverride, 'message', $event);
            if (is_string($result)) {
                return $result;
            }
        } elseif (self::$messageOverride !== null) {
            return self::$messageOverride;
        }

        return is_string($default) ? $default : '';
    }

    /**
     * @param  array<string, mixed>  $measurements
     * @return array<string, mixed>
     */
    private static function buildEntry(CommandFinished $event, array $measurements): array
    {
        $entry = [
            'command' => $event->command,
            'exit_code' => $event->exitCode,
            'status' => $event->exitCode === 0 ? 'success' : 'failed',
        ];

        $traceId = RequestContext::traceId(null);
        if ($traceId !== null) {
            $entry['trace_id'] = $traceId;
        }

        return array_merge($entry, $measurements);
    }

    private static function reportInternalError(Throwable $e): void
    {
        try {
            Log::error('[CommandSensor] '.$e->getMessage());
        } catch (Throwable) {
        }
    }
}
