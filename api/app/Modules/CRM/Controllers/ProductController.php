<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Requests\StoreProductRequest;
use App\Modules\CRM\Requests\UpdateProductCustomerAccessRequest;
use App\Modules\CRM\Requests\UpdateProductRequest;
use App\Modules\CRM\Resources\ProductOptionResource;
use App\Modules\CRM\Resources\ProductResource;
use App\Modules\CRM\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ProductController
{
    public function __construct(private readonly ProductService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ProductResource::collection($this->service->list($request->query()));
    }

    public function options(Request $request): AnonymousResourceCollection
    {
        return ProductOptionResource::collection($this->service->list($request->query()));
    }

    public function customerOptions(Request $request): JsonResponse
    {
        $params = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->service->portalCustomerOptions($params['search'] ?? null)]);
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

    public function updateCustomerAccess(UpdateProductCustomerAccessRequest $request, Product $product): ProductResource
    {
        return new ProductResource($this->service->updateCustomerAccess($product, $request->validated()));
    }

    public function destroy(Product $product): JsonResponse
    {
        try {
            $this->service->delete($product);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    public function proposeFinancialChange(Request $request, Product $product): ProductResource
    {
        $data = $request->validate(['standard_cost' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'], 'minimum_selling_price' => ['present', 'nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'], 'base_selling_price' => ['present', 'nullable', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'], 'notes' => ['required', 'string', 'max:1000']]);

        return new ProductResource($this->service->proposeFinancialChange($product, $request->user(), $data));
    }

    public function decideFinancialChange(Request $request, Product $product): ProductResource
    {
        $data = $request->validate(['proposal_id' => ['required', 'uuid'], 'decision' => ['required', Rule::in(['approve', 'reject'])], 'notes' => ['required', 'string', 'max:1000']]);

        return new ProductResource($this->service->decideFinancialChange($product, $request->user(), $data));
    }

    public function restore(Product $product): JsonResponse
    {
        $this->service->restore($product);

        return response()->json(['message' => 'Product restored.']);
    }
}
