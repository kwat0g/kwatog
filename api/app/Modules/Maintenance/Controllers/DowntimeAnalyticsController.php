<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Controllers;

use App\Modules\Maintenance\Services\DowntimeAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADV8 — Maintenance Automation.
 * Downtime analytics: MTBF, MTTR, breakdown frequency, trends.
 */
class DowntimeAnalyticsController
{
    public function __construct(
        private readonly DowntimeAnalyticsService $analytics,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'days'       => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $from = now()->subDays((int) $request->input('days', 30));
        $to   = now();

        $data = $this->analytics->summary(
            $request->filled('machine_id') ? (int) $request->input('machine_id') : null,
            $from,
            $to,
        );

        return response()->json(['data' => $data]);
    }

    public function dailyTrend(Request $request): JsonResponse
    {
        $request->validate([
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'days'       => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $data = $this->analytics->dailyTrend(
            $request->filled('machine_id') ? (int) $request->input('machine_id') : null,
            (int) $request->input('days', 30)
        );

        return response()->json(['data' => $data]);
    }

    public function topMachines(Request $request): JsonResponse
    {
        $request->validate([
            'days'  => ['nullable', 'integer', 'min:1', 'max:365'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $data = $this->analytics->topMachines(
            (int) $request->input('limit', 10),
            (int) $request->input('days', 30)
        );

        return response()->json(['data' => $data]);
    }

    public function allMachines(Request $request): JsonResponse
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $data = $this->analytics->allMachinesSummary(
            (int) $request->input('days', 30)
        );

        return response()->json(['data' => $data]);
    }
}
