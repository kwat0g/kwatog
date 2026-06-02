<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Resources\WarehouseMapResource;
use App\Modules\Inventory\Services\PickingListService;
use App\Modules\Inventory\Services\WarehouseMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WarehouseMapController
{
    public function __construct(
        private readonly WarehouseMapService $mapService,
        private readonly PickingListService $pickingService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return WarehouseMapResource::collection($this->mapService->map());
    }

    public function binDetail(int $id): JsonResponse
    {
        $detail = $this->mapService->binDetail($id);
        if (!$detail) {
            return response()->json(['message' => 'Location not found.'], 404);
        }
        return response()->json(['data' => $detail]);
    }

    /**
     * Generate picking list for a Material Issue Slip.
     */
    public function pickingList(int $misId): JsonResponse
    {
        return response()->json(['data' => $this->pickingService->generateForMis($misId)]);
    }
}
