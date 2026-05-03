<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\RoleDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sprint 8 — Tasks 72 + 73. */
class DashboardController
{
    public function __construct(private readonly RoleDashboardService $service) {}

    public function plantManager(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->plantManager($request->user())]);
    }

    public function hr(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->hr($request->user())]);
    }

    public function ppc(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->ppc($request->user())]);
    }

    public function accounting(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->accounting($request->user())]);
    }

    public function employee(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->employee($request->user())]);
    }
}
