<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum StockMovementType: string
{
    case GrnReceipt        = 'grn_receipt';
    case MaterialIssue     = 'material_issue';
    case MaterialReturn    = 'material_return';
    case ProductionReceipt = 'production_receipt';
    case Delivery          = 'delivery';
    /** Source-bound receipt of goods recovered from a delivery truck. */
    case DeliveryReturn    = 'delivery_return';
    /** Source-bound receipt of goods returned by a customer after acceptance. */
    case DeliveryCustomerReturn = 'delivery_customer_return';
    case Transfer          = 'transfer';
    case AdjustmentIn      = 'adjustment_in';
    case AdjustmentOut     = 'adjustment_out';
    case Scrap             = 'scrap';
    case ReturnToVendor    = 'return_to_vendor';
    case CycleCount        = 'cycle_count';
    // REC-05 — opening stock loaded at go-live with an explicit cost basis.
    case Opening           = 'opening';

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
            self::MaterialReturn,
            self::DeliveryReturn,
            self::DeliveryCustomerReturn,
            self::Opening, // seeds stock at a destination with a cost basis
            self::Transfer, // adds to destination
            self::CycleCount, // can be either; service decides direction by sign
        ], true);
    }

}
