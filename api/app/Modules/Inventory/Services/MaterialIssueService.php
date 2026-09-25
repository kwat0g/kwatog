<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderMaterialUsageService;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MaterialIssueService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly StockMovementService $movements,
        private readonly WorkOrderMaterialUsageService $materialUsage,
        private readonly MaterialReturnService $materialReturns,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = MaterialIssueSlip::query()
            ->with(['issuer:id,name,role_id', 'creator:id,name,role_id', 'workOrder:id,wo_number']);
        if (! empty($filters['status'])) $q->where('status', $filters['status']);
        if (! empty($filters['from'])) $q->whereDate('issued_date', '>=', $filters['from']);
        if (! empty($filters['to']))   $q->whereDate('issued_date', '<=', $filters['to']);
        if (! empty($filters['search'])) {
            $q->where('slip_number', SearchOperator::like(), SearchOperator::contains($filters['search']));
        }
        return $q->orderByDesc('issued_date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    /**
     * Narrow lookup contract for the Warehouse material-issue form. The source
     * list includes available stock and reservations owned by the selected WO;
     * unrelated reservations never make unavailable stock look issuable.
     *
     * @return array{work_orders: array<int, array<string, mixed>>, sources: array<int, array<string, mixed>>, reservations: array<int, array<string, mixed>>}
     */
    public function options(?int $workOrderId = null, ?int $itemId = null): array
    {
        // Confirmed, active and paused work orders all retain material demand.
        $supportedStatuses = [WorkOrderStatus::Confirmed, WorkOrderStatus::InProgress, WorkOrderStatus::Paused];
        $workOrders = WorkOrder::query()
            ->with('product:id,part_number')
            ->whereIn('status', array_map(static fn (WorkOrderStatus $status) => $status->value, $supportedStatuses))
            ->orderBy('wo_number')
            ->get(['id', 'wo_number', 'product_id', 'status'])
            ->map(static fn (WorkOrder $workOrder) => [
                'id' => $workOrder->hash_id,
                'wo_number' => $workOrder->wo_number,
                'status' => $workOrder->status->value,
                'product_part_number' => $workOrder->product?->part_number,
            ])
            ->all();

        $eligibleWorkOrderId = null;
        if ($workOrderId !== null) {
            $eligibleWorkOrderId = WorkOrder::query()
                ->whereKey($workOrderId)
                ->whereIn('status', array_map(static fn (WorkOrderStatus $status) => $status->value, $supportedStatuses))
                ->value('id');
        }

        $sources = [];
        $reservations = [];
        if ($itemId !== null) {
            $sourceByLocation = [];
            $levels = StockLevel::query()
                ->with('location.zone.warehouse')
                ->where('item_id', $itemId)
                ->whereRaw('stock_levels.quantity > stock_levels.reserved_quantity')
                ->whereHas('location', fn ($query) => $query->where('is_active', true)
                    ->whereHas('zone', fn ($zone) => $zone
                        ->whereNotIn('zone_type', [WarehouseZoneType::Quarantine->value, WarehouseZoneType::Scrap->value])
                        ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('is_active', true))))
                ->get();

            foreach ($levels as $level) {
                $location = $level->location;
                $sourceByLocation[$location->id] = [
                    'location_id' => $location->hash_id,
                    'label' => $location->full_code,
                    'available' => $level->available,
                    'reserved_for_work_order' => '0.000',
                    'is_blocked' => (bool) $location->is_blocked,
                ];
            }

            $matchingReservations = collect();
            if ($eligibleWorkOrderId !== null) {
                $matchingReservations = MaterialReservation::query()
                    ->with('location.zone.warehouse')
                    ->where('work_order_id', $eligibleWorkOrderId)
                    ->where('item_id', $itemId)
                    ->where('status', ReservationStatus::Reserved->value)
                    ->where('quantity', '>', 0)
                    ->whereHas('location', fn ($query) => $query->where('is_active', true)
                        ->whereHas('zone', fn ($zone) => $zone
                            ->whereNotIn('zone_type', [WarehouseZoneType::Quarantine->value, WarehouseZoneType::Scrap->value])
                            ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('is_active', true))))
                    ->orderBy('id')
                    ->get();

                foreach ($matchingReservations as $reservation) {
                    $location = $reservation->location;
                    if (! isset($sourceByLocation[$location->id])) {
                        $sourceByLocation[$location->id] = [
                            'location_id' => $location->hash_id,
                            'label' => $location->full_code,
                            'available' => '0.000',
                            'reserved_for_work_order' => '0.000',
                            'is_blocked' => (bool) $location->is_blocked,
                        ];
                    }
                    $sourceByLocation[$location->id]['reserved_for_work_order'] = bcadd(
                        $sourceByLocation[$location->id]['reserved_for_work_order'],
                        (string) $reservation->quantity,
                        3,
                    );
                    $reservations[] = [
                        'id' => $reservation->hash_id,
                        'location_id' => $location->hash_id,
                        'quantity' => (string) $reservation->quantity,
                        'reserved_at' => $reservation->reserved_at?->toIso8601String(),
                    ];
                }
            }

            $sources = array_values($sourceByLocation);
            usort($sources, static fn (array $a, array $b) => strcmp($a['label'], $b['label']));
        }

        return [
            'work_orders' => $workOrders,
            'sources' => $sources,
            'reservations' => $reservations,
        ];
    }

    public function show(MaterialIssueSlip $slip): MaterialIssueSlip
    {
        return $slip->load([
            'workOrder:id,wo_number',
            'items.item:id,code,name,unit_of_measure',
            'items.location.zone.warehouse',
            'items.stockMovement',
            'issuer:id,name,role_id', 'creator:id,name,role_id',
        ]);
    }

    /**
     * @param array{work_order_id?:int|null, issued_date:string, items:array<int,array>, reference_text?:string|null, remarks?:string|null, idempotency_key?:string|null} $data
     * Each item: { item_id, location_id, quantity_issued, material_reservation_id?, remarks? }
     */
    public function create(array $data, User $by): MaterialIssueSlip
    {
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        unset($data['idempotency_key']);
        if (strlen($idempotencyKey) > 128 || ($idempotencyKey !== '' && ! preg_match('/^[A-Za-z0-9._:-]+$/D', $idempotencyKey))) {
            throw new BusinessRuleException('Idempotency-Key must contain only letters, numbers, dot, underscore, colon, or hyphen and be at most 128 characters.');
        }
        $idempotencyKey = $idempotencyKey !== '' ? $idempotencyKey : null;
        $fingerprint = $idempotencyKey === null ? null : hash('sha256', json_encode([
            'operation' => 'material_issue.create',
            'actor_id' => $by->id,
            'payload' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            return DB::transaction(function () use ($data, $by, $idempotencyKey, $fingerprint) {
            if ($idempotencyKey !== null) {
                $existing = MaterialIssueSlip::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $this->replayIdempotentSlip($existing, (string) $fingerprint);
                }
            }

            if (! empty($data['work_order_id'])) {
                $workOrderId = HashIdFilter::decode($data['work_order_id'], WorkOrder::class) ?? (int) $data['work_order_id'];
                $workOrder = WorkOrder::query()->lockForUpdate()->find($workOrderId);
                if (! $workOrder || ! in_array($workOrder->status, [WorkOrderStatus::Confirmed, WorkOrderStatus::InProgress, WorkOrderStatus::Paused], true)) {
                    throw new BusinessRuleException('Materials can only be issued to confirmed, in-progress, or paused work orders.');
                }
                $data['work_order_id'] = $workOrder->id;
            }

            $slip = MaterialIssueSlip::create([
                'slip_number'   => $this->sequences->generate('material_issue'),
                'work_order_id' => $data['work_order_id'] ?? null,
                'issued_date'   => $data['issued_date'],
                'issued_by'     => $by->id,
                'created_by'    => $by->id,
                'status'        => MaterialIssueStatus::Issued,
                'total_value'   => '0.00',
                'reference_text'=> $data['reference_text'] ?? null,
                'remarks'       => $data['remarks'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'idempotency_fingerprint' => $fingerprint,
            ]);

            $totalValue = '0';
            foreach ($data['items'] as $row) {
                $itemId  = HashIdFilter::decode($row['item_id'], Item::class) ?? (int) $row['item_id'];
                $locId   = HashIdFilter::decode($row['location_id'], WarehouseLocation::class) ?? (int) $row['location_id'];
                $qty     = (string) $row['quantity_issued'];

                // OGAMI-004 — multi-UOM issuing. If the caller supplies an
                // `issued_uom_code` different from the item base uom, convert
                // the issued quantity to BASE before it touches stock — keeping
                // the base-uom storage invariant. Identity when null/equal.
                if (! empty($row['issued_uom_code'])) {
                    $item = Item::query()->findOrFail($itemId);
                    $qty  = $item->convertToBase($qty, (string) $row['issued_uom_code']);
                }

                // REC-08 — nonconforming stock held under MRB physically sits in
                // a Quarantine (or Scrap) zone location. Never issuable.
                $loc = WarehouseLocation::query()->with('zone')->find($locId);
                $zoneType = $loc?->zone?->zone_type;
                $zoneValue = $zoneType instanceof \App\Modules\Inventory\Enums\WarehouseZoneType
                    ? $zoneType->value
                    : (string) $zoneType;
                if (in_array($zoneValue, [
                    \App\Modules\Inventory\Enums\WarehouseZoneType::Quarantine->value,
                    \App\Modules\Inventory\Enums\WarehouseZoneType::Scrap->value,
                ], true)) {
                    throw new BusinessRuleException("Cannot issue stock from a {$zoneValue} location (item held under MRB).");
                }

                $level = StockLevel::query()
                    ->where('item_id', $itemId)->where('location_id', $locId)
                    ->lockForUpdate()->first();
                if (! $level) {
                    throw new BusinessRuleException("No stock at item={$itemId} location={$locId}.");
                }

                // IN-01 — validate the linked reservation and release it
                // BEFORE the stock movement: move() gates on
                // available = quantity − reserved_quantity, so reserved stock
                // is only issuable after its reservation is released (same
                // ordering as WorkOrderService::issueReservedMaterials()).
                $reservation = null;
                $reservationId = $row['material_reservation_id'] ?? null;
                if ($reservationId) {
                    $actualId = HashIdFilter::decode($reservationId, MaterialReservation::class) ?? (int) $reservationId;
                    $reservation = MaterialReservation::query()->lockForUpdate()->find($actualId);
                    if (! $reservation) {
                        throw new BusinessRuleException("Reservation {$reservationId} does not exist.");
                    }
                    if ($reservation->status !== ReservationStatus::Reserved) {
                        throw new BusinessRuleException(
                            "Reservation {$reservationId} is {$reservation->status->value}, not reserved."
                        );
                    }
                    if (
                        (int) $reservation->item_id !== $itemId
                        || (int) $reservation->location_id !== $locId
                        || (int) $reservation->work_order_id !== (int) ($data['work_order_id'] ?? 0)
                    ) {
                        throw new BusinessRuleException(
                            "Reservation {$reservationId} does not belong to this slip line (item/location/work-order mismatch)."
                        );
                    }
                    if (bccomp($qty, (string) $reservation->quantity, 3) > 0) {
                        throw new BusinessRuleException(
                            "Cannot issue {$qty} against reservation {$reservationId} of quantity {$reservation->quantity}."
                        );
                    }

                    $this->movements->release($itemId, $locId, $qty);
                }

                $mvmt = $this->movements->move(new StockMovementInput(
                    type: StockMovementType::MaterialIssue,
                    itemId: $itemId,
                    fromLocationId: $locId,
                    toLocationId: null,
                    quantity: $qty,
                    unitCost: null,
                    referenceType: 'material_issue_slip',
                    referenceId: $slip->id,
                    remarks: "MIS {$slip->slip_number}",
                    createdBy: $by->id,
                    lotNumber: isset($row['lot_number']) ? (string) $row['lot_number'] : null,
                ));

                $unitCost = (string) $mvmt->unit_cost;
                $lineTotal = (string) $mvmt->total_cost;

                if ($reservation) {
                    if (bccomp($qty, (string) $reservation->quantity, 3) === 0) {
                        $reservation->update(['status' => ReservationStatus::Issued, 'released_at' => now()]);
                    } else {
                        // Partial issue: only the issued quantity was released
                        // above, so decrementing the row keeps the sum of
                        // Reserved reservations equal to reserved_quantity and
                        // the remainder stays bookable instead of leaking.
                        $reservation->update(['quantity' => bcsub((string) $reservation->quantity, $qty, 3)]);
                    }
                }

                MaterialIssueSlipItem::create([
                    'material_issue_slip_id'  => $slip->id,
                    'item_id'                 => $itemId,
                    'location_id'             => $locId,
                    'stock_movement_id'       => $mvmt->id,
                    'quantity_issued'         => $qty,
                    'unit_cost'               => $unitCost,
                    'total_cost'              => bcadd($lineTotal, '0', 2),
                    'issued_uom_code'         => $row['issued_uom_code'] ?? null,
                    // Persist the ledger-resolved FEFO lot as well as explicit
                    // operator selection; the stock movement is authoritative.
                    'lot_number'              => $mvmt->lot_number,
                    'material_reservation_id' => $row['material_reservation_id'] ?? null,
                    'remarks'                 => $row['remarks'] ?? null,
                ]);
                $totalValue = bcadd($totalValue, $lineTotal, 4);
            }

            $slip->total_value = bcadd($totalValue, '0', 2);
            $slip->save();

            if ($slip->work_order_id !== null) {
                $workOrder = WorkOrder::query()->findOrFail($slip->work_order_id);
                $this->materialUsage->refreshLotReferences($workOrder);
            }

            return $this->show($slip);
            });
        } catch (QueryException $e) {
            if ($idempotencyKey === null
                || $e->getCode() !== '23505'
                || ! str_contains($e->getMessage(), 'material_issue_slips_idempotency_unique')) {
                throw $e;
            }

            $existing = MaterialIssueSlip::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            return $this->replayIdempotentSlip($existing, (string) $fingerprint);
        }
    }

    private function replayIdempotentSlip(MaterialIssueSlip $existing, string $fingerprint): MaterialIssueSlip
    {
        if (! hash_equals((string) $existing->idempotency_fingerprint, $fingerprint)) {
            throw new BusinessRuleException('The idempotency key was already used for a different material-issue payload.');
        }

        return $this->show($existing);
    }

    /**
     * Cancel a slip. F-18: previously dead code — create() landed slips in
     * Issued, so the Draft-only guard meant no slip could ever be cancelled
     * and issued stock was irreversible. Now:
     *  - Draft slips: release any linked reservations, then mark cancelled.
     *  - Issued slips: return only each line's remaining quantity against its
     *    original MaterialIssue movement, then mark cancelled. Partial returns
     *    already posted remain linked and are not repeated.
     */
    public function cancel(MaterialIssueSlip $slip, ?User $by = null): void
    {
        // Read the immutable parent key without taking a lock so all mutations
        // that can race start/output follow the same WO → slip → lines → stock
        // order. The transaction revalidates the relationship after locking.
        $workOrderId = MaterialIssueSlip::query()->whereKey($slip->getKey())->value('work_order_id');

        DB::transaction(function () use ($slip, $by, $workOrderId) {
            $workOrder = $workOrderId !== null
                ? WorkOrder::query()->lockForUpdate()->find($workOrderId)
                : null;

            $lockedSlip = MaterialIssueSlip::query()
                ->lockForUpdate()
                ->findOrFail($slip->getKey());
            if ((int) ($lockedSlip->work_order_id ?? 0) !== (int) ($workOrderId ?? 0)) {
                throw new BusinessRuleException('The work-order link changed while cancelling the material issue slip. Reload and retry.');
            }
            if ($lockedSlip->status === MaterialIssueStatus::Cancelled) {
                throw new BusinessRuleException('Slip is already cancelled.');
            }

            if ($lockedSlip->status === MaterialIssueStatus::Issued
                && $workOrder !== null
                && $this->materialUsage->hasRecordedProduction($workOrder)) {
                throw new BusinessRuleException(
                    'This material issue cannot be cancelled after the work order has recorded output; the material may already have been consumed.'
                );
            }

            $items = MaterialIssueSlipItem::query()
                ->where('material_issue_slip_id', $lockedSlip->id)
                ->lockForUpdate()
                ->get();

            if ($lockedSlip->status === MaterialIssueStatus::Issued) {
                foreach ($items as $item) {
                    if ($item->stock_movement_id === null) {
                        throw new BusinessRuleException(
                            "MIS {$lockedSlip->slip_number} line {$item->id} has no uniquely linked source movement. Ask Warehouse to reconcile the issue history before cancelling."
                        );
                    }
                    $source = \App\Modules\Inventory\Models\StockMovement::query()->findOrFail($item->stock_movement_id);
                    $summary = $this->materialReturns->options($source);
                    $remaining = bcsub((string) $item->quantity_issued, (string) ($summary['returned_quantity'] ?? '0.000'), 3);
                    if (bccomp($remaining, '0', 3) <= 0) {
                        continue;
                    }
                    if (($summary['message'] ?? null) !== null && ($summary['eligible'] ?? false) !== true) {
                        throw new BusinessRuleException((string) $summary['message']);
                    }
                    $this->materialReturns->returnUnused($source, [
                        'quantity_returned' => $remaining,
                        'expected_returned_quantity' => (string) ($summary['returned_quantity'] ?? '0.000'),
                        'reason' => "Cancel unused material issue slip {$lockedSlip->slip_number}",
                        'idempotency_key' => "mis-cancel-{$lockedSlip->id}-{$item->id}",
                    ], $by);
                }
            } else {
                foreach ($items as $item) {
                    if ($item->material_reservation_id) {
                        $res = MaterialReservation::query()
                            ->lockForUpdate()
                            ->find($item->material_reservation_id);
                        if ($res && $res->status === ReservationStatus::Reserved) {
                            $res->update(['status' => ReservationStatus::Released, 'released_at' => now()]);
                            $this->movements->release(
                                $item->item_id,
                                $item->location_id,
                                (string) $item->quantity_issued,
                            );
                        }
                    }
                }
            }

            $lockedSlip->update(['status' => MaterialIssueStatus::Cancelled]);

            if ($workOrder !== null) {
                $this->materialUsage->refreshLotReferences($workOrder);
            }
        });

        // Keep callers that passed a previously-loaded model in sync with the
        // row serialized immediately after cancellation (notably the API
        // controller).
        $slip->refresh();
    }
}
