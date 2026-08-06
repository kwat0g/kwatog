<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Requests\StoreWarehouseLocationRequest;
use App\Modules\Inventory\Requests\StoreWarehouseRequest;
use App\Modules\Inventory\Requests\StoreWarehouseZoneRequest;
use App\Modules\Inventory\Resources\WarehouseLocationResource;
use App\Modules\Inventory\Resources\WarehouseResource;
use App\Modules\Inventory\Resources\WarehouseZoneResource;
use App\Modules\Inventory\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WarehouseController
{
    public function __construct(private readonly WarehouseService $service) {}

    public function tree(): AnonymousResourceCollection
    {
        return WarehouseResource::collection($this->service->tree());
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'zone_types' => array_map(static fn (WarehouseZoneType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ], WarehouseZoneType::cases()),
        ]]);
    }

    public function indexWarehouses(): AnonymousResourceCollection
    {
        return WarehouseResource::collection($this->service->listWarehouses());
    }

    public function storeWarehouse(StoreWarehouseRequest $request): JsonResponse
    {
        $w = $this->service->createWarehouse($request->validated());
        return (new WarehouseResource($w))->response()->setStatusCode(201);
    }

    public function updateWarehouse(StoreWarehouseRequest $request, Warehouse $warehouse): WarehouseResource
    {
        return new WarehouseResource($this->service->updateWarehouse($warehouse, $request->validated()));
    }

    public function destroyWarehouse(Warehouse $warehouse): JsonResponse
    {
        try { $this->service->deleteWarehouse($warehouse); }
        catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(null, 204);
    }

    public function restoreWarehouse(Warehouse $warehouse): JsonResponse
    {
        $warehouse->restore();
        return response()->json(['message' => 'Warehouse restored.']);
    }

    public function storeZone(StoreWarehouseZoneRequest $request): JsonResponse
    {
        $z = $this->service->createZone($request->validated());
        return (new WarehouseZoneResource($z))->response()->setStatusCode(201);
    }

    public function updateZone(StoreWarehouseZoneRequest $request, WarehouseZone $zone): WarehouseZoneResource
    {
        return new WarehouseZoneResource($this->service->updateZone($zone, $request->validated()));
    }

    public function destroyZone(WarehouseZone $zone): JsonResponse
    {
        try { $this->service->deleteZone($zone); }
        catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(null, 204);
    }

    public function restoreZone(WarehouseZone $zone): JsonResponse
    {
        $zone->restore();
        return response()->json(['message' => 'Warehouse zone restored.']);
    }

    public function storeLocation(StoreWarehouseLocationRequest $request): JsonResponse
    {
        $l = $this->service->createLocation($request->validated());
        return (new WarehouseLocationResource($l->load('zone.warehouse')))->response()->setStatusCode(201);
    }

    public function updateLocation(StoreWarehouseLocationRequest $request, WarehouseLocation $location): WarehouseLocationResource
    {
        return new WarehouseLocationResource(
            $this->service->updateLocation($location, $request->validated())->load('zone.warehouse')
        );
    }

    public function destroyLocation(WarehouseLocation $location): JsonResponse
    {
        try { $this->service->deleteLocation($location); }
        catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(null, 204);
    }

    public function restoreLocation(WarehouseLocation $location): JsonResponse
    {
        $location->restore();
        return response()->json(['message' => 'Warehouse location restored.']);
    }
}
