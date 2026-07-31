<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\WarehouseLocation;
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

    public function binDetail(WarehouseLocation $location): JsonResponse
    {
        $detail = $this->mapService->binDetail((int) $location->getKey());
        if (! $detail) {
            return response()->json(['message' => 'Location not found.'], 404);
        }

        return response()->json(['data' => $detail]);
    }

    /**
     * Generate picking list for a Material Issue Slip.
     */
    public function pickingList(MaterialIssueSlip $materialIssueSlip): JsonResponse
    {
        return response()->json(['data' => $this->pickingService->generateForMis((int) $materialIssueSlip->getKey())]);
    }
}
