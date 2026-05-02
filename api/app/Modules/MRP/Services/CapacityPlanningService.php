<?php

declare(strict_types=1);

namespace App\Modules\MRP\Services;

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
use RuntimeException;

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
            $workOrders = $q->orderByDesc('priority')->orderBy('planned_start')->get();

            // Track machine end-of-last-job per machine for the simulation.
            $machineCursor = []; // [machine_id => Carbon]
            $scheduled = [];
            $conflicts = [];

            foreach ($workOrders as $wo) {
                $placement = $this->placeWorkOrder($wo, $machineCursor);
                if ($placement['ok']) {
                    // Supersede any existing pending schedule for this WO.
                    ProductionSchedule::where('work_order_id', $wo->id)
                        ->where('status', ProductionScheduleStatus::Pending->value)
                        ->update(['status' => ProductionScheduleStatus::Superseded->value]);

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
                    $machineCursor[$placement['machine_id']] = $placement['end'];
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
            $rows = ProductionSchedule::whereIn('id', $scheduleIds)
                ->where('status', ProductionScheduleStatus::Pending->value)
                ->lockForUpdate()
                ->get();

            $confirmed = collect();
            foreach ($rows as $row) {
                $row->update([
                    'status'       => ProductionScheduleStatus::Confirmed->value,
                    'is_confirmed' => true,
                    'confirmed_by' => $confirmedBy,
                    'confirmed_at' => Carbon::now(),
                ]);
                // Hand off to WorkOrderService — it sets machine + mold and
                // flips status to 'confirmed' (which Sprint 5 reservation
                // hooks will see once wired).
                $wo = WorkOrder::lockForUpdate()->find($row->work_order_id);
                if ($wo && $wo->status === WorkOrderStatus::Planned) {
                    $this->workOrders->confirm($wo, $row->machine_id, $row->mold_id);
                }
                $confirmed->push($row->fresh());
            }
            return $confirmed;
        });
    }

    public function reorder(int $scheduleId, int $newPriorityOrder): ProductionSchedule
    {
        $row = ProductionSchedule::where('status', ProductionScheduleStatus::Pending->value)
            ->findOrFail($scheduleId);
        $row->update(['priority_order' => $newPriorityOrder]);
        return $row->fresh();
    }

    public function reassign(int $scheduleId, int $machineId, int $moldId): ProductionSchedule
    {
        $row = ProductionSchedule::where('status', ProductionScheduleStatus::Pending->value)
            ->findOrFail($scheduleId);
        $row->update(['machine_id' => $machineId, 'mold_id' => $moldId]);
        return $row->fresh();
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
     * Try to place one WO. Returns ['ok'=>bool, ...] with placement details
     * or reasons array.
     */
    private function placeWorkOrder(WorkOrder $wo, array &$machineCursor): array
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
                ->whereIn('status', [MachineStatus::Idle->value, MachineStatus::Running->value])
                ->sortBy('tonnage')
                ->values();
            if ($machines->isEmpty()) {
                $reasons[] = "mold {$mold->mold_code}: no compatible machine available";
                continue;
            }

            foreach ($machines as $machine) {
                $duration = (float) $wo->quantity_target / max(1, (int) $mold->output_rate_per_hour)
                          + ((int) $mold->setup_time_minutes / 60.0);
                $hours = max(0.5, $duration); // minimum 30 minutes

                $start = $machineCursor[$machine->id] ?? Carbon::parse($wo->planned_start);
                if ($start->lt(Carbon::parse($wo->planned_start))) {
                    $start = Carbon::parse($wo->planned_start);
                }
                $end = $start->copy()->addMinutes((int) round($hours * 60));

                return [
                    'ok'         => true,
                    'machine_id' => $machine->id,
                    'mold_id'    => $mold->id,
                    'start'      => $start->toDateTimeString(),
                    'end'        => $end->toDateTimeString(),
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
