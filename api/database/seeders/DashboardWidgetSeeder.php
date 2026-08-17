<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Dashboard\Models\DashboardWidget;
use App\Modules\Dashboard\Services\KpiSnapshotService;
use Illuminate\Database\Seeder;

/**
 * Series R — Task R4.
 *
 * Catalog of widget keys used by the dashboard registry. Adding a new widget
 * means: (1) add a row here, (2) register a React component in the SPA's
 * widget registry under the same `key`, (3) re-run the seeder. Widgets the
 * registry doesn't know about render as an EmptyState placeholder.
 *
 * A row declares four independent things, and they must stay independent:
 * `permission` (who may see it), `render_kind` (how it draws), `link_path`
 * (where "Open →" goes) and its module (how the picker groups it). None of
 * them names a role — a role reaches a widget by holding its permission.
 */
class DashboardWidgetSeeder extends Seeder
{
    /**
     * Default width per render kind, in 12-column units. A widget's shape,
     * not its module, decides how much room it needs: a table wants the full
     * row, a gauge reads fine at a third of one.
     */
    private const WIDTH_BY_KIND = [
        'table' => 12,
        'trend' => 8,
        'breakdown' => 6,
        'gauge' => 4,
        'scalar' => 4,
    ];

    /**
     * Where each widget's "Open →" link goes.
     *
     * Held here rather than in the SPA because this is where the widget is
     * defined. The identical map used to live in
     * spa/src/components/dashboard/registry.tsx with nothing binding the two
     * lists together, so a widget added here rendered a tile with no way out
     * of it. WidgetSeedIntegrityTest now asserts every seeded key has an entry.
     */
    private const LINK_BY_KEY = [
        'production.kpi'            => '/production/dashboard',
        'production.active_wo'      => '/production/work-orders',
        'production.wo_breakdown'   => '/production/work-orders',
        'production.gantt_mini'     => '/production/schedule',
        'machine.utilization'       => '/production/dashboard',
        'machine.status'            => '/mrp/machines',
        'oee.gauges'                => '/production/oee',
        'chain.stage_breakdown'     => '/chains',
        'qc.pareto'                 => '/quality/dashboard',
        'qc.pending_inspections'    => '/quality/inspections',
        'qc.open_ncrs'              => '/quality/ncrs',
        'qc.pass_rate'              => '/quality/dashboard',
        'mrp.shortages'             => '/mrp/plans',
        'material.reservations'     => '/inventory/stock-levels',
        'finance.cash_position'     => '/accounting/balance-sheet',
        'finance.ar_aging'          => '/accounting/invoices',
        'finance.ap_aging'          => '/accounting/bills',
        'finance.revenue_mtd'       => '/accounting/income-statement',
        'finance.unpaid_invoices'   => '/accounting/invoices',
        'finance.upcoming_payables' => '/accounting/bills',
        'hr.headcount'              => '/hr/employees',
        'hr.on_leave_today'         => '/hr/leaves',
        'hr.team_on_leave_today'    => '/hr/leaves',
        'hr.team_dtr_today'         => '/hr/attendance',
        'hr.probation_alerts'       => '/hr/employees',
        'payroll.upcoming'          => '/payroll/periods',
        'approvals.pending'         => '/approvals',
        'purchasing.open_prs'       => '/purchasing/purchase-requests',
        'purchasing.open_pos'       => '/purchasing/purchase-orders',
        'purchasing.supplier_perf'  => '/purchasing/approved-suppliers',
        'supply.overdue_deliveries' => '/supply-chain/deliveries',
        'supply.delivery_schedule'  => '/supply-chain/deliveries',
        'inventory.low_stock'       => '/inventory/stock-levels',
        'inventory.pending_grns'    => '/inventory/grn',
        'inventory.pending_issues'  => '/inventory/material-issues',
        'self.payslip_summary'      => '/self-service/payslips',
        'self.leave_balance'        => '/self-service/leaves',
        'self.dtr_today'            => '/self-service/dtr',
        'self.pending_requests'     => '/self-service',
        // Forecast tiles summarise a projection; the bespoke role dashboards
        // carry the full historical + forecast chart, so "Open →" goes there.
        'forecast.headcount'        => '/dashboard/hr',
        'forecast.revenue'          => '/dashboard/finance',
        'forecast.defect_rate'      => '/dashboard/quality',
        'maintenance.open_wos'      => '/maintenance/work-orders',
        'maintenance.due_schedules' => '/maintenance/schedules',
        'assets.under_maintenance'  => '/assets',
        'rma.open_returns'          => '/return-management',
        'rma.pending_approval'      => '/return-management',
        'crm.open_complaints'       => '/crm/complaints',
        'budget.utilization'        => '/budgeting/budget-vs-actual',
        'loans.outstanding'         => '/hr/loans',
        'alerts'                    => '/alerts',
    ];

    /**
     * The IATF scorecard KPIs, as pickable widgets.
     *
     * `kpi_definitions` + `kpi_snapshots` already carried a monthly actual, a
     * target, a warning threshold, a direction and up to 24 months of history
     * — the richest analytics asset in the system — and none of it was
     * addressable from the widget registry. It was reachable only through the
     * KpiStrip hard-coded onto the seven bespoke dashboard pages, so the five
     * roles that land on the generic dashboard could not see a single KPI.
     *
     * Each row's permission is taken from KpiSnapshotService::MODULE_PERMISSIONS
     * — the SAME boundary /dashboard/kpi/scorecard already enforces. A widget
     * over a dataset is no less sensitive than the dataset (the rule the
     * forecast.* widgets follow).
     *
     * Kept as an explicit list, not derived from `kpi_definitions` at seed
     * time: seeder order would then decide whether widgets exist at all.
     * WidgetSeedIntegrityTest asserts this list and KpiDefinitionSeeder agree.
     *
     * @return array<int, array{code: string, name: string, module: string}>
     */
    public static function kpiCatalog(): array
    {
        return [
            ['code' => 'oee',               'name' => 'OEE Trend',                 'module' => 'production'],
            ['code' => 'wo_completion_rate','name' => 'WO Completion Rate Trend',  'module' => 'production'],
            ['code' => 'dppm',              'name' => 'DPPM Trend',                'module' => 'quality'],
            ['code' => 'first_pass_yield',  'name' => 'First Pass Yield Trend',    'module' => 'quality'],
            ['code' => 'ncr_closure_days',  'name' => 'NCR Closure Time Trend',    'module' => 'quality'],
            ['code' => 'on_time_delivery',  'name' => 'On-Time Delivery Trend',    'module' => 'supply_chain'],
            ['code' => 'supplier_quality',  'name' => 'Supplier Quality Trend',    'module' => 'purchasing'],
            ['code' => 'attendance_rate',   'name' => 'Attendance Rate Trend',     'module' => 'attendance'],
            ['code' => 'ar_aging_60d',      'name' => 'AR Over 60 Days Trend',     'module' => 'accounting'],
            ['code' => 'budget_utilization','name' => 'Budget Utilization Trend',  'module' => 'accounting'],
            ['code' => 'inventory_turnover','name' => 'Inventory Turnover Trend',  'module' => 'inventory'],
        ];
    }

    /** The widget key a KPI code is published under. */
    public static function kpiWidgetKey(string $code): string
    {
        return 'kpi.'.$code;
    }

    /**
     * @return array<int, array{key: string, name: string, module: string, permission: ?string, render_kind?: string, default_w?: int, default_h?: int, description?: string}>
     */
    private function kpiWidgets(): array
    {
        return array_map(fn (array $kpi): array => [
            'key'         => self::kpiWidgetKey($kpi['code']),
            'name'        => $kpi['name'],
            'module'      => $kpi['module'],
            // A module with no entry in the map is ungated on the scorecard;
            // keep the widget ungated too rather than inventing a stricter
            // rule the scorecard does not apply.
            'permission'  => KpiSnapshotService::MODULE_PERMISSIONS[$kpi['module']] ?? null,
            'render_kind' => 'trend',
            // A KPI sparkline reads fine at a third of the row; the 8-column
            // default for `trend` is for a 14-day production series.
            'default_w'   => 4,
            'description' => 'Monthly actual against target, with trailing history.',
        ], self::kpiCatalog());
    }

    /**
     * @return array<int, array{key: string, name: string, module: string, permission: ?string, render_kind?: string, default_w?: int, default_h?: int, description?: string}>
     */
    private function catalog(): array
    {
        return [
            // ─── Production / Plant ────────────────────────────────
            ['key' => 'production.kpi',                'name' => 'Production KPIs',           'module' => 'production',  'permission' => 'production.dashboard.view', 'render_kind' => 'trend'],
            ['key' => 'production.active_wo',          'name' => 'Active Work Orders',        'module' => 'production',  'permission' => 'production.work_orders.view'],
            ['key' => 'production.wo_breakdown',       'name' => 'WO Status Breakdown',       'module' => 'production',  'permission' => 'production.work_orders.view', 'render_kind' => 'breakdown'],
            ['key' => 'production.gantt_mini',         'name' => 'Production Schedule (Gantt)', 'module' => 'production', 'permission' => 'production.schedule.view'],
            ['key' => 'machine.utilization',           'name' => 'Machine Utilization',       'module' => 'production',  'permission' => 'production.dashboard.view', 'render_kind' => 'gauge'],
            ['key' => 'machine.status',                'name' => 'Machine Status',            'module' => 'production',  'permission' => 'mrp.machines.view'],
            ['key' => 'oee.gauges',                    'name' => 'OEE Gauges',                'module' => 'production',  'permission' => 'production.dashboard.view', 'render_kind' => 'gauge'],
            ['key' => 'chain.stage_breakdown',         'name' => 'Chain Stage Breakdown',     'module' => 'production',  'permission' => 'dashboard.view_bottlenecks'],

            // ─── Quality ────────────────────────────────────────────
            ['key' => 'qc.pareto',                     'name' => 'QC Defect Pareto',          'module' => 'quality',     'permission' => 'quality.view', 'render_kind' => 'breakdown'],
            ['key' => 'qc.pending_inspections',        'name' => 'Pending Inspections',       'module' => 'quality',     'permission' => 'quality.inspections.view'],
            ['key' => 'qc.open_ncrs',                  'name' => 'Open NCRs',                 'module' => 'quality',     'permission' => 'quality.ncr.view'],
            ['key' => 'qc.pass_rate',                  'name' => 'Pass Rate by Product',      'module' => 'quality',     'permission' => 'quality.view'],

            // ─── MRP / PPC ──────────────────────────────────────────
            ['key' => 'mrp.shortages',                 'name' => 'MRP Shortages',             'module' => 'mrp',         'permission' => 'mrp.plans.view'],
            ['key' => 'material.reservations',         'name' => 'Material Reservations',     'module' => 'mrp',         'permission' => 'mrp.view'],

            // ─── Finance ────────────────────────────────────────────
            ['key' => 'finance.cash_position',         'name' => 'Cash Position',             'module' => 'accounting',  'permission' => 'accounting.dashboard.view'],
            ['key' => 'finance.ar_aging',              'name' => 'AR Aging',                  'module' => 'accounting',  'permission' => 'accounting.invoices.view', 'render_kind' => 'breakdown'],
            ['key' => 'finance.ap_aging',              'name' => 'AP Aging',                  'module' => 'accounting',  'permission' => 'accounting.bills.view'],
            ['key' => 'finance.revenue_mtd',           'name' => 'Revenue Month-To-Date',     'module' => 'accounting',  'permission' => 'accounting.dashboard.view'],
            ['key' => 'finance.unpaid_invoices',       'name' => 'Unpaid Invoices',           'module' => 'accounting',  'permission' => 'accounting.invoices.view'],
            ['key' => 'finance.upcoming_payables',     'name' => 'Upcoming Payables',         'module' => 'accounting',  'permission' => 'accounting.bills.view'],

            // ─── HR / Payroll ───────────────────────────────────────
            ['key' => 'hr.headcount',                  'name' => 'Headcount by Department',   'module' => 'hr',          'permission' => 'hr.employees.view', 'render_kind' => 'breakdown'],
            // Company-wide leave requires the same sensitive HR gate as the
            // employee controller's all-row view. A department head may read
            // their team through the separate team widget, but must not add a
            // company-wide roster through the picker.
            ['key' => 'hr.on_leave_today',             'name' => 'On Leave Today',            'module' => 'hr',          'permission' => 'hr.employees.view_sensitive'],
            ['key' => 'hr.team_on_leave_today',        'name' => 'Team On Leave Today',       'module' => 'hr',          'permission' => 'leave.view'],
            ['key' => 'hr.team_dtr_today',             'name' => 'Team DTR Today',            'module' => 'hr',          'permission' => 'attendance.view'],
            ['key' => 'hr.probation_alerts',           'name' => 'Probation Alerts',          'module' => 'hr',          'permission' => 'hr.employees.view'],
            ['key' => 'payroll.upcoming',              'name' => 'Upcoming Payroll',          'module' => 'payroll',     'permission' => 'payroll.periods.view'],
            // No permission: the resolver scopes to the caller's own role
            // (DashboardWidgetDataService::pendingApprovalsForRole).
            //
            // A worklist, not a count. This is the one widget every
            // approval-carrying role has, and `department_head` — 6 approve
            // grants, no bespoke dashboard page — reads its queue here or
            // nowhere. A bare "7" told it nothing about what to open first.
            ['key' => 'approvals.pending',             'name' => 'Pending Approvals',         'module' => 'platform',    'permission' => null, 'render_kind' => 'table'],

            // ─── Purchasing / Supply Chain ─────────────────────────
            ['key' => 'purchasing.open_prs',           'name' => 'Open Purchase Requests',    'module' => 'purchasing',  'permission' => 'purchasing.view'],
            ['key' => 'purchasing.open_pos',           'name' => 'Open Purchase Orders',      'module' => 'purchasing',  'permission' => 'purchasing.view', 'render_kind' => 'breakdown'],
            ['key' => 'purchasing.supplier_perf',      'name' => 'Supplier Performance',      'module' => 'purchasing',  'permission' => 'purchasing.view'],
            ['key' => 'supply.overdue_deliveries',     'name' => 'Overdue Deliveries',        'module' => 'supply_chain', 'permission' => 'supply_chain.view', 'render_kind' => 'table'],
            ['key' => 'supply.delivery_schedule',      'name' => 'Delivery Schedule',         'module' => 'supply_chain', 'permission' => 'supply_chain.view', 'render_kind' => 'table'],

            // ─── Inventory / Warehouse ─────────────────────────────
            ['key' => 'inventory.low_stock',           'name' => 'Low Stock Alerts',          'module' => 'inventory',   'permission' => 'inventory.view'],
            ['key' => 'inventory.pending_grns',        'name' => 'Pending GRNs',              'module' => 'inventory',   'permission' => 'inventory.view'],
            ['key' => 'inventory.pending_issues',      'name' => 'Pending Material Issues',   'module' => 'inventory',   'permission' => 'inventory.view'],

            // ─── Self-service ──────────────────────────────────────
            ['key' => 'self.payslip_summary',          'name' => 'Latest Payslip',            'module' => 'payroll',     'permission' => null],
            ['key' => 'self.leave_balance',            'name' => 'My Leave Balance',          'module' => 'leave',       'permission' => null],
            ['key' => 'self.dtr_today',                'name' => 'My Shift Today',            'module' => 'attendance',  'permission' => null],
            ['key' => 'self.pending_requests',         'name' => 'My Pending Requests',       'module' => 'platform',    'permission' => null],

            // ─── Platform ──────────────────────────────────────────
            ['key' => 'alerts',                        'name' => 'Alerts',                    'module' => 'platform',    'permission' => 'alerts.view'],

            // ─── Forecasts ─────────────────────────────────────────
            // ForecastingDashboardService already computed these; until now
            // they were reachable only from the bespoke HR / Finance /
            // Quality dashboard pages. Each is gated on the SAME permission
            // as the existing widget over the same underlying data
            // (hr.headcount, finance.revenue_mtd, qc.pareto) — a projection
            // of a dataset is no less sensitive than the dataset.
            ['key' => 'forecast.headcount',            'name' => 'Headcount Forecast',        'module' => 'hr',          'permission' => 'hr.employees.view'],
            ['key' => 'forecast.revenue',              'name' => 'Revenue Forecast',          'module' => 'accounting',  'permission' => 'accounting.dashboard.view'],
            ['key' => 'forecast.defect_rate',          'name' => 'Defect Rate Forecast',      'module' => 'quality',     'permission' => 'quality.view'],

            // ─── Maintenance / Assets ──────────────────────────────
            ['key' => 'maintenance.open_wos',          'name' => 'Open Maintenance WOs',      'module' => 'maintenance', 'permission' => 'maintenance.view'],
            ['key' => 'maintenance.due_schedules',     'name' => 'Preventive Maintenance Due', 'module' => 'maintenance', 'permission' => 'maintenance.view'],
            ['key' => 'assets.under_maintenance',      'name' => 'Assets Under Maintenance',  'module' => 'assets',      'permission' => 'assets.view', 'render_kind' => 'breakdown'],

            // ─── Returns / CRM / Budget ────────────────────────────
            ['key' => 'rma.open_returns',              'name' => 'Open Return Requests',      'module' => 'return_management', 'permission' => 'return_management.view', 'render_kind' => 'breakdown'],
            ['key' => 'rma.pending_approval',          'name' => 'Returns Awaiting Approval', 'module' => 'return_management', 'permission' => 'return_management.view', 'render_kind' => 'table'],
            ['key' => 'crm.open_complaints',           'name' => 'Open Customer Complaints',  'module' => 'crm',         'permission' => 'crm.view', 'render_kind' => 'breakdown'],
            ['key' => 'budget.utilization',            'name' => 'Budget Utilization',        'module' => 'budgeting',   'permission' => 'budgeting.view', 'render_kind' => 'gauge'],
            // Resolver scopes to the caller's department unless they hold a
            // company-wide loans read — see ::outstandingLoans.
            ['key' => 'loans.outstanding',             'name' => 'Outstanding Loans',         'module' => 'loans',       'permission' => 'loans.view', 'render_kind' => 'table'],
        ];
    }

    /**
     * Every widget row: the hand-written catalog plus the KPI scorecard ones.
     *
     * @return array<int, array{key: string, name: string, module: string, permission: ?string, render_kind?: string, default_w?: int, default_h?: int, description?: string}>
     */
    public function allWidgets(): array
    {
        return array_merge($this->catalog(), $this->kpiWidgets());
    }

    /** The "Open →" target for a key, or null when it has no deeper page. */
    public static function linkFor(string $key): ?string
    {
        // Every KPI resolves to the scorecard, which carries the full monthly
        // table and the target/threshold columns a tile cannot show.
        if (str_starts_with($key, 'kpi.')) {
            return '/dashboard/scorecard';
        }

        return self::LINK_BY_KEY[$key] ?? null;
    }

    public function run(): void
    {
        $widgets = $this->allWidgets();

        foreach ($widgets as $w) {
            $kind = $w['render_kind'] ?? 'scalar';

            DashboardWidget::updateOrCreate(
                ['key' => $w['key']],
                [
                    'name'        => $w['name'],
                    'description' => $w['description'] ?? null,
                    'module'      => $w['module'],
                    'permission'  => $w['permission'],
                    'render_kind' => $kind,
                    'link_path'   => self::linkFor($w['key']),
                    'default_w'   => $w['default_w'] ?? self::WIDTH_BY_KIND[$kind] ?? 4,
                    'default_h'   => $w['default_h'] ?? 4,
                ],
            );
        }

        $this->command?->info('Dashboard widgets seeded ('.count($widgets).').');
    }
}
