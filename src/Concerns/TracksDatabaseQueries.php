<?php

namespace DevtimeLtd\LaravelObservabilityLog\Concerns;

use Illuminate\Database\Events\QueryExecuted;

trait TracksDatabaseQueries
{
    private bool $collectQueries = false;

    private ?int $slowQueryThreshold = null;

    /** null = unbounded; positive int caps, extra matches bump $dbSlowQueriesDropped. */
    private ?int $slowQueriesMaxCount = null;

    private int $dbQueryCount = 0;

    private float $dbQueryTotalMs = 0;

    /** @var list<array{sql: string, duration_ms: float, connection: string}> */
    private array $dbSlowQueries = [];

    private int $dbSlowQueriesDropped = 0;

    /** Dotted config path - collect_queries, slow_query_threshold, slow_queries_max_count are read from here. */
    abstract protected static function queryConfigPath(): string;

    protected function configureQueryTracking(): void
    {
        $this->resetQueryStats();
        $this->loadQueryConfig();
    }

    protected function loadQueryConfig(): void
    {
        $this->collectQueries = (bool) config(static::queryConfigPath().'.collect_queries');

        if ($this->collectQueries) {
            $this->slowQueryThreshold = self::resolveSlowQueryThreshold();
            $this->slowQueriesMaxCount = self::resolveSlowQueriesMaxCount();
        }
    }

    protected function resetQueryStats(): void
    {
        $this->dbQueryCount = 0;
        $this->dbQueryTotalMs = 0;
        $this->dbSlowQueries = [];
        $this->dbSlowQueriesDropped = 0;
    }

    protected function trackQuery(QueryExecuted $query): void
    {
        if (! $this->collectQueries) {
            return;
        }

        $this->dbQueryCount++;
        $this->dbQueryTotalMs += $query->time;

        if ($this->slowQueryThreshold !== null && $query->time >= $this->slowQueryThreshold) {
            if ($this->slowQueriesMaxCount !== null
                && count($this->dbSlowQueries) >= $this->slowQueriesMaxCount) {
                $this->dbSlowQueriesDropped++;

                return;
            }

            $this->dbSlowQueries[] = [
                'sql' => $query->sql,
                'duration_ms' => round($query->time, 2),
                'connection' => $query->connectionName,
            ];
        }
    }

    /**
     * Standard measurements payload: duration_ms + memory_peak_mb + db_* fields.
     *
     * Pass $baselines (snapshot of the trait counters at the window's start)
     * to emit deltas for the window only. Omit them to emit the cumulative
     * counters - which is the right call when the sensor's instance lifetime
     * is the same as the window (e.g. a per-request middleware).
     *
     * `memory_peak_mb` is the rise in `memory_get_peak_usage()` since
     * `memoryPeakBaseline`. Without a baseline it falls back to the process
     * peak, which is only accurate when the process is fresh (FPM-style).
     * On long-lived workers the baseline must be supplied or the value will
     * be the historical process peak rather than the attempt's own peak.
     *
     * @param  array{memoryPeakBaseline?: int, queryCountBaseline?: int, queryTotalMsBaseline?: float, slowQueriesBaseline?: int, slowDroppedBaseline?: int}|null  $baselines
     * @return array<string, mixed>
     */
    protected function measurements(float $elapsed, ?array $baselines = null): array
    {
        $memoryPeakBaseline = $baselines['memoryPeakBaseline'] ?? 0;
        $memoryPeakBytes = max(0, memory_get_peak_usage(true) - $memoryPeakBaseline);

        $payload = [
            'duration_ms' => round($elapsed * 1000, 2),
            'memory_peak_mb' => round($memoryPeakBytes / 1024 / 1024, 2),
        ];

        if (! $this->collectQueries) {
            return $payload;
        }

        $queryCountBaseline = $baselines['queryCountBaseline'] ?? 0;
        $queryTotalMsBaseline = $baselines['queryTotalMsBaseline'] ?? 0;
        $slowQueriesBaseline = $baselines['slowQueriesBaseline'] ?? 0;
        $slowDroppedBaseline = $baselines['slowDroppedBaseline'] ?? 0;

        $payload['db_query_count'] = $this->dbQueryCount - $queryCountBaseline;
        $payload['db_query_total_ms'] = round($this->dbQueryTotalMs - $queryTotalMsBaseline, 2);

        $slow = array_slice($this->dbSlowQueries, $slowQueriesBaseline);
        $dropped = $this->dbSlowQueriesDropped - $slowDroppedBaseline;

        if ($slow || $dropped > 0) {
            if ($dropped > 0) {
                $slow[] = [
                    'truncated' => sprintf('%d more slow queries dropped', $dropped),
                ];
            }

            $payload['db_slow_queries'] = $slow;
        }

        return $payload;
    }

    private static function resolveSlowQueryThreshold(): ?int
    {
        $value = config(static::queryConfigPath().'.slow_query_threshold');

        if ($value === null || $value === false) {
            return null;
        }

        return is_int($value) || is_numeric($value) ? (int) $value : null;
    }

    private static function resolveSlowQueriesMaxCount(): ?int
    {
        $value = config(static::queryConfigPath().'.slow_queries_max_count');

        if ($value === null || $value === false) {
            return null;
        }

        if (! is_int($value) && ! is_numeric($value)) {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
