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
 *  2. Sort by priority desc, then planned_start asc.
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
            $scheduled = [];
            $conflicts = [];

            foreach ($workOrders as $wo) {
                $placement = $this->placeWorkOrder($wo, $machineCursor, $blockedWindowsByMachine);
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
                    $this->addScheduleWindow($blockedWindowsByMachine, $row);
                    $scheduled[] = $this->scheduleSummary($row, $wo);
                } else {
                    $conflicts[] = [
                        'work_order_id' => $wo->hash_id,
                        'wo_number'     => $wo->wo_number,
                        'reasons'       => $placement['reasons'],
                    ];
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

            $confirmed = collect();
            foreach ($rows as $row) {
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
        $machines = Machine::orderBy('machine_code')->get();

        $rows = $machines->map(function ($m) use ($from, $to) {
            $bars = ProductionSchedule::with(['workOrder.product', 'mold'])
                ->where('machine_id', $m->id)
                ->whereIn('status', [
                    ProductionScheduleStatus::Pending->value,
                    ProductionScheduleStatus::Confirmed->value,
                    ProductionScheduleStatus::Executed->value,
                ])
                ->whereBetween('scheduled_start', [$from, $to])
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
     * existing window. A pending row belonging to the WO being replanned is
     * ignored; it is superseded only after a replacement is found.
     *
     * @param list<array{schedule_id:int, work_order_id:int, status:string, start:Carbon, end:Carbon}> $windows
     * @return array{start:Carbon, end:Carbon}
     */
    private function nextFreeSlot(Carbon $desiredStart, int $durationMinutes, array $windows, int $workOrderId): array
    {
        usort($windows, static fn (array $left, array $right): int => $left['start']->getTimestamp() <=> $right['start']->getTimestamp());
        $start = $desiredStart->copy();

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
            }
        }

        return [
            'start' => $start,
            'end' => $start->copy()->addMinutes($durationMinutes),
        ];
    }

    /**
     * Try to place one WO. Returns ['ok'=>bool, ...] with placement details
     * or reasons array.
     */
    private function placeWorkOrder(WorkOrder $wo, array &$machineCursor, array &$blockedWindowsByMachine): array
    {
        $reasons = [];

        $compatibleMolds = Mold::where('product_id', $wo->product_id)
            ->whereIn('status', [MoldStatus::Available->value, MoldStatus::InUse->value])
            ->whereRaw('current_shot_count + ? <= max_shots_before_maintenance', [(int) $wo->quantity_target])
            ->with('compatibleMachines')
            ->get();

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

                $plannedStart = Carbon::parse($wo->planned_start);
                $cursor = $machineCursor[$machine->id] ?? null;
                $desiredStart = $cursor && $cursor->gt($plannedStart)
                    ? $cursor->copy()
                    : $plannedStart;
                $slot = $this->nextFreeSlot(
                    $desiredStart,
                    $durationMinutes,
                    $blockedWindowsByMachine[$machine->id] ?? [],
                    (int) $wo->id,
                );

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
