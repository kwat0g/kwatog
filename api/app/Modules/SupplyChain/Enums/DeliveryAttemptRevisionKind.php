<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum DeliveryAttemptRevisionKind: string
{
    case ReportCorrection = 'report_correction';
    case LateRecovery = 'late_recovery';
}
