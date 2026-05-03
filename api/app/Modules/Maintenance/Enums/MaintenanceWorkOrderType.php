<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Enums;

/** Sprint 8 — Task 69. Maintenance WO classification. */
enum MaintenanceWorkOrderType: string
{
    case Preventive = 'preventive';
    case Corrective = 'corrective';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
