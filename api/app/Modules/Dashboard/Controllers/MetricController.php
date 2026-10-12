<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Requests\ListMetricsRequest;
use App\Modules\Dashboard\Requests\ShowMetricRequest;
use App\Modules\Dashboard\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;

/**
 * Metric dictionary + one-metric read. Deliberately thin: it validates through
 * the FormRequest (which owns the authorization), delegates to the analytics
 * service, and returns the envelope unchanged — no query, no shape decision.
 *
 * `GET /dashboard/metrics` is what a dashboard header reads to label a tile and
 * what an admin UI reads to explain one ("definition, formula, sources,
 * refresh"), so there is no second place for a metric's definition to drift.
 */
class MetricController
{
    public function __construct(
        private readonly AnalyticsService $analytics,
    ) {}

    public function index(ListMetricsRequest $request): JsonResponse
    {
        return response()->json(
            $this->analytics->catalog(
                $request->user(),
                $request->validated('module'),
            )
        );
    }

    public function show(ShowMetricRequest $request, string $metric): JsonResponse
    {
        return response()->json(
            $this->analytics->resolve(
                $metric,
                $request->user(),
                $request->period(),
                $request->filters(),
            )
        );
    }
}
