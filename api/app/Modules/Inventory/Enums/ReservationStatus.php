<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum ReservationStatus: string
{
    case Reserved = 'reserved';
    case Issued   = 'issued';
    case Released = 'released';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
