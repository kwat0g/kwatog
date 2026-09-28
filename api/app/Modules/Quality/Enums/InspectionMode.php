<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/**
 * How an inspection's measurement rows are scaffolded.
 *
 * lot_checklist      — checklist rows for untoleranced parameters plus a few
 *                      measured piece rows per toleranced parameter; the AQL
 *                      sample is counted, not enumerated. Default for the three
 *                      non-final stages: incoming, in-process and outgoing.
 * per_unit           — scaffold sample_size × parameters rows, one per sampled
 *                      unit. Legacy rows only; nothing new creates one.
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
