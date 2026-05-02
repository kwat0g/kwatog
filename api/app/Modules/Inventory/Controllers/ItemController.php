<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Requests\StoreItemRequest;
use App\Modules\Inventory\Requests\UpdateItemRequest;
use App\Modules\Inventory\Resources\ItemResource;
use App\Modules\Inventory\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController
{
    public function __construct(private readonly ItemService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ItemResource::collection($this->service->list($request->query()));
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
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }
}
