<?php

declare(strict_types=1);

namespace App\Modules\Production\Listeners;

use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 6 — Task 56. Fires on machine status transitions.
 *
 * On from!=breakdown → to=breakdown:
 *  - Pause the running WO (if any) via WorkOrderService::pause; this opens
 *    a MachineDowntime row tagged Breakdown.
 *  - Notification + alternative-machine suggestion list is left as a TODO
 *    for a follow-up PR (the data is queryable today via the snapshot
 *    endpoint and the mold-machine compatibility join).
 *
 * On from IN (breakdown, maintenance) → to IN (idle, running):
 *  - Close any open machine_downtimes row for the machine (sets end_time
 *    + duration_minutes).
 *
 * Implements ShouldQueue so the broadcast event publish doesn't block the
 * status-transition request — but uses the sync queue in tests.
 */
class HandleMachineBreakdown implements ShouldQueue
{
    public function __construct(private readonly WorkOrderService $workOrders) {}

    public function handle(MachineStatusChanged $event): void
    {
        $from = $event->from;
        $to   = $event->to;

        if ($from !== MachineStatus::Breakdown->value && $to === MachineStatus::Breakdown->value) {
            $this->handleEnteringBreakdown($event);
            return;
        }

        if (in_array($from, [MachineStatus::Breakdown->value, MachineStatus::Maintenance->value], true)
            && in_array($to, [MachineStatus::Idle->value, MachineStatus::Running->value], true)
        ) {
            $this->handleRestoration($event);
        }
    }

    private function handleEnteringBreakdown(MachineStatusChanged $event): void
    {
        $machine = Machine::find($event->machine->id);
        if (! $machine) return;

        $pausedWo = null;

        DB::transaction(function () use ($machine, $event, &$pausedWo) {
            $woId = $machine->current_work_order_id;
            if ($woId) {
                $wo = WorkOrder::find($woId);
                if ($wo && $wo->status === WorkOrderStatus::InProgress) {
                    $this->workOrders->pause(
                        $wo,
                        $event->reason ?? 'Machine breakdown',
                        MachineDowntimeCategory::Breakdown,
                    );
                    $pausedWo = $wo->fresh();
                }
            }
        });

        // Sprint 6 audit §1.8: surface candidate machines and broadcast the
        // breakdown event so the dashboard's BreakdownAlertCard updates and
        // PPC heads can drag a paused WO to a different machine.
        $candidates = [];
        if ($pausedWo && $pausedWo->mold_id) {
            $candidates = Machine::where('status', 'idle')
                ->whereHas('compatibleMolds', fn ($q) => $q->where('id', $pausedWo->mold_id))
                ->get(['id', 'machine_code', 'name'])
                ->map(fn ($m) => [
                    'id'           => $m->hash_id,
                    'machine_code' => $m->machine_code,
                    'name'         => $m->name,
                ])
                ->values()
                ->all();
        }

        DB::afterCommit(function () use ($machine, $pausedWo, $candidates, $event) {
            event(new \App\Modules\Production\Events\MachineBreakdownDetected(
                $machine->fresh(),
                $pausedWo,
                $candidates,
                $event->reason,
            ));
        });
    }

    private function handleRestoration(MachineStatusChanged $event): void
    {
        DB::transaction(function () use ($event) {
            // Close any open downtime rows for this machine.
            \App\Modules\Production\Models\MachineDowntime::where('machine_id', $event->machine->id)
                ->whereNull('end_time')
                ->get()
                ->each(function ($row) {
                    $end = now();
                    $row->update([
                        'end_time'         => $end,
                        'duration_minutes' => max(0, $row->start_time->diffInMinutes($end)),
                    ]);
                });
        });
    }
}
