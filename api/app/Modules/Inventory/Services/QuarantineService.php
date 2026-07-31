<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Quality\Enums\NcrDisposition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

    /** @param array{status?:string, item_id?:int|string, per_page?:int} $filters */
    public function list(array $filters): LengthAwarePaginator
    {
        $q = MaterialReviewRecord::query()
            ->with([
                'item:id,code,name,unit_of_measure',
                'sourceLocation.zone', 'quarantineLocation.zone', 'releaseLocation.zone',
                'ncr:id,ncr_number', 'holder:id,name', 'releaser:id,name',
            ]);

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['item_id'])) {
            // MrbController::index() forwards the raw query bag, so the SPA's hash
            // string would hit a bigint column (Postgres 22P02 → 500).
            $q->where('item_id', HashIdFilter::decode($filters['item_id'], Item::class) ?? 0);
        }

        return $q->orderByDesc('held_at')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(MaterialReviewRecord $mrb): MaterialReviewRecord
    {
        return $mrb->load([
            'item:id,code,name,unit_of_measure',
            'sourceLocation.zone', 'quarantineLocation.zone', 'releaseLocation.zone',
            'ncr:id,ncr_number', 'inspection:id',
            'holder:id,name', 'releaser:id,name',
            'holdMovement', 'releaseMovement',
        ]);
    }

    /**
     * Raise a hold: physically move `quantity` of `item_id` from its source
     * location into a Quarantine-zone location and open a Held MRB record.
     *
     * @param array{
     *   item_id:int, quantity:string|float|int, source_location_id:int,
     *   quarantine_location_id?:int|null, ncr_id?:int|null,
     *   inspection_id?:int|null, notes?:string|null
     * } $data
     */
    public function hold(array $data, User $by): MaterialReviewRecord
    {
        return DB::transaction(function () use ($data, $by) {
            $itemId   = (int) $data['item_id'];
            $qty      = (string) $data['quantity'];
            $sourceId = (int) $data['source_location_id'];

            $source = WarehouseLocation::query()->with('zone')->findOrFail($sourceId);

            $quarantineId = ! empty($data['quarantine_location_id'])
                ? (int) $data['quarantine_location_id']
                : $this->resolveZoneLocation($source, WarehouseZoneType::Quarantine)->id;

            if ($quarantineId === $sourceId) {
                throw new BusinessRuleException('Source and quarantine locations must differ.');
            }

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

            $mrbNumber = $this->sequences->generate('mrb');

            $movement = $this->movements->move(new StockMovementInput(
                type: StockMovementType::Transfer,
                itemId: $itemId,
                quantity: $qty,
                fromLocationId: $sourceId,
                toLocationId: $quarantineId,
                referenceType: 'material_review_record',
                referenceId: null,
                remarks: "MRB hold {$mrbNumber}",
                createdBy: $by->id,
            ));

            $mrb = new MaterialReviewRecord();
            $mrb->fill([
                'mrb_number'             => $mrbNumber,
                'ncr_id'                 => $data['ncr_id'] ?? null,
                'inspection_id'          => $data['inspection_id'] ?? null,
                'item_id'                => $itemId,
                'quantity'               => $qty,
                'source_location_id'     => $sourceId,
                'quarantine_location_id' => $quarantineId,
                'hold_movement_id'       => $movement->id,
                'held_by'                => $by->id,
                'held_at'                => now(),
                'notes'                  => $data['notes'] ?? null,
            ]);
            $mrb->status = MrbStatus::Held;
            $mrb->save();

            // Backfill the movement reference now that the MRB id exists.
            $movement->reference_id = $mrb->id;
            $movement->save();

            return $mrb;
        });
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
    ): MaterialReviewRecord {
        if ($mrb->status !== MrbStatus::Held) {
            throw new BusinessRuleException("MRB {$mrb->mrb_number} is not held (status: {$mrb->status->value}).");
        }

        $dispo = NcrDisposition::tryFrom($disposition);
        if ($dispo === null) {
            throw new BusinessRuleException("Invalid disposition: {$disposition}.");
        }

        return DB::transaction(function () use ($mrb, $dispo, $by, $targetLocationId, $notes) {
            $qty       = (string) $mrb->quantity;
            $fromId    = $mrb->quarantine_location_id;
            $quarantine = WarehouseLocation::query()->with('zone')->findOrFail($fromId);

            $releaseLocationId = null;
            $newStatus         = MrbStatus::Released;

            switch ($dispo) {
                case NcrDisposition::Rework:
                case NcrDisposition::UseAsIs:
                    if (! $targetLocationId) {
                        throw new BusinessRuleException('A target good location is required for rework/use-as-is release.');
                    }
                    $target = WarehouseLocation::query()->with('zone')->findOrFail($targetLocationId);
                    $type = $this->zoneType($target);
                    if (in_array($type, [WarehouseZoneType::Quarantine, WarehouseZoneType::Scrap], true)) {
                        throw new BusinessRuleException('Release target must be a good location, not a quarantine/scrap zone.');
                    }
                    $movement = $this->movements->move(new StockMovementInput(
                        type: StockMovementType::Transfer,
                        itemId: $mrb->item_id,
                        quantity: $qty,
                        fromLocationId: $fromId,
                        toLocationId: $targetLocationId,
                        referenceType: 'material_review_record',
                        referenceId: $mrb->id,
                        remarks: "MRB release ({$dispo->value}) {$mrb->mrb_number}",
                        createdBy: $by->id,
                    ));
                    $releaseLocationId = $targetLocationId;
                    $newStatus = MrbStatus::Released;
                    break;

                case NcrDisposition::Scrap:
                    $scrapLocation = $this->resolveZoneLocation($quarantine, WarehouseZoneType::Scrap);
                    $movement = $this->movements->move(new StockMovementInput(
                        type: StockMovementType::Transfer,
                        itemId: $mrb->item_id,
                        quantity: $qty,
                        fromLocationId: $fromId,
                        toLocationId: $scrapLocation->id,
                        referenceType: 'material_review_record',
                        referenceId: $mrb->id,
                        remarks: "MRB scrap {$mrb->mrb_number}",
                        createdBy: $by->id,
                    ));
                    $releaseLocationId = $scrapLocation->id;
                    $newStatus = MrbStatus::Scrapped;
                    break;

                case NcrDisposition::ReturnToSupplier:
                    $movement = $this->movements->move(new StockMovementInput(
                        type: StockMovementType::ReturnToVendor,
                        itemId: $mrb->item_id,
                        quantity: $qty,
                        fromLocationId: $fromId,
                        toLocationId: null,
                        referenceType: 'material_review_record',
                        referenceId: $mrb->id,
                        remarks: "MRB return-to-supplier {$mrb->mrb_number}",
                        createdBy: $by->id,
                    ));
                    $newStatus = MrbStatus::Returned;
                    break;
            }

            $mrb->fill([
                'disposition'         => $dispo->value,
                'release_movement_id' => $movement->id,
                'released_by'         => $by->id,
                'released_at'         => now(),
                'release_location_id' => $releaseLocationId,
            ]);
            if ($notes !== null && $notes !== '') {
                $mrb->notes = trim(($mrb->notes ? $mrb->notes."\n" : '').$notes);
            }
            $mrb->status = $newStatus;
            $mrb->save();

            return $mrb;
        });
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
            throw new RuntimeException("Source location {$reference->id} has no zone/warehouse.");
        }
        $warehouseId = $refZone->warehouse_id;

        $location = WarehouseLocation::query()
            ->where('is_active', true)
            ->whereHas('zone', function ($q) use ($warehouseId, $type) {
                $q->where('warehouse_id', $warehouseId)
                    ->where('zone_type', $type->value);
            })
            ->orderBy('id')
            ->first();

        if (! $location) {
            throw new RuntimeException(
                "No active {$type->label()} location exists in warehouse {$warehouseId}. ".
                'Create one before raising an MRB hold/release.'
            );
        }

        return $location;
    }
}
