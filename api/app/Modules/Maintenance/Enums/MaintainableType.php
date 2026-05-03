<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Enums;

/** Sprint 8 — Task 69. Polymorphic target for a maintenance schedule / WO. */
enum MaintainableType: string
{
    case Machine = 'machine';
    case Mold    = 'mold';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
