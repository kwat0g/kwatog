<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Enums;

/** Sprint 8 — Task 69. Schedule interval semantics. */
enum MaintenanceScheduleInterval: string
{
    case Hours = 'hours';
    case Days  = 'days';
    case Shots = 'shots';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
