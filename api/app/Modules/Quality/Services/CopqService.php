<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Modules\Quality\Events\CopqSnapshotComputed;
use App\Modules\Quality\Models\CopqSnapshot;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COPQ — Cost of Poor Quality breakdown.
 *
 * Aggregates internal failure (scrap + rework) and external failure
 * (returns + complaints) for a given date range.
 *
 * Full calendar-month windows route through snapshot() which persists a
 * canonical row using per-product cost via DB-level JOIN. Non-aligned
 * windows fall through to computeAdHoc() (legacy AVG(items.standard_cost)
 * placeholder) so in-progress NCRs in arbitrary ranges still surface live.
 */
class CopqService
{
    public function compute(CarbonInterface $from, CarbonInterface $to): array
    {
        if ($this->isFullCalendarMonth($from, $to)) {
            $snap = $this->snapshot($from->year, $from->month);

            // Legacy contract: same array shape the dashboard + CopqServiceTest expect.
            $b = $snap->breakdown ?? [];
            return [
                'internal_failure' => $b['internal_failure'] ?? [
                    'scrap_units' => 0, 'rework_units' => 0,
                    'scrap_cost' => 0.0, 'rework_cost' => 0.0,
                ],
                'external_failure' => $b['external_failure'] ?? [
                    'returns' => 0, 'complaints' => 0, 'return_cost' => 0.0,
                ],
                'total'        => (float) ($b['total'] ?? 0),
                'period_label' => $from->format('M Y') . ' – ' . $to->format('M Y'),
            ];
        }

        return $this->computeAdHoc($from, $to);
    }

    /**
     * Persist (updateOrCreate) the COPQ snapshot for the given calendar month.
     *
     * Per-item costs come from JOINing NCR / WO -> products.standard_cost.
     * NCRs without product_id are dropped by the INNER JOIN (no cost without
     * product) — this is intentional. Unit counts stay JOIN-free so the
     * `scrap_units` figure matches the legacy CopqService contract.
     */
    public function snapshot(int $year, int $month): CopqSnapshot
    {
        $from   = Carbon::create($year, $month, 1)->startOfDay();
        $to     = $from->copy()->endOfMonth();
        $fromTs = $from->toDateTimeString();
        $toTs   = $to->toDateTimeString();

        return DB::transaction(function () use ($year, $month, $from, $fromTs, $toTs) {

            // --- Internal failure: scrap cost (product-cost JOIN) ---
            $scrapCost = (float) (DB::table('non_conformance_reports as n')
                ->join('products as p', 'n.product_id', '=', 'p.id')
                ->where('n.status', 'closed')
                ->where('n.disposition', 'scrap')
                ->whereBetween('n.closed_at', [$fromTs, $toTs])
                ->sum(DB::raw('n.affected_quantity * p.standard_cost')) ?? 0);

            // Keep unit counts JOIN-free for legacy `scrap_units` semantic.
            $scrapUnits = (int) (DB::table('non_conformance_reports')
                ->where('status', 'closed')
                ->where('disposition', 'scrap')
                ->whereBetween('closed_at', [$fromTs, $toTs])
                ->sum('affected_quantity') ?? 0);

            // --- Internal failure: rework cost (WO -> parent NCR -> product) ---
            $reworkCost = (float) (DB::table('work_orders as w')
                ->join('non_conformance_reports as n', 'w.parent_ncr_id', '=', 'n.id')
                ->join('products as p', 'n.product_id', '=', 'p.id')
                ->whereNotNull('w.parent_ncr_id')
                ->whereBetween('w.created_at', [$fromTs, $toTs])
                ->sum(DB::raw('w.quantity_target * p.standard_cost * 0.30')) ?? 0);

            $reworkUnits = (int) (DB::table('work_orders')
                ->whereNotNull('parent_ncr_id')
                ->whereBetween('created_at', [$fromTs, $toTs])
                ->sum('quantity_target') ?? 0);

            // --- External failure (counts only, costs stay 0 for now) ---
            $returns = Schema::hasTable('return_requests')
                ? (int) DB::table('return_requests')
                    ->where('status', 'completed')
                    ->whereBetween('updated_at', [$fromTs, $toTs])
                    ->count()
                : 0;

            $complaints = Schema::hasTable('customer_complaints')
                ? (int) DB::table('customer_complaints')
                    ->whereBetween('created_at', [$fromTs, $toTs])
                    ->count()
                : 0;

            $internalScrap  = round($scrapCost, 2);
            $internalRework = round($reworkCost, 2);
            $externalReturn = 0.00;       // no per-item cost yet
            $externalComp   = 0.00;       // no per-item cost yet
            $total = round($internalScrap + $internalRework + $externalReturn + $externalComp, 2);

            $breakdown = [
                'internal_failure' => [
                    'scrap_units'  => $scrapUnits,
                    'rework_units' => $reworkUnits,
                    'scrap_cost'   => $internalScrap,
                    'rework_cost'  => $internalRework,
                ],
                'external_failure' => [
                    'returns'      => $returns,
                    'complaints'   => $complaints,
                    'return_cost'  => $externalReturn,
                ],
                'total'        => $total,
                'period_label' => $from->format('M Y'),
            ];

            $snap = CopqSnapshot::updateOrCreate(
                ['period_year' => $year, 'period_month' => $month],
                [
                    'prevention_cost'         => 0.00,
                    'appraisal_cost'          => 0.00,
                    'internal_scrap_cost'     => $internalScrap,
                    'internal_rework_cost'    => $internalRework,
                    'external_return_cost'    => $externalReturn,
                    'external_complaint_cost' => $externalComp,
                    'total_cost'              => $total,
                    'breakdown'               => $breakdown,
                    'computed_at'             => now(),
                ],
            );

            event(new CopqSnapshotComputed($snap));

            return $snap;
        });
    }

    private function isFullCalendarMonth(CarbonInterface $from, CarbonInterface $to): bool
    {
        return $from->year === $to->year
            && $from->month === $to->month
            && $from->equalTo($from->copy()->startOfMonth())
            && $to->equalTo($from->copy()->endOfMonth());
    }

    public function getSummary(): array
    {
        $now = Carbon::now();
        $currentMonth = $this->compute(
            $now->copy()->startOfMonth(),
            $now->copy()->endOfMonth()
        );

        $ytdSnapshots = CopqSnapshot::where('period_year', $now->year)
            ->where('period_month', '<', $now->month)
            ->get();

        $ytdTotal = $ytdSnapshots->sum('total_cost') + ($currentMonth['total'] ?? 0);
        $ytdScrap = $ytdSnapshots->sum('internal_scrap_cost') + ($currentMonth['internal_failure']['scrap_cost'] ?? 0);
        $ytdRework = $ytdSnapshots->sum('internal_rework_cost') + ($currentMonth['internal_failure']['rework_cost'] ?? 0);

        return [
            'current_month' => $currentMonth,
            'ytd' => [
                'total' => round((float) $ytdTotal, 2),
                'scrap_cost' => round((float) $ytdScrap, 2),
                'rework_cost' => round((float) $ytdRework, 2),
                'months_covered' => $ytdSnapshots->count() + 1,
            ],
        ];
    }

    public function getByProduct(CarbonInterface $from, CarbonInterface $to, int $limit = 20): array
    {
        $fromTs = $from->toDateTimeString();
        $toTs = $to->toDateTimeString();

        $rows = DB::table('non_conformance_reports as n')
            ->join('products as p', 'n.product_id', '=', 'p.id')
            ->where('n.status', 'closed')
            ->whereBetween('n.closed_at', [$fromTs, $toTs])
            ->select([
                'p.id as product_id',
                'p.name as product_name',
                'p.part_number',
                DB::raw('COUNT(n.id) as ncr_count'),
                DB::raw("SUM(CASE WHEN n.disposition = 'scrap' THEN n.affected_quantity * p.standard_cost ELSE 0 END) as scrap_cost"),
                DB::raw("SUM(CASE WHEN n.disposition = 'rework' THEN n.affected_quantity * p.standard_cost * 0.30 ELSE 0 END) as rework_cost"),
                DB::raw('SUM(n.affected_quantity * p.standard_cost) as total_cost'),
            ])
            ->groupBy('p.id', 'p.name', 'p.part_number')
            ->orderByDesc('total_cost')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'product_id' => $r->product_id,
            'product_name' => $r->product_name,
            'part_number' => $r->part_number,
            'ncr_count' => (int) $r->ncr_count,
            'scrap_cost' => round((float) $r->scrap_cost, 2),
            'rework_cost' => round((float) $r->rework_cost, 2),
            'total_cost' => round((float) $r->total_cost, 2),
        ])->toArray();
    }

    public function getBySupplier(CarbonInterface $from, CarbonInterface $to, int $limit = 20): array
    {
        $fromTs = $from->toDateTimeString();
        $toTs = $to->toDateTimeString();

        if (!Schema::hasTable('vendors')) {
            return [];
        }

        $rows = DB::table('non_conformance_reports as n')
            ->join('vendors as v', 'n.vendor_id', '=', 'v.id')
            ->where('n.status', 'closed')
            ->where('n.source', 'inspection_fail')
            ->whereBetween('n.closed_at', [$fromTs, $toTs])
            ->select([
                'v.id as vendor_id',
                'v.name as vendor_name',
                DB::raw('COUNT(n.id) as ncr_count'),
                DB::raw('SUM(n.affected_quantity) as total_defective_qty'),
            ])
            ->groupBy('v.id', 'v.name')
            ->orderByDesc('ncr_count')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'vendor_id' => $r->vendor_id,
            'vendor_name' => $r->vendor_name,
            'ncr_count' => (int) $r->ncr_count,
            'total_defective_qty' => (int) $r->total_defective_qty,
        ])->toArray();
    }

    /**
     * Preserved legacy ad-hoc path (avg(items.standard_cost) fallback) for
     * non-month-aligned ranges. Dashboards never hit this in practice.
     */
    private function computeAdHoc(CarbonInterface $from, CarbonInterface $to): array
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
