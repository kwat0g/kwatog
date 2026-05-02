<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockMovementInput;

class StockAdjustmentService
{
    public function __construct(private readonly StockMovementService $movements) {}

    public function adjustIn(int $itemId, int $locationId, string $qty, string $unitCost, string $reason, User $by): StockMovement
    {
        return $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $itemId,
            fromLocationId: null,
            toLocationId: $locationId,
            quantity: $qty,
            unitCost: $unitCost,
            referenceType: 'stock_adjustment',
            referenceId: null,
            remarks: $reason,
            createdBy: $by->id,
        ));
    }

    public function adjustOut(int $itemId, int $locationId, string $qty, string $reason, User $by): StockMovement
    {
        $level = StockLevel::query()->where('item_id', $itemId)->where('location_id', $locationId)->first();
        $cost = $level?->weighted_avg_cost ?? '0';
        return $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentOut,
            itemId: $itemId,
            fromLocationId: $locationId,
            toLocationId: null,
            quantity: $qty,
            unitCost: (string) $cost,
            referenceType: 'stock_adjustment',
            referenceId: null,
            remarks: $reason,
            createdBy: $by->id,
        ));
    }
}
