<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Enums;

enum KpiTrend: string
{
    case Up = 'up';
    case Down = 'down';
    case Flat = 'flat';
}
