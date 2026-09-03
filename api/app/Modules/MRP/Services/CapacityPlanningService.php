<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Enums\MoldStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 6 — Task 53. MRP II capacity planner.
 *
 * Algorithm (priority-first greedy):
 *  1. Take all 'planned' work orders (or a subset by id).
 *  2. Sort by dependency order (children before parents), then priority desc
 *     and planned_start asc within each dependency branch.
 *  3. For each WO:
 *       a. Find compatible molds: product_id matches AND status IN
 *          (available, in_use) AND current_shot_count + qty <= max_shots.
 *       b. For each mold, find compatible machines (via mold_machine_compatibility).
 *       c. Pick the smallest-tonnage compatible machine that does not yet have
 *          a "pending" or "confirmed" schedule row that overlaps the proposed
 *          slot. Slots stack starting at WO.planned_start, advancing as
 *          earlier WOs claim time on the same machine.
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
        private readonly \App\Modules\Production\Services\WorkOrderService $workOrders,
    ) {}

    /**
     * Propose schedules for WOs in 'planned' state.
     * Persists pending rows in production_schedules; supersedes any prior
     * pending rows for the same WO.
     *
     * @return array{scheduled: list<array>, conflicts: list<array>}
     */
    public function run(?array $workOrderIds = null): array
    {
        return DB::transaction(function () use ($workOrderIds) {
            $q = WorkOrder::query()
                ->where('status', WorkOrderStatus::Planned->value)
                ->with('product');
            if ($workOrderIds) {
                $q->whereIn('id', $workOrderIds);
            }
            $workOrders = $q
                ->orderByDesc('priority')
                ->orderBy('planned_start')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($workOrders->isEmpty()) {
                return ['scheduled' => [], 'conflicts' => []];
            }

            $workOrders = $this->orderWorkOrdersByDependencies($workOrders);

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

            $blockedWindowsByMachine = $this->loadScheduleWindows();

            // Track machine end-of-last-job per machine for the simulation.
            $machineCursor = []; // [machine_id => Carbon]
            $dependencyReadyAt = []; // [parent_work_order_id => latest child end]
            $dependencyFailed = []; // [work_order_id => true]
            // M050 — mold rated-shot-life accounting across the WHOLE run,
            // not per WO. Three 900-shot WOs each pass the single-WO filter
            // but together exceed a 1000-shot remaining life; the run must
            // refuse the over-budget ones instead of promising them.
            $moldBookedShots = []; // [mold_id => shots promised so far]
            $scheduled = [];
            $conflicts = [];

            foreach ($workOrders as $wo) {
                if (isset($dependencyFailed[(int) $wo->id])) {
                    $conflicts[] = [
                        'work_order_id' => $wo->hash_id,
                        'wo_number' => $wo->wo_number,
                        'reasons' => ['subassembly_dependency_unscheduled'],
                    ];
                    if ($wo->parent_wo_id) {
                        $dependencyFailed[(int) $wo->parent_wo_id] = true;
                    }
                    continue;
                }

                $placement = $this->placeWorkOrder(
                    $wo,
                    $machineCursor,
                    $blockedWindowsByMachine,
                    $dependencyReadyAt,
                    $moldBookedShots,
                );
                if ($placement['ok']) {
                    // Supersede any existing pending schedule for this WO.
                    ProductionSchedule::where('work_order_id', $wo->id)
                        ->where('status', ProductionScheduleStatus::Pending->value)
                        ->update(['status' => ProductionScheduleStatus::Superseded->value]);

                    // The old pending row was kept in the blocked-window index
                    // while this WO was being placed. Remove it only after a
                    // replacement has been successfully selected.
                    $this->removePendingWindowsForWorkOrder($blockedWindowsByMachine, (int) $wo->id);

                    $row = ProductionSchedule::create([
                        'work_order_id'   => $wo->id,
                        'machine_id'      => $placement['machine_id'],
                        'mold_id'         => $placement['mold_id'],
                        'scheduled_start' => $placement['start'],
                        'scheduled_end'   => $placement['end'],
                        'priority_order'  => $wo->priority ?? 0,
                        'status'          => ProductionScheduleStatus::Pending->value,
                        'is_confirmed'    => false,
                    ]);
                    $machineCursor[$placement['machine_id']] = Carbon::parse($placement['end']);
                    $moldId = (int) $placement['mold_id'];
                    $moldBookedShots[$moldId] = ((int) ($moldBookedShots[$moldId] ?? 0)) + (int) $wo->quantity_target;
                    $this->addScheduleWindow($blockedWindowsByMachine, $row);
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
                        'wo_number'     => $wo->wo_number,
                        'reasons'       => $placement['reasons'],
                    ];
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
    public function confirm(array $scheduleIds, int $confirmedBy): Collection
    {
        return DB::transaction(function () use ($scheduleIds, $confirmedBy) {
            $requestedIds = array_values(array_unique(array_map('intval', $scheduleIds)));
            if ($requestedIds === []) {
                return collect();
            }

            $rows = ProductionSchedule::whereIn('id', $scheduleIds)
                ->where('status', ProductionScheduleStatus::Pending->value)
                ->orderBy('machine_id')
                ->orderBy('id')
                ->lockForUpdate()
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
                $this->assertScheduleWindowIsValid($row);
                $this->assertScheduleWindowAvailable($row, (int) $machine->id);
                $this->assertMoldWindowAvailable($row, (int) $mold->id);
                $this->workOrders->assertAssignmentValid($wo, $machine, $mold);

                // Hand off to WorkOrderService first. If reservations or any
                // later row fail, the surrounding transaction rolls back both
                // the WO status and the schedule status.
                $this->workOrders->confirm($wo, (int) $machine->id, (int) $mold->id);
                $row->update([
                    'status'       => ProductionScheduleStatus::Confirmed->value,
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
            $row = ProductionSchedule::where('status', ProductionScheduleStatus::Pending->value)
                ->lockForUpdate()
                ->findOrFail($scheduleId);
            $row->update(['priority_order' => $newPriorityOrder]);
            $this->reflowMachineTimeline((int) $row->machine_id);
            return $row->fresh();
        });
    }

    public function reassign(int $scheduleId, int $machineId, int $moldId): ProductionSchedule
    {
        return DB::transaction(function () use ($scheduleId, $machineId, $moldId) {
            $row = ProductionSchedule::where('status', ProductionScheduleStatus::Pending->value)
                ->lockForUpdate()
                ->findOrFail($scheduleId);
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

            $this->assertScheduleWindowIsValid($row);
            $this->assertScheduleWindowAvailable($row, (int) $machine->id);
            $this->assertMoldWindowAvailable($row, (int) $mold->id);
            $this->workOrders->assertAssignmentValid($wo, $machine, $mold);

            $row->update(['machine_id' => $machine->id, 'mold_id' => $mold->id]);
            return $row->fresh();
        });
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

        $machines = Machine::withTrashed()->orderBy('machine_code')->get();

        $rows = $machines->map(function ($m) use ($from, $to) {
            $bars = ProductionSchedule::with(['workOrder.product', 'mold'])
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
                        'id'           => $s->hash_id,
                        'wo_id'        => $s->workOrder?->hash_id,
                        'wo_number'    => $s->workOrder?->wo_number,
                        'product_name' => $s->workOrder?->product?->name,
                        'mold_code'    => $s->mold?->mold_code,
                        'start'        => optional($s->scheduled_start)->toIso8601String(),
                        'end'          => optional($s->scheduled_end)->toIso8601String(),
                        'status'       => (string) $s->status?->value,
                        'wo_status'    => (string) $s->workOrder?->status?->value,
                    ];
                });

            return [
                'machine_id'   => $m->hash_id,
                'machine_code' => $m->machine_code,
                'name'         => $m->name,
                'tonnage'      => $m->tonnage,
                'status'       => (string) $m->status?->value,
                'bars'         => $bars->all(),
            ];
        });

        return [
            'from' => $from->toIso8601String(),
            'to'   => $to->toIso8601String(),
            'rows' => $rows->all(),
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // Internal — slot finder

    /**
     * Topologically order a selected set of work orders so every child is
     * placed before its parent. The query's priority ordering is preserved
     * for unrelated work orders and siblings.
     *
     * @param Collection<int, WorkOrder> $workOrders
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
            ->whereIn('status', self::BLOCKING_SCHEDULE_STATUSES)
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

        return $windows;
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
            ->whereIn('status', self::BLOCKING_SCHEDULE_STATUSES)
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
     * @param list<array{schedule_id:int, work_order_id:int, status:string, start:Carbon, end:Carbon}> $windows
     * @return array{start:Carbon, end:Carbon}
     */
    private function nextFreeSlot(Carbon $desiredStart, int $durationMinutes, array $windows, int $workOrderId, int $dailyCapacityMinutes): array
    {
        usort($windows, static fn (array $left, array $right): int => $left['start']->getTimestamp() <=> $right['start']->getTimestamp());
        $start = $desiredStart->copy();

        while (true) {
            $pushed = false;
            foreach ($windows as $window) {
                if ($window['work_order_id'] === $workOrderId
                    && $window['status'] === ProductionScheduleStatus::Pending->value) {
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

            // M050 — never book a machine beyond its declared daily capacity.
            // Only single-day slots are checked: the planner refuses jobs
            // longer than one day's capacity up front, so a slot here never
            // needs to straddle midnight.
            $end = $start->copy()->addMinutes($durationMinutes);
            if ($start->toDateString() === $end->toDateString()) {
                $dayBooked = 0;
                $dayStart = $start->copy()->startOfDay();
                $dayEnd = $dayStart->copy()->addDay();
                foreach ($windows as $window) {
                    if ($window['start']->lt($dayEnd) && $window['end']->gt($dayStart)) {
                        $overlapStart = $window['start']->gt($dayStart) ? $window['start'] : $dayStart->copy();
                        $overlapEnd = $window['end']->lt($dayEnd) ? $window['end'] : $dayEnd->copy();
                        // overlapStart < overlapEnd by construction (the window
                        // overlaps this day), so this is a magnitude.
                        $dayBooked += (int) $overlapStart->diffInMinutes($overlapEnd, true);
                    }
                }
                if ($dayBooked + $durationMinutes > $dailyCapacityMinutes) {
                    $start = $start->copy()->addDay()->setTime((int) $start->format('H'), (int) $start->format('i'));
                    continue;
                }
            }
            break;
        }

        return [
            'start' => $start,
            'end' => $start->copy()->addMinutes($durationMinutes),
        ];
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
     * @param array<int, Carbon> $dependencyReadyAt
     * @param array<int, int> $moldBookedShots
     */
    private function placeWorkOrder(
        WorkOrder $wo,
        array &$machineCursor,
        array &$blockedWindowsByMachine,
        array $dependencyReadyAt,
        array $moldBookedShots,
    ): array
    {
        $reasons = [];

        $compatibleMolds = Mold::where('product_id', $wo->product_id)
            ->whereIn('status', [MoldStatus::Available->value, MoldStatus::InUse->value])
            ->with('compatibleMachines')
            ->get()
            ->filter(function (Mold $mold) use ($wo, $moldBookedShots): bool {
                $promised = (int) $mold->current_shot_count
                    + ((int) ($moldBookedShots[(int) $mold->id] ?? 0))
                    + (int) $wo->quantity_target;
                return $promised <= (int) $mold->max_shots_before_maintenance;
            })
            ->values();

        if ($compatibleMolds->isEmpty()) {
            return ['ok' => false, 'reasons' => ['no_mold_with_capacity']];
        }

        // Try each (mold, machine) pair. Prefer smallest tonnage to spread
        // load away from the high-tonnage machines.
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
                $duration = (float) $wo->quantity_target / max(1, (int) $mold->output_rate_per_hour)
                          + ((int) $mold->setup_time_minutes / 60.0);
                $durationMinutes = (int) round(max(0.5, $duration) * 60); // minimum 30 minutes

                $dailyCapacityMinutes = $this->dailyCapacityMinutes($machine);
                if ($dailyCapacityMinutes !== null && $durationMinutes > $dailyCapacityMinutes) {
                    // A job longer than one full day's capacity cannot be
                    // promised: the plan has no shift-splitting model, and a
                    // single window longer than the day's declared hours
                    // would book the machine beyond its capacity.
                    $reasons[] = "mold {$mold->mold_code} / machine {$machine->machine_code}: job exceeds daily capacity";
                    continue;
                }

                $plannedStart = Carbon::parse($wo->planned_start);
                $cursor = $machineCursor[$machine->id] ?? null;
                $desiredStart = $cursor && $cursor->gt($plannedStart)
                    ? $cursor->copy()
                    : $plannedStart;
                $dependencyStart = $dependencyReadyAt[(int) $wo->id] ?? null;
                if ($dependencyStart && $dependencyStart->gt($desiredStart)) {
                    $desiredStart = $dependencyStart->copy();
                }
                // Never book a window that has already elapsed.
                if ($desiredStart->lt(Carbon::now())) {
                    $desiredStart = Carbon::now();
                }

                $slot = $this->nextFreeSlot(
                    $desiredStart,
                    $durationMinutes,
                    $blockedWindowsByMachine[$machine->id] ?? [],
                    (int) $wo->id,
                    $dailyCapacityMinutes ?? 1440,
                );

                // The due date is a hard promise: a slot ending after it
                // must conflict rather than over-promise.
                if ($wo->planned_end !== null && $slot['end']->gt(Carbon::parse($wo->planned_end))) {
                    $reasons[] = "mold {$mold->mold_code} / machine {$machine->machine_code}: would miss planned_end";
                    continue;
                }

                // One physical mold cannot run on two machines at once. The
                // machine-window index above only covers the candidate
                // machine, so consult the persisted plan for other machines.
                if ($this->moldWindowConflict($mold, $machine, $slot, (int) $wo->id)) {
                    $reasons[] = "mold {$mold->mold_code} is already booked on another machine in this window";
                    continue;
                }

                return [
                    'ok'         => true,
                    'machine_id' => $machine->id,
                    'mold_id'    => $mold->id,
                    'start'      => $slot['start']->toDateTimeString(),
                    'end'        => $slot['end']->toDateTimeString(),
                ];
            }
        }

        return ['ok' => false, 'reasons' => $reasons ?: ['no_capacity_in_horizon']];
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
     * @param array{start: Carbon, end: Carbon} $slot
     */
    private function moldWindowConflict(Mold $mold, Machine $machine, array $slot, int $workOrderId): bool
    {
        return ProductionSchedule::query()
            ->where('mold_id', $mold->id)
            ->where('machine_id', '!=', $machine->id)
            ->where('work_order_id', '!=', $workOrderId)
            ->whereIn('status', self::BLOCKING_SCHEDULE_STATUSES)
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
            ->whereIn('status', self::BLOCKING_SCHEDULE_STATUSES)
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
        $dailyCapacityMinutes = $this->dailyCapacityMinutes($machine) ?? 1440;

        $anchors = ProductionSchedule::where('machine_id', $machineId)
            ->whereIn('status', [
                ProductionScheduleStatus::Confirmed->value,
                ProductionScheduleStatus::Executed->value,
            ])
            ->orderBy('scheduled_start')
            ->get();
        $pending = ProductionSchedule::where('machine_id', $machineId)
            ->where('status', ProductionScheduleStatus::Pending->value)
            ->orderBy('priority_order')
            ->orderBy('scheduled_start')
            ->orderBy('id')
            ->get();
        if ($pending->isEmpty()) {
            return;
        }

        $windows = $anchors->map(static fn (ProductionSchedule $row): array => [
            'schedule_id'   => (int) $row->id,
            'work_order_id' => (int) $row->work_order_id,
            'status'        => ProductionScheduleStatus::Confirmed->value,
            'start'         => Carbon::parse($row->scheduled_start),
            'end'           => Carbon::parse($row->scheduled_end),
        ])->all();

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
            $slot = $this->nextFreeSlot($cursor->copy(), $durationMinutes, $windows, (int) $row->work_order_id, $dailyCapacityMinutes);
            $plan[] = [
                'id'             => (int) $row->id,
                'work_order_id'  => (int) $row->work_order_id,
                'mold_id'        => (int) $row->mold_id,
                'priority_order' => (int) $row->priority_order,
                'start'          => $slot['start'],
                'end'            => $slot['end'],
            ];
            $windows[] = [
                'schedule_id'   => (int) $row->id,
                'work_order_id' => (int) $row->work_order_id,
                'status'        => ProductionScheduleStatus::Pending->value,
                'start'         => $slot['start'],
                'end'           => $slot['end'],
            ];
            $cursor = $slot['end'];
        }

        $now = Carbon::now();
        ProductionSchedule::where('machine_id', $machineId)
            ->where('status', ProductionScheduleStatus::Pending->value)
            ->delete();
        foreach ($plan as $row) {
            DB::table('production_schedules')->insert([
                'id'              => $row['id'],
                'work_order_id'   => $row['work_order_id'],
                'machine_id'      => $machineId,
                'mold_id'         => $row['mold_id'],
                'scheduled_start' => $row['start'],
                'scheduled_end'   => $row['end'],
                'priority_order'  => $row['priority_order'],
                'status'          => ProductionScheduleStatus::Pending->value,
                'is_confirmed'    => false,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    private function scheduleSummary(ProductionSchedule $row, WorkOrder $wo): array
    {
        return [
            'id'              => $row->hash_id,
            'work_order_id'   => $wo->hash_id,
            'wo_number'       => $wo->wo_number,
            'machine_id'      => $row->machine_id,
            'mold_id'         => $row->mold_id,
            'scheduled_start' => optional($row->scheduled_start)->toIso8601String(),
            'scheduled_end'   => optional($row->scheduled_end)->toIso8601String(),
            'priority_order'  => (int) $row->priority_order,
            'status'          => (string) $row->status?->value,
        ];
    }
}
