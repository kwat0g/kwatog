<?php

declare(strict_types=1);

namespace App\Modules\MRP\Enums;

enum MoldEventType: string
{
    case Created               = 'created';
    case MaintenanceScheduled  = 'maintenance_scheduled';
    case MaintenanceStarted    = 'maintenance_started';
    case MaintenanceCompleted  = 'maintenance_completed';
    case ShotLimitReached      = 'shot_limit_reached';
    case Retired               = 'retired';
    case Repaired              = 'repaired';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
