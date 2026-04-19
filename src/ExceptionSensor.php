<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Closure;
use DevtimeLtd\LaravelObservabilityLog\Concerns\EmitsEntries;
use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExceptionSensor
{
    use EmitsEntries;

    public const REPORTING_BINDING = 'devtime-ltd.observability-log.exception-reporting';

    /** @var (Closure(Throwable): array<string, mixed>)|null */
    private static ?Closure $usingCallback = null;

    /** @var (Closure(Throwable, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $extendCallback = null;

    /** @var (Closure(Throwable): string)|string|null */
    private static Closure|string|null $messageOverride = null;

    /**
     * Replace the default entry. Throw or non-array return falls back to default.
     *
     * @param  (Closure(Throwable): array<string, mixed>)|null  $callback
     */
    public static function using(?Closure $callback): void
    {
        self::$usingCallback = $callback;
    }

    /**
     * Add or override fields on the entry. Throw or non-array return keeps the previous entry.
     *
     * @param  (Closure(Throwable, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function extend(?Closure $callback): void
    {
        self::$extendCallback = $callback;
    }

    /**
     * Override the log message. Pass null to revert to the config default.
     *
     * @param  (Closure(Throwable): string)|string|null  $message
     */
    public static function message(Closure|string|null $message): void
    {
        self::$messageOverride = $message;
    }

    public static function report(Throwable $e): void
    {
        if (self::normaliseChannels(config('observability-log.exceptions.channel')) === []) {
            return;
        }

        $app = app();

        if ($app->bound(self::REPORTING_BINDING)) {
            return;
        }

        $ignored = collect(config('observability-log.exceptions.ignore', []))
            ->contains(static fn ($class) => is_string($class) && is_a($e, $class));

        if ($ignored) {
            return;
        }

        $app->instance(self::REPORTING_BINDING, true);

        try {
            self::write($e);
        } catch (Throwable $logError) {
            try {
                Log::error('[ExceptionSensor] '.$logError->getMessage());
            } catch (Throwable) {
            }
        } finally {
            $app->forgetInstance(self::REPORTING_BINDING);
        }
    }

    private static function write(Throwable $e): void
    {
        $level = config('observability-log.exceptions.level');

        if (self::$usingCallback) {
            $result = self::safeCallback(self::$usingCallback, 'using', $e);
            $entry = is_array($result) ? $result : self::buildEntry($e);
        } else {
            $entry = self::buildEntry($e);
        }

        if (self::$extendCallback) {
            $result = self::safeCallback(self::$extendCallback, 'extend', $e, $entry);
            if (is_array($result)) {
                $entry = $result;
            }
        }

        $message = config('observability-log.exceptions.message');

        if (self::$messageOverride instanceof Closure) {
            $result = self::safeCallback(self::$messageOverride, 'message', $e);
            if (is_string($result)) {
                $message = $result;
            }
        } elseif (self::$messageOverride !== null) {
            $message = self::$messageOverride;
        }

        self::dispatchEntry(
            config('observability-log.exceptions.channel'),
            $level,
            $message,
            $entry
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildEntry(Throwable $e): array
    {
        $entry = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
        ];

        if (config('observability-log.exceptions.trace', true)) {
            if (config('observability-log.exceptions.trace_args', false)) {
                $entry['trace'] = self::capTraceFrames($e->getTrace());
            } else {
                $entry['trace'] = self::truncateTrace($e->getTraceAsString());
            }
        }

        $previous = self::buildPrevious($e);
        if ($previous) {
            $entry['previous'] = $previous;
        }

        $app = app();
        $inHttp = $app->resolved(HttpKernelContract::class);
        $request = $inHttp ? self::resolveRequest($app) : null;

        if ($request) {
            $entry['method'] = $request->method();
            $entry['url'] = $request->fullUrl();
            $entry['route'] = $request->route()?->getName();
            $entry['user_id'] = $request->user()?->getAuthIdentifier();
            $entry['ip'] = $request->ip();

            if (config('observability-log.exceptions.capture_headers')) {
                $headers = RequestContext::headers($request);
                if ($headers !== null) {
                    $entry['headers'] = $headers;
                }
            }
        } elseif ($app->runningInConsole()) {
            $command = self::resolveCommand();
            if ($command !== null) {
                $entry['command'] = $command;
            }
        }

        $traceId = RequestContext::traceId($request);
        if ($traceId !== null) {
            $entry['trace_id'] = $traceId;
        }

        return $entry;
    }

    private static function truncateTrace(string $trace): string
    {
        $max = config('observability-log.exceptions.trace_max_bytes');

        if (! is_numeric($max)) {
            return $trace;
        }

        $max = (int) $max;

        if ($max <= 0 || strlen($trace) <= $max) {
            return $trace;
        }

        $cut = function_exists('mb_strcut')
            ? mb_strcut($trace, 0, $max, 'UTF-8')
            : substr($trace, 0, $max);

        $lastNewline = strrpos($cut, "\n");

        if ($lastNewline !== false && $lastNewline > 0) {
            $cut = substr($cut, 0, $lastNewline);
        }

        return $cut.sprintf("\n... [truncated at %d bytes]", $max);
    }

    /**
     * @param  list<array<string, mixed>>  $frames
     * @return list<array<string, mixed>>
     */
    private static function capTraceFrames(array $frames): array
    {
        $max = config('observability-log.exceptions.trace_args_max_frames');

        if (! is_numeric($max)) {
            return $frames;
        }

        $max = (int) $max;

        if ($max <= 0 || count($frames) <= $max) {
            return $frames;
        }

        $capped = array_slice($frames, 0, $max);
        $capped[] = ['truncated' => sprintf('after %d frames', $max)];

        return $capped;
    }

    /**
     * previous_max_depth: null unbounded, 0 or negative omits, positive caps.
     *
     * @return list<array{class: string, message: string}>
     */
    private static function buildPrevious(Throwable $e): array
    {
        $max = config('observability-log.exceptions.previous_max_depth');

        $max = is_numeric($max) ? (int) $max : null;

        if ($max !== null && $max <= 0) {
            return [];
        }

        $hasLimit = $max !== null && $max > 0;

        $out = [];
        $previous = $e->getPrevious();
        $depth = 0;

        while ($previous !== null) {
            if ($hasLimit && $depth >= $max) {
                break;
            }

            $out[] = [
                'class' => get_class($previous),
                'message' => $previous->getMessage(),
            ];
            $previous = $previous->getPrevious();
            $depth++;
        }

        return $out;
    }

    private static function resolveRequest(Application $app): ?Request
    {
        try {
            if (! $app->bound('request')) {
                return null;
            }

            $request = $app['request'];

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolveCommand(): ?string
    {
        if (! isset($_SERVER['argv'][1])) {
            return null;
        }

        $candidate = $_SERVER['argv'][1];

        if (! is_string($candidate) || $candidate === '' || str_starts_with($candidate, '-')) {
            return null;
        }

        return $candidate;
    }
}
