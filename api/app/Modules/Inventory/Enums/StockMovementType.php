<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum StockMovementType: string
{
    case GrnReceipt        = 'grn_receipt';
    case MaterialIssue     = 'material_issue';
    case ProductionReceipt = 'production_receipt';
    case Delivery          = 'delivery';
    case Transfer          = 'transfer';
    case AdjustmentIn      = 'adjustment_in';
    case AdjustmentOut     = 'adjustment_out';
    case Scrap             = 'scrap';
    case ReturnToVendor    = 'return_to_vendor';
    case CycleCount        = 'cycle_count';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Movements that ADD stock to the destination location. */
    public function isReceipt(): bool
    {
        return in_array($this, [
            self::GrnReceipt,
            self::ProductionReceipt,
            self::AdjustmentIn,
            self::Transfer, // adds to destination
            self::CycleCount, // can be either; service decides direction by sign
        ], true);
    }

    /** Movements that REMOVE stock from the source location. */
    public function isIssue(): bool
    {
        return in_array($this, [
            self::MaterialIssue,
            self::Delivery,
            self::AdjustmentOut,
            self::Scrap,
            self::ReturnToVendor,
            self::Transfer, // removes from source
        ], true);
    }
}
