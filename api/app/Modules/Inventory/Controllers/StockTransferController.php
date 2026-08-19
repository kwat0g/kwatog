<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Resources\StockMovementResource;
use App\Modules\Inventory\Services\StockTransferService;
use Illuminate\Http\JsonResponse;

class StockTransferController
{
    public function __construct(private readonly StockTransferService $service) {}

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $data = $request->validated();
        $itemId = HashIdFilter::decode($data['item_id'], Item::class) ?? (int) $data['item_id'];
        $from   = HashIdFilter::decode($data['from_location_id'], WarehouseLocation::class) ?? (int) $data['from_location_id'];
        $to     = HashIdFilter::decode($data['to_location_id'], WarehouseLocation::class) ?? (int) $data['to_location_id'];
        try {
            $movement = $this->service->transfer($itemId, $from, $to, (string) $data['quantity'], $data['remarks'] ?? null, $request->user());
        } catch (BusinessRuleException|ClosedPeriodException|InsufficientStockException|InvalidMovementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new StockMovementResource($movement->load(['item', 'fromLocation', 'toLocation'])))
            ->response()->setStatusCode(201);
    }
}
