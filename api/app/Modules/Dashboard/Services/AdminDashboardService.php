<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Concerns\DashboardQueries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System Administrator dashboard — cross-module health overview.
 */
class AdminDashboardService
{
    use DashboardQueries;

    private const CACHE_TTL = 30;

    public function admin(User $user): array
    {
        return Cache::remember("dashboard:admin:{$user->id}", self::CACHE_TTL, function () {
            return [
                'kpis'   => $this->adminKpis(),
                'panels' => [
                    'chain_stages'      => $this->chainStageBreakdown(),
                    'module_activity'   => $this->moduleActivity(),
                    'user_activity'     => $this->userActivity(),
                    'pending_approvals' => $this->pendingApprovalsByType(),
                    'recent_audit'      => $this->recentAuditEvents(),
                ],
            ];
        });
    }

    private function adminKpis(): array
    {
        $activeUsers = $this->safeCount('sessions', fn ($q) =>
            $q->where('last_activity', '>=', now()->subMinutes(30)->timestamp)
        );

        $pendingApprovals = $this->safeCount('approval_records', fn ($q) =>
            $q->where('action', 'pending')
        );

        $openAlerts = $this->safeCount('alerts', fn ($q) =>
            $q->where('is_dismissed', false)
        );

        $failedLogins = $this->safeCount('login_history', fn ($q) =>
            $q->where('status', '!=', 'success')
              ->where('created_at', '>=', now()->subHours(24))
        );

        return [
            $this->kpi('Active Users',        (string) $activeUsers,        'users'),
            $this->kpi('Pending Approvals',   (string) $pendingApprovals,   'items'),
            $this->kpi('Open Alerts',         (string) $openAlerts,         'alerts'),
            $this->kpi('Failed Logins (24h)', (string) $failedLogins,       'attempts'),
        ];
    }

    private function moduleActivity(): array
    {
        return [
            [
                'key'   => 'hr',
                'label' => 'HR',
                'href'  => '/hr/employees',
                'stats' => [
                    ['label' => 'Active employees', 'value' => (string) $this->safeCount('employees', fn ($q) => $q->where('status', 'active'))],
                    ['label' => 'Pending leaves',   'value' => (string) $this->safeCount('leave_requests', fn ($q) => $q->where('status', 'pending'))],
                    ['label' => 'Pending OT',        'value' => (string) $this->safeCount('overtime_requests', fn ($q) => $q->where('status', 'pending'))],
                ],
            ],
            [
                'key'   => 'payroll',
                'label' => 'Payroll',
                'href'  => '/payroll/periods',
                'stats' => [
                    ['label' => 'Draft periods',    'value' => (string) $this->safeCount('payroll_periods', fn ($q) => $q->where('status', 'draft'))],
                    ['label' => 'Approved periods', 'value' => (string) $this->safeCount('payroll_periods', fn ($q) => $q->where('status', 'approved'))],
                    ['label' => 'Anomaly flags',    'value' => (string) $this->safeCount('payroll_anomaly_flags', fn ($q) => $q->where('is_resolved', false))],
                ],
            ],
            [
                'key'   => 'inventory',
                'label' => 'Inventory',
                'href'  => '/inventory/items',
                'stats' => [
                    ['label' => 'Low stock items',  'value' => (string) $this->lowStockCount()],
                    ['label' => 'Pending GRNs',     'value' => (string) $this->safeCount('goods_receipt_notes', fn ($q) => $q->where('status', 'pending'))],
                    ['label' => 'Open MIS',          'value' => (string) $this->safeCount('material_issue_slips', fn ($q) => $q->whereIn('status', ['draft', 'pending']))],
                ],
            ],
            [
                'key'   => 'purchasing',
                'label' => 'Purchasing',
                'href'  => '/purchasing/purchase-requests',
                'stats' => [
                    ['label' => 'Open PRs',      'value' => (string) $this->safeCount('purchase_requests', fn ($q) => $q->whereIn('status', ['draft', 'pending', 'approved']))],
                    ['label' => 'Open POs',      'value' => (string) $this->safeCount('purchase_orders',  fn ($q) => $q->whereIn('status', ['draft', 'approved', 'sent']))],
                    ['label' => 'Overdue bills', 'value' => (string) $this->safeCount('bills', fn ($q) => $q->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', now()))],
                ],
            ],
            [
                'key'   => 'production',
                'label' => 'Production',
                'href'  => '/production/work-orders',
                'stats' => [
                    ['label' => 'Active WOs',         'value' => (string) $this->safeCount('work_orders', fn ($q) => $q->where('status', 'in_progress'))],
                    ['label' => 'Planned WOs',        'value' => (string) $this->safeCount('work_orders', fn ($q) => $q->whereIn('status', ['planned', 'confirmed']))],
                    ['label' => 'Machine breakdowns', 'value' => (string) $this->safeCount('machine_downtimes', fn ($q) => $q->where('category', 'breakdown')->whereNull('end_time'))],
                ],
            ],
            [
                'key'   => 'quality',
                'label' => 'Quality',
                'href'  => '/quality/ncrs',
                'stats' => [
                    ['label' => 'Open NCRs',          'value' => (string) $this->safeCount('non_conformance_reports', fn ($q) => $q->whereIn('status', ['open', 'in_progress']))],
                    ['label' => 'Pending inspections','value' => (string) $this->safeCount('inspections', fn ($q) => $q->where('status', 'in_progress'))],
                    ['label' => 'Critical NCRs',      'value' => (string) $this->safeCount('non_conformance_reports', fn ($q) => $q->where('severity', 'critical')->whereIn('status', ['open', 'in_progress']))],
                ],
            ],
        ];
    }

    private function lowStockCount(): int
    {
        if (! Schema::hasTable('stock_levels') || ! Schema::hasTable('items')) {
            return 0;
        }
        return (int) DB::table('stock_levels as sl')
            ->join('items as i', 'i.id', '=', 'sl.item_id')
            ->whereRaw('sl.quantity <= i.reorder_point')
            ->where('i.reorder_point', '>', 0)
            ->count();
    }

    private function userActivity(): array
    {
        $recentLogins = [];
        if (Schema::hasTable('login_history') && Schema::hasTable('users')) {
            $rows = DB::table('login_history as lh')
                ->leftJoin('users as u', 'u.id', '=', 'lh.user_id')
                ->orderByDesc('lh.created_at')
                ->limit(8)
                ->select(['u.name', 'lh.email_attempted', 'lh.status', 'lh.ip_address', 'lh.created_at'])
                ->get();

            foreach ($rows as $row) {
                $recentLogins[] = [
                    'name'       => $row->name ?? $row->email_attempted ?? '—',
                    'status'     => $row->status,
                    'ip'         => $row->ip_address ?? '—',
                    'created_at' => $row->created_at,
                ];
            }
        }

        $trendRows = [];
        if (Schema::hasTable('login_history')) {
            $trendRows = DB::table('login_history')
                ->where('status', 'success')
                ->where('created_at', '>=', Carbon::today()->subDays(6)->startOfDay())
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
                ->groupByRaw('DATE(created_at)')
                ->pluck('total', 'day')
                ->toArray();
        }
        $loginTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day          = Carbon::today()->subDays($i)->toDateString();
            $loginTrend[] = (int) ($trendRows[$day] ?? 0);
        }

        return [
            'recent_logins'  => $recentLogins,
            'login_trend_7d' => $loginTrend,
            'total_users'    => $this->safeCount('users'),
            'active_today'   => $this->safeCount('login_history', fn ($q) =>
                $q->where('status', 'success')->whereDate('created_at', today())
            ),
        ];
    }

    private function pendingApprovalsByType(): array
    {
        if (! Schema::hasTable('approval_records')) {
            return [];
        }

        $rows = DB::table('approval_records')
            ->where('action', 'pending')
            ->select('approvable_type', DB::raw('COUNT(*) as total'))
            ->groupBy('approvable_type')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $labelMap = [
            'App\\Modules\\Purchasing\\Models\\PurchaseRequest'      => 'Purchase Requests',
            'App\\Modules\\Purchasing\\Models\\PurchaseOrder'        => 'Purchase Orders',
            'App\\Modules\\Leave\\Models\\LeaveRequest'              => 'Leave Requests',
            'App\\Modules\\Loans\\Models\\EmployeeLoan'              => 'Loan Applications',
            'App\\Modules\\Attendance\\Models\\OvertimeRequest'      => 'Overtime Requests',
            'App\\Modules\\Quality\\Models\\NonConformanceReport'    => 'NCR Dispositions',
            'App\\Modules\\ReturnManagement\\Models\\ReturnRequest'  => 'Return Requests',
            'App\\Modules\\Accounting\\Models\\Budget'               => 'Budget Approvals',
        ];

        $hrefMap = [
            'App\\Modules\\Purchasing\\Models\\PurchaseRequest'      => '/purchasing/purchase-requests',
            'App\\Modules\\Purchasing\\Models\\PurchaseOrder'        => '/purchasing/purchase-orders',
            'App\\Modules\\Leave\\Models\\LeaveRequest'              => '/hr/leaves',
            'App\\Modules\\Loans\\Models\\EmployeeLoan'              => '/hr/loans',
            'App\\Modules\\Attendance\\Models\\OvertimeRequest'      => '/hr/attendance/overtime',
            'App\\Modules\\Quality\\Models\\NonConformanceReport'    => '/quality/ncrs',
            'App\\Modules\\ReturnManagement\\Models\\ReturnRequest'  => '/return-management',
            'App\\Modules\\Accounting\\Models\\Budget'               => '/budgeting',
        ];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'type'  => \Illuminate\Support\Str::snake(class_basename($row->approvable_type)),
                'label' => $labelMap[$row->approvable_type] ?? class_basename($row->approvable_type),
                'count' => (int) $row->total,
                'href'  => $hrefMap[$row->approvable_type] ?? '/approvals',
            ];
        }
        return $out;
    }

    private function recentAuditEvents(): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $rows = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->orderByDesc('al.created_at')
            ->limit(10)
            ->select(['u.name as user_name', 'al.action', 'al.model_type', 'al.ip_address', 'al.created_at'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'user'       => $row->user_name ?? 'System',
                'action'     => $row->action,
                'entity'     => class_basename($row->model_type),
                'ip'         => $row->ip_address ?? '—',
                'created_at' => $row->created_at,
            ];
        }
        return $out;
    }
}
