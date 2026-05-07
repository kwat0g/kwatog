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
     * @return array<string, array<int, string>>
     */
    private function roleWidgets(): array
    {
        return [
            'system_admin' => [
                'chain.stage_breakdown', 'production.kpi', 'finance.cash_position',
                'hr.headcount', 'qc.pareto', 'alerts',
            ],
            'production_manager' => [
                'production.kpi', 'chain.stage_breakdown', 'machine.utilization',
                'oee.gauges', 'qc.pareto', 'alerts', 'production.active_wo',
            ],
            'ppc_head' => [
                'production.gantt_mini', 'mrp.shortages', 'machine.status',
                'production.wo_breakdown', 'material.reservations',
            ],
            'finance_officer' => [
                'finance.cash_position', 'finance.ar_aging', 'finance.ap_aging',
                'finance.revenue_mtd', 'finance.unpaid_invoices', 'finance.upcoming_payables',
            ],
            'hr_officer' => [
                'hr.headcount', 'hr.on_leave_today', 'approvals.pending',
                'hr.probation_alerts', 'payroll.upcoming',
            ],
            'purchasing_officer' => [
                'purchasing.open_prs', 'purchasing.open_pos', 'purchasing.supplier_perf',
                'supply.overdue_deliveries', 'inventory.low_stock',
            ],
            'qc_inspector' => [
                'qc.pending_inspections', 'qc.pareto', 'qc.open_ncrs', 'qc.pass_rate',
            ],
            'warehouse_staff' => [
                'inventory.pending_grns', 'inventory.low_stock',
                'inventory.pending_issues', 'supply.delivery_schedule',
            ],
            'department_head' => [
                'approvals.pending', 'hr.team_on_leave_today', 'hr.team_dtr_today',
            ],
            'employee' => [
                'self.payslip_summary', 'self.leave_balance',
                'self.dtr_today', 'self.pending_requests',
            ],
        ];
    }

    public function run(): void
    {
        $now = now();
        $rolesByslug = Role::pluck('id', 'slug');

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

            $rows = [];
            foreach ($widgetKeys as $i => $key) {
                $rows[] = [
                    'owner_type' => DashboardLayout::OWNER_ROLE,
                    'owner_id'   => $roleId,
                    'widget_key' => $key,
                    'position_x' => 0,
                    'position_y' => $i,
                    'width'      => 12,
                    'height'     => 4,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if (! empty($rows)) {
                DB::table('dashboard_layouts')->insert($rows);
            }
        }

        $this->command?->info('Dashboard role-default layouts seeded.');
    }
}
