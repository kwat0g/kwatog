<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum VehicleType: string
{
    case Truck = 'truck';
    case Van = 'van';
    case Motorcycle = 'motorcycle';

    public function label(): string
    {
        return match ($this) {
            self::Truck => 'Truck', self::Van => 'Van', self::Motorcycle => 'Motorcycle',
        };
    }
}
