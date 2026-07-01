<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Common\Concerns\ResolvesHashIds;
use App\Modules\Quality\Enums\SpcChartStatus;
use App\Modules\Quality\Enums\SpcChartType;
use App\Modules\Quality\Models\SpcAlert;
use App\Modules\Quality\Models\SpcControlChart;
use App\Modules\Quality\Requests\StoreSpcChartRequest;
use App\Modules\Quality\Resources\SpcAlertResource;
use App\Modules\Quality\Resources\SpcControlChartResource;
use App\Modules\Quality\Resources\SpcDataPointResource;
use App\Modules\Quality\Services\SpcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SpcController
{
    public function __construct(private readonly SpcService $service) {}

    /**
     * List SPC control charts with optional filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SpcControlChart::query()
            ->with(['product', 'specItem'])
            ->withCount('unresolvedAlerts');

        if ($request->filled('product_id')) {
            $decoded = app('hashids')->decode($request->query('product_id'));
            if (!empty($decoded)) {
                $query->where('product_id', $decoded[0]);
            }
        }

        if ($request->filled('status')) {
            $status = SpcChartStatus::tryFrom($request->query('status'));
            if ($status) {
                $query->where('status', $status);
            }
        }

        $charts = $query->orderByDesc('created_at')->paginate(
            (int) $request->query('per_page', 15)
        );

        return SpcControlChartResource::collection($charts);
    }

    /**
     * Create a new SPC control chart.
     */
    public function store(StoreSpcChartRequest $request): SpcControlChartResource
    {
        $data = $request->validated();

        $chart = $this->service->createChart(
            productId: (int) $data['product_id'],
            specItemId: (int) $data['spec_item_id'],
            type: SpcChartType::from($data['chart_type']),
            subgroupSize: (int) ($data['subgroup_size'] ?? 5),
        );

        $chart->load(['product', 'specItem']);

        return new SpcControlChartResource($chart);
    }

    /**
     * Show a single chart with recent data points.
     */
    public function show(SpcControlChart $chart): SpcControlChartResource
    {
        $chart->load(['product', 'specItem']);
        $chart->loadCount('unresolvedAlerts');

        // Eager-load most recent 50 data points for charting
        $chart->setRelation(
            'dataPoints',
            $chart->dataPoints()->orderBy('subgroup_number')->limit(50)->get()
        );

        return new SpcControlChartResource($chart);
    }

    /**
     * Paginated data points for a chart.
     */
    public function data(Request $request, SpcControlChart $chart): AnonymousResourceCollection
    {
        $points = $chart->dataPoints()
            ->orderByDesc('subgroup_number')
            ->paginate((int) $request->query('per_page', 50));

        return SpcDataPointResource::collection($points);
    }

    /**
     * Force recalculation of control limits.
     */
    public function recalculate(SpcControlChart $chart): SpcControlChartResource
    {
        $this->service->recalculateLimits($chart);

        $chart->refresh();
        $chart->load(['product', 'specItem']);
        $chart->loadCount('unresolvedAlerts');

        return new SpcControlChartResource($chart);
    }

    /**
     * Run a capability study (Cp/Cpk) for a product + spec item.
     */
    public function capability(Request $request): JsonResponse
    {
        $request->validate([
            'product_id'   => ['required', 'string'],
            'spec_item_id' => ['required', 'string'],
        ]);

        $productDecoded = app('hashids')->decode($request->input('product_id'));
        $specItemDecoded = app('hashids')->decode($request->input('spec_item_id'));

        if (empty($productDecoded) || empty($specItemDecoded)) {
            return response()->json(['message' => 'Invalid product or spec item ID.'], 422);
        }

        $result = $this->service->computeCapabilityStudy(
            productId: $productDecoded[0],
            specItemId: $specItemDecoded[0],
        );

        if ($result === null) {
            return response()->json([
                'message' => 'Insufficient data or missing bilateral spec limits for capability study.',
            ], 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * List unresolved SPC alerts.
     */
    public function alerts(Request $request): AnonymousResourceCollection
    {
        $query = SpcAlert::query()
            ->whereNull('resolved_at')
            ->with(['controlChart.product', 'dataPoint', 'acknowledgedByUser'])
            ->orderByDesc('created_at');

        if ($request->filled('chart_id')) {
            $decoded = app('hashids')->decode($request->query('chart_id'));
            if (!empty($decoded)) {
                $query->where('control_chart_id', $decoded[0]);
            }
        }

        $alerts = $query->paginate((int) $request->query('per_page', 25));

        return SpcAlertResource::collection($alerts);
    }

    /**
     * Acknowledge an SPC alert.
     */
    public function acknowledgeAlert(Request $request, SpcAlert $alert): SpcAlertResource
    {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $alert->update([
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
            'resolved_at'     => now(),
            'notes'           => $request->input('notes'),
        ]);

        $alert->load(['controlChart', 'dataPoint', 'acknowledgedByUser']);

        return new SpcAlertResource($alert);
    }
}
