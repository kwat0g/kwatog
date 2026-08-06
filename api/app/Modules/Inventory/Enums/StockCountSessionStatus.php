<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum StockCountSessionStatus: string
{
    case Draft      = 'draft';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => 'Draft',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Cancelled  => 'Cancelled',
        };
    }
}
