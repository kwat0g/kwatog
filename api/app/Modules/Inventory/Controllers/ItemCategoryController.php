<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Requests\StoreItemCategoryRequest;
use App\Modules\Inventory\Requests\UpdateItemCategoryRequest;
use App\Modules\Inventory\Resources\ItemCategoryResource;
use App\Modules\Inventory\Services\ItemCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemCategoryController
{
    public function __construct(private readonly ItemCategoryService $service) {}

    public function index(): AnonymousResourceCollection
    {
        return ItemCategoryResource::collection($this->service->list());
    }

    public function tree(): AnonymousResourceCollection
    {
        return ItemCategoryResource::collection($this->service->tree());
    }

    public function store(StoreItemCategoryRequest $request): JsonResponse
    {
        $cat = $this->service->create($request->validated());
        return (new ItemCategoryResource($cat))->response()->setStatusCode(201);
    }

    public function update(UpdateItemCategoryRequest $request, ItemCategory $itemCategory): ItemCategoryResource
    {
        $cat = $this->service->update($itemCategory, $request->validated());
        return new ItemCategoryResource($cat);
    }

    public function destroy(ItemCategory $itemCategory): JsonResponse
    {
        try {
            $this->service->delete($itemCategory);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }
}
