<?php

namespace DevtimeLtd\LaravelObservabilityLog\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

class RequestContext
{
    public const REDACTED = '[redacted]';

    /** @return array<string, string|array<int, string>>|null */
    public static function headers(?Request $request): ?array
    {
        if ($request === null) {
            return null;
        }

        $redact = collect(config('observability-log.redact_headers', []))
            ->filter(static fn ($value) => is_string($value))
            ->map(static fn (string $value) => strtolower($value))
            ->flip()
            ->all();

        $valueCap = config('observability-log.header_value_max_length');

        $out = [];

        foreach ($request->headers->all() as $name => $values) {
            $lower = strtolower((string) $name);

            if (isset($redact[$lower])) {
                $out[$lower] = self::REDACTED;

                continue;
            }

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
        $value = self::resolveTraceId($request);

        if ($value === null) {
            return null;
        }

        return self::truncate(
            $value,
            config('observability-log.trace_id_max_length')
        );
    }

    private static function resolveTraceId(?Request $request): ?string
    {
        $config = config('observability-log.trace_id');

        if (is_callable($config)) {
            try {
                $result = call_user_func($config, $request);
            } catch (Throwable $e) {
                try {
                    Log::error('[RequestContext] trace_id callable threw: '.$e->getMessage());
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
            return self::stringOrNull(Context::get('trace_id'));
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
