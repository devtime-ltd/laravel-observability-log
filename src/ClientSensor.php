<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Closure;
use DevtimeLtd\LaravelObservabilityLog\Concerns\EmitsEntries;
use DevtimeLtd\LaravelObservabilityLog\Support\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClientSensor
{
    use EmitsEntries;

    /** @var list<string> */
    public const KINDS = ['error', 'vital', 'event', 'pageview'];

    /** @var list<string> */
    public const VITALS = ['LCP', 'CLS', 'INP', 'TTFB', 'FCP'];

    /** @var list<string> */
    public const RATINGS = ['good', 'needs-improvement', 'poor'];

    /** @var list<string> */
    public const NAV_TYPES = ['load', 'spa'];

    protected const CONFIG_PATH = 'observability-log.client';

    private const TEXT_MAX = 500;

    /** @var (Closure(Request, string, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $usingCallback = null;

    /** @var (Closure(Request, string, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $extendCallback = null;

    /** @var (Closure(string, array<string, mixed>): string)|string|null */
    private static Closure|string|null $messageOverride = null;

    /**
     * Replace the default entry. Receives the raw browser payload, so
     * anything it keeps is unvalidated. Throw or non-array return falls
     * back to default.
     *
     * @param  (Closure(Request, string, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function using(?Closure $callback): void
    {
        self::$usingCallback = $callback;
    }

    /**
     * Add or override fields on the entry. Throw or non-array return keeps the previous entry.
     *
     * @param  (Closure(Request, string, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function extend(?Closure $callback): void
    {
        self::$extendCallback = $callback;
    }

    /**
     * Override the log message. Pass null to revert to the config default.
     *
     * @param  (Closure(string, array<string, mixed>): string)|string|null  $message
     */
    public static function message(Closure|string|null $message): void
    {
        self::$messageOverride = $message;
    }

    public static function enabled(): bool
    {
        return self::normaliseChannels(self::sensorConfig('channel')) !== [];
    }

    /** @return list<string> */
    public static function collecting(): array
    {
        $configured = self::sensorConfig('collect', []);

        return array_values(array_intersect(self::normaliseChannels($configured), self::KINDS));
    }

    public static function accepts(string $kind): bool
    {
        return in_array($kind, self::collecting(), true);
    }

    /** One browser entry. Anything unrecognised is dropped in silence. */
    public static function record(Request $request, mixed $raw): void
    {
        try {
            if (! self::enabled() || ! is_array($raw)) {
                return;
            }

            $kind = $raw['kind'] ?? null;

            if (! is_string($kind) || ! self::accepts($kind)) {
                return;
            }

            // Only an error counts as a failure here, so the rest go when an
            // app asks for failures only, as they do on every other sensor.
            if ($kind !== 'error' && self::sensorConfig('failures_only', false)) {
                return;
            }

            $entry = self::$usingCallback
                ? self::safeCallback(self::$usingCallback, 'using', $request, $kind, $raw)
                : null;

            if (! is_array($entry)) {
                $entry = self::buildEntry($request, $kind, $raw);
            }

            if ($entry === null) {
                return;
            }

            if (self::$extendCallback) {
                $result = self::safeCallback(self::$extendCallback, 'extend', $request, $kind, $entry);
                if (is_array($result)) {
                    $entry = $result;
                }
            }

            self::dispatchEntry(
                self::sensorConfig('channel'),
                self::levelForStatus($kind === 'error' ? 'failed' : 'success'),
                self::messageFor($kind, $entry),
                $entry
            );
        } catch (Throwable $e) {
            try {
                Log::error('[ClientSensor] '.$e->getMessage());
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private static function buildEntry(Request $request, string $kind, array $raw): ?array
    {
        $details = match ($kind) {
            'error' => self::errorFields($raw),
            'vital' => self::vitalFields($raw),
            'event' => self::eventFields($raw),
            'pageview' => self::pageviewFields($raw),
            default => null,
        };

        if ($details === null) {
            return null;
        }

        $url = self::text($raw['url'] ?? null);

        return array_merge([
            'kind' => $kind,
            'page_id' => self::text($raw['page_id'] ?? null, 64),
            // The page's trace id, not the beacon's, so the entry joins to
            // the http.request that rendered it.
            'trace_id' => self::text($raw['trace_id'] ?? null, 128) ?? RequestContext::traceId($request),
            'url' => $url,
            'host' => self::urlPart($url, PHP_URL_HOST),
            'path' => self::urlPart($url, PHP_URL_PATH),
            'referrer' => self::text($raw['referrer'] ?? null),
            'viewport' => self::viewport($raw['viewport'] ?? null),
            'user_agent' => $request->userAgent(),
            'ip' => self::clientIp($request),
        ], $details);
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private static function errorFields(array $raw): ?array
    {
        $message = self::text($raw['message'] ?? null);

        if ($message === null) {
            return null;
        }

        return [
            'error_message' => $message,
            'error_type' => self::text($raw['type'] ?? null, 120),
            'source' => self::text($raw['source'] ?? null),
            'line' => self::integer($raw['line'] ?? null),
            'col' => self::integer($raw['col'] ?? null),
            'stack' => self::text($raw['stack'] ?? null, self::intConfig('stack_max_bytes', 4096)),
            'handled' => (bool) ($raw['handled'] ?? false),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private static function vitalFields(array $raw): ?array
    {
        $name = $raw['name'] ?? null;
        $value = $raw['value'] ?? null;

        if (! is_string($name) || ! in_array($name, self::VITALS, true) || ! is_numeric($value)) {
            return null;
        }

        $rating = $raw['rating'] ?? null;

        return [
            'vital' => $name,
            'value' => round((float) $value, 4),
            'rating' => is_string($rating) && in_array($rating, self::RATINGS, true) ? $rating : null,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private static function eventFields(array $raw): ?array
    {
        $name = $raw['name'] ?? null;

        if (! is_string($name) || ! in_array($name, self::allowedEvents(), true)) {
            return null;
        }

        return [
            'event' => $name,
            'props' => self::props($raw['props'] ?? null),
        ];
    }

    /**
     * @param  array<mixed, mixed>  $raw
     * @return array<string, mixed>|null
     */
    private static function pageviewFields(array $raw): ?array
    {
        $navType = $raw['nav_type'] ?? null;

        return [
            'from' => self::text($raw['from'] ?? null),
            'to' => self::text($raw['to'] ?? null),
            'nav_type' => is_string($navType) && in_array($navType, self::NAV_TYPES, true) ? $navType : 'spa',
        ];
    }

    /** @return list<string> */
    private static function allowedEvents(): array
    {
        return self::normaliseChannels(self::sensorConfig('events', []));
    }

    /** @return array<string, scalar>|null */
    private static function props(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $max = self::intConfig('max_props', 10);
        $length = self::intConfig('prop_max_length', 200);
        $out = [];

        foreach ($raw as $key => $value) {
            if (count($out) >= $max) {
                break;
            }

            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $name = self::text($key, 60);
            $clean = is_string($value) ? self::text($value, $length) : $value;

            if ($name === null || $clean === null) {
                continue;
            }

            $out[$name] = $clean;
        }

        return $out === [] ? null : $out;
    }

    /** @param array<string, mixed> $entry */
    private static function messageFor(string $kind, array $entry): string
    {
        if (self::$messageOverride instanceof Closure) {
            $result = self::safeCallback(self::$messageOverride, 'message', $kind, $entry);

            if (is_string($result) && $result !== '') {
                return $result;
            }
        } elseif (is_string(self::$messageOverride) && self::$messageOverride !== '') {
            return self::$messageOverride;
        }

        $messages = self::sensorConfig('messages', []);

        if (is_array($messages) && isset($messages[$kind]) && is_string($messages[$kind]) && $messages[$kind] !== '') {
            return $messages[$kind];
        }

        return 'client.'.$kind;
    }

    private static function viewport(mixed $raw): ?string
    {
        if (! is_array($raw)) {
            return self::text($raw, 24);
        }

        $width = self::integer($raw['w'] ?? $raw[0] ?? null);
        $height = self::integer($raw['h'] ?? $raw[1] ?? null);

        return $width !== null && $height !== null ? $width.'x'.$height : null;
    }

    private static function urlPart(?string $url, int $component): ?string
    {
        if ($url === null) {
            return null;
        }

        $part = parse_url($url, $component);

        return is_string($part) && $part !== '' ? $part : null;
    }

    private static function text(mixed $value, ?int $max = null): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '') {
            return null;
        }

        $max ??= self::TEXT_MAX;

        if ($max > 0 && strlen($string) > $max) {
            $string = function_exists('mb_strcut')
                ? mb_strcut($string, 0, $max, 'UTF-8')
                : substr($string, 0, $max);
        }

        return $string;
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = self::sensorConfig($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
