<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Enums;

enum ReturnCaseIntakeKind: string
{
    case Discrepancy = 'discrepancy';
    case DeliveryTrace = 'delivery_trace';
}
