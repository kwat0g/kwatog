<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Models\CopqSnapshot;
use App\Modules\Quality\Services\CopqService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CopqController
{
    public function __construct(private readonly CopqService $service) {}

    public function trend(Request $request): JsonResponse
    {
        $requested = (int) $request->query('months', 12);
        $months    = max(1, min(36, $requested));

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

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->service->getSummary()]);
    }

    public function byProduct(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->query('from', Carbon::now()->startOfYear()->toDateString()));
        $to = Carbon::parse($request->query('to', Carbon::now()->endOfMonth()->toDateString()));
        $limit = max(1, min(50, (int) $request->query('limit', 20)));

        return response()->json(['data' => $this->service->getByProduct($from, $to, $limit)]);
    }

    public function bySupplier(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->query('from', Carbon::now()->startOfYear()->toDateString()));
        $to = Carbon::parse($request->query('to', Carbon::now()->endOfMonth()->toDateString()));
        $limit = max(1, min(50, (int) $request->query('limit', 20)));

        return response()->json(['data' => $this->service->getBySupplier($from, $to, $limit)]);
    }
}
