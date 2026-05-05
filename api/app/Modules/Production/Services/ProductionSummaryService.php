<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Task A10 — End-of-day / weekly production summary collector.
 *
 * forDate() returns the figures used by both the daily email and the
 * Plant Manager dashboard. Numbers are intentionally simple aggregations
 * straight from the canonical tables — no caching, no derived KPIs that
 * depend on uncertain running hours.
 */
class ProductionSummaryService
{
    /** @return array<string, mixed> */
    public function forDate(Carbon $date): array
    {
        $day = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $woRows = DB::table('work_orders as w')
            ->leftJoin('products as p', 'p.id', '=', 'w.product_id')
            ->leftJoin('work_order_outputs as wo', 'wo.work_order_id', '=', 'w.id')
            ->whereBetween('w.planned_start', [$day, $end])
            ->orWhereBetween('w.planned_end', [$day, $end])
            ->orWhereBetween('wo.recorded_at', [$day, $end])
            ->groupBy('w.id', 'w.wo_number', 'p.name', 'w.quantity_target', 'w.status')
            ->select(
                'w.id',
                'w.wo_number',
                'p.name as product_name',
                'w.quantity_target',
                'w.status',
                DB::raw('COALESCE(SUM(wo.good_count), 0) as good'),
                DB::raw('COALESCE(SUM(wo.reject_count), 0) as reject'),
            )
            ->orderBy('w.wo_number')
            ->get();

        $totalGood   = (int) $woRows->sum('good');
        $totalReject = (int) $woRows->sum('reject');
        $totalUnits  = $totalGood + $totalReject;
        $scrapRate   = $totalUnits > 0 ? round($totalReject / $totalUnits * 100, 2) : 0.0;

        $breakdowns = DB::table('machine_downtimes as md')
            ->join('machines as m', 'm.id', '=', 'md.machine_id')
            ->whereBetween('md.start_time', [$day, $end])
            ->orWhereNull('md.end_time')
            ->where('md.category', 'breakdown')
            ->select(
                'md.id',
                'm.machine_code',
                'm.name',
                'md.start_time',
                'md.end_time',
                'md.duration_minutes',
                'md.description',
            )
            ->get();

        $defects = DB::table('work_order_defects as d')
            ->join('defect_types as dt', 'dt.id', '=', 'd.defect_type_id')
            ->join('work_order_outputs as wo', 'wo.id', '=', 'd.output_id')
            ->whereBetween('wo.recorded_at', [$day, $end])
            ->groupBy('dt.code', 'dt.name')
            ->select(
                'dt.code',
                'dt.name',
                DB::raw('SUM(d.count) as count'),
            )
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $qc = DB::table('inspections')
            ->whereBetween('completed_at', [$day, $end])
            ->select('status', DB::raw('COUNT(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();

        return [
            'date'         => $day->toDateString(),
            'wos'          => $woRows->map(fn ($r) => [
                'wo_number'        => $r->wo_number,
                'product_name'     => $r->product_name,
                'quantity_target'  => (int) $r->quantity_target,
                'status'           => $r->status,
                'good'             => (int) $r->good,
                'reject'           => (int) $r->reject,
                'variance'         => (int) ((int) $r->good - (int) $r->quantity_target),
            ])->all(),
            'totals'       => [
                'good'        => $totalGood,
                'reject'      => $totalReject,
                'total_units' => $totalUnits,
                'scrap_rate'  => $scrapRate,
            ],
            'breakdowns'   => $breakdowns->map(fn ($b) => [
                'machine_code'   => $b->machine_code,
                'machine_name'   => $b->name,
                'start_time'     => (string) $b->start_time,
                'end_time'       => $b->end_time ? (string) $b->end_time : 'ongoing',
                'duration_min'   => (int) ($b->duration_minutes ?? 0),
                'description'    => $b->description,
            ])->all(),
            'defects'      => $defects->map(fn ($d) => [
                'code'  => $d->code,
                'name'  => $d->name,
                'count' => (int) $d->count,
            ])->all(),
            'qc'           => [
                'passed' => (int) ($qc['passed'] ?? 0),
                'failed' => (int) ($qc['failed'] ?? 0),
                'total'  => array_sum(array_map('intval', $qc)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function forWeek(Carbon $weekEnd): array
    {
        $rangeStart = $weekEnd->copy()->subDays(6)->startOfDay();
        $end        = $weekEnd->copy()->endOfDay();

        $totals = DB::table('work_order_outputs')
            ->whereBetween('recorded_at', [$rangeStart, $end])
            ->selectRaw('COALESCE(SUM(good_count),0) as good, COALESCE(SUM(reject_count),0) as reject')
            ->first();
        $good   = (int) ($totals->good ?? 0);
        $reject = (int) ($totals->reject ?? 0);

        $prevStart = $rangeStart->copy()->subDays(7);
        $prevEnd   = $end->copy()->subDays(7);
        $prevTotals = DB::table('work_order_outputs')
            ->whereBetween('recorded_at', [$prevStart, $prevEnd])
            ->selectRaw('COALESCE(SUM(good_count),0) as good')
            ->first();
        $prev = (int) ($prevTotals->good ?? 0);

        $delta = $prev > 0 ? round(($good - $prev) / $prev * 100, 2) : null;

        return [
            'range_start'   => $rangeStart->toDateString(),
            'range_end'     => $end->toDateString(),
            'good'          => $good,
            'reject'        => $reject,
            'wow_delta_pct' => $delta,
        ];
    }
}
