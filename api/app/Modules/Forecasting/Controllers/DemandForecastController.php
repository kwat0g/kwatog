<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Controllers;

use App\Common\Services\SettingsService;
use Illuminate\Routing\Controller;
use App\Modules\CRM\Models\Product;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Forecasting\Enums\DemandSource;
use App\Modules\Forecasting\Resources\DemandForecastResource;
use App\Modules\Forecasting\Services\ForecastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DemandForecastController extends Controller
{
    public function __construct(
        private readonly ForecastingService $service,
        private readonly SettingsService $settings,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'methods' => array_values(array_filter((array) $this->settings->get('forecasting.methods', []), static fn ($method): bool => is_array($method) && isset($method['value'], $method['label']))),
            'demand_sources' => array_map(
                static fn (DemandSource $source): array => ['value' => $source->value, 'label' => $source->label()],
                DemandSource::cases(),
            ),
            'accuracy_policy' => [
                'excellent_mape' => $this->settings->requiredFloat('forecasting.accuracy.excellent_mape', 0),
                'acceptable_mape' => $this->settings->requiredFloat('forecasting.accuracy.acceptable_mape', 0),
            ],
        ]]);
    }

    /**
     * GET /forecasting/demand-forecasts
     * Filters: product_id, customer_id, year, method.
     */
    public function index(Request $request): JsonResponse
    {
        $q = DemandForecast::query()->with(['product', 'customer', 'creator']);

        if ($pid = $request->query('product_id')) {
            $decoded = Product::tryDecodeHash((string) $pid);
            if ($decoded) $q->where('product_id', $decoded);
        }
        if ($cid = $request->query('customer_id')) {
            $decoded = \App\Modules\Accounting\Models\Customer::tryDecodeHash((string) $cid);
            if ($decoded) $q->where('customer_id', $decoded);
        }
        if ($year = $request->query('year')) {
            $q->where('forecast_year', (int) $year);
        }
        if ($method = $request->query('method')) {
            $q->where('method', $method);
        }

        $perPage = min(max((int) $request->query('per_page', 100), 1), 500);
        $paginated = $q->orderBy('forecast_year')->orderBy('forecast_month')->paginate($perPage);

        return DemandForecastResource::collection($paginated)->response();
    }

    public function settings(): JsonResponse
    {
        $minHorizon = $this->settings->requiredInt('forecasting.minimum_horizon_months', 1, 36);
        $maxHorizon = $this->settings->requiredInt('forecasting.maximum_horizon_months', $minHorizon, 36);
        $minLookback = $this->settings->requiredInt('forecasting.minimum_lookback_months', 1, 36);
        $maxLookback = $this->settings->requiredInt('forecasting.maximum_lookback_months', $minLookback, 60);
        return response()->json(['data' => [
            'default_history_months' => $this->settings->requiredInt('forecasting.default_history_months', 1, 36),
            'default_horizon_months' => $this->settings->requiredInt('forecasting.default_horizon_months', $minHorizon, $maxHorizon),
            'default_lookback_months' => $this->settings->requiredInt('forecasting.default_lookback_months', $minLookback, $maxLookback),
            'minimum_horizon_months' => $minHorizon,
            'maximum_horizon_months' => $maxHorizon,
            'minimum_lookback_months' => $minLookback,
            'maximum_lookback_months' => $maxLookback,
        ]]);
    }

    /**
     * GET /forecasting/demand-forecasts/historical
     * Returns the last N months of confirmed demand for one product/customer.
     */
    public function historical(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id'   => ['required', 'string'],
            'customer_id'  => ['nullable', 'string'],
            'months_back'  => ['nullable', 'integer', 'min:3', 'max:36'],
        ]);

        $productId = Product::tryDecodeHash($data['product_id']);
        abort_unless($productId, 404, 'Product not found');

        $customerId = null;
        if (! empty($data['customer_id'])) {
            $customerId = \App\Modules\Accounting\Models\Customer::tryDecodeHash($data['customer_id']);
        }

        $now    = Carbon::now();
        $months = (int) ($data['months_back'] ?? $this->settings->requiredInt('forecasting.default_history_months', 3, 36));

        $series = $this->service->historicalDemand(
            $productId,
            $customerId,
            $now->year,
            $now->month,
            $months
        );

        return response()->json(['data' => $series]);
    }

    /**
     * POST /forecasting/demand-forecasts/recompute
     * Recompute forecasts for one (product, customer) pair across a horizon.
     */
    public function recompute(Request $request): JsonResponse
    {
        $minHorizon = $this->settings->requiredInt('forecasting.minimum_horizon_months', 1, 36);
        $maxHorizon = $this->settings->requiredInt('forecasting.maximum_horizon_months', $minHorizon, 36);
        $minLookback = $this->settings->requiredInt('forecasting.minimum_lookback_months', 1, 36);
        $maxLookback = $this->settings->requiredInt('forecasting.maximum_lookback_months', $minLookback, 60);
        $data = $request->validate([
            'product_id'      => ['required', 'string'],
            'customer_id'     => ['nullable', 'string'],
            'method'          => ['required', 'in:moving_avg,weighted_avg'],
            'horizon_months'  => ['nullable', 'integer', "min:{$minHorizon}", "max:{$maxHorizon}"],
            'lookback_months' => ['nullable', 'integer', "min:{$minLookback}", "max:{$maxLookback}"],
        ]);

        $productId = Product::tryDecodeHash($data['product_id']);
        abort_unless($productId, 404, 'Product not found');

        $customerId = null;
        if (! empty($data['customer_id'])) {
            $customerId = \App\Modules\Accounting\Models\Customer::tryDecodeHash($data['customer_id']);
        }

        $horizon  = (int) ($data['horizon_months'] ?? $this->settings->requiredInt('forecasting.default_horizon_months', $minHorizon, $maxHorizon));
        $lookback = (int) ($data['lookback_months'] ?? $this->settings->requiredInt('forecasting.default_lookback_months', $minLookback, $maxLookback));

        $start = Carbon::now()->startOfMonth()->addMonthNoOverflow();
        $written = [];
        for ($i = 0; $i < $horizon; $i++) {
            $cursor = $start->copy()->addMonthsNoOverflow($i);
            $f = $this->service->compute(
                $productId,
                $customerId,
                $cursor->year,
                $cursor->month,
                $data['method'],
                $lookback,
                $request->user()
            );
            $written[] = $f;
        }

        $models = collect($written)->map(fn ($f) => $f->load(['product', 'customer']));

        return response()->json([
            'data'    => DemandForecastResource::collection($models),
            'message' => 'Forecasts recomputed.',
        ]);
    }

    /**
     * GET /forecasting/accuracy
     * Returns MAPE, bias, and per-month detail for a given year.
     */
    public function accuracy(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', now()->year);
        return response()->json(['data' => $this->service->accuracy($year)]);
    }

    /**
     * POST /forecasting/demand-forecasts/manual
     * Operator-entered manual override for one period.
     */
    public function storeManual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id'        => ['required', 'string'],
            'customer_id'       => ['nullable', 'string'],
            'forecast_year'     => ['required', 'integer', 'min:2000', 'max:2100'],
            'forecast_month'    => ['required', 'integer', 'min:1', 'max:12'],
            'forecasted_quantity' => ['required', 'numeric', 'min:0'],
            'confidence_level'  => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $productId = Product::tryDecodeHash($data['product_id']);
        abort_unless($productId, 404, 'Product not found');

        $customerId = null;
        if (! empty($data['customer_id'])) {
            $customerId = \App\Modules\Accounting\Models\Customer::tryDecodeHash($data['customer_id']);
        }

        $f = $this->service->storeManual(
            $productId,
            $customerId,
            (int) $data['forecast_year'],
            (int) $data['forecast_month'],
            (float) $data['forecasted_quantity'],
            isset($data['confidence_level']) ? (float) $data['confidence_level'] : null,
            $request->user()
        );

        return response()->json([
            'data'    => new DemandForecastResource($f->load(['product', 'customer', 'creator'])),
            'message' => 'Manual forecast saved.',
        ], 201);
    }
}
