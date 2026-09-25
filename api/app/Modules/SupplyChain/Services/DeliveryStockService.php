<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryStockReservation;

/** Dispatch runs inside DeliveryService's locked lifecycle transaction. */
class DeliveryStockService
{
    public function __construct(
        private readonly StockMovementService $movements,
        private readonly StockLocationSummaryService $lots,
    ) {}

    public function issue(Delivery $delivery): void
    {
        $delivery->loadMissing([
            'items.salesOrderItem.product',
            'items.inspection.workOrderOutput.workOrder',
            'items.inspection.workOrderOutput.productionReceiptMovement',
        ]);

        // Resolve all evidence before changing stock, then acquire stock rows
        // in inventory item/location order (product IDs can differ).
        $sources = $delivery->items
            ->filter(fn (DeliveryItem $line) => $line->stock_movement_id === null)
            ->map(fn (DeliveryItem $line) => [$line, ...$this->source($delivery, $line)])
            ->sortBy(fn (array $source) => [$source[1]->id, $source[0]->id]);
        foreach ($sources as [$line, $item, $receipt]) {

            // The approved lot may have moved or been split across FG bins.
            // Never substitute a generic FIFO lot of the same product.
            $reservations = DeliveryStockReservation::query()
                ->where('delivery_item_id', $line->id)
                ->where('status', 'reserved')
                ->orderBy('item_id')->orderBy('location_id')->orderBy('id')
                ->lockForUpdate()->get();
            if ($reservations->isEmpty()) {
                throw new BusinessRuleException("Delivery {$delivery->delivery_number} has no durable physical stock reservation. Ask Warehouse to reserve the approved lot before departure.");
            }
            $remaining = (string) $line->quantity;
            $firstMovementId = null;
            foreach ($reservations as $reservation) {
                $quantity = $reservation->remainingQuantity();
                if (bccomp($quantity, $remaining, 3) > 0) $quantity = $remaining;
                if (bccomp($quantity, '0', 3) <= 0) continue;
                $movement = $this->movements->move(new StockMovementInput(
                    type: StockMovementType::Delivery,
                    itemId: (int) $item->id,
                    quantity: $quantity,
                    fromLocationId: (int) $reservation->location_id,
                    referenceType: 'delivery_item',
                    referenceId: (int) $line->id,
                    remarks: "Delivery {$delivery->delivery_number}",
                    createdBy: auth()->id() ? (int) auth()->id() : null,
                    lotNumber: (string) $reservation->lot_number,
                    expiryDate: $reservation->expiry_date?->toDateString(),
                    deliveryStockReservationId: (int) $reservation->id,
                ));
                $firstMovementId ??= $movement->id;
                $remaining = bcsub($remaining, $quantity, 3);
                if (bccomp($remaining, '0', 3) === 0) break;
            }
            if (bccomp($remaining, '0', 3) > 0) {
                throw new BusinessRuleException("Approved lot {$receipt->lot_number} is short by {$remaining} for {$item->code}. Ask Warehouse to make this lot available in an active finished-goods bin before dispatch.");
            }
            // Compatibility pointer; stockMovements contains every bin issue.
            $line->forceFill(['stock_movement_id' => $firstMovementId])->save();
        }
    }
    /** Read-only pick guidance; physical availability is checked again at dispatch. */
    public function preparation(Delivery $delivery): array
    {
        $delivery->loadMissing([
            'items.salesOrderItem.product', 'items.inspection.workOrderOutput.workOrder',
            'items.inspection.workOrderOutput.productionReceiptMovement',
        ]);
        $result = [];
        $planned = [];
        $plannedLots = [];
        foreach ($delivery->items as $line) {
            if ($line->stock_movement_id !== null) continue;
            $row = ['delivery_item_id' => $line->hash_id, 'product' => $line->salesOrderItem?->product?->part_number,
                'quantity' => (string) $line->quantity, 'lot_number' => null, 'locations' => [], 'shortage' => (string) $line->quantity, 'message' => null];
            try {
                [$item, $receipt] = $this->source($delivery, $line);
                $row['lot_number'] = $receipt->lot_number;
                $levels = $this->eligibleLevels((int) $item->id)->with('location.zone.warehouse')->get();
                $remaining = (string) $line->quantity;
                foreach ($levels as $level) {
                    $key = $item->id.':'.$level->location_id;
                    $lotKey = $key.':'.$receipt->lot_number;
                    $available = bcsub(bcsub((string) $level->quantity, (string) $level->reserved_quantity, 3), $planned[$key] ?? '0', 3);
                    $lotAvailable = bcsub($this->lots->lotQuantity((int) $item->id, (int) $level->location_id, (string) $receipt->lot_number), $plannedLots[$lotKey] ?? '0', 3);
                    $quantity = bccomp($available, $lotAvailable, 3) < 0 ? $available : $lotAvailable;
                    $quantity = bccomp($quantity, $remaining, 3) < 0 ? $quantity : $remaining;
                    if (bccomp($quantity, '0', 3) <= 0) continue;
                    $row['locations'][] = ['code' => $level->location->full_code, 'quantity' => $quantity];
                    $planned[$key] = bcadd($planned[$key] ?? '0', $quantity, 3);
                    $plannedLots[$lotKey] = bcadd($plannedLots[$lotKey] ?? '0', $quantity, 3);
                    $remaining = bcsub($remaining, $quantity, 3);
                    if (bccomp($remaining, '0', 3) === 0) break;
                }
                $row['shortage'] = $remaining;
                if (bccomp($remaining, '0', 3) > 0) {
                    $row['message'] = "Short by {$remaining}. Ask Warehouse to make this approved lot available in a finished-goods bin.";
                }
            } catch (BusinessRuleException $error) {
                $row['message'] = $error->getMessage();
            }
            $result[] = $row;
        }
        return $result;
    }

    public function eligibleLevels(int $itemId): \Illuminate\Database\Eloquent\Builder
    {
        return StockLevel::query()->where('item_id', $itemId)
            ->whereHas('location', fn ($q) => $q->where('is_active', true)
                ->where('is_blocked', false)
                ->whereHas('zone', fn ($zone) => $zone->where('zone_type', 'finished_goods')
                    ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('is_active', true))))
            ->orderBy('location_id');
    }

    /** @return array{Item, StockMovement} */
    /** Resolve the same source evidence used by dispatch-time issue. */
    public function reservationSource(Delivery $delivery, DeliveryItem $line): array
    {
        return $this->source($delivery, $line);
    }

    private function source(Delivery $delivery, DeliveryItem $line): array
    {
        $inspection = $line->inspection;
        $output = $inspection?->workOrderOutput;
        $workOrder = $output?->workOrder;
        $receipt = $output?->productionReceiptMovement;
        $product = $line->salesOrderItem?->product;
        if (! $inspection || $inspection->stage !== InspectionStage::Outgoing
            || $inspection->status !== InspectionStatus::Passed || ! $inspection->isMakerChecked()
            || ! $workOrder || (int) $workOrder->sales_order_id !== (int) $delivery->sales_order_id
            || (int) $workOrder->sales_order_item_id !== (int) $line->sales_order_item_id
            || (int) $inspection->product_id !== (int) $product?->id) {
            throw new BusinessRuleException('Dispatch requires a reviewed outgoing inspection linked to this sales-order line. Ask Quality to reconcile the delivery evidence.');
        }

        $item = $product ? Item::query()->where('code', $product->part_number)
            ->where('item_type', ItemType::FinishedGood->value)->first() : null;
        if (! $item || ! $receipt || $receipt->movement_type !== StockMovementType::ProductionReceipt
            || (int) $receipt->item_id !== (int) $item->id
            || $receipt->reference_type !== 'work_order_output' || (int) $receipt->reference_id !== (int) $output->id
            || trim((string) $receipt->lot_number) === '') {
            throw new BusinessRuleException('The approved output has no traceable finished-goods receipt. Ask Production and Warehouse to reconcile its receipt and lot before dispatch.');
        }

        return [$item, $receipt];
    }

}
