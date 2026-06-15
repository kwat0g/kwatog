<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum EmployeeTrainingStatus: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Expired   = 'expired';
    case Cancelled = 'cancelled';
}
