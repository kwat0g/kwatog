<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Enums;

/** Sprint 8 — Task 69. Maintenance WO lifecycle. */
enum MaintenanceWorkOrderStatus: string
{
    case Open       = 'open';
    case Assigned   = 'assigned';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
