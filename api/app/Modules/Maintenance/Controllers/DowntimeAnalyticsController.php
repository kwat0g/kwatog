<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Controllers;

use App\Common\Services\SettingsService;
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
        private readonly SettingsService $settings,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'days'       => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $defaultDays = $this->settings->requiredInt('maintenance.downtime.default_history_days', 1, 3650);
        $days = (int) $request->input('days', $defaultDays);
        $from = now()->subDays($days);
        $to   = now();

        $data = $this->analytics->summary(
            $request->filled('machine_id') ? (int) $request->input('machine_id') : null,
            $from,
            $to,
        );

        return response()->json(['data' => $data, 'meta' => ['days' => $days, 'default_days' => $defaultDays]]);
    }

    public function policy(): JsonResponse
    {
        return response()->json(['data' => [
            'default_days' => $this->settings->requiredInt('maintenance.downtime.default_history_days', 1, 3650),
            'minimum_days' => 1,
            'maximum_days' => 3650,
            'availability_good_pct' => round($this->settings->requiredFloat('maintenance.downtime.availability_good_ratio', 0, 1) * 100, 1),
            'availability_warning_pct' => round($this->settings->requiredFloat('maintenance.downtime.availability_warning_ratio', 0, 1) * 100, 1),
            'total_warning_minutes' => $this->settings->requiredInt('maintenance.downtime.total_warning_minutes', 1),
            'mtbf_good_hours' => $this->settings->requiredFloat('maintenance.downtime.mtbf_good_hours', 0),
            'mttr_good_minutes' => $this->settings->requiredFloat('maintenance.downtime.mttr_good_minutes', 0),
            'breakdown_warning_count' => $this->settings->requiredInt('maintenance.downtime.breakdown_warning_count', 1),
            'breakdown_critical_count' => $this->settings->requiredInt('maintenance.downtime.breakdown_critical_count', 1),
        ]]);
    }

    public function dailyTrend(Request $request): JsonResponse
    {
        $request->validate([
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'days'       => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $defaultDays = $this->settings->requiredInt('maintenance.downtime.default_history_days', 1, 3650);
        $days = (int) $request->input('days', $defaultDays);
        $data = $this->analytics->dailyTrend(
            $request->filled('machine_id') ? (int) $request->input('machine_id') : null,
            $days
        );

        return response()->json(['data' => $data]);
    }

    public function topMachines(Request $request): JsonResponse
    {
        $request->validate([
            'days'  => ['nullable', 'integer', 'min:1', 'max:3650'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $defaultDays = $this->settings->requiredInt('maintenance.downtime.default_history_days', 1, 3650);
        $days = (int) $request->input('days', $defaultDays);
        $data = $this->analytics->topMachines(
            (int) $request->input('limit', 10),
            $days
        );

        return response()->json(['data' => $data]);
    }

    public function allMachines(Request $request): JsonResponse
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $defaultDays = $this->settings->requiredInt('maintenance.downtime.default_history_days', 1, 3650);
        $data = $this->analytics->allMachinesSummary((int) $request->input('days', $defaultDays));

        return response()->json(['data' => $data]);
    }

    /**
     * L-39 — GET /maintenance/downtime-analytics/pareto
     */
    public function pareto(Request $request): JsonResponse
    {
        $request->validate([
            'machine_id' => ['nullable', 'integer', 'exists:machines,id'],
            'days'       => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $defaultDays = $this->settings->requiredInt('maintenance.downtime.default_history_days', 1, 3650);
        $data = $this->analytics->categoryPareto(
            $request->filled('machine_id') ? (int) $request->input('machine_id') : null,
            (int) $request->input('days', $defaultDays),
        );

        return response()->json(['data' => $data]);
    }
}
