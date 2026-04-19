<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Closure;
use DevtimeLtd\LaravelObservabilityLog\Concerns\EmitsEntries;
use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RequestSensor
{
    use EmitsEntries;

    public const QUERY_LISTENER_BINDING = 'devtime-ltd.observability-log.query-listener-registered';

    public const CURRENT_INSTANCE_BINDING = 'devtime-ltd.observability-log.request-sensor-instance';

    /** @var (Closure(Request, ?Response, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $usingCallback = null;

    /** @var (Closure(Request, ?Response, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $extendCallback = null;

    /** @var (Closure(Request, ?Response): string)|string|null */
    private static Closure|string|null $messageOverride = null;

    private bool $collectQueries = false;

    private ?int $slowQueryThreshold = null;

    /** null = unbounded; positive int caps, extra matches bump $dbSlowQueriesDropped. */
    private ?int $slowQueriesMaxCount = null;

    private int $dbQueryCount = 0;

    private float $dbQueryTotalMs = 0;

    /** @var list<array{sql: string, duration_ms: float, connection: string}> */
    private array $dbSlowQueries = [];

    private int $dbSlowQueriesDropped = 0;

    /**
     * Replace the default entry. Throw or non-array return falls back to default.
     *
     * @param  (Closure(Request, ?Response, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function using(?Closure $callback): void
    {
        self::$usingCallback = $callback;
    }

    /**
     * Add or override fields on the entry. Throw or non-array return keeps the previous entry.
     *
     * @param  (Closure(Request, ?Response, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function extend(?Closure $callback): void
    {
        self::$extendCallback = $callback;
    }

    /**
     * Override the log message. Pass null to revert to the config default.
     *
     * @param  (Closure(Request, ?Response): string)|string|null  $message
     */
    public static function message(Closure|string|null $message): void
    {
        self::$messageOverride = $message;
    }

    public static function recordQuery(QueryExecuted $query): void
    {
        $app = app();

        if (! $app->bound(self::CURRENT_INSTANCE_BINDING)) {
            return;
        }

        $instance = $app->make(self::CURRENT_INSTANCE_BINDING);

        if (! $instance instanceof self || ! $instance->collectQueries) {
            return;
        }

        $instance->dbQueryCount++;
        $instance->dbQueryTotalMs += $query->time;

        if ($instance->slowQueryThreshold !== null && $query->time >= $instance->slowQueryThreshold) {
            if ($instance->slowQueriesMaxCount !== null
                && count($instance->dbSlowQueries) >= $instance->slowQueriesMaxCount) {
                $instance->dbSlowQueriesDropped++;

                return;
            }

            $instance->dbSlowQueries[] = [
                'sql' => $query->sql,
                'duration_ms' => round($query->time, 2),
                'connection' => $query->connectionName,
            ];
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (self::normaliseChannels(config('observability-log.requests.channel')) === []) {
            return $next($request);
        }

        $this->collectQueries = (bool) config('observability-log.requests.collect_queries');

        if ($this->collectQueries) {
            $this->slowQueryThreshold = self::resolveSlowQueryThreshold();
            $this->slowQueriesMaxCount = self::resolveSlowQueriesMaxCount();
            self::ensureQueryListener();
        }

        $app = app();
        $start = microtime(true);
        $app->instance(self::CURRENT_INSTANCE_BINDING, $this);

        try {
            $response = $next($request);
            $this->log($request, $response, microtime(true) - $start);

            return $response;
        } catch (Throwable $e) {
            $this->log($request, null, microtime(true) - $start);

            throw $e;
        } finally {
            $app->forgetInstance(self::CURRENT_INSTANCE_BINDING);
        }
    }

    private static function resolveSlowQueryThreshold(): ?int
    {
        $value = config('observability-log.requests.slow_query_threshold');

        if ($value === null || $value === false) {
            return null;
        }

        return is_int($value) || is_numeric($value) ? (int) $value : null;
    }

    private static function resolveSlowQueriesMaxCount(): ?int
    {
        $value = config('observability-log.requests.slow_queries_max_count');

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

        if ($app->bound(self::QUERY_LISTENER_BINDING)) {
            return;
        }

        DB::listen(function (QueryExecuted $query) {
            self::recordQuery($query);
        });

        $app->instance(self::QUERY_LISTENER_BINDING, true);
    }

    private function log(Request $request, ?Response $response, float $elapsed): void
    {
        try {
            $level = config('observability-log.requests.level');

            $measurements = [
                'duration_ms' => round($elapsed * 1000, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ];

            if ($this->collectQueries) {
                $measurements['db_query_count'] = $this->dbQueryCount;
                $measurements['db_query_total_ms'] = round($this->dbQueryTotalMs, 2);

                if ($this->dbSlowQueries || $this->dbSlowQueriesDropped > 0) {
                    $slow = $this->dbSlowQueries;

                    if ($this->dbSlowQueriesDropped > 0) {
                        $slow[] = [
                            'truncated' => sprintf('%d more slow queries dropped', $this->dbSlowQueriesDropped),
                        ];
                    }

                    $measurements['db_slow_queries'] = $slow;
                }
            }

            if (self::$usingCallback) {
                $result = self::safeCallback(self::$usingCallback, 'using', $request, $response, $measurements);
                $entry = is_array($result)
                    ? $result
                    : $this->buildEntry($request, $response, $measurements);
            } else {
                $entry = $this->buildEntry($request, $response, $measurements);
            }

            if (self::$extendCallback) {
                $result = self::safeCallback(self::$extendCallback, 'extend', $request, $response, $entry);
                if (is_array($result)) {
                    $entry = $result;
                }
            }

            $message = config('observability-log.requests.message');

            if (self::$messageOverride instanceof Closure) {
                $result = self::safeCallback(self::$messageOverride, 'message', $request, $response);
                if (is_string($result)) {
                    $message = $result;
                }
            } elseif (self::$messageOverride !== null) {
                $message = self::$messageOverride;
            }

            self::dispatchEntry(
                config('observability-log.requests.channel'),
                $level,
                $message,
                $entry
            );
        } catch (Throwable $e) {
            try {
                Log::error('[RequestSensor] '.$e->getMessage());
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param  array<string, mixed>  $measurements
     * @return array<string, mixed>
     */
    private function buildEntry(Request $request, ?Response $response, array $measurements): array
    {
        $ip = $request->ip();
        $maskIp = config('observability-log.requests.obfuscate_ip');

        if (is_callable($maskIp)) {
            $ip = call_user_func($maskIp, $ip);
        }

        try {
            if ($response instanceof BinaryFileResponse) {
                $responseSize = $response->getFile()->getSize() ?: null;
            } elseif ($response instanceof StreamedResponse || ! $response) {
                $responseSize = null;
            } else {
                $responseSize = strlen($response->getContent());
            }
        } catch (Throwable) {
            $responseSize = null;
        }

        $entry = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
            'path' => $request->path(),
            'route' => $request->route()?->getName(),
            'status' => $response?->getStatusCode(),
            'content_type' => $response?->headers->get('Content-Type'),
            'response_size' => $responseSize,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'ip' => $ip,
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('referer'),
        ];

        $queryString = $request->getQueryString();
        if ($queryString !== null && $queryString !== '') {
            $entry['query_string'] = $queryString;
        }

        $action = $request->route()?->getActionName();
        if ($action !== null && $action !== '') {
            $entry['action'] = $action;
        }

        $routeParams = $request->route()?->originalParameters();
        if ($routeParams) {
            $entry['route_params'] = $routeParams;
        }

        if (config('observability-log.requests.capture_headers')) {
            $headers = RequestContext::headers($request);
            if ($headers !== null) {
                $entry['headers'] = $headers;
            }
        }

        $traceId = RequestContext::traceId($request);
        if ($traceId !== null) {
            $entry['trace_id'] = $traceId;
        }

        return array_merge($entry, $measurements);
    }
}
