<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\HR\Models\Department;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Models\MrpRun;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Production\Services\WorkOrderMaterialUsageService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\OpenSupplyService;
use App\Modules\Quality\Enums\InspectionStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 6 — Task 52. MRP engine.
 *
 * Run by the automatic queue after sales-order confirmation. Produces:
 *  - One mrp_plans row per run (versioned).
 *  - Draft purchase_requests for any raw-material shortfall (one PR row
 *    consolidating all material lines for the SO; each line is one
 *    purchase_request_items row). is_auto_generated=true, priority is set
 *    to 'urgent' when order_by_date <= today, else 'normal'. Each line
 *    quantity is the net shortage ceiled to 3dp, then rounded up to the
 *    item's minimum_order_quantity multiple when one is set (MRP-01).
 *  - Draft work_orders (status='planned') — one root per SO line plus one
 *    linked child per manufactured subassembly. Each WO receives only its
 *    immediate BOM components via WorkOrderService::createDraft().
 *
 * Net-requirement math (per material):
 *   gross      = BOM requirements for SO units not delivered or already produced as good output
 *   committed  = this SO's unconverted PR, live PO/QC hold and unfinished WO material
 *   available  = free non-quarantine stock plus unallocated purchasing supply above safety stock
 *   net        = max(0, gross - committed - available)
 *
 * MRP-01: safety stock is a buffer against variability, not consumable
 * supply — netting may only spend stock above it.
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
        private readonly OpenSupplyService $openSupply,
    ) {}

    private function resolveAutoPurchaseRequestDepartment(SalesOrder $so, ?MrpRun $run, ?int $actorId): ?int
    {
        $requesterId = $actorId ?? (int) $so->created_by;
        $requesterDepartmentId = User::query()
            ->with('employee:id,department_id')
            ->find($requesterId)?->employee?->department_id;

        if ($requesterDepartmentId !== null) {
            return (int) $requesterDepartmentId;
        }

        $runDepartmentId = $run?->triggered_by_user_id
            ? User::query()
                ->with('employee:id,department_id')
                ->find($run->triggered_by_user_id)?->employee?->department_id
            : null;

        if ($runDepartmentId !== null) {
            return (int) $runDepartmentId;
        }

        // Automatic MRP has no human actor. PPC owns the material-planning need
        // and provides the accountable budget when the SO creator is not an
        // employee, such as a CRM service account.
        return Department::query()->where('code', 'PPC')->value('id');
    }

    /**
     * Run MRP for a confirmed sales order. Idempotent at the per-run level;
     * re-running supersedes the prior active plan for this SO.
     */
    public function runForSalesOrder(
        SalesOrder $so,
        ?array &$sharedSupply = null,
        ?MrpRun $run = null,
        ?int $initiatingActorId = null,
        ?string $triggerReason = null,
    ): MrpPlan {
        $sharedSupplyBeforeRun = $sharedSupply;

        try {
            return DB::transaction(function () use ($so, &$sharedSupply, $run, $initiatingActorId, $triggerReason) {
                if ($run !== null) {
                    $activeRun = MrpRun::query()
                        ->whereKey($run->id)
                        ->where('status', MrpRunStatus::Running->value)
                        ->lockForUpdate()
                        ->first();

                    if ($activeRun === null) {
                        throw new BusinessRuleException(
                            'MRP run was reaped before this sales order could be planned. Retry the affected sales order.'
                        );
                    }
                }

                $actorId = $initiatingActorId ?? $run?->triggered_by_user_id;
                $recordedBy = $actorId ?? (int) $so->created_by;
                $purchaseRequestDepartmentId = $this->resolveAutoPurchaseRequestDepartment($so, $run, $actorId);
                $trigger = $run?->triggered_by instanceof MrpRunTrigger
                    ? $run->triggered_by->value
                    : (string) ($run?->triggered_by ?? 'direct');
                $generationContext = [
                    'source' => 'sales_order',
                    'source_id' => (int) $so->id,
                    'trigger' => $trigger,
                    'reason' => $triggerReason ?? 'mrp_planning',
                    'run_id' => $run?->id,
                    'actor_type' => $actorId === null ? 'system' : 'user',
                    'actor_id' => $actorId,
                ];
                // Lock + supersede prior active plan.
                $previous = MrpPlan::where('sales_order_id', $so->id)
                    ->where('status', MrpPlanStatus::Active->value)
                    ->lockForUpdate()
                    ->orderByDesc('version')
                    ->first();
                if ($previous) {
                    $previous->forceFill(['status' => MrpPlanStatus::Superseded->value])->save();
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
                $costSummary = [
                    'material_cost' => Money::zero(),
                    'labor_cost' => Money::zero(),
                    'machine_cost' => Money::zero(),
                    'overhead_cost' => Money::zero(),
                    'planned_production_cost' => Money::zero(),
                    'products' => [],
                ];
                $planningSupply = $sharedSupply ?? [];

                $quantityToManufacture = function (
                    int $subassemblyProductId,
                    int $itemId,
                    float $grossQuantity,
                ) use (&$planningSupply): float {
                    return $this->quantityToManufacture($itemId, $grossQuantity, $planningSupply);
                };

                $remainingQuantityByLine = [];
                $bomAvailableByLine = [];

                foreach ($lines as $line) {
                    $remainingQuantity = $this->remainingToProduce($line);
                    $remainingQuantityByLine[$line->id] = $remainingQuantity;
                    if ($remainingQuantity <= 0.000001) {
                        if ((float) $line->quantity_delivered < (float) $line->quantity) {
                            $diagnostics[] = [
                                'kind' => 'warning',
                                'type' => 'production_already_committed',
                                'product_id' => (int) $line->product_id,
                                'sales_order_line_id' => (int) $line->id,
                                'message' => 'Production already covers the remaining sales-order quantity. Confirm outgoing QC and delivery before ordering more material.',
                            ];
                        }
                        continue;
                    }

                    $activeBom = $this->boms->activeForProduct((int) $line->product_id);
                    if ($activeBom === null) {
                        // A standard work order without a material plan is not an
                        // executable production commitment. Keep the plan
                        // visible, but block both explosion and WO creation.
                        $bomAvailableByLine[$line->id] = false;
                        $diagnostics[] = [
                            'kind' => 'warning',
                            'type' => 'missing_bom',
                            'product_id' => (int) $line->product_id,
                            'sales_order_line_id' => (int) $line->id,
                            'message' => 'No active BOM found for this product; demand explosion and standard work-order creation were skipped.',
                        ];

                        continue;
                    }
                    $activeBom = $this->boms->ensureFreshForPlanning($activeBom);
                    $this->boms->assertComponentIntegrity($activeBom);
                    $bomAvailableByLine[$line->id] = true;

                    $unitMaterialCost = (string) ($activeBom->material_cost ?? '0.00');
                    $unitLaborCost = (string) ($activeBom->labor_cost ?? '0.00');
                    $unitMachineCost = (string) ($activeBom->machine_cost ?? '0.00');
                    $unitOverheadCost = (string) ($activeBom->overhead_cost ?? '0.00');
                    $unitTotalCost = (string) ($activeBom->total_cost ?? $line->product?->standard_cost ?? '0.00');
                    $costSummary['material_cost'] = Money::add(
                        $costSummary['material_cost'],
                        bcmul((string) $remainingQuantity, $unitMaterialCost, 8),
                    );
                    $costSummary['labor_cost'] = Money::add(
                        $costSummary['labor_cost'],
                        bcmul((string) $remainingQuantity, $unitLaborCost, 8),
                    );
                    $costSummary['machine_cost'] = Money::add(
                        $costSummary['machine_cost'],
                        bcmul((string) $remainingQuantity, $unitMachineCost, 8),
                    );
                    $costSummary['overhead_cost'] = Money::add(
                        $costSummary['overhead_cost'],
                        bcmul((string) $remainingQuantity, $unitOverheadCost, 8),
                    );
                    $costSummary['planned_production_cost'] = Money::add(
                        $costSummary['planned_production_cost'],
                        bcmul((string) $remainingQuantity, $unitTotalCost, 8),
                    );
                    $costSummary['products'][] = [
                        'product_id' => (int) $line->product_id,
                        'part_number' => (string) $line->product?->part_number,
                        'name' => (string) $line->product?->name,
                        'quantity' => round($remainingQuantity, 3),
                        'unit_cost' => $unitTotalCost,
                        'extended_cost' => Money::round2(bcmul((string) $remainingQuantity, $unitTotalCost, 8)),
                    ];
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
                    'mrp_plan_no' => $this->sequences->generate('mrp_plan'),
                    'sales_order_id' => $so->id,
                    'version' => $previous ? $previous->version + 1 : 1,
                    'status' => MrpPlanStatus::Active->value,
                    'generated_by' => $recordedBy,
                    'mrp_run_id' => $run?->id,
                    'total_lines' => count($lines),
                    'shortages_found' => 0,
                    'auto_pr_count' => 0,
                    'draft_wo_count' => 0,
                    'diagnostics' => [],
                    'cost_summary' => [],
                    'generation_context' => $generationContext,
                    'generated_at' => Carbon::now(),
                ]);

                // Calculate net requirements per material.
                // Note: $diagnostics may already contain BOM-missing warnings from above.
                $shortages = []; // [item_id => ['net' => float, 'order_by' => Carbon, 'priority' => string, 'unit' => string]]

                foreach ($grossPerItem as $itemId => $gross) {
                    $item = Item::find($itemId);
                    if (! $item) {
                        continue;
                    }

                    // Sprint 6 audit §1.4: lock the per-item stock_levels rows so
                    // concurrent SO confirmations cannot race the same on-hand /
                    // reserved quantities. Order by id for deterministic locking.
                    // F-02 — quarantine/scrap-zone stock is held or scrapped and
                    // must not satisfy gross requirements.
                    $supply = $this->supplyForItem($itemId, $planningSupply);

                    // Count each SO's procurement and active WO commitments before
                    // allocating shared stock; another SO cannot spend this PO.
                    $openPurchaseRequests = $this->openPurchaseRequestQuantity((int) $so->id, (int) $itemId);
                    $committedPo = (float) ($supply['linked_pos'][$so->id] ?? 0);
                    $issuedToWorkOrders = $this->committedWorkOrderMaterial((int) $so->id, $itemId);
                    $grossAfterOpenRequests = max(0.0, $gross - $openPurchaseRequests - $committedPo - $issuedToWorkOrders);
                    $availableBeforeAllocation = max(0.0, (float) $supply['available']);
                    $consumedFromSharedSupply = min($grossAfterOpenRequests, $availableBeforeAllocation);
                    $net = max(0.0, $grossAfterOpenRequests - $availableBeforeAllocation);

                    $planningSupply[$itemId]['available'] = $availableBeforeAllocation - $consumedFromSharedSupply;

                    $awaitingQc = (float) $supply['awaiting_qc'] + (float) ($supply['linked_qc'][$so->id] ?? 0);
                    $pendingPo = (float) $supply['pending_purchase_orders']
                        + max(0.0, $committedPo - (float) ($supply['linked_transit'][$so->id] ?? 0) - (float) ($supply['linked_qc'][$so->id] ?? 0));

                    $entry = [
                        'item_id' => $itemId,
                        'item_code' => $item->code,
                        'gross' => round($gross, 3),
                        'on_hand' => round((float) $supply['on_hand'], 3),
                        'reserved' => round((float) $supply['reserved'], 3),
                        'in_transit' => round((float) $supply['in_transit'] + (float) ($supply['linked_transit'][$so->id] ?? 0), 3),
                        'open_purchase_requests' => round($openPurchaseRequests, 3),
                        'linked_purchase_orders' => round($committedPo, 3),
                        'work_order_material' => round($issuedToWorkOrders, 3),
                        'awaiting_qc' => round($awaitingQc, 3),
                        'pending_purchase_orders' => round($pendingPo, 3),
                        'open_unplanned_requests' => round((float) $supply['open_requests'], 3),
                        'safety_stock' => round((float) $supply['safety_stock'], 3),
                        'standard_unit_cost' => (string) $item->standard_cost,
                        'gross_cost' => Money::round2(bcmul((string) $gross, (string) $item->standard_cost, 8)),
                        'net_cost' => Money::round2(bcmul((string) $net, (string) $item->standard_cost, 8)),
                        'net' => round($net, 3),
                        'action' => $awaitingQc > 0 ? 'awaiting_qc' : ($pendingPo > 0 ? 'awaiting_po_approval' : 'sufficient'),
                    ];

                    if ($net > 0) {
                        $leadTime = $this->effectiveLeadTime($itemId, $item);
                        $earliest = $earliestNeedPerItem[$itemId] ?? null;
                        if (! $earliest) {
                            throw new BusinessRuleException("No required delivery date is available for item {$item->code}.");
                        }
                        $safetyBuffer = $this->safetyBufferDays();
                        $orderBy = $earliest->copy()->subDays($leadTime + $safetyBuffer);
                        $needBy = $orderBy->copy()->addDays($leadTime);

                        $priority = $orderBy->lte(Carbon::today()) ? 'urgent' : 'normal';
                        $shortages[$itemId] = [
                            'net' => $net,
                            'order_by' => $orderBy,
                            'need_by' => $needBy,
                            'priority' => $priority,
                            'unit' => $item->unit_of_measure,
                            'estimated_unit_price' => (string) $item->standard_cost,
                            'name' => $item->name,
                            'minimum_order_quantity' => (string) $item->minimum_order_quantity,
                        ];

                        $entry['action'] = 'pr_created';
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
                    // MRP-03 — lock the eligible prior draft auto-PRs for the rest
                    // of this transaction. A concurrent purchasing submit selects
                    // the same rows; without the lock it can commit between this
                    // read and the draft/cancel/reuse writes below, after which the
                    // rerun deletes the line items of a PR that has already crossed
                    // the purchasing handoff. This block runs inside the
                    // DB::transaction() opened by runForSalesOrder(), so the lock
                    // is held until the MRP run commits.
                    $priorDraftAutoPrs = PurchaseRequest::query()
                        ->where('is_auto_generated', true)
                        ->where('status', PurchaseRequestStatus::Draft->value)
                        ->whereHas('mrpPlan', fn ($q) => $q
                            ->where('sales_order_id', $so->id)
                            ->where('id', '!=', $plan->id))
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->get();

                    // Reuse the latest eligible draft auto-PR, refreshed to current
                    // requirements; cancel any older surplus drafts. Defense in
                    // depth under the lock: a candidate that is no longer Draft at
                    // write time (a writer that bypassed the row lock, or an
                    // in-process submit) is skipped rather than clobbered, and a
                    // fresh consolidated PR is created when no candidate survives.
                    $pr = null;
                    foreach ($priorDraftAutoPrs as $candidate) {
                        $candidate->refresh();
                        if ($candidate->status !== PurchaseRequestStatus::Draft) {
                            continue;
                        }

                        if ($pr === null) {
                            $pr = $candidate;

                            continue;
                        }

                        $candidate->forceFill(['status' => PurchaseRequestStatus::Cancelled->value])->save();
                    }

                    $earliestNeedBy = collect($shortages)
                        ->pluck('need_by')
                        ->min();

                    if ($pr === null) {
                        $pr = PurchaseRequest::create([
                            'pr_number' => $this->sequences->generate('pr'),
                            'requested_by' => $recordedBy,
                            'department_id' => $purchaseRequestDepartmentId,
                            'mrp_plan_id' => $plan->id,
                            'date' => Carbon::today(),
                            'required_delivery_date' => $earliestNeedBy?->toDateString(),
                            'reason' => "Auto-generated from MRP plan {$plan->mrp_plan_no} for SO {$so->so_number}.",
                            'priority' => collect($shortages)->contains(fn ($s) => $s['priority'] === 'urgent') ? 'urgent' : 'normal',
                            'is_auto_generated' => true,
                        ]);
                    } else {
                        // status non-fillable; service-only. Repoint the reused PR
                        // to the current plan and refresh its lines.
                        $pr->forceFill([
                            'status' => PurchaseRequestStatus::Draft->value,
                            'mrp_plan_id' => $plan->id,
                            'date' => Carbon::today(),
                            'required_delivery_date' => $earliestNeedBy?->toDateString(),
                            'reason' => "Auto-generated from MRP plan {$plan->mrp_plan_no} for SO {$so->so_number}.",
                            'priority' => collect($shortages)->contains(fn ($s) => $s['priority'] === 'urgent') ? 'urgent' : 'normal',
                            'department_id' => $pr->department_id ?? $purchaseRequestDepartmentId,
                        ])->save();
                        $pr->items()->delete();
                    }

                    foreach ($shortages as $itemId => $s) {
                        PurchaseRequestItem::create([
                            'purchase_request_id' => $pr->id,
                            'item_id' => $itemId,
                            'description' => $s['name'],
                            'quantity' => $this->purchaseQuantity((float) $s['net'], (string) $s['minimum_order_quantity']),
                            'unit' => $s['unit'],
                            'estimated_unit_price' => Money::round2((string) $s['estimated_unit_price']),
                            'purpose' => "MRP demand for SO {$so->so_number}",
                        ]);
                    }
                    $autoPrCount = 1; // one consolidated PR per run
                } else {
                    // No shortages on this run — retire leftover draft auto-PRs so
                    // the purchasing queue never shows demand that no longer exists.
                    $retiredPrs = PurchaseRequest::query()
                        ->where('is_auto_generated', true)
                        ->where('status', PurchaseRequestStatus::Draft->value)
                        ->whereHas('mrpPlan', fn ($q) => $q
                            ->where('sales_order_id', $so->id)
                            ->where('id', '!=', $plan->id))
                        ->lockForUpdate()
                        ->get();

                    foreach ($retiredPrs as $retiredPr) {
                        $retiredPr->forceFill([
                            'status' => PurchaseRequestStatus::Cancelled->value,
                        ])->save();
                    }
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
                        $this->cancelStalePlannedRootWorkOrders($line->id, $plan->id);
                        $this->cancelStalePlannedChildWorkOrders($line->id, $plan->id);
                        continue;
                    }

                    if (($bomAvailableByLine[$line->id] ?? false) !== true) {
                        $this->cancelStalePlannedRootWorkOrders($line->id, $plan->id);
                        $this->cancelStalePlannedChildWorkOrders($line->id, $plan->id);

                        continue;
                    }

                    $plannedStart = $line->delivery_date->copy()->subDays(2)->toDateTimeString();
                    $plannedEnd = $line->delivery_date->copy()->subDay()->toDateTimeString();
                    // Receiver-earlier, argument-later, and day-granular on both
                    // sides. Carbon 3 made diffIn* SIGNED, and this read
                    // `$line->delivery_date->diffInDays(now())` — the other way
                    // round — so a future delivery yielded a NEGATIVE count and
                    // `-25 <= 5` was always true: EVERY MRP work order came out
                    // urgent, and the horizon setting decided nothing. startOfDay on
                    // the left keeps this a whole-day comparison rather than one that
                    // shifts with the time of day the run happens to fire.
                    //
                    // Signed on purpose (`false`), and NOT an absolute magnitude: a
                    // delivery date already past yields a negative count, which is
                    // exactly what the `<= $urgentDeliveryDays` test wants — an
                    // overdue line is more urgent than one due today, not less. An
                    // absolute value would read a line 30 days overdue as 30 days of
                    // slack and downgrade it to normal priority.
                    $daysUntilDelivery = Carbon::now()->startOfDay()->diffInDays($line->delivery_date, false);
                    $priority = $daysUntilDelivery <= $urgentDeliveryDays
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
                        ->get(['quantity_target', 'quantity_good']);
                    // A WO's target is good pieces; rejects do not consume it.
                    $openProduction = $progressedWos->sum(function (WorkOrder $workOrder): float {
                        return max(0.0, (float) $workOrder->quantity_target - (float) $workOrder->quantity_good);
                    });
                    // NCR owns its own planned replacement/rework WO. It has no
                    // mrp_plan_id to repoint, but it still covers this SO line.
                    $openProduction += WorkOrder::query()
                        ->where('sales_order_item_id', $line->id)
                        ->whereNull('parent_wo_id')
                        ->whereNotNull('parent_ncr_id')
                        ->where('status', WorkOrderStatus::Planned->value)
                        ->get(['quantity_target', 'quantity_produced'])
                        ->sum(fn (WorkOrder $workOrder): float => max(0.0, (float) $workOrder->quantity_target - (float) $workOrder->quantity_produced));

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
                            'mrp_plan_id' => $plan->id,
                            'quantity_target' => $workOrderTarget,
                            'planned_start' => $plannedStart,
                            'planned_end' => $plannedEnd,
                            'priority' => $priority,
                        ])->save();
                        foreach ($priorPlanned as $surplus) {
                            $surplus->forceFill(['status' => WorkOrderStatus::Cancelled->value])->save();
                        }
                        $rootWorkOrder = $reuse->fresh();
                        $draftWoCount++;
                    } else {
                        $rootWorkOrder = $this->workOrders->createDraft([
                            'product_id' => $line->product_id,
                            'sales_order_id' => $so->id,
                            'sales_order_item_id' => $line->id,
                            'mrp_plan_id' => $plan->id,
                            'quantity_target' => $workOrderTarget,
                            'planned_start' => $plannedStart,
                            'planned_end' => $plannedEnd,
                            'priority' => $priority,
                            'created_by' => $recordedBy,
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
                    'auto_pr_count' => $autoPrCount,
                    'draft_wo_count' => $draftWoCount,
                    'diagnostics' => $diagnostics,
                    'cost_summary' => $costSummary,
                ]);

                // Link the SO to this plan.
                $so->update(['mrp_plan_id' => $plan->id]);

                $finalPlan = $plan->fresh();
                app(OutboxService::class)->recordForChain(
                    new MrpPlanGenerated($finalPlan, $run?->id, $actorId, $triggerReason ?? 'mrp_planning'),
                    $so,
                    'o2c',
                    'sales_order',
                    'mrp_plan_generated',
                );

                // Refresh while the run-row lock is still held. The reaper
                // must see this heartbeat after the plan transaction commits.
                if ($run !== null) {
                    $this->touchRunHeartbeatOrFail($run);
                }

                return $this->show($finalPlan);
            });
        } catch (\Throwable $e) {
            if ($sharedSupply !== null) {
                $sharedSupply = $sharedSupplyBeforeRun;
            }

            throw $e;
        }
    }

    public function rerun(MrpPlan $plan, ?int $userId = null): MrpPlan
    {
        $salesOrder = $plan->salesOrder()->firstOrFail();
        $run = $this->runForActiveSalesOrders(
            MrpRunTrigger::Manual,
            $userId,
            [$salesOrder->id],
            'manual_rerun',
        );

        if ($run->status !== MrpRunStatus::Completed || (int) $run->plans_generated < 1) {
            throw new BusinessRuleException(
                'MRP rerun did not generate a new plan. Correct the reported planning error and try again.'
            );
        }

        $newPlan = MrpPlan::query()
            ->where('sales_order_id', $salesOrder->id)
            ->where('status', MrpPlanStatus::Active->value)
            ->orderByDesc('version')
            ->first();

        if ($newPlan === null) {
            throw new BusinessRuleException('MRP rerun completed without an active plan. Run MRP again after reviewing the run history.');
        }

        return $this->show($newPlan);
    }

    /**
     * Task A1 — Re-runs MRP across every active sales order. Creates one
     * MrpRun history row, increments counters per SO, and rolls back via
     * the run-row's status on catastrophic failure.
     *
     * Active = any non-terminal SO with remaining (undelivered) demand, per
     * SalesOrder::scopePlanningRelevant(). Delivered coverage, not status, is
     * what ends planning ownership: an order invoiced after a partial
     * shipment still has goods to make.
     *
     * Idempotency: each runForSalesOrder() supersedes the prior plan and
     * reconciles its draft auto-PR/planned WO children. Progressed purchasing
     * and production records remain authoritative and are included in the
     * next net-requirement calculation.
     */
    public function runForAllActiveSalesOrders(MrpRunTrigger $trigger, ?int $userId = null): MrpRun
    {
        return $this->runForActiveSalesOrders($trigger, $userId, null, null);
    }

    /**
     * Run MRP for all active SOs or an affected subset. Passing an empty list
     * deliberately evaluates no orders; null is the plant-wide fallback.
     *
     * @param  list<int>|null  $salesOrderIds
     */
    public function runForActiveSalesOrders(
        MrpRunTrigger $trigger,
        ?int $userId = null,
        ?array $salesOrderIds = null,
        ?string $reason = null,
    ): MrpRun {
        // Queue overlap middleware only covers queued automatic jobs. Manual
        // recovery and the daily scheduler enter through this service too, so
        // one shared-cache mutex protects the stock-allocation pass across all
        // run sources and application workers.
        // ponytail: 20-minute lease caps a batch; renew it or use a DB advisory
        // lock if measured plant-wide runs grow beyond that window.
        $lock = Cache::lock('mrp:plant-run', 1200);
        if (! $lock->get()) {
            throw new BusinessRuleException('Another MRP run is already in progress. Retry after it finishes.');
        }

        try {
            return $this->runForActiveSalesOrdersLocked($trigger, $userId, $salesOrderIds, $reason);
        } finally {
            $lock->release();
        }
    }

    /** @param list<int>|null $salesOrderIds */
    private function runForActiveSalesOrdersLocked(
        MrpRunTrigger $trigger,
        ?int $userId,
        ?array $salesOrderIds,
        ?string $reason,
    ): MrpRun {
        $start = microtime(true);

        $run = MrpRun::create([
            'run_at' => now(),
            'started_at' => now(),
            'heartbeat_at' => now(),
            'triggered_by' => $trigger->value,
            'triggered_by_user_id' => $userId,
            'status' => MrpRunStatus::Running->value,
        ]);

        try {
            $salesOrderQuery = SalesOrder::query()->planningRelevant();
            if ($salesOrderIds !== null) {
                $salesOrderQuery->whereIn('id', array_values(array_unique(array_map('intval', $salesOrderIds))));
            }
            $sos = $salesOrderQuery->get();

            $shortagesTotal = 0;
            $prsCreated = 0;
            $prsUpdated = 0;
            $plansGenerated = 0;
            $failedSalesOrders = 0;
            $incompleteSalesOrders = 0;
            $perSo = [];
            $sharedSupply = [];

            foreach ($sos as $so) {
                $this->touchRunHeartbeatOrFail($run);

                try {
                    $beforeAutoPrs = PurchaseRequest::where('is_auto_generated', true)
                        ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
                        ->where('status', 'draft')
                        ->count();

                    $plan = $this->runForSalesOrder($so, $sharedSupply, $run, $userId, $reason);
                    $plansGenerated++;
                    $shortagesTotal += (int) $plan->shortages_found;
                    $warnings = collect((array) $plan->diagnostics)
                        ->filter(static fn ($row): bool => is_array($row) && ($row['type'] ?? null) === 'missing_bom')
                        ->values()
                        ->all();
                    if ($warnings !== []) {
                        $incompleteSalesOrders++;
                    }

                    $afterAutoPrs = PurchaseRequest::where('is_auto_generated', true)
                        ->whereHas('mrpPlan', fn ($q) => $q->where('sales_order_id', $so->id))
                        ->where('status', 'draft')
                        ->count();

                    $delta = $afterAutoPrs - $beforeAutoPrs;
                    if ($delta > 0) {
                        $prsCreated += $delta;
                    } elseif ($afterAutoPrs > 0 && $beforeAutoPrs > 0) {
                        $prsUpdated += 1;
                    }

                    $perSo[] = [
                        'so_id' => $so->id,
                        'so_number' => $so->so_number,
                        'shortages_found' => (int) $plan->shortages_found,
                        'plan_no' => $plan->mrp_plan_no,
                        'warnings' => $warnings,
                    ];
                } catch (\Throwable $inner) {
                    $failure = MrpErrorPolicy::describe($inner);
                    $failedSalesOrders++;
                    Log::warning('MRP run: SO failed', [
                        'so_id' => $so->id,
                        'so_number' => $so->so_number,
                        'error' => $inner->getMessage(),
                    ]);
                    $perSo[] = [
                        'so_id' => $so->id,
                        'so_number' => $so->so_number,
                        'error' => $failure['message'],
                        'error_code' => $failure['code'],
                        'recovery_action' => $failure['recovery_action'],
                    ];
                }
            }

            $this->touchRunHeartbeatOrFail($run);
            $run->update([
                'sales_orders_evaluated' => $sos->count(),
                'shortages_found' => $shortagesTotal,
                'prs_created' => $prsCreated,
                'prs_updated' => $prsUpdated,
                'plans_generated' => $plansGenerated,
                'failed_sales_orders' => $failedSalesOrders,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'status' => $failedSalesOrders > 0 || $incompleteSalesOrders > 0
                    ? MrpRunStatus::Partial->value
                    : MrpRunStatus::Completed->value,
                'summary' => [
                    'per_sales_order' => $perSo,
                    'failed_sales_orders' => $failedSalesOrders,
                    'incomplete_sales_orders' => $incompleteSalesOrders,
                ],
            ]);
        } catch (\Throwable $e) {
            $failure = MrpErrorPolicy::describe($e);
            $updated = MrpRun::query()
                ->whereKey($run->id)
                ->where('status', MrpRunStatus::Running->value)
                ->update([
                    'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                    'status' => MrpRunStatus::Failed->value,
                    'error_message' => $failure['message'],
                    'error_code' => $failure['code'],
                    'recovery_action' => $failure['recovery_action'],
                ]);

            if ($updated === 0) {
                $run->refresh();
            }
            Log::error('MRP run: catastrophic failure', ['error' => $e->getMessage()]);
        }

        return $run->fresh();
    }

    private function touchRunHeartbeatOrFail(MrpRun $run): void
    {
        $now = now();
        $updated = MrpRun::query()
            ->whereKey($run->id)
            ->where('status', MrpRunStatus::Running->value)
            ->update([
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            throw new BusinessRuleException(
                'MRP run was reaped before planning completed. Review the run history and retry the affected sales orders.'
            );
        }

        $run->setAttribute('heartbeat_at', $now);
    }

    public function list(array $filters): LengthAwarePaginator
    {
        $q = MrpPlan::query()
            ->with(['salesOrder:id,so_number,customer_id', 'salesOrder.customer:id,name', 'generator:id,name,role_id']);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['sales_order_id'])) {
            $sid = HashIdFilter::decode($filters['sales_order_id'], SalesOrder::class);
            if ($sid) {
                $q->where('sales_order_id', $sid);
            }
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
            'priorProgressedWorkOrders' => fn ($query) => $query->where('mrp_plans.version', '<', $plan->version),
            'priorProgressedWorkOrders.parent:id,wo_number',
            'purchaseRequests:id,pr_number,priority,status,is_auto_generated,date,mrp_plan_id',
            'purchaseRequests.purchaseOrders:id,po_number,status,purchase_request_id',
            'priorProgressedPurchaseRequests' => fn ($query) => $query->where('mrp_plans.version', '<', $plan->version),
            'priorProgressedPurchaseRequests.purchaseOrders:id,po_number,status,purchase_request_id',
        ]);
    }

    /** Cancel only automatically planned roots made obsolete by a BOM gap. */
    private function cancelStalePlannedRootWorkOrders(int $salesOrderItemId, int $planId): void
    {
        $workOrders = WorkOrder::query()
            ->where('sales_order_item_id', $salesOrderItemId)
            ->whereNull('parent_wo_id')
            ->whereNotNull('mrp_plan_id')
            ->where('mrp_plan_id', '!=', $planId)
            ->where('status', WorkOrderStatus::Planned->value)
            ->lockForUpdate()
            ->get();

        foreach ($workOrders as $workOrder) {
            $workOrder->forceFill(['status' => WorkOrderStatus::Cancelled->value])->save();
        }
    }

    /**
     * Create or reconcile the manufactured subassembly WOs beneath one
     * parent. Planned children from the superseded plan are reused by product
     * and parent, so an MRP rerun preserves the production tree instead of
     * appending duplicate records.
     *
     * @param  list<array{product_id:int,item_id:int,item_code:string,quantity:string,children:list<array>}>  $nodes
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
                    'mrp_plan_id' => $plan->id,
                    'quantity_target' => $quantity,
                    'planned_start' => $childStart,
                    'planned_end' => $childEnd,
                    'priority' => $priority,
                ])->save();
                $child = $child->fresh();

                foreach ($priorChildren as $surplus) {
                    $surplus->forceFill(['status' => WorkOrderStatus::Cancelled->value])->save();
                }
            } else {
                $child = $this->workOrders->createDraft([
                    'product_id' => (int) $node['product_id'],
                    'sales_order_id' => $so->id,
                    'sales_order_item_id' => $line->id,
                    'mrp_plan_id' => $plan->id,
                    'parent_wo_id' => $parent->id,
                    'quantity_target' => $quantity,
                    'planned_start' => $childStart,
                    'planned_end' => $childEnd,
                    'priority' => $priority,
                    'created_by' => $plan->generated_by,
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
        $workOrders = WorkOrder::query()
            ->where('sales_order_item_id', $salesOrderItemId)
            ->whereNotNull('parent_wo_id')
            ->whereNotNull('mrp_plan_id')
            ->where('mrp_plan_id', '!=', $planId)
            ->where('status', WorkOrderStatus::Planned->value)
            ->lockForUpdate()
            ->get();

        foreach ($workOrders as $workOrder) {
            $workOrder->forceFill(['status' => WorkOrderStatus::Cancelled->value])->save();
        }
    }

    /** Good output still held for this SO is production already performed, not fresh raw demand. */
    private function remainingToProduce(SalesOrderItem $line): float
    {
        $good = 0;
        $roots = WorkOrder::query()
            ->where('sales_order_item_id', $line->id)
            ->whereNull('parent_wo_id')
            ->get(['id', 'quantity_good']);
        foreach ($roots as $wo) {
            $good += (int) $wo->quantity_good;
        }

        // Failed outgoing QC cannot fulfil the order. A later passed review
        // restores credit; pending QC remains a held commitment, never free FG.
        if ($roots->isNotEmpty()) {
            $outputs = WorkOrderOutput::query()
                ->whereIn('work_order_id', $roots->pluck('id'))
                ->get(['id', 'good_count']);
            if ($outputs->isNotEmpty()) {
                $latestResults = DB::table('inspections')
                    ->whereIn('work_order_output_id', $outputs->pluck('id'))
                    ->where('stage', 'outgoing')
                    ->orderByDesc('id')
                    ->get(['work_order_output_id', 'status'])
                    ->unique('work_order_output_id')
                    ->keyBy('work_order_output_id');
                foreach ($outputs as $output) {
                    if (($latestResults->get($output->id)?->status ?? null) === InspectionStatus::Failed->value) {
                        $good -= (int) $output->good_count;
                    }
                }
            }
        }

        $delivered = (float) $line->quantity_delivered;

        return max(0.0, (float) $line->quantity - max($delivered, min((float) $line->quantity, (float) $good)));
    }

    /** Stock already reserved or issued to an unfinished WO belongs to that WO. */
    private function committedWorkOrderMaterial(int $salesOrderId, int $itemId): float
    {
        $workOrders = WorkOrder::query()
            ->where('sales_order_id', $salesOrderId)
            ->whereIn('status', [
                WorkOrderStatus::Confirmed->value,
                WorkOrderStatus::InProgress->value,
                WorkOrderStatus::Paused->value,
            ])
            ->with(['materials' => fn ($q) => $q->where('item_id', $itemId)])
            ->get(['id', 'quantity_target', 'quantity_produced', 'material_plan_source']);
        if ($workOrders->isEmpty()) {
            return 0.0;
        }

        $reserved = DB::table('material_reservations')
            ->whereIn('work_order_id', $workOrders->pluck('id'))
            ->where('item_id', $itemId)
            ->where('status', 'reserved')
            ->select(['work_order_id', 'quantity'])
            ->get()->groupBy('work_order_id');
        $manualIssues = DB::table('material_issue_slip_items as misi')
            ->join('material_issue_slips as mis', 'mis.id', '=', 'misi.material_issue_slip_id')
            ->whereIn('mis.work_order_id', $workOrders->pluck('id'))
            ->where('mis.status', 'issued')
            ->where('misi.item_id', $itemId)
            ->select(['mis.work_order_id', 'misi.quantity_issued', 'misi.stock_movement_id'])
            ->get();
        $autoSources = DB::table('stock_movements')
            ->where('movement_type', StockMovementType::MaterialIssue->value)
            ->where('reference_type', 'work_order')
            ->whereIn('reference_id', $workOrders->pluck('id'))
            ->where('item_id', $itemId)
            ->get(['id', 'reference_id']);
        $sourceIds = $autoSources->pluck('id')
            ->merge($manualIssues->pluck('stock_movement_id')->filter())
            ->unique()
            ->values();
        $returnedBySource = $sourceIds->isEmpty()
            ? collect()
            : DB::table('stock_movements')
                ->where('movement_type', StockMovementType::MaterialReturn->value)
                ->where('reference_type', 'stock_movement')
                ->whereIn('reference_id', $sourceIds)
                ->selectRaw('reference_id, COALESCE(SUM(quantity), 0) AS quantity')
                ->groupBy('reference_id')
                ->get()
                ->keyBy('reference_id');

        $netManualByWorkOrder = [];
        foreach ($manualIssues as $issue) {
            $returned = $issue->stock_movement_id !== null
                ? (string) ($returnedBySource->get($issue->stock_movement_id)?->quantity ?? '0.000')
                : '0.000';
            $netLine = bcsub((string) $issue->quantity_issued, $returned, 3);
            if (bccomp($netLine, '0', 3) < 0) {
                $netLine = '0.000';
            }
            $woId = (int) $issue->work_order_id;
            $netManualByWorkOrder[$woId] = bcadd($netManualByWorkOrder[$woId] ?? '0.000', $netLine, 3);
        }

        $autoReturnedByWorkOrder = [];
        foreach ($autoSources as $source) {
            $returned = (string) ($returnedBySource->get($source->id)?->quantity ?? '0.000');
            $woId = (int) $source->reference_id;
            $autoReturnedByWorkOrder[$woId] = bcadd($autoReturnedByWorkOrder[$woId] ?? '0.000', $returned, 3);
        }

        $committed = 0.0;
        foreach ($workOrders as $wo) {
            $materials = $wo->materials;
            $planned = '0.000';
            $autoIssued = '0.000';
            foreach ($materials as $material) {
                $planned = bcadd($planned, (string) $material->bom_quantity, 3);
                $autoIssued = bcadd($autoIssued, (string) $material->actual_quantity_issued, 3);
            }
            // Persisted counters remain auto-only. Subtract actual returns
            // linked to auto issue movements from those counters, then add
            // net manual slips once as a separate commitment term.
            $autoReturned = (string) ($autoReturnedByWorkOrder[$wo->id] ?? '0.000');
            $netAutoIssued = bcsub($autoIssued, $autoReturned, 3);
            if (bccomp($netAutoIssued, '0', 3) < 0) {
                $netAutoIssued = '0.000';
            }
            $issued = bcadd($netAutoIssued, (string) ($netManualByWorkOrder[$wo->id] ?? '0.000'), 3);
            $grossOutput = (string) $wo->quantity_produced;
            $consumed = '0.000';
            if (bccomp($grossOutput, '0', 3) > 0) {
                $consumed = $wo->material_plan_source === 'bom'
                    ? WorkOrderMaterialUsageService::plannedConsumptionAtOutput(
                        $planned,
                        $grossOutput,
                        (string) max(1, (int) $wo->quantity_target),
                    )
                    : $planned;
            }
            $unconsumed = bcsub($issued, $consumed, 3);
            if (bccomp($unconsumed, '0', 3) < 0) {
                $unconsumed = '0.000';
            }
            $reservedQuantity = (string) ($reserved->get($wo->id)?->sum('quantity') ?? '0.000');
            $committed += (float) bcadd($unconsumed, $reservedQuantity, 3);
        }

        return $committed;
    }

    /**
     * Load and cache usable supply for one inventory item. The cache is shared
     * across SOs during a multi-order MRP run so one order cannot consume the
     * same stock that an earlier order already allocated.
     *
     * MRP-01 — safety stock absorbs demand variability; netting may only
     * consume stock above it, so it floors the available quantity.
     *
     * @param  array<int, array{on_hand:float,reserved:float,in_transit:float,open_requests:float,safety_stock:float,available:float}>  $planningSupply
     * @return array{on_hand:float,reserved:float,in_transit:float,open_requests:float,safety_stock:float,available:float}
     */
    private function supplyForItem(int $itemId, array &$planningSupply): array
    {
        if (array_key_exists($itemId, $planningSupply)) {
            return $planningSupply[$itemId];
        }

        $levels = StockLevel::where('item_id', $itemId)
            ->whereHas('location.zone', function ($q) {
                $q->whereNotIn('zone_type', [
                    WarehouseZoneType::Quarantine->value,
                    WarehouseZoneType::Scrap->value,
                ]);
            })
            ->orderBy('location_id')
            ->lockForUpdate()
            ->get();
        $onHand = (float) $levels->sum('quantity');
        $reserved = (float) $levels->sum('reserved_quantity');
        $inTransit = $this->inTransit($itemId);
        $item = Item::query()->findOrFail($itemId);
        [$linkedPos, $linkedTransit, $unplannedHeld, $linkedTransitBySo, $unplannedPendingPo, $linkedQcBySo] = $this->linkedPurchaseOrderSupply($item);
        $openRequests = $this->openUnplannedRequestQuantity($itemId);
        $safetyStock = (float) $item->safety_stock;

        return $planningSupply[$itemId] = [
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'in_transit' => max(0.0, $inTransit - $linkedTransit),
            'open_requests' => $openRequests,
            'linked_pos' => $linkedPos,
            'linked_transit' => $linkedTransitBySo,
            'linked_qc' => $linkedQcBySo,
            'awaiting_qc' => $unplannedHeld,
            'pending_purchase_orders' => $unplannedPendingPo,
            'safety_stock' => $safetyStock,
            'available' => max(0.0, $onHand - $reserved + $inTransit - $linkedTransit + $openRequests + $unplannedHeld + $unplannedPendingPo - $safetyStock),
        ];
    }

    /** Separate SO-linked purchasing commitments from the unallocated plant pool. */
    private function linkedPurchaseOrderSupply(Item $item): array
    {
        $openStatuses = array_map(
            static fn (PurchaseOrderStatus $status): string => $status->value,
            array_filter(PurchaseOrderStatus::open(), static fn (PurchaseOrderStatus $status): bool => $status !== PurchaseOrderStatus::SupplierDeclined),
        );
        $lines = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->leftJoin('purchase_requests as pr', 'pr.id', '=', 'po.purchase_request_id')
            ->leftJoin('mrp_plans as mp', 'mp.id', '=', 'pr.mrp_plan_id')
            ->where('poi.item_id', $item->id)
            ->whereNull('po.deleted_at')
            ->whereIn('po.status', array_merge($openStatuses, [
                PurchaseOrderStatus::Draft->value,
                PurchaseOrderStatus::PendingApproval->value,
            ]))
            ->get(['po.id as po_id', 'po.status', 'po.pending_change_response_id', 'mp.sales_order_id', 'poi.id as line_id', 'poi.quantity', 'poi.quantity_received', 'poi.unit']);

        $linked = [];
        $linkedTransit = 0.0;
        $linkedTransitBySo = [];
        $unplannedPendingPo = 0.0;
        foreach ($lines as $line) {
            $ordered = (float) $item->convertToBase((string) $line->quantity, trim((string) $line->unit) ?: null);
            $remaining = max(0.0, $ordered - (float) $line->quantity_received);
            $inTransit = in_array($line->status, $openStatuses, true)
                || ($line->status === PurchaseOrderStatus::PendingApproval->value && $line->pending_change_response_id !== null);
            if ($line->sales_order_id !== null) {
                $sid = (int) $line->sales_order_id;
                $linked[$sid] = ($linked[$sid] ?? 0.0) + $remaining;
                if ($inTransit) {
                    $linkedTransit += $remaining;
                    $linkedTransitBySo[$sid] = ($linkedTransitBySo[$sid] ?? 0.0) + $remaining;
                }
            } elseif (! $inTransit) {
                $unplannedPendingPo += $remaining;
            }
        }

        $held = DB::table('grn_items as gi')
            ->join('goods_receipt_notes as grn', 'grn.id', '=', 'gi.goods_receipt_note_id')
            ->join('purchase_orders as po', 'po.id', '=', 'grn.purchase_order_id')
            ->leftJoin('purchase_requests as pr', 'pr.id', '=', 'po.purchase_request_id')
            ->leftJoin('mrp_plans as mp', 'mp.id', '=', 'pr.mrp_plan_id')
            ->where('gi.item_id', $item->id)
            ->where(function ($q): void {
                $q->where('grn.status', GrnStatus::PendingQc->value)
                    ->orWhere(function ($partial): void {
                        $partial->where('grn.status', GrnStatus::PartialAccepted->value)
                            ->whereNull('grn.remainder_rejected_at');
                    });
            })
            ->whereNull('po.deleted_at')
            ->where('po.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->get(['mp.sales_order_id', 'gi.quantity_received', 'gi.quantity_accepted']);
        $unplannedHeld = 0.0;
        $linkedQcBySo = [];
        foreach ($held as $row) {
            $remainder = max(0.0, (float) $row->quantity_received - (float) $row->quantity_accepted);
            if ($row->sales_order_id !== null) {
                $sid = (int) $row->sales_order_id;
                $linked[$sid] = ($linked[$sid] ?? 0.0) + $remainder;
                $linkedQcBySo[$sid] = ($linkedQcBySo[$sid] ?? 0.0) + $remainder;
            } else {
                $unplannedHeld += $remainder;
            }
        }

        return [$linked, $linkedTransit, $unplannedHeld, $linkedTransitBySo, $unplannedPendingPo, $linkedQcBySo];
    }

    /**
     * Allocate available stock to a manufactured subassembly and return only
     * the quantity that still needs a child work order. Inherits the
     * safety-stock-floored availability from supplyForItem() (MRP-01).
     *
     * @param  array<int, array{on_hand:float,reserved:float,in_transit:float,safety_stock:float,available:float}>  $planningSupply
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
     * MRP-01 — orderable quantity for an auto-PR line: the net shortage
     * ceiled to three decimal places (purchase-request precision), then lifted
     * to the next multiple of the item's minimum order quantity when one is
     * configured, so purchasing never receives a below-MOQ line. BCMath keeps
     * the multiple exact; the bcdiv quotient truncates at scale 8, so any
     * surviving remainder bumps the multiple count up.
     */
    private function purchaseQuantity(float $net, string $minimumOrderQuantity): string
    {
        $net = number_format(max(0.0, $net), 8, '.', '');
        $thousandths = bcmul($net, '1000', 8);
        $quantityUnits = bcdiv($thousandths, '1', 0);
        if (bccomp($thousandths, $quantityUnits, 8) > 0) {
            $quantityUnits = bcadd($quantityUnits, '1', 0);
        }
        $quantity = bcdiv($quantityUnits, '1000', 3);

        if (bccomp($minimumOrderQuantity, '0', 3) === 1) {
            $quotient = bcdiv($quantity, $minimumOrderQuantity, 8);
            $multiples = bcdiv($quotient, '1', 0);
            if (bccomp($quotient, $multiples, 8) > 0) {
                $multiples = bcadd($multiples, '1', 0);
            }
            $quantity = bcmul($multiples, $minimumOrderQuantity, 3);
        }

        return bcadd($quantity, '0', 3);
    }

    /**
     * Quantity already covered by open purchase requests for one SO/item.
     * Counts only remaining unconverted quantities (PR line qty minus PO qty already placed).
     * Draft requests are intentionally excluded because this service owns and
     * reconciles those rows on the current run; pending/approved requests have
     * crossed the purchasing handoff and must not be duplicated.
     */
    private function openPurchaseRequestQuantity(int $salesOrderId, int $itemId): float
    {
        $baseQty = $this->openSupply->openRequestBaseQuantity($itemId, $salesOrderId, false);
        return (float) $baseQty;
    }

    /**
     * Quantity in unconverted unplanned PRs, including reorder drafts that
     * have not yet been submitted. Share this supply across SOs only once.
     */
    private function openUnplannedRequestQuantity(int $itemId): float
    {
        $baseQty = $this->openSupply->openRequestBaseQuantity($itemId, null, true, true);
        return (float) $baseQty;
    }

    /**
     * Sum of (purchase_order_items.quantity - quantity_received) across all
     * open POs for this item (in base UoM). Includes POs under change re-approval
     * (pending approval with pending_change_response_id set) as they are real supply
     * in transit. Delegates to OpenSupplyService which excludes declined/closed POs.
     */
    private function inTransit(int $itemId): float
    {
        return (float) $this->openSupply->inTransitBaseQuantity($itemId);
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
            ->qualified()
            ->orderByDesc('is_preferred')
            ->orderBy('lead_time_days')
            ->first();
        $supplierLT = (int) ($approved?->lead_time_days ?? 0);
        $itemLT = (int) $item->lead_time_days;
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
