<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Controllers;

use Illuminate\Routing\Controller;
use App\Modules\Forecasting\Services\StockOutProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockOutProjectionController extends Controller
{
    public function __construct(private readonly StockOutProjectionService $service) {}

    /**
     * GET /forecasting/stock-out
     * Returns items projected to stock out within the horizon, sorted by risk.
     */
    public function index(Request $request): JsonResponse
    {
        $horizon = (int) $request->query('horizon_days', 60);
        $horizon = max(7, min(180, $horizon));

        return response()->json([
            'data' => $this->service->projectAll($horizon),
            'meta' => [
                'horizon_days' => $horizon,
                'generated_at' => now()->toISOString(),
            ],
        ]);
    }
}
