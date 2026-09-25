<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\SupplyChain\Enums\DeliveryCostingMode;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Enums\DeliveryStockReservationStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryStockReservation;
use App\Modules\SupplyChain\Models\DeliveryStockReservationBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable, lot-and-bin-specific outbound stock holds for delivery lines. */
class DeliveryStockReservationService
{
    public function __construct(
        private readonly DeliveryStockService $dispatchStock,
        private readonly \App\Modules\Inventory\Services\StockMovementService $movements,
        private readonly \App\Modules\Inventory\Services\StockLocationSummaryService $lots,
    ) {}

    /**
     * Reserve the inspected source lot before loading. Creation calls this
     * inside its SO transaction; Warehouse uses recovery=true for legacy rows.
     */
    public function reserveForDelivery(Delivery $delivery, User $by, string $requestKey, bool $recovery = false): DeliveryStockReservationBatch
    {
        $key = strtolower(trim($requestKey));
        if (! Str::isUuid($key)) {
            throw new BusinessRuleException('A valid UUID request key is required for delivery stock reservation.');
        }

        return DB::transaction(function () use ($delivery, $by, $key, $recovery): DeliveryStockReservationBatch {
            $salesOrderId = Delivery::query()->whereKey($delivery->id)->value('sales_order_id');
            if ($salesOrderId) {
                \App\Modules\CRM\Models\SalesOrder::query()->lockForUpdate()->find($salesOrderId);
            }
            $locked = Delivery::query()->lockForUpdate()->find($delivery->id);
            if (! $locked) {
                throw new BusinessRuleException('Delivery not found.');
            }
            if ((int) $locked->sales_order_id !== (int) $salesOrderId) {
                throw new BusinessRuleException('The delivery order changed. Reload before reserving stock.');
            }
            if (! in_array($locked->status, [DeliveryStatus::Scheduled, DeliveryStatus::Loading], true)) {
                throw new BusinessRuleException('Physical delivery stock may only be reserved before departure.');
            }
            if ($locked->items()->whereNotNull('stock_movement_id')->exists()) {
                throw new BusinessRuleException('This delivery has already issued stock and cannot create a new reservation.');
            }

            $locked->loadMissing(['items.salesOrderItem.product', 'items.inspection.workOrderOutput.workOrder', 'items.inspection.workOrderOutput.productionReceiptMovement']);
            if ($locked->items->isEmpty()) {
                throw new BusinessRuleException('A delivery needs at least one line before stock can be reserved.');
            }

            $fingerprint = $this->fingerprint($locked, $by, $recovery);
            $priorKey = DeliveryStockReservationBatch::query()->where('request_key', $key)->lockForUpdate()->first();
            if ($priorKey) {
                if ((int) $priorKey->delivery_id !== (int) $locked->id
                    || ! hash_equals((string) $priorKey->payload_fingerprint, $fingerprint)) {
                    throw new BusinessRuleException('This reservation request key was already used for a different delivery or payload.');
                }
                return $priorKey->fresh(['reservations']);
            }

            $existing = DeliveryStockReservationBatch::query()->where('delivery_id', $locked->id)->lockForUpdate()->first();
            if ($existing) {
                throw new BusinessRuleException('This delivery already has a physical stock reservation. Retry with its original request key or reload the delivery.');
            }
            if (! $recovery && $locked->cost_recognition_mode !== DeliveryCostingMode::Transit) {
                throw new BusinessRuleException('Only the delivery creation flow may create a new transit-costing reservation.');
            }

            $plan = $this->allocationPlan($locked);
            $batch = DeliveryStockReservationBatch::create([
                'delivery_id' => $locked->id,
                'request_key' => $key,
                'payload_fingerprint' => $fingerprint,
                'status' => DeliveryStockReservationStatus::Reserved,
                'reserved_by' => $by->id,
                'reserved_at' => now(),
            ]);

            foreach ($plan as $allocation) {
                $this->movements->reserve($allocation['item_id'], $allocation['location_id'], $allocation['quantity']);
                DeliveryStockReservation::create([
                    'reservation_batch_id' => $batch->id,
                    'delivery_id' => $locked->id,
                    'delivery_item_id' => $allocation['delivery_item_id'],
                    'item_id' => $allocation['item_id'],
                    'location_id' => $allocation['location_id'],
                    'lot_number' => $allocation['lot_number'],
                    'expiry_date' => $allocation['expiry_date'],
                    'quantity' => $allocation['quantity'],
                    'consumed_quantity' => '0.000',
                    'released_quantity' => '0.000',
                    'status' => DeliveryStockReservationStatus::Reserved,
                ]);
            }

            return $batch->fresh(['reservations']);
        }, 3);
    }

    public function assertReadyForDeparture(Delivery $delivery): void
    {
        $batch = DeliveryStockReservationBatch::query()
            ->with('reservations')
            ->where('delivery_id', $delivery->id)
            ->lockForUpdate()
            ->first();
        if (! $batch || $batch->status !== DeliveryStockReservationStatus::Reserved) {
            throw new BusinessRuleException('Reserve the inspected lot and warehouse bins before this delivery can depart.');
        }

        $heldByLine = [];
        foreach ($batch->reservations as $reservation) {
            if ($reservation->status !== DeliveryStockReservationStatus::Reserved) continue;
            $heldByLine[$reservation->delivery_item_id] = bcadd(
                $heldByLine[$reservation->delivery_item_id] ?? '0.000',
                $reservation->remainingQuantity(),
                3,
            );
        }
        foreach ($delivery->items as $line) {
            if (bccomp($heldByLine[$line->id] ?? '0.000', (string) $line->quantity, 3) !== 0) {
                throw new BusinessRuleException("Delivery line {$line->id} is not fully backed by its durable physical stock reservation.");
            }
        }
    }

    /** Release all unconsumed held stock when a pre-dispatch delivery ends. */
    public function releaseForDelivery(Delivery $delivery): void
    {
        $batch = DeliveryStockReservationBatch::query()
            ->where('delivery_id', $delivery->id)
            ->first();
        if (! $batch || $batch->status === DeliveryStockReservationStatus::Released) {
            return;
        }
        if ($batch->status === DeliveryStockReservationStatus::Consumed) {
            throw new BusinessRuleException('Dispatched stock cannot be released through delivery cancellation.');
        }

        $rows = DeliveryStockReservation::query()
            ->where('reservation_batch_id', $batch->id)
            ->orderBy('item_id')->orderBy('location_id')->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            if ($row->status !== DeliveryStockReservationStatus::Reserved) continue;
            $remaining = $row->remainingQuantity();
            if (bccomp($remaining, '0', 3) > 0) {
                $this->movements->release((int) $row->item_id, (int) $row->location_id, $remaining);
                $row->forceFill([
                    'released_quantity' => bcadd((string) $row->released_quantity, $remaining, 3),
                    'status' => DeliveryStockReservationStatus::Released,
                ])->save();
            }
        }
        $batch->forceFill(['status' => DeliveryStockReservationStatus::Released])->save();
    }

    /** @return array<int, array{delivery_item_id:int,item_id:int,location_id:int,lot_number:string,expiry_date:?string,quantity:string}> */
    private function allocationPlan(Delivery $delivery): array
    {
        $lines = $delivery->items->sortBy(fn (DeliveryItem $line): array => [
            (int) $line->salesOrderItem?->product?->id,
            (int) $line->id,
        ]);
        $plannedLevel = [];
        $plannedLot = [];
        $plan = [];

        foreach ($lines as $line) {
            [$item, $receipt] = $this->dispatchStock->reservationSource($delivery, $line);
            $levels = $this->dispatchStock->eligibleLevels((int) $item->id)
                ->orderBy('location_id')->lockForUpdate()->get();
            $remaining = (string) $line->quantity;
            foreach ($levels as $level) {
                $levelKey = $item->id.':'.$level->location_id;
                $lotKey = $levelKey.':'.$receipt->lot_number;
                $aggregate = bcsub((string) $level->quantity, (string) $level->reserved_quantity, 3);
                $aggregate = bcsub($aggregate, $plannedLevel[$levelKey] ?? '0.000', 3);
                $lotQuantity = $this->lots->lotQuantity((int) $item->id, (int) $level->location_id, (string) $receipt->lot_number);
                $lotQuantity = bcsub($lotQuantity, $this->activeLotHold((int) $item->id, (int) $level->location_id, (string) $receipt->lot_number), 3);
                $lotQuantity = bcsub($lotQuantity, $plannedLot[$lotKey] ?? '0.000', 3);
                $quantity = bccomp($aggregate, $lotQuantity, 3) < 0 ? $aggregate : $lotQuantity;
                if (bccomp($quantity, $remaining, 3) > 0) $quantity = $remaining;
                if (bccomp($quantity, '0', 3) <= 0) continue;

                $plan[] = [
                    'delivery_item_id' => (int) $line->id,
                    'item_id' => (int) $item->id,
                    'location_id' => (int) $level->location_id,
                    'lot_number' => (string) $receipt->lot_number,
                    'expiry_date' => $receipt->expiry_date?->toDateString(),
                    'quantity' => $quantity,
                ];
                $plannedLevel[$levelKey] = bcadd($plannedLevel[$levelKey] ?? '0.000', $quantity, 3);
                $plannedLot[$lotKey] = bcadd($plannedLot[$lotKey] ?? '0.000', $quantity, 3);
                $remaining = bcsub($remaining, $quantity, 3);
                if (bccomp($remaining, '0', 3) === 0) break;
            }
            if (bccomp($remaining, '0', 3) > 0) {
                throw new BusinessRuleException("Approved lot {$receipt->lot_number} is short by {$remaining} for {$item->code}. Ask Warehouse to make this lot available in an active finished-goods bin before departure.");
            }
        }

        return $plan;
    }

    private function activeLotHold(int $itemId, int $locationId, string $lotNumber): string
    {
        return DeliveryStockReservation::query()
            ->where('item_id', $itemId)->where('location_id', $locationId)
            ->where('lot_number', $lotNumber)->where('status', DeliveryStockReservationStatus::Reserved)
            ->get(['quantity', 'consumed_quantity', 'released_quantity'])
            ->reduce(fn (string $sum, DeliveryStockReservation $reservation): string => bcadd(
                $sum,
                $reservation->remainingQuantity(),
                3,
            ), '0.000');
    }

    private function fingerprint(Delivery $delivery, User $by, bool $recovery): string
    {
        return hash('sha256', json_encode([
            'delivery_id' => (int) $delivery->id,
            'actor_id' => (int) $by->id,
            'recovery' => $recovery,
            'lines' => $delivery->items->sortBy('id')->map(fn (DeliveryItem $line): array => [
                'delivery_item_id' => (int) $line->id,
                'quantity' => (string) $line->quantity,
                'inspection_id' => (int) $line->inspection_id,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
