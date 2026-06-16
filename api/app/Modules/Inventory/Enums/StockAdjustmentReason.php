<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * OGAMI-012 — structured reason codes for manual stock adjustments.
 *
 * Stored on `stock_adjustments.reason_code`. The free-text justification still
 * lives alongside on the adjustment's `reason` (and the linked stock_movement
 * remarks) for the audit trail.
 */
enum StockAdjustmentReason: string
{
    case CycleCountVariance = 'cycle_count_variance';
    case Damage             = 'damage';
    case Expiry             = 'expiry';
    case Theft              = 'theft';
    case FoundStock         = 'found_stock';
    case SystemCorrection   = 'system_correction';

    public function label(): string
    {
        return match ($this) {
            self::CycleCountVariance => 'Cycle Count Variance',
            self::Damage             => 'Damage',
            self::Expiry             => 'Expiry',
            self::Theft              => 'Theft',
            self::FoundStock         => 'Found Stock',
            self::SystemCorrection   => 'System Correction',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
