<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Enums;

enum KpiUnit: string
{
    case Percentage = 'percentage';
    case Count = 'count';
    case Currency = 'currency';
    case Days = 'days';
    case Ratio = 'ratio';
}
