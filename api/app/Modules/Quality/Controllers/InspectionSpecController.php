<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Requests\UpsertInspectionSpecRequest;
use App\Modules\Quality\Resources\InspectionSpecResource;
use App\Modules\Quality\Services\InspectionSpecService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InspectionSpecController
{
    public function __construct(private readonly InspectionSpecService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return InspectionSpecResource::collection($this->service->list($request->query()));
    }

    public function show(InspectionSpec $inspectionSpec): InspectionSpecResource
    {
        return new InspectionSpecResource($this->service->show($inspectionSpec));
    }

    /**
     * Upsert: create-or-replace the spec for a product. The same endpoint
     * powers both initial authoring and revisions; the service bumps the
     * version counter on every successful save.
     */
    public function upsert(UpsertInspectionSpecRequest $request): InspectionSpecResource
    {
        $data = $request->validated();
        $spec = $this->service->upsertForProduct(
            (int) $data['product_id'],
            $data['items'],
            $request->user()->id,
            $data['notes'] ?? null,
        );
        return new InspectionSpecResource($spec);
    }

    public function forProduct(Product $product): JsonResponse
    {
        $spec = $this->service->forProduct($product->id);
        if (! $spec) return response()->json(['data' => null]);
        return response()->json(['data' => (new InspectionSpecResource($spec))->resolve()]);
    }

    public function destroy(InspectionSpec $inspectionSpec): InspectionSpecResource
    {
        return new InspectionSpecResource($this->service->deactivate($inspectionSpec));
    }
}
