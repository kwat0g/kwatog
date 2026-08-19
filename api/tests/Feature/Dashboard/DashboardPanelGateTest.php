<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\FinanceDashboardService;
use App\Modules\Dashboard\Services\HrDashboardService;
use App\Modules\Dashboard\Services\PlantManagerDashboardService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The bespoke dashboards gate every panel, not just the page.
 *
 * They used to be gated ONCE, at the route (`permission:dashboard.plant_manager.view`
 * and friends). Every panel inside then ran unconditionally, so holding the page
 * grant delivered data the viewer's own module would have refused — most
 * visibly, the Plant Manager dashboard handed cash, AR, AP and posted revenue to
 * production_manager, a role with no `accounting.*` grant at all.
 *
 * The widget registry never had this problem: a widget row declares its
 * permission and DashboardLayoutService strips what the caller cannot hold.
 * These tests pin the bespoke pages to the same rule.
 */
class DashboardPanelGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // Every dashboard caches per caller; a stale entry from a previous
        // assertion would mask the gate under test.
        Cache::flush();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'gate+'.substr(uniqid(), -8).'@t.test',
        ]);
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Gate Role '.substr(uniqid(), -6),
            'slug' => 'gate-'.substr(uniqid(), -6),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')->all(),
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'email' => 'gate+'.substr(uniqid(), -8).'@t.test',
        ]);
    }

    /* ─── Plant Manager ─────────────────────────────────────────────── */

    /**
     * The leak this work closed. production_manager holds
     * `dashboard.plant_manager.view` and NO accounting grant, so the financial
     * snapshot and the Revenue KPI must not reach it.
     */
    public function test_plant_manager_without_an_accounting_grant_gets_no_financial_panel(): void
    {
        $user = $this->userWithRole('production_manager');

        $data = app(PlantManagerDashboardService::class)->plantManager($user);

        $this->assertArrayNotHasKey('financial_snapshot', $data['panels']);
        $this->assertNotContains(
            'Revenue · Week',
            array_column($data['kpis'], 'label'),
            'money reached a role with no accounting grant',
        );

        // The rest of its own dashboard is untouched.
        $this->assertArrayHasKey('machine_util', $data['panels']);
        $this->assertArrayHasKey('defect_pareto', $data['panels']);
        $this->assertArrayHasKey('chain_stages', $data['panels']);
        $this->assertArrayHasKey('alerts', $data['panels']);
    }

    /**
     * Non-financial plant rates ride the page grant: one aggregate each, no row,
     * no amount, no counterparty. Gating OEE or OTD on a module read that
     * production_manager lacks would strip the plant dashboard's own headline
     * numbers from the plant manager.
     */
    public function test_plant_rates_survive_without_module_grants(): void
    {
        $data = app(PlantManagerDashboardService::class)
            ->plantManager($this->userWithRole('production_manager'));

        $labels = array_column($data['kpis'], 'label');
        $this->assertContains('OEE · Today', $labels);
        $this->assertContains('On-Time Delivery', $labels);
        $this->assertContains('Production · Week', $labels);
    }

    /**
     * Permission, not role name: a role nobody wrote code for gets the finance
     * panel purely by holding the accounting read.
     */
    public function test_the_financial_panel_follows_the_permission_not_the_role(): void
    {
        $user = $this->userWithPermissions([
            'dashboard.plant_manager.view',
            'accounting.dashboard.view',
        ]);

        $data = app(PlantManagerDashboardService::class)->plantManager($user);

        $this->assertArrayHasKey('financial_snapshot', $data['panels']);
        $this->assertContains('Revenue · Week', array_column($data['kpis'], 'label'));
        // …and it still gets nothing it has no grant for.
        $this->assertArrayNotHasKey('machine_util', $data['panels']);
        $this->assertArrayNotHasKey('defect_pareto', $data['panels']);
    }

    public function test_system_admin_sees_every_plant_panel(): void
    {
        $data = app(PlantManagerDashboardService::class)
            ->plantManager($this->userWithRole('system_admin'));

        foreach (['chain_stages', 'alerts', 'machine_util', 'defect_pareto', 'financial_snapshot', 'range'] as $key) {
            $this->assertArrayHasKey($key, $data['panels'], "admin lost the {$key} panel");
        }
        $this->assertCount(4, $data['kpis']);
    }

    /** The range echo is the caller's own input, so it is never gated away. */
    public function test_the_range_echo_is_never_gated(): void
    {
        $data = app(PlantManagerDashboardService::class)
            ->plantManager($this->userWithPermissions(['dashboard.plant_manager.view']), 'month');

        $this->assertSame('month', $data['panels']['range']);
    }

    /* ─── HR ────────────────────────────────────────────────────────── */

    /**
     * REC-05 gated the payroll panel on `payroll.view`, which
     * RolePermissionSeeder::selfService() grants to EVERY role — so the company's
     * latest payroll run, its net-pay total and its pending salary adjustments
     * were effectively ungated for anyone who reached the page.
     */
    public function test_the_payroll_panel_needs_the_payroll_run_read_not_the_own_payslip_read(): void
    {
        // Holds payroll.view (own payslip) and the HR reads, but not
        // payroll.periods.view.
        $user = $this->userWithPermissions([
            'dashboard.hr.view',
            'hr.employees.view',
            'payroll.view',
        ]);

        $data = app(HrDashboardService::class)->hr($user);

        $this->assertArrayNotHasKey('payroll_summary', $data['panels']);
        $this->assertArrayHasKey('by_department', $data['panels']);
    }

    public function test_the_payroll_panel_arrives_with_the_payroll_run_read(): void
    {
        $data = app(HrDashboardService::class)->hr($this->userWithRole('hr_officer'));

        $this->assertArrayHasKey('payroll_summary', $data['panels']);
    }

    /**
     * The HR page draws on three separate domains, and a viewer can hold any
     * subset: HR master, leave, attendance.
     */
    public function test_hr_panels_arrive_per_domain_grant(): void
    {
        $data = app(HrDashboardService::class)->hr($this->userWithPermissions([
            'dashboard.hr.view',
            'leave.view',
        ]));

        $this->assertArrayHasKey('pending_leaves', $data['panels']);
        $this->assertArrayHasKey('leave_calendar_week', $data['panels']);
        $this->assertArrayNotHasKey('by_department', $data['panels']);
        $this->assertArrayNotHasKey('attendance_summary', $data['panels']);
        // Self-scoped, so never gated.
        $this->assertArrayHasKey('pending_my_action', $data['panels']);
    }

    /**
     * A projection of headcount is gated like headcount, not on
     * `forecasting.view` — hr_officer holds no forecasting grant and must still
     * read its own forecast. Same rule the forecast.headcount widget follows.
     */
    public function test_the_headcount_forecast_is_gated_like_headcount(): void
    {
        $withHr = app(HrDashboardService::class)->hr($this->userWithPermissions([
            'dashboard.hr.view', 'hr.employees.view',
        ]));
        $withoutHr = app(HrDashboardService::class)->hr($this->userWithPermissions([
            'dashboard.hr.view', 'leave.view',
        ]));

        $this->assertArrayHasKey('headcount_forecast', $withHr['panels']);
        $this->assertArrayNotHasKey('headcount_forecast', $withoutHr['panels']);
    }

    /* ─── Finance (shared cache) ────────────────────────────────────── */

    /**
     * FinanceDashboardService caches under a key shared across callers. Gating
     * its panels per viewer without keying the cache by the ANSWERS would serve
     * one viewer's panel set to another — a gate that leaks is worse than none.
     */
    public function test_the_finance_cache_does_not_leak_a_richer_payload_to_a_narrower_viewer(): void
    {
        $full = $this->userWithRole('finance_officer');
        $narrow = $this->userWithPermissions(['accounting.dashboard.view']);

        // Warm the cache with the privileged payload FIRST: if the key ignored
        // permissions, the narrow caller would be served this one.
        $fullData = app(FinanceDashboardService::class)->summary($full);
        $narrowData = app(FinanceDashboardService::class)->summary($narrow);

        $this->assertArrayHasKey('ar_aging_summary', $fullData);
        $this->assertArrayHasKey('payroll_pipeline', $fullData);

        $this->assertArrayNotHasKey('ar_aging_summary', $narrowData);
        $this->assertArrayNotHasKey('ap_aging_summary', $narrowData);
        $this->assertArrayNotHasKey('recent_journal_entries', $narrowData);
        $this->assertArrayNotHasKey('payroll_pipeline', $narrowData);
        $this->assertArrayNotHasKey('budget_vs_actual_top', $narrowData);

        // The page grant still carries cash and revenue.
        $this->assertArrayHasKey('cash_balance', $narrowData);
        $this->assertArrayHasKey('revenue_mtd', $narrowData);
    }

    /** Two callers with identical grants still share one cache entry. */
    public function test_callers_with_the_same_grants_share_a_cache_entry(): void
    {
        $a = $this->userWithRole('finance_officer');
        $b = $this->userWithRole('finance_officer');

        $this->assertSame(
            array_keys(app(FinanceDashboardService::class)->summary($a)),
            array_keys(app(FinanceDashboardService::class)->summary($b)),
        );
    }

    /** The endpoint still answers, and still refuses the unauthorized. */
    public function test_the_finance_endpoint_returns_the_gated_payload(): void
    {
        $this->actingAs($this->userWithRole('finance_officer'))
            ->getJson('/api/v1/dashboards/finance')
            ->assertOk()
            ->assertJsonStructure(['data' => ['cash_balance', 'ar_aging_summary']]);

        $this->actingAs($this->userWithRole('warehouse_staff'))
            ->getJson('/api/v1/dashboards/finance')
            ->assertForbidden();
    }
}
