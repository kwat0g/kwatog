<?php

declare(strict_types=1);

namespace App\Common\Support;

/**
 * Money math helpers built on PHP BCMath. All operations take + return strings
 * to preserve decimal precision for currency. Never use float for money.
 *
 * Convention: peso amounts use scale 2; intermediate computations use scale 4.
 */
final class Money
{
    public const SCALE = 2;
    public const INNER = 4;

    public static function zero(): string
    {
        return '0.00';
    }

    public static function add(string|float|int ...$values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, (string) $v, self::INNER);
        }
        return self::round2($sum);
    }

    public static function sub(string|float|int $a, string|float|int $b): string
    {
        return self::round2(bcsub((string) $a, (string) $b, self::INNER));
    }

    public static function mul(string|float|int $a, string|float|int $b): string
    {
        return self::round2(bcmul((string) $a, (string) $b, self::INNER));
    }

    public static function div(string|float|int $a, string|float|int $b, int $scale = self::INNER): string
    {
        if (bccomp((string) $b, '0', self::INNER) === 0) return '0.00';
        return bcdiv((string) $a, (string) $b, $scale);
    }

    public static function cmp(string|float|int $a, string|float|int $b): int
    {
        return bccomp((string) $a, (string) $b, self::SCALE);
    }

    public static function gt(string|float|int $a, string|float|int $b): bool
    {
        return self::cmp($a, $b) > 0;
    }

    public static function gte(string|float|int $a, string|float|int $b): bool
    {
        return self::cmp($a, $b) >= 0;
    }

    public static function lt(string|float|int $a, string|float|int $b): bool
    {
        return self::cmp($a, $b) < 0;
    }

    public static function lte(string|float|int $a, string|float|int $b): bool
    {
        return self::cmp($a, $b) <= 0;
    }

    public static function isZero(string|float|int $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function clampMin(string $v, string $min): string
    {
        return self::lt($v, $min) ? self::round2($min) : self::round2($v);
    }

    public static function negate(string|float|int $v): string
    {
        return self::round2(bcmul((string) $v, '-1', self::INNER));
    }

    /**
     * Half-up rounding to scale 2.
     * BCMath defaults to truncation; we add 0.005 (sign-aware) and re-truncate.
     */
    public static function round2(string|float|int $v): string
    {
        $val = (string) $v;
        $isNeg = bccomp($val, '0', self::INNER) < 0;
        $abs = $isNeg ? ltrim($val, '-') : $val;
        $rounded = bcadd($abs, '0.005', self::SCALE);
        return $isNeg ? bcmul($rounded, '-1', self::SCALE) : bcadd($rounded, '0', self::SCALE);
    }
}
