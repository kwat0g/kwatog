<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — Tasks 72 + 73. One service, multiple role-targeted dashboards.
 *
 * Each role-method returns the same envelope shape:
 *   { kpis: [...], panels: { <key>: <data> } }
 *
 * All queries are guarded by Schema::hasTable to keep tests resilient on
 * fresh databases that haven't run every Sprint's migrations.
 *
 * 30-second Redis cache per (role, user_id) to keep dashboards snappy under
 * concurrent dashboard refreshes (e.g. during a panel demo). Cache invalidates
 * naturally via TTL — no manual purge needed for read-mostly aggregate data.
 */
class RoleDashboardService
{
    private const CACHE_TTL = 30;

    public function plantManager(User $user): array
    {
        return Cache::remember("dashboard:plant_manager:{$user->id}", self::CACHE_TTL, function () {
            return [
                'kpis' => [
                    $this->kpi('Revenue · Week',     $this->revenueWeek(),    'PHP'),
                    $this->kpi('Production · Week',  $this->productionWeek(), 'units'),
                    $this->kpi('OEE · Today',        $this->oeeToday(),       'pct'),
                    $this->kpi('On-Time Delivery',   $this->otdRate(),        'pct'),
                ],
                'panels' => [
                    'chain_stages'   => $this->chainStageBreakdown(),
                    'alerts'         => $this->alerts(),
                    'machine_util'   => $this->machineUtilization(),
                    'defect_pareto'  => $this->defectPareto(),
                ],
            ];
        });
    }

    public function hr(User $user): array
    {
        return Cache::remember("dashboard:hr:{$user->id}", self::CACHE_TTL, function () {
            $headcount         = $this->safeCount('employees', fn ($q) => $q->where('status', 'active'));
            $onLeaveToday      = $this->safeCount('leave_requests', fn ($q) => $q
                ->where('status', 'approved')
                ->where('start_date', '<=', today())
                ->where('end_date', '>=', today()));
            $pendingLeave      = $this->safeCount('leave_requests', fn ($q) => $q->whereIn('status', ['pending_dept', 'pending_hr', 'pending']));
            $pendingSeparation = $this->safeCount('clearances',     fn ($q) => $q->whereIn('status', ['pending', 'in_progress', 'completed']));

            return [
                'kpis' => [
                    $this->kpi('Active Headcount', (string) $headcount,       'count'),
                    $this->kpi('On Leave Today',   (string) $onLeaveToday,    'count'),
                    $this->kpi('Pending Leave',    (string) $pendingLeave,    'count'),
                    $this->kpi('Open Clearances',  (string) $pendingSeparation,'count'),
                ],
                'panels' => [
                    'by_department'   => $this->headcountByDepartment(),
                    'recent_hires'    => $this->recentHires(),
                    'pending_leaves'  => $this->pendingLeaves(),
                ],
            ];
        });
    }

    public function ppc(User $user): array
    {
        return Cache::remember("dashboard:ppc:{$user->id}", self::CACHE_TTL, function () {
            $activeWos     = $this->safeCount('work_orders', fn ($q) => $q->whereIn('status', ['planned', 'confirmed', 'in_progress', 'paused']));
            $shortages     = $this->safeCount('purchase_requests', fn ($q) => $q->where('is_auto_generated', true)->where('status', 'pending'));
            $breakdowns    = $this->safeCount('machine_downtimes', fn ($q) => $q->whereNull('end_time')->where('category', 'breakdown'));
            $moldsAtLimit  = $this->moldsNearingLimit();

            return [
                'kpis' => [
                    $this->kpi('Active WOs',       (string) $activeWos,    'count'),
                    $this->kpi('Material Shortages', (string) $shortages,  'count'),
                    $this->kpi('Active Breakdowns', (string) $breakdowns,  'count'),
                    $this->kpi('Molds ≥ 80%',      (string) $moldsAtLimit, 'count'),
                ],
                'panels' => [
                    'chain_stages'  => $this->chainStageBreakdown(),
                    'alerts'        => $this->alerts(),
                    'machine_util'  => $this->machineUtilization(),
                ],
            ];
        });
    }

    public function accounting(User $user): array
    {
        return Cache::remember("dashboard:accounting:{$user->id}", self::CACHE_TTL, function () {
            $cashBalance = $this->cashBalance();
            $arOpen      = $this->safeSum('invoices', 'balance', fn ($q) => $q->whereIn('status', ['unpaid', 'partial']));
            $apOpen      = $this->safeSum('bills',    'balance', fn ($q) => $q->whereIn('status', ['unpaid', 'partial']));
            $jeDraft     = $this->safeCount('journal_entries', fn ($q) => $q->where('status', 'draft'));

            return [
                'kpis' => [
                    $this->kpi('Cash Balance',  $cashBalance, 'PHP'),
                    $this->kpi('AR Outstanding', $arOpen,     'PHP'),
                    $this->kpi('AP Outstanding', $apOpen,     'PHP'),
                    $this->kpi('Draft JEs',     (string) $jeDraft, 'count'),
                ],
                'panels' => [
                    'recent_jes'     => $this->recentJournalEntries(),
                    'top_overdue_ar' => $this->topOverdueArCustomers(),
                ],
            ];
        });
    }

    public function employee(User $user): array
    {
        if (! $user->employee_id) {
            return ['kpis' => [], 'panels' => ['notice' => 'No linked employee profile.']];
        }
        return Cache::remember("dashboard:employee:{$user->id}", self::CACHE_TTL, function () use ($user) {
            $myAttendance = $this->safeCount('attendances', fn ($q) => $q
                ->where('employee_id', $user->employee_id)
                ->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()]));
            $myLeaveRem   = $this->safeSum('employee_leave_balances', 'remaining', fn ($q) => $q
                ->where('employee_id', $user->employee_id)
                ->where('year', (int) now()->format('Y')));
            $myPending    = $this->safeCount('leave_requests', fn ($q) => $q
                ->where('employee_id', $user->employee_id)
                ->whereIn('status', ['pending_dept', 'pending_hr', 'pending']));

            return [
                'kpis' => [
                    $this->kpi('Attendance · Month',   (string) $myAttendance, 'days'),
                    $this->kpi('Leave Days Remaining', $myLeaveRem,            'days'),
                    $this->kpi('Pending Requests',     (string) $myPending,    'count'),
                ],
                'panels' => [
                    'latest_payslip' => $this->latestPayslip($user->employee_id),
                    'next_holiday'   => $this->nextHoliday(),
                ],
            ];
        });
    }

    /* ─── Component queries ─── */

    private function kpi(string $label, string $value, string $unit): array
    {
        return ['label' => $label, 'value' => $value, 'unit' => $unit];
    }

    private function revenueWeek(): string
    {
        if (! Schema::hasTable('invoices')) return '0.00';
        $sum = (float) DB::table('invoices')
            ->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('total_amount');
        return number_format($sum, 2, '.', '');
    }

    private function productionWeek(): string
    {
        if (! Schema::hasTable('work_order_outputs')) return '0';
        $sum = (int) DB::table('work_order_outputs')
            ->whereBetween('recorded_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('good_count');
        return (string) $sum;
    }

    private function oeeToday(): string
    {
        if (! Schema::hasTable('work_order_outputs')) return '0.0';
        $good = (int) DB::table('work_order_outputs')
            ->whereDate('recorded_at', today())->sum('good_count');
        $rej  = (int) DB::table('work_order_outputs')
            ->whereDate('recorded_at', today())->sum('reject_count');
        if ($good + $rej === 0) return '0.0';
        return number_format(($good * 100.0) / max(1, $good + $rej), 1, '.', '');
    }

    private function otdRate(): string
    {
        if (! Schema::hasTable('deliveries')) return '0.0';
        $total = (int) DB::table('deliveries')
            ->whereIn('status', ['delivered', 'confirmed'])
            ->whereBetween('actual_delivery_date', [now()->subMonth(), now()])
            ->count();
        if ($total === 0) return '0.0';
        $onTime = (int) DB::table('deliveries')
            ->whereIn('status', ['delivered', 'confirmed'])
            ->whereBetween('actual_delivery_date', [now()->subMonth(), now()])
            ->whereColumn('actual_delivery_date', '<=', 'scheduled_date')
            ->count();
        return number_format(($onTime * 100.0) / $total, 1, '.', '');
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
        foreach ($stages as &$s) {
            $s['percent'] = (int) round(($s['count'] / $max) * 100);
        }
        return array_values($stages);
    }

    private function alerts(): array
    {
        $rows = [];
        if (Schema::hasTable('non_conformance_reports')) {
            $rows[] = ['kind' => 'ncr_open', 'severity' => 'danger',
                'count' => (int) DB::table('non_conformance_reports')->whereIn('status', ['open', 'in_progress'])->count(),
                'label' => 'Open NCRs'];
        }
        if (Schema::hasTable('machine_downtimes')) {
            $rows[] = ['kind' => 'breakdown', 'severity' => 'danger',
                'count' => (int) DB::table('machine_downtimes')->where('category', 'breakdown')->whereNull('end_time')->count(),
                'label' => 'Active Machine Breakdowns'];
        }
        $rows[] = ['kind' => 'mold_limit', 'severity' => 'warning',
            'count' => $this->moldsNearingLimit(),
            'label' => 'Molds ≥ 80% shot limit'];
        if (Schema::hasTable('purchase_requests')) {
            $rows[] = ['kind' => 'urgent_pr', 'severity' => 'warning',
                'count' => (int) DB::table('purchase_requests')->where('is_auto_generated', true)->where('status', 'pending')->count(),
                'label' => 'Auto-generated urgent PRs'];
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

    private function moldsNearingLimit(): int
    {
        if (! Schema::hasTable('molds')) return 0;
        return (int) DB::table('molds')
            ->whereRaw('current_shot_count >= (max_shots_before_maintenance * 0.8)')
            ->count();
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

    private function headcountByDepartment(): array
    {
        if (! Schema::hasTable('employees') || ! Schema::hasTable('departments')) return [];
        return DB::table('employees')
            ->leftJoin('departments', 'employees.department_id', '=', 'departments.id')
            ->where('employees.status', 'active')
            ->select('departments.name as label', DB::raw('COUNT(*) as count'))
            ->groupBy('departments.id', 'departments.name')
            ->orderByDesc('count')->get()
            ->map(fn ($r) => ['label' => $r->label ?? '—', 'count' => (int) $r->count])->all();
    }

    private function recentHires(): array
    {
        if (! Schema::hasTable('employees')) return [];
        return DB::table('employees')
            ->where('status', 'active')->orderByDesc('date_hired')
            ->select('id', 'employee_no', 'first_name', 'last_name', 'date_hired')->limit(5)->get()
            ->map(fn ($e) => [
                'id'          => app('hashids')->encode((int) $e->id),
                'employee_no' => $e->employee_no,
                'name'        => trim(($e->first_name ?? '').' '.($e->last_name ?? '')),
                'date_hired'  => $e->date_hired,
            ])->all();
    }

    private function pendingLeaves(): array
    {
        if (! Schema::hasTable('leave_requests')) return [];
        return DB::table('leave_requests')
            ->whereIn('status', ['pending_dept', 'pending_hr', 'pending'])
            ->orderByDesc('id')->limit(8)->get()
            ->map(fn ($r) => [
                'id'             => app('hashids')->encode((int) $r->id),
                'leave_request_no' => $r->leave_request_no ?? null,
                'status'         => $r->status,
                'days'           => (string) ($r->days ?? '0'),
            ])->all();
    }

    private function recentJournalEntries(): array
    {
        if (! Schema::hasTable('journal_entries')) return [];
        return DB::table('journal_entries')
            ->orderByDesc('id')->limit(8)->get()
            ->map(fn ($je) => [
                'id'           => app('hashids')->encode((int) $je->id),
                'entry_number' => $je->entry_number,
                'status'       => $je->status,
                'total_debit'  => (string) $je->total_debit,
                'date'         => $je->date,
            ])->all();
    }

    private function topOverdueArCustomers(): array
    {
        if (! Schema::hasTable('invoices')) return [];
        return DB::table('invoices')
            ->whereIn('status', ['unpaid', 'partial'])
            ->where('due_date', '<', now()->toDateString())
            ->select('customer_id', DB::raw('SUM(balance) as total_overdue'))
            ->groupBy('customer_id')
            ->orderByDesc('total_overdue')->limit(5)->get()
            ->map(fn ($r) => [
                'customer_id' => app('hashids')->encode((int) $r->customer_id),
                'total_overdue' => number_format((float) $r->total_overdue, 2, '.', ''),
            ])->all();
    }

    private function latestPayslip(int $employeeId): ?array
    {
        if (! Schema::hasTable('payrolls')) return null;
        $row = DB::table('payrolls')
            ->where('employee_id', $employeeId)
            ->orderByDesc('id')->first();
        if (! $row) return null;
        return [
            'id'        => app('hashids')->encode((int) $row->id),
            'gross_pay' => (string) ($row->gross_pay ?? '0'),
            'net_pay'   => (string) ($row->net_pay ?? '0'),
        ];
    }

    private function nextHoliday(): ?array
    {
        if (! Schema::hasTable('holidays')) return null;
        $row = DB::table('holidays')->where('date', '>=', today())->orderBy('date')->first();
        if (! $row) return null;
        return ['name' => $row->name, 'date' => $row->date, 'type' => $row->type];
    }
}
