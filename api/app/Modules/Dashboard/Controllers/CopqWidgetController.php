<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Quality\Services\CopqService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CopqWidgetController
{
    public function __construct(private readonly CopqService $copq) {}

    public function index(Request $request): JsonResponse
    {
        $months = min($request->integer('months', 6), 12);

        // Current month live computation
        $current = $this->copq->compute(now()->startOfMonth(), now()->endOfMonth());

        // Historical trend from snapshots — query by period_year/period_month.
        // Exclude the current month since it's already returned as live `current`.
        $cutoff = now()->subMonths($months)->startOfMonth();
        $currentYear = (int) now()->year;
        $currentMonth = (int) now()->month;

        $trend = DB::table('copq_snapshots')
            ->where(function ($q) use ($cutoff) {
                $q->where('period_year', '>', $cutoff->year)
                  ->orWhere(function ($q2) use ($cutoff) {
                      $q2->where('period_year', $cutoff->year)
                         ->where('period_month', '>=', $cutoff->month);
                  });
            })
            ->where(function ($q) use ($currentYear, $currentMonth) {
                $q->where('period_year', '<', $currentYear)
                  ->orWhere(function ($q2) use ($currentYear, $currentMonth) {
                      $q2->where('period_year', $currentYear)
                         ->where('period_month', '<', $currentMonth);
                  });
            })
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get()
            ->map(fn ($row) => [
                'month'           => Carbon::create((int) $row->period_year, (int) $row->period_month, 1)->format('M Y'),
                'scrap_cost'      => (float) $row->internal_scrap_cost,
                'rework_cost'     => (float) $row->internal_rework_cost,
                'warranty_cost'   => (float) $row->external_return_cost,
                'inspection_cost' => (float) $row->external_complaint_cost,
                'total'           => (float) $row->total_cost,
            ]);

        return response()->json([
            'data' => [
                'current' => $current,
                'trend'   => $trend,
                'period'  => now()->format('M Y'),
            ],
        ]);
    }
}
