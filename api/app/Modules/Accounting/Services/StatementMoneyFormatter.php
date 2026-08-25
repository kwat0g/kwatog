<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use InvalidArgumentException;

/**
 * Formats statement decimal strings without converting them through a float.
 *
 * Statement values are already scale-2 decimal strings. Keeping formatting
 * string-based prevents PDF output from drifting away from JSON/CSV values for
 * large balances or values near a rounding boundary.
 */
final class StatementMoneyFormatter
{
    public function format(string|int $value): string
    {
        $normalized = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $normalized)) {
            throw new InvalidArgumentException('Statement money must be a decimal string.');
        }

        $negative = str_starts_with($normalized, '-');
        $absolute = $negative ? substr($normalized, 1) : $normalized;
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        $cents = substr($fraction, 0, 2);
        if (isset($fraction[2]) && $fraction[2] >= '5') {
            $cents = bcadd($cents, '1', 0);
        }

        $scaled = bcadd(bcmul($whole, '100', 0), $cents, 0);
        $scaled = str_pad($scaled, 3, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($scaled, 0, -2), '0') ?: '0';
        $cents = substr($scaled, -2);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;

        return ($negative ? '-' : '').$whole.'.'.$cents;
    }
}
