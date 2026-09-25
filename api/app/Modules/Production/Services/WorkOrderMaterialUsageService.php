<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\Money;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;

/**
 * Reads the material evidence attached to a work order without changing the
 * persisted auto-issue counters used by MRP. Warehouse slips remain their own
 * auditable source, and reservations only contribute to start coverage while
 * they are still active.
 */
class WorkOrderMaterialUsageService
{
    /**
     * @return array<int, array<string, mixed>> One deterministic row per item.
     *                                            Repeated BOM rows are aggregated
     *                                            because manual slips are item
     *                                            scoped, not allocated to BOM lines.
     */
    public function groups(WorkOrder $workOrder, bool $includeReservations = true, bool $lockReservationLevels = false): array
    {
        $materials = $workOrder->materials()->with('item:id,code,name,unit_of_measure')->orderBy('id')->get();
        $groups = [];

        foreach ($materials as $material) {
            $itemId = (int) $material->item_id;
            $group = $groups[$itemId] ?? $this->emptyGroup($itemId, $material->item);
            $group['material_ids'][] = $material->hash_id;
            $group['required_quantity'] = bcadd($group['required_quantity'], (string) $material->bom_quantity, 3);
            $group['standard_cost'] = Money::add($group['standard_cost'], (string) $material->standard_cost);
            $group['auto_gross_quantity_issued'] = bcadd($group['auto_gross_quantity_issued'], (string) $material->actual_quantity_issued, 3);
            $group['auto_gross_actual_cost'] = Money::add($group['auto_gross_actual_cost'], (string) $material->actual_cost);
            $groups[$itemId] = $group;
        }

        $autoSources = StockMovement::query()
            ->with('item:id,code,name,unit_of_measure')
            ->where('reference_type', 'work_order')
            ->where('reference_id', $workOrder->id)
            ->where('movement_type', StockMovementType::MaterialIssue->value)
            ->orderBy('id')
            ->get(['id', 'item_id']);
        $autoReturns = $this->returnTotalsBySource($autoSources->pluck('id')->all());
        foreach ($autoSources as $source) {
            $itemId = (int) $source->item_id;
            $group = $groups[$itemId] ?? $this->emptyGroup($itemId, $source->item);
            $returned = $autoReturns[(int) $source->id] ?? ['quantity' => '0.000', 'cost' => '0.00'];
            $group['auto_returned_quantity'] = bcadd($group['auto_returned_quantity'], $returned['quantity'], 3);
            $group['auto_returned_cost'] = Money::add($group['auto_returned_cost'], $returned['cost']);
            $groups[$itemId] = $group;
        }
        foreach ($groups as &$group) {
            $group['auto_quantity_issued'] = $this->nonNegativeSubtract(
                $group['auto_gross_quantity_issued'],
                $group['auto_returned_quantity'],
                3,
            );
            $group['auto_actual_cost'] = $this->nonNegativeMoneySubtract(
                $group['auto_gross_actual_cost'],
                $group['auto_returned_cost'],
            );
        }
        unset($group);

        $issuedSlips = MaterialIssueSlip::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', MaterialIssueStatus::Issued->value)
            ->with('items.item:id,code,name,unit_of_measure')
            ->orderBy('id')
            ->get();

        $manualLines = $issuedSlips->flatMap(fn (MaterialIssueSlip $slip) => $slip->items);
        $manualReturns = $this->returnTotalsBySource($manualLines->pluck('stock_movement_id')->filter()->all());
        foreach ($manualLines as $line) {
            $itemId = (int) $line->item_id;
            $group = $groups[$itemId] ?? $this->emptyGroup($itemId, $line->item);
            $returned = $line->stock_movement_id !== null
                ? ($manualReturns[(int) $line->stock_movement_id] ?? ['quantity' => '0.000', 'cost' => '0.00'])
                : ['quantity' => '0.000', 'cost' => '0.00'];
            $netQuantity = $this->nonNegativeSubtract((string) $line->quantity_issued, $returned['quantity'], 3);
            $netCost = $this->nonNegativeMoneySubtract((string) ($line->total_cost ?? '0.00'), $returned['cost']);
            $group['manual_gross_quantity_issued'] = bcadd($group['manual_gross_quantity_issued'], (string) $line->quantity_issued, 3);
            $group['manual_gross_actual_cost'] = Money::add($group['manual_gross_actual_cost'], (string) ($line->total_cost ?? '0.00'));
            $group['manual_returned_quantity'] = bcadd($group['manual_returned_quantity'], $returned['quantity'], 3);
            $group['manual_returned_cost'] = Money::add($group['manual_returned_cost'], $returned['cost']);
            $group['manual_quantity_issued'] = bcadd($group['manual_quantity_issued'], $netQuantity, 3);
            $group['manual_actual_cost'] = Money::add($group['manual_actual_cost'], $netCost);
            $groups[$itemId] = $group;
        }

        if ($includeReservations) {
            foreach ($this->reservationPlan($workOrder, strict: $lockReservationLevels, lockLevels: $lockReservationLevels) as $reservation) {
                if (! $reservation['backed'] || ! $reservation['location_usable']) {
                    continue;
                }

                $itemId = (int) $reservation['item_id'];
                $groups[$itemId] ??= $this->emptyGroup($itemId, $reservation['item']);
                $groups[$itemId]['reserved_quantity'] = bcadd(
                    $groups[$itemId]['reserved_quantity'],
                    $reservation['quantity'],
                    3,
                );
            }
        }

        foreach ($groups as &$group) {
            $group['actual_quantity_issued'] = bcadd(
                $group['auto_quantity_issued'],
                $group['manual_quantity_issued'],
                3,
            );
            $group['actual_cost'] = Money::add($group['auto_actual_cost'], $group['manual_actual_cost']);
            $group['returned_quantity'] = bcadd($group['auto_returned_quantity'], $group['manual_returned_quantity'], 3);
            $group['returned_cost'] = Money::add($group['auto_returned_cost'], $group['manual_returned_cost']);
            $group['cost_variance'] = Money::sub($group['actual_cost'], $group['standard_cost']);
            $group['variance'] = bcsub($group['actual_quantity_issued'], $group['required_quantity'], 3);
            $group['standard_unit_cost'] = bccomp($group['required_quantity'], '0', 3) > 0
                ? bcdiv($group['standard_cost'], $group['required_quantity'], 4)
                : '0.0000';
            $group['coverage_quantity'] = bcadd(
                $group['actual_quantity_issued'],
                $group['reserved_quantity'],
                3,
            );
        }
        unset($group);

        ksort($groups);

        return array_values($groups);
    }

    /**
     * Read this WO's live reservations and compare every affected item/location
     * pair with both the stock-level reserved total and all live reservation
     * rows for that pair. A mismatch has no safe per-WO allocation.
     *
     * @return array<int, array{reservation_id:int,item_id:int,location_id:?int,quantity:string,backed:bool,location_usable:bool,item:?Item}>
     */
    public function reservationPlan(WorkOrder $workOrder, bool $strict = false, bool $lockLevels = false): array
    {
        $reservations = MaterialReservation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', ReservationStatus::Reserved->value)
            ->with(['item', 'location.zone.warehouse'])
            ->orderBy('id')
            ->get();

        $located = $reservations->whereNotNull('location_id');
        if ($located->isEmpty()) {
            return $reservations->map(static fn (MaterialReservation $reservation) => [
                'reservation_id' => (int) $reservation->id,
                'item_id' => (int) $reservation->item_id,
                'location_id' => null,
                'quantity' => (string) $reservation->quantity,
                'backed' => false,
                'location_usable' => false,
                'item' => $reservation->item,
            ])->values()->all();
        }

        $itemIds = $located->pluck('item_id')->unique()->sort()->values();
        $locationIds = $located->pluck('location_id')->unique()->sort()->values();

        // The start path takes these locks before it reads reservation sums.
        // Reservation creation/reversal uses the same stock rows, so no new
        // hold can appear between consistency validation and issue/release.
        $levelQuery = StockLevel::query()
            ->whereIn('item_id', $itemIds)
            ->whereIn('location_id', $locationIds)
            ->orderBy('item_id')
            ->orderBy('location_id');
        if ($lockLevels) {
            $levelQuery->lockForUpdate();
        }
        $levels = $levelQuery->get(['item_id', 'location_id', 'reserved_quantity']);
        $reservedByLocation = MaterialReservation::query()
            ->where('status', ReservationStatus::Reserved->value)
            ->whereIn('item_id', $itemIds)
            ->whereIn('location_id', $locationIds)
            ->selectRaw('item_id, location_id, SUM(quantity) AS reservation_quantity')
            ->groupBy('item_id', 'location_id')
            ->get()
            ->mapWithKeys(static fn ($row) => [$row->item_id.'_'.$row->location_id => (string) $row->reservation_quantity])
            ->all();
        $levelByLocation = $levels->mapWithKeys(static fn (StockLevel $level) => [
            $level->item_id.'_'.$level->location_id => (string) $level->reserved_quantity,
        ])->all();

        $mismatches = [];
        foreach ($located as $reservation) {
            $key = $reservation->item_id.'_'.$reservation->location_id;
            $reservationTotal = $reservedByLocation[$key] ?? '0.000';
            $ledgerReserved = $levelByLocation[$key] ?? '0.000';
            if (bccomp($reservationTotal, $ledgerReserved, 3) > 0) {
                $mismatches[$key] = [
                    'item' => $reservation->item,
                    'location_code' => $reservation->location?->code,
                    'location_hash_id' => $reservation->location?->hash_id,
                    'reservation_total' => $reservationTotal,
                    'ledger_reserved' => $ledgerReserved,
                ];
            }
        }

        if ($strict && $mismatches !== []) {
            $mismatch = reset($mismatches);
            $itemLabel = $mismatch['item']?->code ?? $mismatch['item']?->hash_id ?? 'material';
            $locationLabel = $mismatch['location_code'] ?? $mismatch['location_hash_id'] ?? 'warehouse location';
            throw new BusinessRuleException(
                "Reservation records for {$itemLabel} at {$locationLabel} total {$mismatch['reservation_total']}, but stock records only {$mismatch['ledger_reserved']} reserved. Reconcile the reservation ledger before starting production."
            );
        }

        $plan = [];
        foreach ($reservations as $reservation) {
            $location = $reservation->location;
            $zoneType = $location?->zone?->zone_type;
            $zoneValue = $zoneType instanceof WarehouseZoneType ? $zoneType->value : (string) $zoneType;
            $levelKey = $reservation->item_id.'_'.$reservation->location_id;
            $plan[] = [
                'reservation_id' => (int) $reservation->id,
                'item_id' => (int) $reservation->item_id,
                'location_id' => $reservation->location_id !== null ? (int) $reservation->location_id : null,
                'quantity' => (string) $reservation->quantity,
                'backed' => $reservation->location_id !== null && ! isset($mismatches[$levelKey]),
                'location_usable' => $location !== null
                    && $location->is_active
                    && (bool) $location->zone?->warehouse?->is_active
                    && ! in_array($zoneValue, [WarehouseZoneType::Quarantine->value, WarehouseZoneType::Scrap->value], true),
                'item' => $reservation->item,
            ];
        }

        return $plan;
    }

    public function assertReservationLedgerConsistent(WorkOrder $workOrder): void
    {
        $this->reservationPlan($workOrder, strict: true, lockLevels: true);
    }

    /**
     * Gate another production record against the saved per-item BOM norm for
     * cumulative gross output. A reject is already production, so the current
     * reject can be reported against the material issued for the units made so
     * far; a later replacement piece requires the next increment of material.
     *
     * Canonical WO output and routed operation output describe the same work.
     * The larger cumulative ledger is authoritative for this gate; the two are
     * never added together. The caller passes the pending increment in exactly
     * one ledger.
     */
    public function assertProductionCoverage(
        WorkOrder $workOrder,
        string $pendingCanonicalUnits = '0.0000',
        ?int $pendingOperationId = null,
        string $pendingOperationUnits = '0.0000',
    ): void {
        $grossUnits = $this->grossProductionUnits(
            $workOrder,
            $pendingCanonicalUnits,
            $pendingOperationId,
            $pendingOperationUnits,
        );
        if (bccomp($grossUnits, '0', 4) <= 0) {
            return;
        }

        $target = (string) max(1, (int) $workOrder->quantity_target);
        $shortages = [];
        foreach ($this->groups($workOrder, includeReservations: false) as $group) {
            $plannedForTarget = (string) $group['required_quantity'];
            if (bccomp($plannedForTarget, '0', 3) <= 0) {
                continue;
            }

            $requiredAtOutput = $workOrder->material_plan_source === 'bom'
                ? self::plannedConsumptionAtOutput($plannedForTarget, $grossUnits, $target)
                : $plannedForTarget;
            $shortage = bcsub($requiredAtOutput, (string) $group['actual_quantity_issued'], 3);
            if (bccomp($shortage, '0', 3) <= 0) {
                continue;
            }

            $name = $group['item']['name'] ?? $group['item']['code'] ?? ('item '.$group['id']);
            $uom = $group['item']['unit_of_measure'] ?? '';
            $shortages[] = trim("{$name}: {$shortage} {$uom}");
        }

        if ($shortages !== []) {
            throw new BusinessRuleException(
                'Material coverage is short for cumulative production. Issue the remaining quantity before recording more output: '.implode('; ', $shortages).'.'
            );
        }
    }

    public function grossProductionUnits(
        WorkOrder $workOrder,
        string $pendingCanonicalUnits = '0.0000',
        ?int $pendingOperationId = null,
        string $pendingOperationUnits = '0.0000',
    ): string {
        $canonicalUnits = bcadd((string) $workOrder->quantity_produced, $pendingCanonicalUnits, 4);
        $routedUnits = '0.0000';
        $operations = WoOperation::query()
            ->where('work_order_id', $workOrder->id)
            ->orderBy('id')
            ->get(['id', 'qty_completed', 'qty_scrapped']);

        foreach ($operations as $operation) {
            $operationUnits = bcadd((string) $operation->qty_completed, (string) $operation->qty_scrapped, 4);
            if ($pendingOperationId !== null && (int) $operation->id === $pendingOperationId) {
                $operationUnits = bcadd($operationUnits, $pendingOperationUnits, 4);
            }
            if (bccomp($operationUnits, $routedUnits, 4) > 0) {
                $routedUnits = $operationUnits;
            }
        }

        return bccomp($canonicalUnits, $routedUnits, 4) >= 0 ? $canonicalUnits : $routedUnits;
    }

    /** Return floor for one item, using only the work order's saved plan. */
    public function returnFloorForItem(WorkOrder $workOrder, int $itemId, string $grossUnits): string
    {
        if (bccomp($grossUnits, '0', 4) <= 0) {
            return '0.000';
        }

        $group = collect($this->groups($workOrder, includeReservations: false))
            ->first(static fn (array $row): bool => (int) $row['item_id'] === $itemId);
        if ($group === null) {
            return '0.000';
        }

        $plannedForTarget = (string) $group['required_quantity'];
        if ($workOrder->material_plan_source === 'bom' && bccomp($plannedForTarget, '0', 3) > 0) {
            return self::plannedConsumptionAtOutput(
                $plannedForTarget,
                $grossUnits,
                (string) max(1, (int) $workOrder->quantity_target),
            );
        }

        if (bccomp($plannedForTarget, '0', 3) > 0) {
            // Manual/no-BOM plans have no defensible per-unit recipe. Keep the
            // stated full quantity as the floor once output exists.
            return $plannedForTarget;
        }

        // An issued item outside a no-recipe plan has no usage norm at all.
        // Conservatively retain its remaining net issue after output starts.
        return (string) $group['actual_quantity_issued'];
    }

    /**
     * Scale a saved per-target BOM quantity to cumulative gross output,
     * rounding the aggregate item norm upward once to inventory precision.
     * Shared with MRP so issue coverage, return floors and planning use the
     * same conservative 0.001 quantity rule.
     */
    public static function plannedConsumptionAtOutput(string $plannedForTarget, string $grossUnits, string $targetUnits): string
    {
        if (bccomp($plannedForTarget, '0', 3) <= 0
            || bccomp($grossUnits, '0', 4) <= 0
            || bccomp($targetUnits, '0', 0) <= 0) {
            return '0.000';
        }

        $plannedMilliunits = bcmul($plannedForTarget, '1000', 0);
        $grossTenThousandths = bcmul($grossUnits, '10000', 0);
        $divisor = bcmul($targetUnits, '10000', 0);
        $numerator = bcmul($plannedMilliunits, $grossTenThousandths, 0);
        $resultMilliunits = bcdiv($numerator, $divisor, 0);
        if (bccomp(bcmod($numerator, $divisor), '0', 0) > 0) {
            $resultMilliunits = bcadd($resultMilliunits, '1', 0);
        }

        return bcdiv($resultMilliunits, '1000', 3);
    }

    /** Reject production use if any planned material is neither issued nor reserved. */
    public function assertCoverage(WorkOrder $workOrder, bool $includeReservations = true, bool $lockReservationLevels = false): void
    {
        $shortages = [];
        foreach ($this->groups($workOrder, $includeReservations, $lockReservationLevels) as $group) {
            if (bccomp((string) $group['required_quantity'], '0', 3) <= 0) {
                continue;
            }

            $covered = $includeReservations
                ? (string) $group['coverage_quantity']
                : (string) $group['actual_quantity_issued'];
            $shortage = bcsub((string) $group['required_quantity'], $covered, 3);
            if (bccomp($shortage, '0', 3) <= 0) {
                continue;
            }

            $name = $group['item']['name'] ?? $group['item']['code'] ?? ('item '.$group['id']);
            $uom = $group['item']['unit_of_measure'] ?? '';
            $shortages[] = trim("{$name}: {$shortage} {$uom}");
        }

        if ($shortages !== []) {
            throw new BusinessRuleException(
                'Material coverage is short. Issue the remaining quantity before production can continue: '.implode('; ', $shortages).'.'
            );
        }
    }

    /** Refresh the work-order snapshot from actual lot-bearing issue evidence. */
    public function refreshLotReferences(WorkOrder $workOrder): array
    {
        $references = [];
        $hasIssueEvidence = false;
        $lotUsage = [];

        $workOrderMovements = StockMovement::query()
            ->where('reference_type', 'work_order')
            ->where('reference_id', $workOrder->id)
            ->where('movement_type', StockMovementType::MaterialIssue->value)
            ->get(['id', 'item_id', 'quantity', 'lot_number']);
        $autoLotReturns = $this->returnTotalsBySource($workOrderMovements->pluck('id')->all());

        foreach ($workOrderMovements as $movement) {
            $hasIssueEvidence = true;
            if ($movement->lot_number === null || $movement->lot_number === '') {
                continue;
            }
            $returned = $autoLotReturns[(int) $movement->id]['quantity'] ?? '0.000';
            $netQuantity = $this->nonNegativeSubtract((string) $movement->quantity, $returned, 3);
            if (bccomp($netQuantity, '0', 3) > 0) {
                $this->addLotUsage($lotUsage, (int) $movement->item_id, (string) $movement->lot_number, $netQuantity);
            }
        }

        // A cancelled slip still proves that this WO had an authoritative
        // issue history. It suppresses the legacy latest-GRN inference even
        // though its cancelled material is no longer part of current usage.
        $hasIssueEvidence = $hasIssueEvidence || MaterialIssueSlip::query()
            ->where('work_order_id', $workOrder->id)
            ->exists();

        $issuedLines = MaterialIssueSlip::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', MaterialIssueStatus::Issued->value)
            ->with('items.stockMovement:id,quantity,lot_number')
            ->get()
            ->flatMap(fn (MaterialIssueSlip $slip) => $slip->items);
        $manualLotReturns = $this->returnTotalsBySource($issuedLines->pluck('stock_movement_id')->filter()->all());

        foreach ($issuedLines as $line) {
            $hasIssueEvidence = true;
            $source = $line->stockMovement;
            $lotNumber = $source?->lot_number ?? $line->lot_number;
            if ($lotNumber === null || $lotNumber === '') {
                continue;
            }
            $grossQuantity = (string) ($source?->quantity ?? $line->quantity_issued);
            $returned = $line->stock_movement_id !== null
                ? ($manualLotReturns[(int) $line->stock_movement_id]['quantity'] ?? '0.000')
                : '0.000';
            $netQuantity = $this->nonNegativeSubtract($grossQuantity, $returned, 3);
            if (bccomp($netQuantity, '0', 3) > 0) {
                $this->addLotUsage($lotUsage, (int) $line->item_id, (string) $lotNumber, $netQuantity);
            }
        }

        $items = Item::query()
            ->whereIn('id', collect($lotUsage)->pluck('item_id')->unique()->all())
            ->get(['id', 'code', 'name', 'unit_of_measure'])
            ->keyBy('id');

        foreach ($lotUsage as $usage) {
            $item = $items->get($usage['item_id']);
            $grnItem = GrnItem::query()
                ->where('item_id', $usage['item_id'])
                ->where('material_lot_number', $usage['lot_number'])
                ->with('grn:id,grn_number,received_date')
                ->latest('id')
                ->first();
            $references[] = [
                'item_id' => $item?->hash_id,
                'item_code' => $item?->code,
                'item_name' => $item?->name,
                'grn_number' => $grnItem?->grn?->grn_number,
                'material_lot_number' => $usage['lot_number'],
                'supplier_lot_reference' => $grnItem?->supplier_lot_reference,
                'quantity_used' => $usage['quantity'],
            ];
        }

        // Older records may predate stock-issue movements. Retain the former
        // GRN-based fallback only when there is no issue evidence at all. An
        // authoritative issue with an untracked lot must never be replaced by
        // an unrelated, newer supplier lot.
        if (! $hasIssueEvidence) {
            $materials = $workOrder->materials()->with('item:id,code,name,unit_of_measure')->orderBy('id')->get();
            foreach ($materials as $material) {
                $latestGrnItem = GrnItem::query()
                    ->where('item_id', $material->item_id)
                    ->whereNotNull('material_lot_number')
                    ->with('grn:id,grn_number,received_date')
                    ->latest('id')
                    ->first();
                if (! $latestGrnItem) {
                    continue;
                }
                $key = $material->item_id.'_'.$latestGrnItem->material_lot_number;
                $already = collect($references)->firstWhere('_key', $key);
                $quantity = $already
                    ? bcadd((string) $already['quantity_used'], (string) $material->bom_quantity, 3)
                    : (string) $material->bom_quantity;
                $references = array_values(array_filter($references, static fn (array $reference) => ($reference['_key'] ?? null) !== $key));
                $references[] = [
                    '_key' => $key,
                    'item_id' => $material->item?->hash_id,
                    'item_code' => $material->item?->code,
                    'item_name' => $material->item?->name,
                    'grn_number' => $latestGrnItem->grn?->grn_number,
                    'material_lot_number' => $latestGrnItem->material_lot_number,
                    'supplier_lot_reference' => $latestGrnItem->supplier_lot_reference,
                    'quantity_used' => $quantity,
                ];
            }
        }

        $references = array_map(static function (array $reference): array {
            unset($reference['_key']);
            return $reference;
        }, $references);
        $workOrder->update(['material_lot_references' => $references]);

        return $references;
    }

    /** Return whether production has consumed material through either output path. */
    public function hasRecordedProduction(WorkOrder $workOrder): bool
    {
        return WorkOrderOutput::query()
            ->where('work_order_id', $workOrder->id)
            ->where(fn ($query) => $query->where('good_count', '>', 0)->orWhere('reject_count', '>', 0))
            ->exists()
            || WoOperation::query()
                ->where('work_order_id', $workOrder->id)
                ->where(fn ($query) => $query->where('qty_completed', '>', 0)->orWhere('qty_scrapped', '>', 0))
                ->exists();
    }

    /** @return array<string, mixed> */
    private function emptyGroup(int $itemId, ?Item $item): array
    {
        return [
            'item_id' => $itemId,
            'id' => $item?->hash_id ?? app('hashids')->encode($itemId),
            'item' => $item ? [
                'id' => $item->hash_id,
                'code' => $item->code,
                'name' => $item->name,
                'unit_of_measure' => $item->unit_of_measure,
            ] : null,
            'material_ids' => [],
            'required_quantity' => '0.000',
            'standard_cost' => '0.00',
            'standard_unit_cost' => '0.0000',
            'auto_quantity_issued' => '0.000',
            'auto_actual_cost' => '0.00',
            'auto_gross_quantity_issued' => '0.000',
            'auto_gross_actual_cost' => '0.00',
            'auto_returned_quantity' => '0.000',
            'auto_returned_cost' => '0.00',
            'manual_quantity_issued' => '0.000',
            'manual_actual_cost' => '0.00',
            'manual_gross_quantity_issued' => '0.000',
            'manual_gross_actual_cost' => '0.00',
            'manual_returned_quantity' => '0.000',
            'manual_returned_cost' => '0.00',
            'actual_quantity_issued' => '0.000',
            'actual_cost' => '0.00',
            'returned_quantity' => '0.000',
            'returned_cost' => '0.00',
            'cost_variance' => '0.00',
            'variance' => '0.000',
            'reserved_quantity' => '0.000',
            'coverage_quantity' => '0.000',
        ];
    }

    /** @param array<int, int|string|null> $sourceIds
     *  @return array<int, array{quantity:string,cost:string}>
     */
    private function returnTotalsBySource(array $sourceIds): array
    {
        $ids = collect($sourceIds)->filter()->map(static fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return StockMovement::query()
            ->where('movement_type', StockMovementType::MaterialReturn->value)
            ->where('reference_type', 'stock_movement')
            ->whereIn('reference_id', $ids)
            ->selectRaw('reference_id, COALESCE(SUM(quantity), 0) AS quantity, COALESCE(SUM(total_cost), 0) AS cost')
            ->groupBy('reference_id')
            ->get()
            ->mapWithKeys(static fn ($movement) => [(int) $movement->reference_id => [
                'quantity' => bcadd((string) $movement->quantity, '0', 3),
                'cost' => bcadd((string) $movement->cost, '0', 2),
            ]])
            ->all();
    }

    private function nonNegativeSubtract(string $gross, string $returned, int $scale): string
    {
        $net = bcsub($gross, $returned, $scale);

        return bccomp($net, '0', $scale) > 0 ? $net : bcadd('0', '0', $scale);
    }

    private function nonNegativeMoneySubtract(string $gross, string $returned): string
    {
        $net = Money::sub($gross, $returned);

        return bccomp($net, '0', 2) > 0 ? $net : '0.00';
    }

    /** @param array<string, array{item_id:int,lot_number:string,quantity:string}> $lotUsage */
    private function addLotUsage(array &$lotUsage, int $itemId, string $lotNumber, string $quantity): void
    {
        $key = $itemId.'_'.$lotNumber;
        if (! isset($lotUsage[$key])) {
            $lotUsage[$key] = ['item_id' => $itemId, 'lot_number' => $lotNumber, 'quantity' => '0.000'];
        }
        $lotUsage[$key]['quantity'] = bcadd($lotUsage[$key]['quantity'], $quantity, 3);
    }
}
