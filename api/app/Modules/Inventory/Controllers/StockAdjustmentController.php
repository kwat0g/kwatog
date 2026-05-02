<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Requests\StoreStockAdjustmentRequest;
use App\Modules\Inventory\Resources\StockMovementResource;
use App\Modules\Inventory\Services\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class StockAdjustmentController
{
    public function __construct(private readonly StockAdjustmentService $service) {}

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $itemId = HashIdFilter::decode($data['item_id'], Item::class) ?? (int) $data['item_id'];
        $locId  = HashIdFilter::decode($data['location_id'], WarehouseLocation::class) ?? (int) $data['location_id'];
        try {
            $movement = $data['direction'] === 'in'
                ? $this->service->adjustIn($itemId, $locId, (string) $data['quantity'], (string) ($data['unit_cost'] ?? '0'), $data['reason'], $request->user())
                : $this->service->adjustOut($itemId, $locId, (string) $data['quantity'], $data['reason'], $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new StockMovementResource($movement->load(['item', 'fromLocation', 'toLocation'])))
            ->response()->setStatusCode(201);
    }
}
