<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Modules\Accounting\Services\SourceReferenceRegistry;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Inventory\Enums\StockCountSessionStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Events\StockMovementGlPostingRequested;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\QualityStockHoldService;
use App\Modules\ReturnManagement\Enums\DispositionType;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnReceiptItem;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\SupplyChain\Enums\DeliveryCostingMode;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Enums\DeliveryStockReservationStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryStockReservation;
use App\Modules\SupplyChain\Services\DeliveryCostRecognitionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Linchpin of the inventory ledger.
 *
 * Every stock-affecting operation in the system funnels through `move()`.
 * The method is the only place where `stock_levels.quantity`, `reserved_quantity`,
 * and `weighted_avg_cost` mutate, and the only place where `stock_movements`
 * rows are inserted.
 *
 * Concurrency: every call wraps in a DB transaction and `lockForUpdate()` on
 * affected `stock_levels` rows. Dual-location movements lock both rows in
 * deterministic order to prevent deadlocks.
 *
 * Weighted-average cost (per (item, location)) on receipt:
 *   new_qty       = old_qty + receive_qty
 *   new_total_val = (old_qty * old_wac) + (receive_qty * unit_cost)
 *   new_wac       = new_total_val / new_qty   (4-decimal rounding HALF UP)
 *
 * Issues, scrap, deliveries, transfers-out: cost-out at current WAC; do NOT
 * change WAC at the source. Transfers-in inherit the source WAC if no explicit
 * unit cost is supplied.
 */
class StockMovementService
{
    private const QUALITY_HELD_ADJUSTMENT_ERROR = 'Stock adjustment would consume QC-held stock; adjust only the unheld quantity or complete its Quality disposition first.';

    public function __construct(
        private readonly MovementGlPostingService $glPosting,
        private readonly StockLocationSummaryService $locationSummary,
    ) {}

    public function move(StockMovementInput $in): StockMovement
    {
        $this->validateInput($in);
        SourceReferenceRegistry::assertValid($in->referenceType, $in->referenceId);

        // Laravel retries deadlock/serialization failures for these bounded
        // attempts. Each retry re-enters after PostgreSQL rolls the previous
        // transaction back, so no partial ledger row survives a transient
        // concurrency failure.
        return DB::transaction(function () use ($in) {
            // Source rows and physical receipt authorization must be read under
            // the same transaction as the stock-ledger mutation.
            $this->validateInput($in);
            SourceReferenceRegistry::assertValid($in->referenceType, $in->referenceId);
            $this->assertProductionReceiptClassified($in);
            $this->assertLocationsNotFrozen(
                [$in->fromLocationId, $in->toLocationId],
                ! $in->bypassCountFreeze,
            );
            $this->assertMovementLocationsUsable($in);
            if ($in->type === StockMovementType::AdjustmentOut && $in->fromLocationId !== null) {
                $this->assertCustomerReturnQuarantineCanBeAdjusted($in->itemId, $in->fromLocationId);
            }
            $customerReturnReleaseItem = null;
            if ($in->type === StockMovementType::Transfer && $in->fromLocationId !== null) {
                $source = WarehouseLocation::with('zone')->findOrFail($in->fromLocationId);
                if (in_array($source->zone?->zone_type, [WarehouseZoneType::Quarantine, WarehouseZoneType::Scrap], true)) {
                    $mrb = $in->mrbReleaseId === null ? null : MaterialReviewRecord::query()
                        ->lockForUpdate()->find($in->mrbReleaseId);
                    $authorizedMrbRelease = $mrb
                        && $mrb->status === MrbStatus::Held
                        && $in->referenceType === 'material_review_record' && $in->referenceId === $mrb->id
                        && $mrb->item_id === $in->itemId && $mrb->quarantine_location_id === $in->fromLocationId
                        && bccomp((string) $mrb->quantity, $in->quantity, 3) === 0
                        && (int) $mrb->held_by !== (int) $in->createdBy && $mrb->release_movement_id === null;

                    if ($authorizedMrbRelease && $mrb->return_request_item_id !== null) {
                        $customerReturnReleaseItem = $this->lockCustomerReturnReleaseItem($in, $mrb);
                    }

                    if (! $authorizedMrbRelease) {
                        throw new BusinessRuleException('Quarantine stock can leave only through the authorized MRB release workflow.');
                    }
                } elseif ($in->mrbReleaseId !== null) {
                    throw new BusinessRuleException('A quarantine release authorization requires a quarantine source location.');
                }
            }

            if ($in->type === StockMovementType::Transfer && $in->fromLocationId !== null) {
                app(QualityStockHoldService::class)->assertUntrackedTransferAllowed($in);
            }

            $protectsQualityHeldStock = in_array($in->type, [StockMovementType::MaterialIssue, StockMovementType::Delivery], true)
                || ($in->type === StockMovementType::AdjustmentOut && $in->referenceType !== 'work_order_output');
            if ($in->fromLocationId && $protectsQualityHeldStock) {
                // QC precedes stock locks, matching repair, scrap and delivery.
                app(QualityStockHoldService::class)->heldQuantity($in->itemId, $in->fromLocationId, $in->lotNumber);
            }

            // Lock every affected (item, location) row in one deterministic
            // location-id order. A->B and B->A therefore acquire the same pair
            // in the same order and cannot deadlock on opposite row locks.
            [$fromLevel, $toLevel] = $this->lockAffectedLevels(
                $in->itemId,
                $in->fromLocationId,
                $in->toLocationId,
            );

            // F-15 — optimistic-lock check: when the caller passes the lock
            // version it previously read, reject the movement if the ledger
            // row has moved on since (a concurrent receipt/issue landed in
            // between read and write). The row lock above guarantees the
            // version cannot change between this check and the save below.
            $this->assertVersionMatches($fromLevel, $in->expectedFromVersion, 'source');
            $this->assertVersionMatches($toLevel, $in->expectedToVersion, 'destination');

            $deliveryReservation = $in->type === StockMovementType::Delivery
                ? $this->lockedDeliveryReservation($in)
                : null;

            $unitCost = $in->unitCost;
            if ($unitCost === null && $fromLevel !== null) {
                if ($fromLevel->weighted_avg_cost === null) {
                    throw new BusinessRuleException('A source weighted-average cost is required for this stock movement.');
                }
                $unitCost = (string) $fromLevel->weighted_avg_cost;
            }
            if ($unitCost === null && $toLevel !== null) {
                // Pure receipt without an explicit cost (customer returns,
                // production receipts): inherit the destination level's current
                // WAC so the blend is value-neutral. A fresh location with no
                // prior stock costs 0.00 — the received units add no value.
                $unitCost = $toLevel->weighted_avg_cost !== null
                    ? $this->round2((string) $toLevel->weighted_avg_cost)
                    : '0.00';
            }
            if ($unitCost === null || trim((string) $unitCost) === '') {
                throw new BusinessRuleException('A unit cost is required for this stock movement.');
            }
            $totalCost = $in->totalCostOverride ?? bcmul($in->quantity, (string) $unitCost, 4);

            $lotNumber = $in->lotNumber;
            $expiryDate = $in->expiryDate;
            if ($lotNumber === null && $in->fromLocationId !== null) {
                $lot = $this->availableLot(
                    $in->itemId,
                    $in->fromLocationId,
                    $in->quantity,
                    excludeQualityHeld: $protectsQualityHeldStock,
                    excludeExpired: in_array($in->type, [StockMovementType::MaterialIssue, StockMovementType::Delivery], true),
                );
                $lotNumber = $lot['lot_number'] ?? null;
                $expiryDate = $lot['expiry_date'] ?? null;
            }

            if ($fromLevel !== null && $lotNumber === null && $in->type === StockMovementType::MaterialIssue) {
                $knownLots = $this->locationSummary->lots($in->itemId, (int) $in->fromLocationId);
                $held = app(QualityStockHoldService::class)->heldQuantity($in->itemId, (int) $in->fromLocationId);
                if ($knownLots !== []) {
                    if (bccomp($held, '0', 3) > 0) {
                        throw new BusinessRuleException('Material issue would consume QC-held stock. Release the batch first.');
                    }
                    throw new BusinessRuleException('No nonexpired available lot can cover this issue; expired or untracked stock needs reconciliation.');
                }
                if (bccomp($held, '0', 3) > 0 && bccomp($in->quantity, bcsub((string) $fromLevel->quantity, $held, 3), 3) > 0) {
                    throw new BusinessRuleException('Material issue would consume QC-held stock. Release the batch first.');
                }
            }

            if ($fromLevel !== null && $lotNumber === null
                && $in->type === StockMovementType::AdjustmentOut && $protectsQualityHeldStock) {
                $held = app(QualityStockHoldService::class)->heldQuantity($in->itemId, (int) $in->fromLocationId);
                $available = bcsub((string) $fromLevel->quantity, (string) $fromLevel->reserved_quantity, 3);
                if (bccomp($held, '0', 3) > 0 && bccomp($in->quantity, bcsub($available, $held, 3), 3) > 0) {
                    throw new BusinessRuleException(self::QUALITY_HELD_ADJUSTMENT_ERROR);
                }
            }

            if ($fromLevel !== null && $lotNumber !== null) {
                if (in_array($in->type, [StockMovementType::MaterialIssue, StockMovementType::Delivery], true)) {
                    $sourceLot = collect($this->locationSummary->lots($in->itemId, (int) $in->fromLocationId))->firstWhere('lot_number', $lotNumber);
                    if ($sourceLot && $this->locationSummary->isExpired($sourceLot['expiry_date'])) {
                        throw new BusinessRuleException('Expired lot '.$lotNumber.' cannot be issued or delivered.');
                    }
                }
                $lotAvailable = $this->locationSummary->lotQuantity($in->itemId, (int) $in->fromLocationId, $lotNumber);
                $lotAvailable = bcsub($lotAvailable, $this->activeLotHolds(
                    $in->itemId,
                    (int) $in->fromLocationId,
                    $lotNumber,
                    $deliveryReservation?->id,
                ), 3);
                if ($protectsQualityHeldStock) {
                    $held = app(QualityStockHoldService::class)->heldQuantity($in->itemId, (int) $in->fromLocationId, $lotNumber);
                    $lotAvailable = bcsub($lotAvailable, $held, 3);
                    if (bccomp($held, '0', 3) > 0 && bccomp($lotAvailable, $in->quantity, 3) < 0) {
                        if ($in->type === StockMovementType::AdjustmentOut) {
                            throw new BusinessRuleException(self::QUALITY_HELD_ADJUSTMENT_ERROR);
                        }
                        throw new BusinessRuleException('Stock issue would consume QC-held pieces or pieces reserved for delivery. Release the batch first.');
                    }
                }
                if (bccomp($lotAvailable, $in->quantity, 3) < 0) {
                    throw new InsufficientStockException(
                        "Insufficient stock in lot {$lotNumber} at location {$in->fromLocationId}: "
                        ."needed {$in->quantity}, available {$lotAvailable}."
                    );
                }
            }

            // ── Issue side: validate availability and decrement source.
            if ($fromLevel) {
                $available = bcsub((string) $fromLevel->quantity, (string) $fromLevel->reserved_quantity, 3);
                // This dispatch may consume only its own authorized hold.
                // Every other hold remains unavailable to this movement.
                if ($deliveryReservation) {
                    $available = bcadd($available, $in->quantity, 3);
                }
                if (bccomp($available, $in->quantity, 3) < 0) {
                    throw new InsufficientStockException(
                        "Insufficient available stock at location {$in->fromLocationId} for item {$in->itemId}: ".
                        "needed {$in->quantity}, available {$available}."
                    );
                }
                $fromLevel->quantity = bcsub((string) $fromLevel->quantity, $in->quantity, 3);
                if ($deliveryReservation) {
                    $fromLevel->reserved_quantity = bcsub((string) $fromLevel->reserved_quantity, $in->quantity, 3);
                }
                // WAC unchanged on the source for issues/transfers-out.
                $fromLevel->lock_version++;
                $fromLevel->save();
            }

            // ── Receipt side: increment destination + recompute WAC.
            if ($toLevel) {
                $oldQty = (string) $toLevel->quantity;
                $oldWac = (string) $toLevel->weighted_avg_cost;
                $newQty = bcadd($oldQty, $in->quantity, 3);
                if (bccomp($newQty, '0', 3) > 0) {
                    $oldVal = bcmul($oldQty, $oldWac, 4);
                    // Material returns preserve the original issue's rounded
                    // ledger value. For a split return the allocated cents can
                    // differ from quantity × the immutable 4-decimal unit cost,
                    // so the receipt-side inventory value must use that same
                    // authorized ledger amount.
                    $addVal = $in->totalCostOverride !== null
                        ? bcadd($in->totalCostOverride, '0', 4)
                        : bcmul($in->quantity, (string) $unitCost, 4);
                    $newVal = bcadd($oldVal, $addVal, 4);
                    $toLevel->weighted_avg_cost = $this->round4(bcdiv($newVal, $newQty, 6));
                }
                $toLevel->quantity = $newQty;
                $toLevel->lock_version++;
                $toLevel->save();
            }

            // ── Persist the movement record.
            $movement = StockMovement::create([
                'item_id' => $in->itemId,
                'from_location_id' => $in->fromLocationId,
                'to_location_id' => $in->toLocationId,
                'movement_type' => $in->type,
                'quantity' => $in->quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $this->round2($totalCost),
                'reference_type' => $in->referenceType,
                'reference_id' => $in->referenceId,
                'lot_number' => $lotNumber,
                'expiry_date' => $expiryDate,
                'remarks' => $in->remarks,
                'created_by' => $in->createdBy,
                'created_at' => now(),
                'idempotency_key' => $in->idempotencyKey,
                'idempotency_fingerprint' => $in->idempotencyFingerprint,
            ]);

            if ($customerReturnReleaseItem) {
                $customerReturnReleaseItem->forceFill([
                    'quarantine_release_movement_id' => $movement->id,
                    'quarantine_status' => 'released',
                    'stock_movement_quantity' => $in->quantity,
                    'stock_movement_id' => $movement->id,
                ])->save();
            }

            if ($deliveryReservation) {
                if ($lineAllocation = DeliveryItem::find($deliveryReservation->delivery_item_id)?->stockAllocation) {
                    $lineAllocation->forceFill(['consumed_quantity' => bcadd((string) $lineAllocation->consumed_quantity, $in->quantity, 3), 'status' => 'consumed'])->save();
                }
                $remainingReservation = bcsub($deliveryReservation->remainingQuantity(), $in->quantity, 3);
                $deliveryReservation->forceFill([
                    'consumed_quantity' => bcadd((string) $deliveryReservation->consumed_quantity, $in->quantity, 3),
                    'status' => bccomp($remainingReservation, '0', 3) === 0
                        ? DeliveryStockReservationStatus::Consumed
                        : DeliveryStockReservationStatus::Reserved,
                    'stock_movement_id' => $movement->id,
                ])->save();
                $batch = $deliveryReservation->batch()->lockForUpdate()->first();
                if ($batch && ! $batch->reservations()->where('status', DeliveryStockReservationStatus::Reserved->value)->exists()) {
                    $batch->forceFill(['status' => DeliveryStockReservationStatus::Consumed])->save();
                }
            }

            // Record the stock-ledger event atomically. Publication waits for
            // commit, so reorder listeners never observe rolled-back stock.
            app(OutboxService::class)->record(new StockMovementCompleted($movement));

            // F-05 — post the movement's GL entry atomically inside the same
            // transaction. Expected Accounting setup gaps leave the stock
            // movement committed with a narrow, replayable GL handoff rather
            // than disappearing into a log line.
            $this->glPosting->postFor($movement);

            $movement = $movement->fresh();
            if ($movement?->gl_handoff_status === MovementGlHandoffStatus::ManualRequired) {
                app(OutboxService::class)->recordForChain(
                    new StockMovementGlPostingRequested($movement),
                    $movement,
                    'inventory',
                    'stock_movement',
                    'gl_handoff',
                    'stock-movement-gl-request:'.$movement->id,
                );
            }

            return $movement;
        }, 3);
    }

    /**
     * Read the current ledger row for an (item, location) pair without taking
     * a write lock. Used by F-15 optimistic-lock callers: read the version,
     * then pass it back on the next movement.
     */
    /** Fulfill one material request with one immutable movement per source lot. */
    public function issueAcrossLots(StockMovementInput $input): Collection
    {
        if ($input->type !== StockMovementType::MaterialIssue || $input->fromLocationId === null
            || $input->idempotencyKey !== null || $input->totalCostOverride !== null) {
            throw new InvalidMovementException('Split allocation requires a source material issue with caller-owned replay protection.');
        }

        return DB::transaction(function () use ($input): Collection {
            $this->validateInput($input);
            SourceReferenceRegistry::assertValid($input->referenceType, $input->referenceId);
            // Match move(): location/count and QC locks precede stock rows.
            // A direct movement must never wait on a location we request after locking its stock.
            $this->assertLocationsNotFrozen([$input->fromLocationId], ! $input->bypassCountFreeze);
            $this->assertMovementLocationsUsable($input);
            app(QualityStockHoldService::class)->heldQuantity($input->itemId, $input->fromLocationId, $input->lotNumber);
            [$level] = $this->lockAffectedLevels($input->itemId, $input->fromLocationId, null);
            $this->assertVersionMatches($level, $input->expectedFromVersion, 'source');
            $lots = $this->locationSummary->lots($input->itemId, $input->fromLocationId);
            if ($input->lotNumber !== null || $lots === []) {
                return collect([$this->move($input)]);
            }
            $remaining = $input->quantity;
            $movements = collect();
            $expired = false;
            $qualityHeld = false;
            foreach ($lots as $lot) {
                if ($this->locationSummary->isExpired($lot['expiry_date'])) {
                    $expired = true;

                    continue;
                }
                $available = bcsub($lot['net'], $this->activeLotHolds($input->itemId, $input->fromLocationId, $lot['lot_number']), 3);
                $held = app(QualityStockHoldService::class)->heldQuantity($input->itemId, $input->fromLocationId, $lot['lot_number']);
                $qualityHeld = $qualityHeld || bccomp($held, '0', 3) > 0;
                $available = bcsub($available, $held, 3);
                $take = bccomp($available, $remaining, 3) < 0 ? $available : $remaining;
                if (bccomp($take, '0', 3) <= 0) {
                    continue;
                }
                $movements->push($this->move(new StockMovementInput(
                    type: $input->type, itemId: $input->itemId, fromLocationId: $input->fromLocationId,
                    quantity: $take, referenceType: $input->referenceType, referenceId: $input->referenceId,
                    remarks: $input->remarks, createdBy: $input->createdBy, bypassCountFreeze: $input->bypassCountFreeze,
                    expectedFromVersion: $movements->isEmpty() ? $input->expectedFromVersion : null,
                    lotNumber: $lot['lot_number'], expiryDate: $lot['expiry_date'],
                )));
                $remaining = bcsub($remaining, $take, 3);
                if (bccomp($remaining, '0', 3) === 0) {
                    return $movements;
                }
            }
            throw new BusinessRuleException(match (true) {
                $expired && $qualityHeld => 'No nonexpired, QC-released lot can cover this issue; release QC holds or reconcile expired stock before issuing.',
                $expired => 'Insufficient nonexpired lot stock; expired material cannot be issued.',
                $qualityHeld => 'Material issue would consume QC-held stock. Release the batch first.',
                default => 'Insufficient traceable available lot stock. Mixed untracked stock needs reconciliation before issuing.',
            });
        }, 3);
    }

    public function currentLevel(int $itemId, int $locationId): ?StockLevel
    {
        return StockLevel::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->first();
    }

    /**
     * F-15 — reject a stale write: the caller supplies the lock_version it
     * observed on a prior read; if the ledger row has advanced since, another
     * transaction already changed the stock the caller was reasoning about.
     */
    private function assertVersionMatches(?StockLevel $level, ?int $expectedVersion, string $side): void
    {
        if ($level === null || $expectedVersion === null) {
            return;
        }
        if ((int) $level->lock_version !== $expectedVersion) {
            throw new BusinessRuleException(
                "Stock level for item {$level->item_id} at location {$level->location_id} changed since it was read ".
                "(version {$expectedVersion} → {$level->lock_version}); reload and retry ({$side})."
            );
        }
    }

    /** Lock-or-create the per-(item, location) ledger row. */
    private function lockOrCreate(int $itemId, int $locationId): StockLevel
    {
        $level = StockLevel::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (! $level) {
            // Insert (race-safe via unique constraint) then re-lock.
            StockLevel::query()->insertOrIgnore([
                'item_id' => $itemId,
                'location_id' => $locationId,
                'quantity' => 0,
                'reserved_quantity' => 0,
                'weighted_avg_cost' => 0,
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $level = StockLevel::query()
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $level;
    }

    /**
     * @return array{0: StockLevel|null, 1: StockLevel|null}
     */
    private function lockAffectedLevels(int $itemId, ?int $fromLocationId, ?int $toLocationId): array
    {
        $locationIds = array_values(array_unique(array_filter(
            [$fromLocationId, $toLocationId],
            static fn (?int $id): bool => $id !== null,
        )));
        sort($locationIds, SORT_NUMERIC);

        $levels = [];
        foreach ($locationIds as $locationId) {
            $levels[$locationId] = $this->lockOrCreate($itemId, $locationId);
        }

        return [
            $fromLocationId === null ? null : $levels[$fromLocationId],
            $toLocationId === null ? null : $levels[$toLocationId],
        ];
    }

    /** The delivery issue must consume one exact active physical hold. */
    private function lockedDeliveryReservation(StockMovementInput $input): DeliveryStockReservation
    {
        if ($input->deliveryStockReservationId === null || $input->referenceType !== 'delivery_item'
            || $input->referenceId === null || $input->fromLocationId === null || $input->toLocationId !== null) {
            throw new BusinessRuleException('A delivery issue must consume its exact reserved delivery line, lot, and warehouse bin.');
        }

        $reservation = DeliveryStockReservation::query()->with('batch')
            ->lockForUpdate()->find($input->deliveryStockReservationId);
        $line = DeliveryItem::query()->lockForUpdate()->find($input->referenceId);
        $delivery = $reservation ? Delivery::query()->find($reservation->delivery_id) : null;
        if (! $reservation || ! $line || ! $delivery
            || (int) $line->delivery_id !== (int) $reservation->delivery_id
            || (int) $reservation->delivery_item_id !== (int) $line->id
            || (int) $reservation->item_id !== $input->itemId
            || (int) $reservation->location_id !== $input->fromLocationId
            || (string) $reservation->lot_number !== (string) $input->lotNumber
            || (string) $reservation->expiry_date?->toDateString() !== (string) $input->expiryDate
            || $reservation->status !== DeliveryStockReservationStatus::Reserved
            || $delivery->status !== DeliveryStatus::InTransit
            || bccomp($reservation->remainingQuantity(), $input->quantity, 3) < 0) {
            throw new BusinessRuleException('The delivery issue does not match an active reservation for this line, lot, and bin.');
        }

        $inspection = Inspection::query()->whereKey($line->inspection_id)->lockForUpdate()->first();
        if (! $inspection || ! $inspection->canReleaseOutgoing()) {
            throw new BusinessRuleException('The reserved delivery batch is held by QC. Release it before issuing stock.');
        }

        return $reservation;
    }

    /** Lot holds are enforced alongside aggregate reserved_quantity. */
    private function activeLotHolds(int $itemId, int $locationId, string $lotNumber, ?int $exceptReservationId = null): string
    {
        $query = DeliveryStockReservation::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('lot_number', $lotNumber)
            ->where('status', DeliveryStockReservationStatus::Reserved->value);
        if ($exceptReservationId !== null) {
            $query->whereKeyNot($exceptReservationId);
        }

        $orderHeld = \App\Modules\CRM\Models\SalesOrderStockAllocation::where('item_id', $itemId)->where('location_id', $locationId)->where('lot_number', $lotNumber)->get()->reduce(fn ($sum, $allocation) => bcadd($sum, $allocation->untransferredQuantity(), 3), '0.000');
        return $query->get(['id', 'quantity', 'consumed_quantity', 'released_quantity'])
            ->reduce(static fn (string $sum, DeliveryStockReservation $reservation): string => bcadd(
                $sum,
                $reservation->remainingQuantity(),
                3,
            ), $orderHeld);
    }

    /** Prefer an unheld FEFO/FIFO lot when an ordinary outbound omitted one. */
    private function availableLot(
        int $itemId,
        int $locationId,
        string $needed,
        bool $excludeQualityHeld = false,
        bool $excludeExpired = false,
    ): ?array {
        $lots = StockMovement::query()->where('item_id', $itemId)->whereNotNull('lot_number')
            ->where(fn ($query) => $query->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId))
            ->orderBy('created_at')->orderBy('id')->get(['lot_number', 'expiry_date'])
            ->unique('lot_number')->values();
        $available = [];
        foreach ($lots as $lot) {
            if ($excludeExpired && $this->locationSummary->isExpired($lot->expiry_date?->toDateString())) {
                continue;
            }
            $number = (string) $lot->lot_number;
            $quantity = bcsub(
                $this->locationSummary->lotQuantity($itemId, $locationId, $number),
                $this->activeLotHolds($itemId, $locationId, $number),
                3,
            );
            if ($excludeQualityHeld) {
                $quantity = bcsub($quantity, app(QualityStockHoldService::class)->heldQuantity($itemId, $locationId, $number), 3);
            }
            if (bccomp($quantity, '0', 3) <= 0) {
                continue;
            }
            $available[] = [
                'lot_number' => $number,
                'expiry_date' => $lot->expiry_date?->toDateString(),
                'available' => $quantity,
            ];
        }
        usort($available, static function (array $left, array $right): int {
            if ($left['expiry_date'] === null && $right['expiry_date'] !== null) {
                return 1;
            }
            if ($left['expiry_date'] !== null && $right['expiry_date'] === null) {
                return -1;
            }

            return strcmp((string) $left['expiry_date'], (string) $right['expiry_date']);
        });
        foreach ($available as $lot) {
            if (bccomp($lot['available'], $needed, 3) >= 0) {
                return $lot;
            }
        }

        return $available[0] ?? null;
    }

    private function assertProductionReceiptClassified(StockMovementInput $input): void
    {
        if ($input->type !== StockMovementType::ProductionReceipt
            || ! in_array($input->referenceType, ['work_order', 'work_order_output'], true)) {
            return;
        }
        $source = $input->referenceType === 'work_order_output'
            ? WorkOrderOutput::query()->findOrFail($input->referenceId)
            : null;
        $workOrder = WorkOrder::query()->whereKey($source?->work_order_id ?? $input->referenceId)->lockForUpdate()->firstOrFail();
        if (($source && (int) $source->held_quantity > 0) || (! $source && $workOrder->outputs()->where('held_quantity', '>', 0)->exists())) {
            throw new BusinessRuleException('Held historical output requires independent QC recovery before inventory receipt.');
        }
        $trials = $workOrder->outputs()->whereNotNull('trial_inspection_id');
        if ($source) {
            $trials->whereKey($source->id);
        }
        $trialSources = (clone $trials)->orderBy('trial_inspection_id')->get(['id', 'trial_inspection_id']);
        Inspection::query()->whereIn('id', $trialSources->pluck('trial_inspection_id'))->orderBy('id')->lockForUpdate()->get();
        $pending = $trials->whereNull('trial_settled_at')->orderBy('id')->lockForUpdate()->first();
        if ($pending) {
            throw new BusinessRuleException('Startup trial pieces await QC disposition before finished-goods receipt.');
        }
    }

    private function validateInput(StockMovementInput $in): void
    {
        if ($in->mrbReleaseId !== null
            && ($in->type !== StockMovementType::Transfer || $in->referenceType !== 'material_review_record'
                || $in->referenceId === null || $in->fromLocationId === null || $in->toLocationId === null)) {
            throw new InvalidMovementException('An MRB release authorization is valid only for a transfer linked to that MRB.');
        }
        if (($in->idempotencyKey === null) !== ($in->idempotencyFingerprint === null)) {
            throw new InvalidMovementException('A stock-movement idempotency key and fingerprint must be supplied together.');
        }
        if ($in->idempotencyKey !== null
            && (strlen($in->idempotencyKey) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/D', $in->idempotencyKey))) {
            throw new InvalidMovementException('The stock-movement idempotency key is invalid.');
        }
        if ($in->idempotencyFingerprint !== null && ! preg_match('/^[a-f0-9]{64}$/D', $in->idempotencyFingerprint)) {
            throw new InvalidMovementException('The stock-movement idempotency fingerprint is invalid.');
        }
        if ($in->totalCostOverride !== null
            && (! preg_match('/^\d{1,14}(?:\.\d{1,2})?$/D', $in->totalCostOverride)
                || bccomp($in->totalCostOverride, '0', 2) < 0)) {
            throw new InvalidMovementException('The stock-movement total cost override must be a non-negative currency amount with at most two decimal places.');
        }
        if ($in->totalCostOverride !== null) {
            $sourceOverride = match ($in->type) {
                StockMovementType::MaterialReturn,
                StockMovementType::DeliveryReturn => $in->referenceType === 'stock_movement' && $in->referenceId !== null,
                StockMovementType::DeliveryCustomerReturn => $in->referenceType === 'return_request_item' && $in->referenceId !== null,
                default => false,
            };
            if (! $sourceOverride) {
                throw new InvalidMovementException('Source-value overrides are allowed only on a source-linked material or delivery return.');
            }
        }
        if (str_starts_with($in->quantity, '-')) {
            throw new InvalidMovementException('Quantity must be positive.');
        }
        if ($in->type === StockMovementType::DeliveryReturn) {
            $this->validateDeliveryReturnInput($in);
        }
        if ($in->type === StockMovementType::DeliveryCustomerReturn) {
            $this->validateDeliveryCustomerReturnInput($in);
        }

        app(UomConversionService::class)->assertBaseQuantity(Item::withTrashed()->findOrFail($in->itemId), $in->quantity);

        if (bccomp($in->quantity, '0', 3) <= 0) {
            throw new InvalidMovementException('Quantity must be positive.');
        }

        // Receipts require a destination; issues require a source.
        $hasFrom = $in->fromLocationId !== null;
        $hasTo = $in->toLocationId !== null;

        if ($in->type === StockMovementType::Transfer) {
            if (! $hasFrom || ! $hasTo) {
                throw new InvalidMovementException('Transfer requires both a source and destination location.');
            }
            if ($in->fromLocationId === $in->toLocationId) {
                throw new InvalidMovementException('Transfer source and destination must differ.');
            }

            return;
        }

        if ($in->type->isReceipt() && ! $hasTo) {
            throw new InvalidMovementException("{$in->type->value} requires a destination location.");
        }
        if (in_array($in->type, [StockMovementType::MaterialIssue, StockMovementType::AdjustmentOut, StockMovementType::Scrap]) && ! $in->fromLocationId) {
            throw new InvalidMovementException("{$in->type->value} requires a source location.");
        }

        // F-02 — consumption must never draw from quarantine/scrap zones. Stock
        // there is held under an MRB (or already written off). Only deliberate
        // write-offs (adjustment_out, scrap, return_to_vendor) and quarantine
        // mechanics (transfer) may touch it.
        $this->assertConsumableSource($in->fromLocationId, $in->type);
    }

    private function lockCustomerReturnReleaseItem(StockMovementInput $in, MaterialReviewRecord $mrb): ReturnRequestItem
    {
        $denied = static fn (): BusinessRuleException => new BusinessRuleException(
            'Customer-return quarantine stock can leave only through its linked MRB after inspection and disposition.'
        );
        $candidate = ReturnRequestItem::query()->find($mrb->return_request_item_id);
        $rma = $candidate
            ? ReturnRequest::query()->lockForUpdate()->find($candidate->return_request_id)
            : null;
        $line = $rma
            ? ReturnRequestItem::query()->with('deliveryAttemptOutcomeMovement.stockMovement')
                ->where('return_request_id', $rma->id)
                ->lockForUpdate()->find($mrb->return_request_item_id)
            : null;

        if (! $rma || ! $line || (int) $line->return_request_id !== (int) $rma->id
            || (int) $mrb->return_request_item_id !== (int) $line->id
            || $in->referenceType !== 'material_review_record' || $in->referenceId !== (int) $mrb->id
            || (int) $mrb->item_id !== (int) $line->item_id
            || (int) $mrb->quarantine_location_id !== (int) $line->quarantine_location_id
            || bccomp((string) $mrb->quantity, $this->customerReturnQuantity($line), 3) !== 0
            || $rma->type !== ReturnRequestType::CustomerReturn
            || ! in_array($rma->status, [ReturnRequestStatus::Inspected, ReturnRequestStatus::Completed], true)
            || $rma->disposition_status !== 'disposed'
            || (int) $line->item_id !== $in->itemId
            || (int) $line->quarantine_location_id !== $in->fromLocationId
            || $line->quarantine_status !== 'held'
            || $line->quarantine_release_movement_id !== null
            || bccomp((string) $line->stock_movement_quantity, '0', 3) > 0
            || $line->stock_movement_id !== null
            || ! in_array($line->disposition, [DispositionType::Restock->value, DispositionType::Rework->value], true)
            || (string) $in->lotNumber !== (string) $line->lot_number) {
            throw $denied();
        }

        $quantity = (bool) $line->receipt_recorded || bccomp((string) $line->returned_quantity, '0', 3) > 0
            ? (string) $line->returned_quantity
            : (string) $line->quantity;
        if (bccomp($quantity, '0', 3) <= 0 || bccomp($quantity, $in->quantity, 3) !== 0) {
            throw $denied();
        }

        $source = $line->quarantine_movement_id === null
            ? null
            : StockMovement::query()->lockForUpdate()->find($line->quarantine_movement_id);
        if (! $source || ! $this->isReturnQuarantineReceipt($source, $rma, $line)
            || (int) $source->item_id !== (int) $line->item_id
            || (int) $source->to_location_id !== (int) $line->quarantine_location_id) {
            throw $denied();
        }

        $receipts = ReturnReceiptItem::query()->where('return_request_item_id', $line->id)
            ->lockForUpdate()->get();
        if ($receipts->isNotEmpty()) {
            $received = '0.000';
            $sourceIsLinked = false;
            foreach ($receipts as $receipt) {
                if (bccomp((string) $receipt->quantity, '0', 3) === 0 && $receipt->stock_movement_id === null) {
                    continue;
                }
                $receiptMovement = $receipt->stock_movement_id === null
                    ? null
                    : StockMovement::query()->lockForUpdate()->find($receipt->stock_movement_id);
                if (! $receiptMovement || ! $this->isReturnQuarantineReceipt($receiptMovement, $rma, $line)
                    || (int) $receiptMovement->item_id !== (int) $line->item_id
                    || (int) $receiptMovement->to_location_id !== (int) $line->quarantine_location_id
                    || ! $line->hasConservedReceiptAllocation($receiptMovement)) {
                    throw $denied();
                }
                $sourceIsLinked = $sourceIsLinked || (int) $receiptMovement->id === (int) $source->id;
                $received = bcadd($received, (string) $receipt->quantity, 3);
            }
            if (! $sourceIsLinked || bccomp($received, $quantity, 3) !== 0) {
                throw $denied();
            }
        } elseif (bccomp((string) $source->quantity, $quantity, 3) < 0) {
            throw $denied();
        }

        $destination = $this->activeLocation((int) $in->toLocationId);
        if (in_array($destination->zone?->zone_type, [WarehouseZoneType::Quarantine, WarehouseZoneType::Scrap], true)) {
            throw $denied();
        }

        if ($line->product_id !== null || config('inspection.checklist_capture_enabled')) {
            $inspections = $line->qualityInspections()
                ->lockForUpdate()->get();
            $acceptedStatuses = $line->disposition === DispositionType::Restock->value
                ? [InspectionStatus::Passed]
                : [InspectionStatus::Passed, InspectionStatus::Failed];
            if ($inspections->isEmpty() || $inspections->contains(
                fn (Inspection $inspection): bool => ! in_array($inspection->status, $acceptedStatuses, true)
                    || ($inspection->requiresMakerChecker() && ! $inspection->isMakerChecked())
            )) {
                throw new BusinessRuleException('Customer-return stock needs a completed Quality inspection and any required independent review.');
            }
        }

        if ($source->movement_type === StockMovementType::DeliveryReturn) {
            if ($in->unitCost === null || bccomp((string) $in->unitCost, (string) $source->unit_cost, 4) !== 0) {
                throw $denied();
            }
        } elseif ($in->unitCost !== null) {
            throw $denied();
        }

        return $line;
    }

    private function customerReturnQuantity(ReturnRequestItem $line): string
    {
        return (bool) $line->receipt_recorded || bccomp((string) $line->returned_quantity, '0', 3) > 0
            ? (string) $line->returned_quantity
            : (string) $line->quantity;
    }

    private function isReturnQuarantineReceipt(StockMovement $movement, ReturnRequest $rma, ReturnRequestItem $line): bool
    {
        $type = $movement->movement_type instanceof StockMovementType
            ? $movement->movement_type
            : StockMovementType::tryFrom((string) $movement->movement_type);

        return match ($type) {
            StockMovementType::AdjustmentIn => $movement->reference_type === 'return_request'
                && (int) $movement->reference_id === (int) $rma->id,
            StockMovementType::DeliveryCustomerReturn => $movement->reference_type === 'return_request_item'
                && (int) $movement->reference_id === (int) ($line->split_parent_item_id ?? $line->id),
            StockMovementType::DeliveryReturn => $this->isTruckReturnReceipt($movement, $rma, $line),
            default => false,
        };
    }

    private function isTruckReturnReceipt(StockMovement $movement, ReturnRequest $rma, ReturnRequestItem $line): bool
    {
        $sourceIssue = $line->deliveryAttemptOutcomeMovement?->stockMovement;

        return $rma->delivery_attempt_outcome_id !== null && $sourceIssue !== null
            && $sourceIssue->movement_type === StockMovementType::Delivery
            && (int) $sourceIssue->item_id === (int) $line->item_id
            && $movement->reference_type === 'stock_movement'
            && (int) $movement->reference_id === (int) $sourceIssue->id;
    }

    private function validateDeliveryReturnInput(StockMovementInput $in): void
    {
        if ($in->referenceType !== 'stock_movement' || $in->referenceId === null
            || $in->deliveryReturnItemId === null || $in->fromLocationId !== null
            || $in->toLocationId === null || $in->totalCostOverride === null) {
            throw new InvalidMovementException('A delivery return must reference its exact reconciled RMA line and original delivery issue.');
        }

        $source = StockMovement::query()->lockForUpdate()->find($in->referenceId);
        $returnItem = ReturnRequestItem::query()->with('deliveryAttemptOutcomeMovement.outcomeItem.outcome', 'returnRequest')
            ->lockForUpdate()->find($in->deliveryReturnItemId);
        $outcomeMovement = $returnItem?->deliveryAttemptOutcomeMovement;
        $outcome = $outcomeMovement?->outcomeItem?->outcome;
        $destination = $this->activeLocation((int) $in->toLocationId);
        $zoneType = $destination->zone?->zone_type;
        $zoneType = $zoneType instanceof WarehouseZoneType ? $zoneType : WarehouseZoneType::tryFrom((string) $zoneType);

        if (! $source || $source->movement_type !== StockMovementType::Delivery
            || $source->reference_type !== 'delivery_item'
            || (int) $source->item_id !== $in->itemId
            || (int) $returnItem?->item_id !== $in->itemId
            || (int) $outcomeMovement?->stock_movement_id !== (int) $source->id
            || ! $outcome?->reconciled_at
            || $outcomeMovement?->received_quantity === null
            || (int) $returnItem?->quarantine_location_id !== (int) $in->toLocationId
            || bccomp((string) $returnItem?->quantity, $in->quantity, 3) !== 0
            || bccomp((string) $outcomeMovement->received_quantity, $in->quantity, 3) < 0
            || bccomp((string) $in->unitCost, (string) $source->unit_cost, 4) !== 0
            || (string) $in->lotNumber !== (string) $source->lot_number
            || (string) $in->expiryDate !== (string) $source->expiry_date?->toDateString()
            || $zoneType !== WarehouseZoneType::Quarantine) {
            throw new InvalidMovementException('A delivery-return receipt must match its reconciled source, RMA quantity, lot, and quarantine bin.');
        }

        $prior = StockMovement::query()->where('movement_type', StockMovementType::DeliveryReturn->value)
            ->where('reference_type', 'stock_movement')->where('reference_id', $source->id)
            ->lockForUpdate()->get();
        $priorQuantity = $prior->reduce(static fn (string $sum, StockMovement $row): string => bcadd($sum, (string) $row->quantity, 3), '0.000');
        if (bccomp(bcadd($priorQuantity, $in->quantity, 3), (string) $outcomeMovement->received_quantity, 3) > 0) {
            throw new InvalidMovementException('Cumulative delivery returns cannot exceed the quantity physically reconciled for this source.');
        }

        $expected = app(DeliveryCostRecognitionService::class)
            ->deliveryReturnCost($returnItem, $in->quantity);
        if (bccomp($expected, (string) $in->totalCostOverride, 2) !== 0) {
            throw new InvalidMovementException('Delivery-return value must match the remaining source-backed transit or loss value.');
        }
    }

    private function validateDeliveryCustomerReturnInput(StockMovementInput $in): void
    {
        if ($in->referenceType !== 'return_request_item' || $in->referenceId === null
            || $in->customerReturnItemId === null || $in->referenceId !== $in->customerReturnItemId
            || $in->fromLocationId !== null || $in->toLocationId === null || $in->totalCostOverride === null) {
            throw new InvalidMovementException('A source-bound customer return must reference its exact RMA line and quarantine location.');
        }

        $returnItem = ReturnRequestItem::query()->with([
            'sourceDeliveryItem.delivery', 'sourceDeliveryItem.salesOrderItem.product', 'returnRequest',
        ])->lockForUpdate()->find($in->customerReturnItemId);
        $deliveryItem = $returnItem?->sourceDeliveryItem;
        $delivery = $deliveryItem?->delivery;
        $rmaType = $returnItem?->returnRequest?->type;
        $rmaType = $rmaType instanceof \BackedEnum ? $rmaType->value : (string) $rmaType;
        $rmaStatus = $returnItem?->returnRequest?->status;
        $rmaStatus = $rmaStatus instanceof \BackedEnum ? $rmaStatus->value : (string) $rmaStatus;
        $destination = $this->activeLocation((int) $in->toLocationId);
        $zoneType = $destination->zone?->zone_type;
        $zoneType = $zoneType instanceof WarehouseZoneType ? $zoneType : WarehouseZoneType::tryFrom((string) $zoneType);
        $issues = $deliveryItem ? StockMovement::query()->where('movement_type', StockMovementType::Delivery->value)
            ->where('reference_type', 'delivery_item')->where('reference_id', $deliveryItem->id)->orderBy('id')->get() : collect();

        if (! $returnItem || ! $deliveryItem || ! $delivery
            || $delivery->cost_recognition_mode !== DeliveryCostingMode::Transit
            || $rmaType !== ReturnRequestType::CustomerReturn->value
            || ! in_array($rmaStatus, ['approved', 'received'], true)
            || (int) $returnItem->item_id !== $in->itemId
            || (int) $returnItem->quarantine_location_id !== (int) $in->toLocationId
            || bccomp((string) $returnItem->quantity, '0', 3) <= 0
            || $zoneType !== WarehouseZoneType::Quarantine
            || $issues->isEmpty()
            || $issues->contains(fn (StockMovement $source): bool => (int) $source->item_id !== $in->itemId
                || (string) $source->lot_number !== (string) $in->lotNumber
                || (string) $source->expiry_date?->toDateString() !== (string) $in->expiryDate)) {
            throw new InvalidMovementException('A delivery customer-return receipt must match an approved customer RMA, its accepted delivery source, and quarantine lot.');
        }

        $expected = app(DeliveryCostRecognitionService::class)
            ->customerReturnCost($returnItem, $in->quantity);
        if (bccomp($expected, (string) $in->totalCostOverride, 2) !== 0) {
            throw new InvalidMovementException('Customer-return value must reconcile to the remaining immutable delivery source cost.');
        }
    }

    private function assertConsumableSource(?int $fromLocationId, StockMovementType $type): void
    {
        if ($fromLocationId === null) {
            return;
        }
        if (! in_array($type, [StockMovementType::MaterialIssue, StockMovementType::Delivery], true)) {
            return;
        }

        $location = $this->activeLocation($fromLocationId);
        $zoneType = $location->zone?->zone_type;

        $zoneValue = $zoneType instanceof WarehouseZoneType ? $zoneType->value : (string) $zoneType;
        if (in_array($zoneValue, [WarehouseZoneType::Quarantine->value, WarehouseZoneType::Scrap->value], true)) {
            throw new BusinessRuleException(
                "Cannot {$type->value} from location {$fromLocationId}: stock in a {$zoneValue} zone is held (MRB) or scrapped."
            );
        }
    }

    private function assertMovementLocationsUsable(StockMovementInput $input): void
    {
        if ($input->toLocationId !== null) {
            $destination = $this->activeLocation($input->toLocationId);
            if ($destination->is_blocked && $input->type->isReceipt()) {
                throw new BusinessRuleException("Warehouse location {$input->toLocationId} is blocked for receiving.");
            }
        }

        if ($input->fromLocationId !== null) {
            $this->activeLocation($input->fromLocationId);
        }
    }

    public function assertActiveLocation(int $locationId, bool $lockForUpdate = false): void
    {
        $this->activeLocation($locationId, $lockForUpdate);
    }

    /** Keep outbound adjustments that cannot be fulfilled out of the approval queue. */
    public function assertAdjustmentOutCapacity(int $itemId, int $locationId, string $quantity): void
    {
        $normalizedQuantity = bcadd($quantity, '0', 3);
        $held = app(QualityStockHoldService::class)->heldQuantity($itemId, $locationId);
        $level = StockLevel::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();
        $available = bcsub((string) ($level?->quantity ?? '0.000'), (string) ($level?->reserved_quantity ?? '0.000'), 3);
        if (bccomp($normalizedQuantity, bcsub($available, $held, 3), 3) <= 0) {
            return;
        }

        if (bccomp($held, '0', 3) > 0) {
            throw new BusinessRuleException(self::QUALITY_HELD_ADJUSTMENT_ERROR);
        }

        throw new InsufficientStockException(
            "Insufficient available stock at location {$locationId} for item {$itemId}: "
            ."needed {$normalizedQuantity}, available {$available}."
        );
    }

    public function assertCustomerReturnQuarantineCanBeAdjusted(int $itemId, int $locationId): void
    {
        $location = $this->activeLocation($locationId);
        if ($location->zone?->zone_type !== WarehouseZoneType::Quarantine) {
            return;
        }

        $heldReturnExists = ReturnRequestItem::query()
            ->where('item_id', $itemId)
            ->where('quarantine_location_id', $locationId)
            ->where('quarantine_status', 'held')
            ->whereHas('returnRequest', fn ($query) => $query
                ->where('type', ReturnRequestType::CustomerReturn->value))
            ->exists();

        if ($heldReturnExists) {
            throw new BusinessRuleException(
                'Customer-return stock held in quarantine cannot be adjusted; complete its RMA or MRB disposition first.'
            );
        }
    }

    private function activeLocation(int $locationId, bool $lockForUpdate = false): WarehouseLocation
    {
        $query = WarehouseLocation::query()->with('zone.warehouse')->whereKey($locationId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $location = $query->first();

        if (! $location || ! $location->is_active || ! $location->zone?->warehouse?->is_active) {
            throw new BusinessRuleException("Warehouse location {$locationId} is missing or inactive.");
        }

        return $location;
    }

    /** @param array<int, int|null> $locationIds */
    private function assertLocationsNotFrozen(array $locationIds, bool $checkFreeze = true): void
    {
        $ids = array_values(array_unique(array_filter($locationIds)));
        if ($ids === []) {
            return;
        }

        $locations = WarehouseLocation::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'zone_id']);

        if (! $checkFreeze) {
            return;
        }

        foreach ($locations as $location) {
            $session = StockCountSession::query()
                ->where('status', StockCountSessionStatus::InProgress->value)
                ->where(function ($query) use ($location) {
                    $query->whereHas('items', fn ($items) => $items->where('location_id', $location->id))
                        ->orWhere(function ($scope) use ($location) {
                            $scope->where('scope', 'zone')->where('zone_id', $location->zone_id);
                        })
                        ->orWhere(function ($scope) use ($location) {
                            $scope->whereIn('scope', ['warehouse', 'full'])
                                ->where(function ($warehouse) use ($location) {
                                    $warehouse->whereNull('warehouse_id')
                                        ->orWhereIn('warehouse_id', function ($zones) use ($location) {
                                            $zones->select('warehouse_id')
                                                ->from('warehouse_zones')
                                                ->where('id', $location->zone_id);
                                        });
                                });
                        });
                })
                ->lockForUpdate()
                ->first();

            if ($session) {
                // Record state the operator can clear — finish or cancel the
                // count, or move the stock elsewhere — and the message names the
                // count session to go to. Never a server fault.
                throw new BusinessRuleException(
                    "Warehouse location {$location->id} is frozen by stock count {$session->session_number}."
                );
            }
        }
    }

    /** Reserve stock for a work order (no quantity change, just reservation). */
    public function reserve(int $itemId, int $locationId, string $quantity): void
    {
        // A negative reserve silently DECREASED reserved_quantity (it passed the
        // availability check, since any negative is below available), driving it
        // below zero and over-stating what the location can issue. Reservations
        // are a one-directional counter; release() is the inverse operation.
        $this->assertPositiveQuantity($quantity, 'reserve');

        DB::transaction(function () use ($itemId, $locationId, $quantity) {
            $this->assertLocationsNotFrozen([$locationId]);
            $this->activeLocation($locationId);
            // F-02 — never reserve stock held in quarantine/scrap zones.
            $zoneType = WarehouseLocation::query()
                ->where('id', $locationId)
                ->with('zone:id,zone_type')
                ->first()
                ?->zone
                ?->zone_type;
            $zoneValue = $zoneType instanceof WarehouseZoneType ? $zoneType->value : (string) $zoneType;
            if (in_array($zoneValue, [WarehouseZoneType::Quarantine->value, WarehouseZoneType::Scrap->value], true)) {
                throw new BusinessRuleException(
                    "Cannot reserve stock at location {$locationId}: its {$zoneValue} zone is held (MRB) or scrapped."
                );
            }
            app(QualityStockHoldService::class)->heldQuantity($itemId, $locationId);
            $level = $this->lockOrCreate($itemId, $locationId);
            $available = bcsub((string) $level->quantity, (string) $level->reserved_quantity, 3);
            $available = $this->locationSummary->availability(collect([$level]))['usable_quantity'];
            if (bccomp($available, $quantity, 3) < 0) {
                if (bccomp(app(QualityStockHoldService::class)->heldQuantity($itemId, $locationId), '0', 3) > 0) {
                    throw new BusinessRuleException('Cannot reserve QC-held stock for production. Release the batch first.');
                }
                throw new InsufficientStockException(
                    "Cannot reserve {$quantity}: only {$available} available at location {$locationId}."
                );
            }
            $level->reserved_quantity = bcadd((string) $level->reserved_quantity, $quantity, 3);
            $level->lock_version++;
            $level->save();
        });
    }

    /** Release a reservation without issuing. */
    public function release(int $itemId, int $locationId, string $quantity): void
    {
        // A negative release INCREASED reserved_quantity, and the `< 0` clamp
        // below only guards the floor — so release(-999) pushed reserved above
        // on-hand, making available() negative and the location unissuable.
        $this->assertPositiveQuantity($quantity, 'release');

        DB::transaction(function () use ($itemId, $locationId, $quantity) {
            $this->assertLocationsNotFrozen([$locationId]);
            $level = $this->lockOrCreate($itemId, $locationId);
            $rem = bcsub((string) $level->reserved_quantity, $quantity, 3);
            if (bccomp($rem, '0', 3) < 0) {
                $rem = '0';
            }
            $level->reserved_quantity = $rem;
            $level->lock_version++;
            $level->save();
        });
    }

    /**
     * Reservation quantities are magnitudes, never signed deltas. `move()`
     * already enforces this for physical movements via validateInput(); the
     * reservation pair had no equivalent guard.
     */
    private function assertPositiveQuantity(string $quantity, string $operation): void
    {
        if (! is_numeric($quantity) || bccomp($quantity, '0', 3) <= 0) {
            throw new InvalidMovementException(
                "Cannot {$operation} a non-positive quantity ({$quantity}); reservation quantities must be greater than zero."
            );
        }
    }

    private function round2(string $v): string
    {
        $abs = ltrim($v, '-');
        $isNeg = strlen($v) > strlen($abs);
        $r = bcadd($abs, '0.005', 2);

        return $isNeg ? '-'.$r : $r;
    }

    private function round4(string $v): string
    {
        $abs = ltrim($v, '-');
        $isNeg = strlen($v) > strlen($abs);
        $r = bcadd($abs, '0.00005', 4);

        return $isNeg ? '-'.$r : $r;
    }

    /**
     * OGAMI-012 — stamp lot/expiry metadata onto a just-created movement.
     *
     * Kept separate from move() (whose input DTO is owned elsewhere) so lot
     * capture is fully OPTIONAL and null-safe: callers that have a lot pass it
     * here right after move(); everyone else is untouched. No-ops when both
     * values are null.
     */
    public function stampLot(StockMovement $movement, ?string $lotNumber, ?string $expiryDate = null): StockMovement
    {
        if (($lotNumber === null || $lotNumber === '') && ($expiryDate === null || $expiryDate === '')) {
            return $movement;
        }
        if ($lotNumber !== null && $lotNumber !== '') {
            $movement->lot_number = $lotNumber;
        }
        if ($expiryDate !== null && $expiryDate !== '') {
            $movement->expiry_date = $expiryDate;
        }
        $movement->save();

        return $movement;
    }

    /**
     * OGAMI-012 — lot ledger: every movement carrying a given lot for an item,
     * oldest first. Lets a supplier lot be traced GRN-receipt → material-issue.
     *
     * @return Collection<int, StockMovement>
     */
    public function lotHistory(int $itemId, string $lotNumber): Collection
    {
        return StockMovement::query()
            ->where('item_id', $itemId)
            ->where('lot_number', $lotNumber)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
