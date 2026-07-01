<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Enums;

enum KpiDirection: string
{
    case HigherIsBetter = 'higher_is_better';
    case LowerIsBetter = 'lower_is_better';
}
