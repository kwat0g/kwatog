<?php

declare(strict_types=1);

namespace App\Modules\Production\Enums;

enum MachineDowntimeCategory: string
{
    case Breakdown          = 'breakdown';
    case Changeover         = 'changeover';
    case MaterialShortage   = 'material_shortage';
    case NoOrder            = 'no_order';
    case PlannedMaintenance = 'planned_maintenance';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function isPlanned(): bool
    {
        return match ($this) {
            self::PlannedMaintenance, self::Changeover => true,
            default => false,
        };
    }
}
