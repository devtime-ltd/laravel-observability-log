<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Closure;
use DevtimeLtd\LaravelObservabilityLog\Concerns\EmitsEntries;
use DevtimeLtd\LaravelObservabilityLog\Concerns\TracksDatabaseQueries;
use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScheduledTaskSensor
{
    use EmitsEntries;
    use TracksDatabaseQueries;

    protected const CONFIG_PATH = 'observability-log.schedule';

    /** @var (Closure(object, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $usingCallback = null;

    /** @var (Closure(object, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $extendCallback = null;

    /** @var (Closure(object): string)|string|null */
    private static Closure|string|null $messageOverride = null;

    /**
     * Per-task state keyed by spl_object_hash() of the scheduled Event.
     * Same instance flows through Starting and Finished/Failed.
     *
     * @var array<string, array{startedAt: float, memoryPeakBaseline: int, queryCountBaseline: int, queryTotalMsBaseline: float, slowQueriesBaseline: int, slowDroppedBaseline: int}>
     */
    private array $tasks = [];

    /**
     * Replace the default entry. Throw or non-array return falls back to default.
     *
     * @param  (Closure(object, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function using(?Closure $callback): void
    {
        self::$usingCallback = $callback;
    }

    /**
     * Add or override fields on the entry. Throw or non-array return keeps the previous entry.
     *
     * @param  (Closure(object, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function extend(?Closure $callback): void
    {
        self::$extendCallback = $callback;
    }

    /**
     * Override the log message. Pass null to revert to the config default.
     *
     * @param  (Closure(object): string)|string|null  $message
     */
    public static function message(Closure|string|null $message): void
    {
        self::$messageOverride = $message;
    }

    public static function recordStarting(ScheduledTaskStarting $event): void
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return;
        }

        app(self::class)->onStarting($event);
    }

    public static function recordFinished(ScheduledTaskFinished $event): void
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return;
        }

        app(self::class)->emitTerminal($event, 'success', null);
    }

    public static function recordBackgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return;
        }

        app(self::class)->emitTerminal($event, 'success', null);
    }

    public static function recordFailed(ScheduledTaskFailed $event): void
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return;
        }

        app(self::class)->emitTerminal($event, 'failed', $event->exception);
    }

    public static function recordSkipped(ScheduledTaskSkipped $event): void
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return;
        }

        // Skipped tasks have no Starting event, so there is nothing to
        // measure. Emit a minimal entry so the skip is still observable.
        try {
            $entry = self::resolveEntry(
                $event,
                [],
                fn () => self::buildEntry($event->task, 'skipped', null, []),
            );

            self::dispatchEntry(
                self::sensorConfig('channel'),
                self::sensorConfig('level', 'info'),
                self::resolveMessage($event, self::sensorConfig('message', 'schedule.task')),
                $entry
            );
        } catch (Throwable $e) {
            self::reportInternalError($e);
        }
    }

    public static function recordQuery(QueryExecuted $query): void
    {
        $instance = app(self::class);

        if ($instance->tasks === []) {
            return;
        }

        $instance->trackQuery($query);
    }

    private function onStarting(ScheduledTaskStarting $event): void
    {
        $this->loadQueryConfig();

        if ($this->tasks === []) {
            $this->resetQueryStats();
        }

        $this->tasks[spl_object_hash($event->task)] = [
            'startedAt' => microtime(true),
            'memoryPeakBaseline' => memory_get_peak_usage(true),
            'queryCountBaseline' => $this->dbQueryCount,
            'queryTotalMsBaseline' => $this->dbQueryTotalMs,
            'slowQueriesBaseline' => count($this->dbSlowQueries),
            'slowDroppedBaseline' => $this->dbSlowQueriesDropped,
        ];
    }

    private function emitTerminal(object $event, string $status, ?Throwable $exception): void
    {
        $task = $event->task ?? null;
        if (! $task instanceof ScheduledEvent) {
            return;
        }

        $key = spl_object_hash($task);
        if (! isset($this->tasks[$key])) {
            return;
        }

        $state = $this->tasks[$key];
        unset($this->tasks[$key]);

        try {
            $measurements = $this->measurements(microtime(true) - $state['startedAt'], $state);
            $entry = self::resolveEntry(
                $event,
                $measurements,
                fn () => self::buildEntry($task, $status, $exception, $measurements),
            );

            self::dispatchEntry(
                self::sensorConfig('channel'),
                self::sensorConfig('level', 'info'),
                self::resolveMessage($event, self::sensorConfig('message', 'schedule.task')),
                $entry
            );
        } catch (Throwable $e) {
            self::reportInternalError($e);
        } finally {
            if ($this->tasks === []) {
                $this->resetQueryStats();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $measurements
     * @param  callable(): array<string, mixed>  $defaultBuilder
     * @return array<string, mixed>
     */
    private static function resolveEntry(object $event, array $measurements, callable $defaultBuilder): array
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

    private static function resolveMessage(object $event, mixed $default): string
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
    private static function buildEntry(
        ScheduledEvent $task,
        string $status,
        ?Throwable $exception,
        array $measurements,
    ): array {
        $entry = [
            'task' => self::stringOrNull($task->getSummaryForDisplay()),
            'expression' => self::stringOrNull($task->getExpression()),
            'status' => $status,
        ];

        $timezone = $task->timezone ?? null;
        $timezoneString = self::stringOrNull(is_object($timezone) ? (string) $timezone : $timezone);
        if ($timezoneString !== null) {
            $entry['timezone'] = $timezoneString;
        }

        if ($exception !== null) {
            $entry['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'code' => $exception->getCode(),
            ];
        }

        $traceId = RequestContext::traceId(null);
        if ($traceId !== null) {
            $entry['trace_id'] = $traceId;
        }

        return array_merge($entry, $measurements);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private static function reportInternalError(Throwable $e): void
    {
        try {
            Log::error('[ScheduledTaskSensor] '.$e->getMessage());
        } catch (Throwable) {
        }
    }
}
