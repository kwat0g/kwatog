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

    /** Allocate a nonnegative amount proportionally without losing or inventing cents. */
    public static function allocate(string $total, array $weights): array
    {
        $sum = '0';
        foreach ($weights as $weight) {
            if (bccomp((string) $weight, '0', 12) < 0) throw new \InvalidArgumentException('Allocation weights must be nonnegative.');
            $sum = bcadd($sum, (string) $weight, 12);
        }
        if ($weights === []) return [];
        if (bccomp($sum, '0', 12) === 0) {
            $weights = array_fill_keys(array_keys($weights), '1');
            $sum = (string) count($weights);
        }
        $cents = bcmul(self::round2($total), '100', 0);
        if (bccomp($cents, '0', 0) < 0) throw new \InvalidArgumentException('Allocation amount must be nonnegative.');
        $values = $fractions = [];
        $assigned = '0';
        foreach ($weights as $index => $weight) {
            $exact = bcdiv(bcmul($cents, (string) $weight, 12), $sum, 12);
            $values[$index] = bcadd($exact, '0', 0);
            $fractions[$index] = bcsub($exact, $values[$index], 12);
            $assigned = bcadd($assigned, $values[$index], 0);
        }
        uasort($fractions, fn ($left, $right) => bccomp($right, $left, 12));
        $remaining = bcsub($cents, $assigned, 0);
        foreach (array_keys($fractions) as $index) {
            if (bccomp($remaining, '0', 0) <= 0) break;
            $values[$index] = bcadd($values[$index], '1', 0);
            $remaining = bcsub($remaining, '1', 0);
        }
        return array_map(fn ($value) => bcdiv($value, '100', 2), $values);
    }

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
