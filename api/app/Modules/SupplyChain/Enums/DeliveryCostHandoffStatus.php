<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum DeliveryCostHandoffStatus: string
{
    case Generated = 'generated';
    case ManualRequired = 'manual_required';
    case NotRequired = 'not_required';
}
