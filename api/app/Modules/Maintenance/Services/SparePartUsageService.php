<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\SparePartUsage;
use App\Modules\Maintenance\Support\MaintenanceWorkOrderStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Sprint 8 — Task 69. */
class SparePartUsageService
{
    public function __construct(
        private readonly StockMovementService $stockMovements,
        private readonly MaintenanceWorkOrderStateMachine $stateMachine,
    ) {}

    /** Advisory ranking; issuing still checks the authoritative stock ledger. */
    public function options(?MaintenanceWorkOrder $order, string $search): array
    {
        $history = $order ? SparePartUsage::query()
            ->join('maintenance_work_orders as orders', 'orders.id', '=', 'spare_part_usage.work_order_id')
            ->where('orders.status', 'completed')
            ->where('orders.maintainable_type', $order->maintainable_type)
            ->where('orders.maintainable_id', $order->maintainable_id)
            ->whereColumn('spare_part_usage.quantity', '>', 'spare_part_usage.returned_quantity')
            ->selectRaw('item_id, COUNT(DISTINCT work_order_id) as history_count, MAX(orders.completed_at) as last_used_at')
            ->groupBy('item_id')->get()->keyBy('item_id') : collect();
        $items = Item::query()->where('item_type', 'spare_part')->where('is_active', true)
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('code', \App\Common\Support\SearchOperator::like(), '%'.$search.'%')
                ->orWhere('name', \App\Common\Support\SearchOperator::like(), '%'.$search.'%')))
            ->when($history->isNotEmpty(), function ($query) use ($history): void {
                $ranked = $history->sort(function ($a, $b): int {
                    return ((int) $b->history_count <=> (int) $a->history_count)
                        ?: strcmp((string) $b->last_used_at, (string) $a->last_used_at)
                        ?: ((int) $a->item_id <=> (int) $b->item_id);
                })->values();
                $cases = $ranked->map(fn ($row, $index) => 'WHEN '.(int) $row->item_id.' THEN '.$index)->implode(' ');
                $query->orderByRaw('CASE id '.$cases.' ELSE '.$ranked->count().' END');
            })
            ->orderBy('code')->limit(50)->get(['id', 'code', 'name', 'unit_of_measure']);
        $levels = StockLevel::query()->with('location.zone.warehouse')->whereIn('item_id', $items->pluck('id'))->get();
        $summary = app(\App\Modules\Inventory\Services\StockLocationSummaryService::class);
        $availability = $summary->availabilityByStockLevel($levels);

        return $items->map(function (Item $item) use ($history, $levels, $availability, $summary): array {
            $stock = $levels->where('item_id', $item->id)->map(function ($level) use ($availability, $summary): array {
                $lots = [];
                // Generic reservations cannot be allocated safely to individual lots.
                if (bccomp((string) $level->reserved_quantity, '0', 3) === 0) {
                    foreach ($summary->lots((int) $level->item_id, (int) $level->location_id) as $lot) {
                        if ($summary->isExpired($lot['expiry_date'])) {
                            continue;
                        }
                        $usable = bcsub($lot['net'], app(\App\Modules\Quality\Services\QualityStockHoldService::class)
                            ->heldQuantity((int) $level->item_id, (int) $level->location_id, $lot['lot_number']), 3);
                        if (bccomp($usable, '0', 3) > 0) {
                            $lots[] = ['lot_number' => $lot['lot_number'], 'expiry_date' => $lot['expiry_date'], 'available' => $usable];
                        }
                    }
                }
                return ['location' => ['id' => $level->location->hash_id, 'code' => $level->location->full_code],
                    'available' => $availability->get($level->id)['usable_quantity'], 'lots' => $lots];
            })->filter(fn ($row) => bccomp($row['available'], '0', 3) > 0)->values()->all();

            return ['id' => $item->hash_id, 'code' => $item->code, 'name' => $item->name,
                'unit_of_measure' => $item->unit_of_measure,
                'history_count' => (int) ($history->get($item->id)?->history_count ?? 0),
                'stock_status' => $stock === [] ? 'no_usable_stock' : 'available', 'stock' => $stock];
        })->all();
    }

    /**
     * Record spare-part consumption and deduct stock.
     *
     * @param  array{item_id:int, location_id:int, quantity:string|float}  $data
     */
    public function record(MaintenanceWorkOrder $wo, array $data, User $by): SparePartUsage
    {
        $key = $data['operation_key'] ?? null;
        if ($key !== null && (! is_string($key) || ! Str::isUuid($key))) {
            throw ValidationException::withMessages(['operation_key' => 'A valid operation key is required.']);
        }
        $key = $key !== null ? strtolower($key) : null;
        $groupKey = $key ?? (string) Str::uuid();

        return DB::transaction(function () use ($wo, $data, $by, $key, $groupKey) {
            if ($key !== null) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?::text, 0))', ['maintenance-spare-issue:'.$key]);
                $lockedWo = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
                app(MaintenanceWorkOrderService::class)->assertRepairEditable($lockedWo);
                $existing = SparePartUsage::query()->with('stockMovement')->where('operation_key', $key)->first();
                if ($existing !== null) {
                    $issued = SparePartUsage::query()->where('issue_group_key', $key)->get();
                    $issuedQuantity = $issued->isEmpty() ? (string) $existing->quantity
                        : $issued->reduce(fn (string $sum, SparePartUsage $part): string => bcadd($sum, (string) $part->quantity, 3), '0.000');
                    if ($existing->work_order_id !== $wo->id || $existing->item_id !== (int) $data['item_id']
                        || $existing->created_by !== $by->id
                        || $existing->stockMovement?->from_location_id !== (int) $data['location_id']
                        || bccomp($issuedQuantity, (string) $data['quantity'], 3) !== 0) {
                        throw ValidationException::withMessages(['operation_key' => 'This operation key was already used for a different spare-part issue.']);
                    }

                    return $existing->load('item:id,code,name,unit_of_measure');
                }
            }
            // Lock the WO so concurrent spare-part issues serialize the running-
            // cost read-modify-write instead of losing cost contributions.
            $lockedWo = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->getKey());
            app(MaintenanceWorkOrderService::class)->assertRepairEditable($lockedWo);
            $this->stateMachine->assertInProgress($lockedWo, 'issue spare parts');
            $workLog = MaintenanceLog::query()->whereKey((int) ($data['work_record_id'] ?? 0))
                ->where('work_order_id', $lockedWo->id)->lockForUpdate()->first();
            if ($workLog === null || trim((string) $workLog->finding) === ''
                || trim((string) $workLog->action_taken) === ''
                || ! in_array($workLog->result, ['in_progress', 'passed', 'needs_follow_up'], true)) {
                throw ValidationException::withMessages([
                    'work_record_id' => ['Select a structured work record from this work order.'],
                ]);
            }

            $item = Item::query()->whereKey((int) $data['item_id'])->lockForUpdate()->firstOrFail();
            if ($item->item_type !== ItemType::SparePart) {
                throw ValidationException::withMessages([
                    'item_id' => ['Item must be a spare_part to be issued for maintenance.'],
                ]);
            }

            // Cost-out at current WAC for the source location
            $level = StockLevel::query()
                ->where('item_id', $item->id)
                ->where('location_id', (int) $data['location_id'])
                ->lockForUpdate()
                ->first();
            if ($level === null) {
                throw ValidationException::withMessages([
                    'location_id' => ['This item has no stock record at the selected location.'],
                ]);
            }
            $qty = (string) $data['quantity'];
            if (! preg_match('/^\d{1,12}(?:\.\d{1,3})?$/D', $qty) || bccomp($qty, '0', 3) <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['Quantity must be positive with at most three decimal places.'],
                ]);
            }

            if (in_array(strtoupper(trim($item->unit_of_measure)), ['PC', 'PCS', 'PIECE', 'PIECES', 'EA', 'EACH', 'UNIT', 'UNITS', 'SET', 'SETS'], true)
                && bccomp($qty, bcadd($qty, '0', 0), 3) !== 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['Quantity must be a whole number for this unit of measure.'],
                ]);
            }

            $movements = $this->stockMovements->issueAcrossLots(new StockMovementInput(
                type: StockMovementType::MaterialIssue,
                itemId: $item->id,
                fromLocationId: (int) $data['location_id'],
                toLocationId: null,
                quantity: $qty,
                unitCost: null,
                referenceType: 'maintenance_work_order',
                referenceId: $lockedWo->id,
                remarks: 'Spare-part issue for '.$lockedWo->mwo_number,
                createdBy: $by->id,
            ));

            $totalCost = '0.00';
            $firstUsage = null;
            foreach ($movements as $movement) {
                $usage = SparePartUsage::create([
                    'operation_key' => $firstUsage === null ? $key : null,
                    'issue_group_key' => $groupKey,
                    'created_by' => $by->id,
                    'work_order_id' => $lockedWo->id,
                    'work_log_id' => $workLog->id,
                    'item_id' => $item->id,
                    'quantity' => (string) $movement->quantity,
                    'unit_cost' => (string) $movement->unit_cost,
                    'total_cost' => (string) $movement->total_cost,
                    'stock_movement_id' => $movement->id,
                    'created_at' => now(),
                ]);
                $firstUsage ??= $usage;
                $totalCost = bcadd($totalCost, (string) $movement->total_cost, 2);
            }

            // Bump WO running cost on the locked row (bcadd, not float +).
            $lockedWo->forceFill([
                'cost' => bcadd((string) $lockedWo->cost, $totalCost, 2),
                'parts_reconciled_at' => null,
                'parts_reconciled_by' => null,
            ])->save();

            return $firstUsage->load('item:id,code,name,unit_of_measure');
        });
    }

    public function returnPart(MaintenanceWorkOrder $wo, SparePartUsage $usage, string $quantity, User $by, string $operationKey): SparePartUsage
    {
        if (! Str::isUuid($operationKey)) {
            throw ValidationException::withMessages(['operation_key' => ['A valid operation key is required.']]);
        }
        $key = 'maintenance-return:'.strtolower($operationKey);

        return DB::transaction(function () use ($wo, $usage, $quantity, $by, $key): SparePartUsage {
            $lockedWo = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($wo->id);
            app(MaintenanceWorkOrderService::class)->assertRepairEditable($lockedWo);
            $lockedUsage = SparePartUsage::query()->with('stockMovement')->lockForUpdate()->findOrFail($usage->id);
            if ($lockedUsage->work_order_id !== $lockedWo->id || $lockedUsage->stockMovement === null) {
                throw ValidationException::withMessages(['usage_id' => ['Select a part issued for this work order.']]);
            }
            $issue = $lockedUsage->stockMovement;
            $prior = StockMovement::query()->where('idempotency_key', $key)->first();
            if ($prior !== null) {
                if ($prior->movement_type !== StockMovementType::MaterialReturn
                    || $prior->reference_type !== 'stock_movement' || $prior->reference_id !== $issue->id
                    || $prior->created_by !== $by->id || bccomp((string) $prior->quantity, $quantity, 3) !== 0) {
                    throw ValidationException::withMessages(['operation_key' => ['This operation key was already used for a different part return.']]);
                }

                return $lockedUsage->load('item:id,code,name,unit_of_measure');
            }
            $this->stateMachine->assertInProgress($lockedWo, 'return spare parts');
            $remaining = bcsub((string) $lockedUsage->quantity, (string) $lockedUsage->returned_quantity, 3);
            if (! preg_match('/^\d{1,12}(?:\.\d{1,3})?$/D', $quantity)
                || bccomp($quantity, '0', 3) <= 0 || bccomp($quantity, $remaining, 3) > 0) {
                throw ValidationException::withMessages(['quantity' => ['Return quantity must be positive and no greater than the unreturned issue quantity.']]);
            }
            $remainingCost = bcsub((string) $lockedUsage->total_cost, (string) $lockedUsage->returned_cost, 2);
            $returnCost = Money::allocate($remainingCost, [$quantity, bcsub($remaining, $quantity, 3)])[0];
            if (bccomp((string) $lockedWo->cost, $returnCost, 2) < 0) {
                throw ValidationException::withMessages(['usage_id' => ['Work-order cost is lower than the part return value.']]);
            }
            $this->stockMovements->move(new StockMovementInput(
                type: StockMovementType::MaterialReturn,
                itemId: $lockedUsage->item_id,
                toLocationId: (int) $issue->from_location_id,
                quantity: $quantity,
                unitCost: (string) $issue->unit_cost,
                referenceType: 'stock_movement',
                referenceId: $issue->id,
                remarks: 'Unused spare part returned from '.$lockedWo->mwo_number,
                createdBy: $by->id,
                lotNumber: $issue->lot_number,
                expiryDate: $issue->expiry_date,
                totalCostOverride: $returnCost,
                idempotencyKey: $key,
                idempotencyFingerprint: hash('sha256', $issue->id.'|'.$quantity.'|'.$by->id),
            ));
            $lockedUsage->forceFill([
                'returned_quantity' => bcadd((string) $lockedUsage->returned_quantity, $quantity, 3),
                'returned_cost' => bcadd((string) $lockedUsage->returned_cost, $returnCost, 2),
            ])->save();
            $lockedWo->forceFill([
                'cost' => bcsub((string) $lockedWo->cost, $returnCost, 2),
                'parts_reconciled_at' => null,
                'parts_reconciled_by' => null,
            ])->save();

            return $lockedUsage->load('item:id,code,name,unit_of_measure');
        });
    }
}
