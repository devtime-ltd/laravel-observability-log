<?php

namespace DevtimeLtd\LaravelObservabilityLog;

use Closure;

class ObfuscateIp
{
    /** @var array<int, Closure> */
    private static array $closures = [];

    /**
     * @param  int  $level  1-4 octets to mask (e.g. 198.51.100.123, level 1: 198.51.100.0, level 4: 0.0.0.0)
     */
    public static function level(int $level): Closure
    {
        if ($level < 1 || $level > 4) {
            throw new \InvalidArgumentException('IP masking level must be between 1 and 4.');
        }

        return static::$closures[$level] ??= fn (?string $ip) => static::mask($ip, $level);
    }

    private static function mask(?string $ip, int $level): ?string
    {
        if ($ip === null) {
            return null;
        }

        $packed = inet_pton($ip);
        $length = strlen($packed);
        $maskBytes = $length === 4 ? $level : $level * 4;

        return inet_ntop(substr($packed, 0, $length - $maskBytes) . str_repeat("\0", $maskBytes));
    }
}
