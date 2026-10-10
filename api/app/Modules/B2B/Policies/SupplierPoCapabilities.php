<?php

declare(strict_types=1);

namespace App\Modules\B2B\Policies;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\B2B\Enums\DeliveryScheduleStatus;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseStatus;
use App\Modules\Purchasing\Enums\PurchaseOrderResponseType;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Carbon\CarbonInterface;
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
            'can_schedule_delivery' => false,
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
        return in_array($po->status, self::INVOICE_STATUSES, true)
            && self::hasInvoiceableReceipt($po)
            && ! self::hasPendingQcReplacement($po);
    }

    /** Incoming QC returns hold invoicing until their replacement shipments pass QC. */
    public static function hasPendingQcReplacement(PurchaseOrder $po): bool
    {
        $preloaded = $po->getAttribute('has_qc_replacement_return');
        if ($preloaded !== null && ! (bool) $preloaded) {
            return false;
        }

        $returns = ReturnRequest::query()
            ->where('purchase_order_id', $po->id)
            ->where('source_key', 'like', 'grn-rejection:%')
            ->whereNull('replacement_purchase_order_id')
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->with('items:id,return_request_id,source_po_item_id,quantity')
            ->get();

        foreach ($returns as $return) {
            if (self::qcReplacementPending($return)) {
                return true;
            }
        }

        return false;
    }

    public static function qcReplacementPending(ReturnRequest $return): bool
    {
        if (! str_starts_with((string) $return->source_key, 'grn-rejection:')
            || $return->replacement_purchase_order_id
            || in_array($return->status?->value, ['cancelled', 'rejected'], true)) {
            return false;
        }
        if (! $return->completed_at) {
            return true;
        }

        $replacementReturnIds = self::replacementReturnIds($return);
        $lines = $return->relationLoaded('items') ? $return->items : $return->items()->get();
        foreach ($lines as $line) {
            if (! $line->source_po_item_id) {
                return true;
            }
            $accepted = GrnItem::query()
                ->where('purchase_order_item_id', $line->source_po_item_id)
                ->whereHas('grn', static fn (Builder $query) => $query
                    ->where('purchase_order_id', $return->purchase_order_id)
                    ->whereIn('status', GrnStatus::billableValues())
                    ->whereHas('supplierShipment', static fn (Builder $shipment) => $shipment->whereIn('return_request_id', $replacementReturnIds)))
                ->sum('quantity_accepted');
            if (bccomp((string) $accepted, (string) $line->quantity, 3) < 0) {
                return true;
            }
        }

        return false;
    }

    /** A failed replacement opens a child return against the replacement shipment's GRN. */
    private static function replacementReturnIds(ReturnRequest $return): array
    {
        $ids = [(int) $return->id => true];
        $frontier = [(int) $return->id];
        while ($frontier !== []) {
            $children = ReturnRequest::query()
                ->where('purchase_order_id', $return->purchase_order_id)
                ->where('source_key', 'like', 'grn-rejection:%')
                ->whereHas('goodsReceiptNote.supplierShipment', static fn (Builder $query) => $query->whereIn('return_request_id', $frontier))
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $frontier = [];
            foreach ($children as $id) {
                if (! isset($ids[$id])) {
                    $ids[$id] = true;
                    $frontier[] = $id;
                }
            }
        }

        return array_keys($ids);
    }

    public static function hasOpenQuantity(PurchaseOrder $po): bool
    {
        return self::items($po)->contains(
            static fn (PurchaseOrderItem $item): bool => bccomp($item->baseOrderedQuantity(), (string) $item->quantity_received, 3) > 0,
        );
    }

    /** Extra dispatch quantity owed after incoming QC rejected goods already shipped on this PO. */
    public static function rejectedDispatchAllowance(PurchaseOrder $po): array
    {
        $items = self::items($po)->keyBy('id');
        $allowance = [];
        ReturnRequestItem::query()
            ->whereIn('source_po_item_id', $items->keys())
            ->where('reversal_already_applied', true)
            ->where('disposition', 'return_to_supplier')
            ->whereHas('returnRequest', static fn (Builder $query) => $query
                ->where('purchase_order_id', $po->id)
                ->where('source_key', 'like', 'grn-rejection:%')
                ->where('disposition_status', 'disposed')
                ->whereNull('replacement_purchase_order_id')
                ->whereIn('status', ['inspected', 'completed']))
            ->get(['source_po_item_id', 'quantity'])
            ->each(static function (ReturnRequestItem $line) use ($items, &$allowance): void {
                $item = $items->get($line->source_po_item_id);
                if ($item) {
                    $id = (int) $item->id;
                    $allowance[$id] = Money::add($allowance[$id] ?? '0', $item->purchaseQuantity((string) $line->quantity, 6));
                }
            });

        return $allowance;
    }

    public static function shipmentDeadline(SupplierShipment $shipment): ?CarbonInterface
    {
        $po = $shipment->purchaseOrder;
        $originalDeadline = $po->confirmed_delivery_date ?? $po->expected_delivery_date;
        if ($shipment->return_request_id) {
            return $shipment->returnRequest?->replacement_due_date ?? $originalDeadline;
        }
        $itemIds = $shipment->items->pluck('purchase_order_item_id');
        if ($itemIds->isEmpty()) {
            return $originalDeadline;
        }

        $return = ReturnRequest::query()
            ->where('purchase_order_id', $po->id)
            ->where('source_key', 'like', 'grn-rejection:%')
            ->where('status', 'completed')
            ->whereNull('replacement_purchase_order_id')
            ->whereNotNull('replacement_due_date')
            ->where('completed_at', '<=', $shipment->created_at)
            ->whereHas('items', static fn (Builder $query) => $query->whereIn('source_po_item_id', $itemIds))
            ->orderByDesc('completed_at')
            ->first(['id', 'replacement_due_date']);

        return $return?->replacement_due_date ?? $originalDeadline;
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
            ->where(static fn (Builder $query) => $query
                ->whereHas('bills', static fn (Builder $bills) => $bills
                    ->where('provenance_type', 'stock')
                    ->where(static fn (Builder $eligible) => $eligible
                        ->where('status', BillStatus::Draft->value)
                        ->orWhere(static fn (Builder $posted) => $posted
                            ->where('status', BillStatus::Unpaid->value)
                            ->whereDoesntHave('payments')))
                    ->whereNull('supplier_invoice_id')
                    ->whereNull('supplier_invoice_number'))
                ->orWhereDoesntHave('bills', static fn (Builder $bills) => $bills
                    ->where('status', '<>', BillStatus::Cancelled->value)));
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
            $received = $item->purchaseQuantity((string) $item->quantity_received, 6);
            $planned = $scheduled[(int) $item->id] ?? '0';
            $covered = bccomp($planned, $received, 6) > 0 ? $planned : $received;
            $remaining = bcsub((string) $item->quantity, $covered, 6);
            $result[(int) $item->id] = bccomp($remaining, '0', 6) > 0 ? bcadd($remaining, '0', 2) : '0.00';
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
                'Invoices need an accepted, un-invoiced receipt and all QC replacement pieces accepted on a later GRN.',
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
            : $po->items()->with('item')->get();
    }

    private static function latestResponseStatus(PurchaseOrder $po): ?PurchaseOrderResponseStatus
    {
        $latest = $po->relationLoaded('latestResponse')
            ? $po->latestResponse
            : $po->latestResponse()->first();

        return $latest?->status;
    }
}
