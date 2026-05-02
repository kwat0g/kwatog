<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

enum ProductionScheduleStatus: string
{
    case Pending    = 'pending';
    case Confirmed  = 'confirmed';
    case Superseded = 'superseded';
    case Executed   = 'executed';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
