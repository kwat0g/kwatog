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
    /** Incoming supply remains a purchasing budget, never production-ready inventory. */
    public function stockPlanningSupply(int $itemId, ?int $stockRootId = null): array
    {
        $item = Item::findOrFail($itemId);
        $levels = \App\Modules\Inventory\Models\StockLevel::where('item_id', $itemId)->with('location.zone.warehouse')->get();
        $physical = app(\App\Modules\Inventory\Services\StockLocationSummaryService::class)->availability($levels)['usable_quantity'];
        $physical = $this->positive(bcsub($physical, (string) $item->safety_stock, 3));
        $requests = \App\Modules\Purchasing\Models\PurchaseRequest::whereNotIn('status', ['cancelled', 'rejected'])
            ->whereHas('items', fn ($q) => $q->where('item_id', $itemId))
            ->with(['items' => fn ($q) => $q->where('item_id', $itemId), 'mrpPlan'])->get();
        $ownership = [];
        foreach ($requests as $request) {
            $ownership[$request->id] = $request->stock_production_work_order_id
                ? 'stock:'.$request->stock_production_work_order_id
                : ($request->mrp_plan_id ? 'order:'.$request->mrpPlan?->sales_order_id : 'free');
        }
        $incoming = [];
        $open = array_map(fn ($status) => $status->value, array_filter(PurchaseOrderStatus::open(), fn ($status) => $status !== PurchaseOrderStatus::SupplierDeclined));
        $poLines = DB::table('purchase_order_items as poi')->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('poi.item_id', $itemId)->whereNull('po.deleted_at')->where('po.status', '!=', 'cancelled')
            ->get(['po.id', 'po.purchase_request_id', 'po.status', 'poi.purchase_request_item_id', 'poi.quantity', 'poi.quantity_received', 'poi.unit']);
        $orderedByRequestLine = [];
        foreach ($poLines as $line) {
            $base = $item->convertToBase((string) $line->quantity, $line->unit ?: null);
            if ($line->purchase_request_item_id) $orderedByRequestLine[$line->purchase_request_item_id] = bcadd($orderedByRequestLine[$line->purchase_request_item_id] ?? '0', $base, 3);
            // Draft purchasing is committed but does not authorize physical production.
            if (! in_array($line->status, array_merge($open, ['draft', 'pending_approval']), true)) continue;
            $owner = $ownership[$line->purchase_request_id] ?? ($line->purchase_request_id ? 'other' : 'free');
            $incoming[$owner] = bcadd($incoming[$owner] ?? '0', $this->positive(bcsub($base, (string) $line->quantity_received, 3)), 3);
        }
        foreach ($requests as $request) {
            if (! in_array($request->status->value, ['draft', 'pending', 'approved'], true)) continue;
            if ($stockRootId && $request->stock_production_work_order_id === $stockRootId && $request->status->value === 'draft'
                && ! $request->purchaseOrders()->exists() && ! $request->rfqs()->exists()) continue;
            foreach ($request->items as $line) {
                $base = $item->convertToBase((string) $line->quantity, $line->unit ?: null);
                $remaining = $this->positive(bcsub($base, $orderedByRequestLine[$line->id] ?? '0', 3));
                $owner = $ownership[$request->id];
                $incoming[$owner] = bcadd($incoming[$owner] ?? '0', $remaining, 3);
            }
        }
        $held = DB::table('grn_items as gi')->join('goods_receipt_notes as grn', 'grn.id', '=', 'gi.goods_receipt_note_id')
            ->join('purchase_orders as po', 'po.id', '=', 'grn.purchase_order_id')->where('gi.item_id', $itemId)
            ->whereIn('grn.status', ['pending_qc', 'partial_accepted'])->whereNull('grn.remainder_rejected_at')
            ->whereNull('po.deleted_at')->where('po.status', '!=', 'cancelled')
            ->get(['po.purchase_request_id', 'gi.quantity_received', 'gi.quantity_accepted']);
        foreach ($held as $row) {
            $owner = $ownership[$row->purchase_request_id] ?? ($row->purchase_request_id ? 'other' : 'free');
            $incoming[$owner] = bcadd($incoming[$owner] ?? '0', $this->positive(bcsub((string) $row->quantity_received, (string) $row->quantity_accepted, 3)), 3);
        }
        $workOrders = \App\Modules\Production\Models\WorkOrder::whereNotIn('status', ['cancelled', 'closed', 'completed'])
            ->whereHas('materials', fn ($q) => $q->where('item_id', $itemId))
            ->with(['materials' => fn ($q) => $q->where('item_id', $itemId)])->orderByDesc('priority')->orderBy('planned_end')->orderBy('id')->get();
        $reservations = \App\Modules\Inventory\Models\MaterialReservation::where('item_id', $itemId)->where('status', 'reserved')->get()->groupBy('work_order_id');
        $allWork = \App\Modules\Production\Models\WorkOrder::get(['id', 'parent_wo_id', 'production_purpose', 'sales_order_id'])->keyBy('id');
        $demands = [];
        foreach ($workOrders as $work) {
            $root = $work;
            $seen = [];
            while ($root->parent_wo_id && ! isset($seen[$root->id])) { $seen[$root->id] = true; $root = $allWork->get($root->parent_wo_id) ?? $root; }
            $owner = $root->production_purpose === 'stock' && ! $root->sales_order_id ? 'stock:'.$root->id : 'order:'.$root->sales_order_id;
            $gross = '0';
            foreach ($work->materials as $material) $gross = bcadd($gross, $this->positive(bcsub((string) $material->bom_quantity, (string) $material->actual_quantity_issued, 3)), 3);
            $reserved = (string) ($reservations->get($work->id)?->sum('quantity') ?? '0');
            $demands[$owner] = bcadd($demands[$owner] ?? '0', $this->positive(bcsub($gross, $reserved, 3)), 3);
        }
        $pool = bcadd($physical, $incoming['free'] ?? '0', 3);
        $other = '0';
        foreach ($demands as $owner => $demand) {
            if ($stockRootId && $owner === 'stock:'.$stockRootId) break;
            if ($stockRootId === null && ! str_starts_with($owner, 'stock:')) continue;
            $unmet = $this->positive(bcsub($demand, $incoming[$owner] ?? '0', 3));
            $take = bccomp($pool, $unmet, 3) < 0 ? $pool : $unmet;
            $pool = bcsub($pool, $take, 3);
            $other = bcadd($other, $take, 3);
        }
        $owned = $stockRootId ? ($incoming['stock:'.$stockRootId] ?? '0') : '0';
        return ['uncommitted_incoming' => bcadd($incoming['free'] ?? '0', '0', 3), 'owned_incoming' => bcadd($owned, '0', 3),
            'other_stock_demand' => $other, 'available_to_root' => bcadd($pool, $owned, 3)];
    }

    private function positive(string $quantity): string
    {
        return bccomp($quantity, '0', 3) < 0 ? '0.000' : bcadd($quantity, '0', 3);
    }

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
            $prQuery->whereNull('mrp_plan_id')->whereNull('stock_production_work_order_id');
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
            ->whereNull('pri.deleted_at')
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
