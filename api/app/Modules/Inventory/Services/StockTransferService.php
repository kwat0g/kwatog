<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockMovementInput;

class StockTransferService
{
    public function __construct(private readonly StockMovementService $movements) {}

    public function transfer(int $itemId, int $fromLocationId, int $toLocationId, string $qty, ?string $remarks, User $by): StockMovement
    {
        $level = StockLevel::query()->where('item_id', $itemId)->where('location_id', $fromLocationId)->first();
        $cost = $level?->weighted_avg_cost ?? '0';
        return $this->movements->move(new StockMovementInput(
            type: StockMovementType::Transfer,
            itemId: $itemId,
            fromLocationId: $fromLocationId,
            toLocationId: $toLocationId,
            quantity: $qty,
            unitCost: (string) $cost,
            referenceType: 'stock_transfer',
            referenceId: null,
            remarks: $remarks,
            createdBy: $by->id,
        ));
    }
}
