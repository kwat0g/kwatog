<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Requests\SaveDashboardLayoutRequest;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use App\Modules\Dashboard\Services\DashboardWidgetDataService;
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
        private readonly DashboardWidgetDataService $widgetData,
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

    public function data(Request $request): JsonResponse
    {
        $requested = $request->validate(['keys' => ['required', 'array', 'max:50'], 'keys.*' => ['string', 'max:100']])['keys'];
        $allowed = collect($this->service->listAvailableWidgets($request->user()))->pluck('key');
        $keys = collect($requested)->intersect($allowed)->values()->all();

        return response()->json(['data' => $this->widgetData->summaries($keys, $request->user())]);
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
