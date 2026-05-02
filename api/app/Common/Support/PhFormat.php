<?php

declare(strict_types=1);

namespace App\Common\Support;

/**
 * Philippine government ID and contact normalization.
 *
 * Storage rule: persist as digits-only (no dashes / spaces).
 * Display rule: format on render (frontend).
 *
 *  SSS:        10 digits
 *  PhilHealth: 12 digits
 *  Pag-IBIG:   12 digits
 *  TIN:        9 or 12 digits
 *  PH mobile:  11 digits, must start with 09
 */
final class PhFormat
{
    public const SSS_LEN        = 10;
    public const PHILHEALTH_LEN = 12;
    public const PAGIBIG_LEN    = 12;
    public const TIN_MIN        = 9;
    public const TIN_MAX        = 12;
    public const MOBILE_LEN     = 11;

    /** Strip everything except digits. */
    public static function digitsOnly(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = preg_replace('/\D+/', '', $value) ?? '';
        return $clean === '' ? null : $clean;
    }

    public static function isValidSss(?string $value): bool
    {
        return $value !== null && strlen($value) === self::SSS_LEN && ctype_digit($value);
    }

    public static function isValidPhilHealth(?string $value): bool
    {
        return $value !== null && strlen($value) === self::PHILHEALTH_LEN && ctype_digit($value);
    }

    public static function isValidPagIbig(?string $value): bool
    {
        return $value !== null && strlen($value) === self::PAGIBIG_LEN && ctype_digit($value);
    }

    public static function isValidTin(?string $value): bool
    {
        if ($value === null || !ctype_digit($value)) return false;
        $n = strlen($value);
        return $n >= self::TIN_MIN && $n <= self::TIN_MAX;
    }

    public static function isValidMobile(?string $value): bool
    {
        return $value !== null
            && strlen($value) === self::MOBILE_LEN
            && ctype_digit($value)
            && str_starts_with($value, '09');
    }
}
