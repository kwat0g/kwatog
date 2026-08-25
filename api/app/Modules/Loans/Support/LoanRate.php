<?php

declare(strict_types=1);

namespace App\Modules\Loans\Support;

use App\Common\Exceptions\BusinessRuleException;

/**
 * Canonical representation for annual loan rates.
 *
 * Rates are stored as fractions (0.105000 = 10.5%) and never pass through a
 * PHP float. Six decimal places are enough for the persisted policy column;
 * inputs with more precision are rejected instead of silently rounded.
 */
final class LoanRate
{
    public const SCALE = 6;

    public static function normalize(string $value): string
    {
        $value = trim($value);

        if (! preg_match('/^\d+(?:\.\d{1,6})?$/D', $value)) {
            throw new BusinessRuleException('Annual interest rate must be a decimal fraction with no more than 6 decimal places.');
        }

        if (bccomp($value, '0', self::SCALE) < 0 || bccomp($value, '1', self::SCALE) > 0) {
            throw new BusinessRuleException('Annual interest rate must be between 0 and 1.');
        }

        return self::trimTrailingZeros(bcadd($value, '0', self::SCALE));
    }

    public static function percent(string $fraction): string
    {
        $normalized = self::normalize($fraction);
        return self::trimTrailingZeros(bcmul($normalized, '100', 4));
    }

    private static function trimTrailingZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
