<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * REC-08 — lifecycle of a Material Review Board hold.
 *
 *   Held ──rework/use_as_is──▶ Released
 *        ──scrap─────────────▶ Scrapped
 *        ──return_to_supplier▶ Returned
 */
enum MrbStatus: string
{
    case Held      = 'held';
    case Released  = 'released';
    case Scrapped  = 'scrapped';
    case Returned  = 'returned';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Held     => 'Held (Quarantined)',
            self::Released => 'Released',
            self::Scrapped => 'Scrapped',
            self::Returned => 'Returned to Supplier',
        };
    }
}
