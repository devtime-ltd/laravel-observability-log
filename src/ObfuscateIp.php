<?php

namespace DevtimeLtd\LaravelObservabilityLog;

/**
 * Static IP masking methods, usable as `[ObfuscateIp::class, 'levelTwo']`
 * callables in config. Plain arrays so `php artisan config:cache` (which
 * serialises via var_export) does not choke on closures.
 */
class ObfuscateIp
{
    public static function levelOne(?string $ip): ?string
    {
        return self::mask($ip, 1);
    }

    public static function levelTwo(?string $ip): ?string
    {
        return self::mask($ip, 2);
    }

    public static function levelThree(?string $ip): ?string
    {
        return self::mask($ip, 3);
    }

    public static function levelFour(?string $ip): ?string
    {
        return self::mask($ip, 4);
    }

    private static function mask(?string $ip, int $level): ?string
    {
        if ($ip === null) {
            return null;
        }

        $packed = inet_pton($ip);
        $length = strlen($packed);
        $maskBytes = $length === 4 ? $level : $level * 4;

        return inet_ntop(substr($packed, 0, $length - $maskBytes).str_repeat("\0", $maskBytes));
    }
}
