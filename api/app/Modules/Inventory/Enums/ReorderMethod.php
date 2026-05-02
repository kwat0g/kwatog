<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum ReorderMethod: string
{
    case FixedQuantity = 'fixed_quantity';
    case DaysOfSupply  = 'days_of_supply';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
