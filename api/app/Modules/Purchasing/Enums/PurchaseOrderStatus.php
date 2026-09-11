<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum PurchaseOrderStatus: string
{
    case Draft              = 'draft';
    case PendingApproval    = 'pending_approval';
    case Approved           = 'approved';
    case Sent               = 'sent';
    // 2026-09-11 — supplier response states. `sent` now means OGAMI transmitted
    // the PO; the supplier's acceptance is `acknowledged`, and a supplier that
    // cannot fulfil as-is counter-offers (`supplier_proposed`) or refuses
    // (`supplier_declined`). Previously the supplier's acknowledgment was
    // recorded as `sent`, so the status described the wrong event.
    case Acknowledged       = 'acknowledged';
    case SupplierProposed   = 'supplier_proposed';
    case SupplierDeclined   = 'supplier_declined';
    case PartiallyReceived  = 'partially_received';
    case Received           = 'received';
    case Closed             = 'closed';
    case Cancelled          = 'cancelled';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * The live-commitment set: an order exists and is neither fully settled nor
     * withdrawn. This is the single list dashboards, MRP in-transit, and the
     * warehouse queue should use, so adding a status cannot silently drop POs
     * from those surfaces. Includes `supplier_declined` because it still needs a
     * purchasing decision rather than disappearing.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [
            self::Approved,
            self::Sent,
            self::Acknowledged,
            self::SupplierProposed,
            self::SupplierDeclined,
            self::PartiallyReceived,
        ];
    }

    /**
     * Statuses in which goods may legitimately be received against the PO.
     *
     * @return list<self>
     */
    public static function receivable(): array
    {
        return [
            self::Approved,
            self::Sent,
            self::Acknowledged,
            self::SupplierProposed,
            self::PartiallyReceived,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Pending approval',
            self::Approved => 'Approved',
            self::Sent => 'Sent',
            self::Acknowledged => 'Acknowledged',
            self::SupplierProposed => 'Supplier proposed changes',
            self::SupplierDeclined => 'Supplier declined',
            self::PartiallyReceived => 'Partially received',
            self::Received => 'Received',
            self::Closed => 'Closed',
            self::Cancelled => 'Cancelled',
        };
    }
}
