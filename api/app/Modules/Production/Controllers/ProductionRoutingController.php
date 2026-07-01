<?php

declare(strict_types=1);

namespace App\Modules\Production\Controllers;

use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Requests\StoreRoutingRequest;
use App\Modules\Production\Resources\ProductRoutingResource;
use App\Modules\Production\Services\ProductionRoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductionRoutingController
{
    public function __construct(private readonly ProductionRoutingService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ProductRoutingResource::collection($this->service->list($request->query()));
    }

    public function store(StoreRoutingRequest $request): JsonResponse
    {
        $routing = $this->service->create($request->validated());

        return (new ProductRoutingResource($routing))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ProductRouting $routing): ProductRoutingResource
    {
        return new ProductRoutingResource($this->service->show($routing));
    }

    public function update(StoreRoutingRequest $request, ProductRouting $routing): ProductRoutingResource
    {
        return new ProductRoutingResource(
            $this->service->update($routing, $request->validated()),
        );
    }

    public function duplicate(ProductRouting $routing): JsonResponse
    {
        $newRouting = $this->service->duplicate($routing);

        return (new ProductRoutingResource($newRouting))
            ->response()
            ->setStatusCode(201);
    }
}
