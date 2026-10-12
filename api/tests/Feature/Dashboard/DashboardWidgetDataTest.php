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
use App\Modules\Inventory\Models\GoodsReceiptNote;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
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

    public function test_warehouse_qc_handoff_widget_counts_retryable_pending_grns_and_is_in_default_layout(): void
    {
        foreach ([
            ['pending_qc', 'manual_required'],
            ['pending_qc', 'not_started'],
            ['pending_qc', 'generated'],
            ['pending_qc', 'not_required'],
            ['accepted', 'manual_required'],
        ] as [$status, $handoff]) {
            GoodsReceiptNote::factory()->create([
                'status' => $status,
                'incoming_qc_handoff_status' => $handoff,
            ]);
        }

        $summary = app(DashboardWidgetDataService::class)
            ->summaries(['inventory.qc_handoff_issues'], User::factory()->create())['inventory.qc_handoff_issues'];

        $this->assertSame('2', $summary['value']);
        $this->assertSame('pending QC handoffs needing retry', $summary['helper']);

        $this->seed(DashboardRoleLayoutSeeder::class);
        $warehouse = User::factory()->create([
            'role_id' => Role::where('slug', 'warehouse_staff')->firstOrFail()->id,
        ]);
        $keys = collect(app(DashboardLayoutService::class)->getEffectiveLayout($warehouse))->pluck('key');
        $this->assertTrue($keys->contains('inventory.qc_handoff_issues'));
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

    public function test_summary_and_rich_widget_caches_reuse_only_the_callers_data(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $summaryKey = "dashboard:widget:summary:{$firstUser->id}:production.active_wo";
        $richKey = "dashboard:widget:rich:{$firstUser->id}:breakdown:production.wo_breakdown";
        Cache::forget($summaryKey);
        Cache::forget($richKey);

        $workOrderQueries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$workOrderQueries): void {
            if (str_contains(strtolower($query->sql), 'work_orders')) {
                $workOrderQueries++;
            }
        });

        $widgetData = app(DashboardWidgetDataService::class);
        $analytics = app(WidgetAnalyticsService::class);
        $widgetData->summaries(['production.active_wo'], $firstUser);
        $queriesAfterFirstSummary = $workOrderQueries;
        $widgetData->summaries(['production.active_wo'], $firstUser);
        $this->assertSame($queriesAfterFirstSummary, $workOrderQueries);

        $analytics->payload('production.wo_breakdown', RenderKind::Breakdown, $firstUser);
        $queriesAfterFirstPayload = $workOrderQueries;
        $analytics->payload('production.wo_breakdown', RenderKind::Breakdown, $firstUser);
        $this->assertSame($queriesAfterFirstPayload, $workOrderQueries);

        $widgetData->summaries(['production.active_wo'], $secondUser);
        $analytics->payload('production.wo_breakdown', RenderKind::Breakdown, $secondUser);
        $this->assertGreaterThan($queriesAfterFirstPayload, $workOrderQueries);
        $this->assertTrue(Cache::has("dashboard:widget:summary:{$secondUser->id}:production.active_wo"));
        $this->assertTrue(Cache::has("dashboard:widget:rich:{$secondUser->id}:breakdown:production.wo_breakdown"));
    }

    public function test_cash_position_uses_configured_account_codes_only(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('accounting.accounts.cash_code', 'WGT1000');
        $settings->set('accounting.accounts.payroll_cash_code', 'WGT1001');
        $settings->set('accounting.accounts.asset_cash_code', null);
        $now = now();
        $accounts = [];
        foreach ([
            ['WGT1000', 'Operating bank', 'asset'],
            ['WGT1001', 'Payroll bank', 'asset'],
            ['WGT9999', 'Petty Cash', 'asset'],
            ['WGT2000', 'Payables', 'liability'],
        ] as [$code, $name, $type]) {
            $accounts[$code] = DB::table('accounts')->insertGetId([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'normal_balance' => $type === 'asset' ? 'debit' : 'credit',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $entryId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'JE-CASH-WIDGET-TEST',
            'date' => $now->toDateString(),
            'total_debit' => 80,
            'total_credit' => 80,
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([['WGT1000', 10, 0], ['WGT1001', 20, 0], ['WGT9999', 50, 0], ['WGT2000', 0, 80]] as [$code, $debit, $credit]) {
            DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $entryId,
                'account_id' => $accounts[$code],
                'line_no' => count($accounts),
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }
        DB::table('journal_entries')->where('id', $entryId)->update([
            'status' => 'posted',
            'posted_at' => $now,
        ]);

        $user = User::factory()->create();
        Cache::forget("dashboard:widget:summary:{$user->id}:finance.cash_position");
        $summary = app(DashboardWidgetDataService::class)
            ->summaries(['finance.cash_position'], $user)['finance.cash_position'];

        $this->assertSame('30.00', $summary['value']);
    }
}
