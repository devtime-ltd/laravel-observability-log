<?php

namespace DevtimeLtd\LaravelObservabilityLog\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

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

    /** Dotted config path — collect_queries, slow_query_threshold, slow_queries_max_count are read from here. */
    abstract protected static function queryConfigPath(): string;

    /** Container key used to register DB::listen() exactly once per app. */
    abstract protected static function queryListenerBinding(): string;

    protected function configureQueryTracking(): void
    {
        $this->dbQueryCount = 0;
        $this->dbQueryTotalMs = 0;
        $this->dbSlowQueries = [];
        $this->dbSlowQueriesDropped = 0;

        $this->collectQueries = (bool) config(static::queryConfigPath().'.collect_queries');

        if ($this->collectQueries) {
            $this->slowQueryThreshold = self::resolveSlowQueryThreshold();
            $this->slowQueriesMaxCount = self::resolveSlowQueriesMaxCount();
            self::ensureQueryListener();
        }
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

    /** @return array<string, mixed> */
    protected function queryStats(): array
    {
        if (! $this->collectQueries) {
            return [];
        }

        $stats = [
            'db_query_count' => $this->dbQueryCount,
            'db_query_total_ms' => round($this->dbQueryTotalMs, 2),
        ];

        if ($this->dbSlowQueries || $this->dbSlowQueriesDropped > 0) {
            $slow = $this->dbSlowQueries;

            if ($this->dbSlowQueriesDropped > 0) {
                $slow[] = [
                    'truncated' => sprintf('%d more slow queries dropped', $this->dbSlowQueriesDropped),
                ];
            }

            $stats['db_slow_queries'] = $slow;
        }

        return $stats;
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

    /**
     * Register once per app. Container-bound flag so Octane reuses and
     * Testbench refreshes re-register against the new event dispatcher.
     */
    private static function ensureQueryListener(): void
    {
        $app = app();
        $binding = static::queryListenerBinding();

        if ($app->bound($binding)) {
            return;
        }

        DB::listen(function (QueryExecuted $query) {
            static::recordQuery($query);
        });

        $app->instance($binding, true);
    }
}
