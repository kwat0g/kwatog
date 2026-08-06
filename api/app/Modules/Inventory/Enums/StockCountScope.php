<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum StockCountScope: string
{
    case Full = 'full';
    case Warehouse = 'warehouse';
    case Zone = 'zone';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full count (all locations)',
            self::Warehouse => 'Single warehouse',
            self::Zone => 'Single zone',
        };
    }
}
