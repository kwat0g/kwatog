<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\Product;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Services\WidgetAnalyticsService;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'sales_orders' => ['sales.open_orders', RenderKind::Breakdown, 'segments'],
            'sales_price_requests' => ['sales.price_requests', RenderKind::Breakdown, 'segments'],
            'sales_order_intake' => ['sales.order_intake', RenderKind::Trend, 'points'],
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
     */
    #[DataProvider('richWidgetProvider')]
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

    public function test_sales_pipeline_and_price_request_breakdowns_use_only_open_work(): void
    {
        foreach (['draft', 'confirmed', 'paid', 'cancelled'] as $status) {
            SalesOrder::factory()->create(['status' => $status]);
        }

        foreach (['requested', 'pending_finance', 'offered', 'accepted'] as $status) {
            DB::table('price_requests')->insert([
                'customer_id' => \App\Modules\Accounting\Models\Customer::factory()->create()->id,
                'product_id' => Product::factory()->create()->id,
                'notes' => 'Dashboard test request',
                'status' => $status,
                'history' => '[]',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $analytics = app(WidgetAnalyticsService::class);
        $orders = $analytics->payload('sales.open_orders', RenderKind::Breakdown, $this->admin);
        $priceRequests = $analytics->payload('sales.price_requests', RenderKind::Breakdown, $this->admin);

        $this->assertSame(2, $orders['total']);
        $this->assertEqualsCanonicalizing(['Draft', 'Confirmed'], array_column($orders['segments'], 'label'));
        $this->assertSame(3, $priceRequests['total']);
        $this->assertEqualsCanonicalizing(['Requested', 'Pending Finance', 'Offered'], array_column($priceRequests['segments'], 'label'));
    }

    public function test_sales_order_intake_returns_zero_filled_trailing_12_months(): void
    {
        $month = now()->startOfMonth();
        SalesOrder::factory()->create([
            'date' => $month->copy()->subMonth()->addDays(2)->toDateString(),
        ]);
        SalesOrder::factory()->create([
            'date' => $month->copy()->subMonths(13)->addDays(2)->toDateString(),
        ]);

        $payload = app(WidgetAnalyticsService::class)
            ->payload('sales.order_intake', RenderKind::Trend, $this->admin);

        $this->assertCount(12, $payload['points']);
        $this->assertSame($month->copy()->subMonth()->format('M y'), $payload['points'][10]['label']);
        $this->assertSame(1, $payload['points'][10]['value']);
        $this->assertSame(0, $payload['points'][11]['value']);
        $this->assertNull($payload['delta']);
    }

    public function test_ar_aging_uses_indexable_due_date_buckets_and_open_balances(): void
    {
        foreach ([
            [0, '100.00', InvoiceStatus::Finalized->value],
            [10, '50.00', InvoiceStatus::Partial->value],
            [40, '30.00', InvoiceStatus::Finalized->value],
            [61, '20.00', InvoiceStatus::Partial->value],
            [10, '900.00', InvoiceStatus::Cancelled->value],
        ] as [$daysOverdue, $balance, $status]) {
            Invoice::factory()->create([
                'due_date' => now()->subDays($daysOverdue)->toDateString(),
                'balance' => $balance,
                'status' => $status,
            ]);
        }

        Cache::forget("dashboard:widget:rich:{$this->admin->id}:breakdown:finance.ar_aging");
        $payload = app(WidgetAnalyticsService::class)
            ->payload('finance.ar_aging', RenderKind::Breakdown, $this->admin);
        $segments = collect($payload['segments'])->keyBy('label');

        $this->assertSame(200.0, $payload['total']);
        $this->assertSame(100.0, $segments['Current']['value']);
        $this->assertSame(50.0, $segments['1-30']['value']);
        $this->assertSame(30.0, $segments['31-60']['value']);
        $this->assertSame(20.0, $segments['60+']['value']);
    }

    /**
     * The gauge reads `FiscalYear::active()->current()`, so the fixture must
     * seed the year that CONTAINS today with the status that scope accepts.
     * This previously hard-coded 2031 with status `open` and so matched
     * nothing: `active()` wants `active` (fiscal_years is draft/active/closed,
     * migration 0162) and `current()` wants today inside start_date..end_date.
     * The gauge then correctly declined with [] and the assertion tripped over
     * a missing key — a fixture that only ever passed if run during 2031.
     */
    public function test_budget_gauge_reports_a_percentage_once_allocated(): void
    {
        $fiscalYearId = DB::table('fiscal_years')->insertGetId([
            'year' => (int) now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'active',
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
