<?php

declare(strict_types=1);

namespace App\Modules\B2B\Policies;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\B2B\Enums\DeliveryScheduleStatus;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseType;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The ONE supplier-portal action matrix for a purchase order.
 *
 * The portal resource renders these flags as `capabilities`, and every
 * supplier mutation re-checks the same predicate under its row lock. Before
 * this class the two were separate lists that drifted: the SPA offered
 * "Update shipment" on a PO the supplier had not accepted, "Decline" on a PO
 * already declined, and "Submit invoice" whenever any receipt existed, even
 * one already invoiced.
 *
 * Lifecycle, as the supplier sees it:
 *   sent / supplier_proposed / supplier_declined(pending) → respond
 *   acknowledged / partially_received                     → fulfil (ship, docs, schedule)
 *   acknowledged / partially_received / received          → invoice an accepted, un-invoiced receipt
 *
 * Fulfilment waits for acceptance on purpose: a supplier shipping against a
 * PO it is still negotiating commits OGAMI to terms nobody agreed.
 */
final class SupplierPoCapabilities
{
    /** Accept as ordered. Includes retracting a decline purchasing has not decided. */
    public const ACCEPT_STATUSES = [
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::SupplierProposed,
        PurchaseOrderStatus::SupplierDeclined,
    ];

    /** Counter-offer. A pending proposal may be replaced by a newer one. */
    public const PROPOSE_STATUSES = [
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::SupplierProposed,
        PurchaseOrderStatus::SupplierDeclined,
    ];

    /** Decline. Never twice, and never after accepting. */
    public const DECLINE_STATUSES = [
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::SupplierProposed,
    ];

    /** Shipment updates, shipping documents, delivery schedules. */
    public const FULFILMENT_STATUSES = [
        PurchaseOrderStatus::Acknowledged,
        PurchaseOrderStatus::PartiallyReceived,
    ];

    public const INVOICE_STATUSES = [
        PurchaseOrderStatus::Acknowledged,
        PurchaseOrderStatus::PartiallyReceived,
        PurchaseOrderStatus::Received,
    ];

    /** Schedules that still claim quantity. Rejected and cancelled ones free it. */
    public const OPEN_SCHEDULE_STATUSES = [
        DeliveryScheduleStatus::Submitted,
        DeliveryScheduleStatus::Acknowledged,
    ];

    /**
     * @return array{can_acknowledge: bool, can_accept: bool, can_propose: bool, can_decline: bool, can_respond: bool, can_update_shipment: bool, can_upload_document: bool, can_schedule_delivery: bool, can_submit_invoice: bool}
     */
    public static function forPurchaseOrder(PurchaseOrder $po): array
    {
        $accept = self::canRespond($po, PurchaseOrderResponseType::Accept);
        $propose = self::canRespond($po, PurchaseOrderResponseType::Propose);
        $decline = self::canRespond($po, PurchaseOrderResponseType::Decline);
        $shipOrSchedule = self::canShipOrSchedule($po);

        return [
            // Legacy alias: the acknowledge endpoint is an accept-as-ordered.
            'can_acknowledge' => $accept,
            'can_accept' => $accept,
            'can_propose' => $propose,
            'can_decline' => $decline,
            'can_respond' => $accept || $propose || $decline,
            'can_update_shipment' => $shipOrSchedule,
            'can_upload_document' => self::canUploadDocument($po),
            'can_schedule_delivery' => $shipOrSchedule,
            'can_submit_invoice' => self::canSubmitInvoice($po),
        ];
    }

    public static function canRespond(PurchaseOrder $po, PurchaseOrderResponseType $type): bool
    {
        $allowed = match ($type) {
            PurchaseOrderResponseType::Accept => self::ACCEPT_STATUSES,
            PurchaseOrderResponseType::Propose => self::PROPOSE_STATUSES,
            PurchaseOrderResponseType::Decline => self::DECLINE_STATUSES,
        };
        if (! in_array($po->status, $allowed, true)) {
            return false;
        }

        // A decline purchasing already accepted is final: the PO now waits for
        // purchasing to cancel or re-source it, not for another reply.
        if ($po->status === PurchaseOrderStatus::SupplierDeclined) {
            return self::latestResponseStatus($po) === PurchaseOrderResponseStatus::Pending;
        }

        return true;
    }

    public static function canShipOrSchedule(PurchaseOrder $po): bool
    {
        return in_array($po->status, self::FULFILMENT_STATUSES, true) && self::hasOpenQuantity($po);
    }

    /**
     * Documents do not require open quantity: incoming QC routinely asks for a
     * CoA after the last lot has already arrived.
     */
    public static function canUploadDocument(PurchaseOrder $po): bool
    {
        return in_array($po->status, self::FULFILMENT_STATUSES, true);
    }

    public static function canSubmitInvoice(PurchaseOrder $po): bool
    {
        return in_array($po->status, self::INVOICE_STATUSES, true) && self::hasInvoiceableReceipt($po);
    }

    public static function hasOpenQuantity(PurchaseOrder $po): bool
    {
        return self::items($po)->contains(
            static fn (PurchaseOrderItem $item): bool => Money::gt((string) $item->quantity, (string) $item->quantity_received),
        );
    }

    /**
     * Receipts the supplier may still invoice: billable (accepted or partially
     * accepted) and not yet carrying a supplier invoice on a live bill.
     *
     * A receipt with only a system-staged draft bill IS invoiceable —
     * AutoCreateBillOnGrnAccepted stages that draft the moment QC accepts the
     * goods, so treating "a bill exists" as "invoiced" would lock out the
     * supplier on nearly every receipt.
     *
     * @return Builder<GoodsReceiptNote>
     */
    public static function invoiceableReceipts(PurchaseOrder|int $po): Builder
    {
        $poId = $po instanceof PurchaseOrder ? (int) $po->id : $po;

        return self::constrainInvoiceable(GoodsReceiptNote::query()->where('purchase_order_id', $poId));
    }

    /**
     * The invoiceable-receipt predicate on any GoodsReceiptNote query, so the
     * list page can select it with withExists() instead of re-deriving it:
     *
     *   ->withExists(['goodsReceiptNotes as has_invoiceable_receipt'
     *       => fn ($q) => SupplierPoCapabilities::constrainInvoiceable($q)])
     *
     * @param  Builder<GoodsReceiptNote>  $grns
     * @return Builder<GoodsReceiptNote>
     */
    public static function constrainInvoiceable(Builder $grns): Builder
    {
        return $grns
            ->whereIn('status', GrnStatus::billableValues())
            ->whereDoesntHave('bills', static fn (Builder $bills) => $bills
                ->where('status', '<>', BillStatus::Cancelled->value)
                ->whereNotNull('supplier_invoice_number'));
    }

    /**
     * Uses the `has_invoiceable_receipt` withExists() attribute when the list
     * query selected it, so a page of POs costs one query, not one per row.
     */
    public static function hasInvoiceableReceipt(PurchaseOrder $po): bool
    {
        $preloaded = $po->getAttribute('has_invoiceable_receipt');
        if ($preloaded !== null) {
            return (bool) $preloaded;
        }

        return self::invoiceableReceipts($po)->exists();
    }

    /**
     * Quantity per PO line the supplier may still put on a new schedule,
     * keyed by purchase_order_items.id, as a 2dp decimal string (never < 0).
     *
     *   schedulable = ordered − max(received, open scheduled)
     *
     * Not `ordered − received − scheduled`: deliveries are normally made
     * against a schedule that stays acknowledged afterwards, so subtracting
     * both counts the same goods twice and strands the tail of the order.
     * A plan the supplier did not deliver is freed by cancelling it.
     *
     * @return array<int, string>
     */
    public static function schedulableQuantities(PurchaseOrder $po, ?int $excludingScheduleId = null): array
    {
        $scheduled = self::openScheduledQuantities($po, $excludingScheduleId);
        $result = [];
        foreach (self::items($po) as $item) {
            $received = (string) $item->quantity_received;
            $planned = $scheduled[(int) $item->id] ?? '0';
            $covered = Money::gt($planned, $received) ? $planned : $received;
            $result[(int) $item->id] = Money::round2(Money::clampMin(Money::sub((string) $item->quantity, $covered), '0'));
        }

        return $result;
    }

    /**
     * Sum of open (submitted + acknowledged) schedule lines per PO line id.
     * Schedule lines store the PO line as a HashID.
     *
     * @return array<int, string>
     */
    public static function openScheduledQuantities(PurchaseOrder $po, ?int $excludingScheduleId = null): array
    {
        $totals = [];
        DeliverySchedule::query()
            ->where('purchase_order_id', $po->id)
            ->whereNotNull('vendor_id')
            ->whereIn('status', array_map(static fn (DeliveryScheduleStatus $s): string => $s->value, self::OPEN_SCHEDULE_STATUSES))
            ->when($excludingScheduleId !== null, static fn (Builder $q) => $q->whereKeyNot($excludingScheduleId))
            ->get(['id', 'lines'])
            ->each(static function (DeliverySchedule $schedule) use (&$totals): void {
                foreach ((array) $schedule->lines as $line) {
                    $decoded = app('hashids')->decode((string) ($line['purchase_order_item_id'] ?? ''));
                    if ($decoded === []) {
                        continue;
                    }
                    $id = (int) $decoded[0];
                    $totals[$id] = Money::add($totals[$id] ?? '0', (string) ($line['quantity'] ?? '0'));
                }
            });

        return $totals;
    }

    /**
     * Service-side guard. Call with the row-locked PO inside the transaction.
     *
     * @param  'ship'|'document'|'schedule'|'invoice'  $action
     */
    public static function assert(PurchaseOrder $po, string $action): void
    {
        [$allowed, $message] = match ($action) {
            'ship' => [
                self::canShipOrSchedule($po),
                'Shipment updates open once you accept the purchase order and close when every line has been received.',
            ],
            'document' => [
                self::canUploadDocument($po),
                'Shipping documents are accepted once you accept the purchase order and until it is fully received.',
            ],
            'schedule' => [
                self::canShipOrSchedule($po),
                'Delivery schedules open once you accept the purchase order and close when every line has been received.',
            ],
            'invoice' => [
                self::canSubmitInvoice($po),
                'Invoices need an accepted goods receipt that you have not invoiced yet.',
            ],
        };

        if (! $allowed) {
            throw new BusinessRuleException($message);
        }
    }

    /** @return Collection<int, PurchaseOrderItem> */
    private static function items(PurchaseOrder $po): Collection
    {
        return $po->relationLoaded('items')
            ? $po->items
            : $po->items()->get(['id', 'purchase_order_id', 'quantity', 'quantity_received']);
    }

    private static function latestResponseStatus(PurchaseOrder $po): ?PurchaseOrderResponseStatus
    {
        $latest = $po->relationLoaded('latestResponse')
            ? $po->latestResponse
            : $po->latestResponse()->first();

        return $latest?->status;
    }
}
