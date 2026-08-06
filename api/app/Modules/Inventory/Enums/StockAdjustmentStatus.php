<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum StockAdjustmentStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::Pending  => 'Pending',
            self::Approved => 'Approved',
        };
    }
}
