<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Models\DashboardWidget;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use App\Modules\Dashboard\Services\DashboardWidgetDataService;
use App\Modules\Dashboard\Services\WidgetAnalyticsService;
use App\Modules\HR\Models\Employee;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DashboardWidgetDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
    }

    public function test_every_registered_widget_resolves_a_live_data_source(): void
    {
        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->firstOrFail()->id,
        ]);
        $keys = collect(app(DashboardLayoutService::class)->listAvailableWidgets($admin))
            ->pluck('key')->all();

        $summaries = app(DashboardWidgetDataService::class)->summaries($keys, $admin);

        // Every widget in the catalog must resolve — asserted against the
        // catalog itself rather than a hardcoded count, so adding a widget
        // without a resolver fails here instead of silently passing once
        // someone bumps the number.
        $this->assertCount(count($keys), $summaries);
        $this->assertSame(DashboardWidget::count(), count($summaries));
        $unavailable = collect($summaries)->where('available', false);
        $this->assertSame([], $unavailable->keys()->all(), $unavailable->pluck('helper', 'key')->toJson());
    }

    public function test_widget_endpoint_returns_live_self_data_and_filters_forbidden_keys(): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'employee')->firstOrFail()->id,
            'employee_id' => $employee->id,
        ]);
        DB::table('attendances')->insert([
            'employee_id' => $employee->id,
            'date' => now()->toDateString(),
            'regular_hours' => '7.50',
            'status' => 'present',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/dashboard/widget-data?keys[]=self.dtr_today&keys[]=finance.cash_position');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('7.50', $data['self.dtr_today']['value']);
        $this->assertSame('hours', $data['self.dtr_today']['kind']);
        $this->assertArrayNotHasKey('finance.cash_position', $data);
    }

    public function test_unavailable_widget_logs_the_original_exception_and_keeps_the_safe_fallback(): void
    {
        $exception = new \RuntimeException('Settings database connection failed.');
        $settings = \Mockery::mock(SettingsService::class);
        $settings->shouldReceive('requiredInt')->once()->andThrow($exception);
        $this->app->instance(SettingsService::class, $settings);
        Log::spy();

        $user = User::factory()->create();
        $summary = app(DashboardWidgetDataService::class)
            ->summaries(['production.active_wo'], $user)['production.active_wo'];

        $this->assertFalse($summary['available']);
        $this->assertSame('Live data source unavailable.', $summary['helper']);
        Log::shouldHaveReceived('error')->once()->withArgs(
            static fn (string $message, array $context): bool => $message === 'Dashboard widget data source failed.'
                && ($context['widget'] ?? null) === 'production.active_wo'
                && ($context['exception'] ?? null) === $exception,
        );
    }

    /**
     * `loans.outstanding` is gated on `loans.view`, which department_head holds
     * so it can approve its own team's loans. Read company-wide, that gate
     * would hand one department's head every employee's debt, so the resolver
     * narrows to the caller's department unless they also hold
     * `loans.write_off` (finance/HR, the company-wide readers).
     */
    public function test_loans_outstanding_widget_is_department_scoped_for_department_heads(): void
    {
        $ownDept = Employee::factory()->create();
        $otherDept = Employee::factory()->create();
        $this->assertNotSame($ownDept->department_id, $otherDept->department_id);

        foreach ([[$ownDept, '1000.00'], [$otherDept, '4000.00']] as [$employee, $balance]) {
            DB::table('employee_loans')->insert([
                'employee_id' => $employee->id,
                'loan_no' => 'LN-T-'.substr(uniqid(), -5),
                'loan_type' => 'company_loan',
                'principal' => $balance,
                'balance' => $balance,
                'monthly_amortization' => '500.00',
                'pay_periods_total' => 2,
                'pay_periods_remaining' => 2,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $head = User::factory()->create([
            'role_id' => Role::where('slug', 'department_head')->firstOrFail()->id,
            'employee_id' => $ownDept->id,
        ]);
        $finance = User::factory()->create([
            'role_id' => Role::where('slug', 'finance_officer')->firstOrFail()->id,
        ]);

        $service = app(DashboardWidgetDataService::class);

        $scoped = $service->summaries(['loans.outstanding'], $head)['loans.outstanding'];
        $this->assertSame('1000.00', $scoped['value']);
        $this->assertSame('outstanding in your department', $scoped['helper']);

        $companyWide = $service->summaries(['loans.outstanding'], $finance)['loans.outstanding'];
        $this->assertSame('5000.00', $companyWide['value']);
        $this->assertSame('outstanding across all active loans', $companyWide['helper']);
    }

    public function test_hr_widgets_follow_the_employee_department_scope(): void
    {
        $own = Employee::factory()->create(['status' => 'active']);
        $other = Employee::factory()->create(['status' => 'active']);
        $this->assertNotSame($own->department_id, $other->department_id);

        $head = User::factory()->create([
            'role_id' => Role::where('slug', 'department_head')->firstOrFail()->id,
            'employee_id' => $own->id,
        ]);

        $scalar = app(DashboardWidgetDataService::class)
            ->summaries(['hr.headcount'], $head)['hr.headcount'];
        $this->assertSame('1', $scalar['value']);
        $this->assertSame('active employees in your department', $scalar['helper']);

        $rich = app(WidgetAnalyticsService::class)
            ->payload('hr.headcount', RenderKind::Breakdown, $head);
        $this->assertSame(1, $rich['total']);
        $this->assertCount(1, $rich['segments']);

        $available = collect(app(DashboardLayoutService::class)->listAvailableWidgets($head))
            ->pluck('key');
        $this->assertFalse($available->contains('hr.on_leave_today'));
    }

    public function test_production_manager_default_includes_due_maintenance_schedules(): void
    {
        $this->seed(DashboardRoleLayoutSeeder::class);
        $manager = User::factory()->create([
            'role_id' => Role::where('slug', 'production_manager')->firstOrFail()->id,
        ]);

        $keys = collect(app(DashboardLayoutService::class)->getEffectiveLayout($manager))
            ->pluck('key');

        $this->assertTrue($keys->contains('maintenance.due_schedules'));
    }
}
