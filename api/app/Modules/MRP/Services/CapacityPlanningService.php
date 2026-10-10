<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Modules\Maintenance\Models\MaintenanceWindow;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\HR\Models\Employee;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Enums\MoldStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Services\ProductionResourceService;
use App\Modules\Production\Services\WoOperationService;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderReworkService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 6 — Task 53. MRP II capacity planner.
 *
 * Algorithm (priority-first greedy):
 *  1. Take all 'planned' work orders (or a subset by id).
 *  2. Sort by dependency order (children before parents), then priority desc
 *     and production deadline within each dependency branch.
 *  3. For each WO:
 *       a. Find compatible molds: product_id matches AND status IN
 *          (available, in_use) AND current_shot_count + qty <= max_shots.
 *       b. For each mold, find compatible machines (via mold_machine_compatibility).
 *       c. Pick the earliest feasible compatible machine/mold window around
 *          occupied equipment windows, working shifts, and dependency completion.
 *       d. Duration = qty / mold.output_per_hour + setup_minutes/60 (hours).
 *       e. If a placement worked, persist a 'pending' production_schedules row.
 *       f. If not, record a conflict reason.
 *
 * confirm() flips selected pending rows to 'confirmed', writes machine_id +
 * mold_id back to the WO, then calls WorkOrderService::confirm() so material
 * reservations land.
 */
class CapacityPlanningService
{
    /** Schedule states that reserve a machine time window. */
    private const BLOCKING_SCHEDULE_STATUSES = [
        'pending',
        'confirmed',
        'executed',
    ];

    public function __construct(
        private readonly WorkOrderService $workOrders,
    ) {}

    /** Preview repair resources without changing the original work order or reservations. */
    public function suggestRepair(WorkOrder $wo, NonConformanceReport $ncr, array $options): array
    {
        app(WorkOrderReworkService::class)->assertRepair($wo, $ncr);
        if ($wo->status !== WorkOrderStatus::Completed || $ncr->rework_started_at) {
            throw new BusinessRuleException('Auto scheduling is available before repair starts, after production finishes.');
        }
        $crews = app(WorkOrderAssignmentService::class);
        $slot = $crews->schedule($wo, $ncr->id);
        $references = ['expected_schedule_id' => $slot?->hash_id, 'expected_schedule_updated_at' => $slot?->updated_at?->toISOString(),
            'expected_crew_id' => $crews->currentCrew($wo, $ncr->id)?->hash_id, 'production_deadline' => $wo->planned_end?->toIso8601String(),
            'repair_quantity' => app(ProductionResourceService::class)->remainingQuantity($wo, $ncr->id)];
        $options['rework_ncr_id'] = $ncr->id;
        $options['maximum_workers'] = $options['minimum_workers'] ?? $wo->minimum_workers;
        $others = ProductionSchedule::where('reserves_capacity', true)->whereIn('status', self::BLOCKING_SCHEDULE_STATUSES)
            ->where(fn ($q) => $q->where('work_order_id', '!=', $wo->id)->orWhereNull('rework_ncr_id')->orWhere('rework_ncr_id', '!=', $ncr->id))->get();
        $windows = [];
        foreach ($others as $row) {
            $this->assertScheduleWindowIsValid($row);
            $windows[$row->machine_id][] = ['schedule_id' => $row->id, 'work_order_id' => 0, 'rework_ncr_id' => $row->rework_ncr_id,
                'status' => $row->status->value, 'start' => $row->scheduled_start, 'end' => $row->scheduled_end];
        }
        $placement = $this->placeWorkOrder($wo, $windows, [], $options);
        if (! $placement['ok']) {
            return $references + ['scheduled_start' => null, 'scheduled_end' => null, 'machine_id' => null, 'mold_id' => null,
                'members' => [], 'recorder_employee_id' => null, 'blockers' => $placement['reasons']];
        }
        $proposed = new ProductionSchedule(['scheduled_start' => $placement['start'], 'scheduled_end' => $placement['end']]);
        $suggestion = $crews->suggest($wo, $proposed, $ncr->id, $options);
        $machine = isset($placement['machine_id']) ? Machine::findOrFail($placement['machine_id']) : null;
        $mold = isset($placement['mold_id']) ? Mold::findOrFail($placement['mold_id']) : null;

        return $references + ['scheduled_start' => $proposed->scheduled_start->toIso8601String(), 'scheduled_end' => $proposed->scheduled_end->toIso8601String(),
            'machine_id' => $machine?->hash_id, 'mold_id' => $mold?->hash_id,
            'machine' => $machine ? ['machine_code' => $machine->machine_code, 'name' => $machine->name] : null,
            'mold' => $mold ? ['mold_code' => $mold->mold_code, 'name' => $mold->name] : null,
            'members' => $suggestion['members'], 'recorder_employee_id' => $suggestion['recorder_employee_id'],
            'requires_late_production_approval' => $wo->planned_end && $proposed->scheduled_end->gt($wo->planned_end),
            'blockers' => $suggestion['blockers']];
    }

    /** Suggest replacement timing without changing reservations, crew, or production. */
    public function suggestReschedule(WorkOrder $wo, ?string $preferredDate = null): array
    {
        if (! in_array($wo->status, [WorkOrderStatus::Confirmed, WorkOrderStatus::Paused], true)) {
            throw new BusinessRuleException('Only confirmed or paused production can be rescheduled.');
        }
        $crews = app(WorkOrderAssignmentService::class);
        $resources = app(ProductionResourceService::class);
        $currentSlot = $crews->schedule($wo);
        $currentCrew = $crews->currentCrew($wo);
        $references = ['production_deadline' => $wo->planned_end?->toIso8601String(), 'expected_schedule_id' => $currentSlot?->hash_id,
            'expected_schedule_updated_at' => $currentSlot?->updated_at?->toISOString(), 'expected_crew_id' => $currentCrew?->hash_id];
        $blocked = fn (array $messages): array => $references + ['scheduled_start' => null, 'scheduled_end' => null,
            'members' => [], 'recorder_employee_id' => null, 'blockers' => array_values(array_unique($messages))];
        if (! $wo->machine || ! $wo->mold) {
            return $blocked(['Assigned machine and mold are required before rescheduling.']);
        }
        try {
            $resources->assertCompatibility($wo, $wo->machine, $wo->mold);
            // Reserve at least two hours, or the production estimate plus a fifteen-minute buffer.
            $duration = $resources->reservationMinutes($wo, $wo->mold);
            $daily = $this->dailyCapacityMinutes($wo->machine);
            if (! $daily) {
                throw new BusinessRuleException('Machine daily capacity is not configured.');
            }
            // Minute precision permits immediate approval; execution still rechecks remaining duration.
            $from = now()->startOfMinute();
            $horizon = $from->copy()->addDays(90);
            if ($preferredDate !== null) {
                $from = $from->max(Carbon::parse($preferredDate, config('app.timezone'))->startOfDay());
            }
            $deadline = $wo->status === WorkOrderStatus::Paused ? $from->copy()->addDays(90) : $wo->planned_end;
            if (! $deadline || $deadline->lte($from)) {
                throw new BusinessRuleException('Planning deadline expired. Review the WO deadline and delivery impact.');
            }
            $deadline = $deadline->copy()->min($horizon);
            $others = ProductionSchedule::where('reserves_capacity', true)->whereIn('status', self::BLOCKING_SCHEDULE_STATUSES)
                ->where(fn ($q) => $q->where('work_order_id', '!=', $wo->id)->orWhereNotNull('rework_ncr_id'))->get();
            $windows = fn ($rows) => $rows->map(function ($row) {
                $this->assertScheduleWindowIsValid($row);

                return ['schedule_id' => $row->id, 'work_order_id' => $row->work_order_id, 'rework_ncr_id' => $row->rework_ncr_id, 'status' => $row->status->value,
                    'start' => $row->scheduled_start, 'end' => $row->scheduled_end];
            })->all();
            $machineWindows = $windows($others->where('machine_id', $wo->machine_id));
            $moldWindows = $windows($others->where('mold_id', $wo->mold_id));
            $count = max(1, (int) $wo->minimum_workers, $currentCrew?->members->count() ?? 0);
            $reasons = [];
            $best = null;
            foreach ($this->workerCandidateStarts($wo, $from, $deadline) as $candidate) {
                if ($best && $candidate->gte($best['scheduled_start'])) {
                    break;
                }
                try {
                    $time = $this->nextFreeSlot($candidate, $duration, array_merge($machineWindows, $moldWindows),
                        (int) $wo->id, $daily, $deadline, $machineWindows);
                    $resources->assertReservation($wo, $wo->machine, $wo->mold, $time['start'], $time['end'], allowLate: $wo->status === WorkOrderStatus::Paused);
                    $proposed = new ProductionSchedule(['scheduled_start' => $time['start'], 'scheduled_end' => $time['end']]);
                    $staffing = $crews->suggest($wo, $proposed, staffingLimits: ['minimum_workers' => $count, 'maximum_workers' => $count]);
                    if ($staffing['blockers'] !== []) {
                        $reasons = array_merge($reasons, $staffing['blockers']);

                        continue;
                    }
                    if (! $best || $time['start']->lt($best['scheduled_start'])) {
                        $best = $references + ['scheduled_start' => $time['start'], 'scheduled_end' => $time['end'],
                            'members' => $staffing['members'], 'recorder_employee_id' => $staffing['recorder_employee_id'],
                            'machine' => ['machine_code' => $wo->machine->machine_code, 'name' => $wo->machine->name],
                            'mold' => ['mold_code' => $wo->mold->mold_code, 'name' => $wo->mold->name], 'blockers' => [],
                            'requires_late_production_approval' => $wo->planned_end && $time['end']->gt($wo->planned_end)];
                    }
                } catch (BusinessRuleException $error) {
                    $reasons[] = $error->getMessage();
                }
            }
            if ($best) {
                if ($preferredDate !== null && $best['scheduled_start']->toDateString() !== $preferredDate) {
                    $best['preferred_day_message'] = 'No available slot with eligible workers on '.$preferredDate.'. '
                        .(array_values(array_unique($reasons))[0] ?? 'The selected day has no available crew or equipment capacity.');
                }
                $best['scheduled_start'] = $best['scheduled_start']->toIso8601String();
                $best['scheduled_end'] = $best['scheduled_end']->toIso8601String();

                return $best;
            }

            return $blocked(array_slice(array_values(array_unique($reasons)), 0, 3) ?: ['No available production slot and eligible crew before the deadline.']);
        } catch (BusinessRuleException $error) {
            return $blocked([$error->getMessage()]);
        }
    }

    /**
     * Propose schedules for WOs in 'planned' state.
     * Persists pending rows in production_schedules; supersedes any prior
     * pending rows for the same WO.
     *
     * @return array{scheduled: list<array>, conflicts: list<array>}
     */
    public function run(?array $workOrderIds = null, array $optionsByWorkOrder = []): array
    {
        return DB::transaction(function () use ($workOrderIds, $optionsByWorkOrder) {
            $q = WorkOrder::query()
                ->where('status', WorkOrderStatus::Planned->value)
                ->with('product');
            if ($workOrderIds) {
                $q->whereIn('id', $workOrderIds);
            }
            $workOrders = $q
                ->orderByDesc('priority')
                ->orderBy('planned_end')
                ->orderBy('planned_start')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($workOrders->isEmpty()) {
                return ['scheduled' => [], 'conflicts' => []];
            }

            $workOrders = $this->orderWorkOrdersByDependencies($workOrders);

            // ponytail: serialize all candidate resources; narrow resource locks when planning throughput requires it.
            // Scheduler runs, manual reassignments, and machine/mold lifecycle
            // changes must serialize on the same resource rows. This makes the
            // persisted-window check below meaningful under concurrent requests.
            Machine::query()
                ->whereIn('status', [MachineStatus::Idle->value, MachineStatus::Running->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            Mold::query()
                ->whereIn('status', [MoldStatus::Available->value, MoldStatus::InUse->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            app(WorkOrderAssignmentService::class)->productionEmployees()->orderBy('id')->lockForUpdate()->get();
            $blockedWindowsByMachine = $this->loadScheduleWindows();

            $dependencyReadyAt = []; // [parent_work_order_id => latest child end]
            $dependencyFailed = []; // [work_order_id => true]
            $scheduled = [];
            $conflicts = [];

            foreach ($workOrders as $wo) {
                if (isset($dependencyFailed[(int) $wo->id])) {
                    $conflicts[] = [
                        'work_order_id' => $wo->hash_id,
                        'wo_number' => $wo->wo_number,
                        'reasons' => ['subassembly_dependency_unscheduled'], 'checked_at' => now()->toIso8601String(),
                    ];
                    $wo->update(['scheduling_diagnostic' => end($conflicts)]);
                    if ($wo->parent_wo_id) {
                        $dependencyFailed[(int) $wo->parent_wo_id] = true;
                    }

                    continue;
                }

                foreach ($wo->children()->get() as $child) {
                    if ($child->status === WorkOrderStatus::Cancelled) {
                        $dependencyFailed[(int) $wo->id] = true;
                    } elseif ((int) $child->quantity_good < (int) $child->quantity_target) {
                        $childSlot = app(WorkOrderAssignmentService::class)->schedule($child);
                        if (! $childSlot || ! $childSlot->reserves_capacity || $childSlot->scheduled_end->lte(now())) {
                            $dependencyFailed[(int) $wo->id] = true;
                        } else {
                            $dependencyReadyAt[(int) $wo->id] = isset($dependencyReadyAt[(int) $wo->id])
                                ? $dependencyReadyAt[(int) $wo->id]->max($childSlot->scheduled_end)
                                : $childSlot->scheduled_end->copy();
                        }
                    }
                }
                if (isset($dependencyFailed[(int) $wo->id])) {
                    $diagnostic = ['work_order_id' => $wo->hash_id, 'wo_number' => $wo->wo_number,
                        'reasons' => ['Required subassembly has no available production schedule. Schedule its work order first.'],
                        'checked_at' => now()->toIso8601String()];
                    $conflicts[] = $diagnostic;
                    $wo->update(['scheduling_diagnostic' => $diagnostic]);
                    if ($wo->parent_wo_id) {
                        $dependencyFailed[(int) $wo->parent_wo_id] = true;
                    }

                    continue;
                }

                $placement = $this->placeWorkOrder(
                    $wo,
                    $blockedWindowsByMachine,
                    $dependencyReadyAt,
                    $optionsByWorkOrder[(int) $wo->id] ?? [],
                );
                if ($placement['ok']) {
                    // Supersede any existing pending schedule for this WO.
                    ProductionSchedule::where('work_order_id', $wo->id)
                        ->where('status', ProductionScheduleStatus::Pending->value)->where('reserves_capacity', true)
                        ->update(['status' => ProductionScheduleStatus::Superseded->value, 'reserves_capacity' => false]);

                    // The old pending row was kept in the blocked-window index
                    // while this WO was being placed. Remove it only after a
                    // replacement has been successfully selected.
                    $this->removePendingWindowsForWorkOrder($blockedWindowsByMachine, (int) $wo->id);

                    $row = ProductionSchedule::create([
                        'work_order_id' => $wo->id,
                        'machine_id' => $placement['machine_id'],
                        'mold_id' => $placement['mold_id'],
                        'scheduled_start' => $placement['start'],
                        'scheduled_end' => $placement['end'],
                        'priority_order' => $wo->priority ?? 0,
                        'status' => ProductionScheduleStatus::Pending->value,
                        'is_confirmed' => false,
                    ]);
                    $this->addScheduleWindow($blockedWindowsByMachine, $row);
                    $wo->update(['scheduling_diagnostic' => null]);
                    app(WoOperationService::class)->refreshPendingSchedule($wo);
                    $scheduled[] = $this->scheduleSummary($row, $wo);

                    if ($wo->parent_wo_id) {
                        $parentId = (int) $wo->parent_wo_id;
                        $childEnd = Carbon::parse($placement['end']);
                        if (! isset($dependencyReadyAt[$parentId]) || $childEnd->gt($dependencyReadyAt[$parentId])) {
                            $dependencyReadyAt[$parentId] = $childEnd;
                        }
                    }
                } else {
                    $conflicts[] = [
                        'work_order_id' => $wo->hash_id,
                        'wo_number' => $wo->wo_number,
                        'reasons' => array_values(array_unique($placement['reasons'])),
                        'checked_at' => now()->toIso8601String(),
                    ];
                    $wo->update(['scheduling_diagnostic' => end($conflicts)]);
                    if ($wo->parent_wo_id) {
                        $dependencyFailed[(int) $wo->parent_wo_id] = true;
                    }
                }
            }

            return ['scheduled' => $scheduled, 'conflicts' => $conflicts];
        });
    }

    /**
     * Persist a batch of pending schedules as confirmed. Triggers
     * WorkOrderService::confirm() so the WO transitions and reservations
     * are taken.
     */
    public function confirm(array $scheduleIds, int $confirmedBy, ?array $reviews = null): Collection
    {
        return DB::transaction(function () use ($scheduleIds, $confirmedBy, $reviews) {
            $requestedIds = array_values(array_unique(array_map('intval', $scheduleIds)));
            if ($requestedIds === []) {
                return collect();
            }

            $workOrderIds = ProductionSchedule::whereIn('id', $requestedIds)->pluck('work_order_id')->unique()->sort()->all();
            WorkOrder::whereIn('id', $workOrderIds)->orderBy('id')->lockForUpdate()->get();
            $rows = ProductionSchedule::whereIn('id', $scheduleIds)
                ->where('status', ProductionScheduleStatus::Pending->value)->where('reserves_capacity', true)
                ->orderBy('machine_id')
                ->orderBy('id')
                ->get();

            if ($rows->count() !== count($requestedIds)) {
                throw new BusinessRuleException('One or more selected schedules are no longer pending. Refresh the scheduler and try again.');
            }

            $machineIds = $rows->pluck('machine_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $machines = Machine::query()
                ->whereIn('id', $machineIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $moldIds = $rows->pluck('mold_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
            $molds = Mold::query()
                ->whereIn('id', $moldIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $employeeIds = WorkOrderCrew::whereIn('work_order_id', $workOrderIds)->whereNull('ended_at')->whereNull('rework_ncr_id')
                ->with('members')->get()->flatMap(fn ($crew) => $crew->members->pluck('employee_id'))->unique()->sort();
            Employee::whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get();
            $rows = ProductionSchedule::whereIn('id', $requestedIds)->orderBy('id')->lockForUpdate()->get();
            if ($rows->contains(fn ($row) => $row->status !== ProductionScheduleStatus::Pending || ! $row->reserves_capacity)) {
                throw new BusinessRuleException('Schedule changed; refresh and review.');
            }

            $rowsByWorkOrderId = $rows->keyBy('work_order_id');
            $orderedWorkOrders = $this->orderWorkOrdersByDependencies(
                $rows->map(fn (ProductionSchedule $row): WorkOrder => WorkOrder::findOrFail($row->work_order_id))
            );
            $orderedRows = $orderedWorkOrders
                ->map(fn (WorkOrder $wo): ?ProductionSchedule => $rowsByWorkOrderId->get($wo->id))
                ->filter()
                ->values();

            $confirmed = collect();
            foreach ($orderedRows as $row) {
                $wo = WorkOrder::lockForUpdate()->find($row->work_order_id);
                $machine = $machines->get((int) $row->machine_id);
                $mold = $molds->get((int) $row->mold_id);
                if (! $wo || ! $machine || ! $mold) {
                    throw new BusinessRuleException('A selected schedule references a missing work-order resource.');
                }
                if ($wo->status !== WorkOrderStatus::Planned) {
                    throw new BusinessRuleException("Work order {$wo->wo_number} is no longer planned.");
                }
                if ($reviews !== null) {
                    $review = collect($reviews)->firstWhere('schedule_id', $row->id);
                    $crew = app(WorkOrderAssignmentService::class)->currentCrew($wo);
                    if (! $review || ! $row->updated_at->equalTo(Carbon::parse($review['schedule_updated_at']))
                        || $crew?->id !== ($review['expected_crew_id'] ?? null)) {
                        throw new BusinessRuleException('Schedule or crew changed. Refresh and review before confirming.');
                    }
                }
                $this->assertScheduleWindowIsValid($row);
                $this->assertScheduleWindowAvailable($row, (int) $machine->id);
                $this->assertMoldWindowAvailable($row, (int) $mold->id);
                $this->workOrders->assertAssignmentValid($wo, $machine, $mold);

                // Hand off to WorkOrderService first. If reservations or any
                // later row fail, the surrounding transaction rolls back both
                // the WO status and the schedule status.
                $this->workOrders->confirm($wo, (int) $machine->id, (int) $mold->id);
                $row->update([
                    'status' => ProductionScheduleStatus::Confirmed->value,
                    'is_confirmed' => true,
                    'confirmed_by' => $confirmedBy,
                    'confirmed_at' => Carbon::now(),
                ]);
                $confirmed->push($row->fresh());
            }

            return $confirmed;
        });
    }

    public function reorder(int $scheduleId, int $newPriorityOrder): ProductionSchedule
    {
        if ($newPriorityOrder < 0 || $newPriorityOrder > 65535) {
            throw new BusinessRuleException('Schedule priority must be between 0 and 65535.');
        }

        return DB::transaction(function () use ($scheduleId, $newPriorityOrder) {
            $proposal = ProductionSchedule::where('status', ProductionScheduleStatus::Pending->value)->findOrFail($scheduleId);
            $related = ProductionSchedule::where('machine_id', $proposal->machine_id)->where('reserves_capacity', true)->get();
            WorkOrder::whereIn('id', $related->pluck('work_order_id'))->orderBy('id')->lockForUpdate()->get();
            Machine::whereKey($proposal->machine_id)->lockForUpdate()->firstOrFail();
            Mold::whereIn('id', $related->pluck('mold_id'))->orderBy('id')->lockForUpdate()->get();
            $row = ProductionSchedule::where('status', ProductionScheduleStatus::Pending->value)->where('reserves_capacity', true)->findOrFail($scheduleId);
            $row->update(['priority_order' => $newPriorityOrder]);
            $this->reflowMachineTimeline((int) $row->machine_id);

            return $row->fresh();
        });
    }

    public function reassign(int $scheduleId, int $machineId, int $moldId): ProductionSchedule
    {
        return DB::transaction(function () use ($scheduleId, $machineId, $moldId) {
            $row = ProductionSchedule::where('status', ProductionScheduleStatus::Pending->value)->findOrFail($scheduleId);
            $wo = WorkOrder::query()->lockForUpdate()->find($row->work_order_id);
            if (! $wo) {
                throw new BusinessRuleException('Work order for schedule not found.');
            }
            if ($wo->status !== WorkOrderStatus::Planned) {
                throw new BusinessRuleException("Work order {$wo->wo_number} is no longer planned.");
            }

            $machine = Machine::query()->lockForUpdate()->find($machineId);
            $mold = Mold::query()->lockForUpdate()->find($moldId);
            if (! $machine || ! $mold) {
                throw new BusinessRuleException('Selected machine or mold not found.');
            }

            $row = ProductionSchedule::where('status', ProductionScheduleStatus::Pending->value)->where('reserves_capacity', true)->lockForUpdate()->findOrFail($scheduleId);
            if ($wo->parent_wo_id || $wo->children()->exists()) {
                throw new BusinessRuleException('Dependency schedules must be replanned together before changing equipment.');
            }
            $row->scheduled_end = $row->scheduled_start->copy()->addMinutes(app(ProductionResourceService::class)->reservationMinutes($wo, $mold));
            app(ProductionResourceService::class)->assertReservation($wo, $machine, $mold, $row->scheduled_start, $row->scheduled_end, $row->id);
            $this->assertScheduleWindowIsValid($row);
            $this->assertScheduleWindowAvailable($row, (int) $machine->id);
            $this->assertMoldWindowAvailable($row, (int) $mold->id);
            $this->workOrders->assertAssignmentValid($wo, $machine, $mold);

            app(WorkOrderAssignmentService::class)->assertScheduledCrew($wo, $row);
            ProductionSchedule::query()->lockForUpdate()->findOrFail($row->id);
            $row->update(['machine_id' => $machine->id, 'mold_id' => $mold->id, 'scheduled_end' => $row->scheduled_end]);
            app(WoOperationService::class)->refreshPendingSchedule($wo);

            return $row->fresh();
        });
    }

    /** Current data determines readiness; historical rows never become new commitments. */
    public function readiness(ProductionSchedule $slot): array
    {
        $slot->loadMissing('workOrder', 'machine', 'mold');
        $wo = $slot->workOrder;
        $service = app(WorkOrderAssignmentService::class);
        $crew = $service->currentCrew($wo, $slot->rework_ncr_id);
        $forExecution = in_array($wo->status->value, ['confirmed', 'in_progress', 'paused'], true);
        $blockers = [];
        if ($slot->reserves_capacity && (! $forExecution || $slot->rework_ncr_id !== null)) {
            if ($slot->scheduled_end->lte(now())) {
                $blockers[] = 'Production slot expired; PPC or production management must review remaining timing.';
            }
            if (($slot->machine_id && ! $slot->machine) || ($slot->mold_id && ! $slot->mold)) {
                $blockers[] = 'Assigned equipment was archived. PPC must review the reservation.';
            } elseif ($slot->machine_id && $slot->mold_id) {
                try {
                    app(ProductionResourceService::class)->assertReservation($wo, $slot->machine, $slot->mold, $slot->scheduled_start, $slot->scheduled_end, $slot->id, $slot->rework_ncr_id, $forExecution);
                } catch (BusinessRuleException $e) {
                    $blockers[] = $e->getMessage();
                }
            }
            try {
                if ($wo->status->value === 'planned') {
                    $service->assertReadyForConfirmation($wo, $slot, false);
                }
            } catch (BusinessRuleException $error) {
                $blockers[] = $error->getMessage();
            }
        }

        $executionBlockers = [];
        if ($forExecution && $wo->status->value !== 'in_progress' && $slot->reserves_capacity && $slot->rework_ncr_id === null) {
            $execution = $this->workOrders->executionReadiness($wo);
            $executionBlockers = array_values(array_unique(array_column($execution['blockers'], 'message')));
        } elseif ($forExecution && $slot->reserves_capacity) {
            if ($wo->status->value === 'in_progress' && $slot->scheduled_end->lte(now())) {
                $executionBlockers[] = 'Run reservation ended. Open the WO and use Extend run to review remaining time.';
            }
            try {
                $service->assertReadyForStart($wo, $slot->rework_ncr_id, lock: false);
            } catch (BusinessRuleException $error) {
                $executionBlockers[] = $error->getMessage();
            }
            if ($wo->status->value !== 'in_progress') {
                try {
                    app(ProductionResourceService::class)->assertExecutionSlot($wo, $slot->machine, $slot->mold, $slot->rework_ncr_id);
                    if ($slot->machine && $slot->mold) {
                        app(ProductionResourceService::class)->assertCanAcquire($wo, $slot->machine, $slot->mold, $slot->rework_ncr_id);
                    }
                } catch (BusinessRuleException $error) {
                    $executionBlockers[] = $error->getMessage();
                }
            }
            if ($slot->scheduled_end->gt(now())) {
                try {
                    $service->assertScheduledCrew($wo, new ProductionSchedule(['scheduled_start' => now()->max($slot->scheduled_start), 'scheduled_end' => $slot->scheduled_end]), $slot->rework_ncr_id, true, false);
                } catch (BusinessRuleException $error) {
                    $executionBlockers[] = $error->getMessage();
                }
            }
            if ($slot->scheduled_start->lte(now()) && $crew) {
                foreach ($crew->members as $member) {
                    if ($member->employee) {
                        $presence = $service->presence($member->employee, now());
                        if ($presence['state'] === 'blocked') {
                            $executionBlockers[] = $member->employee->full_name.': '.$presence['reason'];
                        }
                    }
                }
            }
        }

        return ['execution_blockers' => $executionBlockers, 'worker_names' => $crew?->members->map(fn ($member) => $member->employee?->full_name)->filter()->values()->all() ?? [],
            'worker_count' => $crew?->members->count() ?? 0, 'minimum_workers' => $wo->minimum_workers,
            'expected_crew_id' => $crew?->hash_id, 'approval_blockers' => $wo->status->value === 'planned' ? array_values(array_unique($blockers)) : [],
            'recorder_name' => $crew?->members->firstWhere('employee_id', $crew->recorder_employee_id)?->employee?->full_name,
            'blockers' => array_values(array_unique($blockers))];
    }

    /**
     * Snapshot of machines + their pending/confirmed schedules within a window.
     * Used by the Gantt UI (Sprint 6 Task 54).
     *
     * @return array{from: string, to: string, rows: list<array>}
     */
    public function snapshot(Carbon $from, Carbon $to): array
    {
        if (! $from->lt($to)) {
            throw new BusinessRuleException('Gantt window start must be before its end.');
        }

        $machines = Machine::withTrashed()->with('currentWorkOrder')->orderBy('machine_code')->get();

        $rows = $machines->map(function ($m) use ($from, $to) {
            $bars = ProductionSchedule::with(['workOrder.product', 'workOrder.salesOrder', 'mold'])
                ->where('machine_id', $m->id)
                ->whereIn('status', [
                    ProductionScheduleStatus::Pending->value,
                    ProductionScheduleStatus::Confirmed->value,
                    ProductionScheduleStatus::Executed->value,
                ])
                // M050 — a job already running when the window opens must
                // still occupy its Gantt row: overlap, not containment.
                ->where('scheduled_start', '<', $to)
                ->where('scheduled_end', '>', $from)
                ->orderBy('scheduled_start')
                ->get()
                ->map(function ($s) {
                    return [
                        'id' => $s->hash_id,
                        'updated_at' => $s->updated_at->toISOString(),
                        'wo_id' => $s->workOrder?->hash_id,
                        'wo_number' => $s->workOrder?->wo_number,
                        'product_name' => $s->workOrder?->product?->name,
                        'product_part_number' => $s->workOrder?->product?->part_number,
                        'quantity_target' => (int) $s->workOrder?->quantity_target,
                        'sales_order' => $s->workOrder?->salesOrder ? [
                            'id' => $s->workOrder->salesOrder->hash_id,
                            'so_number' => $s->workOrder->salesOrder->so_number,
                        ] : null,
                        'mold_code' => $s->mold?->mold_code,
                        'start' => optional($s->scheduled_start)->toIso8601String(),
                        'end' => optional($s->scheduled_end)->toIso8601String(),
                        'status' => (string) $s->status?->value,
                        'wo_status' => (string) $s->workOrder?->status?->value,
                        'reserves_capacity' => (bool) $s->reserves_capacity,
                        'staffing' => $s->workOrder ? $this->readiness($s) : null,
                    ];
                });

            return [
                'machine_id' => $m->hash_id,
                'machine_code' => $m->machine_code,
                'name' => $m->name,
                'tonnage' => $m->tonnage,
                'status' => (string) $m->status?->value,
                'current_owner' => $m->currentWorkOrder ? ['id' => $m->currentWorkOrder->hash_id, 'wo_number' => $m->currentWorkOrder->wo_number] : null,
                'bars' => $bars->all(),
            ];
        });

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'timezone' => config('app.timezone'),
            'rows' => $rows->all(),
            'conflicts' => WorkOrder::where('status', WorkOrderStatus::Planned->value)->whereNotNull('scheduling_diagnostic')->get()->pluck('scheduling_diagnostic')->all(),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Internal — slot finder

    /**
     * Topologically order a selected set of work orders so every child is
     * placed before its parent. The query's priority ordering is preserved
     * for unrelated work orders and siblings.
     *
     * @param  Collection<int, WorkOrder>  $workOrders
     * @return Collection<int, WorkOrder>
     */
    private function orderWorkOrdersByDependencies(Collection $workOrders): Collection
    {
        $childrenByParent = $workOrders->groupBy(
            static fn (WorkOrder $wo): int => $wo->parent_wo_id ? (int) $wo->parent_wo_id : 0,
        );
        $visited = [];
        $visiting = [];
        $ordered = collect();

        $visit = function (WorkOrder $wo) use (&$visit, &$visited, &$visiting, &$ordered, $childrenByParent): void {
            $id = (int) $wo->id;
            if (isset($visited[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                throw new BusinessRuleException("Circular subassembly dependency detected at work order {$wo->wo_number}.");
            }

            $visiting[$id] = true;
            foreach ($childrenByParent->get($id, collect()) as $child) {
                $visit($child);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
            $ordered->push($wo);
        };

        foreach ($workOrders as $wo) {
            $visit($wo);
        }

        return $ordered;
    }

    /**
     * Index every active persisted window before placing new work orders.
     * Pending rows are deliberately included: a rerun must not overlap an
     * existing proposal until that proposal is replaced successfully.
     *
     * @return array<int, list<array{schedule_id:int, work_order_id:int, status:string, start:Carbon, end:Carbon}>>
     */
    private function loadScheduleWindows(): array
    {
        $windows = [];

        $schedules = ProductionSchedule::query()
            ->whereIn('status', self::BLOCKING_SCHEDULE_STATUSES)->where('reserves_capacity', true)
            ->orderBy('machine_id')
            ->orderBy('scheduled_start')
            ->get();

        foreach ($schedules as $schedule) {
            $start = $schedule->scheduled_start instanceof Carbon
                ? $schedule->scheduled_start->copy()
                : Carbon::parse($schedule->scheduled_start);
            $end = $schedule->scheduled_end instanceof Carbon
                ? $schedule->scheduled_end->copy()
                : Carbon::parse($schedule->scheduled_end);
            if (! $start->lt($end)) {
                throw new BusinessRuleException("Production schedule #{$schedule->id} has an invalid time window.");
            }

            $windows[(int) $schedule->machine_id][] = [
                'schedule_id' => (int) $schedule->id,
                'work_order_id' => (int) $schedule->work_order_id,
                'status' => (string) ($schedule->status?->value ?? $schedule->status),
                'start' => $start,
                'end' => $end,
            ];
        }

        foreach (MaintenanceWindow::confirmed()->where('maintainable_type', 'machine')->get() as $window) {
            $windows[(int) $window->maintainable_id][] = $this->maintenanceCapacityWindow($window);
        }

        return $windows;
    }

    private function maintenanceCapacityWindow(MaintenanceWindow $window): array
    {
        return ['schedule_id' => 0, 'work_order_id' => 0, 'status' => 'confirmed',
            'start' => $window->planned_start_at, 'end' => $window->planned_end_at];
    }

    /** Remove only old pending rows after a replacement has been selected. */
    private function removePendingWindowsForWorkOrder(array &$windows, int $workOrderId): void
    {
        foreach ($windows as $machineId => $entries) {
            $windows[$machineId] = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => ! (
                    $entry['work_order_id'] === $workOrderId
                    && $entry['status'] === ProductionScheduleStatus::Pending->value
                ),
            ));
            if ($windows[$machineId] === []) {
                unset($windows[$machineId]);
            }
        }
    }

    /** Add a newly persisted proposal to the in-memory conflict index. */
    private function addScheduleWindow(array &$windows, ProductionSchedule $schedule): void
    {
        $windows[(int) $schedule->machine_id][] = [
            'schedule_id' => (int) $schedule->id,
            'work_order_id' => (int) $schedule->work_order_id,
            'status' => ProductionScheduleStatus::Pending->value,
            'start' => Carbon::parse($schedule->scheduled_start),
            'end' => Carbon::parse($schedule->scheduled_end),
        ];
    }

    private function assertScheduleWindowIsValid(ProductionSchedule $row): void
    {
        $start = $row->scheduled_start instanceof Carbon
            ? $row->scheduled_start
            : Carbon::parse($row->scheduled_start);
        $end = $row->scheduled_end instanceof Carbon
            ? $row->scheduled_end
            : Carbon::parse($row->scheduled_end);
        if (! $start->lt($end)) {
            throw new BusinessRuleException("Production schedule #{$row->id} has an invalid time window.");
        }
    }

    /**
     * Reject a manual move/confirmation that would overlap another active
     * schedule on the target machine. Intervals are half-open, so an adjacent
     * schedule ending at the candidate start is valid.
     */
    private function assertScheduleWindowAvailable(ProductionSchedule $row, int $machineId): void
    {
        $conflict = ProductionSchedule::query()
            ->where('machine_id', $machineId)
            ->where('id', '!=', $row->id)
            ->whereIn('status', self::BLOCKING_SCHEDULE_STATUSES)->where('reserves_capacity', true)
            ->where('scheduled_start', '<', $row->scheduled_end)
            ->where('scheduled_end', '>', $row->scheduled_start)
            ->with('workOrder:id,wo_number')
            ->first();

        if ($conflict) {
            $number = $conflict->workOrder?->wo_number ?? "#{$conflict->work_order_id}";
            throw new BusinessRuleException(
                "Machine schedule overlaps work order {$number} in the requested time window."
            );
        }
    }

    /**
     * Return the first slot after $desiredStart that does not overlap an
     * existing window and does not push any single calendar day past the
     * machine's declared available_hours_per_day. A pending row belonging to
     * the WO being replanned is ignored; it is superseded only after a
     * replacement is found.
     *
     * @param  list<array{schedule_id:int, work_order_id:int, status:string, start:Carbon, end:Carbon}>  $windows
     * @return array{start:Carbon, end:Carbon}
     */
    private function nextFreeSlot(Carbon $desiredStart, int $durationMinutes, array $windows, int $workOrderId, int $dailyCapacityMinutes, ?Carbon $deadline = null, ?array $capacityWindows = null): array
    {
        usort($windows, static fn (array $left, array $right): int => $left['start']->getTimestamp() <=> $right['start']->getTimestamp());
        $start = $desiredStart->copy();

        $deadline ??= $desiredStart->copy()->addDays(90);
        $capacityWindows ??= $windows;
        while ($start->copy()->addMinutes($durationMinutes)->lte($deadline)) {
            $pushed = false;
            foreach ($windows as $window) {
                if ($window['work_order_id'] === $workOrderId
                    && $window['status'] === ProductionScheduleStatus::Pending->value && empty($window['rework_ncr_id'])) {
                    continue;
                }

                $end = $start->copy()->addMinutes($durationMinutes);
                if ($end->lessThanOrEqualTo($window['start'])) {
                    break;
                }
                if ($start->lessThan($window['end']) && $window['start']->lessThan($end)) {
                    $start = $window['end']->copy();
                    $pushed = true;
                    break;
                }
            }
            if ($pushed) {
                continue;
            }

            $end = $start->copy()->addMinutes($durationMinutes);
            $overCapacity = false;
            for ($day = $start->copy()->startOfDay(); $day->lt($end); $day->addDay()) {
                $next = $day->copy()->addDay();
                $dayBooked = $start->copy()->max($day)->diffInMinutes($end->copy()->min($next), true);
                foreach ($capacityWindows as $window) {
                    if ($window['work_order_id'] === $workOrderId && $window['status'] === 'pending' && empty($window['rework_ncr_id'])) {
                        continue;
                    }
                    if ($window['start']->lt($next) && $window['end']->gt($day)) {
                        $dayBooked += $window['start']->copy()->max($day)->diffInMinutes($window['end']->copy()->min($next), true);
                    }
                }
                if ($dayBooked > $dailyCapacityMinutes) {
                    $start = $next;
                    $overCapacity = true;
                    break;
                }
            }
            if ($overCapacity) {
                continue;
            }
            break;
        }

        if ($start->copy()->addMinutes($durationMinutes)->gt($deadline)) {
            throw new BusinessRuleException('No equipment capacity remains before the planning deadline. PPC must review.');
        }

        return ['start' => $start, 'end' => $start->copy()->addMinutes($durationMinutes)];
    }

    private function workerCandidateStarts(WorkOrder $wo, Carbon $from, Carbon $deadline): array
    {
        $dates = [$from->getTimestamp() => $from->copy()];
        $until = $deadline->copy()->min($from->copy()->addDays(90));
        $workers = app(WorkOrderAssignmentService::class)->productionEmployees()->get();
        $assignments = EmployeeShiftAssignment::whereIn('employee_id', $workers->pluck('id'))->with('shift')->get();
        // ponytail: bounded shift-boundary search; optimize indexing if factory planning volume makes this scan expensive.
        for ($day = $from->copy()->startOfDay()->subDay(); $day->lte($until); $day->addDay()) {
            foreach ($assignments as $assignment) {
                if (! $assignment->shift?->is_active || ! is_array($assignment->working_days)
                    || ! in_array($day->dayOfWeekIso, $assignment->working_days, true)
                    || $assignment->effective_date->gt($day) || ($assignment->end_date && $assignment->end_date->lt($day))) {
                    continue;
                }
                $start = Carbon::parse($day->toDateString().' '.$assignment->shift->start_time, config('app.timezone'));
                if ($start->gte($from) && $start->lt($until)) {
                    $dates[$start->getTimestamp()] = $start;
                }
            }
        }
        foreach (ProductionSchedule::where('reserves_capacity', true)->where('scheduled_end', '>', $from)->where('scheduled_end', '<', $until)->get() as $slot) {
            $dates[$slot->scheduled_end->getTimestamp()] = $slot->scheduled_end;
        }
        foreach (MaintenanceWindow::confirmed()->where('planned_end_at', '>', $from)->where('planned_end_at', '<', $until)->get() as $window) {
            $dates[$window->planned_end_at->getTimestamp()] = $window->planned_end_at;
        }
        ksort($dates);

        return array_values($dates);
    }

    /**
     * Try to place one WO. Returns ['ok'=>bool, ...] with placement details
     * or reasons array.
     *
     * M050 invariants enforced here, per (mold, machine) candidate:
     *   - never book into the past (desired start is clamped to now()),
     *   - never exceed the machine's declared available_hours_per_day on a
     *     single calendar day,
     *   - never promise a slot whose end misses the WO planned_end due date,
     *   - never book one mold on two machines at overlapping times,
     *   - never promise more shots than the mold's remaining rated life,
     *     accumulated across the whole run.
     *
     * @param  array<int, Carbon>  $dependencyReadyAt
     */
    private function placeWorkOrder(
        WorkOrder $wo,
        array &$blockedWindowsByMachine,
        array $dependencyReadyAt,
        array $options = [],
    ): array {
        $ncrId = $options['rework_ncr_id'] ?? null;
        $resources = app(ProductionResourceService::class);
        $quantity = $resources->remainingQuantity($wo, $ncrId);
        $reasons = [];
        $best = null;
        $bestKey = null;
        if (! $ncrId && $wo->planned_end && $wo->planned_end->lte(now())) {
            return ['ok' => false, 'reasons' => ['Planning deadline expired. Review WO deadline and delivery impact.']];
        }
        $preferred = array_key_exists('preferred_production_date', $options)
            ? $options['preferred_production_date'] : ($ncrId ? null : $wo->preferred_production_date?->toDateString());
        if (! $ncrId && $preferred !== null && $wo->planned_end
            && Carbon::parse($preferred, config('app.timezone'))->startOfDay()->gte($wo->planned_end)) {
            return ['ok' => false, 'reasons' => ['Choose a day before production due: '.$wo->planned_end->format('M j, Y · g:i A').'.']];
        }

        $desiredStart = $ncrId ? now()->startOfMinute() : now();
        if (! $ncrId && $wo->mrp_plan_id === null && $wo->planned_start?->gt($desiredStart)) {
            $desiredStart = $wo->planned_start->copy();
        }
        if ($preferred !== null) {
            $desiredStart = $desiredStart->max(Carbon::parse($preferred, config('app.timezone'))->startOfDay());
        }
        $dependencyStart = $dependencyReadyAt[(int) $wo->id] ?? null;
        if ($dependencyStart && $dependencyStart->gt($desiredStart)) {
            $desiredStart = $dependencyStart->copy();
        }
        $deadline = ! $ncrId && $wo->planned_end ? $wo->planned_end->copy()->min($desiredStart->copy()->addDays(90)) : $desiredStart->copy()->addDays(90);
        $candidateStarts = $this->workerCandidateStarts($wo, $desiredStart, $deadline);
        $eligibleByWindow = [];
        $crewService = app(WorkOrderAssignmentService::class);
        $crew = $crewService->currentCrew($wo, $ncrId);

        if ($ncrId && ($options['manual_repair'] ?? false)) {
            foreach ($candidateStarts as $candidate) {
                $end = $candidate->copy()->addMinutes(120);
                if ($end->gt($deadline)) {
                    continue;
                }
                $suggestion = $crewService->suggest($wo, new ProductionSchedule(['scheduled_start' => $candidate, 'scheduled_end' => $end]), $ncrId, $options);
                if ($suggestion['blockers'] === []) {
                    return ['ok' => true, 'start' => $candidate->toDateTimeString(), 'end' => $end->toDateTimeString()];
                }
            }

            return ['ok' => false, 'reasons' => ['No eligible repair crew covers a continuous two-hour shift. Review HR shifts, leave, and assignments.']];
        }

        $compatibleMolds = Mold::where('product_id', $wo->product_id)
            ->whereIn('status', [MoldStatus::Available->value, MoldStatus::InUse->value])
            ->with('compatibleMachines')
            ->get()
            ->filter(function (Mold $mold) use ($wo, $quantity, $ncrId, &$reasons): bool {
                try {
                    app(ProductionResourceService::class)->assertMoldLife($mold, $quantity, $wo->id, $ncrId);

                    return true;
                } catch (BusinessRuleException $error) {
                    $reasons[] = $error->getMessage();

                    return false;
                }
            })
            ->values();

        if ($compatibleMolds->isEmpty()) {
            return ['ok' => false, 'reasons' => array_values(array_unique($reasons)) ?: ['No compatible mold has capacity. Review mold compatibility and maintenance.']];
        }

        // Earliest start wins; tonnage and IDs only break ties.
        foreach ($compatibleMolds as $mold) {
            $machines = $mold->compatibleMachines
                ->filter(static fn (Machine $machine): bool => in_array(
                    $machine->status,
                    [MachineStatus::Idle, MachineStatus::Running],
                    true,
                ))
                ->sortBy(static fn (Machine $machine): string => sprintf(
                    '%010d-%010d',
                    $machine->tonnage ?? PHP_INT_MAX,
                    $machine->id,
                ))
                ->values();
            if ($machines->isEmpty()) {
                $reasons[] = "mold {$mold->mold_code}: no compatible machine available";

                continue;
            }

            foreach ($machines as $machine) {
                $durationMinutes = app(ProductionResourceService::class)->reservationMinutes($wo, $mold, $quantity);

                $dailyCapacityMinutes = $this->dailyCapacityMinutes($machine);
                if ($dailyCapacityMinutes === null) {
                    $reasons[] = "machine {$machine->machine_code}: daily capacity is missing";

                    continue;
                }
                $machineWindows = $blockedWindowsByMachine[$machine->id] ?? [];
                $moldWindows = ProductionSchedule::query()->where('mold_id', $mold->id)->where('reserves_capacity', true)->where(fn ($q) => $ncrId ? $q->where('work_order_id', '!=', $wo->id)->orWhereNull('rework_ncr_id')->orWhere('rework_ncr_id', '!=', $ncrId) : $q->where('work_order_id', '!=', $wo->id))->get()->map(fn ($row) => ['schedule_id' => $row->id, 'work_order_id' => $ncrId ? 0 : $row->work_order_id, 'rework_ncr_id' => $row->rework_ncr_id, 'status' => $row->status->value, 'start' => $row->scheduled_start, 'end' => $row->scheduled_end])->all();
                foreach (MaintenanceWindow::confirmed()->where('maintainable_type', 'mold')->where('maintainable_id', $mold->id)->get() as $window) {
                    $moldWindows[] = $this->maintenanceCapacityWindow($window);
                }
                try {
                    $slot = null;
                    foreach ($candidateStarts as $candidate) {
                        $proposed = $this->nextFreeSlot($candidate, $durationMinutes, array_merge($machineWindows, $moldWindows), (int) $wo->id, $dailyCapacityMinutes, $deadline, $machineWindows);
                        $windowKey = $proposed['start']->toDateTimeString().'|'.$proposed['end']->toDateTimeString();
                        $eligible = $eligibleByWindow[$windowKey] ??= $crewService->eligibleWorkers($wo, $proposed['start'], $proposed['end'], $ncrId);
                        if ($eligible->count() < max(1, (int) ($options['minimum_workers'] ?? $wo->minimum_workers))
                            || (! $ncrId && $crew && ! $crew->members->every(fn ($member) => $eligible->contains('id', $member->employee_id)))) {
                            continue;
                        }
                        if ($ncrId && $crewService->suggest($wo, new ProductionSchedule(['scheduled_start' => $proposed['start'], 'scheduled_end' => $proposed['end']]), $ncrId, $options)['blockers'] !== []) {
                            continue;
                        }
                        if ($crew && ! $ncrId) {
                            $crewService->assertScheduledCrew($wo, new ProductionSchedule(['scheduled_start' => $proposed['start'], 'scheduled_end' => $proposed['end']]), $ncrId);
                        }
                        app(ProductionResourceService::class)->assertReservation($wo, $machine, $mold, $proposed['start'], $proposed['end'], ncrId: $ncrId, allowLate: $ncrId !== null);
                        $slot = $proposed;
                        break;
                    }
                    if (! $slot) {
                        throw new BusinessRuleException('Not enough eligible Production workers cover one continuous shift before the deadline. Review HR working days, leave, worker count and assignments.');
                    }
                } catch (BusinessRuleException $e) {
                    $reasons[] = $e->getMessage();

                    continue;
                }

                $key = [$slot['start']->getTimestamp(), $slot['end']->getTimestamp(),
                    $machine->tonnage ?? PHP_INT_MAX, $machine->id, $mold->id];
                if ($bestKey === null || $key < $bestKey) {
                    $bestKey = $key;
                    $best = ['ok' => true, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
                        'start' => $slot['start']->toDateTimeString(), 'end' => $slot['end']->toDateTimeString()];
                }
            }
        }

        if ($best !== null) {
            return $best;
        }

        return ['ok' => false, 'reasons' => array_values(array_unique($reasons)) ?: ['no_capacity_in_horizon']];
    }

    /**
     * Declared daily capacity of a machine in minutes, or null when the
     * machine has no declared hours (legacy rows) — the day-budget checks
     * are then skipped rather than blocking everything.
     */
    private function dailyCapacityMinutes(Machine $machine): ?int
    {
        $hours = (float) ($machine->available_hours_per_day ?? 0);
        if ($hours <= 0) {
            return null;
        }

        return (int) round($hours * 60);
    }

    /**
     * True when another active schedule uses the same mold on a different
     * machine during the proposed window. Rows belonging to the WO being
     * (re)placed are ignored — they are superseded right after placement.
     *
     * @param  array{start: Carbon, end: Carbon}  $slot
     */
    private function moldWindowConflict(Mold $mold, Machine $machine, array $slot, int $workOrderId): bool
    {
        return ProductionSchedule::query()
            ->where('mold_id', $mold->id)
            ->where('machine_id', '!=', $machine->id)
            ->where('work_order_id', '!=', $workOrderId)
            ->whereIn('status', self::BLOCKING_SCHEDULE_STATUSES)->where('reserves_capacity', true)
            ->where('scheduled_start', '<', $slot['end'])
            ->where('scheduled_end', '>', $slot['start'])
            ->exists();
    }

    /**
     * Reject a manual move/confirmation that would double-book one physical
     * mold on two machines at overlapping times.
     */
    private function assertMoldWindowAvailable(ProductionSchedule $row, int $moldId): void
    {
        $conflict = ProductionSchedule::query()
            ->where('mold_id', $moldId)
            ->where('id', '!=', $row->id)
            ->whereIn('status', self::BLOCKING_SCHEDULE_STATUSES)->where('reserves_capacity', true)
            ->where('scheduled_start', '<', $row->scheduled_end)
            ->where('scheduled_end', '>', $row->scheduled_start)
            ->with('workOrder:id,wo_number')
            ->first();

        if ($conflict) {
            $number = $conflict->workOrder?->wo_number ?? "#{$conflict->work_order_id}";
            throw new BusinessRuleException(
                "Mold is already booked on another machine for work order {$number} in the requested time window."
            );
        }
    }

    /**
     * Recompute the pending timeline of one machine after a manual priority
     * change, so the Gantt sequence actually reflects the new priority_order.
     * Confirmed/executed rows are immutable anchors; pending rows are packed
     * in priority order around them.
     *
     * The new plan is applied as one delete + re-insert (ids preserved): a
     * step-by-step in-place update of a swap would trip the
     * production_schedules_no_overlap exclusion constraint mid-transition,
     * since the two rows briefly occupy each other's windows.
     */
    private function reflowMachineTimeline(int $machineId): void
    {
        $machine = Machine::query()->find($machineId);
        if (! $machine) {
            return;
        }
        $dailyCapacityMinutes = $this->dailyCapacityMinutes($machine);
        if ($dailyCapacityMinutes === null) {
            throw new BusinessRuleException('Machine daily capacity is missing. PPC must configure available hours.');
        }

        $anchors = ProductionSchedule::where('machine_id', $machineId)
            ->where('reserves_capacity', true)->whereIn('status', [
                ProductionScheduleStatus::Confirmed->value,
                ProductionScheduleStatus::Executed->value,
            ])
            ->orderBy('scheduled_start')
            ->get();
        $pending = ProductionSchedule::where('machine_id', $machineId)
            ->where('status', ProductionScheduleStatus::Pending->value)->where('reserves_capacity', true)
            ->orderBy('priority_order')
            ->orderBy('scheduled_start')
            ->orderBy('id')
            ->get();
        if ($pending->isEmpty()) {
            return;
        }

        $windows = $anchors->map(static fn (ProductionSchedule $row): array => [
            'schedule_id' => (int) $row->id,
            'work_order_id' => (int) $row->work_order_id,
            'status' => ProductionScheduleStatus::Confirmed->value,
            'start' => Carbon::parse($row->scheduled_start),
            'end' => Carbon::parse($row->scheduled_end),
        ])->all();

        foreach (MaintenanceWindow::confirmed()->where('maintainable_type', 'machine')->where('maintainable_id', $machineId)->get() as $window) {
            $windows[] = $this->maintenanceCapacityWindow($window);
        }

        app(WorkOrderAssignmentService::class)->productionEmployees()->orderBy('id')->lockForUpdate()->get();

        // Reflow from the machine's earliest pending commitment: reordering
        // must not drag a job earlier than the earliest slot already promised.
        $cursor = $pending->reduce(static function (?Carbon $carry, ProductionSchedule $row): Carbon {
            $start = Carbon::parse($row->scheduled_start);

            return $carry === null || $start->lt($carry) ? $start : $carry;
        }, null);
        if ($cursor && $cursor->lt(Carbon::now())) {
            $cursor = Carbon::now();
        }

        $plan = [];
        foreach ($pending as $row) {
            // The row's window is valid (start < end), so the duration is a
            // magnitude.
            $durationMinutes = (int) max(1, Carbon::parse($row->scheduled_start)->diffInMinutes(Carbon::parse($row->scheduled_end), true));
            $wo = WorkOrder::findOrFail($row->work_order_id);
            $earliest = $cursor->copy();
            if ($wo->mrp_plan_id === null && $wo->planned_start?->gt($earliest)) {
                $earliest = $wo->planned_start->copy();
            }
            if ($wo->preferred_production_date) {
                $earliest = $earliest->max($wo->preferred_production_date->copy()->startOfDay());
            }
            $moldWindows = MaintenanceWindow::confirmed()->where('maintainable_type', 'mold')->where('maintainable_id', $row->mold_id)
                ->get()->map(fn ($window) => $this->maintenanceCapacityWindow($window))->all();
            $slot = $this->nextFreeSlot($earliest, $durationMinutes, array_merge($windows, $moldWindows), (int) $row->work_order_id, $dailyCapacityMinutes, $wo->planned_end, $windows);
            if ($wo->planned_end && $slot['end']->gt($wo->planned_end)) {
                throw new BusinessRuleException('Reordering would miss WO '.$wo->wo_number.' deadline.');
            }
            $mold = Mold::findOrFail($row->mold_id);
            app(ProductionResourceService::class)->assertCompatibility($wo, $machine, $mold);
            app(ProductionResourceService::class)->assertMoldLife($mold, max(0, (int) $wo->quantity_target - (int) $wo->quantity_good), $wo->id);
            if ($durationMinutes < app(ProductionResourceService::class)->durationMinutes($wo, $mold)) {
                throw new BusinessRuleException('Mold capacity changed; regenerate the production proposal before reordering.');
            }
            if ($this->moldWindowConflict($mold, $machine, $slot, $wo->id)) {
                throw new BusinessRuleException('Reordering conflicts with another mold reservation.');
            }
            if ($wo->parent_wo_id || $wo->children()->exists()) {
                throw new BusinessRuleException('Dependency schedules must be replanned together before changing priority.');
            }
            $crewService = app(WorkOrderAssignmentService::class);
            $crew = $crewService->currentCrew($wo);
            if ($crewService->eligibleWorkers($wo, $slot['start'], $slot['end'])->count() < max(1, $wo->minimum_workers)) {
                throw new BusinessRuleException('Not enough eligible workers cover reordered WO '.$wo->wo_number.'. Review HR coverage and timing.');
            }
            if ($crew) {
                $crewService->assertScheduledCrew($wo, new ProductionSchedule(['scheduled_start' => $slot['start'], 'scheduled_end' => $slot['end']]));
            }
            $plan[] = [
                'id' => (int) $row->id,
                'work_order_id' => (int) $row->work_order_id,
                'mold_id' => (int) $row->mold_id,
                'priority_order' => (int) $row->priority_order,
                'start' => $slot['start'],
                'end' => $slot['end'],
            ];
            $windows[] = [
                'schedule_id' => (int) $row->id,
                'work_order_id' => (int) $row->work_order_id,
                'status' => ProductionScheduleStatus::Pending->value,
                'start' => $slot['start'],
                'end' => $slot['end'],
            ];
            $cursor = $slot['end'];
        }

        $now = Carbon::now();
        ProductionSchedule::where('machine_id', $machineId)
            ->where('status', ProductionScheduleStatus::Pending->value)->where('reserves_capacity', true)
            ->delete();
        foreach ($plan as $row) {
            DB::table('production_schedules')->insert([
                'id' => $row['id'],
                'work_order_id' => $row['work_order_id'],
                'machine_id' => $machineId,
                'mold_id' => $row['mold_id'],
                'scheduled_start' => $row['start'],
                'scheduled_end' => $row['end'],
                'priority_order' => $row['priority_order'],
                'status' => ProductionScheduleStatus::Pending->value,
                'is_confirmed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            app(WoOperationService::class)->refreshPendingSchedule(WorkOrder::findOrFail($row['work_order_id']));
        }
    }

    private function scheduleSummary(ProductionSchedule $row, WorkOrder $wo): array
    {
        return [
            'id' => $row->hash_id,
            'work_order_id' => $wo->hash_id,
            'wo_number' => $wo->wo_number,
            'machine_id' => app('hashids')->encode((int) $row->machine_id),
            'mold_id' => app('hashids')->encode((int) $row->mold_id),
            'scheduled_start' => optional($row->scheduled_start)->toIso8601String(),
            'scheduled_end' => optional($row->scheduled_end)->toIso8601String(),
            'priority_order' => (int) $row->priority_order,
            'status' => (string) $row->status?->value,
        ];
    }
}
