<?php

declare(strict_types=1);

namespace App\Modules\MRP\Enums;

enum MoldStatus: string
{
    case Available   = 'available';
    case InUse       = 'in_use';
    case Maintenance = 'maintenance';
    case Retired     = 'retired';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Available   => 'Available',
            self::InUse       => 'In Use',
            self::Maintenance => 'Maintenance',
            self::Retired     => 'Retired',
        };
    }
}
