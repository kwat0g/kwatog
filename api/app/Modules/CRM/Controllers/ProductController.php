<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Requests\StoreProductRequest;
use App\Modules\CRM\Requests\UpdateProductRequest;
use App\Modules\CRM\Resources\ProductResource;
use App\Modules\CRM\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class ProductController
{
    public function __construct(private readonly ProductService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ProductResource::collection($this->service->list($request->query()));
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($this->service->show($product));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->service->create($request->validated());
        return (new ProductResource($product))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        return new ProductResource($this->service->update($product, $request->validated()));
    }

    public function destroy(Product $product): JsonResponse
    {
        try {
            $this->service->delete($product);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }
}
