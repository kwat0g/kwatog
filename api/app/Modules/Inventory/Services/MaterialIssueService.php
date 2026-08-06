<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MaterialIssueService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly StockMovementService $movements,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = MaterialIssueSlip::query()
            ->with(['issuer:id,name,role_id', 'creator:id,name,role_id']);
        if (! empty($filters['status'])) $q->where('status', $filters['status']);
        if (! empty($filters['from'])) $q->whereDate('issued_date', '>=', $filters['from']);
        if (! empty($filters['to']))   $q->whereDate('issued_date', '<=', $filters['to']);
        if (! empty($filters['search'])) {
            $q->where('slip_number', 'ilike', '%'.$filters['search'].'%');
        }
        return $q->orderByDesc('issued_date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(MaterialIssueSlip $slip): MaterialIssueSlip
    {
        return $slip->load([
            'items.item:id,code,name,unit_of_measure',
            'items.location.zone.warehouse',
            'issuer:id,name,role_id', 'creator:id,name,role_id',
        ]);
    }

    /**
     * @param array{work_order_id?:int|null, issued_date:string, items:array<int,array>, reference_text?:string|null, remarks?:string|null} $data
     * Each item: { item_id, location_id, quantity_issued, material_reservation_id?, remarks? }
     */
    public function create(array $data, User $by): MaterialIssueSlip
    {
        return DB::transaction(function () use ($data, $by) {
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
                ));

                $unitCost = (string) $mvmt->unit_cost;
                $lineTotal = bcmul($qty, $unitCost, 4);

                // OGAMI-012 — optional lot stamp so an issued lot is traceable
                // back to the GRN lot it came from. Null-safe; existing callers
                // omit `lot_number` and the movement stays unstamped.
                $this->movements->stampLot(
                    $mvmt,
                    isset($row['lot_number']) ? (string) $row['lot_number'] : null,
                );

                $reservationId = $row['material_reservation_id'] ?? null;
                if ($reservationId) {
                    $actualId = HashIdFilter::decode($reservationId, MaterialReservation::class) ?? (int) $reservationId;
                    $res = MaterialReservation::query()->lockForUpdate()->find($actualId);
                    if ($res) {
                        $res->update(['status' => ReservationStatus::Issued, 'released_at' => now()]);
                        $this->movements->release($itemId, $locId, $qty);
                    }
                }

                MaterialIssueSlipItem::create([
                    'material_issue_slip_id'  => $slip->id,
                    'item_id'                 => $itemId,
                    'location_id'             => $locId,
                    'quantity_issued'         => $qty,
                    'unit_cost'               => $unitCost,
                    'total_cost'              => bcadd($lineTotal, '0', 2),
                    'material_reservation_id' => $row['material_reservation_id'] ?? null,
                    'remarks'                 => $row['remarks'] ?? null,
                ]);
                $totalValue = bcadd($totalValue, $lineTotal, 4);
            }

            $slip->total_value = bcadd($totalValue, '0', 2);
            $slip->save();

            return $this->show($slip);
        });
    }

    /**
     * Cancel a slip. F-18: previously dead code — create() landed slips in
     * Issued, so the Draft-only guard meant no slip could ever be cancelled
     * and issued stock was irreversible. Now:
     *  - Draft slips: release any linked reservations, then mark cancelled.
     *  - Issued slips: reverse every issued line back into inventory via an
     *    AdjustmentIn movement at the original unit cost (GL posts through
     *    MovementGlPostingService), then mark cancelled.
     */
    public function cancel(MaterialIssueSlip $slip, ?User $by = null): void
    {
        if ($slip->status === MaterialIssueStatus::Cancelled) {
            throw new BusinessRuleException('Slip is already cancelled.');
        }

        DB::transaction(function () use ($slip, $by) {
            if ($slip->status === MaterialIssueStatus::Issued) {
                foreach ($slip->items as $item) {
                    $this->movements->move(new StockMovementInput(
                        type: StockMovementType::AdjustmentIn,
                        itemId: $item->item_id,
                        toLocationId: $item->location_id,
                        quantity: (string) $item->quantity_issued,
                        unitCost: (string) $item->unit_cost,
                        referenceType: 'material_issue_slip',
                        referenceId: $slip->id,
                        remarks: "MIS {$slip->slip_number} cancel reversal",
                        createdBy: $by?->id,
                    ));
                }
            } else {
                foreach ($slip->items as $item) {
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

            $slip->update(['status' => MaterialIssueStatus::Cancelled]);
        });
    }
}
