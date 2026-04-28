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

    public const QUERY_LISTENER_BINDING = 'devtime-ltd.observability-log.job-query-listener-registered';

    /** @var (Closure(object, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $usingCallback = null;

    /** @var (Closure(object, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $extendCallback = null;

    /** @var (Closure(object): string)|string|null */
    private static Closure|string|null $messageOverride = null;

    private ?float $startedAt = null;

    /** True once a terminal event has emitted for the current attempt. */
    private bool $emitted = true;

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

        app(self::class)->onProcessing();
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

        if ($instance->startedAt === null) {
            return;
        }

        $instance->trackQuery($query);
    }

    protected static function queryConfigPath(): string
    {
        return 'observability-log.jobs';
    }

    protected static function queryListenerBinding(): string
    {
        return self::QUERY_LISTENER_BINDING;
    }

    private function onProcessing(): void
    {
        $this->configureQueryTracking();
        $this->startedAt = microtime(true);
        $this->emitted = false;
    }

    private function emitAttempt(object $event, string $status, ?Throwable $exception): void
    {
        if ($this->emitted || $this->startedAt === null) {
            return;
        }

        try {
            $measurements = $this->measurements();
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
            $this->emitted = true;
            $this->startedAt = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function measurements(): array
    {
        $elapsed = microtime(true) - ($this->startedAt ?? microtime(true));

        return array_merge(
            [
                'duration_ms' => round($elapsed * 1000, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ],
            $this->queryStats(),
        );
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
