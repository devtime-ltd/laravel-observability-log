<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Closure;
use DevtimeLtd\LaravelObservabilityLog\Concerns\EmitsEntries;
use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

class OutgoingHttpSensor
{
    use EmitsEntries;

    protected const CONFIG_PATH = 'observability-log.outgoing_http';

    /** @var (Closure(object, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $usingCallback = null;

    /** @var (Closure(object, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $extendCallback = null;

    /** @var (Closure(object): string)|string|null */
    private static Closure|string|null $messageOverride = null;

    /**
     * Per-request state keyed by spl_object_hash() of the
     * Illuminate\Http\Client\Request instance. Same instance is
     * passed through RequestSending and ResponseReceived /
     * ConnectionFailed, so the hash matches across the pair.
     *
     * @var array<string, array{startedAt: float}>
     */
    private array $requests = [];

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

    public static function recordSending(RequestSending $event): void
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return;
        }

        if (self::isIgnored($event->request->url())) {
            return;
        }

        app(self::class)->onSending($event);
    }

    public static function recordReceived(ResponseReceived $event): void
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return;
        }

        if (self::isIgnored($event->request->url())) {
            return;
        }

        app(self::class)->emitReceived($event);
    }

    public static function recordConnectionFailed(ConnectionFailed $event): void
    {
        if (self::normaliseChannels(self::sensorConfig('channel')) === []) {
            return;
        }

        if (self::isIgnored($event->request->url())) {
            return;
        }

        app(self::class)->emitConnectionFailed($event);
    }

    private function onSending(RequestSending $event): void
    {
        $this->requests[spl_object_hash($event->request)] = [
            'startedAt' => microtime(true),
        ];
    }

    private function emitReceived(ResponseReceived $event): void
    {
        $key = spl_object_hash($event->request);
        $startedAt = $this->requests[$key]['startedAt'] ?? null;
        unset($this->requests[$key]);

        if ($startedAt === null) {
            return;
        }

        try {
            $statusCode = $event->response->status();
            $bucket = $statusCode >= 500 ? 'failed' : 'success';

            if ($bucket !== 'failed' && self::sensorConfig('failures_only', false)) {
                return;
            }

            $measurements = ['duration_ms' => round((microtime(true) - $startedAt) * 1000, 2)];

            $entry = self::resolveEntry(
                $event,
                $measurements,
                fn () => self::buildReceivedEntry($event, $measurements),
            );

            self::dispatchEntry(
                self::sensorConfig('channel'),
                self::levelForStatus($bucket),
                self::resolveMessage($event, self::sensorConfig('message', 'http.outgoing')),
                $entry
            );
        } catch (Throwable $e) {
            self::reportInternalError($e);
        }
    }

    private function emitConnectionFailed(ConnectionFailed $event): void
    {
        $key = spl_object_hash($event->request);
        $startedAt = $this->requests[$key]['startedAt'] ?? null;
        unset($this->requests[$key]);

        try {
            $measurements = $startedAt !== null
                ? ['duration_ms' => round((microtime(true) - $startedAt) * 1000, 2)]
                : [];

            $entry = self::resolveEntry(
                $event,
                $measurements,
                fn () => self::buildConnectionFailedEntry($event, $measurements),
            );

            self::dispatchEntry(
                self::sensorConfig('channel'),
                self::levelForStatus('failed'),
                self::resolveMessage($event, self::sensorConfig('message', 'http.outgoing')),
                $entry
            );
        } catch (Throwable $e) {
            self::reportInternalError($e);
        }
    }

    private static function isIgnored(string $url): bool
    {
        $ignore = self::sensorConfig('ignore_hosts', []);

        if (! is_array($ignore) || $ignore === []) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return false;
        }

        foreach ($ignore as $entry) {
            if (is_string($entry) && strcasecmp($entry, $host) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $measurements
     * @return array<string, mixed>
     */
    private static function buildReceivedEntry(ResponseReceived $event, array $measurements): array
    {
        $entry = self::buildCommonFields($event->request);

        $entry['status'] = $event->response->status();

        try {
            $entry['response_size'] = strlen($event->response->body());
        } catch (Throwable) {
        }

        $traceId = RequestContext::traceId(null);
        if ($traceId !== null) {
            $entry['trace_id'] = $traceId;
        }

        return array_merge($entry, $measurements);
    }

    /**
     * @param  array<string, mixed>  $measurements
     * @return array<string, mixed>
     */
    private static function buildConnectionFailedEntry(ConnectionFailed $event, array $measurements): array
    {
        $entry = self::buildCommonFields($event->request);

        $exception = $event->exception;
        $entry['exception'] = [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
        ];

        $traceId = RequestContext::traceId(null);
        if ($traceId !== null) {
            $entry['trace_id'] = $traceId;
        }

        return array_merge($entry, $measurements);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildCommonFields(ClientRequest $request): array
    {
        $captureQuery = (bool) self::sensorConfig('capture_query_string', false);
        $url = $request->url();
        $parts = parse_url($url);

        $entry = [
            'method' => $request->method(),
            'url' => $captureQuery ? $url : self::stripQuery($url),
        ];

        if (is_array($parts)) {
            if (isset($parts['host']) && is_string($parts['host'])) {
                $entry['host'] = $parts['host'];
            }
            if (isset($parts['path']) && is_string($parts['path'])) {
                $entry['path'] = $parts['path'];
            }
            if ($captureQuery && isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
                $entry['query_string'] = $parts['query'];
            }
        }

        if (self::sensorConfig('capture_headers')) {
            $headers = RequestContext::clientHeaders($request);
            if ($headers !== null) {
                $entry['headers'] = $headers;
            }
        }

        return $entry;
    }

    private static function stripQuery(string $url): string
    {
        $pos = strpos($url, '?');

        return $pos === false ? $url : substr($url, 0, $pos);
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

    private static function reportInternalError(Throwable $e): void
    {
        try {
            Log::error('[OutgoingHttpSensor] '.$e->getMessage());
        } catch (Throwable) {
        }
    }
}
