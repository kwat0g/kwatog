<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
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
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Exceptions\IllegalLifecycleTransitionException;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Events\WorkOrderStatusChanged;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\ProductionSchedule;
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

    /** @return array<string, list<string>> */
    public static function allowedTransitions(): array
    {
        return self::ALLOWED;
    }

    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly BomService $boms,
        private readonly StockMovementService $stock,
        private readonly SettingsService $settings,
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

        TrashedFilter::apply($q, $filters);

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
            'creator:id,name,role_id',
            'materials.item:id,code,name,unit_of_measure',
            'outputs.recorder:id,name,role_id', 'outputs.defects.defectType',
            'inspections:id,inspection_number,stage,status,entity_type,entity_id,completed_at',
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
                'priority'            => array_key_exists('priority', $data) && $data['priority'] !== null
                    ? (int) $data['priority']
                    : $this->settings->requiredInt('mrp.work_order.normal_priority', 0, 255),
                'status'              => WorkOrderStatus::Planned->value,
                'created_by'          => (int) $data['created_by'],
            ];
            $wo = WorkOrder::create($payload);

            // BOM expansion (Task 49 owns BomService::explode). If no active
            // BOM exists, the WO still saves — supervisor can add materials
            // manually. Once a BOM exists, however, explosion errors (cycles,
            // depth limits, or data faults) must roll back instead of being
            // silently converted into an unmaterialized WO.
            if ($this->boms->activeForProduct((int) $data['product_id']) !== null) {
                $rows = $this->boms->explode((int) $data['product_id'], (float) $data['quantity_target']);
                foreach ($rows as $row) {
                    $wo->materials()->create([
                        'item_id'                => (int) $row['item_id'],
                        'bom_quantity'           => (string) $row['gross_quantity'],
                        'actual_quantity_issued' => '0',
                        'variance'               => '0',
                    ]);
                }
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

        $result = DB::transaction(function () use ($wo, $machineId, $moldId, &$from) {
            // Re-read the WO under a lock. Scheduler confirmation and the
            // direct WO endpoint can otherwise both pass the outer transition
            // check against the same stale planned row.
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::Confirmed);
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

            $lockedWo->forceFill([
                'status' => WorkOrderStatus::Confirmed->value,
                'machine_id' => $machine->id,
                'mold_id' => $mold->id,
            ])->save();
            $lockedWo->refresh();

            // OGAMI-015 — machine double-booking guard. A machine already
            // committed to another Confirmed/InProgress work order cannot also
            // take this one. When both this WO and the incumbent carry schedule
            // rows, the conflict is restricted to overlapping time windows;
            // otherwise any other active WO on the same machine blocks confirm.
            $this->assertMachineAvailable($lockedWo);

            $this->reserveMaterialsFor($lockedWo);

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
     * also releases reservations and creates MaterialIssue stock movements
     * via StockMovementService.
     */
    /**
     * ADV3 — Snapshot the supplier-lot trail of every issued material onto
     * `work_orders.material_lot_references`. This enables IATF 16949
     * backward traceability: "this batch used Resin from GRN X, supplier lot Y".
     */
    private function captureMaterialLotReferences(WorkOrder $wo): void
    {
        $refs = [];
        $materials = $wo->materials()->with('item:id,code,name')->get();
        foreach ($materials as $material) {
            $latestGrnItem = \App\Modules\Inventory\Models\GrnItem::query()
                ->where('item_id', $material->item_id)
                ->whereNotNull('material_lot_number')
                ->with('grn:id,grn_number,received_date')
                ->latest('id')
                ->first();
            if (! $latestGrnItem) {
                continue;
            }
            $refs[] = [
                'item_id'                => $material->item ? $material->item->hash_id : null,
                'item_code'              => $material->item->code ?? null,
                'item_name'              => $material->item->name ?? null,
                'grn_number'             => $latestGrnItem->grn?->grn_number,
                'material_lot_number'    => $latestGrnItem->material_lot_number,
                'supplier_lot_reference' => $latestGrnItem->supplier_lot_reference,
                'quantity_used'          => (string) $material->bom_quantity,
            ];
        }
        if (! empty($refs)) {
            $wo->update(['material_lot_references' => $refs]);
        }
    }

    public function start(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::InProgress);
        $from = $wo->status?->value ?? 'confirmed';

        $result = DB::transaction(function () use ($wo, &$from) {
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::InProgress);
            $from = $lockedWo->status?->value ?? 'confirmed';

            $machine = $lockedWo->machine_id ? Machine::lockForUpdate()->find($lockedWo->machine_id) : null;
            $mold    = $lockedWo->mold_id    ? Mold::lockForUpdate()->find($lockedWo->mold_id)       : null;
            if (! $machine || ! $mold) {
                throw new BusinessRuleException('Cannot start a work order without an assigned machine and mold.');
            }
            if (! in_array($machine->status, [MachineStatus::Idle, MachineStatus::Running], true)) {
                throw new BusinessRuleException('Assigned machine is not available to start production.');
            }
            if (! in_array($mold->status, [MoldStatus::Available, MoldStatus::InUse], true)) {
                throw new BusinessRuleException('Assigned mold is not available.');
            }

            $machine->update([
                'status'                => MachineStatus::Running->value,
                'current_work_order_id' => $lockedWo->id,
            ]);
            $mold->update(['status' => MoldStatus::InUse->value]);

            // ADV3 — IATF 16949 traceability: every WO run is a Production Batch.
            // Generate batch_number on first start (assertTransition prevents double-start).
            $batchNumber = $lockedWo->batch_number ?: $this->sequences->generate('production_batch');

            $lockedWo->update([
                'status'       => WorkOrderStatus::InProgress->value,
                'actual_start' => $lockedWo->actual_start ?? Carbon::now(),
                'batch_number' => $batchNumber,
            ]);

            // Issue reserved materials. Best-effort: if no reservation exists
            // (e.g. legacy WOs that were confirmed before the audit fix), the
            // WO still starts — material_issue rows just won't be created.
            $this->issueReservedMaterials($lockedWo, (int) ($lockedWo->creator?->id ?? $lockedWo->created_by));

            // ADV3 — Capture incoming material lot references for backward traceability.
            // Best-effort: queries the latest GRN item with a material_lot_number for
            // each WO material's item_id. If no lot info exists in seeded data, the
            // attribute stays an empty array — the WO still starts cleanly.
            $this->captureMaterialLotReferences($lockedWo);

            // C-2 — Promote the parent SO to in_production. app() lookup avoids
            // a circular dependency on SalesOrderService at construction time.
            if ($lockedWo->sales_order_id) {
                app(\App\Modules\CRM\Services\SalesOrderService::class)
                    ->markInProduction((int) $lockedWo->sales_order_id);
            }

            $started = $this->show($lockedWo->fresh());
            $this->recordStatusChange($started, $from, WorkOrderStatus::InProgress->value);

            return $started;
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
                    'machine_id'    => $machine->id,
                    'work_order_id' => $lockedWo->id,
                    'start_time'    => Carbon::now(),
                    'category'      => $category->value,
                    'description'   => $reason,
                ]);
                $machine->update([
                    'status'                => MachineStatus::Idle->value,
                    'current_work_order_id' => null,
                ]);
            }
            $lockedWo->update([
                'status'       => WorkOrderStatus::Paused->value,
                'pause_reason' => $reason,
            ]);
            $paused = $this->show($lockedWo->fresh());
            $this->recordStatusChange($paused, $from, WorkOrderStatus::Paused->value, $reason);

            return $paused;
        });
        return $result;
    }

    public function resume(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::InProgress);
        $from = $wo->status?->value ?? 'paused';

        $result = DB::transaction(function () use ($wo, &$from) {
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::InProgress);
            $from = $lockedWo->status?->value ?? 'paused';

            // Close any open downtime row for this WO.
            $open = MachineDowntime::where('work_order_id', $lockedWo->id)
                ->whereNull('end_time')->latest()->first();
            if ($open) {
                $end = Carbon::now();
                $open->update([
                    'end_time'         => $end,
                    'duration_minutes' => (int) max(0, $open->start_time->diffInMinutes($end)),
                ]);
            }
            $machine = $lockedWo->machine_id
                ? Machine::query()->lockForUpdate()->find($lockedWo->machine_id)
                : null;
            if ($machine) {
                $machine->update([
                    'status'                => MachineStatus::Running->value,
                    'current_work_order_id' => $lockedWo->id,
                ]);
            }
            $lockedWo->update([
                'status'       => WorkOrderStatus::InProgress->value,
                'pause_reason' => null,
            ]);
            $resumed = $this->show($lockedWo->fresh());
            $this->recordStatusChange($resumed, $from, WorkOrderStatus::InProgress->value);

            return $resumed;
        });
        return $result;
    }

    public function complete(WorkOrder $wo): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Completed);
        $from = $wo->status?->value ?? 'in_progress';

        $result = DB::transaction(function () use ($wo, &$from) {
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::Completed);
            $from = $lockedWo->status?->value ?? 'in_progress';

            $produced = (int) $lockedWo->quantity_produced;
            $rejected = (int) $lockedWo->quantity_rejected;
            $scrap = $produced > 0 ? round(($rejected / $produced) * 100, 2) : 0.0;
            $lockedWo->update([
                'status'     => WorkOrderStatus::Completed->value,
                'actual_end' => Carbon::now(),
                'scrap_rate' => $scrap,
            ]);
            $machine = $lockedWo->machine_id
                ? Machine::query()->lockForUpdate()->find($lockedWo->machine_id)
                : null;
            if ($machine) {
                $machine->update([
                    'status'                => MachineStatus::Idle->value,
                    'current_work_order_id' => null,
                ]);
            }
            if ($lockedWo->mold_id) {
                $mold = Mold::query()->lockForUpdate()->find($lockedWo->mold_id);
                if ($mold && $mold->status !== MoldStatus::Maintenance) {
                    $mold->update(['status' => MoldStatus::Available->value]);
                }
            }
            $completed = $this->show($lockedWo->fresh());
            app(OutboxService::class)->recordForChain(
                new WorkOrderCompleted($completed),
                $completed,
                'o2c',
                'work_order',
                WorkOrderStatus::Completed->value,
            );
            $this->recordStatusChange($completed, $from, WorkOrderStatus::Completed->value);

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
            $lockedWo->update(['status' => WorkOrderStatus::Closed->value]);
            $closed = $this->show($lockedWo->fresh());
            $this->recordStatusChange($closed, $from, WorkOrderStatus::Closed->value);

            return $closed;
        });
        return $result;
    }

    public function cancel(WorkOrder $wo, ?string $reason = null): WorkOrder
    {
        $this->assertTransition($wo, WorkOrderStatus::Cancelled);
        $from = $wo->status?->value ?? 'planned';

        $result = DB::transaction(function () use ($wo, $reason, &$from) {
            $lockedWo = WorkOrder::query()->lockForUpdate()->find($wo->id);
            if (! $lockedWo) {
                throw new BusinessRuleException('Work order not found.');
            }
            $this->assertTransition($lockedWo, WorkOrderStatus::Cancelled);
            $from = $lockedWo->status?->value ?? 'planned';

            $lockedWo->update([
                'status'       => WorkOrderStatus::Cancelled->value,
                'pause_reason' => $reason,
            ]);

            // Sprint 6 audit §1.1: release any reservations held by this WO.
            $this->releaseReservedMaterials($lockedWo);

            // Free machine + mold if currently bound.
            $machine = $lockedWo->machine_id
                ? Machine::query()->lockForUpdate()->find($lockedWo->machine_id)
                : null;
            if ($machine && (int) $machine->current_work_order_id === (int) $lockedWo->id) {
                $machine->update([
                    'status'                => MachineStatus::Idle->value,
                    'current_work_order_id' => null,
                ]);
            }
            if ($lockedWo->mold_id) {
                $mold = Mold::query()->lockForUpdate()->find($lockedWo->mold_id);
                if ($mold && $mold->status === MoldStatus::InUse) {
                    $mold->update(['status' => MoldStatus::Available->value]);
                }
            }
            $cancelled = $this->show($lockedWo->fresh());
            $this->recordStatusChange($cancelled, $from, WorkOrderStatus::Cancelled->value, $reason);

            return $cancelled;
        });
        return $result;
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
            $lockedWo->delete();
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
        $from = $wo->status?->value ?? 'planned';
        if (! in_array($to->value, self::ALLOWED[$from] ?? [], true)) {
            throw new IllegalLifecycleTransitionException($from, $to->value);
        }
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
        app(\App\Common\Services\ChainBroadcaster::class)->broadcastFor(
            $wo,
            $to,
            auth()->user(),
        );
    }

    /**
     * Shared machine/mold assignment gate used by direct WO confirmation and
     * the capacity scheduler's manual reassignment path.
     *
     * The caller should hold row locks on the WO, machine, and mold when the
     * assignment is being persisted.
     */
    public function assertAssignmentValid(WorkOrder $wo, Machine $machine, Mold $mold): void
    {
        if ((int) $mold->product_id !== (int) $wo->product_id) {
            throw new BusinessRuleException('The selected mold is not configured for this work-order product.');
        }
        if (! in_array($machine->status, [MachineStatus::Idle, MachineStatus::Running], true)) {
            throw new BusinessRuleException('The selected machine is not available for scheduling.');
        }
        if (! in_array($mold->status, [MoldStatus::Available, MoldStatus::InUse], true)) {
            throw new BusinessRuleException('The selected mold is not available for scheduling.');
        }
        if (! $mold->compatibleMachines()->whereKey($machine->id)->exists()) {
            throw new BusinessRuleException('The selected machine and mold are not compatible.');
        }
    }

    /**
     * OGAMI-015 — block confirming a work order onto a machine that is already
     * committed to another active (Confirmed or InProgress) work order.
     *
     * Resolution strategy:
     *   - If this WO has schedule rows for the machine AND a candidate
     *     conflicting WO also has schedule rows, the two are only in conflict
     *     when their [scheduled_start, scheduled_end) windows overlap.
     *   - Otherwise (either side lacks schedule rows), any other active WO
     *     bound to the same machine is treated as a conflict.
     *
     * @throws RuntimeException when the machine is already committed.
     */
    private function assertMachineAvailable(WorkOrder $wo): void
    {
        if (! $wo->machine_id) {
            return;
        }

        $candidates = WorkOrder::query()
            ->where('machine_id', $wo->machine_id)
            ->where('id', '!=', $wo->id)
            ->whereIn('status', [
                WorkOrderStatus::Confirmed->value,
                WorkOrderStatus::InProgress->value,
            ])
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        // Schedule windows for THIS WO on the target machine (if any).
        $activeScheduleStatuses = [
            ProductionScheduleStatus::Pending->value,
            ProductionScheduleStatus::Confirmed->value,
            ProductionScheduleStatus::Executed->value,
        ];

        $ownWindows = ProductionSchedule::where('work_order_id', $wo->id)
            ->where('machine_id', $wo->machine_id)
            ->whereIn('status', $activeScheduleStatuses)
            ->get(['scheduled_start', 'scheduled_end']);

        foreach ($candidates as $other) {
            $otherWindows = ProductionSchedule::where('work_order_id', $other->id)
                ->where('machine_id', $wo->machine_id)
                ->whereIn('status', $activeScheduleStatuses)
                ->get(['scheduled_start', 'scheduled_end']);

            // Only do the precise time-window comparison when BOTH sides have
            // schedule rows. Otherwise fall back to a blanket conflict.
            if ($ownWindows->isNotEmpty() && $otherWindows->isNotEmpty()) {
                foreach ($ownWindows as $own) {
                    foreach ($otherWindows as $cand) {
                        if ($own->scheduled_start < $cand->scheduled_end
                            && $cand->scheduled_start < $own->scheduled_end) {
                            throw new BusinessRuleException(
                                "Machine is already committed to work order {$other->wo_number} "
                                . 'over an overlapping schedule window.'
                            );
                        }
                    }
                }
                // No overlap with this candidate — keep checking others.
                continue;
            }

            throw new BusinessRuleException(
                "Machine is already committed to active work order {$other->wo_number}."
            );
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
        $wo->loadMissing('materials');
        foreach ($wo->materials as $material) {
            $needed = (string) $material->bom_quantity;
            if (bccomp($needed, '0', 3) <= 0) continue;

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
                throw new RuntimeException(
                    "Insufficient stock for item {$material->item_id} (work order {$wo->wo_number}): "
                    . "needed {$needed}."
                );
            }
        }
    }

    private function reserveAt(int $itemId, int $locationId, string $quantity, int $woId): void
    {
        $this->stock->reserve($itemId, $locationId, $quantity);

        MaterialReservation::create([
            'item_id'       => $itemId,
            'work_order_id' => $woId,
            'location_id'   => $locationId,
            'quantity'      => $quantity,
            'status'        => ReservationStatus::Reserved->value,
            'reserved_at'   => Carbon::now(),
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
                    \App\Modules\Inventory\Enums\WarehouseZoneType::Quarantine->value,
                    \App\Modules\Inventory\Enums\WarehouseZoneType::Scrap->value,
                ]);
            })
            ->orderByRaw('(quantity - reserved_quantity) DESC')
            ->lockForUpdate()
            ->get();

        foreach ($levels as $level) {
            if (bccomp($remaining, '0', 3) <= 0) break;
            $available = bcsub((string) $level->quantity, (string) $level->reserved_quantity, 3);
            if (bccomp($available, '0', 3) <= 0) continue;
            $take = bccomp($available, $remaining, 3) >= 0 ? $remaining : $available;
            $this->reserveAt($itemId, (int) $level->location_id, $take, $woId);
            $remaining = bcsub($remaining, $take, 3);
        }

        return bccomp($remaining, '0', 3) <= 0;
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
            // F-02 — never pick quarantine/scrap-zone stock for production.
            ->whereHas('location.zone', function ($q) {
                $q->whereNotIn('zone_type', [
                    \App\Modules\Inventory\Enums\WarehouseZoneType::Quarantine->value,
                    \App\Modules\Inventory\Enums\WarehouseZoneType::Scrap->value,
                ]);
            })
            ->orderByRaw('(quantity - reserved_quantity) DESC')
            ->lockForUpdate()
            ->first();
        if (! $row) return null;
        $available = bcsub((string) $row->quantity, (string) $row->reserved_quantity, 3);
        return bccomp($available, $needed, 3) >= 0 ? (int) $row->location_id : null;
    }
}
