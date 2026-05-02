<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
                'slip_number'   => $this->sequences->generate('grn'), // reuse GRN seq pad style; we add MIS prefix below
                'work_order_id' => $data['work_order_id'] ?? null,
                'issued_date'   => $data['issued_date'],
                'issued_by'     => $by->id,
                'created_by'    => $by->id,
                'status'        => MaterialIssueStatus::Issued,
                'total_value'   => '0.00',
                'reference_text'=> $data['reference_text'] ?? null,
                'remarks'       => $data['remarks'] ?? null,
            ]);
            // Replace the GRN-style number with MIS prefix.
            $slip->slip_number = 'MIS-'.now()->format('Ym').'-'.str_pad((string) $slip->id, 4, '0', STR_PAD_LEFT);
            $slip->save();

            $totalValue = '0';
            foreach ($data['items'] as $row) {
                $itemId  = HashIdFilter::decode($row['item_id'], Item::class) ?? (int) $row['item_id'];
                $locId   = HashIdFilter::decode($row['location_id'], WarehouseLocation::class) ?? (int) $row['location_id'];
                $qty     = (string) $row['quantity_issued'];

                $level = StockLevel::query()
                    ->where('item_id', $itemId)->where('location_id', $locId)
                    ->lockForUpdate()->first();
                if (! $level) {
                    throw new RuntimeException("No stock at item={$itemId} location={$locId}.");
                }
                $unitCost = (string) $level->weighted_avg_cost;
                $lineTotal = bcmul($qty, $unitCost, 4);

                $mvmt = $this->movements->move(new StockMovementInput(
                    type: StockMovementType::MaterialIssue,
                    itemId: $itemId,
                    fromLocationId: $locId,
                    toLocationId: null,
                    quantity: $qty,
                    unitCost: $unitCost,
                    referenceType: 'material_issue_slip',
                    referenceId: $slip->id,
                    remarks: "MIS {$slip->slip_number}",
                    createdBy: $by->id,
                ));

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

    public function cancel(MaterialIssueSlip $slip): void
    {
        if ($slip->status !== MaterialIssueStatus::Draft) {
            throw new RuntimeException('Only draft slips can be cancelled.');
        }
        $slip->update(['status' => MaterialIssueStatus::Cancelled]);
    }
}
