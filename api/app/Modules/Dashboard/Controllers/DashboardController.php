<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\HrDashboardService;
use App\Modules\Dashboard\Services\PlantManagerDashboardService;
use App\Modules\Dashboard\Services\PpcDashboardService;
use App\Modules\Dashboard\Services\PurchasingDashboardService;
use App\Modules\Dashboard\Services\QualityDashboardService;
use App\Modules\Dashboard\Services\WarehouseDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sprint 8 — Tasks 72 + 73.
 * P4.1: Each action injects only the service it needs.
 */
class DashboardController
{
    public function __construct(
        private readonly PlantManagerDashboardService $plantManagerService,
        private readonly HrDashboardService           $hrService,
        private readonly PpcDashboardService          $ppcService,
        private readonly PurchasingDashboardService   $purchasingService,
        private readonly WarehouseDashboardService    $warehouseService,
        private readonly QualityDashboardService      $qualityService,
    ) {}

    public function plantManager(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->plantManagerService->plantManager($request->user(), (string) $request->query('range', 'week'))]);
    }

    public function hr(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->hrService->hr($request->user())]);
    }

    public function ppc(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ppcService->ppc($request->user())]);
    }

    public function accounting(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->ppcService->accounting($request->user())]);
    }

    public function employee(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->hrService->employee($request->user())]);
    }

    public function purchasing(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->purchasingService->purchasing($request->user())]);
    }

    public function warehouse(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->warehouseService->warehouse($request->user())]);
    }

    public function quality(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->qualityService->quality($request->user())]);
    }
}
