<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Controllers;

use App\Common\Services\SettingsService;
use Illuminate\Routing\Controller;
use App\Modules\Forecasting\Services\StockOutProjectionService;
use App\Modules\Forecasting\Enums\StockOutRisk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockOutProjectionController extends Controller
{
    public function __construct(
        private readonly StockOutProjectionService $service,
        private readonly SettingsService $settings,
    ) {}

    /**
     * GET /forecasting/stock-out
     * Returns items projected to stock out within the horizon, sorted by risk.
     */
    public function index(Request $request): JsonResponse
    {
        $default = $this->settings->requiredInt('inventory.stockout.default_horizon_days', 1);
        $minimum = $this->settings->requiredInt('inventory.stockout.minimum_horizon_days', 1);
        $maximum = $this->settings->requiredInt('inventory.stockout.maximum_horizon_days', $minimum);
        $demandHistoryDays = $this->settings->requiredInt('inventory.stockout.demand_history_days', 1);
        $horizon = (int) $request->query('horizon_days', $default);
        $horizon = max($minimum, min($maximum, $horizon));

        return response()->json([
            'data' => $this->service->projectAll($horizon),
            'meta' => [
                'horizon_days' => $horizon,
                'default_horizon_days' => $default,
                'minimum_horizon_days' => $minimum,
                'maximum_horizon_days' => $maximum,
                'demand_history_days' => $demandHistoryDays,
                'generated_at' => now()->toISOString(),
                'risk_options' => array_map(
                    static fn (StockOutRisk $risk): array => ['value' => $risk->value, 'label' => $risk->label()],
                    StockOutRisk::cases(),
                ),
            ],
        ]);
    }
}
