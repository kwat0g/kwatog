<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Dashboard\Models\DashboardLayout;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Series R — Task R4.
 *
 * Default dashboard widget set per role. AuthService::login clones these
 * rows into user-owned rows on first login. Re-running this seeder rebuilds
 * the role defaults but does NOT touch user-owned rows — users keep their
 * personal layouts intact.
 */
class DashboardRoleLayoutSeeder extends Seeder
{
    /**
     * Each seeded role gets a default layout. Roles without a purpose-built
     * dashboard get only the general widgets their permissions allow; the
     * registry strips any widget they no longer qualify for.
     *
     * @return array<string, array<int, string>>
     */
    private function roleWidgets(): array
    {
        return [
            'system_admin' => [
                'chain.stage_breakdown', 'production.kpi', 'finance.cash_position',
                'hr.headcount', 'qc.pareto', 'alerts',
                'kpi.oee',
            ],
            // Company overview (DSS 01-matrix §B) — this role had NO default at
            // all, so its generic layout resolved to empty and the dashboard
            // switcher had nothing to add. The blocks it can read are money
            // (AR/AP/unpaid/upcoming payables), chain health, demand vs supply
            // (revenue + capacity forecasts) and the approvals worklist.
            //
            // Quality widgets use read-only grants; the executive receives no
            // quality write or approval authority.
            // Demand-vs-supply is present because those two forecasts are gated on
            // their DATASET reads, which admit the company-wide read; gating them
            // on a module page grant (`forecasting.view`, `accounting.dashboard.view`)
            // had hidden them from the one reader the page is built for. This role
            // does not hold |forecasting.view` and never needed to.
            //
            // The six health-strip readings arrive through `KpiStrip`, not as
            // widgets here, because the strip already names them and a tile that
            // duplicates the strip's own number is noise.
            'vice_president' => [
                'chain.stage_breakdown', 'chain.recovery_backlog', 'alerts',
                'finance.ar_aging', 'finance.ap_aging',
                'finance.unpaid_invoices', 'finance.upcoming_payables',
                'budget.utilization', 'forecast.revenue', 'forecast.capacity',
                'approvals.pending', 'qc.pareto', 'quality.ncr_aging',
                'quality.eightd_sla', 'executive.risks_opportunities',
                'executive.weekly_briefing',
            ],
            'production_manager' => [
                'production.kpi', 'chain.stage_breakdown', 'machine.utilization',
                'oee.gauges', 'qc.pareto', 'alerts', 'production.active_wo',
                'maintenance.open_wos', 'maintenance.due_schedules', 'kpi.oee',
                'forecast.capacity',
            ],
            'ppc_head' => [
                'production.gantt_mini', 'mrp.shortages', 'machine.status',
                'production.wo_breakdown', 'material.reservations',
                'maintenance.due_schedules', 'kpi.on_time_delivery',
                'forecast.capacity',
            ],
            'finance_officer' => [
                'finance.cash_position', 'finance.ar_aging', 'finance.ap_aging',
                'finance.revenue_mtd', 'finance.invoice_handoff_backlog',
                'finance.unpaid_invoices', 'finance.upcoming_payables',
                'forecast.revenue', 'budget.utilization', 'kpi.ar_aging_60d',
                'forecast.cash_flow',
            ],
            'hr_officer' => [
                'hr.headcount', 'hr.on_leave_today', 'approvals.pending',
                'hr.probation_alerts', 'payroll.upcoming', 'forecast.headcount',
                'kpi.attendance_rate', 'forecast.attendance',
            ],
            'purchasing_officer' => [
                'purchasing.open_prs', 'purchasing.open_pos', 'purchasing.supplier_perf',
                'supply.overdue_deliveries', 'inventory.low_stock',
                'rma.open_returns', 'kpi.supplier_quality',
            ],
            'qc_inspector' => [
                'qc.pending_inspections', 'qc.pareto', 'qc.open_ncrs', 'qc.pass_rate',
                'forecast.defect_rate', 'rma.open_returns', 'kpi.dppm',
            ],
            // `supply.delivery_schedule` is back, now that
            // `supply_chain.deliveries.view` exists and warehouse_staff holds
            // it. It was briefly dropped: the widget needed `supply_chain.view`,
            // a whole-module read (shipments, fleet, customs docs) that the
            // floor has no business holding, so the tile was silently stripped
            // at render and the default was lying about it.
            'warehouse_staff' => [
                'inventory.pending_grns', 'inventory.qc_handoff_issues', 'inventory.low_stock',
                'inventory.pending_issues', 'supply.delivery_schedule',
                'rma.open_returns', 'kpi.inventory_turnover',
            ],
            // Approvals-first: this role carries the second-most approve-type
            // permissions but has no bespoke dashboard page, so the registry
            // default IS its dashboard. Deliberately department-scoped — the
            // company-wide `hr.headcount` / `hr.on_leave_today` readings it
            // also qualifies for duplicate the team-scoped widgets below at a
            // breadth a single department's head has no use for.
            // Now lands on `/dashboard/department` (2026-10-11). The set is
            // unchanged and deliberately so: it is already the department-scoped
            // approver's card list (M-p3 R15 §2), and the new page is a body to
            // render it in rather than a reason to add tiles. The two cards that
            // role still cannot have are recorded, not faked — a department
            // attendance RATE would need `kpi.attendance_rate`, whose gate is the
            // HR planning read this role does not hold, and the matrix's other
            // option (a dept-rollup card) has no dept-scoped KPI source. Left
            // open rather than granted, because that KPI is company-wide.
            'department_head' => [
                'approvals.pending', 'hr.team_dtr_today', 'hr.team_on_leave_today',
                'purchasing.open_prs', 'hr.probation_alerts', 'loans.outstanding',
                'rma.pending_approval',
                'self.payslip_summary', 'self.leave_balance',
                'self.dtr_today', 'self.pending_requests',
            ],
            'employee' => [
                'self.payslip_summary', 'self.leave_balance',
                'self.dtr_today', 'self.pending_requests',
            ],
            // Roles without a bespoke dashboard page get their best qualifying
            // widget set (all self-scoped or permission-consistent). Order
            // matters: the widget registry strips anything the role can't see
            // (DashboardLayoutService::getEffectiveLayout), so these lists
            // stay valid if role permissions change.
            'maintenance_tech' => [
                'machine.status', 'maintenance.open_wos', 'maintenance.due_schedules',
                'assets.under_maintenance', 'approvals.pending',
                'self.payslip_summary', 'self.leave_balance',
                'self.dtr_today', 'self.pending_requests',
            ],
            'maintenance_supervisor' => [
                'machine.status', 'maintenance.open_wos', 'maintenance.due_schedules',
                'assets.under_maintenance', 'approvals.pending',
                'self.payslip_summary', 'self.leave_balance',
                'self.dtr_today', 'self.pending_requests',
            ],
            // Now lands on `/dashboard/logistics` (2026-10-11). Shipments in
            // transit is the one card here that had no widget; the other four
            // are the supply/purchasing reads this role already qualified for.
            // `kpi.on_time_delivery` is the addition the logistics page existed
            // for — OTD is the reading ImpEx is judged on, and the supply_chain
            // KPI gate now admits `dashboard.logistics.view` (G-P3-2).
            'impex_officer' => [
                'kpi.on_time_delivery',
                'supply.overdue_deliveries', 'supply.delivery_schedule',
                'logistics.shipments_in_transit',
                'purchasing.open_prs', 'purchasing.open_pos',
                'approvals.pending',
                'self.payslip_summary', 'self.leave_balance',
                'self.dtr_today', 'self.pending_requests',
            ],
            // These roles previously had no defaults and resolved to an empty
            // grid, so their new page body would have been empty too.
            // Order intake, pipeline stages, price-request status, and customer
            // requests give sales a compact view of its active work.
            'sales_officer' => [
                'sales.order_intake', 'sales.open_orders', 'sales.price_requests',
                'alerts',
                'self.payslip_summary', 'self.leave_balance',
                'self.dtr_today', 'self.pending_requests',
            ],
            // Complaints and return cases are the two queues this role owns.
            'customer_service_officer' => [
                'crm.open_complaints', 'rma.open_returns', 'rma.pending_approval',
                'alerts', 'approvals.pending',
                'self.payslip_summary', 'self.leave_balance',
                'self.dtr_today', 'self.pending_requests',
            ],
            'driver' => [
                'approvals.pending',
                'self.payslip_summary', 'self.leave_balance',
                'self.dtr_today', 'self.pending_requests',
            ],
        ];
    }

    /** Columns in the dashboard grid. Mirrors the SPA's 12-column layout. */
    private const GRID_COLUMNS = 12;

    public function run(): void
    {
        $now = now();
        $rolesByslug = Role::pluck('id', 'slug');
        $defaults = DB::table('dashboard_widgets')->pluck('default_w', 'key');

        foreach ($this->roleWidgets() as $slug => $widgetKeys) {
            $roleId = $rolesByslug->get($slug);
            if (! $roleId) {
                $this->command?->warn("Role '{$slug}' not found; skipping default layout.");

                continue;
            }

            // Clear existing role-owned rows for this role and re-seed.
            DB::table('dashboard_layouts')
                ->where('owner_type', DashboardLayout::OWNER_ROLE)
                ->where('owner_id', $roleId)
                ->delete();

            // Each widget takes the width its render kind implies, packed
            // left-to-right and wrapped at the 12-column boundary. Every row
            // used to be full-width because the SPA ignored the column.
            $rows = [];
            $x = 0;
            $y = 0;
            foreach ($widgetKeys as $key) {
                $width = (int) ($defaults[$key] ?? 12);

                if ($x + $width > self::GRID_COLUMNS) {
                    $x = 0;
                    $y++;
                }

                $rows[] = [
                    'owner_type' => DashboardLayout::OWNER_ROLE,
                    'owner_id' => $roleId,
                    'widget_key' => $key,
                    'position_x' => $x,
                    'position_y' => $y,
                    'width' => $width,
                    'height' => 4,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $x += $width;
            }
            if (! empty($rows)) {
                DB::table('dashboard_layouts')->insert($rows);
            }
        }

        $this->command?->info('Dashboard role-default layouts seeded.');
    }
}
