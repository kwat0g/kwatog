<?php

declare(strict_types=1);

namespace App\Modules\MRP\Enums;

enum MrpPlanStatus: string
{
    case Active     = 'active';
    case Superseded = 'superseded';
    case Cancelled  = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Superseded => 'Superseded',
            self::Cancelled => 'Cancelled',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
