<?php

declare(strict_types=1);

namespace App\Modules\MRP\Enums;

enum MrpRunTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual    = 'manual';
    case Automatic = 'automatic';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
