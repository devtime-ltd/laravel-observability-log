<?php

namespace DevtimeLtd\LaravelObservabilityLog\Support;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

class RequestContext
{
    public const REDACTED = '[redacted]';

    /** The browser, the running copy of the page in it, and the visit within that. */
    public const CLIENT_IDS = ['client_id', 'client_instance_id', 'visit_id'];

    private const CLIENT_ID_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/';

    /** @return array<string, string|array<int, string>>|null */
    public static function headers(?Request $request): ?array
    {
        if ($request === null) {
            return null;
        }

        return self::redactHeaders($request->headers->all());
    }

    /** @return array<string, string|array<int, string>>|null */
    public static function clientHeaders(?ClientRequest $request): ?array
    {
        if ($request === null) {
            return null;
        }

        return self::redactHeaders($request->headers());
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string|array<int, string>>
     */
    private static function redactHeaders(array $headers): array
    {
        $redact = collect(config('observability-log.redact_headers', []))
            ->filter(static fn ($value) => is_string($value))
            ->map(static fn (string $value) => strtolower($value))
            ->flip()
            ->all();

        $valueCap = config('observability-log.header_value_max_length');

        $out = [];

        foreach ($headers as $name => $values) {
            $lower = strtolower((string) $name);

            if (isset($redact[$lower])) {
                $out[$lower] = self::REDACTED;

                continue;
            }

            $values = is_array($values) ? $values : [$values];

            if (count($values) === 1) {
                $out[$lower] = self::truncate((string) $values[0], $valueCap);
            } else {
                $out[$lower] = array_map(
                    static fn ($value) => self::truncate((string) $value, $valueCap),
                    $values
                );
            }
        }

        return $out;
    }

    /** Resolves via configured callable, configured header list, then Context::get('trace_id'). */
    public static function traceId(?Request $request): ?string
    {
        $value = self::resolve('trace_id', $request);

        if ($value === null) {
            return null;
        }

        return self::truncate(
            $value,
            config('observability-log.trace_id_max_length')
        );
    }

    /**
     * The client ids known for this request, each resolved via its
     * configured callable, configured header list, then Context. The
     * headers are the browser's to set, so anything but a short plain
     * token is dropped.
     *
     * @return array<string, string>
     */
    public static function clientIds(?Request $request): array
    {
        $ids = [];

        foreach (self::enabledClientIds() as $name) {
            $id = self::validClientId(self::resolve($name, $request));

            if ($id !== null) {
                $ids[$name] = $id;
            }
        }

        return $ids;
    }

    /**
     * An id whose config is null is switched off: not sent, not read, not logged.
     *
     * @return list<string>
     */
    public static function enabledClientIds(): array
    {
        return array_values(array_filter(
            self::CLIENT_IDS,
            static fn (string $name) => config('observability-log.'.$name) !== null
        ));
    }

    public static function validClientId(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::CLIENT_ID_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * Puts the request's client ids on Context, where every log record
     * and every job the request dispatches picks them up.
     *
     * @param  array<string, string>|null  $ids  share these rather than the request's own
     * @return array<string, string>
     */
    public static function shareClientIds(?Request $request, ?array $ids = null): array
    {
        try {
            $ids = $ids === null
                ? self::clientIds($request)
                : array_intersect_key($ids, array_flip(self::enabledClientIds()));

            foreach ($ids as $name => $id) {
                if (Context::get($name) !== $id) {
                    Context::add($name, $id);
                }
            }

            return $ids;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The header the browser agent sends each client id in, where one is configured.
     *
     * @return array<string, string|null>
     */
    public static function clientIdHeaders(): array
    {
        $headers = [];

        foreach (self::CLIENT_IDS as $name) {
            $config = config('observability-log.'.$name);
            $header = is_array($config) && ! is_callable($config) ? (array_values($config)[0] ?? null) : null;

            $headers[$name] = is_string($header) && $header !== '' ? $header : null;
        }

        return $headers;
    }

    private static function resolve(string $key, ?Request $request): ?string
    {
        $config = config('observability-log.'.$key);

        if (is_callable($config)) {
            try {
                $result = call_user_func($config, $request);
            } catch (Throwable $e) {
                try {
                    Log::error('[RequestContext] '.$key.' callable threw: '.$e->getMessage());
                } catch (Throwable) {
                }

                return null;
            }

            return self::stringOrNull($result);
        }

        if (is_array($config) && $request !== null) {
            foreach ($config as $header) {
                $value = $request->header($header);

                $resolved = self::stringOrNull($value);

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        try {
            return self::stringOrNull(Context::get($key));
        } catch (Throwable) {
            return null;
        }
    }

    /** Non-positive / non-numeric $max disables the cap. */
    private static function truncate(string $value, mixed $max): string
    {
        if (is_int($max)) {
            $limit = $max;
        } elseif (is_numeric($max)) {
            $limit = (int) $max;
        } else {
            return $value;
        }

        if ($limit <= 0 || strlen($value) <= $limit) {
            return $value;
        }

        return function_exists('mb_strcut')
            ? mb_strcut($value, 0, $limit, 'UTF-8')
            : substr($value, 0, $limit);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        if (is_object($value) && ! method_exists($value, '__toString')) {
            return null;
        }

        try {
            $string = (string) $value;
        } catch (Throwable) {
            return null;
        }

        return $string === '' ? null : $string;
    }
}
