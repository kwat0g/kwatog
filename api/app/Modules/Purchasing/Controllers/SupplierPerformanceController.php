<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Purchasing\Services\SupplierPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Series F — Task F4. Supplier performance dashboard endpoints.
 */
class SupplierPerformanceController
{
    public function __construct(private readonly SupplierPerformanceService $service) {}

    /**
     * GET /api/v1/purchasing/vendors/{vendor}/performance?months=6
     */
    public function show(Request $request, Vendor $vendor): JsonResponse
    {
        $months = (int) max(1, min(24, (int) $request->query('months', 6)));
        $snapshots = $this->service->trendForVendor($vendor, $months);

        $latest = $snapshots->last();

        return response()->json([
            'data' => [
                'vendor' => [
                    'id'   => $vendor->hash_id,
                    'name' => $vendor->name,
                ],
                'latest' => $latest ? [
                    'period_year'             => $latest->period_year,
                    'period_month'            => $latest->period_month,
                    'on_time_delivery_rate'   => $latest->on_time_delivery_rate,
                    'quality_pass_rate'       => $latest->quality_pass_rate,
                    'incoming_quality_rate'   => $latest->incoming_quality_rate,
                    'in_process_quality_rate' => $latest->in_process_quality_rate,
                    'outgoing_quality_rate'   => $latest->outgoing_quality_rate,
                    'ncr_rate'                => $latest->ncr_rate,
                    'price_variance_pct'      => $latest->price_variance_pct,
                    'lead_time_variance_days' => $latest->lead_time_variance_days,
                    'overall_score'           => $latest->overall_score,
                    'po_count'                => $latest->po_count,
                    'grn_count'               => $latest->grn_count,
                    'computed_at'             => $latest->computed_at?->toIso8601String(),
                ] : null,
                'trend' => $snapshots->map(fn ($s) => [
                    'period_year'           => $s->period_year,
                    'period_month'          => $s->period_month,
                    'overall_score'         => $s->overall_score,
                    'on_time_delivery_rate' => $s->on_time_delivery_rate,
                    'quality_pass_rate'     => $s->quality_pass_rate,
                    'incoming_quality_rate' => $s->incoming_quality_rate,
                    'ncr_rate'              => $s->ncr_rate,
                ])->values(),
            ],
        ]);
    }

    /**
     * POST /api/v1/purchasing/vendors/{vendor}/performance/recompute
     * Admin-only: forces a recomputation for the current month.
     */
    public function recompute(Vendor $vendor): JsonResponse
    {
        $now = Carbon::now();
        $snapshot = $this->service->compute($vendor, $now->year, $now->month);

        return response()->json([
            'data' => [
                'vendor_id'    => $vendor->hash_id,
                'period_year'  => $snapshot->period_year,
                'period_month' => $snapshot->period_month,
                'overall_score' => $snapshot->overall_score,
                'computed_at'  => $snapshot->computed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * T3.3.B — GET /api/v1/purchasing/vendors/ranking
     *
     * Cross-vendor ranking for a given period. Defaults to the previous
     * calendar month. Optional ?tier=A|B|C|D filter, ?limit clamped to 100.
     */
    public function ranking(Request $request): JsonResponse
    {
        $now = Carbon::now();
        // Default to the previous calendar month.
        $defaultPeriod = $now->copy()->subMonthNoOverflow();
        $year  = (int) $request->query('period_year',  (string) $defaultPeriod->year);
        $month = (int) $request->query('period_month', (string) $defaultPeriod->month);

        $tier  = $request->query('tier');
        if ($tier !== null) {
            $tier = strtoupper((string) $tier);
            if (! in_array($tier, ['A', 'B', 'C', 'D'], true)) {
                $tier = null;
            }
        }

        $limit = (int) $request->query('limit', '50');

        $rows = $this->service->ranking($year, $month, $tier, $limit);

        return response()->json([
            'data' => $rows->map(fn ($s) => [
                'vendor' => [
                    'id'   => $s->vendor?->hash_id,
                    'name' => $s->vendor?->name,
                ],
                'tier'                  => $s->tier,
                'overall_score'         => $s->overall_score,
                'on_time_delivery_rate' => $s->on_time_delivery_rate,
                'quality_pass_rate'     => $s->quality_pass_rate,
                'ncr_rate'              => $s->ncr_rate,
                'po_count'              => (int) $s->po_count,
                'grn_count'             => (int) $s->grn_count,
                'computed_at'           => $s->computed_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'period_year'  => $year,
                'period_month' => $month,
                'tier'         => $tier,
                'limit'        => max(1, min($limit, 100)),
            ],
        ]);
    }
}
