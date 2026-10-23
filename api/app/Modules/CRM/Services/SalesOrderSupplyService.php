<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\SalesOrderStockAllocation;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\QualityStockHoldService;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryStockReservation;
use App\Modules\SupplyChain\Services\DeliveryStockService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SalesOrderSupplyService
{
    private array $coverageByLine = [];

    private array $preparedShipments = [];

    private array $preparedWorkOrders = [];

    private array $preparedInspections = [];

    private array $preparedItems = [];

    public function prepareCoverage(Collection $orders): void
    {
        $this->coverageByLine = [];
        $lines = $orders->flatMap(fn ($order) => $order->items)->filter(fn ($line) => $line->fulfillment_policy === 'stock_first');
        if ($lines->isEmpty()) {
            return;
        }
        $lines = new \Illuminate\Database\Eloquent\Collection($lines->all());
        $lines->load(['product.inspectionSpec.currentRevision', 'stockAllocations.output.workOrder', 'stockAllocations.output.productionReceiptMovement', 'stockAllocations.salesOrderItem.product.inspectionSpec.currentRevision']);
        $ids = $lines->pluck('id');
        $this->preparedShipments = DeliveryItem::whereIn('sales_order_item_id', $ids)
            ->whereHas('delivery', fn ($q) => $q->whereNotIn('status', ['cancelled', 'returned']))
            ->with(['inspection', 'stockAllocation.output.workOrder', 'stockAllocation.output.productionReceiptMovement', 'stockAllocation.salesOrderItem.product.inspectionSpec.currentRevision'])
            ->get()->groupBy('sales_order_item_id')->all();
        $workOrders = WorkOrder::whereIn('sales_order_item_id', $ids)->whereNull('parent_wo_id')->where('status', '!=', 'cancelled')
            ->with(['outputs', 'currentProductionSchedule', 'materials'])->get();
        $this->preparedWorkOrders = $workOrders->groupBy('sales_order_item_id')->all();
        $outputIds = $workOrders->flatMap(fn ($order) => $order->outputs->pluck('id'))->merge($lines->flatMap(fn ($line) => $line->stockAllocations->pluck('work_order_output_id')))->unique();
        $this->preparedInspections = Inspection::whereIn('work_order_output_id', $outputIds)->where('stage', 'outgoing')->where('status', '!=', 'cancelled')
            ->with(['ncr', 'resultAuthors', 'workOrderOutput.workOrder'])->latest('id')->get()->unique('work_order_output_id')->keyBy('work_order_output_id')->all();
        $this->preparedItems = Item::whereIn('id', $lines->flatMap(fn ($line) => $line->stockAllocations->pluck('item_id')))->get()->keyBy('id')->all();
        foreach ($lines as $line) {
            $this->preparedShipments[$line->id] ??= collect();
            $this->preparedWorkOrders[$line->id] ??= collect();
            $this->coverageByLine[$line->id] = $this->lineCoverage($line);
        }
        $this->preparedShipments = $this->preparedWorkOrders = $this->preparedInspections = $this->preparedItems = [];
    }

    public function preview(SalesOrder $order): array
    {
        $this->coverageByLine = [];
        $proposed = [];
        $lines = [];
        foreach ($order->items()->with('product')->orderBy('id')->get() as $line) {
            $coverage = $this->lineCoverage($line);
            $sources = ($line->fulfillment_policy === 'stock_first') ? $this->sources($line) : [];
            $free = '0.000';
            $remainingProposed = $coverage['uncovered'];
            foreach ($sources as $source) {
                $key = $source['output']->id.':'.$source['level']->id;
                $available = self::positive(bcsub($source['quantity'], $proposed[$key] ?? '0', 3));
                $free = bcadd($free, $available, 3);
                $take = self::minimum($available, $remainingProposed);
                $proposed[$key] = bcadd($proposed[$key] ?? '0', $take, 3);
                $remainingProposed = bcsub($remainingProposed, $take, 3);
            }
            $stock = self::minimum($free, $coverage['uncovered']);
            $lines[] = $coverage + [
                'id' => $line->hash_id, 'part_number' => $line->product?->part_number, 'name' => $line->product?->name,
                'policy' => $line->fulfillment_policy ?? 'produce_new', 'eligible_free_stock' => $free,
                'proposed_stock' => $stock, 'production_shortage' => bcsub($coverage['uncovered'], $stock, 3),
            ];
        }

        return ['revision' => $this->revision($order), 'as_of' => now()->toIso8601String(),
            'editable' => $order->status->value === 'confirmed', 'lines' => $lines];
    }

    public function revision(SalesOrder $order): string
    {
        return hash('sha256', json_encode([$order->status->value, $order->items()->orderBy('id')->get(['id', 'quantity', 'fulfillment_policy', 'quantity_delivered'])->toArray()], JSON_THROW_ON_ERROR));
    }

    public function allocate(SalesOrder $order, User $actor, string $requestKey): array
    {
        if (! Str::isUuid($requestKey)) {
            throw new BusinessRuleException('A UUID allocation request key is required.');
        }

        return DB::transaction(function () use ($order, $actor, $requestKey): array {
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            $this->coverageByLine = [];
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status->value, ['confirmed', 'in_production', 'partially_delivered'], true)) {
                return $this->preview($order);
            }
            $fingerprint = hash('sha256', $actor->id.':'.$order->id.':'.$this->revision($order));
            $prior = SalesOrderStockAllocation::where('request_key', $requestKey)->first();
            if ($prior && ! hash_equals($prior->payload_fingerprint, $fingerprint)) {
                throw new BusinessRuleException('Allocation key belongs to another order revision.');
            }
            if ($prior) {
                return $this->preview($order);
            }
            foreach ($order->items()->orderBy('id')->lockForUpdate()->get() as $line) {
                if ($line->fulfillment_policy !== 'stock_first') {
                    continue;
                }
                $remaining = $this->lineCoverage($line)['uncovered'];
                foreach ($this->sources($line, true) as $source) {
                    $quantity = self::minimum($remaining, $source['quantity']);
                    // Whole pieces cannot be truncated into a fractional reservation.
                    $quantity = bcadd(bcadd($quantity, '0', 0), '0', 3);
                    if (bccomp($quantity, '0', 3) <= 0) {
                        continue;
                    }
                    app(StockMovementService::class)->reserve($source['item']->id, $source['level']->location_id, $quantity);
                    SalesOrderStockAllocation::create([
                        'sales_order_item_id' => $line->id, 'work_order_output_id' => $source['output']->id,
                        'inspection_id' => $source['inspection']->id, 'item_id' => $source['item']->id,
                        'location_id' => $source['level']->location_id, 'lot_number' => $source['receipt']->lot_number,
                        'expiry_date' => $source['receipt']->expiry_date, 'quantity' => $quantity,
                        'allocated_by' => $actor->id, 'request_key' => $requestKey, 'payload_fingerprint' => $fingerprint,
                    ]);
                    $remaining = bcsub($remaining, $quantity, 3);
                    if (bccomp($remaining, '0', 3) <= 0) {
                        break;
                    }
                }
            }

            return $this->preview($order);
        }, 3);
    }

    public function lineCoverage(SalesOrderItem $line): array
    {
        if (DB::transactionLevel() === 0 && isset($this->coverageByLine[$line->id])) {
            return $this->coverageByLine[$line->id];
        }
        $delivered = bcadd((string) $line->quantity_delivered, '0', 3);
        $stock = '0.000';
        $awaiting = '0.000';
        $approved = '0.000';
        $commitments = '0.000';
        $shipments = $this->preparedShipments[$line->id] ?? DeliveryItem::where('sales_order_item_id', $line->id)->whereHas('delivery', fn ($q) => $q->whereNotIn('status', ['cancelled', 'returned']))->with(['inspection', 'stockAllocation.output.workOrder', 'stockAllocation.output.productionReceiptMovement', 'stockAllocation.salesOrderItem.product.inspectionSpec.currentRevision'])->get();
        $represented = '0.000';
        foreach ($shipments as $shipment) {
            $represented = bcadd($represented, (string) $shipment->quantity, 3);
        }
        foreach (($line->relationLoaded('stockAllocations') ? $line->stockAllocations : $line->stockAllocations()->with(['inspection.ncr', 'inspection.resultAuthors', 'inspection.workOrderOutput.workOrder', 'salesOrderItem.product.inspectionSpec.currentRevision', 'output.workOrder', 'output.productionReceiptMovement'])->get()) as $allocation) {
            if ($this->validAllocation($allocation)) {
                $stock = bcadd($stock, $allocation->untransferredQuantity(), 3);
            }
        }
        foreach (($this->preparedWorkOrders[$line->id] ?? WorkOrder::where('sales_order_item_id', $line->id)->whereNull('parent_wo_id')->where('status', '!=', 'cancelled')->with('outputs')->get()) as $workOrder) {
            foreach ($workOrder->outputs as $output) {
                $inspection = $this->latestInspection($output->id);
                $used = bcadd((string) $shipments->where('inspection_id', $inspection?->id)->sum('quantity'), '0', 3);
                if ($inspection?->canReleaseOutgoing()) {
                    $approved = bcadd($approved, self::positive(bcsub((string) $inspection->accepted_quantity, $used, 3)), 3);
                } elseif (! $inspection || ! in_array($inspection->status->value, ['failed', 'cancelled'], true)) {
                    $awaiting = bcadd($awaiting, (string) $output->good_count, 3);
                }
            }
            if (in_array($workOrder->status->value, ['planned', 'confirmed', 'in_progress', 'paused'], true)) {
                $untouchedAuto = $workOrder->status->value === 'planned' && $workOrder->mrp_plan_id !== null
                    && ! ($workOrder->relationLoaded('currentProductionSchedule') ? $workOrder->currentProductionSchedule : $workOrder->currentProductionSchedule()->exists())
                    && ! ($workOrder->relationLoaded('materials') ? $workOrder->materials->contains(fn ($material) => bccomp((string) $material->actual_quantity_issued, '0', 3) > 0) : $workOrder->materials()->where('actual_quantity_issued', '>', 0)->exists());
                if (! $untouchedAuto) {
                    $commitments = bcadd($commitments, self::positive(bcsub((string) $workOrder->quantity_target, (string) $workOrder->quantity_produced, 3)), 3);
                }
            }
        }
        foreach ($shipments as $shipment) {
            if ($shipment->stock_movement_id !== null) {
                continue;
            }
            if ($shipment->sales_order_stock_allocation_id && ! $this->validAllocation($shipment->stockAllocation)) {
                $represented = self::positive(bcsub($represented, (string) $shipment->quantity, 3));
            } elseif (! $shipment->sales_order_stock_allocation_id && ! $this->latestInspection($shipment->inspection?->work_order_output_id ?? 0)?->canReleaseOutgoing()) {
                $represented = self::positive(bcsub($represented, (string) $shipment->quantity, 3));
            }
        }
        $represented = self::maximum($represented, $delivered);
        $ready = bcadd(bcadd($represented, $stock, 3), $approved, 3);
        $covered = bcadd(bcadd($ready, $awaiting, 3), $commitments, 3);

        return ['demand' => bcadd((string) $line->quantity, '0', 3), 'delivered' => $delivered,
            'shipment_quantity' => $represented, 'allocated_stock' => $stock, 'approved_output' => $approved,
            'production_commitments' => $commitments, 'awaiting_qc' => $awaiting,
            'ready_quantity' => self::minimum((string) $line->quantity, $ready),
            'uncovered' => self::positive(bcsub((string) $line->quantity, $covered, 3))];
    }

    public function sources(SalesOrderItem $line, bool $lock = false): array
    {
        $line->loadMissing('product.inspectionSpec.currentRevision');
        $product = $line->product;
        $items = Item::where('code', $product?->part_number)->where('is_active', true)->where('item_type', 'finished_good')->get();
        $spec = $product?->inspectionSpec;
        if (! $product?->is_active || $items->count() !== 1 || strcasecmp((string) $items->first()?->unit_of_measure, (string) $product->unit_of_measure) !== 0 || ! $spec?->is_active || ! $spec->currentRevision) {
            return [];
        }
        $item = $items->first();
        $query = Inspection::where('product_id', $product->id)->where('stage', 'outgoing')->where('status', '!=', 'cancelled')
            ->whereHas('workOrderOutput.workOrder', fn ($q) => $q->where('production_purpose', 'stock')->whereNull('sales_order_id')->whereNull('parent_wo_id'))
            ->with(['ncr', 'resultAuthors', 'workOrderOutput.workOrder', 'workOrderOutput.productionReceiptMovement'])->orderByDesc('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $inspections = $query->get()->unique('work_order_output_id');
        $result = [];
        $plannedBins = [];
        $plannedLots = [];
        foreach ($inspections as $inspection) {
            if (! $inspection->canReleaseOutgoing() || (int) $inspection->inspection_spec_revision_id !== (int) $spec->currentRevision->id) {
                continue;
            }
            $output = $inspection->workOrderOutput;
            $receipt = $output?->productionReceiptMovement;
            if (! $receipt || $receipt->reference_type !== 'work_order_output' || (int) $receipt->reference_id !== (int) $output->id
                || (int) $receipt->item_id !== (int) $item->id || ! $receipt->gl_handoff_status?->isResolved()
                || ! $receipt->lot_number || app(StockLocationSummaryService::class)->isExpired($receipt->expiry_date?->toDateString())) {
                continue;
            }
            $allocationUsed = SalesOrderStockAllocation::where('work_order_output_id', $output->id)->get()
                ->reduce(fn ($sum, $a) => bcadd($sum, bcsub((string) $a->quantity, (string) $a->released_quantity, 3), 3), '0.000');
            $ordinaryUsed = DeliveryItem::where('inspection_id', $inspection->id)->whereNull('sales_order_stock_allocation_id')->whereHas('delivery', fn ($q) => $q->where('status', '!=', 'cancelled'))->sum('quantity');
            $capacity = self::positive(bcsub(bcsub((string) $inspection->accepted_quantity, $allocationUsed, 3), (string) $ordinaryUsed, 3));
            $levels = app(DeliveryStockService::class)->eligibleLevels($item->id)->orderBy('location_id');
            foreach ((clone $levels)->get() as $level) {
                app(QualityStockHoldService::class)->heldQuantity($item->id, $level->location_id);
            }
            if ($lock) {
                $levels->lockForUpdate();
            }
            foreach ($levels->get() as $level) {
                $binKey = $level->id;
                $lotKey = $level->id.':'.$receipt->lot_number;
                $free = self::positive(bcsub(bcsub((string) $level->quantity, (string) $level->reserved_quantity, 3), $plannedBins[$binKey] ?? '0', 3));
                $lot = app(StockLocationSummaryService::class)->lotQuantity($item->id, $level->location_id, $receipt->lot_number);
                $lot = bcsub(bcsub($lot, self::lotHold($item->id, $level->location_id, $receipt->lot_number), 3),
                    app(QualityStockHoldService::class)->heldQuantity($item->id, $level->location_id, $receipt->lot_number), 3);
                $lot = bcsub($lot, $plannedLots[$lotKey] ?? '0', 3);
                $quantity = self::positive(self::minimum($capacity, self::minimum($free, $lot)));
                $plannedBins[$binKey] = bcadd($plannedBins[$binKey] ?? '0', $quantity, 3);
                $plannedLots[$lotKey] = bcadd($plannedLots[$lotKey] ?? '0', $quantity, 3);
                if (bccomp($quantity, '0', 3) > 0) {
                    $result[] = compact('item', 'inspection', 'output', 'receipt', 'level', 'quantity');
                }
                $capacity = self::positive(bcsub($capacity, $quantity, 3));
            }
        }
        usort($result, fn ($a, $b) => [$a['receipt']->expiry_date?->toDateString() ?? '9999', $a['receipt']->id, $a['level']->id] <=> [$b['receipt']->expiry_date?->toDateString() ?? '9999', $b['receipt']->id, $b['level']->id]);
        $freeTotal = array_reduce($result, fn ($sum, $row) => bcadd($sum, $row['quantity'], 3), '0.000');
        $budget = self::positive(bcsub($freeTotal, (string) $item->safety_stock, 3));
        foreach ($result as &$source) {
            $source['quantity'] = self::minimum($source['quantity'], $budget);
            $budget = bcsub($budget, $source['quantity'], 3);
        }
        unset($source);

        return $result;
    }

    public function validAllocation(SalesOrderStockAllocation $allocation): bool
    {
        $allocation->loadMissing(['salesOrderItem.product.inspectionSpec.currentRevision', 'output.workOrder', 'output.productionReceiptMovement']);
        $line = $allocation->salesOrderItem;
        $workOrder = $allocation->output?->workOrder;
        $inspection = $this->latestInspection($allocation->work_order_output_id);
        $receipt = $allocation->output?->productionReceiptMovement;
        $spec = $line?->product?->inspectionSpec;
        $item = $this->preparedItems[$allocation->item_id] ?? Item::find($allocation->item_id);

        return $this->allocationHasPhysicalStock($allocation) && $workOrder?->production_purpose === 'stock' && $workOrder->sales_order_id === null
            && $inspection && $inspection->id === $allocation->inspection_id && $inspection->canReleaseOutgoing()
            && $spec?->is_active && (int) $inspection->inspection_spec_revision_id === (int) $spec->currentRevision?->id
            && $line?->product?->is_active
            && $item?->is_active && strcasecmp((string) $item->unit_of_measure, (string) $line->product->unit_of_measure) === 0 && $item->item_type->value === 'finished_good' && $item->code === $line->product->part_number
            && $receipt && $receipt->gl_handoff_status?->isResolved()
            && $receipt->reference_type === 'work_order_output' && (int) $receipt->reference_id === (int) $allocation->work_order_output_id
            && (int) $receipt->item_id === (int) $allocation->item_id && $receipt->lot_number === $allocation->lot_number
            && ! app(StockLocationSummaryService::class)->isExpired($allocation->expiry_date?->toDateString())
            && ! app(StockLocationSummaryService::class)->isExpired($receipt->expiry_date?->toDateString());
    }

    private function allocationHasPhysicalStock(SalesOrderStockAllocation $allocation): bool
    {
        $remaining = bcsub(bcsub((string) $allocation->quantity, (string) $allocation->released_quantity, 3), (string) $allocation->consumed_quantity, 3);
        if (bccomp($remaining, '0', 3) <= 0) return true;
        $level = app(DeliveryStockService::class)->eligibleLevels($allocation->item_id)->where('location_id', $allocation->location_id)->first();
        if (! $level) return false;
        $otherHeld = self::positive(bcsub((string) $level->reserved_quantity, $remaining, 3));
        if (bccomp(bcsub((string) $level->quantity, $otherHeld, 3), $remaining, 3) < 0) return false;
        $lot = app(StockLocationSummaryService::class)->lotQuantity($allocation->item_id, $allocation->location_id, $allocation->lot_number);
        $otherLotHeld = self::positive(bcsub(self::lotHold($allocation->item_id, $allocation->location_id, $allocation->lot_number), $remaining, 3));
        $qualityHeld = app(QualityStockHoldService::class)->heldQuantity($allocation->item_id, $allocation->location_id, $allocation->lot_number);
        return bccomp(bcsub(bcsub($lot, $otherLotHeld, 3), $qualityHeld, 3), $remaining, 3) >= 0;
    }

    public function assertAllocationForDelivery(SalesOrderStockAllocation $allocation, SalesOrderItem $line, Inspection $inspection, string $quantity, bool $transferred = false): void
    {
        if ((int) $allocation->sales_order_item_id !== (int) $line->id || (int) $allocation->inspection_id !== (int) $inspection->id
            || (int) $inspection->product_id !== (int) $line->product_id || ! $this->validAllocation($allocation)
            || bccomp($quantity, '0', 3) <= 0 || bccomp($quantity, $transferred ? (string) $allocation->transferred_quantity : $allocation->untransferredQuantity(), 3) > 0) {
            throw new BusinessRuleException('Stock allocation does not authorize this order line, inspection, quantity or current QC release.');
        }
    }

    public function transferToDelivery(SalesOrderStockAllocation $allocation, DeliveryItem $deliveryItem, User $actor): void
    {
        $this->assertAllocationForDelivery($allocation, $deliveryItem->salesOrderItem, $deliveryItem->inspection, (string) $deliveryItem->quantity);
        $allocation->forceFill(['transferred_quantity' => bcadd((string) $allocation->transferred_quantity, (string) $deliveryItem->quantity, 3)])->save();
    }

    public function releaseLine(SalesOrderItem $line): void
    {
        foreach ($line->stockAllocations()->orderBy('id')->lockForUpdate()->get() as $allocation) {
            $remaining = $allocation->untransferredQuantity();
            if (bccomp($remaining, '0', 3) <= 0) {
                continue;
            }
            app(StockMovementService::class)->release($allocation->item_id, $allocation->location_id, $remaining);
            $allocation->forceFill(['released_quantity' => bcadd((string) $allocation->released_quantity, $remaining, 3), 'status' => 'released'])->save();
        }
    }

    public function releaseUnshipped(SalesOrder $order, User $actor, string $reason): void
    {
        DB::transaction(function () use ($order): void {
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            foreach (SalesOrderStockAllocation::whereIn('sales_order_item_id', $order->items()->select('id'))->orderBy('id')->lockForUpdate()->get() as $allocation) {
                $remaining = $allocation->untransferredQuantity();
                if (bccomp($remaining, '0', 3) <= 0) {
                    continue;
                }
                app(StockMovementService::class)->release($allocation->item_id, $allocation->location_id, $remaining);
                $allocation->forceFill(['released_quantity' => bcadd((string) $allocation->released_quantity, $remaining, 3), 'status' => 'released'])->save();
            }
        }, 3);
    }

    public static function lotHold(int $itemId, int $locationId, string $lot): string
    {
        $sum = SalesOrderStockAllocation::where('item_id', $itemId)->where('location_id', $locationId)->where('lot_number', $lot)->get()
            ->reduce(fn ($sum, $allocation) => bcadd($sum, $allocation->untransferredQuantity(), 3), '0.000');

        return DeliveryStockReservation::where('item_id', $itemId)->where('location_id', $locationId)->where('lot_number', $lot)->where('status', 'reserved')->get()
            ->reduce(fn ($sum, $hold) => bcadd($sum, $hold->remainingQuantity(), 3), $sum);
    }

    private function latestInspection(int $outputId): ?Inspection
    {
        if (isset($this->preparedInspections[$outputId])) {
            return $this->preparedInspections[$outputId];
        }

        return Inspection::where('work_order_output_id', $outputId)->where('stage', 'outgoing')->where('status', '!=', 'cancelled')->with(['ncr', 'resultAuthors', 'workOrderOutput.workOrder'])->latest('id')->first();
    }

    private static function positive(string $q): string
    {
        return bccomp($q, '0', 3) < 0 ? '0.000' : bcadd($q, '0', 3);
    }

    private static function minimum(string $a, string $b): string
    {
        return bccomp($a, $b, 3) < 0 ? $a : $b;
    }

    private static function maximum(string $a, string $b): string
    {
        return bccomp($a, $b, 3) > 0 ? $a : $b;
    }
}
