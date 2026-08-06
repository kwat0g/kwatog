<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum VehicleStatus: string
{
    case Available = 'available';
    case InUse = 'in_use';
    case Maintenance = 'maintenance';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available', self::InUse => 'In use',
            self::Maintenance => 'Maintenance', self::Retired => 'Retired',
        };
    }
}
