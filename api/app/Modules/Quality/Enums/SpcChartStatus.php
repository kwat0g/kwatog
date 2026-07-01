<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum SpcChartStatus: string
{
    case Active = 'active';
    case Monitoring = 'monitoring';
    case Suspended = 'suspended';
}
