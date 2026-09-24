<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/**
 * Sprint X — Incoming QC lot-checklist mode.
 *
 * per_unit           — scaffold sample_size × parameters rows (default, all inspection types)
 * lot_checklist      — scaffold checklist rows + measured_pieces piece rows (incoming only)
 */
enum InspectionMode: string
{
    case PerUnit       = 'per_unit';
    case LotChecklist  = 'lot_checklist';

    public function label(): string
    {
        return match ($this) {
            self::PerUnit      => 'Per Unit',
            self::LotChecklist => 'Lot Checklist',
        };
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
