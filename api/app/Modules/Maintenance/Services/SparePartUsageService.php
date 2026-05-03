<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\SparePartUsage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Sprint 8 — Task 69. */
class SparePartUsageService
{
    public function __construct(
        private readonly StockMovementService $stockMovements,
    ) {}

    /**
     * Record spare-part consumption and deduct stock.
     *
     * @param array{item_id:int, location_id:int, quantity:string|float} $data
     */
    public function record(MaintenanceWorkOrder $wo, array $data, User $by): SparePartUsage
    {
        return DB::transaction(function () use ($wo, $data, $by) {
            $item = Item::query()->whereKey((int) $data['item_id'])->lockForUpdate()->firstOrFail();
            if ($item->item_type !== ItemType::SparePart) {
                throw new RuntimeException('Item must be a spare_part to be issued for maintenance.');
            }

            // Cost-out at current WAC for the source location
            $level = StockLevel::query()
                ->where('item_id', $item->id)
                ->where('location_id', (int) $data['location_id'])
                ->firstOrFail();
            $unitCost = (string) $level->weighted_avg_cost;
            $qty = (string) $data['quantity'];
            $totalCost = number_format((float) $unitCost * (float) $qty, 2, '.', '');

            $movement = $this->stockMovements->move(new StockMovementInput(
                type: StockMovementType::MaterialIssue,
                itemId: $item->id,
                fromLocationId: (int) $data['location_id'],
                toLocationId: null,
                quantity: $qty,
                unitCost: null,
                referenceType: MaintenanceWorkOrder::class,
                referenceId: $wo->id,
                remarks: 'Spare-part issue for '.$wo->mwo_number,
                createdBy: $by->id,
            ));

            $usage = SparePartUsage::create([
                'work_order_id'     => $wo->id,
                'item_id'           => $item->id,
                'quantity'          => $qty,
                'unit_cost'         => $unitCost,
                'total_cost'        => $totalCost,
                'stock_movement_id' => $movement->id,
                'created_at'        => now(),
            ]);

            // Bump WO running cost
            $wo->forceFill(['cost' => (string) ((float) $wo->cost + (float) $totalCost)])->save();

            return $usage->load('item:id,code,name,unit_of_measure');
        });
    }
}
