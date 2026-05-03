<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/** Sprint 7 — Task 61. Action category for an NCR action row. */
enum NcrActionType: string
{
    case Containment = 'containment';
    case Corrective  = 'corrective';
    case Preventive  = 'preventive';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
