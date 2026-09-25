<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use Illuminate\Support\Facades\DB;

/**
 * Shared supply-in-transit calculation for inventory/MRP planning.
 *
 * Computes the total base-UoM quantity (bcmath string, scale 6) still owed on
 * open purchase orders, excluding declined/closed/soft-deleted orders.
 *
 * Used by AutoReplenishmentService, AutoPurchaseOrderService, and MrpEngineService
 * to net in-transit supply against position calculations.
 */
class OpenSupplyService
{
    /** Physically received base-UoM stock held for QC, not yet in inventory or PO transit. */
    public function heldQcBaseQuantity(int $itemId): string
    {
        $lines = DB::table('grn_items as gi')
            ->join('goods_receipt_notes as grn', 'grn.id', '=', 'gi.goods_receipt_note_id')
            ->join('purchase_orders as po', 'po.id', '=', 'grn.purchase_order_id')
            ->where('gi.item_id', $itemId)
            ->whereIn('grn.status', [GrnStatus::PendingQc->value, GrnStatus::PartialAccepted->value])
            ->whereNull('grn.remainder_rejected_at')
            ->where('po.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->whereNull('po.deleted_at')
            ->get(['gi.quantity_received', 'gi.quantity_accepted']);

        $held = '0.000';
        foreach ($lines as $line) {
            $remainder = bcsub((string) $line->quantity_received, (string) $line->quantity_accepted, 3);
            if (bccomp($remainder, '0', 3) > 0) {
                $held = bcadd($held, $remainder, 3);
            }
        }

        return $held;
    }

    /**
     * Remaining unconverted quantity in open PRs for one item: the sum of PR line
     * quantities minus quantities already ordered via POs.
     *
     * Scopes: if $salesOrderId given, only PRs whose mrpPlan.sales_order_id matches;
     * if $unplannedOnly, only PRs with no MRP plan (manual or reorder-point auto).
     *
     * Excludes soft-deleted PRs. By default counts only Pending/Approved PR statuses;
     * set $includeDraft to true to also count Draft (used by AutoReplenishment guard).
     *
     * @param int $itemId  Item primary key
     * @param int|null $salesOrderId  Optional SO scope for MRP-generated PRs
     * @param bool $unplannedOnly  If true, only count PRs with no MRP plan
     * @param bool $includeDraft  If true, also count Draft PRs (default false)
     * @return string  Remaining unconverted quantity in base UoM, 3-decimal BCMath string
     *
     * @throws BusinessRuleException if UoM conversion fails
     */
    public function openRequestBaseQuantity(int $itemId, ?int $salesOrderId = null, bool $unplannedOnly = false, bool $includeDraft = false): string
    {
        $item = Item::withTrashed()->find($itemId);
        if ($item === null) {
            return '0.000';
        }

        // Base query: PRs with items for this item.
        $statuses = [
            PurchaseRequestStatus::Pending->value,
            PurchaseRequestStatus::Approved->value,
        ];
        if ($includeDraft) {
            $statuses[] = PurchaseRequestStatus::Draft->value;
        }

        $prQuery = \App\Modules\Purchasing\Models\PurchaseRequest::query()
            ->whereIn('status', $statuses)
            ->whereNull('deleted_at');

        if ($unplannedOnly) {
            // Only unplanned PRs (no MRP plan).
            $prQuery->whereNull('mrp_plan_id');
        } elseif ($salesOrderId !== null) {
            // Scope to a specific SO via its MRP plan.
            $prQuery->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $salesOrderId));
        }

        $prIds = $prQuery->pluck('id')->all();
        if ($prIds === []) {
            return '0.000';
        }

        // Get PR lines for this item.
        $lines = DB::table('purchase_request_items as pri')
            ->where('pri.item_id', $itemId)
            ->whereIn('pri.purchase_request_id', $prIds)
            ->select(['pri.id', 'pri.quantity', 'pri.unit'])
            ->get();

        $poLines = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->whereIn('poi.purchase_request_item_id', $lines->pluck('id'))
            ->where('po.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->whereNull('po.deleted_at')
            ->get(['poi.purchase_request_item_id', 'poi.quantity', 'poi.unit'])
            ->groupBy('purchase_request_item_id');

        $remaining = '0.000000';
        foreach ($lines as $line) {
            try {
                $requestedBase = $item->convertToBase((string) $line->quantity, trim((string) $line->unit) ?: null);
                $orderedBase = '0.000000';
                foreach ($poLines->get($line->id, []) as $poLine) {
                    $orderedBase = bcadd(
                        $orderedBase,
                        $item->convertToBase((string) $poLine->quantity, trim((string) $poLine->unit) ?: null),
                        6,
                    );
                }
            } catch (\RuntimeException $e) {
                throw new BusinessRuleException(
                    "Cannot net open PR quantity for item {$item->code}: {$e->getMessage()}",
                    0,
                    $e,
                );
            }
            $lineRemaining = bcsub($requestedBase, $orderedBase, 6);
            if (bccomp($lineRemaining, '0', 6) > 0) {
                $remaining = bcadd($remaining, $lineRemaining, 6);
            }
        }

        return bcadd($remaining, '0', 3);
    }

    /**
     * Sum of (PO quantity converted to base - base quantity_received) across all
     * open POs for this item. Includes POs under change re-approval
     * (pending approval with pending_change_response_id set) as they are real
     * supply in transit.
     *
     * Excludes:
     *   - SupplierDeclined (supplier refused; not incoming supply)
     *   - Closed/short-closed (settlement complete)
     *   - Soft-deleted POs (whereNull(po.deleted_at))
     *
     * @param int $itemId  Item primary key
     * @return string      In-transit quantity in base UoM, 6-decimal BCMath string
     *
     * @throws BusinessRuleException if UoM conversion fails
     */
    public function inTransitBaseQuantity(int $itemId): string
    {
        $item = Item::withTrashed()->find($itemId);
        if ($item === null) {
            return '0.000000';
        }

        // Open statuses, excluding SupplierDeclined which is not incoming supply.
        $openStatuses = array_filter(
            PurchaseOrderStatus::open(),
            static fn (PurchaseOrderStatus $status) => $status !== PurchaseOrderStatus::SupplierDeclined,
        );

        $lines = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('poi.item_id', $itemId)
            ->whereNull('po.deleted_at')
            ->where(function ($q) use ($openStatuses): void {
                $q->whereIn('po.status', array_map(fn (PurchaseOrderStatus $s) => $s->value, $openStatuses))
                    ->orWhere(function ($subq): void {
                        // Include POs under change re-approval.
                        $subq->where('po.status', PurchaseOrderStatus::PendingApproval->value)
                            ->whereNotNull('po.pending_change_response_id');
                    });
            })
            ->get(['poi.quantity', 'poi.quantity_received', 'poi.unit']);

        $inTransit = '0.000000';
        foreach ($lines as $line) {
            $purchaseUnit = trim((string) $line->unit);
            try {
                $orderedBase = $item->convertToBase(
                    (string) $line->quantity,
                    $purchaseUnit !== '' ? $purchaseUnit : (string) $item->unit_of_measure,
                );
            } catch (\RuntimeException $e) {
                throw new BusinessRuleException(
                    "Cannot net in-transit supply for item {$item->code}: {$e->getMessage()}",
                    0,
                    $e,
                );
            }
            $remainingBase = bcsub($orderedBase, (string) $line->quantity_received, 6);
            if (bccomp($remainingBase, '0', 6) > 0) {
                $inTransit = bcadd($inTransit, $remainingBase, 6);
            }
        }

        return $inTransit;
    }
}
