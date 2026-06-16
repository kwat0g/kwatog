<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Requests\StoreStockAdjustmentRequest;
use App\Modules\Inventory\Resources\StockAdjustmentResource;
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
            // OGAMI-012 — route through create() so the structured reason_code
            // and the value-threshold approval gate are honoured. Above-threshold
            // adjustments come back `pending` with no movement yet.
            $adjustment = $this->service->create(
                itemId: $itemId,
                locationId: $locId,
                direction: $data['direction'],
                qty: (string) $data['quantity'],
                unitCost: isset($data['unit_cost']) ? (string) $data['unit_cost'] : null,
                reason: $data['reason'],
                by: $request->user(),
                reasonCode: $data['reason_code'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new StockAdjustmentResource(
            $adjustment->load(['item', 'location', 'stockMovement', 'requester', 'approver'])
        ))->response()->setStatusCode(201);
    }

    public function approve(StockAdjustment $stockAdjustment): JsonResponse
    {
        try {
            $adjustment = $this->service->approve($stockAdjustment, request()->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new StockAdjustmentResource(
            $adjustment->load(['item', 'location', 'stockMovement', 'requester', 'approver'])
        ))->response();
    }
}
