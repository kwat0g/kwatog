<?php

declare(strict_types=1);

namespace App\Modules\Production\Controllers;

use App\Modules\Production\Services\ProductionDashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController
{
    public function __construct(private readonly ProductionDashboardService $service) {}

    /** GET /production/dashboard */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->payload()]);
    }
}
