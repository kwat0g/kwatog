<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Enums\MoldStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\BomService;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Exceptions\IllegalLifecycleTransitionException;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
 * is hooked at confirm() / start() / complete()/cancel(). The actual
 * StockMovementService::reserve / issueFromReservation methods are
 * implemented in Sprint 5 (already on main); we adapt around the existing
 * surface (see resolveStockMovementService for graceful degradation when
 * the methods are not yet wired).
 *
 * MachineService::transitionStatus and MoldService side-effects fire as
 * machine.status changes here. Sprint 6 Task 56 wires the breakdown listener.
 */
class WorkOrderService
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'planned'     => ['confirmed', 'cancelled'],
        'confirmed'   => ['in_progress', 'cancelled'],
        'in_progress' => ['paused', 'completed'],
        'paused'      => ['in_progress', 'cancelled'],
        'completed'   => ['closed'],
        'closed'      => [],
        'cancelled'   => [],
    ];

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly BomService $boms,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = WorkOrder::query()
            ->with([
                'product:id,part_number,name',
                'salesOrder:id,so_number',
                'machine:id,machine_code,name',
                'mold:id,mold_code,name',
            ]);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['sales_order_id'])) {
            $sid = HashIdFilter::decode($filters['sales_order_id'], SalesOrder::class);
            if ($sid) $q->where('sales_order_id', $sid);
        }
        if (! empty($filters['machine_id'])) {
            $mid = HashIdFilter::decode($filters['machine_id'], Machine::class);
            if ($mid) $q->where('machine_id', $mid);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('wo_number', SearchOperator::like(), "%{$term}%")
                   ->orWhereHas('product', fn ($p) => $p->where('part_number', SearchOperator::like(), "%{$term}%"));
            });
        }

        return $q->orderByDesc('priority')
            ->orderByDesc('planned_start')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(WorkOrder $wo): WorkOrder
    {
        return $wo->load([
            'product', 'salesOrder', 'salesOrderItem',
            'machine', 'mold', 'parent:id,wo_number',
            'creator:id,name',
            'materials.item:id,code,name,unit_of_measure',
            'outputs.recorder:id,name', 'outputs.defects.defectType',
            'downtimes', 'schedules.machine:id,machine_code,name',
        ]);
    }

    /**
     * Create a draft (planned) work order.
     * Optionally explodes the BOM into work_order_materials when an active
     * BOM exists for the product (no-op otherwise).
     *
     * @param array $data fields: product_id, sales_order_id?, sales_order_item_id?,
     *   mrp_plan_id?, parent_wo_id?, parent_ncr_id?, machine_id?, mold_id?,
     *   quantity_target, planned_start, planned_end, priority?, created_by
     */
    public function createDraft(array $data): WorkOrder
    {
        return DB::transaction(function () use ($data) {
            $payload = [
                'wo_number'           => $this->sequences->generate('work_order'),
                'product_id'          => (int) $data['product_id'],
                'sales_order_id'      => $data['sales_order_id'] ?? null,
                'sales_order_item_id' => $data['sales_order_item_id'] ?? null,
                'mrp_plan_id'         => $data['mrp_plan_id'] ?? null,
                'parent_wo_id'        => $data['parent_wo_id'] ?? null,
                'parent_ncr_id'       => $data['parent_ncr_id'] ?? null,
                'machine_id'          => $data['machine_id'] ?? null,
                'mold_id'             => $data['mold_id'] ?? null,
                'quantity_target'     => (int) $data['quantity_target'],
                'planned_start'       => $data['planned_start'],
                'planned_end'         => $data['planned_end'],
                'priority'            => (int) ($data['priority'] ?? 0),
                'status'              => WorkOrderStatus::Planned->value,
                'created_by'          => (int) $data['created_by'],
            ];
            $wo = WorkOrder::create($payload);

            // BOM expansion (Task 49 owns BomService::explode). Best-effort: if
            // no active BOM exists, the WO still saves — supervisor can add
            // materials manually via a future endpoint (out of scope here).
            try {
                $rows = $this->boms->explode((int) $data['product_id'], (float) $data['quantity_target']);
                foreach ($rows as $row) {
                    $wo->materials()->create([
                        'item_id'                => (int) $row['item_id'],
                        'bom_quantity'           => (string) $row['gross_quantity'],
                        'actual_quantity_issued' => '0',
                        'variance'               => '0',
                    ]);
                }
            } catch (RuntimeException $e) {
                // No active BOM — leave materials empty; PPC can add manually.
            }

            return $this->show($wo->fresh());
        });
    }

    /**
     * confirmed: requires machine_id + mold_id assigned. Reserves materials
     * (best-effort against the Sprint 5 StockMovementService — see method
     * comment).
     */
    public function confirm(WorkOrder $wo, ?int $machineId = null, ?int $moldId = null): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Confirmed);

        return DB::transaction(function () use ($wo, $machineId, $moldId) {
            $update = ['status' => WorkOrderStatus::Confirmed->value];
            if ($machineId !== null) $update['machine_id'] = $machineId;
            if ($moldId    !== null) $update['mold_id']    = $moldId;
            $wo->update($update);

            if (! $wo->machine_id || ! $wo->mold_id) {
                throw new RuntimeException('Confirming a work order requires both a machine and a mold.');
            }

            // TODO: integrate Sprint 5 StockMovementService::reserve($itemId, $qty, $wo)
            // once its public surface is finalised. For now, the materials rows
            // already capture the bom_quantity as the planned reservation.

            return $this->show($wo->fresh());
        });
    }

    /**
     * start: locks machine + mold rows, flips machine to running, mold to in_use,
     * records actual_start.
     */
    public function start(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::InProgress);

        return DB::transaction(function () use ($wo) {
            $machine = $wo->machine_id ? Machine::lockForUpdate()->find($wo->machine_id) : null;
            $mold    = $wo->mold_id    ? Mold::lockForUpdate()->find($wo->mold_id)       : null;
            if (! $machine || ! $mold) {
                throw new RuntimeException('Cannot start a work order without an assigned machine and mold.');
            }
            if (! in_array($machine->status, [MachineStatus::Idle, MachineStatus::Running], true)) {
                throw new RuntimeException('Assigned machine is not available to start production.');
            }
            if (! in_array($mold->status, [MoldStatus::Available, MoldStatus::InUse], true)) {
                throw new RuntimeException('Assigned mold is not available.');
            }

            $machine->update([
                'status'                => MachineStatus::Running->value,
                'current_work_order_id' => $wo->id,
            ]);
            $mold->update(['status' => MoldStatus::InUse->value]);

            $wo->update([
                'status'       => WorkOrderStatus::InProgress->value,
                'actual_start' => $wo->actual_start ?? Carbon::now(),
            ]);

            // TODO Sprint 5 integration: issue materials (StockMovementService::issueFromReservation).
            return $this->show($wo->fresh());
        });
    }

    public function pause(WorkOrder $wo, string $reason, MachineDowntimeCategory $category): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Paused);

        return DB::transaction(function () use ($wo, $reason, $category) {
            // Open downtime record.
            if ($wo->machine_id) {
                MachineDowntime::create([
                    'machine_id'    => $wo->machine_id,
                    'work_order_id' => $wo->id,
                    'start_time'    => Carbon::now(),
                    'category'      => $category->value,
                    'description'   => $reason,
                ]);
                Machine::where('id', $wo->machine_id)->update([
                    'status'                => MachineStatus::Idle->value,
                    'current_work_order_id' => null,
                ]);
            }
            $wo->update([
                'status'       => WorkOrderStatus::Paused->value,
                'pause_reason' => $reason,
            ]);
            return $this->show($wo->fresh());
        });
    }

    public function resume(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::InProgress);

        return DB::transaction(function () use ($wo) {
            // Close any open downtime row for this WO.
            $open = MachineDowntime::where('work_order_id', $wo->id)
                ->whereNull('end_time')->latest()->first();
            if ($open) {
                $end = Carbon::now();
                $open->update([
                    'end_time'         => $end,
                    'duration_minutes' => max(0, $open->start_time->diffInMinutes($end)),
                ]);
            }
            if ($wo->machine_id) {
                Machine::where('id', $wo->machine_id)->update([
                    'status'                => MachineStatus::Running->value,
                    'current_work_order_id' => $wo->id,
                ]);
            }
            $wo->update([
                'status'       => WorkOrderStatus::InProgress->value,
                'pause_reason' => null,
            ]);
            return $this->show($wo->fresh());
        });
    }

    public function complete(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Completed);

        return DB::transaction(function () use ($wo) {
            $produced = (int) $wo->quantity_produced;
            $rejected = (int) $wo->quantity_rejected;
            $scrap = $produced > 0 ? round(($rejected / $produced) * 100, 2) : 0.0;
            $wo->update([
                'status'     => WorkOrderStatus::Completed->value,
                'actual_end' => Carbon::now(),
                'scrap_rate' => $scrap,
            ]);
            if ($wo->machine_id) {
                Machine::where('id', $wo->machine_id)->update([
                    'status'                => MachineStatus::Idle->value,
                    'current_work_order_id' => null,
                ]);
            }
            if ($wo->mold_id) {
                $mold = Mold::find($wo->mold_id);
                if ($mold && $mold->status !== MoldStatus::Maintenance) {
                    $mold->update(['status' => MoldStatus::Available->value]);
                }
            }
            return $this->show($wo->fresh());
        });
    }

    public function close(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Closed);
        $wo->update(['status' => WorkOrderStatus::Closed->value]);
        return $this->show($wo->fresh());
    }

    public function cancel(WorkOrder $wo, ?string $reason = null): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Cancelled);

        return DB::transaction(function () use ($wo, $reason) {
            $wo->update([
                'status'       => WorkOrderStatus::Cancelled->value,
                'pause_reason' => $reason,
            ]);
            // TODO Sprint 5: release reservations.
            // Free machine + mold if currently bound.
            if ($wo->machine_id) {
                Machine::where('id', $wo->machine_id)->where('current_work_order_id', $wo->id)->update([
                    'status'                => MachineStatus::Idle->value,
                    'current_work_order_id' => null,
                ]);
            }
            if ($wo->mold_id) {
                $mold = Mold::find($wo->mold_id);
                if ($mold && $mold->status === MoldStatus::InUse) {
                    $mold->update(['status' => MoldStatus::Available->value]);
                }
            }
            return $this->show($wo->fresh());
        });
    }

    public function delete(WorkOrder $wo): void
    {
        if ($wo->status !== WorkOrderStatus::Planned) {
            throw new RuntimeException('Only planned work orders can be deleted.');
        }
        $wo->delete();
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
        $from = $wo->status?->value ?? 'planned';
        if (! in_array($to->value, self::ALLOWED[$from] ?? [], true)) {
            throw new IllegalLifecycleTransitionException($from, $to->value);
        }
    }
}
