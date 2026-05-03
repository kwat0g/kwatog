<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 7 — Task 63. Defect Pareto analytics.
 *
 * Aggregates inspection_measurements rows with is_pass=false over a date
 * window, grouped by parameter_name. Returned shape feeds the dashboard
 * bar chart in Task 64 directly.
 */
class DefectParetoService
{
    /**
     * @param array{
     *   from?: string|null,
     *   to?: string|null,
     *   product_id?: int|null,
     *   stage?: string|null,
     *   limit?: int|null
     * } $filters
     *
     * @return array{
     *   from: string,
     *   to: string,
     *   total_defects: int,
     *   rows: array<int, array{
     *     parameter_name: string,
     *     defect_count: int,
     *     percentage: float,
     *     cumulative_percentage: float,
     *     is_critical: bool
     *   }>
     * }
     */
    public function run(array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(30)->startOfDay();
        $to   = isset($filters['to'])   ? Carbon::parse($filters['to'])->endOfDay()     : now()->endOfDay();
        $limit = (int) ($filters['limit'] ?? 10);

        $q = DB::table('inspection_measurements as m')
            ->join('inspections as i', 'i.id', '=', 'm.inspection_id')
            ->where('m.is_pass', false)
            ->whereBetween('i.completed_at', [$from, $to]);

        if (! empty($filters['product_id'])) {
            $q->where('i.product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['stage'])) {
            $q->where('i.stage', (string) $filters['stage']);
        }

        $rows = (clone $q)
            ->selectRaw('m.parameter_name, MAX(m.is_critical) as is_critical, COUNT(*) as defect_count')
            ->groupBy('m.parameter_name')
            ->orderByDesc('defect_count')
            ->limit(max(1, $limit))
            ->get();

        $total = (int) (clone $q)->count();

        $cum = 0;
        $out = [];
        foreach ($rows as $row) {
            $count = (int) $row->defect_count;
            $pct   = $total > 0 ? round(($count / $total) * 100, 2) : 0.0;
            $cum  += $pct;
            $out[] = [
                'parameter_name'        => (string) $row->parameter_name,
                'defect_count'          => $count,
                'percentage'            => $pct,
                'cumulative_percentage' => round($cum, 2),
                'is_critical'           => (bool) $row->is_critical,
            ];
        }

        return [
            'from'           => $from->toDateString(),
            'to'             => $to->toDateString(),
            'total_defects'  => $total,
            'rows'           => $out,
        ];
    }

    /**
     * Drill-down: list inspections that contain a given parameter defect.
     *
     * @return array<int, array{
     *   id: string, inspection_number: string, stage: string, status: string,
     *   product: array{id: string, part_number: string, name: string}|null,
     *   defect_count: int, completed_at: string|null
     * }>
     */
    public function inspectionsWithDefect(string $parameterName, array $filters): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(30)->startOfDay();
        $to   = isset($filters['to'])   ? Carbon::parse($filters['to'])->endOfDay()     : now()->endOfDay();

        $q = DB::table('inspections as i')
            ->join('inspection_measurements as m', 'm.inspection_id', '=', 'i.id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->where('m.parameter_name', $parameterName)
            ->where('m.is_pass', false)
            ->whereBetween('i.completed_at', [$from, $to])
            ->select(
                'i.id', 'i.inspection_number', 'i.stage', 'i.status', 'i.completed_at', 'i.defect_count',
                'p.id as product_id', 'p.part_number', 'p.name',
            )
            ->groupBy(
                'i.id', 'i.inspection_number', 'i.stage', 'i.status', 'i.completed_at', 'i.defect_count',
                'p.id', 'p.part_number', 'p.name',
            )
            ->orderByDesc('i.completed_at')
            ->limit(50);

        if (! empty($filters['product_id'])) $q->where('i.product_id', (int) $filters['product_id']);
        if (! empty($filters['stage']))      $q->where('i.stage', (string) $filters['stage']);

        $hashids = app('hashids');
        return $q->get()->map(fn ($r) => [
            'id'                => $hashids->encode((int) $r->id),
            'inspection_number' => $r->inspection_number,
            'stage'             => $r->stage,
            'status'            => $r->status,
            'product'           => $r->product_id ? [
                'id'          => $hashids->encode((int) $r->product_id),
                'part_number' => $r->part_number,
                'name'        => $r->name,
            ] : null,
            'defect_count'      => (int) $r->defect_count,
            'completed_at'      => $r->completed_at,
        ])->all();
    }
}
