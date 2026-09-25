<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\ReturnManagement\Models\ReturnCaseLine;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Resolves reportable documents and captures quantities in their inventory unit. */
class ReturnCaseSourceService
{
    public function sources(?int $customerId, string $type, string $search = ''): array
    {
        if ($customerId !== null || $type === 'customer') {
            return Delivery::query()->with('salesOrder.customer')
                ->whereIn('status', ['delivered', 'confirmed'])
                ->when($customerId !== null, fn ($q) => $q->whereHas('salesOrder', fn ($s) => $s->where('customer_id', $customerId)))
                ->when($search !== '', fn ($q) => $q->where('delivery_number', 'ilike', '%'.$search.'%'))
                ->latest('id')->limit(100)->get()->map(fn ($d) => [
                    'id' => $d->hash_id, 'kind' => 'delivery', 'label' => $d->delivery_number,
                    'party_name' => $d->salesOrder?->customer?->name,
                ])->all();
        }

        $receipts = GoodsReceiptNote::query()->with('vendor')->where('status', '!=', 'draft')
            ->when($search !== '', fn ($q) => $q->where('grn_number', 'ilike', '%'.$search.'%'))
            ->latest('id')->limit(50)->get()->map(fn ($g) => [
                'id' => $g->hash_id, 'kind' => 'grn', 'label' => $g->grn_number, 'party_name' => $g->vendor?->name,
            ]);
        $orders = PurchaseOrder::query()->with('vendor')->whereIn('status', ['approved', 'sent', 'acknowledged', 'partially_received'])
            ->when($search !== '', fn ($q) => $q->where('po_number', 'ilike', '%'.$search.'%'))
            ->latest('id')->limit(50)->get()->map(fn ($p) => [
                'id' => $p->hash_id, 'kind' => 'purchase_order', 'label' => $p->po_number, 'party_name' => $p->vendor?->name,
            ]);

        return $receipts->concat($orders)->values()->all();
    }

    public function resolve(string $kind, string $hash, ?int $customerId = null): Model
    {
        $class = match ($kind) {
            'delivery' => Delivery::class,
            'grn' => GoodsReceiptNote::class,
            'purchase_order' => PurchaseOrder::class,
            default => throw ValidationException::withMessages(['source_kind' => 'Choose a delivery, receipt, or purchase order.']),
        };
        abort_if($customerId !== null && $kind !== 'delivery', 403);
        $id = HashIdFilter::decode($hash, $class);
        abort_unless($id, 404);
        $query = $class::query();
        if (DB::transactionLevel() > 0) {
            if ($kind === 'delivery') {
                $orderId = Delivery::query()->whereKey($id)->value('sales_order_id');
                if ($orderId) {
                    \App\Modules\CRM\Models\SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
                }
            } elseif ($kind === 'grn') {
                $orderId = GoodsReceiptNote::query()->whereKey($id)->value('purchase_order_id');
                if ($orderId) {
                    PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
                }
            }
            $query->lockForUpdate();
        }
        $source = $query->findOrFail($id);
        if ($source instanceof Delivery) {
            $source->load('salesOrder.customer', 'items.salesOrderItem.product');
            abort_if($customerId !== null && (int) $source->salesOrder?->customer_id !== $customerId, 403);
            if (! in_array($source->status->value, ['delivered', 'confirmed'], true)) {
                throw new BusinessRuleException('Report a problem after this delivery has been recorded as delivered.');
            }
        } elseif ($source instanceof GoodsReceiptNote) {
            if (DB::transactionLevel() > 0) {
                PurchaseOrder::query()->lockForUpdate()->findOrFail($source->purchase_order_id);
            }
            $source->load('vendor', 'purchaseOrder', 'items.item', 'items.purchaseOrderItem');
            if ($source->status->value === 'draft') {
                throw new BusinessRuleException('Record actual receipt first, or report missing goods against the purchase order.');
            }
        } else {
            $source->load('vendor', 'items.item');
            if (! in_array($source->status->value, ['approved', 'sent', 'acknowledged', 'partially_received', 'received', 'closed'], true)) {
                throw new BusinessRuleException('This purchase order is not eligible for a receiving report.');
            }
        }

        return $source;
    }

    public function options(Model $source): array
    {
        $customer = $source instanceof Delivery;
        if ($customer) $source->loadMissing('items.stockMovements');
        $kind = $customer ? 'delivery' : ($source instanceof GoodsReceiptNote ? 'grn' : 'purchase_order');
        $party = $customer ? $source->salesOrder?->customer : $source->vendor;
        $lines = $source->items->map(function ($line) use ($customer, $kind) {
            if ($customer) {
                $product = $line->salesOrderItem?->product;
                $item = $product ? Item::query()->where('code', $product->part_number)->where('item_type', 'finished_good')->first() : null;
                // Once an attempt outcome is reconciled, only quantity the
                // customer actually accepted can be claimed through ordinary
                // customer problem intake. Truck-return and unaccounted goods
                // have their own auditable warehouse/loss path.
                $expected = (string) ($line->customer_received_quantity ?? $line->quantity);
                $received = $expected;
                $unit = $item?->unit_of_measure ?: 'pcs';
                $code = $product?->part_number;
                $name = $product?->name;
                $maximum = $expected;
            } else {
                $item = $line->item;
                $poLine = $kind === 'grn' ? $line->purchaseOrderItem : $line;
                $maximum = $item ? $item->convertToBase((string) $poLine->quantity, $poLine->unit) : (string) $poLine->quantity;
                $received = $kind === 'grn' ? (string) $line->quantity_received : '0.000';
                $outstanding = $this->missingLimit($poLine);
                $maximum = $kind === 'grn' ? bcadd($received, $outstanding, 3) : $outstanding;
                $expected = $kind === 'grn' ? $received : $outstanding;
                $unit = $item?->unit_of_measure ?: $poLine->unit;
                $code = $item?->code;
                $name = $item?->name ?: $poLine->description;
            }

            return [
                'id' => $line->hash_id, 'part_number' => $code, 'description' => $name,
                'unit' => $unit, 'expected_quantity' => bcadd($expected, '0', 3),
                'received_quantity' => bcadd($received, '0', 3), 'maximum_quantity' => bcadd($maximum, '0', 3),
                'lot_number' => $kind === 'grn' ? $line->material_lot_number : ($customer ? $this->dispatchedLot($line) : null),
                'can_return' => $item !== null,
            ];
        })->values()->all();

        return [
            'source' => ['kind' => $kind, 'id' => $source->hash_id, 'label' => $source->delivery_number ?? $source->grn_number ?? $source->po_number],
            'type' => $customer ? 'customer' : 'supplier',
            'party' => $party ? ['id' => $party->hash_id, 'name' => $party->name] : null,
            'lines' => $lines,
        ];
    }

    /** Preserve actual dispatched provenance; never guess for legacy/mixed lots. */
    private function dispatchedLot(\App\Modules\SupplyChain\Models\DeliveryItem $line): ?string
    {
        $movements = $line->stockMovements;
        if ($movements->isEmpty() || $movements->contains(fn ($movement) => trim((string) $movement->lot_number) === '')) return null;
        $lots = $movements->pluck('lot_number')->unique();
        $quantity = '0.000';
        foreach ($movements as $movement) $quantity = bcadd($quantity, (string) $movement->quantity, 3);
        return $lots->count() === 1 && bccomp($quantity, (string) $line->quantity, 3) === 0 ? $lots->first() : null;
    }

    private function missingLimit(PurchaseOrderItem $line): string
    {
        $ordered = $line->item ? $line->item->convertToBase((string) $line->quantity, $line->unit) : (string) $line->quantity;
        // QC rejection can reopen the PO balance. Those units arrived and are
        // defective; they must not also become a claim for never-arrived goods.
        $arrived = (string) GrnItem::query()->where('purchase_order_item_id', $line->id)
            ->whereHas('grn', fn ($q) => $q->where('status', '!=', 'draft'))->sum('quantity_received');
        $remaining = bcsub($ordered, $arrived, 3);
        return bccomp($remaining, '0', 3) > 0 ? $remaining : '0.000';
    }

    public function prepareLines(Model $source, array $rows): array
    {
        $options = collect($this->options($source)['lines'])->keyBy('id');
        $seen = [];
        $result = [];
        foreach ($rows as $index => $row) {
            $hash = $row['source_line_id'];
            $option = $options->get($hash);
            if (! $option || isset($seen[$hash])) {
                throw ValidationException::withMessages(["lines.$index.source_line_id" => 'Choose each affected source line only once.']);
            }
            $seen[$hash] = true;
            $line = $source->items->first(fn ($line) => $line->hash_id === $hash);
            $expected = $source instanceof Delivery ? $option['expected_quantity'] : (string) ($row['expected_quantity'] ?? $option['expected_quantity']);
            $received = (string) $row['received_quantity'];
            $defective = (string) ($row['defective_quantity'] ?? '0');
            if (bccomp($expected, '0', 3) <= 0 || bccomp($expected, $option['maximum_quantity'], 3) > 0) {
                throw ValidationException::withMessages(["lines.$index.expected_quantity" => 'Expected quantity must be positive and within the source order quantity.']);
            }
            if (bccomp($received, '0', 3) < 0 || bccomp($defective, '0', 3) < 0 || bccomp($received, $expected, 3) > 0 || bccomp($defective, $received, 3) > 0) {
                throw ValidationException::withMessages(["lines.$index.received_quantity" => 'Received cannot exceed expected; damaged quantity must be part of what arrived.']);
            }
            if ($source instanceof GoodsReceiptNote && bccomp($received, $option['received_quantity'], 3) !== 0) {
                throw ValidationException::withMessages(["lines.$index.received_quantity" => 'Actual quantity must match this receipt. Correct receiving through the warehouse workflow first.']);
            }
            if ($source instanceof PurchaseOrder && bccomp($received, '0', 3) > 0) {
                throw ValidationException::withMessages(["lines.$index.received_quantity" => 'Choose the goods receipt for items that arrived. Use the PO for a wholly missing shipment.']);
            }
            $missing = bcsub($expected, $received, 3);
            $affected = bcadd($missing, $defective, 3);
            if (bccomp($affected, '0', 3) <= 0) {
                continue;
            }
            if ($source instanceof Delivery) {
                $remaining = app(ReturnRequestService::class)->availableForCase('delivery_item', (int) $line->id);
                if (bccomp($affected, $remaining, 3) > 0) {
                    throw ValidationException::withMessages(["lines.$index.received_quantity" => 'Existing reports or returns already cover this quantity. Open the existing case to provide an update.']);
                }
            } else {
                $poLine = $source instanceof GoodsReceiptNote ? $line->purchaseOrderItem : $line;
                $missingReserved = app(ReturnCaseQuantityService::class)->outstandingShortage((int) $poLine->id);
                if (bccomp(bcadd($missingReserved, $missing, 3), $this->missingLimit($poLine), 3) > 0) {
                    throw ValidationException::withMessages(["lines.$index.expected_quantity" => 'The missing quantity exceeds the unreceived PO balance after existing reports. Update the existing case instead.']);
                }
                if ($source instanceof GoodsReceiptNote) {
                    $defectClaimed = (string) ReturnCaseLine::query()->where('source_grn_item_id', $line->id)
                        ->whereHas('returnCase', fn ($q) => $q->where('status', '!=', 'withdrawn'))
                        ->selectRaw('COALESCE(SUM(COALESCE(verified_defective_quantity, defective_quantity)), 0) AS total')->value('total');
                    if (bccomp(bcadd($defectClaimed, $defective, 3), $received, 3) > 0) {
                        throw ValidationException::withMessages(["lines.$index.defective_quantity" => 'Existing reports already cover these received goods.']);
                    }
                    if (bccomp($defective, '0', 3) > 0 && bccomp((string) $line->quantity_accepted, $received, 3) === 0
                        && bccomp($defective, app(ReturnRequestService::class)->availableForCase('grn_item', (int) $line->id), 3) > 0) {
                        throw ValidationException::withMessages(["lines.$index.defective_quantity" => 'These accepted goods are already covered by a return or case. Continue that record instead.']);
                    }
                }
            }
            $customer = $source instanceof Delivery;
            $poLine = $customer ? null : ($source instanceof GoodsReceiptNote ? $line->purchaseOrderItem : $line);
            $product = $customer ? $line->salesOrderItem?->product : null;
            $item = $customer && $product ? Item::query()->where('code', $product->part_number)->where('item_type', 'finished_good')->first() : ($customer ? null : $line->item);
            $price = $customer ? (string) $line->unit_price : ($source instanceof GoodsReceiptNote ? (string) $line->unit_cost : ($item ? bcdiv((string) $poLine->unit_price, $item->convertToBase('1', $poLine->unit), 4) : (string) $poLine->unit_price));
            $result[] = [
                'source_delivery_item_id' => $customer ? $line->id : null,
                'source_po_item_id' => $poLine?->id,
                'source_grn_item_id' => $source instanceof GoodsReceiptNote ? $line->id : null,
                'product_id' => $product?->id, 'item_id' => $item?->id,
                'description' => $option['description'] ?: 'Reported item', 'unit' => $option['unit'],
                'expected_quantity' => $expected, 'received_quantity' => $received,
                'missing_quantity' => $missing, 'defective_quantity' => $defective,
                'lot_number' => $option['lot_number'] ?: ($row['lot_number'] ?? null),
                'serial_number' => $row['serial_number'] ?? null, 'reason' => $row['reason'] ?? null,
                'source_unit_price' => $price,
            ];
        }
        if ($result === []) {
            throw ValidationException::withMessages(['lines' => 'Select at least one item with a missing or defective quantity.']);
        }

        return $result;
    }
}
