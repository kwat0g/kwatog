<?php

declare(strict_types=1);

namespace App\Modules\MRP\Enums;

enum MrpPlanStatus: string
{
    case Active     = 'active';
    case Superseded = 'superseded';
    case Cancelled  = 'cancelled';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
