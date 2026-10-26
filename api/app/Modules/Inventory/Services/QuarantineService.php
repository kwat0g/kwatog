<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Support\LotDefectCounter;
use App\Modules\ReturnManagement\Enums\DispositionType;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnReceiptItem;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * REC-08 — Material Review Board (MRB) hold/release workflow.
 *
 * Quarantine is LOCATION-BASED: holding stock is a stock Transfer from its
 * source location into a Quarantine-zone location, recorded through the
 * existing {@see StockMovementService::move()} (WAC-safe). Releasing is another
 * Transfer whose destination depends on the disposition. The MRB row ties the
 * NCR/inspection → the physical movements → disposition for IATF §8.7
 * traceability.
 *
 * Blocking issue of held stock is enforced independently in
 * {@see MaterialIssueService} and {@see PickingListService} (Quarantine + Scrap
 * zones are never issuable / suggested).
 */
class QuarantineService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly StockMovementService $movements,
    ) {}

    /** @param array{status?:string, item_id?:int|string, search?:string, per_page?:int} $filters */
    public function list(array $filters): LengthAwarePaginator
    {
        $q = MaterialReviewRecord::query()
            ->with([
                'item:id,code,name,unit_of_measure',
                'sourceLocation.zone.warehouse', 'quarantineLocation.zone.warehouse', 'releaseLocation.zone.warehouse',
                'ncr:id,ncr_number,status,affected_quantity,disposition,inspection_id',
                'ncr.inspection:id,inspection_number,stage,status,item_id,batch_quantity',
                'inspection:id,inspection_number,stage,status,item_id,batch_quantity',
                'returnRequestItem.returnRequest:id,rma_number,type',
                'holder:id,name', 'releaser:id,name',
            ]);

        if (($filters['status'] ?? null) === MrbStatus::Held->value) {
            $q->held();
        } elseif (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['item_id'])) {
            // MrbController::index() forwards the raw query bag, so the SPA's hash
            // string would hit a bigint column (Postgres 22P02 → 500).
            $q->where('item_id', HashIdFilter::decode($filters['item_id'], Item::class) ?? 0);
        }
        if (($search = trim((string) ($filters['search'] ?? ''))) !== '') {
            $like = SearchOperator::contains($search);
            $q->where(function ($query) use ($like) {
                $query->where('mrb_number', SearchOperator::like(), $like)
                    ->orWhereHas('item', fn ($item) => $item
                        ->where('code', SearchOperator::like(), $like)
                        ->orWhere('name', SearchOperator::like(), $like))
                    ->orWhereHas('ncr', fn ($ncr) => $ncr->where('ncr_number', SearchOperator::like(), $like))
                    ->orWhereHas('sourceLocation', fn ($location) => $location
                        ->where('code', SearchOperator::like(), $like)
                        ->orWhereHas('zone', fn ($zone) => $zone->where('code', SearchOperator::like(), $like)))
                    ->orWhereHas('quarantineLocation', fn ($location) => $location
                        ->where('code', SearchOperator::like(), $like)
                        ->orWhereHas('zone', fn ($zone) => $zone->where('code', SearchOperator::like(), $like)));
            });
        }

        return $q->orderByDesc('held_at')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(MaterialReviewRecord $mrb): MaterialReviewRecord
    {
        return $mrb->load([
            'item:id,code,name,unit_of_measure',
            'sourceLocation.zone.warehouse', 'quarantineLocation.zone.warehouse', 'releaseLocation.zone.warehouse',
            'ncr:id,ncr_number,status,affected_quantity,disposition,inspection_id',
            'ncr.inspection:id,inspection_number,stage,status,item_id,batch_quantity',
            'inspection:id,inspection_number,stage,status,item_id,batch_quantity',
            'returnRequestItem.returnRequest:id,rma_number,type',
            'holder:id,name', 'releaser:id,name',
            'holdMovement', 'releaseMovement',
        ]);
    }

    /** @return array{can_release:bool,can_release_good_stock:bool} */
    public function releaseCapabilities(MaterialReviewRecord $mrb, User $by): array
    {
        $canRelease = $by->can('inventory.mrb.manage')
            && $mrb->status === MrbStatus::Held
            && (int) $mrb->held_by !== (int) $by->id;

        return [
            'can_release' => $canRelease,
            'can_release_good_stock' => $canRelease && ! $this->isGoodStockInspectionMaker($mrb, $by),
        ];
    }

    /** Link already-quarantined RMA stock to the separate MRB release gate. */
    public function holdCustomerReturn(ReturnRequestItem $item, User $by): MaterialReviewRecord
    {
        return DB::transaction(function () use ($item, $by): MaterialReviewRecord {
            $candidate = ReturnRequestItem::query()->lockForUpdate()->findOrFail($item->id);
            $rma = ReturnRequest::query()->lockForUpdate()->findOrFail($candidate->return_request_id);
            $line = ReturnRequestItem::query()
                ->with('deliveryAttemptOutcomeMovement.stockMovement')
                ->where('return_request_id', $rma->id)
                ->lockForUpdate()
                ->findOrFail($candidate->id);

            if ($rma->type !== ReturnRequestType::CustomerReturn
                || $rma->status !== ReturnRequestStatus::Inspected
                || $rma->disposition_status !== 'disposed'
                || ! in_array($line->disposition, [DispositionType::Restock->value, DispositionType::Rework->value], true)
                || ! $line->item_id
                || ! $line->quarantine_movement_id
                || ! $line->quarantine_location_id
                || $line->quarantine_status !== 'held'
                || $line->quarantine_release_movement_id !== null
                || bccomp((string) $line->stock_movement_quantity, '0', 3) > 0) {
                throw new BusinessRuleException('Only an inspected customer-return restock or rework line held in quarantine can open an MRB release.');
            }

            $existing = MaterialReviewRecord::query()
                ->where('return_request_item_id', $line->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ($existing->status === MrbStatus::Held
                    && (int) $existing->item_id === (int) $line->item_id
                    && (int) $existing->quarantine_location_id === (int) $line->quarantine_location_id
                    && bccomp((string) $existing->quantity, $this->customerReturnQuantity($line), 3) === 0) {
                    return $existing;
                }
                throw new BusinessRuleException('This customer-return line already has a terminal MRB record.');
            }

            $quantity = $this->customerReturnQuantity($line);
            if (bccomp($quantity, '0', 3) <= 0) {
                throw new BusinessRuleException('A customer-return MRB requires a positive received quantity.');
            }

            $quarantine = WarehouseLocation::query()->with('zone.warehouse')->lockForUpdate()->findOrFail($line->quarantine_location_id);
            $warehouseId = $this->assertLocation($quarantine, 'Quarantine location', WarehouseZoneType::Quarantine);
            $source = StockMovement::query()->lockForUpdate()->find($line->quarantine_movement_id);
            if (! $source || ! $this->isCustomerReturnReceipt($source, $rma, $line)
                || (int) $source->item_id !== (int) $line->item_id
                || (int) $source->to_location_id !== (int) $line->quarantine_location_id) {
                throw new BusinessRuleException('The customer-return MRB must link to this line’s exact quarantine receipt.');
            }

            $receipts = ReturnReceiptItem::query()->where('return_request_item_id', $line->id)->lockForUpdate()->get();
            if ($receipts->isEmpty()) {
                if (bccomp((string) $source->quantity, $quantity, 3) < 0) {
                    throw new BusinessRuleException('The linked customer-return receipt is smaller than the MRB quantity.');
                }
            } else {
                $received = '0.000';
                $sourceIsLinked = false;
                foreach ($receipts as $receipt) {
                    if (bccomp((string) $receipt->quantity, '0', 3) === 0 && $receipt->stock_movement_id === null) {
                        continue;
                    }
                    $movement = $receipt->stock_movement_id === null
                        ? null
                        : StockMovement::query()->lockForUpdate()->find($receipt->stock_movement_id);
                    if (! $movement || ! $this->isCustomerReturnReceipt($movement, $rma, $line)
                        || (int) $movement->item_id !== (int) $line->item_id
                        || (int) $movement->to_location_id !== (int) $line->quarantine_location_id
                        || ! $line->hasConservedReceiptAllocation($movement)) {
                        throw new BusinessRuleException('Every customer-return MRB receipt must match its recorded quarantine movement.');
                    }
                    $sourceIsLinked = $sourceIsLinked || (int) $movement->id === (int) $source->id;
                    $received = bcadd($received, (string) $receipt->quantity, 3);
                }
                if (! $sourceIsLinked || bccomp($received, $quantity, 3) !== 0) {
                    throw new BusinessRuleException('Customer-return MRB quantity must equal all recorded receipt installments.');
                }
            }

            $level = StockLevel::query()->where('item_id', $line->item_id)
                ->where('location_id', $line->quarantine_location_id)->lockForUpdate()->first();
            if (! $level || bccomp((string) $level->quantity, $quantity, 3) < 0) {
                throw new BusinessRuleException('The linked customer-return quantity is no longer held in quarantine.');
            }

            $inspection = null;
            if ($line->product_id !== null || config('inspection.checklist_capture_enabled')) {
                $inspections = $line->qualityInspections()
                    ->lockForUpdate()
                    ->get();
                $allowedStatuses = $line->disposition === DispositionType::Restock->value
                    ? [InspectionStatus::Passed->value]
                    : [InspectionStatus::Passed->value, InspectionStatus::Failed->value];
                if ($inspections->isEmpty() || $inspections->contains(
                    fn (Inspection $inspection): bool => ! in_array(
                        $inspection->status instanceof InspectionStatus ? $inspection->status->value : (string) $inspection->status,
                        $allowedStatuses,
                        true,
                    ) || ($inspection->requiresMakerChecker() && ! $inspection->isMakerChecked())
                )) {
                    throw new BusinessRuleException('A completed customer-return Quality inspection is required before opening the MRB.');
                }
                $inspection = $inspections->sortByDesc('id')->first();
            }

            $ncrId = null;
            if ($line->ncr_id !== null && $inspection !== null) {
                $ncrId = NonConformanceReport::query()
                    ->whereKey($line->ncr_id)
                    ->where('inspection_id', $inspection->id)
                    ->value('id');
            }

            $mrb = new MaterialReviewRecord;
            $mrb->fill([
                'mrb_number' => $this->sequences->generate('mrb'),
                'ncr_id' => $ncrId,
                'inspection_id' => $inspection?->id,
                'return_request_item_id' => $line->id,
                'item_id' => $line->item_id,
                'quantity' => $quantity,
                'source_location_id' => null,
                'quarantine_location_id' => $line->quarantine_location_id,
                'hold_movement_id' => $line->quarantine_movement_id,
                'held_by' => $by->id,
                'held_at' => now(),
                'notes' => "RMA {$rma->rma_number}: {$line->disposition} awaits independent MRB reinspection.",
            ]);
            $mrb->status = MrbStatus::Held;
            $mrb->save();

            return $mrb;
        });
    }

    private function customerReturnQuantity(ReturnRequestItem $line): string
    {
        return (bool) $line->receipt_recorded || bccomp((string) $line->returned_quantity, '0', 3) > 0
            ? (string) $line->returned_quantity
            : (string) $line->quantity;
    }

    private function isCustomerReturnReceipt(StockMovement $movement, ReturnRequest $rma, ReturnRequestItem $line): bool
    {
        $type = $movement->movement_type instanceof StockMovementType
            ? $movement->movement_type
            : StockMovementType::tryFrom((string) $movement->movement_type);

        return match ($type) {
            StockMovementType::AdjustmentIn => $movement->reference_type === 'return_request'
                && (int) $movement->reference_id === (int) $rma->id,
            StockMovementType::DeliveryCustomerReturn => $movement->reference_type === 'return_request_item'
                && (int) $movement->reference_id === (int) ($line->split_parent_item_id ?? $line->id),
            StockMovementType::DeliveryReturn => $rma->delivery_attempt_outcome_id !== null
                && $line->deliveryAttemptOutcomeMovement?->stockMovement !== null
                && $movement->reference_type === 'stock_movement'
                && (int) $movement->reference_id === (int) $line->deliveryAttemptOutcomeMovement->stockMovement->id,
            default => false,
        };
    }

    /**
     * Return only quality records that can be linked to the selected inventory
     * item. This keeps the hold form from presenting unrelated first-page rows.
     *
     * @return array{inspections:list<array<string,mixed>>,ncrs:list<array<string,mixed>>}
     */
    public function qualityOptions(string|int $itemHashId, ?string $search = null, int $perPage = 50): array
    {
        $itemId = HashIdFilter::decode($itemHashId, Item::class);
        if (! $itemId) {
            throw new BusinessRuleException('Select a valid inventory item before choosing quality records.');
        }

        $item = Item::query()->findOrFail($itemId);
        $like = trim((string) $search);
        $pattern = $like === '' ? null : SearchOperator::contains($like);
        $limit = min(max($perPage, 1), 100);

        $inspections = Inspection::query()
            ->where('item_id', $item->id)
            ->where(fn ($query) => $query->where('status', InspectionStatus::Failed->value)
                ->orWhereHas('ncr', fn ($ncr) => $ncr->where('is_late_incoming', true)
                    ->whereIn('status', [NcrStatus::Open->value, NcrStatus::InProgress->value])))
            ->when($pattern, fn ($query) => $query->where('inspection_number', SearchOperator::like(), $pattern))
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->limit($limit)->get();

        $ncrs = NonConformanceReport::query()
            ->with('inspection:id,inspection_number,stage,status,item_id,batch_quantity')
            ->whereIn('status', [NcrStatus::Open->value, NcrStatus::InProgress->value])
            ->where('affected_quantity', '>', 0)
            ->whereHas('inspection', fn ($inspection) => $inspection->where('item_id', $item->id))
            ->where(fn ($query) => $query->where('is_late_incoming', true)
                ->orWhereHas('inspection', fn ($inspection) => $inspection->where('status', InspectionStatus::Failed->value)))
            ->when($pattern, fn ($query) => $query->where('ncr_number', SearchOperator::like(), $pattern))
            ->orderByDesc('id')->limit($limit)->get();

        return [
            'inspections' => $inspections->map(fn (Inspection $inspection): array => [
                'id' => $inspection->hash_id,
                'inspection_number' => $inspection->inspection_number,
                'stage' => $inspection->stage?->value ?? (string) $inspection->stage,
                'stage_label' => $inspection->stage?->label(),
                'status' => $inspection->status?->value ?? (string) $inspection->status,
                'status_label' => $inspection->status?->label(),
                'batch_quantity' => (int) $inspection->batch_quantity,
            ])->values()->all(),
            'ncrs' => $ncrs->map(fn (NonConformanceReport $ncr): array => [
                'id' => $ncr->hash_id,
                'ncr_number' => $ncr->ncr_number,
                'status' => $ncr->status?->value ?? (string) $ncr->status,
                'status_label' => Str::headline((string) ($ncr->status?->value ?? $ncr->status)),
                'affected_quantity' => (int) $ncr->affected_quantity,
                'inspection' => $ncr->inspection ? [
                    'id' => $ncr->inspection->hash_id,
                    'inspection_number' => $ncr->inspection->inspection_number,
                    'stage' => $ncr->inspection->stage?->value ?? (string) $ncr->inspection->stage,
                    'status' => $ncr->inspection->status?->value ?? (string) $ncr->inspection->status,
                ] : null,
            ])->values()->all(),
        ];
    }

    /**
     * Raise a hold: physically move `quantity` of `item_id` from its source
     * location into a Quarantine-zone location and open a Held MRB record.
     *
     * @param array{
     *   item_id:int, quantity:string|float|int, source_location_id:int,
     *   quarantine_location_id?:int|null, ncr_id?:int|null,
     *   inspection_id?:int|null, notes?:string|null,
     *   lot_number?:string|null, expiry_date?:string|null
     * } $data
     */
    public function hold(array $data, User $by, ?string $idempotencyKey = null): MaterialReviewRecord
    {
        $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : null;
        if ($idempotencyKey !== null && $idempotencyKey === '') {
            $idempotencyKey = null;
        }
        if ($idempotencyKey !== null && mb_strlen($idempotencyKey) > 128) {
            throw new BusinessRuleException('Idempotency-Key must be 128 characters or fewer.');
        }

        return DB::transaction(function () use ($data, $by, $idempotencyKey) {
            $inspectionId = $data['inspection_id'] ?? (! empty($data['ncr_id'])
                ? NonConformanceReport::whereKey($data['ncr_id'])->value('inspection_id') : null);
            if ($inspectionId) {
                Inspection::query()->lockForUpdate()->findOrFail($inspectionId);
            }
            $itemId = (int) $data['item_id'];
            app(UomConversionService::class)->assertBaseQuantity(Item::findOrFail($itemId), (string) $data['quantity']);
            $qty = bcadd((string) $data['quantity'], '0', 3);
            $sourceId = (int) $data['source_location_id'];

            $source = WarehouseLocation::query()
                ->with('zone.warehouse')
                ->lockForUpdate()
                ->findOrFail($sourceId);
            $warehouseId = $this->assertLocation($source, 'Source location', rejectSpecialZones: true);

            $quarantine = ! empty($data['quarantine_location_id'])
                ? WarehouseLocation::query()->with('zone.warehouse')->findOrFail((int) $data['quarantine_location_id'])
                : $this->resolveZoneLocation($source, WarehouseZoneType::Quarantine);
            $this->assertLocation($quarantine, 'Quarantine location', WarehouseZoneType::Quarantine, $warehouseId);
            $quarantineId = (int) $quarantine->id;

            if ($quarantineId === $sourceId) {
                throw new BusinessRuleException('Source and quarantine locations must differ.');
            }

            $fingerprint = $idempotencyKey !== null
                ? $this->idempotencyFingerprint($data, $by, $itemId, $qty, $sourceId, $quarantineId)
                : null;
            if ($idempotencyKey !== null) {
                $existing = MaterialReviewRecord::query()
                    ->where('held_by', $by->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $legacyReplay = trim((string) ($data['lot_number'] ?? '')) === ''
                        && $existing->idempotency_fingerprint === $this->idempotencyFingerprint(
                            $data, $by, $itemId, $qty, $sourceId, $quarantineId, includeLot: false,
                        );
                    if ($existing->idempotency_fingerprint !== $fingerprint && ! $legacyReplay) {
                        throw new BusinessRuleException('This Idempotency-Key was already used for a different MRB hold.');
                    }

                    return $existing;
                }
            }

            $this->assertQualityLinks(
                $itemId,
                $qty,
                isset($data['ncr_id']) ? (int) $data['ncr_id'] : null,
                isset($data['inspection_id']) ? (int) $data['inspection_id'] : null,
            );

            // Validate available stock at the source before moving.
            $level = StockLevel::query()
                ->where('item_id', $itemId)->where('location_id', $sourceId)
                ->lockForUpdate()->first();
            if (! $level) {
                throw new BusinessRuleException("No stock for item {$itemId} at source location {$sourceId}.");
            }
            $available = bcsub((string) $level->quantity, (string) $level->reserved_quantity, 3);
            if (bccomp($available, $qty, 3) < 0) {
                throw new BusinessRuleException(
                    "Insufficient available stock to hold: needed {$qty}, available {$available}."
                );
            }

            $lots = app(StockLocationSummaryService::class)->lots($itemId, $sourceId);
            $selectedLot = trim((string) ($data['lot_number'] ?? ''));
            if ($selectedLot === '' && count($lots) > 1) {
                throw new BusinessRuleException('Choose the suspect lot; this bin contains multiple lots.');
            }
            $lot = $selectedLot !== ''
                ? collect($lots)->firstWhere('lot_number', $selectedLot)
                : ($lots[0] ?? null);
            if ($selectedLot !== '' && $lot === null) {
                throw new BusinessRuleException('The selected lot has no source stock in this bin.');
            }
            $data['lot_number'] = $lot['lot_number'] ?? null;
            $data['expiry_date'] = $lot['expiry_date'] ?? null;
            $linkedInspection = ! empty($data['inspection_id']) ? Inspection::find((int) $data['inspection_id'])
                : (! empty($data['ncr_id']) ? NonConformanceReport::find((int) $data['ncr_id'])?->inspection : null);
            if ($linkedInspection) {
                $receiptLine = $this->resolveGrnItemForReturn($linkedInspection, $itemId);
                if ($receiptLine && ((int) $receiptLine->item_id !== $itemId
                    || ($receiptLine->material_lot_number !== null && $receiptLine->material_lot_number !== $data['lot_number']))) {
                    throw new BusinessRuleException('The suspect lot must match the linked Quality receipt evidence.');
                }
            }

            $mrbNumber = $this->sequences->generate('mrb');

            // Persist the source first so the stock ledger never carries a
            // temporarily unresolved polymorphic reference.
            $mrb = new MaterialReviewRecord;
            $mrb->fill([
                'mrb_number' => $mrbNumber,
                'ncr_id' => $data['ncr_id'] ?? null,
                'inspection_id' => $data['inspection_id'] ?? null,
                'item_id' => $itemId,
                'quantity' => $qty,
                'source_location_id' => $sourceId,
                'quarantine_location_id' => $quarantineId,
                'held_by' => $by->id,
                'held_at' => now(),
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'idempotency_fingerprint' => $fingerprint,
            ]);
            $mrb->status = MrbStatus::Held;
            $mrb->save();

            $movement = $this->movements->move(new StockMovementInput(
                type: StockMovementType::Transfer,
                itemId: $itemId,
                quantity: $qty,
                fromLocationId: $sourceId,
                toLocationId: $quarantineId,
                referenceType: 'material_review_record',
                referenceId: $mrb->id,
                remarks: "MRB hold {$mrbNumber}",
                createdBy: $by->id,
                // Without a lot the ledger moves the bin's preferred lot, which
                // is another receipt's material whenever the bin holds more
                // than one lot of the item — quarantining good stock and
                // leaving the suspect lot available.
                lotNumber: ($data['lot_number'] ?? null) ?: null,
                expiryDate: ($data['expiry_date'] ?? null) ?: null,
            ));

            $mrb->hold_movement_id = $movement->id;
            $mrb->save();

            return $mrb;
        });
    }

    /**
     * Hold multiple rework lines from a GRN in quarantine after MRB disposition (rework).
     * Called after GRN partial acceptance when disposition = rework.
     *
     * @param  array<int, NonConformanceReport>  $reworkLines  Map of grn_item_id → NCR
     */
    public function holdMultipleForMrb(
        GoodsReceiptNote $grn,
        array $reworkLines,
        User $by,
    ): void {
        if (empty($reworkLines)) {
            return;
        }

        $grn->loadMissing(['items.location']);

        foreach ($grn->items as $line) {
            $lineId = (int) $line->id;
            if (! isset($reworkLines[$lineId])) {
                continue;
            }

            $ncr = $reworkLines[$lineId];
            $location = $line->location;
            if (! $location) {
                throw new BusinessRuleException(
                    "GRN line {$line->id} for item {$line->item_id} has no location; cannot hold rework."
                );
            }

            $quantity = (string) $line->quantity_accepted;
            if (bccomp($quantity, '0', 3) <= 0) {
                continue;
            }

            try {
                $this->hold([
                    'item_id' => (int) $line->item_id,
                    'quantity' => $quantity,
                    'source_location_id' => (int) $location->id,
                    'ncr_id' => (int) $ncr->id,
                    'inspection_id' => $ncr->inspection_id ? (int) $ncr->inspection_id : null,
                    'notes' => "Rework hold for GRN {$grn->grn_number} line {$line->id}",
                    'lot_number' => $line->material_lot_number,
                    'expiry_date' => $line->expiry_date?->toDateString(),
                ], $by, idempotencyKey: "grn-rework-{$grn->id}-{$lineId}");
            } catch (BusinessRuleException $e) {
                throw new BusinessRuleException(
                    "Failed to hold rework for GRN line {$line->id}: ".$e->getMessage()
                );
            }
        }
    }

    /**
     * Release a Held MRB according to its disposition:
     *   rework | use_as_is → Transfer quarantine → target good location.
     *   scrap              → move quarantine → a Scrap-zone location.
     *   return_to_supplier → ReturnToVendor out of quarantine.
     */
    public function release(
        MaterialReviewRecord $mrb,
        string $disposition,
        User $by,
        ?int $targetLocationId = null,
        ?string $notes = null,
        array $releaseEvidence = [],
    ): MaterialReviewRecord {
        if ($mrb->status !== MrbStatus::Held) {
            throw new BusinessRuleException("MRB {$mrb->mrb_number} is not held (status: {$mrb->status->value}).");
        }

        $dispo = NcrDisposition::tryFrom($disposition);
        if ($dispo === null) {
            throw new BusinessRuleException("Invalid disposition: {$disposition}.");
        }

        return DB::transaction(function () use ($mrb, $dispo, $by, $targetLocationId, $notes, $releaseEvidence) {
            // Lock-then-guard: re-read the MRB under a row lock so a concurrent
            // release holding a stale model cannot slip past the status check
            // and re-move the quarantine stock (P40).
            $locked = MaterialReviewRecord::query()->lockForUpdate()->findOrFail($mrb->getKey());
            if ($locked->status !== MrbStatus::Held) {
                throw new BusinessRuleException("MRB {$locked->mrb_number} is not held (status: {$locked->status->value}).");
            }
            if ((int) $locked->held_by === (int) $by->id) {
                throw new BusinessRuleException('A different user must release an MRB hold than the one who placed it.');
            }

            $returnLine = null;
            $returnRequest = null;
            if ($locked->return_request_item_id !== null) {
                $returnLine = ReturnRequestItem::query()->lockForUpdate()->findOrFail($locked->return_request_item_id);
                $returnRequest = ReturnRequest::query()->lockForUpdate()->findOrFail($returnLine->return_request_id);
                $this->assertCustomerReturnMrbLine($locked, $returnRequest, $returnLine);
                if ($dispo === NcrDisposition::ReturnToSupplier) {
                    throw new BusinessRuleException('A customer-return MRB cannot be returned to a supplier without a supplier-return request.');
                }
            }

            $qty = (string) $locked->quantity;
            app(UomConversionService::class)->assertBaseQuantity($locked->item, $qty);
            $fromId = $locked->quarantine_location_id;
            $quarantine = WarehouseLocation::query()->with('zone.warehouse')->findOrFail($fromId);
            $warehouseId = $this->assertLocation(
                $quarantine,
                'Quarantine location',
                WarehouseZoneType::Quarantine,
            );

            $releaseLocationId = null;
            $newStatus = MrbStatus::Released;

            // Release exactly the lot the hold quarantined, for the same
            // reason hold() names it.
            $holdMovement = $locked->hold_movement_id !== null
                ? StockMovement::query()->find($locked->hold_movement_id)
                : null;
            $heldLot = $holdMovement?->lot_number;
            $heldExpiry = $holdMovement?->expiry_date?->toDateString();

            switch ($dispo) {
                case NcrDisposition::Rework:
                case NcrDisposition::UseAsIs:
                    if (! $targetLocationId) {
                        throw new BusinessRuleException('A target good location is required for rework/use-as-is release.');
                    }
                    $qualityInspection = $locked->inspection ?? $locked->ncr?->inspection;
                    if ($this->isGoodStockInspectionMaker($locked, $by, $returnRequest, $returnLine, lockForUpdate: true)) {
                        throw new BusinessRuleException($returnRequest && $returnLine
                            ? 'Good-stock release requires a reviewer independent of every customer-return inspection maker.'
                            : 'Good-stock release requires a reviewer independent of the original inspection makers.');
                    }
                    $inspected = (string) ($releaseEvidence['inspected_quantity'] ?? '');
                    $reference = trim((string) ($releaseEvidence['evidence_reference'] ?? ''));
                    if (! preg_match('/^\d+(\.\d{1,3})?$/', $inspected)
                        || bccomp($inspected, $qty, 3) !== 0
                        || $reference === '' || mb_strlen($reference) > 500
                        || ($releaseEvidence['recovery_passed'] ?? false) !== true) {
                        throw new BusinessRuleException('Good-stock release requires completed reinspection of the entire held quantity and an evidence reference.');
                    }
                    if ($dispo === NcrDisposition::UseAsIs
                        && ($locked->ncr?->severity === NcrSeverity::Critical
                            || ($qualityInspection && LotDefectCounter::for($qualityInspection, $qualityInspection->measurements)['criticalFail']))) {
                        throw new BusinessRuleException('A critical failure cannot be released by a use-as-is concession.');
                    }
                    if ($dispo === NcrDisposition::UseAsIs && $locked->requiresConcessionApproval()) {
                        $approval = trim((string) ($releaseEvidence['concession_approval_reference'] ?? ''));
                        if ($approval === '' || mb_strlen($approval) > 500 || $approval === $reference) {
                            throw new BusinessRuleException('A known-defect concession requires a separate authorized approval or customer sign-off reference.');
                        }
                        $locked->concession_approval_reference = $approval;
                    }
                    $locked->forceFill(['release_inspected_quantity' => $inspected, 'release_evidence_reference' => $reference]);
                    $target = WarehouseLocation::query()->with('zone.warehouse')->findOrFail($targetLocationId);
                    $this->assertLocation(
                        $target,
                        'Release target',
                        sameWarehouseId: $warehouseId,
                        rejectSpecialZones: true,
                    );
                    $movement = $this->movements->move(new StockMovementInput(
                        type: StockMovementType::Transfer,
                        itemId: $locked->item_id,
                        quantity: $qty,
                        unitCost: $holdMovement?->movement_type === StockMovementType::DeliveryReturn
                            ? (string) $holdMovement->unit_cost
                            : null,
                        fromLocationId: $fromId,
                        toLocationId: $targetLocationId,
                        referenceType: 'material_review_record',
                        referenceId: $locked->id,
                        remarks: "MRB release ({$dispo->value}) {$locked->mrb_number}",
                        createdBy: $by->id,
                        mrbReleaseId: $locked->id,
                        lotNumber: $heldLot,
                        expiryDate: $heldExpiry,
                    ));
                    $releaseLocationId = $targetLocationId;
                    $newStatus = MrbStatus::Released;
                    break;

                case NcrDisposition::Scrap:
                    $scrapLocation = $this->resolveZoneLocation($quarantine, WarehouseZoneType::Scrap);
                    $movement = $this->movements->move(new StockMovementInput(
                        type: StockMovementType::Scrap,
                        itemId: $locked->item_id,
                        quantity: $qty,
                        fromLocationId: $fromId,
                        toLocationId: null,
                        referenceType: 'material_review_record',
                        referenceId: $locked->id,
                        remarks: "MRB scrap {$locked->mrb_number}",
                        createdBy: $by->id,
                        lotNumber: $heldLot,
                        expiryDate: $heldExpiry,
                    ));
                    // The scrap movement writes inventory off at its source;
                    // this location is the physical scrap-bin trace, not a
                    // second on-hand stock destination.
                    $releaseLocationId = (int) $scrapLocation->id;
                    $newStatus = MrbStatus::Scrapped;
                    break;

                case NcrDisposition::ReturnToSupplier:
                    $movement = $this->movements->move(new StockMovementInput(
                        type: StockMovementType::ReturnToVendor,
                        itemId: $locked->item_id,
                        quantity: $qty,
                        fromLocationId: $fromId,
                        toLocationId: null,
                        referenceType: 'material_review_record',
                        referenceId: $locked->id,
                        remarks: "MRB return-to-supplier {$locked->mrb_number}",
                        createdBy: $by->id,
                        lotNumber: $heldLot,
                        expiryDate: $heldExpiry,
                    ));
                    $newStatus = MrbStatus::Returned;
                    break;
            }

            $locked->fill([
                'disposition' => $dispo->value,
                'release_movement_id' => $movement->id,
                'released_by' => $by->id,
                'released_at' => now(),
                'release_location_id' => $releaseLocationId,
            ]);
            if ($notes !== null && $notes !== '') {
                $locked->notes = trim(($locked->notes ? $locked->notes."\n" : '').$notes);
            }
            $locked->status = $newStatus;
            $locked->save();

            // A return-to-supplier release has already moved the goods out of
            // inventory, so hand the finance side (receipt reconciliation +
            // supplier credit + optional replacement) to the ONE supplier-return
            // engine. The RMA is stamped with the released quantity so it can
            // never move the same goods again, and with
            // reversal_already_applied=false because the MRB path did NOT
            // reduce the PO/GRN received quantities — the RMA does that once.
            if ($dispo === NcrDisposition::ReturnToSupplier) {
                $this->openSupplierReturnForMrb($locked, $by);
            }

            if ($returnLine && $returnRequest) {
                $returnLine->forceFill([
                    'quarantine_release_movement_id' => $movement->id,
                    'quarantine_status' => $newStatus === MrbStatus::Scrapped ? 'scrapped' : 'released',
                    'stock_movement_quantity' => $qty,
                    'stock_movement_id' => $movement->id,
                ])->save();
                $returnRequest->forceFill(['stock_movement_id' => $movement->id])->save();

                if (in_array($dispo, [NcrDisposition::Rework, NcrDisposition::UseAsIs], true)) {
                    app(ReturnRequestService::class)->notifyRestock($returnRequest, $qty);
                }
            }

            return $locked;
        });
    }

    private function assertCustomerReturnMrbLine(
        MaterialReviewRecord $mrb,
        ReturnRequest $rma,
        ReturnRequestItem $line,
    ): void {
        $quantity = $this->customerReturnQuantity($line);
        if ($rma->type !== ReturnRequestType::CustomerReturn
            || $rma->status !== ReturnRequestStatus::Inspected
            || $rma->disposition_status !== 'disposed'
            || ! in_array($line->disposition, [DispositionType::Restock->value, DispositionType::Rework->value], true)
            || (int) $line->item_id !== (int) $mrb->item_id
            || (int) $line->quarantine_location_id !== (int) $mrb->quarantine_location_id
            || (int) $line->quarantine_movement_id !== (int) $mrb->hold_movement_id
            || $line->quarantine_status !== 'held'
            || $line->quarantine_release_movement_id !== null
            || $line->stock_movement_id !== null
            || bccomp((string) $line->stock_movement_quantity, '0', 3) > 0
            || bccomp($quantity, (string) $mrb->quantity, 3) !== 0) {
            throw new BusinessRuleException('This MRB no longer matches an open, quarantined customer-return line.');
        }
    }

    private function isGoodStockInspectionMaker(
        MaterialReviewRecord $mrb,
        User $by,
        ?ReturnRequest $returnRequest = null,
        ?ReturnRequestItem $returnLine = null,
        bool $lockForUpdate = false,
    ): bool {
        if ($mrb->return_request_item_id !== null) {
            $returnLine ??= ReturnRequestItem::query()->find($mrb->return_request_item_id);
            $returnRequest ??= $returnLine?->returnRequest;
            if (! $returnLine || ! $returnRequest) {
                return false;
            }

            $query = $returnLine->qualityInspections();
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            return $query->get()->contains(fn (Inspection $inspection): bool => (int) $inspection->inspector_id === (int) $by->id || $inspection->hasResultAuthor($by)
            );
        }

        $inspection = $mrb->inspection ?? $mrb->ncr?->inspection;

        return $inspection !== null
            && ((int) $inspection->inspector_id === (int) $by->id || $inspection->hasResultAuthor($by));
    }

    /**
     * Open or reuse the supplier-return RMA for an MRB return-to-supplier
     * release. Requires the MRB's inspection to carry GRN/PO lineage; without
     * it the goods cannot be tied to a receipt, so the release stands on its own
     * (the historical behaviour) and the reason is logged rather than fabricating
     * an unusable RMA.
     */
    private function openSupplierReturnForMrb(MaterialReviewRecord $mrb, User $by): void
    {
        $inspection = $mrb->inspection_id
            ? Inspection::find((int) $mrb->inspection_id)
            : ($mrb->ncr_id ? $mrb->ncr?->inspection : null);

        $grnItem = $inspection ? $this->resolveGrnItemForReturn($inspection, (int) $mrb->item_id) : null;
        $grn = $grnItem?->grn;

        if (! $grnItem || ! $grn || ! $grn->vendor_id) {
            Log::info('QuarantineService: MRB return_to_supplier has no GRN/PO lineage; released without an RMA.', [
                'mrb_id' => $mrb->id,
                'item_id' => $mrb->item_id,
                'inspection_id' => $mrb->inspection_id,
            ]);

            return;
        }

        $quantity = bccomp((string) $mrb->quantity, '0', 3) > 0
            ? (string) $mrb->quantity
            : (string) $grnItem->quantity_received;

        $poItem = $grnItem->purchaseOrderItem;

        app(ReturnRequestService::class)
            ->openSupplierReturnForReversedGoods(
                vendorId: (int) $grn->vendor_id,
                purchaseOrderId: $grn->purchase_order_id ? (int) $grn->purchase_order_id : null,
                goodsReceiptNoteId: (int) $grn->id,
                lines: [[
                    'grn_item_id' => (int) $grnItem->id,
                    'purchase_order_item_id' => $grnItem->purchase_order_item_id
                        ? (int) $grnItem->purchase_order_item_id
                        : null,
                    'item_id' => (int) $grnItem->item_id,
                    'quantity' => $quantity,
                    'unit_price' => (string) ($poItem?->unit_price ?? $grnItem->unit_cost),
                    'reason' => "MRB {$mrb->mrb_number}: return to supplier",
                    'lot_number' => $grnItem->material_lot_number,
                    'stock_movement_id' => $mrb->release_movement_id,
                    // The MRB moved stock but never touched the PO received
                    // quantity, so the RMA must reconcile the receipt exactly
                    // once. Its lines carry the released quantity as an already
                    // moved amount, so the RMA will not ship the goods again.
                    'reversal_already_applied' => false,
                ]],
                by: $by,
                reason: "MRB {$mrb->mrb_number} released to supplier.",
                dedupeKey: 'mrb-return:'.$mrb->id,
            );
    }

    /**
     * The GRN line a quality record belongs to, when one exists. Mirrors the
     * NCR resolver so both system paths agree on what "lineage" means.
     */
    private function resolveGrnItemForReturn(Inspection $inspection, int $itemId): ?GrnItem
    {
        if ($inspection->grn_item_id) {
            return GrnItem::query()
                ->with(['grn', 'purchaseOrderItem'])
                ->find((int) $inspection->grn_item_id);
        }

        $entityType = $inspection->entity_type instanceof \BackedEnum
            ? $inspection->entity_type->value
            : (string) $inspection->entity_type;

        if ($entityType === 'grn' && $inspection->entity_id) {
            return GrnItem::query()
                ->with(['grn', 'purchaseOrderItem'])
                ->where('goods_receipt_note_id', (int) $inspection->entity_id)
                ->where('item_id', $itemId)
                ->orderByDesc('id')
                ->first();
        }

        return null;
    }

    /**
     * Validate the lifecycle/ownership invariants that the stock movement
     * ledger cannot infer from a location id alone.
     */
    private function assertLocation(
        WarehouseLocation $location,
        string $label,
        ?WarehouseZoneType $requiredZone = null,
        ?int $sameWarehouseId = null,
        bool $rejectSpecialZones = false,
    ): int {
        if (! $location->is_active) {
            throw new BusinessRuleException("{$label} must be active.");
        }

        $zone = $location->relationLoaded('zone') ? $location->zone : $location->zone()->first();
        $warehouse = $zone
            ? ($zone->relationLoaded('warehouse') ? $zone->warehouse : $zone->warehouse()->first())
            : null;
        if (! $zone || ! $warehouse) {
            throw new BusinessRuleException("{$label} must belong to a configured warehouse zone.");
        }
        if (! $warehouse->is_active) {
            throw new BusinessRuleException("{$label} belongs to an inactive warehouse.");
        }

        $type = $this->zoneType($location);
        if ($type === null) {
            throw new BusinessRuleException("{$label} must belong to a typed warehouse zone.");
        }
        if ($requiredZone !== null && $type !== $requiredZone) {
            throw new BusinessRuleException("{$label} must be in a {$requiredZone->label()} zone.");
        }
        if ($rejectSpecialZones && in_array($type, [WarehouseZoneType::Quarantine, WarehouseZoneType::Scrap], true)) {
            throw new BusinessRuleException('Stock can only be held from a good, non-quarantine/non-scrap source location.');
        }
        if ($sameWarehouseId !== null && (int) $warehouse->id !== $sameWarehouseId) {
            throw new BusinessRuleException("{$label} must be in the same warehouse as the MRB quarantine location.");
        }

        return (int) $warehouse->id;
    }

    /** @param array<string,mixed> $data */
    private function idempotencyFingerprint(
        array $data,
        User $by,
        int $itemId,
        string $quantity,
        int $sourceLocationId,
        int $quarantineLocationId,
        bool $includeLot = true,
    ): string {
        return hash('sha256', json_encode([
            'actor_id' => (int) $by->id,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'source_location_id' => $sourceLocationId,
            'quarantine_location_id' => $quarantineLocationId,
            'ncr_id' => isset($data['ncr_id']) ? (int) $data['ncr_id'] : null,
            'inspection_id' => isset($data['inspection_id']) ? (int) $data['inspection_id'] : null,
            ...($includeLot ? ['lot_number' => trim((string) ($data['lot_number'] ?? ''))] : []),
            'notes' => trim((string) ($data['notes'] ?? '')),
        ], JSON_THROW_ON_ERROR));
    }

    private function isLateAcceptedIncident(Inspection $inspection, ?int $ncrId): bool
    {
        if (! $ncrId || $inspection->stage !== InspectionStage::Incoming || ! $inspection->grn_item_id) {
            return false;
        }

        return NonConformanceReport::query()->whereKey($ncrId)->where('inspection_id', $inspection->id)
            ->where('is_late_incoming', true)->whereIn('status', ['open', 'in_progress'])->exists()
            && in_array($inspection->grnItem?->grn?->status, GrnStatus::billable(), true);
    }

    private function assertQualityLinks(
        int $itemId,
        string $quantity,
        ?int $ncrId,
        ?int $inspectionId,
    ): void {
        $inspection = null;
        if ($inspectionId !== null) {
            $inspection = Inspection::query()->findOrFail($inspectionId);
            $status = $inspection->status instanceof InspectionStatus
                ? $inspection->status
                : InspectionStatus::tryFrom((string) $inspection->status);
            if ($status !== InspectionStatus::Failed && ! $this->isLateAcceptedIncident($inspection, $ncrId)) {
                throw new BusinessRuleException('An MRB hold can only link to a failed inspection.');
            }
            if ((int) $inspection->item_id !== $itemId) {
                throw new BusinessRuleException('The linked inspection belongs to a different inventory item.');
            }
            if (bccomp((string) ($inspection->source_quantity ?? $inspection->batch_quantity), $quantity, 3) < 0) {
                throw new BusinessRuleException('The linked inspection batch is smaller than the MRB quantity.');
            }
        }

        if ($ncrId === null) {
            return;
        }

        $ncr = NonConformanceReport::query()->with('inspection')->lockForUpdate()->findOrFail($ncrId);
        $ncrStatus = $ncr->status instanceof NcrStatus
            ? $ncr->status
            : NcrStatus::tryFrom((string) $ncr->status);
        if (! in_array($ncrStatus, [NcrStatus::Open, NcrStatus::InProgress], true)) {
            throw new BusinessRuleException('Only open or in-progress NCRs can be linked to a new MRB hold.');
        }
        if (bccomp((string) ($ncr->is_late_incoming ? $ncr->late_hold_quantity : $ncr->affected_quantity), $quantity, 3) < 0) {
            throw new BusinessRuleException('The NCR affected quantity is smaller than the MRB quantity.');
        }
        if ($ncr->is_late_incoming) {
            $alreadyHeld = MaterialReviewRecord::where('ncr_id', $ncr->id)->get()->reduce(fn ($sum, $review) => bcadd($sum, (string) $review->quantity, 3), '0.000');
            if (bccomp(bcadd($alreadyHeld, $quantity, 3), (string) $ncr->late_hold_quantity, 3) > 0) {
                throw new BusinessRuleException('Late incoming holds cannot exceed the original suspect remaining quantity.');
            }
        }
        if (! $ncr->inspection_id || ! $ncr->inspection) {
            throw new BusinessRuleException('The NCR must be linked to a failed inspection for this MRB item.');
        }
        if ($inspectionId !== null && (int) $ncr->inspection_id !== $inspectionId) {
            throw new BusinessRuleException('The selected NCR and inspection do not refer to the same quality event.');
        }

        $linked = $ncr->inspection;
        $linkedStatus = $linked->status instanceof InspectionStatus
            ? $linked->status
            : InspectionStatus::tryFrom((string) $linked->status);
        if (($linkedStatus !== InspectionStatus::Failed && ! $this->isLateAcceptedIncident($linked, $ncrId)) || (int) $linked->item_id !== $itemId) {
            throw new BusinessRuleException('The NCR inspection must be failed and belong to the MRB item.');
        }
        if (bccomp((string) ($linked->source_quantity ?? $linked->batch_quantity), $quantity, 3) < 0) {
            throw new BusinessRuleException('The NCR inspection batch is smaller than the MRB quantity.');
        }
    }

    /**
     * Resolve the zone type of a location (works whether the cast is present or
     * the column is a raw string).
     */
    private function zoneType(WarehouseLocation $location): ?WarehouseZoneType
    {
        $zone = $location->relationLoaded('zone') ? $location->zone : $location->zone()->first();
        if (! $zone) {
            return null;
        }
        $raw = $zone->zone_type;

        return $raw instanceof WarehouseZoneType ? $raw : WarehouseZoneType::tryFrom((string) $raw);
    }

    /**
     * Find the first active location in a zone of the given type within the SAME
     * warehouse as the reference location.
     */
    private function resolveZoneLocation(WarehouseLocation $reference, WarehouseZoneType $type): WarehouseLocation
    {
        $refZone = $reference->relationLoaded('zone') ? $reference->zone : $reference->zone()->first();
        if (! $refZone) {
            // Left unmapped on purpose. A warehouse location with no zone
            // violates an invariant the warehouse setup is supposed to hold; the
            // message names an internal row id and offers no remedy, so calling
            // it a validation error would only misattribute the fault.
            throw new BusinessRuleException("Source location {$reference->id} has no zone/warehouse.");
        }
        $warehouseId = $refZone->warehouse_id;

        $location = WarehouseLocation::query()
            ->with('zone.warehouse')
            ->where('is_active', true)
            ->whereHas('zone', function ($q) use ($warehouseId, $type) {
                $q->where('warehouse_id', $warehouseId)
                    ->where('zone_type', $type->value)
                    ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('is_active', true));
            })
            ->orderBy('id')
            ->first();

        if (! $location) {
            // The message already tells the operator exactly what to do, and
            // creating a quarantine/scrap location is warehouse setup they own.
            // Withholding that behind a 500 was the whole defect.
            throw new BusinessRuleException(
                "No active {$type->label()} location exists in warehouse {$warehouseId}. ".
                'Create one before raising an MRB hold/release.'
            );
        }

        return $location;
    }
}
