<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ActivityFeedService;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\FinishedGoodInventoryService;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Enums\MoldStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\BomService;
use App\Modules\MRP\Services\MachineService;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\ProductionReceiptHandoffStatus;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WoOperationStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Events\WorkOrderQualityResolved;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Exceptions\IllegalLifecycleTransitionException;
use App\Modules\Production\Models\LateProductionReport;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Support\WorkOrderStateMachine;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Listeners\TriggerInProcessQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\InspectionSpecService;
use App\Modules\Quality\Services\QualityStockHoldService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 6 — Task 51. Work-order lifecycle service.
 *
 * Lifecycle:
 *   planned → confirmed → in_progress → (paused ↔ in_progress)* → completed → closed
 *                                       │
 *                                       ↓ cancel
 *                                   cancelled
 *
 * Reservation / issue integration with [`StockMovementService`](app/Modules/Inventory/Services/StockMovementService.php)
 * is hooked at confirm() / start() / cancel():
 *   - confirm() calls StockMovementService::reserve() for every BOM line and
 *     persists a MaterialReservation row per (item, location) chosen.
 *   - start()   releases each reservation, performs a MaterialIssue stock
 *     movement (atomically decrementing on-hand), updates the
 *     work_order_materials.actual_quantity_issued column, and flips the
 *     MaterialReservation row to status='issued'.
 *   - cancel()  releases reservations of any planned/confirmed WO so the
 *     stock is freed back to the pool, and flips them to status='released'.
 *
 * MachineService::transitionStatus and MoldService side-effects fire as
 * machine.status changes here. Sprint 6 Task 56 wires the breakdown listener.
 */
class WorkOrderService
{
    /** @return array<string, list<string>> */
    public static function allowedTransitions(): array
    {
        return WorkOrderStateMachine::TRANSITIONS;
    }

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly BomService $boms,
        private readonly StockMovementService $stock,
        private readonly SettingsService $settings,
        private readonly WoOperationService $operations,
        private readonly WorkOrderStateMachine $stateMachine,
        private readonly MachineService $machines,
        private readonly StockLocationSummaryService $locationSummary,
        private readonly WorkOrderMaterialUsageService $materialUsage,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = WorkOrder::query()
            ->with([
                'product:id,part_number,name',
                'salesOrder:id,so_number',
                'machine:id,machine_code,name',
                'mold:id,mold_code,name',
                'exceptionAuthorizer:id,name',
                'currentProductionSchedule',
                'activeReworks:id,rework_work_order_id,confirmed_rework_quantity,rework_started_at,rework_finished_at',
            ]);

        TrashedFilter::apply($q, $filters);

        if (($filters['signal'] ?? '') === 'overdue') {
            $q->overdue();
        }

        if (! empty($filters['production_purpose']) && in_array($filters['production_purpose'], ['stock', 'order', 'internal'], true)) {
            $q->where('production_purpose', $filters['production_purpose']);
        }
        if (! empty($filters['status'])) {
            // A single status string or an array (the floor PWA filters by
            // several at once: status[]=in_progress&status[]=confirmed&…).
            $statuses = is_array($filters['status']) ? array_values($filters['status']) : [$filters['status']];
            foreach ($statuses as $status) {
                if (! is_string($status) || WorkOrderStatus::tryFrom($status) === null) {
                    throw ValidationException::withMessages([
                        'status' => ['The selected status is invalid.'],
                    ]);
                }
            }
            $q->whereIn('status', $statuses);
        }
        if (! empty($filters['sales_order_id'])) {
            $sid = HashIdFilter::decode($filters['sales_order_id'], SalesOrder::class);
            if ($sid) {
                $q->where('sales_order_id', $sid);
            }
        }
        if (! empty($filters['machine_id'])) {
            $mid = HashIdFilter::decode($filters['machine_id'], Machine::class);
            if ($mid) {
                $q->where('machine_id', $mid);
            }
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('wo_number', SearchOperator::like(), SearchOperator::contains($term))
                    ->orWhereHas('product', fn ($p) => $p->where('part_number', SearchOperator::like(), SearchOperator::contains($term)));
            });
        }

        $sort = in_array($filters['sort'] ?? null, ['created_at', 'planned_end'], true)
            ? $filters['sort']
            : 'created_at';
        $direction = strtolower((string) ($filters['direction'] ?? 'desc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        if ($sort === 'planned_end') {
            $q->orderByRaw('planned_end IS NULL ASC')
                ->orderBy('planned_end', $direction)
                ->orderByDesc('created_at');
        } else {
            $q->orderBy('created_at', $direction);
        }

        return $q->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(WorkOrder $wo): WorkOrder
    {
        return $wo->load([
            'product', 'salesOrder', 'salesOrderItem',
            'machine', 'mold', 'parent:id,wo_number',
            'children:id,wo_number,product_id,parent_wo_id,status,quantity_target,quantity_good',
            'children.product:id,part_number,name',
            'creator:id,name,role_id',
            'exceptionAuthorizer:id,name',
            'materials.item:id,code,name,unit_of_measure',
            'outputs.recorder:id,name,role_id', 'outputs.defects.defectType', 'outputs.inspections',
            'outputs.productionReceiptMovement',
            'inspections:id,inspection_number,stage,status,entity_type,entity_id,completed_at',
            'downtimes', 'schedules.machine:id,machine_code,name', 'currentProductionSchedule',
            'activeReworks:id,rework_work_order_id,confirmed_rework_quantity,rework_started_at,rework_finished_at',
        ]);
    }

    /**
     * Create a draft (planned) work order.
     * Optionally explodes the BOM into work_order_materials when an active
     * BOM exists for the product (no-op otherwise).
     *
     * @param  array  $data  fields: product_id, sales_order_id?, sales_order_item_id?,
     *                       mrp_plan_id?, parent_wo_id?, parent_ncr_id?, machine_id?, mold_id?,
     *                       quantity_target, planned_start, planned_end, priority?, created_by
     */
    public function createDraft(array $data): WorkOrder
    {
        return DB::transaction(function () use ($data) {
            $product = Product::query()->lockForUpdate()->findOrFail((int) $data['product_id']);
            $activeBom = empty($data['rework_source_output_id']) ? $this->boms->activeForProduct((int) $product->id) : null;
            $payload = [
                'production_purpose' => $data['production_purpose'] ?? (! empty($data['sales_order_id']) ? 'order' : null),
                'stock_creation_request_key' => $data['stock_creation_request_key'] ?? null,
                'stock_creation_fingerprint' => $data['stock_creation_fingerprint'] ?? null,
                'preferred_production_date' => $data['preferred_production_date'] ?? null,
                'minimum_workers' => $data['minimum_workers'] ?? 1,
                'maximum_workers' => $data['maximum_workers'] ?? null,
                'wo_number' => $this->sequences->generate('work_order'),
                'product_id' => (int) $data['product_id'],
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'sales_order_item_id' => $data['sales_order_item_id'] ?? null,
                'mrp_plan_id' => $data['mrp_plan_id'] ?? null,
                'parent_wo_id' => $data['parent_wo_id'] ?? null,
                'parent_ncr_id' => $data['parent_ncr_id'] ?? null,
                'rework_source_output_id' => $data['rework_source_output_id'] ?? null,
                'machine_id' => $data['machine_id'] ?? null,
                'mold_id' => $data['mold_id'] ?? null,
                'quantity_target' => (int) $data['quantity_target'],
                'planned_start' => $data['planned_start'],
                'planned_end' => $data['planned_end'],
                'priority' => array_key_exists('priority', $data) && $data['priority'] !== null
                    ? (int) $data['priority']
                    : $this->settings->requiredInt('mrp.work_order.normal_priority', 0, 255),
                'status' => WorkOrderStatus::Planned->value,
                'work_order_class' => $data['work_order_class'] ?? 'standard',
                'exception_reason' => $data['exception_reason'] ?? null,
                'exception_authorized_by' => $data['exception_authorized_by'] ?? $data['created_by'],
                'material_plan_source' => ! empty($data['rework_source_output_id']) ? 'manual' : ($activeBom ? 'bom' : null),
                'bom_id' => $activeBom?->id, 'bom_version' => $activeBom?->version,
                'unit_standard_cost' => bccomp((string) $product->standard_cost, '0', 4) > 0 ? $product->standard_cost : null,
                'cost_source' => bccomp((string) $product->standard_cost, '0', 4) > 0 ? 'product_standard_cost' : null,
                'cost_snapshot_at' => bccomp((string) $product->standard_cost, '0', 4) > 0 ? now() : null,
                'created_by' => (int) $data['created_by'],
            ];
            $wo = WorkOrder::create($payload);

            // Materialize only the immediate BOM components. Manufactured
            // subassemblies stay on the parent WO and receive their own
            // child WO from MRP; recursively flattening here would make the
            // parent appear to consume raw materials it does not produce.
            // If no active BOM exists, the WO still saves — supervisor can
            // add materials manually. Once a BOM exists, however, direct
            // requirement errors must roll back instead of silently creating
            // an unmaterialized WO.
            if (empty($data['rework_source_output_id']) && $activeBom) {
                $rows = $this->boms->directRequirements((int) $data['product_id'], (float) $data['quantity_target']);
                foreach ($rows as $row) {
                    $wo->materials()->create([
                        'item_id' => (int) $row['item_id'],
                        'bom_quantity' => (string) $row['gross_quantity'],
                        'standard_unit_cost' => (string) $row['standard_unit_cost'],
                        'standard_cost' => (string) $row['standard_cost'],
                        'actual_quantity_issued' => '0',
                        'actual_cost' => '0',
                        'cost_variance' => Money::sub('0.00', (string) $row['standard_cost']),
                        'variance' => '0',
                    ]);
                }
            }

            // Snapshot the active routing after the WO and material plan exist.
            // The operation service is idempotent so retries cannot duplicate
            // the execution rows for the same work order.
            if (! $wo->rework_source_output_id) {
                $this->operations->generateFromRouting($wo);
            }

            return $this->show($wo->fresh());
        });
    }

    /** Planning evidence never replaces transactional reservation and execution guards. */
    public function proposalReadiness(WorkOrder $wo): array
    {
        $blockers = [];
        foreach ($this->materialUsage->groups($wo) as $group) {
            $needed = bcsub((string) $group['required_quantity'], (string) $group['coverage_quantity'], 3);
            if (bccomp($needed, '0', 3) <= 0) {
                continue;
            }
            $levels = StockLevel::where('item_id', $group['item_id'])->with('location.zone.warehouse')->get();
            $available = app(StockLocationSummaryService::class)->availability($levels)['usable_quantity'];
            if (bccomp($available, $needed, 3) < 0) {
                $blockers[] = ['code' => 'material', 'owner' => 'Warehouse',
                    'message' => ($group['item']['code'] ?? 'Material').': insufficient usable stock. Receive or release materials first.'];
            }
        }
        if (! $this->productionReadiness($wo)['ready']) {
            $blockers[] = ['code' => 'dependency', 'owner' => 'Production', 'message' => 'Complete required subassembly work orders first.'];
        }
        try {
            $specs = app(InspectionSpecService::class);
            $specs->assertInspectable($specs->forProduct($wo->product_id));
        } catch (BusinessRuleException $error) {
            $blockers[] = ['code' => 'quality', 'owner' => 'Quality', 'message' => $error->getMessage()];
        }

        return $blockers;
    }

    /**
     * confirmed: requires machine_id + mold_id assigned. Reserves materials
     * via StockMovementService::reserve() — one MaterialReservation row per
     * (item, location) selection. If any material has insufficient stock the
     * whole transaction rolls back and the WO stays planned.
     *
     * Sprint 6 audit §1.1: this previously only stamped the status change.
     * Now it actually reserves stock.
     */
    public function confirm(WorkOrder $wo, ?int $machineId = null, ?int $moldId = null, ?array $review = null): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Confirmed);
        $from = $wo->status?->value ?? 'planned';

        $result = DB::transaction(function () use ($wo, $machineId, $moldId, $review, &$from) {
            // Re-read the WO under a lock. Scheduler confirmation and the
            // direct WO endpoint can otherwise both pass the outer transition
            // check against the same stale planned row.
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::Confirmed);
            $lockedWo->assertFinishFirstRoutingCompatible();
            $from = $lockedWo->status?->value ?? 'planned';

            $targetMachineId = $machineId ?? $lockedWo->machine_id;
            $targetMoldId = $moldId ?? $lockedWo->mold_id;
            $machine = $targetMachineId
                ? Machine::query()->lockForUpdate()->find($targetMachineId)
                : null;
            $mold = $targetMoldId
                ? Mold::query()->lockForUpdate()->find($targetMoldId)
                : null;

            if (! $machine || ! $mold) {
                throw new BusinessRuleException('Confirming a work order requires both a machine and a mold.');
            }

            $this->assertAssignmentValid($lockedWo, $machine, $mold);
            $slot = ProductionSchedule::query()->where('work_order_id', $lockedWo->id)->whereNull('rework_ncr_id')->where('reserves_capacity', true)->latest('id')->first();
            if (! $slot) {
                $start = $lockedWo->planned_start?->copy();
                if (! $start) {
                    throw new BusinessRuleException('PPC must select an explicit production start and schedule before confirming.');
                }
                $end = $start->copy()->addMinutes(app(ProductionResourceService::class)->reservationMinutes($lockedWo, $mold));
                app(ProductionResourceService::class)->assertReservation($lockedWo, $machine, $mold, $start, $end);
                $slot = ProductionSchedule::create(['work_order_id' => $lockedWo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
                    'scheduled_start' => $start, 'scheduled_end' => $end, 'priority_order' => $lockedWo->priority ?? 0, 'status' => 'pending', 'reserves_capacity' => true]);
            } elseif ((int) $slot->machine_id !== (int) $machine->id || (int) $slot->mold_id !== (int) $mold->id) {
                throw new BusinessRuleException('Equipment differs from the production proposal. PPC must revise the proposal before confirming.');
            }
            app(ProductionResourceService::class)->assertReservation($lockedWo, $machine, $mold, $slot->scheduled_start, $slot->scheduled_end, $slot->id);
            if ($review !== null && ((int) $slot->id !== (int) $review['schedule_id']
                || ! $slot->updated_at->equalTo(Carbon::parse($review['schedule_updated_at']))
                || app(WorkOrderAssignmentService::class)->currentCrew($lockedWo)?->id !== $review['expected_crew_id'])) {
                throw new BusinessRuleException('Schedule or crew changed. Refresh and review before confirming.');
            }
            app(WorkOrderAssignmentService::class)->assertReadyForConfirmation($lockedWo, $slot);
            if (now()->max($slot->scheduled_start)->addMinutes(app(ProductionResourceService::class)->durationMinutes($lockedWo, $mold))->gt($slot->scheduled_end)) {
                throw new BusinessRuleException('Proposal no longer fits. Find a new slot before confirming.');
            }
            $this->assertProductionDependenciesReady($lockedWo);

            $lockedWo->forceFill([
                'status' => WorkOrderStatus::Confirmed->value,
                'scheduling_diagnostic' => null,
                'machine_id' => $machine->id,
                'mold_id' => $mold->id,
            ])->save();
            $lockedWo->refresh();

            // OGAMI-015 — machine double-booking guard. A machine already
            // committed to another Confirmed/InProgress work order cannot also
            // take this one. When both this WO and the incumbent carry schedule
            // rows, the conflict is restricted to overlapping time windows;
            // otherwise any other active WO on the same machine blocks confirm.
            $slot->update(['status' => 'confirmed', 'is_confirmed' => true, 'confirmed_at' => now()]);

            $this->reserveMaterialsFor($lockedWo);
            app(WoOperationService::class)->refreshPendingSchedule($lockedWo);

            $confirmed = $this->show($lockedWo->fresh());
            $this->recordStatusChange($confirmed, $from, WorkOrderStatus::Confirmed->value);

            return $confirmed;
        });

        return $result;
    }

    /**
     * start: locks machine + mold rows, flips machine to running, mold to in_use,
     * records actual_start, and issues the previously-reserved materials.
     *
     * Sprint 6 audit §1.1: this previously only flipped statuses. Now it
     * also verifies issued/reserved coverage, releases only the quantity still
     * needed from backed reservations, and creates any remaining MaterialIssue
     * stock movements via StockMovementService.
     */
    /** Read-only preview; start and resume still revalidate under their transaction locks. */
    public function executionReadiness(WorkOrder $wo): array
    {
        $wo->loadMissing(['product', 'machine', 'mold']);
        $crews = app(WorkOrderAssignmentService::class);
        $resources = app(ProductionResourceService::class);
        $slot = $crews->schedule($wo);
        $crew = $crews->currentCrew($wo);
        $blockers = [];
        $check = function (string $code, callable $guard, ?string $owner = null) use (&$blockers): void {
            try {
                $guard();
            } catch (BusinessRuleException $error) {
                $blockers[] = array_filter(['code' => $code, 'message' => $error->getMessage(), 'owner' => $owner], fn ($value) => $value !== null);
            }
        };
        $check('state', function () use ($wo) {
            if (! in_array($wo->status, [WorkOrderStatus::Confirmed, WorkOrderStatus::Paused, WorkOrderStatus::InProgress], true)) {
                throw new BusinessRuleException('Only confirmed, paused or running production can be reviewed.');
            }
        });
        $check('material', function () use ($wo) {
            if (in_array($wo->status, [WorkOrderStatus::Paused, WorkOrderStatus::InProgress], true)) {
                $this->materialUsage->assertProductionCoverage($wo);
            } else {
                $this->assertMaterialPlan($wo);
                $this->materialUsage->assertCoverage($wo);
            }
        });
        if (! in_array($wo->work_order_class, ['service', 'non_stock'], true)) {
            $check('inventory', fn () => app(FinishedGoodInventoryService::class)->assertReady($wo->product), 'Warehouse');
        }
        $check('dependency', fn () => $this->assertProductionDependenciesReady($wo));
        $check('quality', function () use ($wo) {
            $wo->assertFinishFirstRoutingCompatible();
            if (in_array($wo->status, [WorkOrderStatus::Paused, WorkOrderStatus::InProgress], true)) {
                if ($wo->status !== WorkOrderStatus::Paused || ! $this->hasPendingStartupTrial($wo)) {
                    $wo->assertProcessQualityReleased();
                }

                return;
            }
            $specs = app(InspectionSpecService::class);
            $specs->assertInspectable($specs->forProduct($wo->product_id));
        });
        $check('equipment', function () use ($wo, $resources) {
            if (! $wo->machine || ! $wo->mold) {
                throw new BusinessRuleException('Assign an available machine and mold before production.');
            }
            $resources->assertCanAcquire($wo, $wo->machine, $wo->mold);
        });
        $needsReschedule = false;
        $preview = null;
        $presence = [];
        $requiresDeadlineApproval = false;
        try {
            if (! $wo->machine || ! $wo->mold) {
                throw new BusinessRuleException('Assign equipment before starting.');
            }
            $extending = $wo->status === WorkOrderStatus::InProgress;
            $preview = $resources->executionWindow($wo, $wo->machine, $wo->mold, allowLate: $extending);
            $requiresDeadlineApproval = $extending && $wo->planned_end && $preview['window']->scheduled_end->gt($wo->planned_end);
            foreach ($crew?->members ?? [] as $member) {
                if (! $member->employee) {
                    continue;
                }
                $entry = $crews->presence($member->employee, now()) + ['employee_id' => $member->employee->hash_id, 'name' => $member->employee->full_name];
                $presence[] = $entry;
                if ($entry['state'] === 'blocked') {
                    $blockers[] = ['code' => 'presence', 'message' => $entry['name'].': '.$entry['reason']];
                }
            }
            $unverified = collect($presence)->where('state', 'unknown');
            if ($preview['mode'] === 'early' && $unverified->isNotEmpty()) {
                $blockers[] = ['code' => 'presence', 'message' => 'Early start needs verified attendance: '.$unverified->pluck('name')->implode(', ').'.'];
            }
            if (! collect($blockers)->contains('code', 'presence')) {
                $check('crew', fn () => $crews->assertScheduledCrew($wo, $preview['window'], allowHoliday: $preview['allow_holiday'], lock: false));
            }
        } catch (BusinessRuleException $error) {
            $preview = null;
            $needsReschedule = true;
            $message = $error->getMessage();
            if ($slot && $slot->scheduled_start->gt(now()) && $wo->machine && $wo->mold) {
                try {
                    $resources->assertReservation($wo, $wo->machine, $wo->mold, $slot->scheduled_start, $slot->scheduled_end, $slot->id, forExecution: true);
                    $crews->assertScheduledCrew($wo, $slot, allowHoliday: true, lock: false);
                    $needsReschedule = false;
                    $message = 'Scheduled for later. Early start needs free resources and verified worker attendance.';
                } catch (BusinessRuleException $scheduleError) {
                    $message = $scheduleError->getMessage();
                }
            }
            $blockers[] = ['code' => 'schedule', 'message' => $message];
        }
        $check('crew', fn () => $crews->assertReadyForStart($wo, lock: false));

        return ['ready' => $blockers === [], 'needs_reschedule' => $needsReschedule, 'blockers' => $blockers,
            'start_mode' => $preview['mode'] ?? null, 'requires_late_start_acknowledgement' => ($preview['mode'] ?? null) === 'late',
            'execution_start' => $preview ? $preview['window']->scheduled_start->toIso8601String() : null,
            'execution_end' => $preview ? $preview['window']->scheduled_end->toIso8601String() : null,
            'estimated_finish' => $preview ? now()->addMinutes($resources->durationMinutes($wo, $wo->mold))->toIso8601String() : null,
            'requires_late_production_approval' => $requiresDeadlineApproval,
            'presence' => $presence, 'expected_crew_id' => $crew?->hash_id,
            'schedule' => $slot ? $crews->serializeSchedule($slot) : null];
    }

    public function extendRun(WorkOrder $wo, User $actor, array $data): WorkOrder
    {
        return DB::transaction(function () use ($wo, $actor, $data) {
            $locked = WorkOrder::lockForUpdate()->findOrFail($wo->id);
            $locked->assertProcessQualityReleased();
            $this->assertProductionDependenciesReady($locked);
            $this->materialUsage->assertProductionCoverage($locked);
            app(ProductionResourceService::class)->extendRun($locked, $actor, $data);

            return $this->show($locked->fresh());
        }, 3);
    }

    public function start(WorkOrder $wo, int $startedBy, array $execution = []): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::InProgress);
        $from = $wo->status?->value ?? 'confirmed';

        $result = DB::transaction(function () use ($wo, $startedBy, $execution, &$from) {
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::InProgress);
            $lockedWo->assertFinishFirstRoutingCompatible();
            if (array_key_exists('expected_crew_id', $execution)
                && app(WorkOrderAssignmentService::class)->currentCrew($lockedWo)?->id !== $execution['expected_crew_id']) {
                throw new BusinessRuleException('Crew changed. Review the current crew before start or resume.');
            }
            $from = $lockedWo->status?->value ?? 'confirmed';
            $this->assertMaterialPlan($lockedWo);
            $this->assertProductionDependenciesReady($lockedWo);
            $specs = app(InspectionSpecService::class);
            $specs->assertInspectable($specs->forProduct($lockedWo->product_id));
            $this->materialUsage->assertCoverage($lockedWo, lockReservationLevels: true);
            if (! in_array($lockedWo->work_order_class, ['service', 'non_stock'], true)) {
                $inventory = app(FinishedGoodInventoryService::class);
                $inventory->assertReady($lockedWo->product);
                $inventory->ensureItem($lockedWo->product);
            }

            // Close every open downtime row for this WO — a work order can
            // also re-enter production through start after a pause.
            $this->closeOpenDowntimeRows($lockedWo);
            $machine = $lockedWo->machine_id ? Machine::lockForUpdate()->find($lockedWo->machine_id) : null;
            $mold = $lockedWo->mold_id ? Mold::lockForUpdate()->find($lockedWo->mold_id) : null;
            if (! $machine || ! $mold) {
                throw new BusinessRuleException('Cannot start a work order without an assigned machine and mold.');
            }
            if (! in_array($machine->status, [MachineStatus::Idle, MachineStatus::Running], true)) {
                throw new BusinessRuleException('Assigned machine is not available to start production.');
            }
            if (! in_array($mold->status, [MoldStatus::Available, MoldStatus::InUse], true)) {
                throw new BusinessRuleException('Assigned mold is not available.');
            }
            app(ProductionResourceService::class)->acquire($lockedWo, $machine, $mold, actor: User::findOrFail($startedBy), execution: $execution);

            // ADV3 — IATF 16949 traceability: every WO run is a Production Batch.
            // Generate batch_number on first start (assertTransition prevents double-start).
            $batchNumber = $lockedWo->batch_number ?: $this->sequences->generate('production_batch');

            $lockedWo->update([
                'status' => WorkOrderStatus::InProgress->value,
                'actual_start' => $lockedWo->actual_start ?? Carbon::now(),
                'batch_number' => $batchNumber,
            ]);

            // Coverage was checked before any machine/material mutation. Manual
            // issues count once; reservations issue only the remaining BOM need.
            $this->issueReservedMaterials($lockedWo, $startedBy);

            // Refresh the lot trail from exact auto/manual issue evidence. Legacy
            // GRN fallback is used only when no issue evidence exists.
            $this->materialUsage->refreshLotReferences($lockedWo);

            // C-2 — Promote the parent SO to in_production. app() lookup avoids
            // a circular dependency on SalesOrderService at construction time.
            if ($lockedWo->sales_order_id) {
                app(SalesOrderService::class)
                    ->markInProduction((int) $lockedWo->sales_order_id);
            }

            $this->recordStatusChange($lockedWo, $from, WorkOrderStatus::InProgress->value);
            if (! $lockedWo->usesFinishFirstQc()) {
                app(TriggerInProcessQC::class)->handle(
                    new WorkOrderStatusChanged($lockedWo, $from, WorkOrderStatus::InProgress->value),
                );
            }

            return $this->show($lockedWo->fresh());
        });

        return $result;
    }

    public function pause(WorkOrder $wo, string $reason, MachineDowntimeCategory $category): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Paused);
        $from = $wo->status?->value ?? 'in_progress';

        $result = DB::transaction(function () use ($wo, $reason, $category, &$from) {
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::Paused);
            $from = $lockedWo->status?->value ?? 'in_progress';

            // Open downtime record.
            $machine = $lockedWo->machine_id
                ? Machine::query()->lockForUpdate()->find($lockedWo->machine_id)
                : null;
            if ($machine) {
                MachineDowntime::create([
                    'machine_id' => $machine->id,
                    'work_order_id' => $lockedWo->id,
                    'start_time' => Carbon::now(),
                    'category' => $category->value,
                    'description' => $reason,
                ]);
                if ($category === MachineDowntimeCategory::Breakdown) {
                    // A breakdown pause must enter the same authoritative
                    // machine-status flow as an operator transition. This
                    // keeps the maintenance queue and alert listener alive.
                    if ($machine->status !== MachineStatus::Breakdown) {
                        $this->machines->transitionStatus(
                            $machine,
                            MachineStatus::Breakdown,
                            $reason,
                        );
                    }
                }
            }

            $lockedWo->update([
                'status' => WorkOrderStatus::Paused->value,
                'pause_reason' => $reason,
            ]);
            $paused = $this->show($lockedWo->fresh());
            $this->recordStatusChange($paused, $from, WorkOrderStatus::Paused->value, $reason);

            return $paused;
        });

        return $result;
    }

    /**
     * Resume a PAUSED work order.
     *
     * The source state must be checked explicitly, not left to the state
     * machine: `confirmed → in_progress` is a legal edge because that is the
     * edge start() uses, so an edge-only check let resume() move a *Confirmed*
     * work order straight into production as a backdoor start — skipping
     * assertMaterialPlan(), assertProductionDependenciesReady(), the
     * machine/mold availability checks, issueReservedMaterials(),
     * batch_number generation, the actual_start stamp,
     * material-lot snapshot refresh and the parent-SO promotion. That defeated
     * two invariants which have their own passing tests
     * (`standard_no_bom_work_order_cannot_start` and
     * `parent_work_order_cannot_start_before_subassembly_child_is_ready`) and
     * produced finished goods from material that was never issued.
     */
    public function releaseResources(WorkOrder $wo, string $reason, ?int $releasedBy = null): WorkOrder
    {
        return DB::transaction(function () use ($wo, $reason, $releasedBy) {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            if ($wo->status !== WorkOrderStatus::Paused || trim($reason) === '') {
                throw new BusinessRuleException('Only paused production can explicitly release resources; enter a reason.');
            }
            $machine = Machine::query()->lockForUpdate()->findOrFail($wo->machine_id);
            $mold = Mold::query()->lockForUpdate()->findOrFail($wo->mold_id);
            app(ProductionResourceService::class)->release($wo, $machine, $mold);
            $wo->update(['production_released_at' => now()]);
            app(ActivityFeedService::class)->record('production.work_order', 'resources_released', $wo,
                'Paused resources released for '.$wo->wo_number.'.', ['reason' => trim($reason)], actorUserId: $releasedBy);

            return $this->show($wo->fresh());
        });
    }

    public function reviewSlot(WorkOrder $wo, Carbon $start, Carbon $end, int $reviewedBy, string $reason, ?array $review = null): WorkOrder
    {
        return DB::transaction(function () use ($wo, $start, $end, $reviewedBy, $reason, $review) {
            $wo = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            if (! in_array($wo->status, [WorkOrderStatus::Confirmed, WorkOrderStatus::Paused], true)) {
                throw new BusinessRuleException('Review replacement timing only for Confirmed or Paused production. Running timing cannot be changed.');
            }
            if (! empty($review['preferred_production_date']) && $start->toDateString() < $review['preferred_production_date']) {
                throw new BusinessRuleException('Schedule precedes the selected production day. Choose a matching time.');
            }
            $preferenceMatches = ! array_key_exists('preferred_production_date', $review ?? [])
                || $wo->preferred_production_date?->toDateString() === $review['preferred_production_date'];
            $allowLate = app(ProductionResourceService::class)->assertDeadlineReview($wo, $end, User::findOrFail($reviewedBy), $review ?? []);
            if ($preferenceMatches && $review !== null && ! isset($review['employee_ids']) && app(ProductionResourceService::class)->reviewedSlotReplay($wo, $review, $reviewedBy, null, $wo->machine_id, $wo->mold_id)) {
                return $this->show($wo->fresh());
            }
            $resources = app(ProductionResourceService::class);
            if ($review !== null && isset($review['employee_ids'])) {
                if ($resources->reviewedSlotReplay($wo, $review, $reviewedBy, null, $wo->machine_id, $wo->mold_id)) {
                    $current = app(WorkOrderAssignmentService::class)->currentCrew($wo);
                    if ($preferenceMatches && $current && $current->assigned_by === $reviewedBy
                        && $current->recorder_employee_id === $review['recorder_employee_id']
                        && $current->reason === trim($reason)
                        && $current->members->pluck('employee_id')->sort()->values()->all() === collect($review['employee_ids'])->sort()->values()->all()) {
                        return $this->show($wo->fresh());
                    }
                    throw new BusinessRuleException('Crew changed. Refresh before reviewing timing and workers together.');
                }
            }
            $crews = app(WorkOrderAssignmentService::class);
            $actor = User::findOrFail($reviewedBy);
            $holiday = false;
            for ($day = $start->copy()->startOfDay(); $day->lt($end); $day->addDay()) {
                $holiday = $holiday || $crews->isHoliday($day);
            }
            if ($holiday && (empty($review['holiday_authorized']) || ! $actor->hasPermission('production.work_orders.lifecycle'))) {
                throw new BusinessRuleException('Holiday production requires explicit Production Management authorization for this interval.');
            }
            if (isset($review['employee_ids'])) {
                $machine = Machine::whereKey($wo->machine_id)->lockForUpdate()->firstOrFail();
                $mold = Mold::whereKey($wo->mold_id)->lockForUpdate()->firstOrFail();
                app(ProductionResourceService::class)->assertReservation($wo, $machine, $mold, $start, $end, allowLate: $allowLate);
                $proposed = new ProductionSchedule(['scheduled_start' => $start, 'scheduled_end' => $end]);
                $crews->assign($wo, $review + ['minimum_workers' => count($review['employee_ids']),
                    'maximum_workers' => count($review['employee_ids']), '_reviewed_slot' => $proposed, '_allow_holiday' => $holiday], $actor);
            }
            $this->assertProductionDependenciesReady($wo);
            app(ProductionResourceService::class)->reviewSlot($wo, $start, $end, $reviewedBy, $reason, null, $wo->machine_id, $wo->mold_id, $holiday, $review ?? []);
            $changes = ['scheduling_diagnostic' => null];
            if (array_key_exists('preferred_production_date', $review ?? [])) {
                $changes['preferred_production_date'] = $review['preferred_production_date'];
            }
            $wo->update($changes);
            app(WoOperationService::class)->refreshPendingSchedule($wo);
            if ($wo->status === WorkOrderStatus::Paused) {
                $wo->update(['pause_reason' => $reason]);
            }

            return $this->show($wo->fresh());
        }, 3);
    }

    public function resume(WorkOrder $wo, ?int $resumedBy = null, array $execution = []): WorkOrder
    {
        $this->assertResumable($wo);
        $this->assertTransition($wo, WorkOrderStatus::InProgress);
        $from = $wo->status?->value ?? 'paused';

        $result = DB::transaction(function () use ($wo, $resumedBy, $execution, &$from) {
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertResumable($lockedWo);
            if (! $this->hasPendingStartupTrial($lockedWo)) {
                $lockedWo->assertProcessQualityReleased();
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::InProgress);
            if (array_key_exists('expected_crew_id', $execution)
                && app(WorkOrderAssignmentService::class)->currentCrew($lockedWo)?->id !== $execution['expected_crew_id']) {
                throw new BusinessRuleException('Crew changed. Review the current crew before start or resume.');
            }
            $from = $lockedWo->status?->value ?? 'paused';
            // The full BOM is the start gate. After a pause, require issued
            // material only for the saved per-item norm of production already
            // recorded; each further output record gates its own increment.
            $this->materialUsage->assertProductionCoverage($lockedWo);

            // Close every open downtime row for this WO.
            $this->closeOpenDowntimeRows($lockedWo);
            $machine = $lockedWo->machine_id
                ? Machine::query()->lockForUpdate()->find($lockedWo->machine_id)
                : null;
            $mold = $lockedWo->mold_id
                ? Mold::query()->lockForUpdate()->find($lockedWo->mold_id)
                : null;
            if (! $machine || ! $mold) {
                throw new BusinessRuleException('Cannot resume a work order without an assigned machine and mold.');
            }
            if (! in_array($machine->status, [MachineStatus::Idle, MachineStatus::Running], true)) {
                throw new BusinessRuleException(
                    "Assigned machine {$machine->machine_code} is currently {$machine->status?->value} and cannot resume production. Repair or restore the machine first."
                );
            }
            if (! in_array($mold->status, [MoldStatus::Available, MoldStatus::InUse], true)) {
                throw new BusinessRuleException('Assigned mold is not available to resume production.');
            }
            app(ProductionResourceService::class)->acquire($lockedWo, $machine, $mold, actor: $resumedBy ? User::findOrFail($resumedBy) : auth()->user(), execution: $execution);
            $lockedWo->update([
                'status' => WorkOrderStatus::InProgress->value,
                'pause_reason' => null, 'production_released_at' => null,
            ]);
            $resumed = $this->show($lockedWo->fresh());
            $this->recordStatusChange($resumed, $from, WorkOrderStatus::InProgress->value);
            if ($this->autoFinishIfTargetReached($resumed)) {
                $resumed = $this->show($resumed->fresh());
            }

            return $resumed;
        });

        return $result;
    }

    private function closeOpenDowntimeRows(WorkOrder $wo): int
    {
        $now = Carbon::now();
        $rows = MachineDowntime::query()
            ->where('work_order_id', $wo->id)
            ->whereNull('end_time')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $row->closeAt($now);
        }

        return $rows->count();
    }

    public function autoFinishIfTargetReached(WorkOrder $wo): bool
    {
        return DB::transaction(function () use ($wo): bool {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            if (! $locked->usesFinishFirstQc() || $locked->rework_source_output_id
                || $locked->status !== WorkOrderStatus::InProgress
                || (int) $locked->quantity_target < 1
                || (int) $locked->quantity_produced < (int) $locked->quantity_target) {
                return false;
            }
            try {
                $this->complete($locked);
            } catch (BusinessRuleException) {
                // Preserve saved output while an existing completion prerequisite awaits resolution.
                return false;
            }

            return true;
        });
    }

    public function productionCompletionBlocker(WorkOrder $wo): ?string
    {
        if ($wo->status !== WorkOrderStatus::InProgress
            || (int) ($wo->usesFinishFirstQc() ? $wo->quantity_produced : $wo->quantity_good) < (int) $wo->quantity_target) {
            return null;
        }
        try {
            $this->assertProductionCompletionReady($wo);
            $this->assertFinishedGoodsReceiptAndLedgerSettled($wo);
        } catch (BusinessRuleException $error) {
            return $error->getMessage();
        }

        return null;
    }

    private function assertProductionCompletionReady(WorkOrder $wo): void
    {
        $finishFirst = $wo->usesFinishFirstQc();
        $wo->assertFinishFirstRoutingCompatible();
        if ($finishFirst && LateProductionReport::query()->where('work_order_id', $wo->id)
            ->whereIn('status', ['pending', 'correction'])->exists()) {
            throw new BusinessRuleException('Review pending late production reports before finishing this work order.');
        }
        if ($wo->outputs()->where('held_quantity', '>', 0)->exists()) {
            throw new BusinessRuleException('Resolve held historical production through QC recovery before completing this work order.');
        }
        $wo->assertProcessQualityReleased();
        app(WorkOrderMaterialHandoverService::class)->assertForCompletion($wo);

        $incompleteOps = WoOperation::query()
            ->where('work_order_id', $wo->id)
            ->whereNotIn('status', [
                WoOperationStatus::Completed->value,
                WoOperationStatus::Skipped->value,
            ])
            ->exists();
        if ($incompleteOps) {
            throw new BusinessRuleException(
                "Work order {$wo->wo_number} cannot be completed because some routing operations are still pending or in progress."
            );
        }
    }

    public function complete(WorkOrder $wo, ?string $shortCompletionReason = null, ?Carbon $productionFinishedAt = null): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Completed);
        $from = $wo->status?->value ?? 'in_progress';

        $result = DB::transaction(function () use ($wo, $shortCompletionReason, $productionFinishedAt, &$from) {
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::Completed);
            $finishFirst = $lockedWo->usesFinishFirstQc();
            if ($productionFinishedAt !== null && ! $finishFirst) {
                throw new BusinessRuleException('An actual production finish time is supported only for finish-first work orders.');
            }
            $this->assertProductionCompletionReady($lockedWo);
            $from = $lockedWo->status?->value ?? 'in_progress';

            // A completed WO hands its good output to outgoing QC. With no
            // good output there is nothing to inspect or ship, and a completed
            // WO can no longer record output, so the order line was left short
            // behind a status that claimed the work was done.
            $completionQuantity = $finishFirst ? (int) $lockedWo->quantity_produced : (int) $lockedWo->quantity_good;
            if ($completionQuantity < 1 && ! ($lockedWo->rework_source_output_id && (int) $lockedWo->quantity_produced === (int) $lockedWo->quantity_target)
                && ! ((int) $lockedWo->quantity_produced > 0 && trim((string) $shortCompletionReason) !== '')) {
                throw new BusinessRuleException(
                    $finishFirst
                        ? "Work order {$lockedWo->wo_number} has no recorded physical output to complete."
                        : "Work order {$lockedWo->wo_number} has no good output to complete. Record good output or resolve its defects through authorized recovery."
                );
            }
            if ($completionQuantity < (int) $lockedWo->quantity_target && ! $lockedWo->rework_source_output_id) {
                $reason = trim((string) $shortCompletionReason);
                if ($reason === '' || mb_strlen($reason) > 2000) {
                    throw new BusinessRuleException('Production is below target. Enter a short-completion reason before finishing the run.');
                }
                $lockedWo->forceFill(['short_completion_reason' => $reason])->save();
            }
            $this->assertFinishedGoodsReceiptAndLedgerSettled($lockedWo);
            $produced = (int) $lockedWo->quantity_produced;
            $rejected = (int) $lockedWo->quantity_rejected;
            $scrap = $produced > 0 ? round(($rejected / $produced) * 100, 2) : 0.0;
            $actualFinish = $productionFinishedAt ?? Carbon::now();
            if ($finishFirst) {
                $latestOutputFinish = $lockedWo->outputs()->reorder()->selectRaw('MAX(COALESCE(production_finished_at, recorded_at)) AS latest_finish')->first()?->latest_finish;
                $latestScheduledFinish = ProductionSchedule::query()->where('work_order_id', $lockedWo->id)
                    ->whereNull('rework_ncr_id')->whereIn('status', ['confirmed', 'executed'])->max('scheduled_end');
                $latestApprovedReportFinish = LateProductionReport::query()->where('work_order_id', $lockedWo->id)
                    ->where('status', 'approved')->max('production_finished_at');
                $executionEvidenceEnd = collect([$latestScheduledFinish, $latestApprovedReportFinish])->filter()->max();
                if (! $lockedWo->actual_start || $actualFinish->gt(now()) || $actualFinish->lt($lockedWo->actual_start)
                    || ($latestOutputFinish && $actualFinish->lt(Carbon::parse($latestOutputFinish)))
                    || ($productionFinishedAt !== null && (! $executionEvidenceEnd || $actualFinish->gt(Carbon::parse($executionEvidenceEnd))))) {
                    throw new BusinessRuleException('Production finish time must be between the actual start, latest approved production finish, and current time.');
                }
            }
            $lockedWo->update([
                'status' => WorkOrderStatus::Completed->value,
                'actual_end' => $actualFinish,
                'scrap_rate' => $scrap,
            ]);
            if ($lockedWo->machine_id && $lockedWo->mold_id) {
                $machine = Machine::query()->lockForUpdate()->find($lockedWo->machine_id);
                $mold = Mold::query()->lockForUpdate()->find($lockedWo->mold_id);
                if ($machine && $mold) {
                    app(ProductionResourceService::class)->release($lockedWo, $machine, $mold);
                }
            }
            // M050 — a completed work order retires its schedule rows so they
            // stop blocking machine capacity in future planning runs. Runs
            // after the WO transition so the immutability guard on started
            // schedules (in_progress/paused) does not fire.
            ProductionSchedule::where('work_order_id', $lockedWo->id)
                ->whereIn('status', [
                    ProductionScheduleStatus::Pending->value,
                    ProductionScheduleStatus::Confirmed->value,
                ])
                ->update(['status' => ProductionScheduleStatus::Executed->value]);

            $completed = $this->show($lockedWo->fresh());
            app(OutboxService::class)->recordForChain(
                new WorkOrderCompleted($completed),
                $completed,
                'o2c',
                'work_order',
                WorkOrderStatus::Completed->value,
            );
            $this->recordStatusChange($completed, $from, WorkOrderStatus::Completed->value,
                $productionFinishedAt ? 'Production finished at '.$actualFinish->toIso8601String().'; recorded at '.now()->toIso8601String().'.' : null);
            $actor = auth()->user() ?? User::query()->find($completed->created_by);
            if ($actor) {
                $this->requestQualityClose($completed, $actor);
                $completed->refresh();
            }

            return $completed;
        });

        return $result;
    }

    public function close(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Closed);
        $from = $wo->status?->value ?? 'completed';

        $result = DB::transaction(function () use ($wo, &$from) {
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::Closed);
            $from = $lockedWo->status?->value ?? 'completed';

            // A closed work order is terminal: after this no inspection can be
            // completed against it and no NCR can drive a disposition. Closing
            // with either open would freeze the IATF quality record unfinished
            // — the WO read as done while its quality gate had not spoken, and
            // capacity planning treated the run as fully resolved. Only a
            // terminal inspection whose NCR loop (if any) has concluded may be
            // closed over.
            $this->assertQualityGateSettled($lockedWo);

            $lockedWo->update(['status' => WorkOrderStatus::Closed->value]);
            $closed = $this->show($lockedWo->fresh());
            $this->recordStatusChange($closed, $from, WorkOrderStatus::Closed->value);

            return $closed;
        });

        return $result;
    }

    public function requestQualityClose(WorkOrder $wo, User $by): void
    {
        if ($this->autoFinishIfTargetReached($wo)) {
            $wo = $wo->fresh();
        }
        app(OutboxService::class)->record(new WorkOrderQualityResolved($wo, $by));
        $this->autoCloseIfReady($wo, $by);
    }

    public function autoCloseIfReady(WorkOrder $wo, User $by): bool
    {
        return DB::transaction(function () use ($wo): bool {
            $locked = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $locked || $locked->status !== WorkOrderStatus::Completed || $this->qualityCloseBlocker($locked) !== null) {
                return false;
            }
            $locked->update(['status' => WorkOrderStatus::Closed->value]);
            $this->recordStatusChange($locked, 'completed', 'closed', 'Automatically closed after production and quality resolution.');
            app(WorkOrderReworkService::class)->notifyProduction($locked, null, 'closed');

            return true;
        });
    }

    public function qualityCloseBlocker(WorkOrder $wo): ?string
    {
        if ($wo->status !== WorkOrderStatus::Completed) {
            return null;
        }
        try {
            $this->assertQualityGateSettled($wo);
        } catch (BusinessRuleException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function cancel(WorkOrder $wo, ?string $reason = null): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Cancelled);
        $from = $wo->status?->value ?? 'planned';

        $result = DB::transaction(function () use ($wo, $reason, &$from) {
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::Cancelled);
            if ($lockedWo->sales_order_id !== null) {
                $salesOrder = SalesOrder::query()->lockForUpdate()->find($lockedWo->sales_order_id);
                if ($salesOrder?->status !== SalesOrderStatus::Cancelled) {
                    throw new BusinessRuleException('Cancel the sales order to cancel its linked work orders.');
                }
                if ($lockedWo->actual_start !== null
                    || $lockedWo->materials()->where('actual_quantity_issued', '>', 0)->exists()) {
                    throw new BusinessRuleException('Started work and issued materials must be reconciled before cancelling this work order.');
                }
            } elseif ($lockedWo->production_purpose !== 'stock') {
                throw new BusinessRuleException('Only stock production work orders can be cancelled directly.');
            }
            if ($lockedWo->rework_source_output_id) {
                throw new BusinessRuleException('A confirmed NCR repair cannot be cancelled. Finish and account for its good or rejected pieces so the linked NCR can resolve.');
            }
            if ((int) $lockedWo->quantity_produced > 0 || $lockedWo->outputs()->exists()) {
                throw new BusinessRuleException('A work order with produced output cannot be cancelled. Finish production and resolve its QC or defects.');
            }
            if ($lockedWo->production_purpose === 'stock') {
                $reason = trim($reason ?? '');
                if ($reason === '' || mb_strlen($reason) > 500) {
                    throw new BusinessRuleException('Enter a cancellation reason of 1 to 500 characters.');
                }
                $this->assertStockCancellationSafe($lockedWo);
            }
            if ($lockedWo->production_purpose === 'stock' && $lockedWo->parent_wo_id === null) {
                $descendants = collect();
                $visit = function (WorkOrder $parent) use (&$visit, &$descendants): void {
                    foreach ($parent->children()->where('status', '!=', 'cancelled')->lockForUpdate()->get() as $child) {
                        $this->assertStockCancellationSafe($child);
                        if ($child->status->value !== 'planned' || $child->currentProductionSchedule()->exists()
                            || $child->machine_id || $child->mold_id || $child->outputs()->exists()
                            || $child->crews()->whereNull('ended_at')->exists()
                            || $child->materials()->where('actual_quantity_issued', '>', 0)->exists()) {
                            throw new BusinessRuleException("Review committed subassembly {$child->wo_number} before cancelling its stock production.");
                        }
                        $visit($child);
                        $descendants->push($child);
                    }
                };
                $visit($lockedWo);
                $requests = PurchaseRequest::whereIn('stock_production_work_order_id', $descendants->pluck('id')->push($lockedWo->id))
                    ->whereNotIn('status', ['cancelled', 'rejected'])->lockForUpdate()->get();
                foreach ($requests as $request) {
                    if (! $request->is_auto_generated || $request->status->value !== 'draft'
                        || $request->purchaseOrders()->exists() || $request->rfqs()->exists()) {
                        throw new BusinessRuleException("Review purchasing {$request->pr_number} before cancelling its stock production.");
                    }
                }
                foreach ($requests as $request) {
                    app(PurchaseRequestService::class)->cancel($request);
                }
                foreach ($descendants as $child) {
                    $this->cancel($child, $reason);
                }
            }
            $from = $lockedWo->status?->value ?? 'planned';
            $this->materialUsage->assertReservationLedgerConsistent($lockedWo);

            $lockedWo->update([
                'status' => WorkOrderStatus::Cancelled->value, 'scheduling_diagnostic' => null,
                'pause_reason' => $reason,
            ]);

            $this->closeOpenDowntimeRows($lockedWo);

            // Sprint 6 audit §1.1: release any reservations held by this WO.
            $this->releaseReservedMaterials($lockedWo);

            $machine = $lockedWo->machine_id ? Machine::query()->lockForUpdate()->find($lockedWo->machine_id) : null;
            $mold = $lockedWo->mold_id ? Mold::query()->lockForUpdate()->find($lockedWo->mold_id) : null;
            app(ProductionResourceService::class)->release($lockedWo, $machine, $mold);
            if ($lockedWo->production_purpose === 'stock') {
                $lockedWo->crews()->whereNull('ended_at')->whereNull('rework_ncr_id')->update(['ended_at' => now()]);
            }
            // M050 — a cancelled work order releases its reserved machine
            // window so the scheduler can replan the slot.
            ProductionSchedule::where('work_order_id', $lockedWo->id)
                ->whereIn('status', [
                    ProductionScheduleStatus::Pending->value,
                    ProductionScheduleStatus::Confirmed->value,
                ])
                ->update(['status' => ProductionScheduleStatus::Superseded->value]);

            $cancelled = $this->show($lockedWo->fresh());
            $this->recordStatusChange($cancelled, $from, WorkOrderStatus::Cancelled->value, $reason);

            return $cancelled;
        });

        return $result;
    }

    private function assertStockCancellationSafe(WorkOrder $wo): void
    {
        if (! in_array($wo->status, [WorkOrderStatus::Planned, WorkOrderStatus::Confirmed], true)
            || $wo->actual_start !== null || (int) $wo->quantity_produced > 0 || $wo->outputs()->exists()
            || $wo->operations()->where(function ($query): void {
                $query->where('status', '!=', WoOperationStatus::Pending->value)
                    ->orWhereNotNull('setup_start')->orWhereNotNull('actual_start')
                    ->orWhere('qty_completed', '>', 0)->orWhere('qty_scrapped', '>', 0);
            })->exists()) {
            throw new BusinessRuleException("Only unstarted planned or confirmed stock production can be cancelled. Review {$wo->wo_number}.");
        }
        if ($wo->materials()->where('actual_quantity_issued', '>', 0)->exists()
            || MaterialReservation::where('work_order_id', $wo->id)->where('status', ReservationStatus::Issued->value)->exists()
            || MaterialIssueSlip::where('work_order_id', $wo->id)->where('status', MaterialIssueStatus::Issued->value)->exists()
            || StockMovement::where('reference_type', 'work_order')->where('reference_id', $wo->id)
                ->where('movement_type', StockMovementType::MaterialIssue->value)->exists()) {
            throw new BusinessRuleException('Issued materials must be reconciled before cancelling stock production.');
        }
    }

    /** Refresh only an untouched automatic BOM plan; execution snapshots stay fixed. */
    public function reconcilePlannedMaterialPlan(WorkOrder $wo): void
    {
        if ($wo->status !== WorkOrderStatus::Planned || $wo->currentProductionSchedule()->exists()
            || $wo->materials()->where('actual_quantity_issued', '>', 0)->exists()
            || $wo->material_plan_source !== 'bom') {
            throw new BusinessRuleException('Only untouched BOM work orders can refresh their material plan.');
        }
        $bom = $this->boms->activeForProduct($wo->product_id);
        if (! $bom) {
            throw new BusinessRuleException('Configure an active BOM before refreshing production.');
        }
        $bom = $this->boms->ensureFreshForPlanning($bom);
        $rows = $this->boms->directRequirements($wo->product_id, (float) $wo->quantity_target);
        $wo->materials()->delete();
        foreach ($rows as $row) {
            $wo->materials()->create([
                'item_id' => $row['item_id'], 'bom_quantity' => $row['gross_quantity'],
                'standard_unit_cost' => $row['standard_unit_cost'], 'standard_cost' => $row['standard_cost'],
                'actual_quantity_issued' => '0', 'actual_cost' => '0',
                'cost_variance' => Money::sub('0.00', $row['standard_cost']), 'variance' => '0',
            ]);
        }
        $wo->forceFill(['bom_id' => $bom->id, 'bom_version' => $bom->version])->save();
        $wo->unsetRelation('materials');
    }

    public function delete(WorkOrder $wo): void
    {
        DB::transaction(function () use ($wo): void {
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            if ($lockedWo->status !== WorkOrderStatus::Planned) {
                throw new BusinessRuleException('Only planned work orders can be deleted.');
            }
            if ($lockedWo->production_purpose === 'stock') {
                throw new BusinessRuleException('Cancel stock work orders to release related commitments and preserve history.');
            }
            app(ProductionResourceService::class)->releaseReservation($lockedWo);
            $lockedWo->delete();
        });
    }

    public function restore(WorkOrder $wo, int $restoredBy): WorkOrder
    {
        return DB::transaction(function () use ($wo, $restoredBy): WorkOrder {
            $locked = WorkOrder::withTrashed()
                ->lockForUpdate()
                ->findOrFail($wo->id);
            if (! $locked->trashed()) {
                throw new BusinessRuleException('Only deleted work orders can be restored.');
            }

            $deletedAt = (string) $locked->deleted_at;
            $locked->restore();

            app(ActivityFeedService::class)->record(
                type: 'production.work_order',
                action: 'restored',
                subject: $locked,
                summary: "Work order {$locked->wo_number} restored.",
                detail: ['work_order_id' => $locked->hash_id],
                idempotencyKey: 'production:work-order:restore:'.$locked->id.':'.hash('sha256', $deletedAt),
                actorUserId: $restoredBy,
            );

            return $this->show($locked->fresh());
        });
    }

    /**
     * Chain-visualization payload for the WO detail page.
     */
    public function chain(WorkOrder $wo): array
    {
        return [
            ['key' => 'planned',     'label' => 'Planned',
                'date' => $wo->created_at?->toDateString(),
                'state' => 'done'],
            ['key' => 'confirmed',   'label' => 'Confirmed',
                'date' => null,
                'state' => $wo->status === WorkOrderStatus::Planned ? 'pending' : 'done'],
            ['key' => 'in_progress', 'label' => 'In Progress',
                'date' => optional($wo->actual_start)->toDateString(),
                'state' => $wo->status === WorkOrderStatus::InProgress ? 'active'
                           : (in_array($wo->status, [WorkOrderStatus::Completed, WorkOrderStatus::Closed], true) ? 'done' : 'pending')],
            ['key' => 'completed',   'label' => 'Completed',
                'date' => optional($wo->actual_end)->toDateString(),
                'state' => in_array($wo->status, [WorkOrderStatus::Completed, WorkOrderStatus::Closed], true) ? 'done' : 'pending'],
            ['key' => 'closed',      'label' => 'Closed',
                'date' => null,
                'state' => $wo->status === WorkOrderStatus::Closed ? 'done' : 'pending'],
        ];
    }

    private function assertTransition(WorkOrder $wo, WorkOrderStatus $to): void
    {
        $this->stateMachine->assertAllowed($wo, $to);
    }

    /**
     * Only a paused work order may be resumed. Reported as the same illegal
     * transition (409 + stable code) the state machine raises, because "this
     * work order is not paused" is a state conflict, not a malformed request.
     */
    private function hasPendingStartupTrial(WorkOrder $wo): bool
    {
        $active = $wo->inspections()->where('stage', 'in_process')->where('status', '!=', 'cancelled')->get();
        $startup = $active->whereNull('wo_operation_id')->first();

        return $active->count() === 1 && $startup?->startup_trial_required
            && in_array($startup->status->value, ['draft', 'in_progress'], true);
    }

    private function assertResumable(WorkOrder $wo): void
    {
        if ($wo->status !== WorkOrderStatus::Paused) {
            throw new IllegalLifecycleTransitionException(
                $wo->status?->value ?? 'unknown',
                WorkOrderStatus::InProgress->value,
            );
        }
    }

    private function assertMaterialPlan(WorkOrder $wo): void
    {
        $class = (string) ($wo->work_order_class ?: 'standard');
        if ($class !== 'standard') {
            if (! in_array($class, ['service', 'non_stock', 'prototype'], true) || trim((string) $wo->exception_reason) === '') {
                throw new BusinessRuleException('A no-BOM work order requires an explicit service, non-stock, or prototype class and an authorized reason.');
            }

            return;
        }
        if ($wo->materials()->exists()) {
            return;
        }
        throw new BusinessRuleException('Standard stock-producing work orders require an effective BOM/material plan before start.');
    }

    /**
     * A parent work order may only be confirmed or started after every direct
     * subassembly has produced its required good quantity. A completed child
     * below target remains blocking; a cancelled child is also blocking until
     * a replacement plan is created.
     *
     * @return array{ready: bool, blocking_work_orders: list<array{id: string, wo_number: string, status: string, quantity_target: int, quantity_good: int}>}
     */
    public function productionReadiness(WorkOrder $wo): array
    {
        $children = $wo->relationLoaded('children')
            ? $wo->children
            : $wo->children()->get([
                'id', 'wo_number', 'status', 'quantity_target', 'quantity_good', 'parent_wo_id',
            ]);

        $blocking = $children->filter(static function (WorkOrder $child): bool {
            return $child->status === WorkOrderStatus::Cancelled
                || (int) $child->quantity_good < (int) $child->quantity_target;
        })->values();

        return [
            'ready' => $blocking->isEmpty(),
            'blocking_work_orders' => $blocking->map(static fn (WorkOrder $child): array => [
                'id' => $child->hash_id,
                'wo_number' => (string) $child->wo_number,
                'status' => (string) ($child->status?->value ?? $child->status),
                'quantity_target' => (int) $child->quantity_target,
                'quantity_good' => (int) $child->quantity_good,
            ])->all(),
        ];
    }

    private function assertProductionDependenciesReady(WorkOrder $wo): void
    {
        $readiness = $this->productionReadiness($wo);
        if ($readiness['ready']) {
            return;
        }

        $numbers = collect($readiness['blocking_work_orders'])
            ->pluck('wo_number')
            ->implode(', ');

        throw new BusinessRuleException(
            "Work order {$wo->wo_number} is waiting for subassembly work orders: {$numbers}."
        );
    }

    /**
     * Stage both cross-module status evidence and canonical chain progress
     * inside the owning lifecycle transaction. OutboxService registers the
     * queue dispatch only after the outermost commit, so listeners never see
     * a rolled-back transition and a process crash cannot lose the event
     * between the business write and its outbox insert.
     */
    private function recordStatusChange(WorkOrder $wo, string $from, string $to, ?string $reason = null): void
    {
        if ($from === $to) {
            return;
        }

        app(OutboxService::class)->recordForChain(
            new WorkOrderStatusChanged($wo, $from, $to, $reason),
            $wo,
            'o2c',
            'work_order',
            $to,
        );

        // Series C — Task C4. Stage the canonical chain event in the same
        // transaction; only its outbox dispatch waits for commit.
        app(ChainBroadcaster::class)->broadcastFor(
            $wo,
            $to,
            auth()->user(),
        );
        DB::afterCommit(static function (): void {
            ProductionDashboardService::forgetCache();
        });
    }

    /**
     * Shared machine/mold assignment gate used by direct WO confirmation and
     * the capacity scheduler's manual reassignment path.
     *
     * The caller should hold row locks on the WO, machine, and mold when the
     * assignment is being persisted.
     */
    public function assertAssignmentValid(WorkOrder $wo, Machine $machine, Mold $mold, ?int $quantity = null): void
    {
        app(ProductionResourceService::class)->assertCompatibility($wo, $machine, $mold);
        app(ProductionResourceService::class)->assertMoldLife($mold, $quantity ?? max(0, (int) $wo->quantity_target - (int) $wo->quantity_good), $wo->id);

    }

    /**
     * A completed work order may only close once its quality evidence is
     * settled: every in-process/outgoing inspection must be terminal, and an
     * NCR opened from a failed outgoing inspection must be closed or
     * cancelled before the WO record reads as fully resolved.
     *
     * TriggerOutgoingQC accepts Closed as a replay-compatible source, so the
     * inspections being terminal (not merely absent) is the load-bearing
     * condition — an auto-created inspection the checker has not reviewed
     * must block the close.
     */
    private function assertQualityGateSettled(WorkOrder $wo): void
    {
        $missing = $wo->outputs()->where('good_count', '>', 0)
            ->whereDoesntHave('inspections', fn ($q) => $q->where('stage', 'outgoing')->whereIn('status', ['passed', 'failed']))
            ->pluck('batch_code');
        if ($missing->isNotEmpty()) {
            throw new BusinessRuleException('WO cannot close without final QC coverage for output batches: '.$missing->implode(', ').'.');
        }
        $unsettled = Inspection::query()
            ->where('entity_type', InspectionEntityType::WorkOrder->value)
            ->where('entity_id', (int) $wo->id)
            ->whereNotIn('status', [
                InspectionStatus::Passed->value,
                InspectionStatus::Failed->value,
                InspectionStatus::Cancelled->value,
            ])
            ->orderBy('id')
            ->pluck('inspection_number');

        if ($unsettled->isNotEmpty()) {
            throw new BusinessRuleException(
                "Work order {$wo->wo_number} cannot close: inspection(s) "
                .$unsettled->implode(', ').' have no final result yet. '
                .'Complete or cancel them before closing.'
            );
        }

        if (! $wo->inspections()->whereIn('stage', ['in_process', 'outgoing'])->whereIn('status', ['passed', 'failed'])->exists()) {
            throw new BusinessRuleException('WO cannot close without final QC coverage. Complete required inspections first.');
        }
        if ($wo->work_order_class !== 'non_stock' && (int) $wo->quantity_good > (int) $wo->outputs()->whereNull('rework_ncr_id')->sum('good_count')) {
            throw new BusinessRuleException('WO cannot close: recorded output batches do not cover its good quantity. Reconcile production output first.');
        }
        $openNcrs = NonConformanceReport::query()
            ->whereNotNull('inspection_id')
            ->whereHas('inspection', fn ($q) => $q
                ->where('entity_type', InspectionEntityType::WorkOrder->value)
                ->where('entity_id', (int) $wo->id))
            ->whereNotIn('status', [
                NcrStatus::Closed->value,
                NcrStatus::Cancelled->value,
            ])
            ->orderBy('id')
            ->pluck('ncr_number');

        if ($openNcrs->isNotEmpty()) {
            throw new BusinessRuleException(
                "Work order {$wo->wo_number} cannot close: NCR(s) "
                .$openNcrs->implode(', ').' are still open. Resolve the disposition and CAPA first.'
            );
        }
        $wo->assertProcessQualityReleased();
        $this->assertFinishedGoodsReceiptAndLedgerSettled($wo);
        foreach ($wo->outputs()->where('good_count', '>', 0)->with('productionReceiptMovement')->get() as $output) {
            $inspection = $output->inspections()->where('stage', 'outgoing')->where('status', '!=', 'cancelled')->orderByDesc('id')->first();
            $settledLoss = $inspection?->hasResolvedOutgoingDisposition() ?? false;
            if (! $inspection || ! $inspection->isMakerChecked() || (! $inspection->canReleaseOutgoing() && ! $settledLoss)) {
                throw new BusinessRuleException('Waiting for independently approved QC or resolved defect disposition for batch '.$output->batch_code.'.');
            }
        }
    }

    /** Production cannot hand good output to QC or Delivery before stock and GL settle. */
    private function assertFinishedGoodsReceiptAndLedgerSettled(WorkOrder $wo): void
    {
        $outputs = $wo->outputs()
            ->where('good_count', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $recordedGood = (int) $wo->outputs()->whereNull('rework_ncr_id')->sum('good_count');
        if ($wo->work_order_class !== 'non_stock' && (int) $wo->quantity_good > $recordedGood) {
            throw new BusinessRuleException(
                'WO cannot complete: recorded output batches do not cover its good quantity. Reconcile production output first.'
            );
        }

        foreach ($outputs as $output) {
            if ($output->production_receipt_handoff_status !== ProductionReceiptHandoffStatus::Generated
                || ! $output->production_receipt_movement_id) {
                throw new BusinessRuleException(
                    'Stock receipt pending. Ask Warehouse to check inventory setup, then click Retry in Recent outputs.'
                );
            }

            $movement = StockMovement::query()->lockForUpdate()->find($output->production_receipt_movement_id);
            if (! $movement) {
                throw new BusinessRuleException(
                    "Work order {$wo->wo_number} cannot complete: finished-goods stock receipt for batch {$output->batch_code} is missing. Reconcile the receipt before continuing."
                );
            }
            if (! $movement->gl_handoff_status?->isResolved()) {
                throw new BusinessRuleException(
                    "Work order {$wo->wo_number} cannot complete: finished-goods batch {$output->batch_code} has an unresolved General Ledger handoff. Ask Accounting to retry the linked stock movement before completing production."
                );
            }
        }
    }

    /**
     * Reserve every BOM line of $wo. For each material, pick the location
     * with the largest available stock; if the chosen location can't cover
     * the BOM quantity, the reservation is split across the locations with
     * the most available stock (F-10) until the demand is met. When the
     * pooled on-hand still can't cover it, the parent transaction rolls back.
     *
     * Locations are locked for update so concurrent confirms don't race the
     * same on-hand pool.
     */
    private function reserveMaterialsFor(WorkOrder $wo): void
    {
        foreach (StockLevel::query()->whereIn('item_id', $wo->materials()->pluck('item_id'))->orderBy('item_id')->orderBy('location_id')->get() as $level) {
            app(QualityStockHoldService::class)->heldQuantity((int) $level->item_id, (int) $level->location_id);
        }
        // `materials.item` (not just `materials`) because the insufficient-stock
        // message below names the item by code, and Model::preventLazyLoading is
        // active outside production.
        $wo->loadMissing('materials.item');
        foreach ($wo->materials as $material) {
            $needed = (string) $material->bom_quantity;
            if (bccomp($needed, '0', 3) <= 0) {
                continue;
            }

            $locationId = $this->bestLocationForItem(
                (int) $material->item_id,
                $needed,
            );
            if ($locationId !== null) {
                $this->reserveAt((int) $material->item_id, $locationId, $needed, $wo->id);

                continue;
            }

            // F-10 — no single location covers the demand: split across the
            // locations with the most available stock until the need is met.
            // A failure to cover the pooled on-hand throws here and the
            // confirm() transaction rolls back the partial reservations.
            if (! $this->reserveMaterialsSplit((int) $material->item_id, $needed, $wo->id)) {
                // The operator's remedies are real ones — receive the shortfall,
                // release another WO's reservation, or cut the target quantity —
                // and confirm() has already rolled back, so nothing is half done.
                //
                // Name the item by CODE, never by primary key: this message is
                // returned verbatim in a 422 body by WorkOrderController::confirm(),
                // so interpolating $material->item_id published a raw `items` PK
                // to the client and made the endpoint an id-enumeration oracle.
                $itemLabel = $material->item?->code ?? "item #{$material->item_id}";
                throw new BusinessRuleException(
                    "Insufficient stock for {$itemLabel} (work order {$wo->wo_number}): "
                    ."needed {$needed}."
                );
            }
        }
    }

    private function reserveAt(int $itemId, int $locationId, string $quantity, int $woId): void
    {
        $this->stock->reserve($itemId, $locationId, $quantity);

        MaterialReservation::create([
            'item_id' => $itemId,
            'work_order_id' => $woId,
            'location_id' => $locationId,
            'quantity' => $quantity,
            'status' => ReservationStatus::Reserved->value,
            'reserved_at' => Carbon::now(),
        ]);
    }

    /**
     * @return bool true when the full demand was reserved across locations
     */
    private function reserveMaterialsSplit(int $itemId, string $needed, int $woId): bool
    {
        $remaining = $needed;
        $levels = StockLevel::where('item_id', $itemId)
            // F-02 — never draw held/scrapped stock for production.
            ->whereHas('location.zone', function ($q) {
                $q->whereNotIn('zone_type', [
                    WarehouseZoneType::Quarantine->value,
                    WarehouseZoneType::Scrap->value,
                ]);
            })
            ->orderByRaw('(quantity - reserved_quantity) DESC')
            ->lockForUpdate()
            ->get();

        foreach ($levels as $level) {
            if (bccomp($remaining, '0', 3) <= 0) {
                break;
            }
            $available = bcsub((string) $level->quantity, (string) $level->reserved_quantity, 3);
            $available = bcsub($available, app(QualityStockHoldService::class)->heldQuantity($itemId, (int) $level->location_id), 3);
            if (bccomp($available, '0', 3) <= 0) {
                continue;
            }
            $take = bccomp($available, $remaining, 3) >= 0 ? $remaining : $available;
            $this->reserveAt($itemId, (int) $level->location_id, $take, $woId);
            $remaining = bcsub($remaining, $take, 3);
        }

        return bccomp($remaining, '0', 3) <= 0;
    }

    /**
     * Release every backed reservation and issue only the remaining BOM
     * requirement after manual and prior auto issues have been counted. The
     * auto-only persisted counter remains the input used by MRP. All within
     * the start() transaction.
     */
    private function issueReservedMaterials(WorkOrder $wo, int $userId): void
    {
        $remainingByItem = [];
        foreach ($this->materialUsage->groups($wo) as $group) {
            $remainingByItem[(int) $group['item_id']] = bcsub(
                (string) $group['required_quantity'],
                (string) $group['actual_quantity_issued'],
                3,
            );
            if (bccomp($remainingByItem[(int) $group['item_id']], '0', 3) < 0) {
                $remainingByItem[(int) $group['item_id']] = '0.000';
            }
        }

        foreach (MaterialReservation::where('work_order_id', $wo->id)->where('status', ReservationStatus::Reserved->value)->orderBy('item_id')->orderBy('location_id')->get() as $reservation) {
            app(QualityStockHoldService::class)->heldQuantity((int) $reservation->item_id, (int) $reservation->location_id);
        }
        $reservations = MaterialReservation::where('work_order_id', $wo->id)
            ->where('status', ReservationStatus::Reserved->value)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
        $planByReservation = collect($this->materialUsage->reservationPlan($wo, strict: true))
            ->keyBy('reservation_id');

        foreach ($reservations as $res) {
            $reservedQty = (string) $res->quantity;
            $reservationPlan = $planByReservation->get((int) $res->id);
            if ($res->location_id === null) {
                // A null-location row cannot hold stock in the ledger.
                $res->update([
                    'status' => ReservationStatus::Released->value,
                    'released_at' => Carbon::now(),
                ]);

                continue;
            }
            if (! $reservationPlan || ! $reservationPlan['backed']) {
                throw new BusinessRuleException(
                    "Reservation {$res->id} is not backed by an unambiguous stock hold. Reconcile the reservation ledger before starting production."
                );
            }

            $remaining = $remainingByItem[(int) $res->item_id] ?? '0.000';
            if (! $reservationPlan['location_usable']) {
                // A known, exactly backed reservation at a now-blocked location
                // can be released, but not consumed. It never contributes to
                // start coverage; manual/other usable issues must cover demand.
                $this->stock->release((int) $res->item_id, (int) $res->location_id, $reservedQty);
                $res->update([
                    'status' => ReservationStatus::Released->value,
                    'released_at' => Carbon::now(),
                ]);

                continue;
            }

            $qty = bccomp($remaining, $reservedQty, 3) >= 0 ? $reservedQty : $remaining;

            // Release the full, verified reservation before moving only the
            // portion still needed after manual and prior auto issues.
            $this->stock->release((int) $res->item_id, (int) $res->location_id, $reservedQty);

            if (bccomp($qty, '0', 3) <= 0) {
                $res->update([
                    'quantity' => $reservedQty,
                    'status' => ReservationStatus::Released->value,
                    'released_at' => Carbon::now(),
                ]);

                continue;
            }

            $issueMovements = $this->stock->issueAcrossLots(new StockMovementInput(
                type: StockMovementType::MaterialIssue,
                itemId: (int) $res->item_id,
                fromLocationId: (int) $res->location_id,
                toLocationId: null,
                quantity: $qty,
                referenceType: 'work_order',
                referenceId: $wo->id,
                remarks: "WO {$wo->wo_number} material issue (reservation #{$res->id})",
                createdBy: $userId,
            ));

            $res->update([
                'quantity' => $qty,
                'status' => ReservationStatus::Issued->value,
                'released_at' => Carbon::now(),
            ]);

            $excess = bcsub($reservedQty, $qty, 3);
            if (bccomp($excess, '0', 3) > 0) {
                MaterialReservation::create([
                    'item_id' => $res->item_id,
                    'work_order_id' => $res->work_order_id,
                    'location_id' => $res->location_id,
                    'quantity' => $excess,
                    'status' => ReservationStatus::Released->value,
                    'reserved_at' => $res->reserved_at,
                    'released_at' => Carbon::now(),
                ]);
            }

            // Persisted issue counters deliberately remain auto-issue-only;
            // Warehouse slips are read separately and MRP adds the two sources.
            // Repeated BOM rows are aggregated on this deterministic first row.
            $material = WorkOrderMaterial::where('work_order_id', $wo->id)
                ->where('item_id', $res->item_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($material !== null) {
                $issueCost = $issueMovements->reduce(fn (string $sum, $movement): string => Money::add($sum, (string) $movement->total_cost), '0.00');
                $actualCost = Money::add((string) $material->actual_cost, $issueCost);
                $material->actual_quantity_issued = bcadd((string) $material->actual_quantity_issued, $qty, 3);
                $material->actual_cost = $actualCost;
                $itemPlan = WorkOrderMaterial::query()
                    ->where('work_order_id', $wo->id)
                    ->where('item_id', $res->item_id)
                    ->selectRaw('SUM(bom_quantity) AS bom_quantity, SUM(standard_cost) AS standard_cost')
                    ->first();
                $material->cost_variance = Money::sub($actualCost, (string) $itemPlan->standard_cost);
                $material->variance = bcsub((string) $material->actual_quantity_issued, (string) $itemPlan->bom_quantity, 3);
                $material->save();
            }

            $remainingByItem[(int) $res->item_id] = bcsub($remaining, $qty, 3);
        }
    }

    /**
     * Release all Reserved MaterialReservations of $wo without issuing them.
     * Used by cancel(); idempotent — already-released or already-issued rows
     * are skipped.
     */
    private function releaseReservedMaterials(WorkOrder $wo): void
    {
        $reservations = MaterialReservation::where('work_order_id', $wo->id)
            ->where('status', ReservationStatus::Reserved->value)
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $res) {
            if ($res->location_id === null) {
                $res->update([
                    'status' => ReservationStatus::Released->value,
                    'released_at' => Carbon::now(),
                ]);

                continue;
            }
            $this->stock->release((int) $res->item_id, (int) $res->location_id, (string) $res->quantity);
            $res->update([
                'status' => ReservationStatus::Released->value,
                'released_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Find the warehouse_location with the largest available (quantity -
     * reserved_quantity) for an item, where the available is at least
     * $needed. Returns null if no single location can cover the demand.
     */
    private function bestLocationForItem(int $itemId, string $needed): ?int
    {
        $row = StockLevel::where('item_id', $itemId)
            // F-02 — never pick quarantine/scrap-zone stock for production.
            ->whereHas('location.zone', function ($q) {
                $q->whereNotIn('zone_type', [
                    WarehouseZoneType::Quarantine->value,
                    WarehouseZoneType::Scrap->value,
                ]);
            })
            ->orderByRaw('(quantity - reserved_quantity) DESC')
            ->lockForUpdate()
            ->get();
        foreach ($row as $level) {
            $available = bcsub(bcsub((string) $level->quantity, (string) $level->reserved_quantity, 3), app(QualityStockHoldService::class)->heldQuantity($itemId, (int) $level->location_id), 3);
            if (bccomp($available, $needed, 3) >= 0) {
                return (int) $level->location_id;
            }
        }

        return null;
    }
}
