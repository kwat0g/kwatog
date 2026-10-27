<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\ReorderMethod;
use App\Modules\Inventory\Enums\StockAdjustmentDirection;
use App\Modules\Inventory\Enums\StockStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Requests\StoreItemRequest;
use App\Modules\Inventory\Requests\UpdateItemRequest;
use App\Modules\Inventory\Resources\ItemCatalogOptionResource;
use App\Modules\Inventory\Resources\ItemResource;
use App\Modules\Inventory\Services\AbcClassificationService;
use App\Modules\Inventory\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ItemController
{
    public function __construct(
        private readonly ItemService $service,
        private readonly AbcClassificationService $abcService,
    ) {}

    public function options(): JsonResponse
    {
        $label = static fn (string $value): string => ucwords(str_replace('_', ' ', $value));

        return response()->json(['data' => [
            'item_types' => array_map(fn (ItemType $type) => ['value' => $type->value, 'label' => $type->label()], ItemType::cases()),
            'replenishment_basis' => [
                'demand_history_days' => app(SettingsService::class)->requiredInt('inventory.replenishment.demand_history_days', 1),
                'coverage_buffer_ratio' => app(SettingsService::class)->requiredFloat('inventory.replenishment.coverage_buffer_ratio', 1),
                'default_lead_time_days' => app(SettingsService::class)->requiredInt('mrp.default_lead_time_days', 0, 365),
            ],
            'reorder_methods' => array_map(fn (ReorderMethod $method) => ['value' => $method->value, 'label' => $label($method->value)], ReorderMethod::cases()),
            'adjustment_directions' => array_map(
                static fn (StockAdjustmentDirection $direction): array => [
                    'value' => $direction->value,
                    'label' => $direction === StockAdjustmentDirection::In
                        ? 'Increase (IN — cycle count over)'
                        : 'Decrease (OUT — cycle count short / scrap)',
                ],
                StockAdjustmentDirection::cases(),
            ),
            'stock_statuses' => array_map(
                static fn (StockStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                StockStatus::cases(),
            ),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['signal' => ['nullable', Rule::in(['low_stock', 'out_of_stock'])]]);

        return ItemResource::collection($this->service->list($request->query()));
    }

    public function catalog(Request $request): AnonymousResourceCollection
    {
        $request->validate(['signal' => ['nullable', Rule::in(['low_stock', 'out_of_stock'])]]);

        return ItemCatalogOptionResource::collection($this->service->list($request->query()));
    }

    public function show(Item $item): ItemResource
    {
        return new ItemResource($this->service->show($item));
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->service->create($request->validated());

        return (new ItemResource($item))->response()->setStatusCode(201);
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        return new ItemResource($this->service->update($item, $request->validated()));
    }

    public function destroy(Item $item): JsonResponse
    {
        try {
            $this->service->delete($item);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    public function restore(Item $item): JsonResponse
    {
        $item->restore();

        return response()->json(['message' => 'Item restored.']);
    }

    public function recomputeAbc(): JsonResponse
    {
        $result = $this->abcService->compute();

        return response()->json([
            'message' => 'ABC classification recomputed successfully.',
            'data' => $result,
        ]);
    }
}
