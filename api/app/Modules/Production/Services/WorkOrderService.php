<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
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
use App\Modules\Production\Models\WorkOrderMaterial;
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
        private readonly StockMovementService $stock,
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
     * via StockMovementService::reserve() — one MaterialReservation row per
     * (item, location) selection. If any material has insufficient stock the
     * whole transaction rolls back and the WO stays planned.
     *
     * Sprint 6 audit §1.1: this previously only stamped the status change.
     * Now it actually reserves stock.
     */
    public function confirm(WorkOrder $wo, ?int $machineId = null, ?int $moldId = null): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Confirmed);
        $from = $wo->status?->value ?? 'planned';

        $result = DB::transaction(function () use ($wo, $machineId, $moldId) {
            $update = ['status' => WorkOrderStatus::Confirmed->value];
            if ($machineId !== null) $update['machine_id'] = $machineId;
            if ($moldId    !== null) $update['mold_id']    = $moldId;
            $wo->update($update);
            $wo->refresh();

            if (! $wo->machine_id || ! $wo->mold_id) {
                throw new RuntimeException('Confirming a work order requires both a machine and a mold.');
            }

            $this->reserveMaterialsFor($wo);

            return $this->show($wo->fresh());
        });
        $this->broadcastStatusChange($result, $from, WorkOrderStatus::Confirmed->value);
        return $result;
    }

    /**
     * start: locks machine + mold rows, flips machine to running, mold to in_use,
     * records actual_start, and issues the previously-reserved materials.
     *
     * Sprint 6 audit §1.1: this previously only flipped statuses. Now it
     * also releases reservations and creates MaterialIssue stock movements
     * via StockMovementService.
     */
    public function start(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::InProgress);
        $from = $wo->status?->value ?? 'confirmed';

        $result = DB::transaction(function () use ($wo) {
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

            // Issue reserved materials. Best-effort: if no reservation exists
            // (e.g. legacy WOs that were confirmed before the audit fix), the
            // WO still starts — material_issue rows just won't be created.
            $this->issueReservedMaterials($wo, (int) ($wo->creator?->id ?? $wo->created_by));

            return $this->show($wo->fresh());
        });
        $this->broadcastStatusChange($result, $from, WorkOrderStatus::InProgress->value);
        return $result;
    }

    public function pause(WorkOrder $wo, string $reason, MachineDowntimeCategory $category): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Paused);
        $from = $wo->status?->value ?? 'in_progress';

        $result = DB::transaction(function () use ($wo, $reason, $category) {
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
        $this->broadcastStatusChange($result, $from, WorkOrderStatus::Paused->value, $reason);
        return $result;
    }

    public function resume(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::InProgress);
        $from = $wo->status?->value ?? 'paused';

        $result = DB::transaction(function () use ($wo) {
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
        $this->broadcastStatusChange($result, $from, WorkOrderStatus::InProgress->value);
        return $result;
    }

    public function complete(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Completed);
        $from = $wo->status?->value ?? 'in_progress';

        $result = DB::transaction(function () use ($wo) {
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
        $this->broadcastStatusChange($result, $from, WorkOrderStatus::Completed->value);
        return $result;
    }

    public function close(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Closed);
        $from = $wo->status?->value ?? 'completed';
        $wo->update(['status' => WorkOrderStatus::Closed->value]);
        $result = $this->show($wo->fresh());
        $this->broadcastStatusChange($result, $from, WorkOrderStatus::Closed->value);
        return $result;
    }

    public function cancel(WorkOrder $wo, ?string $reason = null): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Cancelled);
        $from = $wo->status?->value ?? 'planned';

        $result = DB::transaction(function () use ($wo, $reason) {
            $wo->update([
                'status'       => WorkOrderStatus::Cancelled->value,
                'pause_reason' => $reason,
            ]);

            // Sprint 6 audit §1.1: release any reservations held by this WO.
            $this->releaseReservedMaterials($wo);

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
        $this->broadcastStatusChange($result, $from, WorkOrderStatus::Cancelled->value, $reason);
        return $result;
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

    /**
     * Sprint 6 audit §1.7: dispatch the WorkOrderStatusChanged broadcast
     * event after a successful lifecycle transition. Always invoked from
     * outside the DB::transaction so listeners and Reverb consumers see
     * the persisted row.
     */
    private function broadcastStatusChange(WorkOrder $wo, string $from, string $to, ?string $reason = null): void
    {
        if ($from === $to) return;
        event(new \App\Modules\Production\Events\WorkOrderStatusChanged($wo, $from, $to, $reason));
    }

    /**
     * Reserve every BOM line of $wo. For each material, pick the location
     * with the largest available stock; if the chosen location can't cover
     * the BOM quantity, the StockMovementService will throw and the parent
     * transaction rolls back.
     *
     * Locations are locked for update so concurrent confirms don't race the
     * same on-hand pool.
     */
    private function reserveMaterialsFor(WorkOrder $wo): void
    {
        $wo->loadMissing('materials');
        foreach ($wo->materials as $material) {
            $needed = (string) $material->bom_quantity;
            if (bccomp($needed, '0', 3) <= 0) continue;

            $locationId = $this->bestLocationForItem(
                (int) $material->item_id,
                $needed,
            );
            if ($locationId === null) {
                // No single location has enough; let StockMovementService::reserve
                // throw on the largest-stock location for a clear error message.
                $locationId = $this->largestLocationForItem((int) $material->item_id);
                if ($locationId === null) {
                    throw new RuntimeException(
                        "No stock available for item {$material->item_id} (work order {$wo->wo_number})."
                    );
                }
            }

            $this->stock->reserve((int) $material->item_id, $locationId, $needed);

            MaterialReservation::create([
                'item_id'       => (int) $material->item_id,
                'work_order_id' => $wo->id,
                'location_id'   => $locationId,
                'quantity'      => $needed,
                'status'        => ReservationStatus::Reserved->value,
                'reserved_at'   => Carbon::now(),
            ]);
        }
    }

    /**
     * Convert each Reserved MaterialReservation into an Issued one by
     * (a) releasing the reservation, (b) recording a MaterialIssue stock
     * movement, and (c) bumping the matching work_order_materials row's
     * actual_quantity_issued counter. All within the start() transaction.
     */
    private function issueReservedMaterials(WorkOrder $wo, int $userId): void
    {
        $reservations = MaterialReservation::where('work_order_id', $wo->id)
            ->where('status', ReservationStatus::Reserved->value)
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $res) {
            if ($res->location_id === null) continue;

            $qty = (string) $res->quantity;
            // Release first so the move()'s availability check sees the
            // freed stock as on-hand-available.
            $this->stock->release((int) $res->item_id, (int) $res->location_id, $qty);

            $this->stock->move(new StockMovementInput(
                type:           StockMovementType::MaterialIssue,
                itemId:         (int) $res->item_id,
                fromLocationId: (int) $res->location_id,
                toLocationId:   null,
                quantity:       $qty,
                referenceType:  'work_order',
                referenceId:    $wo->id,
                remarks:        "WO {$wo->wo_number} material issue (reservation #{$res->id})",
                createdBy:      $userId,
            ));

            $res->update([
                'status'      => ReservationStatus::Issued->value,
                'released_at' => Carbon::now(),
            ]);

            // Bump the matching work_order_materials counter.
            WorkOrderMaterial::where('work_order_id', $wo->id)
                ->where('item_id', $res->item_id)
                ->orderBy('id')
                ->limit(1)
                ->each(function (WorkOrderMaterial $row) use ($qty) {
                    $row->actual_quantity_issued = bcadd((string) $row->actual_quantity_issued, $qty, 3);
                    $row->variance = bcsub((string) $row->actual_quantity_issued, (string) $row->bom_quantity, 3);
                    $row->save();
                });
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
            if ($res->location_id === null) continue;
            $this->stock->release((int) $res->item_id, (int) $res->location_id, (string) $res->quantity);
            $res->update([
                'status'      => ReservationStatus::Released->value,
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
            ->orderByRaw('(quantity - reserved_quantity) DESC')
            ->lockForUpdate()
            ->first();
        if (! $row) return null;
        $available = bcsub((string) $row->quantity, (string) $row->reserved_quantity, 3);
        return bccomp($available, $needed, 3) >= 0 ? (int) $row->location_id : null;
    }

    /**
     * Fallback location selector — returns whichever location has the
     * most stock for the item. Used to produce a meaningful insufficient-
     * stock error message when no single location covers the demand.
     */
    private function largestLocationForItem(int $itemId): ?int
    {
        $row = StockLevel::where('item_id', $itemId)
            ->orderByDesc('quantity')
            ->first();
        return $row ? (int) $row->location_id : null;
    }
}
