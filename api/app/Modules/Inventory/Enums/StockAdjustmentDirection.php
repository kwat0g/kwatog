<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum StockAdjustmentDirection: string
{
    case In = 'in';
    case Out = 'out';
}
