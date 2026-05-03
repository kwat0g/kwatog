<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Services\DocumentSequenceService;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 6 — Task 52. MRP engine.
 *
 * Run on SalesOrderService::confirm(). Produces:
 *  - One mrp_plans row per run (versioned).
 *  - Draft purchase_requests for any raw-material shortfall (one PR row
 *    consolidating all material lines for the SO; each line is one
 *    purchase_request_items row). is_auto_generated=true, priority is set
 *    to 'urgent' when order_by_date <= today, else 'normal'.
 *  - Draft work_orders (status='planned') — one per SO line. Each WO
 *    auto-explodes its product's BOM into work_order_materials via
 *    WorkOrderService::createDraft().
 *
 * Net-requirement math (per material):
 *   gross      = Σ over SO lines: bom.quantity_per_unit * (1 + waste_factor/100) * line.quantity
 *   on_hand    = Σ stock_levels.quantity over all locations
 *   reserved   = Σ stock_levels.reserved_quantity over all locations
 *   in_transit = Σ purchase_order_items.(quantity - quantity_received) for POs in approved/sent/partial
 *   net        = max(0, gross - on_hand + reserved - in_transit)
 *
 * Lead time + safety buffer:
 *   order_by_date = earliest_so_line.delivery_date - max(approved_supplier.lead_time, items.lead_time_days) - 2 days
 *
 * Each line's outcome is recorded in mrp_plans.diagnostics.
 */
class MrpEngineService
{
    /** @var int Safety buffer days subtracted from order_by_date. */
    private const SAFETY_BUFFER_DAYS = 2;

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly BomService $boms,
        private readonly WorkOrderService $workOrders,
    ) {}

    /**
     * Run MRP for a confirmed sales order. Idempotent at the per-run level;
     * re-running supersedes the prior active plan for this SO.
     */
    public function runForSalesOrder(SalesOrder $so): MrpPlan
    {
        return DB::transaction(function () use ($so) {
            // Lock + supersede prior active plan.
            $previous = MrpPlan::where('sales_order_id', $so->id)
                ->where('status', MrpPlanStatus::Active->value)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->first();
            if ($previous) {
                $previous->update(['status' => MrpPlanStatus::Superseded->value]);
            }

            // Load lines with product.
            $so->load('items.product');
            $lines = $so->items;

            // Aggregate gross requirements per material across all lines.
            $grossPerItem = []; // [item_id => float]
            $earliestNeedPerItem = []; // [item_id => Carbon]
            $linesPerItem = []; // [item_id => array of so_line_id]

            foreach ($lines as $line) {
                $exploded = collect();
                try {
                    $exploded = $this->boms->explode((int) $line->product_id, (float) $line->quantity);
                } catch (\Throwable $e) {
                    // No BOM — skip; the work-order side will still be created.
                    continue;
                }
                foreach ($exploded as $row) {
                    $iid = (int) $row['item_id'];
                    $grossPerItem[$iid] = ($grossPerItem[$iid] ?? 0.0) + (float) $row['gross_quantity'];
                    if (! isset($earliestNeedPerItem[$iid]) || $line->delivery_date->lt($earliestNeedPerItem[$iid])) {
                        $earliestNeedPerItem[$iid] = $line->delivery_date;
                    }
                    $linesPerItem[$iid][] = $line->id;
                }
            }

            // Build the plan row up front so we can stamp child records.
            $plan = MrpPlan::create([
                'mrp_plan_no'     => $this->sequences->generate('mrp_plan'),
                'sales_order_id'  => $so->id,
                'version'         => $previous ? $previous->version + 1 : 1,
                'status'          => MrpPlanStatus::Active->value,
                'generated_by'    => $so->created_by,
                'total_lines'     => count($lines),
                'shortages_found' => 0,
                'auto_pr_count'   => 0,
                'draft_wo_count'  => 0,
                'diagnostics'     => [],
                'generated_at'    => Carbon::now(),
            ]);

            // Calculate net requirements per material.
            $diagnostics = [];
            $shortages = []; // [item_id => ['net' => float, 'order_by' => Carbon, 'priority' => string, 'unit' => string]]

            foreach ($grossPerItem as $itemId => $gross) {
                $item = Item::find($itemId);
                if (! $item) continue;

                // Sprint 6 audit §1.4: lock the per-item stock_levels rows so
                // concurrent SO confirmations cannot race the same on-hand /
                // reserved quantities. Order by id for deterministic locking.
                $levels = StockLevel::where('item_id', $itemId)
                    ->orderBy('location_id')
                    ->lockForUpdate()
                    ->get();
                $onHand    = (float) $levels->sum('quantity');
                // "reserved by OTHER active WOs" — for this run, treat all current
                // reservations as already-consumed availability.
                $reserved  = (float) $levels->sum('reserved_quantity');
                $inTransit = $this->inTransit($itemId);

                $net = max(0.0, $gross - $onHand + $reserved - $inTransit);

                $entry = [
                    'item_id'    => $itemId,
                    'item_code'  => $item->code,
                    'gross'      => round($gross, 3),
                    'on_hand'    => round($onHand, 3),
                    'reserved'   => round($reserved, 3),
                    'in_transit' => round($inTransit, 3),
                    'net'        => round($net, 3),
                    'action'     => 'sufficient',
                ];

                if ($net > 0) {
                    $leadTime = $this->effectiveLeadTime($itemId, $item);
                    $earliest = $earliestNeedPerItem[$itemId] ?? Carbon::now()->addDays(7);
                    $orderBy  = $earliest->copy()->subDays($leadTime + self::SAFETY_BUFFER_DAYS);

                    $priority = $orderBy->lte(Carbon::today()) ? 'urgent' : 'normal';
                    $shortages[$itemId] = [
                        'net'      => $net,
                        'order_by' => $orderBy,
                        'priority' => $priority,
                        'unit'     => $item->unit_of_measure,
                        'estimated_unit_price' => (float) $item->standard_cost,
                    ];

                    $entry['action']   = 'pr_created';
                    $entry['order_by'] = $orderBy->toDateString();
                    $entry['priority'] = $priority;
                    $entry['lead_time_days'] = $leadTime;
                }
                $diagnostics[] = $entry;
            }

            // Create one consolidated draft PR for all shortages.
            $autoPrCount = 0;
            if (! empty($shortages)) {
                $pr = PurchaseRequest::create([
                    'pr_number'         => $this->sequences->generate('pr'),
                    'requested_by'      => $so->created_by,
                    'department_id'     => null, // SO creator's dept; resolved at submit time
                    'mrp_plan_id'       => $plan->id,
                    'date'              => Carbon::today(),
                    'reason'            => "Auto-generated from MRP plan {$plan->mrp_plan_no} for SO {$so->so_number}.",
                    'priority'          => collect($shortages)->contains(fn ($s) => $s['priority'] === 'urgent') ? 'urgent' : 'normal',
                    'status'            => 'draft',
                    'is_auto_generated' => true,
                ]);

                foreach ($shortages as $itemId => $s) {
                    PurchaseRequestItem::create([
                        'purchase_request_id'  => $pr->id,
                        'item_id'              => $itemId,
                        'description'          => null,
                        'quantity'             => round($s['net'], 2),
                        'unit'                 => $s['unit'],
                        'estimated_unit_price' => round($s['estimated_unit_price'], 2),
                        'purpose'              => "MRP demand for SO {$so->so_number}",
                    ]);
                }
                $autoPrCount = 1; // one consolidated PR per run
            }

            // Create one draft WO per SO line.
            $draftWoCount = 0;
            foreach ($lines as $line) {
                $this->workOrders->createDraft([
                    'product_id'          => $line->product_id,
                    'sales_order_id'      => $so->id,
                    'sales_order_item_id' => $line->id,
                    'mrp_plan_id'         => $plan->id,
                    'quantity_target'     => (int) $line->quantity,
                    'planned_start'       => $line->delivery_date->copy()->subDays(2)->toDateTimeString(),
                    'planned_end'         => $line->delivery_date->copy()->subDay()->toDateTimeString(),
                    'priority'            => $line->delivery_date->diffInDays(Carbon::now()) <= 7 ? 100 : 50,
                    'created_by'          => $so->created_by,
                ]);
                $draftWoCount++;
            }

            // Finalise plan totals.
            $plan->update([
                'shortages_found' => count($shortages),
                'auto_pr_count'   => $autoPrCount,
                'draft_wo_count'  => $draftWoCount,
                'diagnostics'     => $diagnostics,
            ]);

            // Link the SO to this plan.
            $so->update(['mrp_plan_id' => $plan->id]);

            // Sprint 6 audit §1.7: broadcast plan-generated event so the
            // dashboard's StageBreakdown and Alerts panels refresh in real
            // time. Dispatched after-commit so subscribers see the
            // persisted row.
            $finalPlan = $plan->fresh();
            DB::afterCommit(fn () => event(new \App\Modules\MRP\Events\MrpPlanGenerated($finalPlan)));

            return $this->show($finalPlan);
        });
    }

    public function rerun(MrpPlan $plan): MrpPlan
    {
        return $this->runForSalesOrder($plan->salesOrder()->firstOrFail());
    }

    public function list(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $q = MrpPlan::query()
            ->with(['salesOrder:id,so_number,customer_id', 'salesOrder.customer:id,name', 'generator:id,name,role_id']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['sales_order_id'])) {
            $sid = \App\Common\Support\HashIdFilter::decode($filters['sales_order_id'], SalesOrder::class);
            if ($sid) $q->where('sales_order_id', $sid);
        }

        return $q->orderByDesc('generated_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(MrpPlan $plan): MrpPlan
    {
        return $plan->load([
            'salesOrder.customer:id,name',
            'generator:id,name,role_id',
            'workOrders:id,wo_number,product_id,quantity_target,status,planned_start',
            'purchaseRequests:id,pr_number,priority,status,is_auto_generated,date',
        ]);
    }

    /**
     * Sum of (purchase_order_items.quantity - quantity_received) across all
     * approved / sent / partially_received POs for this item.
     */
    private function inTransit(int $itemId): float
    {
        $row = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('poi.item_id', $itemId)
            ->whereIn('po.status', ['approved', 'sent', 'partially_received'])
            ->selectRaw('COALESCE(SUM(poi.quantity - poi.quantity_received), 0) as in_transit')
            ->first();
        return (float) ($row->in_transit ?? 0);
    }

    /**
     * Largest of (preferred approved supplier lead time, item.lead_time_days).
     * Falls back to 14 days only when neither is configured.
     *
     * Sprint 6 audit §1.5: previous max(14, ...) clamp inflated urgency
     * flagging for items with rush suppliers; respect configured values.
     */
    private function effectiveLeadTime(int $itemId, Item $item): int
    {
        $approved = ApprovedSupplier::where('item_id', $itemId)
            ->orderByDesc('is_preferred')
            ->orderBy('lead_time_days')
            ->first();
        $supplierLT = (int) ($approved?->lead_time_days ?? 0);
        $itemLT     = (int) $item->lead_time_days;
        $configured = max($supplierLT, $itemLT);
        return $configured > 0 ? $configured : 14;
    }
}
