<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Enums;

enum KpiStatus: string
{
    case OnTarget = 'on_target';
    case Warning = 'warning';
    case OffTarget = 'off_target';
}
