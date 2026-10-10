<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Support\Facades\DB;

/** Read QC holds from production lots and honor independently re-inspected customer returns. */
class QualityStockHoldService
{
    /** Disposition consumes held pieces in their current bin, never an approved bin. */
    public function dispositionLocation(int $itemId, string $lotNumber, string $quantity): int
    {
        $locations = StockMovement::query()->where('item_id', $itemId)->where('lot_number', $lotNumber)
            ->get(['from_location_id', 'to_location_id'])->flatMap(fn ($movement) => [$movement->from_location_id, $movement->to_location_id])->filter()->unique()->sortDesc();
        foreach ($locations as $locationId) {
            if (bccomp($this->heldQuantity($itemId, (int) $locationId, $lotNumber), $quantity, 3) >= 0) {
                return (int) $locationId;
            }
        }
        throw new BusinessRuleException('Confirmed defective pieces must be in one held lot bin. Open Stock Movements, filter this lot, and consolidate only its held pieces before retrying disposition.');
    }

    public function heldQuantity(int $itemId, int $locationId, ?string $lotNumber = null): string
    {
        $receipts = StockMovement::query()->where('item_id', $itemId)
            ->where('movement_type', 'production_receipt')->where('reference_type', 'work_order_output')
            ->whereNotNull('lot_number')->when($lotNumber !== null, fn ($q) => $q->where('lot_number', $lotNumber))
            ->get();
        $reinspectedReturns = $this->releasedCustomerReturnQuantities(
            $itemId,
            $receipts->pluck('lot_number')->map(fn ($lot): string => (string) $lot)->unique()->values()->all(),
        );
        $held = $this->lateIncomingHeldQuantity($itemId, $locationId, $lotNumber);
        foreach ($receipts as $receipt) {
            $physical = app(StockLocationSummaryService::class)->lotQuantity($itemId, $locationId, (string) $receipt->lot_number);
            if (bccomp($physical, '0', 3) <= 0) {
                continue;
            }
            $inspection = Inspection::query()->with(['ncr', 'resultAuthors'])
                ->where('work_order_output_id', $receipt->reference_id)->where('stage', 'outgoing')
                ->where('status', '!=', 'cancelled')->orderByDesc('id')
                ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
            $approved = $inspection?->canReleaseOutgoing() ? (string) $inspection->accepted_quantity : '0';
            $returnStock = $reinspectedReturns[(string) $receipt->lot_number] ?? [
                'by_location' => [],
                'original_stock_consumed' => '0.000',
            ];
            $remainingApproved = bcsub($approved, $returnStock['original_stock_consumed'], 3);
            if (bccomp($remainingApproved, '0', 3) < 0) {
                $remainingApproved = '0.000';
            }
            // Assign approval capacity across bins in stable location order.
            // The inspection lock serializes issues from different bins.
            $locationIds = StockMovement::query()->where('item_id', $itemId)->where('lot_number', $receipt->lot_number)
                ->get(['from_location_id', 'to_location_id'])->flatMap(fn ($m) => [$m->from_location_id, $m->to_location_id])
                ->filter()->unique()->values();
            $locations = WarehouseLocation::query()->with('zone.warehouse')->whereIn('id', $locationIds)->orderBy('id')->get();
            foreach ($locations as $otherLocation) {
                $otherLocationId = (int) $otherLocation->id;
                if ($otherLocationId >= $locationId) {
                    break;
                }
                $zone = $otherLocation->zone;
                $zoneType = $zone?->zone_type instanceof WarehouseZoneType
                    ? $zone->zone_type
                    : WarehouseZoneType::tryFrom((string) $zone?->zone_type);
                if (! $otherLocation->is_active || $otherLocation->is_blocked || ! $zone?->warehouse?->is_active
                    || in_array($zoneType, [WarehouseZoneType::Quarantine, WarehouseZoneType::Scrap], true)) {
                    continue;
                }
                $otherPhysical = app(StockLocationSummaryService::class)->lotQuantity($itemId, $otherLocationId, (string) $receipt->lot_number);
                $otherReturnStock = $returnStock['by_location'][$otherLocationId] ?? '0.000';
                $otherOriginalPhysical = bcsub($otherPhysical, min($otherPhysical, $otherReturnStock), 3);
                $remainingApproved = bcsub($remainingApproved, $otherOriginalPhysical, 3);
                if (bccomp($remainingApproved, '0', 3) < 0) {
                    $remainingApproved = '0.000';
                }
            }
            $releasedReturnStock = $returnStock['by_location'][$locationId] ?? '0.000';
            $originalPhysical = bcsub($physical, min($physical, $releasedReturnStock), 3);
            $lotHeld = bcsub($originalPhysical, $remainingApproved, 3);
            if (bccomp($lotHeld, '0', 3) > 0) {
                $held = bcadd($held, $lotHeld, 3);
            }
        }

        return $held;
    }

    /** Historical untracked receipts cannot carry incident ownership to a new bin. */
    public function assertUntrackedTransferAllowed(StockMovementInput $input): void
    {
        if (bccomp($this->lateIncomingHeldQuantity($input->itemId, $input->fromLocationId, null, untrackedOnly: true), '0', 3) <= 0) {
            return;
        }
        $hold = $input->referenceType === 'material_review_record'
            ? MaterialReviewRecord::find($input->referenceId) : null;
        $target = WarehouseLocation::with('zone')->find($input->toLocationId);
        if ($hold && $hold->status->value === 'held' && $hold->hold_movement_id === null
            && $hold->item_id === $input->itemId && $hold->source_location_id === $input->fromLocationId
            && $hold->quarantine_location_id === $input->toLocationId
            && (int) $hold->held_by === $input->createdBy && bccomp((string) $hold->quantity, $input->quantity, 3) === 0
            && $target?->zone?->zone_type === WarehouseZoneType::Quarantine) {
            return;
        }
        throw new BusinessRuleException('Untracked accepted material is QC-held. Move it through Quarantine Holds before transferring stock from this bin.');
    }

    /** A newly reported accepted-receipt defect blocks issue before quarantine can move. */
    private function lateIncomingHeldQuantity(int $itemId, int $locationId, ?string $lotNumber, bool $untrackedOnly = false): string
    {
        $location = WarehouseLocation::with('zone')->find($locationId);
        if (in_array($location?->zone?->zone_type?->value, ['quarantine', 'scrap'], true)) {
            return '0.000';
        }
        if (DB::transactionLevel() > 0) {
            Inspection::query()->where('stage', 'incoming')->where('item_id', $itemId)
                ->whereHas('grnItem.grn', fn ($receipt) => $receipt->whereIn('status', ['accepted', 'partial_accepted']))
                ->orderBy('id')->lockForUpdate()->get(['id']);
        }
        $incidents = NonConformanceReport::query()
            ->where('is_late_incoming', true)
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('inspection', fn ($query) => $query->where('stage', 'incoming')->where('item_id', $itemId)
                ->whereHas('grnItem.grn', fn ($receipt) => $receipt->whereIn('status', ['accepted', 'partial_accepted'])))
            ->with('inspection.grnItem')->get();
        $held = '0.000';
        $seen = [];
        foreach ($incidents as $incident) {
            $line = $incident->inspection?->grnItem;
            $number = $line?->material_lot_number;
            if (($untrackedOnly && $number) || ! $line || isset($seen[$number ?: $line->id]) || ($lotNumber !== null && $lotNumber !== $number)) {
                continue;
            }
            $seen[$number ?: $line->id] = true;
            $physical = $number ? app(StockLocationSummaryService::class)->lotQuantity($itemId, $locationId, $number)
                : ($line->location_id === $locationId ? (string) (StockLevel::where('item_id', $itemId)->where('location_id', $locationId)->value('quantity') ?? '0') : '0');
            $held = bcadd($held, $physical, 3);
        }

        return $held;
    }

    /**
     * Keep separately reinspected customer-return stock attached to its current
     * bin so it cannot consume the original outgoing inspection's capacity.
     * Replay later lot movements because MRB-released stock may be transferred
     * before issue or delivery.
     *
     * @return array<string,array{by_location:array<int,string>,original_stock_consumed:string}>
     */
    private function releasedCustomerReturnQuantities(int $itemId, array $lotNumbers): array
    {
        if ($lotNumbers === []) {
            return [];
        }

        $releases = DB::table('material_review_records as mrb')
            ->join('return_request_items as line', 'line.id', '=', 'mrb.return_request_item_id')
            ->join('return_requests as rma', 'rma.id', '=', 'line.return_request_id')
            ->join('stock_movements as hold', 'hold.id', '=', 'mrb.hold_movement_id')
            ->join('stock_movements as release', 'release.id', '=', 'mrb.release_movement_id')
            ->join('warehouse_locations as target', 'target.id', '=', 'mrb.release_location_id')
            ->join('warehouse_zones as target_zone', 'target_zone.id', '=', 'target.zone_id')
            ->where('rma.type', 'customer_return')
            ->whereIn('rma.status', ['inspected', 'completed'])
            ->where('rma.disposition_status', 'disposed')
            ->whereIn('line.disposition', ['restock', 'rework'])
            ->where('line.item_id', $itemId)
            ->where('line.quarantine_status', 'released')
            ->whereColumn('line.quarantine_movement_id', 'mrb.hold_movement_id')
            ->whereColumn('line.quarantine_release_movement_id', 'mrb.release_movement_id')
            ->where('mrb.item_id', $itemId)
            ->where('mrb.status', 'released')
            ->whereIn('mrb.disposition', ['use_as_is', 'rework'])
            ->whereColumn('mrb.released_by', '<>', 'mrb.held_by')
            ->whereNotNull('mrb.release_inspected_quantity')
            ->whereColumn('mrb.release_inspected_quantity', 'mrb.quantity')
            ->whereNotNull('mrb.release_evidence_reference')
            ->where('mrb.release_evidence_reference', '<>', '')
            ->where('hold.item_id', $itemId)
            ->whereIn('hold.movement_type', ['adjustment_in', 'delivery_customer_return', 'delivery_return'])
            ->whereIn('hold.lot_number', $lotNumbers)
            ->whereColumn('hold.to_location_id', 'mrb.quarantine_location_id')
            ->where('release.item_id', $itemId)
            ->where('release.movement_type', 'transfer')
            ->where('release.reference_type', 'material_review_record')
            ->whereColumn('release.reference_id', 'mrb.id')
            ->whereColumn('release.lot_number', 'hold.lot_number')
            ->whereColumn('release.quantity', 'mrb.quantity')
            ->whereColumn('release.from_location_id', 'mrb.quarantine_location_id')
            ->whereColumn('release.to_location_id', 'mrb.release_location_id')
            ->whereNotIn('target_zone.zone_type', ['quarantine', 'scrap'])
            ->select('mrb.quantity', 'release.id as release_movement_id', 'hold.lot_number')
            ->get()
            ->keyBy('release_movement_id');

        $movements = StockMovement::query()
            ->where('item_id', $itemId)
            ->whereIn('lot_number', $lotNumbers)
            ->orderBy('id')
            ->get(['id', 'lot_number', 'movement_type', 'quantity', 'from_location_id', 'to_location_id', 'reference_type', 'reference_id']);
        $locationIds = $movements->flatMap(fn (StockMovement $movement) => [
            $movement->from_location_id,
            $movement->to_location_id,
        ])->filter()->unique()->values();
        $zoneTypes = WarehouseLocation::query()->with('zone:id,zone_type')
            ->whereIn('id', $locationIds)->get()->mapWithKeys(fn (WarehouseLocation $location): array => [
                (int) $location->id => $location->zone?->zone_type instanceof WarehouseZoneType
                    ? $location->zone->zone_type->value
                    : (string) $location->zone?->zone_type,
            ]);

        $byLocation = [];
        $originalStockConsumed = [];
        foreach ($movements as $movement) {
            $lot = (string) $movement->lot_number;
            $release = $releases->get($movement->id);
            if ($release) {
                $locationId = (int) $movement->to_location_id;
                $byLocation[$lot][$locationId] = bcadd(
                    $byLocation[$lot][$locationId] ?? '0.000',
                    (string) $release->quantity,
                    3,
                );

                continue;
            }

            if ($movement->from_location_id === null) {
                continue;
            }

            $fromLocationId = (int) $movement->from_location_id;
            $quantity = (string) $movement->quantity;
            $returnedQuantity = $byLocation[$lot][$fromLocationId] ?? '0.000';
            $movedReturn = bccomp($returnedQuantity, $quantity, 3) < 0 ? $returnedQuantity : $quantity;
            if (bccomp($movedReturn, '0', 3) > 0) {
                $byLocation[$lot][$fromLocationId] = bcsub($returnedQuantity, $movedReturn, 3);
            }

            $movementType = $movement->movement_type instanceof \BackedEnum
                ? $movement->movement_type->value
                : (string) $movement->movement_type;
            if ($movementType === 'transfer') {
                if (bccomp($movedReturn, '0', 3) > 0 && $movement->to_location_id !== null) {
                    $toLocationId = (int) $movement->to_location_id;
                    $byLocation[$lot][$toLocationId] = bcadd(
                        $byLocation[$lot][$toLocationId] ?? '0.000',
                        $movedReturn,
                        3,
                    );
                }

                continue;
            }

            if (! in_array($movementType, ['material_issue', 'delivery', 'adjustment_out', 'scrap', 'return_to_vendor'], true)) {
                continue;
            }

            // Repair and NCR scrap remove rejected source pieces. They do not
            // consume the independently approved good quantity in that lot.
            if ($movement->reference_type === 'work_order_output'
                && in_array($movementType, ['adjustment_out', 'scrap'], true)) {
                $output = WorkOrderOutput::find($movement->reference_id);
                $source = $output?->rework_source_output_id
                    ? WorkOrderOutput::find($output->rework_source_output_id) : null;
                if ($source?->batch_code === $lot
                    || ($movementType === 'scrap' && $output?->batch_code === $lot
                        && $output->inspections()->where('stage', 'outgoing')
                            ->whereHas('ncr', fn ($ncr) => $ncr->where('status', 'closed')
                                ->where('disposition', 'scrap')->whereNotNull('sorting_approved_at'))->exists())) {
                    continue;
                }
            }

            $remainingQuantity = bcsub($quantity, $movedReturn, 3);
            $sourceZoneType = $zoneTypes->get($fromLocationId);
            if (bccomp($remainingQuantity, '0', 3) > 0
                && ! in_array($sourceZoneType, [WarehouseZoneType::Quarantine->value, WarehouseZoneType::Scrap->value], true)) {
                $originalStockConsumed[$lot] = bcadd(
                    $originalStockConsumed[$lot] ?? '0.000',
                    $remainingQuantity,
                    3,
                );
            }
        }

        $result = [];
        foreach ($lotNumbers as $lotNumber) {
            $lot = (string) $lotNumber;
            $result[$lot] = [
                'by_location' => array_filter($byLocation[$lot] ?? [], fn (string $quantity): bool => bccomp($quantity, '0', 3) > 0),
                'original_stock_consumed' => $originalStockConsumed[$lot] ?? '0.000',
            ];
        }

        return $result;
    }
}
