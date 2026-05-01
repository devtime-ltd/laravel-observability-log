<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Closure;
use DevtimeLtd\LaravelObservabilityLog\Concerns\EmitsEntries;
use DevtimeLtd\LaravelObservabilityLog\Concerns\TracksDatabaseQueries;
use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RequestSensor
{
    use EmitsEntries;
    use TracksDatabaseQueries;

    public const CURRENT_INSTANCE_BINDING = 'devtime-ltd.observability-log.request-sensor-instance';

    protected const CONFIG_PATH = 'observability-log.requests';

    /** @var (Closure(Request, ?Response, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $usingCallback = null;

    /** @var (Closure(Request, ?Response, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $extendCallback = null;

    /** @var (Closure(Request, ?Response): string)|string|null */
    private static Closure|string|null $messageOverride = null;

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

        if ($instance instanceof self) {
            $instance->trackQuery($query);
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return $next($request);
        }

        $this->configureQueryTracking();

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

    private function log(Request $request, ?Response $response, float $elapsed): void
    {
        try {
            $statusCode = $response?->getStatusCode();
            $level = self::levelForStatus(
                $statusCode !== null && $statusCode >= 500 ? 'failed' : 'success'
            );

            $measurements = $this->measurements($elapsed);

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

            $message = self::sensorConfig('message', 'http.request');

            if (self::$messageOverride instanceof Closure) {
                $result = self::safeCallback(self::$messageOverride, 'message', $request, $response);
                if (is_string($result)) {
                    $message = $result;
                }
            } elseif (self::$messageOverride !== null) {
                $message = self::$messageOverride;
            }

            self::dispatchEntry(
                self::sensorConfig('channel'),
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
        $maskIp = self::sensorConfig('obfuscate_ip');

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

        if ($response && $response->isRedirect()) {
            $entry['redirect_to'] = $response->headers->get('Location');
        }

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

        if (self::sensorConfig('capture_headers')) {
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
