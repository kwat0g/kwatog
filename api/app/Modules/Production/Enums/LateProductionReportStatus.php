<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

enum LateProductionReportStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Correction = 'correction';
}
