<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GrnService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly StockMovementService $movements,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = GoodsReceiptNote::query()
            ->with(['vendor:id,name', 'purchaseOrder:id,po_number', 'receiver:id,name,role_id']);

        if (! empty($filters['status'])) $q->where('status', $filters['status']);
        if (! empty($filters['vendor_id'])) {
            $vid = HashIdFilter::decode($filters['vendor_id'], \App\Modules\Accounting\Models\Vendor::class);
            if ($vid) $q->where('vendor_id', $vid);
        }
        if (! empty($filters['purchase_order_id'])) {
            $pid = HashIdFilter::decode($filters['purchase_order_id'], PurchaseOrder::class);
            if ($pid) $q->where('purchase_order_id', $pid);
        }
        if (! empty($filters['from'])) $q->whereDate('received_date', '>=', $filters['from']);
        if (! empty($filters['to']))   $q->whereDate('received_date', '<=', $filters['to']);
        if (! empty($filters['search'])) {
            $q->where('grn_number', 'ilike', '%'.$filters['search'].'%');
        }

        return $q->orderByDesc('received_date')->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(GoodsReceiptNote $grn): GoodsReceiptNote
    {
        return $grn->load([
            'vendor', 'purchaseOrder',
            'items.item:id,code,name,unit_of_measure',
            'items.location.zone.warehouse',
            'items.purchaseOrderItem',
            'receiver:id,name,role_id', 'acceptor:id,name,role_id',
        ]);
    }

    /**
     * Create a GRN for a PO, in `pending_qc` status. Stock is NOT yet incremented.
     * Stock is increased only when accept() is called (Sprint 7 will gate this on QC).
     *
     * @param array<int, array{purchase_order_item_id:int|string, item_id:int|string, location_id:int|string, quantity_received:string, unit_cost?:string|null, remarks?:string|null}> $items
     */
    public function create(PurchaseOrder $po, array $items, array $meta, User $by): GoodsReceiptNote
    {
        if (! in_array($po->status, [
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::PartiallyReceived,
        ], true)) {
            throw new RuntimeException("PO {$po->po_number} is not open for receiving (status={$po->status->value}).");
        }

        return DB::transaction(function () use ($po, $items, $meta, $by) {
            $grn = GoodsReceiptNote::create([
                'grn_number'        => $this->sequences->generate('grn'),
                'purchase_order_id' => $po->id,
                'vendor_id'         => $po->vendor_id,
                'received_date'     => $meta['received_date'] ?? now()->toDateString(),
                'received_by'       => $by->id,
                'status'            => GrnStatus::PendingQc,
                'remarks'           => $meta['remarks'] ?? null,
            ]);

            foreach ($items as $row) {
                $poiId = HashIdFilter::decode($row['purchase_order_item_id'], PurchaseOrderItem::class)
                    ?? (is_int($row['purchase_order_item_id']) ? $row['purchase_order_item_id'] : null);
                $poi = PurchaseOrderItem::query()->whereKey($poiId)->lockForUpdate()->firstOrFail();
                if ($poi->purchase_order_id !== $po->id) {
                    throw new RuntimeException("PO line {$poi->id} does not belong to PO {$po->id}.");
                }
                $remaining = bcsub((string) $poi->quantity, (string) $poi->quantity_received, 3);
                if (bccomp((string) $row['quantity_received'], $remaining, 3) > 0) {
                    throw new RuntimeException(
                        "Cannot receive {$row['quantity_received']} for PO line {$poi->id}: only {$remaining} remaining."
                    );
                }

                $locationId = HashIdFilter::decode($row['location_id'], WarehouseLocation::class)
                    ?? (int) $row['location_id'];
                $itemId = HashIdFilter::decode($row['item_id'], \App\Modules\Inventory\Models\Item::class)
                    ?? (int) $row['item_id'];

                GrnItem::create([
                    'goods_receipt_note_id'  => $grn->id,
                    'purchase_order_item_id' => $poi->id,
                    'item_id'                => $itemId,
                    'location_id'            => $locationId,
                    'quantity_received'      => $row['quantity_received'],
                    'quantity_accepted'      => 0,
                    'unit_cost'              => $row['unit_cost'] ?? $poi->unit_price,
                    'remarks'                => $row['remarks'] ?? null,
                ]);

                // Update PO line running total of received quantity.
                $poi->quantity_received = bcadd((string) $poi->quantity_received, (string) $row['quantity_received'], 3);
                $poi->save();
            }

            $this->refreshPoStatus($po);

            return $this->show($grn->fresh());
        });
    }

    /** Accept the entire GRN — moves stock for every line at full quantity_received. */
    public function accept(GoodsReceiptNote $grn, User $by): GoodsReceiptNote
    {
        if ($grn->status !== GrnStatus::PendingQc) {
            throw new RuntimeException('Only pending_qc GRNs can be accepted.');
        }
        return DB::transaction(function () use ($grn, $by) {
            foreach ($grn->items as $row) {
                $row->quantity_accepted = $row->quantity_received;
                $row->save();
                $this->movements->move(new StockMovementInput(
                    type: StockMovementType::GrnReceipt,
                    itemId: $row->item_id,
                    fromLocationId: null,
                    toLocationId: $row->location_id,
                    quantity: (string) $row->quantity_received,
                    unitCost: (string) $row->unit_cost,
                    referenceType: 'goods_receipt_note',
                    referenceId: $grn->id,
                    remarks: "GRN {$grn->grn_number}",
                    createdBy: $by->id,
                ));
            }
            $grn->update([
                'status'      => GrnStatus::Accepted,
                'accepted_by' => $by->id,
                'accepted_at' => now(),
            ]);
            return $grn->fresh();
        });
    }

    /** Partially accept — caller supplies quantity_accepted per grn_item id. */
    public function partialAccept(GoodsReceiptNote $grn, array $itemAcceptedMap, User $by): GoodsReceiptNote
    {
        if ($grn->status !== GrnStatus::PendingQc) {
            throw new RuntimeException('Only pending_qc GRNs can be partially accepted.');
        }
        return DB::transaction(function () use ($grn, $itemAcceptedMap, $by) {
            $allFull = true;
            foreach ($grn->items as $row) {
                $accepted = (string) ($itemAcceptedMap[$row->id] ?? '0');
                if (bccomp($accepted, (string) $row->quantity_received, 3) > 0) {
                    throw new RuntimeException("Accepted quantity exceeds received for line {$row->id}.");
                }
                if (bccomp($accepted, (string) $row->quantity_received, 3) < 0) {
                    $allFull = false;
                }
                $row->quantity_accepted = $accepted;
                $row->save();
                if (bccomp($accepted, '0', 3) > 0) {
                    $this->movements->move(new StockMovementInput(
                        type: StockMovementType::GrnReceipt,
                        itemId: $row->item_id,
                        fromLocationId: null,
                        toLocationId: $row->location_id,
                        quantity: $accepted,
                        unitCost: (string) $row->unit_cost,
                        referenceType: 'goods_receipt_note',
                        referenceId: $grn->id,
                        remarks: "GRN {$grn->grn_number} (partial)",
                        createdBy: $by->id,
                    ));
                }
            }
            $grn->update([
                'status'      => $allFull ? GrnStatus::Accepted : GrnStatus::PartialAccepted,
                'accepted_by' => $by->id,
                'accepted_at' => now(),
            ]);
            return $grn->fresh();
        });
    }

    public function reject(GoodsReceiptNote $grn, string $reason, User $by): GoodsReceiptNote
    {
        if ($grn->status !== GrnStatus::PendingQc) {
            throw new RuntimeException('Only pending_qc GRNs can be rejected.');
        }
        return DB::transaction(function () use ($grn, $reason, $by) {
            $grn->update([
                'status'          => GrnStatus::Rejected,
                'rejected_reason' => $reason,
                'accepted_by'     => $by->id,
                'accepted_at'     => now(),
            ]);
            return $grn->fresh();
        });
    }

    private function refreshPoStatus(PurchaseOrder $po): void
    {
        $po->load('items');
        $allReceived = $po->items->every(
            fn ($l) => bccomp((string) $l->quantity_received, (string) $l->quantity, 3) >= 0
        );
        $anyReceived = $po->items->contains(
            fn ($l) => bccomp((string) $l->quantity_received, '0', 3) > 0
        );
        if ($allReceived) {
            $po->status = PurchaseOrderStatus::Received;
        } elseif ($anyReceived) {
            $po->status = PurchaseOrderStatus::PartiallyReceived;
        }
        $po->save();
    }
}
