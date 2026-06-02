<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared query helpers used across multiple role dashboards.
 * Extract via P4.1 — zero logic change; only location moved.
 */
trait DashboardQueries
{
    private function kpi(string $label, string $value, string $unit): array
    {
        return ['label' => $label, 'value' => $value, 'unit' => $unit];
    }

    private function safeCount(string $table, ?\Closure $scope = null): int
    {
        if (! Schema::hasTable($table)) return 0;
        $q = DB::table($table);
        if ($scope) $scope($q);
        return (int) $q->count();
    }

    private function safeSum(string $table, string $column, ?\Closure $scope = null): string
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) return '0.00';
        $q = DB::table($table);
        if ($scope) $scope($q);
        return number_format((float) $q->sum($column), 2, '.', '');
    }

    private function cashBalance(): string
    {
        if (! Schema::hasTable('journal_entry_lines') || ! Schema::hasTable('accounts')) return '0.00';
        $row = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_entry_lines.account_id', '=', 'accounts.id')
            ->whereIn('accounts.code', ['1010', '1020', '1030'])
            ->where('journal_entries.status', 'posted')
            ->select(DB::raw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS bal'))
            ->first();
        return number_format((float) ($row->bal ?? 0), 2, '.', '');
    }

    private function chainStageBreakdown(): array
    {
        $stages = [
            'order_entered'     => ['label' => 'Order Entered',  'color' => 'success', 'count' => 0],
            'mrp_planned'       => ['label' => 'MRP Planned',    'color' => 'success', 'count' => 0],
            'in_production'     => ['label' => 'In Production',  'color' => 'info',    'count' => 0],
            'qc_pending'        => ['label' => 'QC Pending',     'color' => 'info',    'count' => 0],
            'ready_to_ship'     => ['label' => 'Ready to Ship',  'color' => 'success', 'count' => 0],
            'delivered_unpaid'  => ['label' => 'Delivered · Unpaid', 'color' => 'warning', 'count' => 0],
        ];

        if (Schema::hasTable('sales_orders')) {
            $stages['order_entered']['count']    = (int) DB::table('sales_orders')->where('status', 'confirmed')->count();
            $stages['in_production']['count']    = (int) DB::table('sales_orders')->where('status', 'in_production')->count();
            $stages['delivered_unpaid']['count'] = (int) DB::table('sales_orders')->where('status', 'delivered')->count();
        }
        if (Schema::hasTable('mrp_plans')) {
            $stages['mrp_planned']['count'] = (int) DB::table('mrp_plans')->whereIn('status', ['draft', 'approved'])->count();
        }
        if (Schema::hasTable('inspections')) {
            $stages['qc_pending']['count'] = (int) DB::table('inspections')
                ->where('stage', 'outgoing')->where('status', 'in_progress')->count();
        }
        if (Schema::hasTable('deliveries')) {
            $stages['ready_to_ship']['count'] = (int) DB::table('deliveries')->where('status', 'scheduled')->count();
        }

        $max = max(1, max(array_column($stages, 'count')));
        $out = [];
        foreach ($stages as $key => $s) {
            $s['key']     = $key;
            $s['percent'] = (int) round(($s['count'] / $max) * 100);
            $out[]        = $s;
        }
        return $out;
    }

    /**
     * Itemized, actionable alerts (named + linkable) with a per-kind cap.
     *
     * @return array<int, array{kind: string, severity: string, label: string, ref: string|null, ref_id: string|null}>
     */
    private function alerts(): array
    {
        $rows = [];

        if (Schema::hasTable('machine_downtimes') && Schema::hasTable('machines')) {
            $breakdowns = DB::table('machine_downtimes as md')
                ->join('machines as m', 'm.id', '=', 'md.machine_id')
                ->where('md.category', 'breakdown')
                ->whereNull('md.end_time')
                ->orderByDesc('md.start_time')
                ->limit(5)
                ->get(['m.id', 'm.machine_code']);
            foreach ($breakdowns as $b) {
                $rows[] = [
                    'kind'     => 'breakdown',
                    'severity' => 'danger',
                    'label'    => "{$b->machine_code} breakdown",
                    'ref'      => 'machine',
                    'ref_id'   => app('hashids')->encode((int) $b->id),
                ];
            }
        }

        if (Schema::hasTable('non_conformance_reports')) {
            $ncrs = DB::table('non_conformance_reports')
                ->whereIn('status', ['open', 'in_progress'])
                ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'major' THEN 1 ELSE 2 END")
                ->limit(5)
                ->get(['id', 'ncr_number', 'severity']);
            foreach ($ncrs as $n) {
                $rows[] = [
                    'kind'     => 'ncr_open',
                    'severity' => $n->severity === 'critical' ? 'danger' : 'warning',
                    'label'    => "{$n->ncr_number} ({$n->severity})",
                    'ref'      => 'ncr',
                    'ref_id'   => app('hashids')->encode((int) $n->id),
                ];
            }
        }

        if (Schema::hasTable('molds')) {
            $molds = DB::table('molds')
                ->whereRaw('current_shot_count >= (max_shots_before_maintenance * 0.8)')
                ->orderByRaw('(current_shot_count * 1.0 / NULLIF(max_shots_before_maintenance, 0)) DESC')
                ->limit(5)
                ->get(['id', 'mold_code', 'current_shot_count', 'max_shots_before_maintenance']);
            foreach ($molds as $mold) {
                $pct = $mold->max_shots_before_maintenance > 0
                    ? (int) round(($mold->current_shot_count * 100) / $mold->max_shots_before_maintenance)
                    : 0;
                $rows[] = [
                    'kind'     => 'mold_limit',
                    'severity' => 'warning',
                    'label'    => "{$mold->mold_code} at {$pct}% shot limit",
                    'ref'      => 'mold',
                    'ref_id'   => app('hashids')->encode((int) $mold->id),
                ];
            }
        }

        if (Schema::hasTable('purchase_requests')) {
            $urgent = (int) DB::table('purchase_requests')
                ->where('is_auto_generated', true)->where('status', 'pending')->count();
            if ($urgent > 0) {
                $rows[] = [
                    'kind'     => 'urgent_pr',
                    'severity' => 'warning',
                    'label'    => "{$urgent} auto-generated urgent PR".($urgent === 1 ? '' : 's'),
                    'ref'      => null,
                    'ref_id'   => null,
                ];
            }
        }

        return $rows;
    }

    private function machineUtilization(): array
    {
        if (! Schema::hasTable('machines')) return [];
        return DB::table('machines')->select('id', 'machine_code', 'name', 'status', 'current_work_order_id')
            ->orderBy('machine_code')->limit(12)->get()
            ->map(fn ($m) => [
                'id'         => app('hashids')->encode((int) $m->id),
                'code'       => $m->machine_code,
                'name'       => $m->name,
                'status'     => $m->status,
                'has_active_wo' => (bool) $m->current_work_order_id,
            ])->all();
    }

    private function defectPareto(): array
    {
        if (! Schema::hasTable('work_order_defects') || ! Schema::hasTable('defect_types')) return [];
        return DB::table('work_order_defects')
            ->join('defect_types', 'work_order_defects.defect_type_id', '=', 'defect_types.id')
            ->select('defect_types.code', 'defect_types.name', DB::raw('SUM(work_order_defects.count) as total'))
            ->groupBy('defect_types.id', 'defect_types.code', 'defect_types.name')
            ->orderByDesc('total')->limit(8)->get()
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'count' => (int) $r->total])
            ->all();
    }
}
