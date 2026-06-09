<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Controllers;

use Illuminate\Routing\Controller;
use App\Modules\CRM\Models\Product;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Forecasting\Resources\DemandForecastResource;
use App\Modules\Forecasting\Services\ForecastingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DemandForecastController extends Controller
{
    public function __construct(private readonly ForecastingService $service) {}

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
        $months = (int) ($data['months_back'] ?: 12);

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
        $data = $request->validate([
            'product_id'      => ['required', 'string'],
            'customer_id'     => ['nullable', 'string'],
            'method'          => ['required', 'in:moving_avg,weighted_avg'],
            'horizon_months'  => ['nullable', 'integer', 'min:1', 'max:12'],
            'lookback_months' => ['nullable', 'integer', 'min:3', 'max:24'],
        ]);

        $productId = Product::tryDecodeHash($data['product_id']);
        abort_unless($productId, 404, 'Product not found');

        $customerId = null;
        if (! empty($data['customer_id'])) {
            $customerId = \App\Modules\Accounting\Models\Customer::tryDecodeHash($data['customer_id']);
        }

        $horizon  = $data['horizon_months']  ?? 3;
        $lookback = $data['lookback_months'] ?? 6;

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
