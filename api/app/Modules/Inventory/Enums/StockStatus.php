<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum StockStatus: string
{
    case Critical = 'critical';
    case Low = 'low';
    case Ok = 'ok';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical',
            self::Low => 'Low',
            self::Ok => 'OK',
        };
    }
}
