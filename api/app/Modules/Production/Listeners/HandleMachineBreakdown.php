<?php

declare(strict_types=1);

namespace App\Modules\Production\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Common\Services\OutboxService;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Events\MachineStatusChanged;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Events\MachineBreakdownDetected;
use App\Modules\Production\Models\MachineDowntime;
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
 *  - Notification fan-out + alternative-machine list are handled downstream
 *    by NotifyOnMachineBreakdown; this listener only handles the WO pause
 *    and machine-downtime row lifecycle.
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
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'skipped',
            'machine_transition_not_actionable',
        );
    }

    private function handleEnteringBreakdown(MachineStatusChanged $event): void
    {
        $machine = null;
        $pausedWo = null;
        $candidates = [];
        $outcomeCode = 'machine_missing_or_not_in_breakdown';

        DB::transaction(function () use ($event, &$machine, &$pausedWo, &$candidates, &$outcomeCode): void {
            $machine = Machine::query()
                ->lockForUpdate()
                ->find($event->machine->id);
            if (! $machine) return;

            // The event may have waited in the queue while an operator
            // restored or reassigned the machine. Only the authoritative
            // breakdown state may pause the current work order.
            if ($machine->status !== MachineStatus::Breakdown) return;
            $outcomeCode = 'breakdown_published_without_running_work_order';

            $woId = $machine->current_work_order_id;
            if ($woId) {
                $wo = WorkOrder::query()->lockForUpdate()->find($woId);
                if ($wo && $wo->status === WorkOrderStatus::InProgress) {
                    $this->workOrders->pause(
                        $wo,
                        $event->reason ?? 'Machine breakdown',
                        MachineDowntimeCategory::Breakdown,
                    );

                    // WorkOrderService::pause releases a machine to idle for
                    // ordinary pauses. A breakdown must remain unavailable
                    // until the explicit restoration transition closes its
                    // downtime row.
                    $machine->refresh();
                    $machine->update([
                        'status' => MachineStatus::Breakdown->value,
                    ]);
                    $pausedWo = $wo->fresh();
                    $outcomeCode = 'work_order_paused_for_breakdown';
                }
            }

            // Surface compatible idle machines in the same transaction as the
            // pause, then persist the broadcast event in the outbox. The
            // dashboard alert can now be replayed after a worker outage.
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

            app(OutboxService::class)->record(
                new MachineBreakdownDetected(
                    $machine->fresh(),
                    $pausedWo,
                    $candidates,
                    $event->reason,
                ),
            );

            $outcomeCode .= '_and_alert_staged';
        });

        if (! $machine || $outcomeCode === 'machine_missing_or_not_in_breakdown') {
            app(ChainListenerRunService::class)->recordOutcome('skipped', $outcomeCode);
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            $outcomeCode,
            $pausedWo
                ? "Paused work order {$pausedWo->wo_number} for machine breakdown."
                : 'Recorded machine breakdown and staged the recovery alert.',
        );
    }

    private function handleRestoration(MachineStatusChanged $event): void
    {
        $machine = null;
        $closed = 0;
        DB::transaction(function () use ($event, &$machine, &$closed): void {
            $machine = Machine::query()
                ->lockForUpdate()
                ->find($event->machine->id);
            if (! $machine) return;

            // A restoration event can be stale by the time a worker handles
            // it. Do not close a downtime row while the machine is still in
            // breakdown/maintenance (or has moved to another state).
            if (! in_array($machine->status, [MachineStatus::Idle, MachineStatus::Running], true)) {
                return;
            }

            // Close any open downtime rows for this machine.
            MachineDowntime::where('machine_id', $machine->id)
                ->whereNull('end_time')
                ->lockForUpdate()
                ->get()
                ->each(function ($row) use (&$closed): void {
                    $end = now();
                    $row->update([
                        'end_time'         => $end,
                        'duration_minutes' => (int) max(0, $row->start_time->diffInMinutes($end, true)),
                    ]);
                    $closed++;
                });
        });

        if (! $machine) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'machine_missing');
            return;
        }
        if ($closed === 0) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'no_open_machine_downtime');
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            'machine_downtime_closed',
            "Closed {$closed} machine downtime row(s) after restoration.",
        );
    }
}
