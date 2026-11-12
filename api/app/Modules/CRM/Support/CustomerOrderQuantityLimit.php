<?php

declare(strict_types=1);

namespace App\Modules\CRM\Support;

final class CustomerOrderQuantityLimit
{
    public static function error(string $quantity, string $minimum, ?string $maximum, string $unit, bool $allowAboveMaximum = false): ?string
    {
        if (bccomp($quantity, $minimum, 3) < 0) {
            return 'Minimum order quantity is '.self::display($minimum).' '.$unit.'.';
        }

        if (! $allowAboveMaximum && $maximum !== null && bccomp($quantity, $maximum, 3) > 0) {
            return 'Maximum order quantity is '.self::display($maximum).' '.$unit.'.';
        }

        return null;
    }

    private static function display(string $quantity): string
    {
        if (! str_contains($quantity, '.')) {
            return $quantity;
        }

        return rtrim(rtrim($quantity, '0'), '.') ?: '0';
    }
}
