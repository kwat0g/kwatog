<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Controllers;

use App\Modules\Forecasting\Services\ForecastMrpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Forecast-driven MRP projection (ADV11 → MRP bridge). Advisory material
 * requirement plan derived from demand forecasts.
 */
class ForecastMrpController
{
    public function __construct(private readonly ForecastMrpService $service) {}

    public function project(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year'  => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return response()->json([
            'data' => $this->service->project((int) $data['year'], (int) $data['month']),
        ]);
    }
}
