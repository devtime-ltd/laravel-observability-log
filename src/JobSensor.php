<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Closure;
use DevtimeLtd\LaravelObservabilityLog\Concerns\EmitsEntries;
use DevtimeLtd\LaravelObservabilityLog\Concerns\TracksDatabaseQueries;
use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Log;
use Throwable;

class JobSensor
{
    use EmitsEntries;
    use TracksDatabaseQueries;

    /** @var (Closure(object, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $usingCallback = null;

    /** @var (Closure(object, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $extendCallback = null;

    /** @var (Closure(object): string)|string|null */
    private static Closure|string|null $messageOverride = null;

    /**
     * Per-attempt state keyed by spl_object_hash() of the queue Job. Each entry
     * snapshots the cumulative trait counters at attempt start so the emitted
     * delta covers exactly what happened during the attempt's wall-clock window
     * (including queries from any nested synchronous attempts dispatched inside).
     *
     * @var array<string, array{startedAt: float, queryCountBaseline: int, queryTotalMsBaseline: float, slowQueriesBaseline: int, slowDroppedBaseline: int}>
     */
    private array $attempts = [];

    /**
     * Replace the default entry. Throw or non-array return falls back to default.
     * Callback receives the queue event (JobQueued, JobProcessed, JobFailed, or
     * JobExceptionOccurred) plus a measurements array (empty for JobQueued).
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
     * Override the log message. Pass null to revert to the config defaults
     * (queued_message / attempt_message).
     *
     * @param  (Closure(object): string)|string|null  $message
     */
    public static function message(Closure|string|null $message): void
    {
        self::$messageOverride = $message;
    }

    public static function recordQueued(JobQueued $event): void
    {
        if (self::normaliseChannels(config('observability-log.jobs.channel')) === []) {
            return;
        }

        try {
            $entry = self::resolveEntry(
                $event,
                [],
                static fn () => self::buildQueuedEntry($event),
            );

            self::dispatchEntry(
                config('observability-log.jobs.channel'),
                config('observability-log.jobs.level'),
                self::resolveMessage($event, config('observability-log.jobs.queued_message')),
                $entry
            );
        } catch (Throwable $e) {
            self::reportInternalError($e);
        }
    }

    public static function recordProcessing(JobProcessing $event): void
    {
        if (self::normaliseChannels(config('observability-log.jobs.channel')) === []) {
            return;
        }

        app(self::class)->onProcessing($event);
    }

    public static function recordProcessed(JobProcessed $event): void
    {
        if (self::normaliseChannels(config('observability-log.jobs.channel')) === []) {
            return;
        }

        app(self::class)->emitAttempt($event, 'processed', null);
    }

    public static function recordExceptionOccurred(JobExceptionOccurred $event): void
    {
        if (self::normaliseChannels(config('observability-log.jobs.channel')) === []) {
            return;
        }

        app(self::class)->emitAttempt($event, 'failed', $event->exception);
    }

    public static function recordFailed(JobFailed $event): void
    {
        if (self::normaliseChannels(config('observability-log.jobs.channel')) === []) {
            return;
        }

        app(self::class)->emitAttempt($event, 'failed', $event->exception);
    }

    public static function recordQuery(QueryExecuted $query): void
    {
        $instance = app(self::class);

        if ($instance->attempts === []) {
            return;
        }

        $instance->trackQuery($query);
    }

    protected static function queryConfigPath(): string
    {
        return 'observability-log.jobs';
    }

    private function onProcessing(JobProcessing $event): void
    {
        $this->loadQueryConfig();

        if ($this->attempts === []) {
            $this->resetQueryStats();
        }

        if (! is_object($event->job)) {
            return;
        }

        $this->attempts[spl_object_hash($event->job)] = [
            'startedAt' => microtime(true),
            'queryCountBaseline' => $this->dbQueryCount,
            'queryTotalMsBaseline' => $this->dbQueryTotalMs,
            'slowQueriesBaseline' => count($this->dbSlowQueries),
            'slowDroppedBaseline' => $this->dbSlowQueriesDropped,
        ];
    }

    private function emitAttempt(object $event, string $status, ?Throwable $exception): void
    {
        $job = $event->job ?? null;
        if (! is_object($job)) {
            return;
        }

        $key = spl_object_hash($job);
        if (! isset($this->attempts[$key])) {
            return;
        }

        $attempt = $this->attempts[$key];
        unset($this->attempts[$key]);

        try {
            $measurements = $this->measurements(microtime(true) - $attempt['startedAt'], $attempt);
            $entry = self::resolveEntry(
                $event,
                $measurements,
                fn () => self::buildAttemptEntry($event, $status, $exception, $measurements),
            );

            self::dispatchEntry(
                config('observability-log.jobs.channel'),
                config('observability-log.jobs.level'),
                self::resolveMessage($event, config('observability-log.jobs.attempt_message')),
                $entry
            );
        } catch (Throwable $e) {
            self::reportInternalError($e);
        } finally {
            if ($this->attempts === []) {
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
     * @return array<string, mixed>
     */
    private static function buildQueuedEntry(JobQueued $event): array
    {
        $entry = [
            'class' => self::resolveQueuedJobClass($event->job),
            'queue' => $event->queue,
            'connection' => $event->connectionName,
            'job_id' => self::stringJobId($event->id),
            'payload_size' => strlen($event->payload),
        ];

        if ($event->delay !== null && $event->delay > 0) {
            $entry['delay'] = (int) $event->delay;
        }

        $traceId = RequestContext::traceId(null);
        if ($traceId !== null) {
            $entry['trace_id'] = $traceId;
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $measurements
     * @return array<string, mixed>
     */
    private static function buildAttemptEntry(
        object $event,
        string $status,
        ?Throwable $exception,
        array $measurements,
    ): array {
        $job = $event->job ?? null;

        $entry = [
            'class' => self::resolveAttemptJobClass($job),
            'queue' => self::callMethod($job, 'getQueue'),
            'connection' => $event->connectionName ?? null,
            'job_id' => self::stringJobId(self::callMethod($job, 'getJobId')),
            'attempt' => self::intOrNull(self::callMethod($job, 'attempts')),
            'status' => $status,
        ];

        $maxTries = self::callMethod($job, 'maxTries');
        if (is_int($maxTries) || (is_numeric($maxTries) && $maxTries > 0)) {
            $entry['max_tries'] = (int) $maxTries;
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

    private static function resolveQueuedJobClass(mixed $job): string
    {
        if (is_object($job)) {
            return get_class($job);
        }

        if (is_string($job) && $job !== '') {
            return $job;
        }

        return 'unknown';
    }

    private static function resolveAttemptJobClass(mixed $job): ?string
    {
        $name = self::callMethod($job, 'resolveName');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $name = self::callMethod($job, 'getName');

        return is_string($name) && $name !== '' ? $name : null;
    }

    private static function callMethod(mixed $job, string $method): mixed
    {
        if (! is_object($job) || ! method_exists($job, $method)) {
            return null;
        }

        try {
            return $job->{$method}();
        } catch (Throwable) {
            return null;
        }
    }

    private static function stringJobId(mixed $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (is_string($id) || is_int($id)) {
            return (string) $id;
        }

        return null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function reportInternalError(Throwable $e): void
    {
        try {
            Log::error('[JobSensor] '.$e->getMessage());
        } catch (Throwable) {
        }
    }
}
