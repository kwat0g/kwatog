<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Enums;

/** Sprint 8 — Task 69. */
enum MaintenancePriority: string
{
    case Critical = 'critical';
    case High     = 'high';
    case Medium   = 'medium';
    case Low      = 'low';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
