<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Models\CopqSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * T3.6.B — Cost-of-Poor-Quality trend endpoint.
 *
 * Returns the most recent N persisted COPQ snapshots in ascending
 * (period_year, period_month) order so dashboards chart left-to-right.
 * `?months=` is clamped to [1, 36]; default is 12.
 */
class CopqController
{
    public function trend(Request $request): JsonResponse
    {
        $requested = (int) $request->query('months', 12);
        $months    = max(1, min(36, $requested));

        // Take the most recent N rows (DESC), then re-order ASC for charting.
        $rows = CopqSnapshot::query()
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->limit($months)
            ->get()
            ->sortBy(fn ($r) => sprintf('%04d-%02d', $r->period_year, $r->period_month))
            ->values();

        return response()->json([
            'data' => $rows->map(fn (CopqSnapshot $r) => [
                'id'                       => $r->hash_id,
                'period_label'             => sprintf('%04d-%02d', $r->period_year, $r->period_month),
                'period_year'              => $r->period_year,
                'period_month'             => $r->period_month,
                'prevention_cost'          => $r->prevention_cost,
                'appraisal_cost'           => $r->appraisal_cost,
                'internal_scrap_cost'      => $r->internal_scrap_cost,
                'internal_rework_cost'     => $r->internal_rework_cost,
                'external_return_cost'     => $r->external_return_cost,
                'external_complaint_cost'  => $r->external_complaint_cost,
                'total_cost'               => $r->total_cost,
                'computed_at'              => optional($r->computed_at)->toIso8601String(),
            ])->values(),
        ]);
    }
}
