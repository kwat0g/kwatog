<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Requests\SaveDashboardLayoutRequest;
use App\Modules\Dashboard\Services\DashboardDispatchService;
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
 *   GET    /api/v1/dashboard/dispatch  — where this user's /dashboard lands
 *   PUT    /api/v1/dashboard/layout    — replace user layout
 *   POST   /api/v1/dashboard/layout/reset — delete user rows (fall back to role)
 */
class DashboardLayoutController
{
    public function __construct(
        private readonly DashboardLayoutService $service,
        private readonly DashboardWidgetDataService $widgetData,
        private readonly DashboardDispatchService $dispatch,
    ) {}

    /**
     * Resolves the landing dashboard from the caller's permissions. The SPA
     * redirects `/dashboard` to `target.path`; `candidates` is returned so
     * the UI can offer the other dashboards a user qualifies for without
     * knowing any role names.
     */
    public function dispatch(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'target'     => $this->dispatch->resolve($user),
                'candidates' => $this->dispatch->qualifying($user),
            ],
        ]);
    }

    public function widgets(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->listAvailableWidgets($request->user()),
        ]);
    }

    /**
     * `?rich=1` attaches each widget's rich payload (breakdown / trend /
     * table / gauge). Without the flag the response is what it always was,
     * plus `render_kind` — so an old client keeps working.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->boolean('rich')
                ? $this->service->getRichLayout($request->user())
                : $this->service->getEffectiveLayout($request->user()),
            'meta' => ['layout_version' => $this->service->userLayoutVersion($request->user())],
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
        $this->service->saveUserLayout(
            $request->user(),
            $request->validated('widgets'),
            $request->validated('layout_version'),
        );

        return response()->json([
            'data' => $this->service->getEffectiveLayout($request->user()),
            'meta' => ['layout_version' => $this->service->userLayoutVersion($request->user())],
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate(['layout_version' => ['required', 'string', 'size:64']]);
        $this->service->resetUserLayout($request->user(), $validated['layout_version']);

        return response()->json([
            'data' => $this->service->getEffectiveLayout($request->user()),
            'meta' => ['layout_version' => $this->service->userLayoutVersion($request->user())],
        ]);
    }
}
