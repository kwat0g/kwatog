<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * COPQ — Cost of Poor Quality breakdown.
 *
 * Aggregates internal failure (scrap + rework) and external failure
 * (returns + complaints) for a given date range.
 *
 * All cost figures are approximations based on average item unit cost
 * and industry-typical rework cost factors.
 */
class CopqService
{
    public function compute(CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDate = $from->toDateString();
        $toDate   = $to->toDateString();

        $scrap = (int) (DB::table('non_conformance_reports')
            ->where('status', 'closed')
            ->where('disposition', 'scrap')
            ->whereBetween('closed_at', [$fromDate, $toDate])
            ->sum('affected_quantity') ?? 0);

        $rework = (int) (DB::table('work_orders')
            ->whereNotNull('parent_ncr_id')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->sum('quantity_target') ?? 0);

        $returns = DB::table('return_requests')
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$fromDate, $toDate])
            ->count();

        $complaints = DB::table('customer_complaints')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->count();

        $avgCost    = (float) (DB::table('items')->avg('standard_cost') ?? 50.0);
        $scrapCost  = $scrap * $avgCost;
        $reworkCost = $rework * $avgCost * 0.3;

        return [
            'internal_failure' => [
                'scrap_units'  => $scrap,
                'rework_units' => $rework,
                'scrap_cost'   => round($scrapCost, 2),
                'rework_cost'  => round($reworkCost, 2),
            ],
            'external_failure' => [
                'returns'     => $returns,
                'complaints'  => $complaints,
                'return_cost' => 0.0,
            ],
            'total'        => round($scrapCost + $reworkCost, 2),
            'period_label' => $from->format('M Y') . ' – ' . $to->format('M Y'),
        ];
    }
}
