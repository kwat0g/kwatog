<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\ShipmentLot;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ADV3 — Shipment Lot service.
 *
 * Conventions (per CLAUDE.md):
 *  - DB::transaction wraps every mutating op.
 *  - DocumentSequenceService generates LOT-YYYYMM-NNNN.
 *  - Inputs are hash_ids; resolved to integer ids inside the txn.
 */
class ShipmentLotService
{
    public function __construct(private readonly DocumentSequenceService $sequences) {}

    /**
     * @param  array{work_order_ids: array<int, string>, quantity?: int, lot_date?: string}  $data
     */
    public function createForDelivery(Delivery $delivery, array $data, User $by): ShipmentLot
    {
        if (empty($data['work_order_ids'])) {
            throw new RuntimeException('At least one work-order batch is required to create a shipment lot.');
        }

        return DB::transaction(function () use ($delivery, $data, $by) {
            // Resolve WO hash_ids to ints + verify each WO has a batch_number.
            $workOrders = collect($data['work_order_ids'])
                ->map(fn (string $hashId) => WorkOrder::query()->findOrFail(
                    (new WorkOrder())->decodeHashId($hashId)
                ))
                ->values();

            $missing = $workOrders->filter(fn (WorkOrder $wo) => empty($wo->batch_number));
            if ($missing->isNotEmpty()) {
                throw new RuntimeException(
                    'All work orders must have a batch_number (i.e. be started) before being added to a shipment lot.'
                );
            }

            $quantity = isset($data['quantity'])
                ? (int) $data['quantity']
                : (int) $workOrders->sum(fn (WorkOrder $wo) => (int) $wo->quantity_good);

            // Resolve customer + product from delivery → sales order.
            $so = $delivery->sales_order_id
                ? SalesOrder::query()->find($delivery->sales_order_id)
                : null;

            $productId = $workOrders->pluck('product_id')->unique()->count() === 1
                ? (int) $workOrders->first()->product_id
                : null;

            $lot = ShipmentLot::create([
                'lot_number'      => $this->sequences->generate('shipment_lot'),
                'delivery_id'     => $delivery->id,
                'customer_id'     => $so?->customer_id,
                'product_id'      => $productId,
                'work_order_ids'  => $workOrders->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'quantity'        => $quantity,
                'lot_date'        => $data['lot_date'] ?? now()->toDateString(),
                'created_by'      => $by->id,
            ]);

            return $lot->fresh(['delivery', 'customer', 'product']);
        });
    }

    public function showForDelivery(Delivery $delivery): ?ShipmentLot
    {
        return ShipmentLot::query()
            ->where('delivery_id', $delivery->id)
            ->with(['delivery', 'customer', 'product'])
            ->latest('id')
            ->first();
    }

    public function show(ShipmentLot $lot): ShipmentLot
    {
        return $lot->loadMissing(['delivery', 'customer', 'product']);
    }
}
