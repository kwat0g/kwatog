<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\KpiSnapshotService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiController
{
    public function __construct(private readonly KpiSnapshotService $service) {}

    public function scorecard(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', (string) Carbon::now()->year);
        $month = (int) $request->query('month', (string) Carbon::now()->month);

        return response()->json(['data' => $this->service->getScorecard($year, $month, $request->user())]);
    }

    public function trend(Request $request, string $code): JsonResponse
    {
        $months = max(1, min(24, (int) $request->query('months', '12')));

        return response()->json(['data' => $this->service->getTrend($code, $months, $request->user())]);
    }

    public function compute(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', (string) Carbon::now()->year);
        $month = (int) $request->input('month', (string) Carbon::now()->subMonth()->month);

        $this->service->computeAll($year, $month);

        return response()->json(['message' => "KPIs computed for $year-$month"]);
    }
}
