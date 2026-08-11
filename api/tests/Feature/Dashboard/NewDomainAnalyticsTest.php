<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Services\WidgetAnalyticsService;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NewDomainAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    public static function richWidgetProvider(): array
    {
        return [
            'complaints' => ['crm.open_complaints', RenderKind::Breakdown, 'segments'],
            'assets' => ['assets.under_maintenance', RenderKind::Breakdown, 'segments'],
            'deliveries' => ['supply.overdue_deliveries', RenderKind::Table, 'rows'],
            'delivery_sch' => ['supply.delivery_schedule', RenderKind::Table, 'rows'],
            'rma_open' => ['rma.open_returns', RenderKind::Breakdown, 'segments'],
            'rma_pending' => ['rma.pending_approval', RenderKind::Table, 'rows'],
            'loans' => ['loans.outstanding', RenderKind::Table, 'rows'],
        ];
    }

    /**
     * Every previously scalar-only domain must produce a populated rich
     * payload of the documented shape — an empty array here means the widget
     * silently fell back to a scalar.
     *
     * @dataProvider richWidgetProvider
     */
    public function test_new_domain_widget_produces_its_rich_shape(
        string $key,
        RenderKind $kind,
        string $expectedKey,
    ): void {
        $payload = app(WidgetAnalyticsService::class)->payload($key, $kind, $this->admin);

        $this->assertNotSame([], $payload, "{$key} produced no rich payload");
        $this->assertArrayHasKey($expectedKey, $payload);
    }

    /**
     * Utilization of nothing is unknown, not 0% — with no approved budget the
     * gauge must decline to render rather than assert a figure.
     */
    public function test_budget_gauge_declines_when_nothing_is_allocated(): void
    {
        $this->assertSame(
            [],
            app(WidgetAnalyticsService::class)
                ->payload('budget.utilization', RenderKind::Gauge, $this->admin),
        );
    }

    public function test_budget_gauge_reports_a_percentage_once_allocated(): void
    {
        $fiscalYearId = DB::table('fiscal_years')->insertGetId([
            'year' => 2031,
            'start_date' => '2031-01-01',
            'end_date' => '2031-12-31',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('budgets')->insert([
            'fiscal_year_id' => $fiscalYearId,
            'department_id' => Department::factory()->create()->id,
            'budget_type' => 'operating',
            'name' => 'BG-T-'.substr(uniqid(), -5),
            'total_allocated' => 1000,
            'total_spent' => 250,
            'total_committed' => 0,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = app(WidgetAnalyticsService::class)
            ->payload('budget.utilization', RenderKind::Gauge, $this->admin);

        $this->assertSame(25.0, $payload['value']);
        $this->assertSame('percent', $payload['kind']);
    }

    /**
     * The department-scoped reading must never widen. A department_head sees
     * only their own department's loans — the same rule LoanController
     * enforces.
     */
    public function test_loans_table_is_department_scoped_without_the_company_wide_gate(): void
    {
        $mine = Department::factory()->create();
        $theirs = Department::factory()->create();

        $me = Employee::factory()->create(['department_id' => $mine->id]);
        $them = Employee::factory()->create(['department_id' => $theirs->id]);

        foreach ([[$me->id, 5000], [$them->id, 9000]] as [$employeeId, $balance]) {
            DB::table('employee_loans')->insert([
                'loan_no' => 'LN-T-'.substr(uniqid(), -5),
                'employee_id' => $employeeId,
                'loan_type' => 'company_loan',
                'principal' => $balance,
                'interest_rate' => 0,
                'monthly_amortization' => 500,
                'total_paid' => 0,
                'balance' => $balance,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'pay_periods_total' => 24,
                'pay_periods_remaining' => 24,
                'approval_chain_size' => 1,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $deptHead = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $me->id,
        ]);

        $payload = app(WidgetAnalyticsService::class)
            ->payload('loans.outstanding', RenderKind::Table, $deptHead);

        $this->assertSame(1, $payload['total_count'], 'department_head must not see other departments');
        $this->assertSame('5000.00', $payload['rows'][0]['balance']);
    }

    /**
     * Carbon 3 returns a SIGNED float from diffInDays, so an overdue date
     * yields -5.0. Lateness must read as a positive whole number of days.
     */
    public function test_overdue_delivery_days_late_is_a_positive_integer(): void
    {
        DB::table('deliveries')->insert([
            'delivery_number' => 'DL-T-'.substr(uniqid(), -5),
            'sales_order_id' => SalesOrder::factory()->create()->id,
            'status' => 'scheduled',
            'scheduled_date' => now()->subDays(5)->toDateString(),
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = app(WidgetAnalyticsService::class)
            ->payload('supply.overdue_deliveries', RenderKind::Table, $this->admin);

        $this->assertSame(5, $payload['rows'][0]['days_late']);
    }
}
