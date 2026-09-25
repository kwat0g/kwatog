<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum DeliveryDiscrepancyStatus: string
{
    case Pending = 'pending';
    case Rejected = 'rejected';
}
