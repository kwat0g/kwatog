<?php

declare(strict_types=1);

namespace App\Modules\MRP\Controllers;

use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Requests\StoreBomRequest;
use App\Modules\MRP\Resources\BomResource;
use App\Modules\MRP\Services\BomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class BomController
{
    public function __construct(private readonly BomService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return BomResource::collection($this->service->list($request->query()));
    }

    public function show(Bom $bom): BomResource
    {
        return new BomResource($this->service->show($bom));
    }

    public function store(StoreBomRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $bom = $this->service->create((int) $payload['product_id'], $payload['items']);
        return (new BomResource($bom))->response()->setStatusCode(201);
    }

    /** Behaves like create — produces a new version and deactivates the prior. */
    public function update(StoreBomRequest $request, Bom $bom): BomResource
    {
        $payload = $request->validated();
        $next = $this->service->update($bom, $payload['items']);
        return new BomResource($next);
    }

    public function destroy(Bom $bom): JsonResponse
    {
        try {
            $this->service->delete($bom);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }

    /** GET /products/{product}/bom — used by product detail page. */
    public function forProduct(Product $product): JsonResponse|BomResource
    {
        $bom = $this->service->activeForProduct($product->id);
        if (! $bom) {
            return response()->json(['data' => null]);
        }
        return new BomResource($bom);
    }
}
