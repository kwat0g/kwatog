<?php

declare(strict_types=1);

namespace App\Modules\Assets\Enums;

enum AssetStatus: string
{
    case Active            = 'active';
    case UnderMaintenance  = 'under_maintenance';
    case Disposed          = 'disposed';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
