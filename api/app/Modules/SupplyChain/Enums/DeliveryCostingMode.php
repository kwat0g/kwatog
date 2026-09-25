<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum DeliveryCostingMode: string
{
    case Legacy = 'legacy';
    case Transit = 'transit';
}
