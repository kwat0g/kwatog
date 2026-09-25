<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum DeliveryStockReservationStatus: string
{
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';
}
