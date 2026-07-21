<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum SalaryAdjustmentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
