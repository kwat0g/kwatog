<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Requests\SaveDashboardLayoutRequest;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Series R — Task R4.
 *
 * Endpoints:
 *   GET    /api/v1/dashboard/widgets   — catalog filtered by permission
 *   GET    /api/v1/dashboard/layout    — effective layout (user → role → empty)
 *   PUT    /api/v1/dashboard/layout    — replace user layout
 *   POST   /api/v1/dashboard/layout/reset — delete user rows (fall back to role)
 */
class DashboardLayoutController
{
    public function __construct(
        private readonly DashboardLayoutService $service,
    ) {}

    public function widgets(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->listAvailableWidgets($request->user()),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->getEffectiveLayout($request->user()),
        ]);
    }

    public function save(SaveDashboardLayoutRequest $request): JsonResponse
    {
        $this->service->saveUserLayout($request->user(), $request->validated('widgets'));

        return response()->json([
            'data' => $this->service->getEffectiveLayout($request->user()),
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $this->service->resetUserLayout($request->user());

        return response()->json([
            'data' => $this->service->getEffectiveLayout($request->user()),
        ]);
    }
}
