<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Dashboard\Models\DashboardWidget;
use Illuminate\Database\Seeder;

/**
 * Series R — Task R4.
 *
 * Catalog of widget keys used by the dashboard registry. Adding a new widget
 * means: (1) add a row here, (2) register a React component in the SPA's
 * widget registry under the same `key`, (3) re-run the seeder. Widgets the
 * registry doesn't know about render as an EmptyState placeholder.
 */
class DashboardWidgetSeeder extends Seeder
{
    /**
     * @return array<int, array{key: string, name: string, module: string, permission: ?string, default_w?: int, default_h?: int, description?: string}>
     */
    private function catalog(): array
    {
        return [
            // ─── Production / Plant ────────────────────────────────
            ['key' => 'production.kpi',                'name' => 'Production KPIs',           'module' => 'production',  'permission' => 'production.dashboard.view'],
            ['key' => 'production.active_wo',          'name' => 'Active Work Orders',        'module' => 'production',  'permission' => 'production.work_orders.view'],
            ['key' => 'production.wo_breakdown',       'name' => 'WO Status Breakdown',       'module' => 'production',  'permission' => 'production.work_orders.view'],
            ['key' => 'production.gantt_mini',         'name' => 'Production Schedule (Gantt)', 'module' => 'production', 'permission' => 'production.schedule.view'],
            ['key' => 'machine.utilization',           'name' => 'Machine Utilization',       'module' => 'production',  'permission' => 'production.dashboard.view'],
            ['key' => 'machine.status',                'name' => 'Machine Status',            'module' => 'production',  'permission' => 'mrp.machines.view'],
            ['key' => 'oee.gauges',                    'name' => 'OEE Gauges',                'module' => 'production',  'permission' => 'production.dashboard.view'],
            ['key' => 'chain.stage_breakdown',         'name' => 'Chain Stage Breakdown',     'module' => 'production',  'permission' => 'dashboard.view_bottlenecks'],

            // ─── Quality ────────────────────────────────────────────
            ['key' => 'qc.pareto',                     'name' => 'QC Defect Pareto',          'module' => 'quality',     'permission' => 'quality.view'],
            ['key' => 'qc.pending_inspections',        'name' => 'Pending Inspections',       'module' => 'quality',     'permission' => 'quality.inspections.view'],
            ['key' => 'qc.open_ncrs',                  'name' => 'Open NCRs',                 'module' => 'quality',     'permission' => 'quality.ncr.view'],
            ['key' => 'qc.pass_rate',                  'name' => 'Pass Rate by Product',      'module' => 'quality',     'permission' => 'quality.view'],

            // ─── MRP / PPC ──────────────────────────────────────────
            ['key' => 'mrp.shortages',                 'name' => 'MRP Shortages',             'module' => 'mrp',         'permission' => 'mrp.plans.view'],
            ['key' => 'material.reservations',         'name' => 'Material Reservations',     'module' => 'mrp',         'permission' => 'mrp.view'],

            // ─── Finance ────────────────────────────────────────────
            ['key' => 'finance.cash_position',         'name' => 'Cash Position',             'module' => 'accounting',  'permission' => 'accounting.dashboard.view'],
            ['key' => 'finance.ar_aging',              'name' => 'AR Aging',                  'module' => 'accounting',  'permission' => 'accounting.invoices.view'],
            ['key' => 'finance.ap_aging',              'name' => 'AP Aging',                  'module' => 'accounting',  'permission' => 'accounting.bills.view'],
            ['key' => 'finance.revenue_mtd',           'name' => 'Revenue Month-To-Date',     'module' => 'accounting',  'permission' => 'accounting.dashboard.view'],
            ['key' => 'finance.unpaid_invoices',       'name' => 'Unpaid Invoices',           'module' => 'accounting',  'permission' => 'accounting.invoices.view'],
            ['key' => 'finance.upcoming_payables',     'name' => 'Upcoming Payables',         'module' => 'accounting',  'permission' => 'accounting.bills.view'],

            // ─── HR / Payroll ───────────────────────────────────────
            ['key' => 'hr.headcount',                  'name' => 'Headcount by Department',   'module' => 'hr',          'permission' => 'hr.employees.view'],
            ['key' => 'hr.on_leave_today',             'name' => 'On Leave Today',            'module' => 'hr',          'permission' => 'leave.view'],
            ['key' => 'hr.team_on_leave_today',        'name' => 'Team On Leave Today',       'module' => 'hr',          'permission' => 'leave.view'],
            ['key' => 'hr.team_dtr_today',             'name' => 'Team DTR Today',            'module' => 'hr',          'permission' => 'attendance.view'],
            ['key' => 'hr.probation_alerts',           'name' => 'Probation Alerts',          'module' => 'hr',          'permission' => 'hr.employees.view'],
            ['key' => 'payroll.upcoming',              'name' => 'Upcoming Payroll',          'module' => 'payroll',     'permission' => 'payroll.view'],
            ['key' => 'approvals.pending',             'name' => 'Pending Approvals',         'module' => 'platform',    'permission' => null],

            // ─── Purchasing / Supply Chain ─────────────────────────
            ['key' => 'purchasing.open_prs',           'name' => 'Open Purchase Requests',    'module' => 'purchasing',  'permission' => 'purchasing.view'],
            ['key' => 'purchasing.open_pos',           'name' => 'Open Purchase Orders',      'module' => 'purchasing',  'permission' => 'purchasing.view'],
            ['key' => 'purchasing.supplier_perf',      'name' => 'Supplier Performance',      'module' => 'purchasing',  'permission' => 'purchasing.view'],
            ['key' => 'supply.overdue_deliveries',     'name' => 'Overdue Deliveries',        'module' => 'supply_chain', 'permission' => 'supply_chain.view'],
            ['key' => 'supply.delivery_schedule',      'name' => 'Delivery Schedule',         'module' => 'supply_chain', 'permission' => 'supply_chain.view'],

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
        ];
    }

    public function run(): void
    {
        foreach ($this->catalog() as $w) {
            DashboardWidget::updateOrCreate(
                ['key' => $w['key']],
                [
                    'name'        => $w['name'],
                    'description' => $w['description'] ?? null,
                    'module'      => $w['module'],
                    'permission'  => $w['permission'],
                    'default_w'   => $w['default_w'] ?? 12,
                    'default_h'   => $w['default_h'] ?? 4,
                ],
            );
        }

        $this->command?->info('Dashboard widgets seeded ('.count($this->catalog()).').');
    }
}
