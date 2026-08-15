<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 6 — Task 52. MRP engine.
 *
 * Run on SalesOrderService::confirm(). Produces:
 *  - One mrp_plans row per run (versioned).
 *  - Draft purchase_requests for any raw-material shortfall (one PR row
 *    consolidating all material lines for the SO; each line is one
 *    purchase_request_items row). is_auto_generated=true, priority is set
 *    to 'urgent' when order_by_date <= today, else 'normal'.
 *  - Draft work_orders (status='planned') — one root per SO line plus one
 *    linked child per manufactured subassembly. Each WO receives only its
 *    immediate BOM components via WorkOrderService::createDraft().
 *
 * Net-requirement math (per material):
 *   gross      = Σ over SO lines: bom.quantity_per_unit * (1 + waste_factor/100) * remaining line.quantity
 *   on_hand    = Σ stock_levels.quantity over all locations
 *   reserved   = Σ stock_levels.reserved_quantity over all locations
 *   in_transit = Σ purchase_order_items.(quantity - quantity_received) for POs in approved/sent/partial
 *   open_pr    = pending/approved PR quantity already linked to this SO
 *   net        = max(0, gross - open_pr - on_hand + reserved - in_transit)
 *
 * Lead time + safety buffer:
 *   order_by_date = earliest_so_line.delivery_date - max(approved_supplier.lead_time, items.lead_time_days) - 2 days
 *
 * Each line's outcome is recorded in mrp_plans.diagnostics.
 */
class MrpEngineService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly BomService $boms,
        private readonly WorkOrderService $workOrders,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Run MRP for a confirmed sales order. Idempotent at the per-run level;
     * re-running supersedes the prior active plan for this SO.
     */
    public function runForSalesOrder(SalesOrder $so, ?array &$sharedSupply = null): MrpPlan
    {
        $sharedSupplyBeforeRun = $sharedSupply;

        try {
            return DB::transaction(function () use ($so, &$sharedSupply) {
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
            // L-32 — warnings collected during the explode pass are carried
            // into the diagnostics array built below.
            $diagnostics = [];
            $productionNodesByLine = [];
            $planningSupply = $sharedSupply ?? [];

            $quantityToManufacture = function (
                int $subassemblyProductId,
                int $itemId,
                float $grossQuantity,
            ) use (&$planningSupply): float {
                return $this->quantityToManufacture($itemId, $grossQuantity, $planningSupply);
            };

            $remainingQuantityByLine = [];

            foreach ($lines as $line) {
                $remainingQuantity = max(0.0, (float) $line->quantity - (float) $line->quantity_delivered);
                $remainingQuantityByLine[$line->id] = $remainingQuantity;
                if ($remainingQuantity <= 0.000001) {
                    continue;
                }

                if ($this->boms->activeForProduct((int) $line->product_id) === null) {
                    // L-32 — No active BOM. Skip the demand explosion (the WO
                    // side still produces a planned WO so PPC can act), but
                    // record a warning row so the plan detail page surfaces
                    // the gap instead of failing silently.
                    $diagnostics[] = [
                        'kind'             => 'warning',
                        'type'             => 'missing_bom',
                        'product_id'       => (int) $line->product_id,
                        'sales_order_line_id' => (int) $line->id,
                        'message'          => 'No active BOM found for this product; demand explosion skipped.',
                    ];
                    continue;
                }
                // Invalid BOM data (cycles, depth overflow, or missing UOM
                // conversions) must fail the run rather than being mislabeled
                // as a missing BOM and silently under-planning demand.
                $productionPlan = $this->boms->productionPlan(
                    (int) $line->product_id,
                    $remainingQuantity,
                    $quantityToManufacture,
                );
                $productionNodesByLine[$line->id] = $productionPlan['subassemblies'];
                foreach ($productionPlan['materials'] as $row) {
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
            // Note: $diagnostics may already contain BOM-missing warnings from above.
            $shortages = []; // [item_id => ['net' => float, 'order_by' => Carbon, 'priority' => string, 'unit' => string]]

            foreach ($grossPerItem as $itemId => $gross) {
                $item = Item::find($itemId);
                if (! $item) continue;

                // Sprint 6 audit §1.4: lock the per-item stock_levels rows so
                // concurrent SO confirmations cannot race the same on-hand /
                // reserved quantities. Order by id for deterministic locking.
                // F-02 — quarantine/scrap-zone stock is held or scrapped and
                // must not satisfy gross requirements.
                $supply = $this->supplyForItem($itemId, $planningSupply);

                // Pending/approved auto-PRs already represent supply committed
                // to this SO. Count them before creating another shortage, while
                // keeping them isolated from other SOs in an all-SO run.
                $openPurchaseRequests = $this->openPurchaseRequestQuantity((int) $so->id, (int) $itemId);
                $grossAfterOpenRequests = max(0.0, $gross - $openPurchaseRequests);
                $availableBeforeAllocation = max(0.0, (float) $supply['available']);
                $consumedFromSharedSupply = min($grossAfterOpenRequests, $availableBeforeAllocation);
                $net = max(0.0, $grossAfterOpenRequests - $availableBeforeAllocation);

                $planningSupply[$itemId]['available'] = $availableBeforeAllocation - $consumedFromSharedSupply;

                $entry = [
                    'item_id'    => $itemId,
                    'item_code'  => $item->code,
                    'gross'      => round($gross, 3),
                    'on_hand'    => round((float) $supply['on_hand'], 3),
                    'reserved'   => round((float) $supply['reserved'], 3),
                    'in_transit' => round((float) $supply['in_transit'], 3),
                    'open_purchase_requests' => round($openPurchaseRequests, 3),
                    'net'        => round($net, 3),
                    'action'     => 'sufficient',
                ];

                if ($net > 0) {
                    $leadTime = $this->effectiveLeadTime($itemId, $item);
                    $earliest = $earliestNeedPerItem[$itemId] ?? null;
                    if (! $earliest) {
                        throw new BusinessRuleException("No required delivery date is available for item {$item->code}.");
                    }
                    $orderBy  = $earliest->copy()->subDays($leadTime + $this->safetyBufferDays());

                    $priority = $orderBy->lte(Carbon::today()) ? 'urgent' : 'normal';
                    $shortages[$itemId] = [
                        'net'      => $net,
                        'order_by' => $orderBy,
                        'priority' => $priority,
                        'unit'     => $item->unit_of_measure,
                        'estimated_unit_price' => (float) $item->standard_cost,
                        'name'     => $item->name,
                    ];

                    $entry['action']   = 'pr_created';
                    $entry['order_by'] = $orderBy->toDateString();
                    $entry['priority'] = $priority;
                    $entry['lead_time_days'] = $leadTime;
                }
                $diagnostics[] = $entry;
            }

            if ($sharedSupply !== null) {
                $sharedSupply = $planningSupply;
            }

            // Create one consolidated draft PR for all shortages — reconciled
            // against the superseded plan's children so a re-run reuses rather
            // than duplicates (Round 2 — MRP rerun safety). Only
            // is_auto_generated + draft rows are eligible; progressed PRs
            // (pending, approved, …) and manual PRs are never touched.
            $autoPrCount = 0;
            if (! empty($shortages)) {
                $priorDraftAutoPrs = PurchaseRequest::query()
                    ->where('is_auto_generated', true)
                    ->where('status', PurchaseRequestStatus::Draft->value)
                    ->whereHas('mrpPlan', fn ($q) => $q
                        ->where('sales_order_id', $so->id)
                        ->where('id', '!=', $plan->id))
                    ->orderByDesc('id')
                    ->get();

                // Reuse the latest eligible draft auto-PR, refreshed to current
                // requirements; cancel any older surplus drafts.
                $pr = $priorDraftAutoPrs->shift();
                foreach ($priorDraftAutoPrs as $surplus) {
                    $surplus->forceFill(['status' => PurchaseRequestStatus::Cancelled->value])->save();
                }

                if ($pr === null) {
                    $pr = PurchaseRequest::create([
                        'pr_number'         => $this->sequences->generate('pr'),
                        'requested_by'      => $so->created_by,
                        'department_id'     => null, // SO creator's dept; resolved at submit time
                        'mrp_plan_id'       => $plan->id,
                        'date'              => Carbon::today(),
                        'reason'            => "Auto-generated from MRP plan {$plan->mrp_plan_no} for SO {$so->so_number}.",
                        'priority'          => collect($shortages)->contains(fn ($s) => $s['priority'] === 'urgent') ? 'urgent' : 'normal',
                        'is_auto_generated' => true,
                    ]);
                } else {
                    // status non-fillable; service-only. Repoint the reused PR
                    // to the current plan and refresh its lines.
                    $pr->forceFill([
                        'status'      => PurchaseRequestStatus::Draft->value,
                        'mrp_plan_id' => $plan->id,
                        'date'        => Carbon::today(),
                        'reason'      => "Auto-generated from MRP plan {$plan->mrp_plan_no} for SO {$so->so_number}.",
                        'priority'    => collect($shortages)->contains(fn ($s) => $s['priority'] === 'urgent') ? 'urgent' : 'normal',
                    ])->save();
                    $pr->items()->delete();
                }

                foreach ($shortages as $itemId => $s) {
                    PurchaseRequestItem::create([
                        'purchase_request_id'  => $pr->id,
                        'item_id'              => $itemId,
                        'description'          => $s['name'],
                        // Purchase-request quantities have two decimal places;
                        // round shortages upward so precision loss cannot
                        // under-order a fractional BOM requirement.
                        'quantity'             => number_format(ceil(max(0.0, (float) $s['net']) * 100 - 0.000000001) / 100, 2, '.', ''),
                        'unit'                 => $s['unit'],
                        'estimated_unit_price' => round($s['estimated_unit_price'], 2),
                        'purpose'              => "MRP demand for SO {$so->so_number}",
                    ]);
                }
                $autoPrCount = 1; // one consolidated PR per run
            } else {
                // No shortages on this run — retire leftover draft auto-PRs so
                // the purchasing queue never shows demand that no longer exists.
                PurchaseRequest::query()
                    ->where('is_auto_generated', true)
                    ->where('status', PurchaseRequestStatus::Draft->value)
                    ->whereHas('mrpPlan', fn ($q) => $q
                        ->where('sales_order_id', $so->id)
                        ->where('id', '!=', $plan->id))
                    ->update([
                        'status'     => PurchaseRequestStatus::Cancelled->value,
                        'updated_at' => now(),
                    ]);
            }

            // Create one draft WO per SO line — reusing a prior plan's planned
            // WO for the same line instead of duplicating (Round 2 — MRP rerun
            // safety). Progressed WOs (confirmed and beyond) and manual WOs
            // (no mrp_plan_id) are never repointed or cancelled.
            $draftWoCount = 0;
            $urgentDeliveryDays = $this->settings->requiredInt('mrp.work_order.urgent_delivery_days', 0, 3650);
            $urgentPriority = $this->settings->requiredInt('mrp.work_order.urgent_priority', 0, 255);
            $normalPriority = $this->settings->requiredInt('mrp.work_order.normal_priority', 0, 255);
            foreach ($lines as $line) {
                $remainingQuantity = $remainingQuantityByLine[$line->id] ?? 0.0;
                if ($remainingQuantity <= 0.000001) {
                    continue;
                }

                $plannedStart = $line->delivery_date->copy()->subDays(2)->toDateTimeString();
                $plannedEnd   = $line->delivery_date->copy()->subDay()->toDateTimeString();
                $priority     = $line->delivery_date->diffInDays(Carbon::now()) <= $urgentDeliveryDays
                    ? $urgentPriority
                    : $normalPriority;

                $progressedWos = WorkOrder::query()
                    ->where('sales_order_item_id', $line->id)
                    ->whereNull('parent_wo_id')
                    ->whereIn('status', [
                        WorkOrderStatus::Confirmed->value,
                        WorkOrderStatus::InProgress->value,
                        WorkOrderStatus::Paused->value,
                    ])
                    ->get(['quantity_target', 'quantity_produced']);
                $openProduction = $progressedWos->sum(function (WorkOrder $workOrder): float {
                    return max(0.0, (float) $workOrder->quantity_target - (float) $workOrder->quantity_produced);
                });

                $priorPlanned = WorkOrder::query()
                    ->where('sales_order_item_id', $line->id)
                    ->whereNull('parent_wo_id')
                    ->whereNotNull('mrp_plan_id')
                    ->where('mrp_plan_id', '!=', $plan->id)
                    ->where('status', WorkOrderStatus::Planned->value)
                    ->orderByDesc('id')
                    ->get();

                if ($openProduction >= $remainingQuantity) {
                    foreach ($priorPlanned as $surplus) {
                        $surplus->forceFill(['status' => WorkOrderStatus::Cancelled->value])->save();
                    }
                    $this->cancelStalePlannedChildWorkOrders($line->id, $plan->id);
                    continue;
                }
                $workOrderQuantity = max(0.0, $remainingQuantity - $openProduction);
                $workOrderTarget = (int) ceil($workOrderQuantity);
                $rootWorkOrder = null;

                if ($priorPlanned->isNotEmpty()) {
                    $reuse = $priorPlanned->shift();
                    $reuse->forceFill([
                        'mrp_plan_id'     => $plan->id,
                        'quantity_target' => $workOrderTarget,
                        'planned_start'   => $plannedStart,
                        'planned_end'     => $plannedEnd,
                        'priority'        => $priority,
                    ])->save();
                    foreach ($priorPlanned as $surplus) {
                        $surplus->forceFill(['status' => WorkOrderStatus::Cancelled->value])->save();
                    }
                    $rootWorkOrder = $reuse->fresh();
                    $draftWoCount++;
                } else {
                    $rootWorkOrder = $this->workOrders->createDraft([
                        'product_id'          => $line->product_id,
                        'sales_order_id'      => $so->id,
                        'sales_order_item_id' => $line->id,
                        'mrp_plan_id'         => $plan->id,
                        'quantity_target'     => $workOrderTarget,
                        'planned_start'       => $plannedStart,
                        'planned_end'         => $plannedEnd,
                        'priority'            => $priority,
                        'created_by'          => $so->created_by,
                    ]);
                    $draftWoCount++;
                }

                $productionNodes = $productionNodesByLine[$line->id] ?? [];
                if ($rootWorkOrder !== null && $productionNodes !== []) {
                    $draftWoCount += $this->createSubassemblyWorkOrders(
                        $productionNodes,
                        $rootWorkOrder,
                        $so,
                        $line,
                        $plan,
                        $priority,
                    );
                }
                $this->cancelStalePlannedChildWorkOrders($line->id, $plan->id);
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

            $finalPlan = $plan->fresh();
            app(OutboxService::class)->recordForChain(
                new MrpPlanGenerated($finalPlan),
                $so,
                'o2c',
                'sales_order',
                'mrp_plan_generated',
            );

            return $this->show($finalPlan);
            });
        } catch (\Throwable $e) {
            if ($sharedSupply !== null) {
                $sharedSupply = $sharedSupplyBeforeRun;
            }

            throw $e;
        }
    }

    public function rerun(MrpPlan $plan): MrpPlan
    {
        return $this->runForSalesOrder($plan->salesOrder()->firstOrFail());
    }

    /**
     * Task A1 — Re-runs MRP across every active sales order. Creates one
     * MrpRun history row, increments counters per SO, and rolls back via
     * the run-row's status on catastrophic failure.
     *
     * Active = confirmed | in_production | partially_delivered.
     *
     * Idempotency: each runForSalesOrder() supersedes the prior plan and
     * reconciles its draft auto-PR/planned WO children. Progressed purchasing
     * and production records remain authoritative and are included in the
     * next net-requirement calculation.
     */
    public function runForAllActiveSalesOrders(MrpRunTrigger $trigger, ?int $userId = null): MrpRun
    {
        return $this->runForActiveSalesOrders($trigger, $userId, null);
    }

    /**
     * Run MRP for all active SOs or an affected subset. Passing an empty list
     * deliberately evaluates no orders; null is the plant-wide fallback.
     *
     * @param list<int>|null $salesOrderIds
     */
    public function runForActiveSalesOrders(
        MrpRunTrigger $trigger,
        ?int $userId = null,
        ?array $salesOrderIds = null,
    ): MrpRun
    {
        $start = microtime(true);

        $run = MrpRun::create([
            'run_at'               => now(),
            'started_at'           => now(),
            'heartbeat_at'         => now(),
            'triggered_by'         => $trigger->value,
            'triggered_by_user_id' => $userId,
            'status'               => MrpRunStatus::Running->value,
        ]);

        try {
            $salesOrderQuery = SalesOrder::whereIn('status', [
                'confirmed', 'in_production', 'partially_delivered',
            ]);
            if ($salesOrderIds !== null) {
                $salesOrderQuery->whereIn('id', array_values(array_unique(array_map('intval', $salesOrderIds))));
            }
            $sos = $salesOrderQuery->get();

            $shortagesTotal = 0;
            $prsCreated     = 0;
            $prsUpdated     = 0;
            $plansGenerated = 0;
            $perSo          = [];
            $sharedSupply   = [];

            foreach ($sos as $so) {
                try {
                    $run->forceFill(['heartbeat_at' => now()])->saveQuietly();

                    $beforeAutoPrs = PurchaseRequest::where('is_auto_generated', true)
                        ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
                        ->where('status', 'draft')
                        ->count();

                    $plan = $this->runForSalesOrder($so, $sharedSupply);
                    $plansGenerated++;
                    $shortagesTotal += (int) $plan->shortages_found;

                    $afterAutoPrs = PurchaseRequest::where('is_auto_generated', true)
                        ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
                        ->where('status', 'draft')
                        ->count();

                    $delta = $afterAutoPrs - $beforeAutoPrs;
                    if ($delta > 0) {
                        $prsCreated += $delta;
                    } elseif ($beforeAutoPrs > 0) {
                        $prsUpdated += 1;
                    }

                    $perSo[] = [
                        'so_id'           => $so->id,
                        'so_number'       => $so->so_number,
                        'shortages_found' => (int) $plan->shortages_found,
                        'plan_no'         => $plan->mrp_plan_no,
                    ];
                    $run->forceFill(['heartbeat_at' => now()])->saveQuietly();
                } catch (\Throwable $inner) {
                    Log::warning('MRP run: SO failed', [
                        'so_id'   => $so->id,
                        'so_number' => $so->so_number,
                        'error'   => $inner->getMessage(),
                    ]);
                    $perSo[] = [
                        'so_id'     => $so->id,
                        'so_number' => $so->so_number,
                        'error'     => $inner->getMessage(),
                    ];
                    $run->forceFill(['heartbeat_at' => now()])->saveQuietly();
                }
            }

            $run->update([
                'sales_orders_evaluated' => $sos->count(),
                'shortages_found'        => $shortagesTotal,
                'prs_created'            => $prsCreated,
                'prs_updated'            => $prsUpdated,
                'plans_generated'        => $plansGenerated,
                'duration_ms'            => (int) round((microtime(true) - $start) * 1000),
                'status'                 => MrpRunStatus::Completed->value,
                'summary'                => ['per_sales_order' => $perSo],
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'duration_ms'   => (int) round((microtime(true) - $start) * 1000),
                'status'        => MrpRunStatus::Failed->value,
                'error_message' => $e->getMessage(),
            ]);
            Log::error('MRP run: catastrophic failure', ['error' => $e->getMessage()]);
        }

        return $run->fresh();
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
            'workOrders:id,wo_number,product_id,quantity_target,status,planned_start,mrp_plan_id,parent_wo_id',
            'workOrders.parent:id,wo_number',
            'purchaseRequests:id,pr_number,priority,status,is_auto_generated,date,mrp_plan_id',
        ]);
    }

    /**
     * Create or reconcile the manufactured subassembly WOs beneath one
     * parent. Planned children from the superseded plan are reused by product
     * and parent, so an MRP rerun preserves the production tree instead of
     * appending duplicate records.
     *
     * @param list<array{product_id:int,item_id:int,item_code:string,quantity:string,children:list<array>}> $nodes
     */
    private function createSubassemblyWorkOrders(
        array $nodes,
        WorkOrder $parent,
        SalesOrder $so,
        SalesOrderItem $line,
        MrpPlan $plan,
        int $priority,
    ): int {
        $createdCount = 0;

        foreach ($nodes as $node) {
            $quantity = (int) ceil((float) $node['quantity']);
            if ($quantity <= 0) {
                continue;
            }

            $parentStart = $parent->planned_start instanceof Carbon
                ? $parent->planned_start->copy()
                : Carbon::parse($parent->planned_start);
            $childEnd = $parentStart->copy()->subDay();
            $childStart = $childEnd->copy()->subDay();

            $priorChildren = WorkOrder::query()
                ->where('parent_wo_id', $parent->id)
                ->where('product_id', (int) $node['product_id'])
                ->whereNotNull('mrp_plan_id')
                ->where('mrp_plan_id', '!=', $plan->id)
                ->where('status', WorkOrderStatus::Planned->value)
                ->orderByDesc('id')
                ->get();

            $child = $priorChildren->shift();
            if ($child !== null) {
                $child->forceFill([
                    'mrp_plan_id'     => $plan->id,
                    'quantity_target' => $quantity,
                    'planned_start'   => $childStart,
                    'planned_end'     => $childEnd,
                    'priority'        => $priority,
                ])->save();
                $child = $child->fresh();

                foreach ($priorChildren as $surplus) {
                    $surplus->forceFill(['status' => WorkOrderStatus::Cancelled->value])->save();
                }
            } else {
                $child = $this->workOrders->createDraft([
                    'product_id'          => (int) $node['product_id'],
                    'sales_order_id'      => $so->id,
                    'sales_order_item_id' => $line->id,
                    'mrp_plan_id'         => $plan->id,
                    'parent_wo_id'        => $parent->id,
                    'quantity_target'     => $quantity,
                    'planned_start'       => $childStart,
                    'planned_end'         => $childEnd,
                    'priority'            => $priority,
                    'created_by'          => $so->created_by,
                ]);
            }

            $createdCount++;
            $createdCount += $this->createSubassemblyWorkOrders(
                $node['children'],
                $child,
                $so,
                $line,
                $plan,
                $priority,
            );
        }

        return $createdCount;
    }

    /**
     * Retire auto-generated children that no longer belong to the active BOM
     * hierarchy after a BOM revision. Progressed and manually created WOs are
     * left untouched; only planned children linked to an older MRP plan are
     * safe for this reconciliation.
     */
    private function cancelStalePlannedChildWorkOrders(int $salesOrderItemId, int $planId): void
    {
        WorkOrder::query()
            ->where('sales_order_item_id', $salesOrderItemId)
            ->whereNotNull('parent_wo_id')
            ->whereNotNull('mrp_plan_id')
            ->where('mrp_plan_id', '!=', $planId)
            ->where('status', WorkOrderStatus::Planned->value)
            ->update([
                'status' => WorkOrderStatus::Cancelled->value,
                'updated_at' => now(),
            ]);
    }

    /**
     * Load and cache usable supply for one inventory item. The cache is shared
     * across SOs during a multi-order MRP run so one order cannot consume the
     * same stock that an earlier order already allocated.
     *
     * @param array<int, array{on_hand:float,reserved:float,in_transit:float,available:float}> $planningSupply
     * @return array{on_hand:float,reserved:float,in_transit:float,available:float}
     */
    private function supplyForItem(int $itemId, array &$planningSupply): array
    {
        if (array_key_exists($itemId, $planningSupply)) {
            return $planningSupply[$itemId];
        }

        $levels = StockLevel::where('item_id', $itemId)
            ->whereHas('location.zone', function ($q) {
                $q->whereNotIn('zone_type', [
                    \App\Modules\Inventory\Enums\WarehouseZoneType::Quarantine->value,
                    \App\Modules\Inventory\Enums\WarehouseZoneType::Scrap->value,
                ]);
            })
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get();
        $onHand = (float) $levels->sum('quantity');
        $reserved = (float) $levels->sum('reserved_quantity');
        $inTransit = $this->inTransit($itemId);

        return $planningSupply[$itemId] = [
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'in_transit' => $inTransit,
            'available' => max(0.0, $onHand - $reserved + $inTransit),
        ];
    }

    /**
     * Allocate available stock to a manufactured subassembly and return only
     * the quantity that still needs a child work order.
     *
     * @param array<int, array{on_hand:float,reserved:float,in_transit:float,available:float}> $planningSupply
     */
    private function quantityToManufacture(int $itemId, float $grossQuantity, array &$planningSupply): float
    {
        $supply = $this->supplyForItem($itemId, $planningSupply);
        $available = max(0.0, (float) $supply['available']);
        $consumed = min(max(0.0, $grossQuantity), $available);
        $planningSupply[$itemId]['available'] = $available - $consumed;

        return max(0.0, $grossQuantity - $consumed);
    }

    /**
     * Quantity already covered by open purchase requests for one SO/item.
     * Draft requests are intentionally excluded because this service owns and
     * reconciles those rows on the current run; pending/approved requests have
     * crossed the purchasing handoff and must not be duplicated.
     */
    private function openPurchaseRequestQuantity(int $salesOrderId, int $itemId): float
    {
        $row = PurchaseRequestItem::query()
            ->where('item_id', $itemId)
            ->whereHas('purchaseRequest', function ($q) use ($salesOrderId): void {
                $q->whereIn('status', [
                    PurchaseRequestStatus::Pending->value,
                    PurchaseRequestStatus::Approved->value,
                ])->whereHas('mrpPlan', fn ($plan) => $plan->where('sales_order_id', $salesOrderId));
            })
            ->selectRaw('COALESCE(SUM(quantity), 0) as quantity')
            ->first();

        return (float) ($row->quantity ?? 0);
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
            ->whereIn('po.status', [
                PurchaseOrderStatus::Approved->value,
                PurchaseOrderStatus::Sent->value,
                PurchaseOrderStatus::PartiallyReceived->value,
            ])
            ->selectRaw('COALESCE(SUM(poi.quantity - poi.quantity_received), 0) as in_transit')
            ->first();
        return (float) ($row->in_transit ?? 0);
    }

    /**
     * Largest of (preferred approved supplier lead time, item.lead_time_days).
     * Uses the persisted MRP policy only when neither source is configured.
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
        return $configured > 0 ? $configured : $this->positiveIntSetting('mrp.default_lead_time_days');
    }

    private function safetyBufferDays(): int
    {
        return $this->nonNegativeIntSetting('mrp.safety_buffer_days');
    }

    private function positiveIntSetting(string $key): int
    {
        $value = $this->nonNegativeIntSetting($key);
        if ($value < 1) {
            throw new BusinessRuleException("MRP setting {$key} must be at least one.");
        }

        return $value;
    }

    private function nonNegativeIntSetting(string $key): int
    {
        $value = $this->settings->get($key, '__missing_mrp_policy__');
        if (! is_numeric($value) || (int) $value < 0) {
            throw new BusinessRuleException("Required MRP setting {$key} is not configured or invalid.");
        }

        return (int) $value;
    }
}
