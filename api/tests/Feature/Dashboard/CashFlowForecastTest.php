<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\DashboardWidgetDataService;
use App\Modules\Dashboard\Services\Analytics\CashFlowWidgetAnalytics;
use App\Modules\Dashboard\Services\FinanceDashboardService;
use App\Modules\Dashboard\Services\ForecastingDashboardService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Task 6 — cash-flow forecast on the finance dashboard, read-only.
 *
 * Moving-average engine over AR/AP (mirrors `revenueForecast`): monthly
 * inflow from issued invoices, monthly outflow from raised bills, flat
 * projection over the configured horizon. Empty books degrade to the empty
 * shape, never a divide-by-zero. The finance payload gates the panel on
 * `forecasting.view` (PanelGate strips it server-side, never CSS-hidden).
 */
class CashFlowForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'cflw+'.substr(uniqid(), -8).'@t.test',
        ]);
    }

    public function test_empty_books_return_the_empty_shape_without_dividing_by_zero(): void
    {
        $result = app(ForecastingDashboardService::class)->cashFlowForecast();

        $this->assertSame([], $result['months']);
        $this->assertSame([], $result['forecast']);
        $this->assertNull($result['confidence']);
        $this->assertSame('stable', $result['trend']);
    }

    public function test_known_ar_ap_fixture_projects_the_expected_net_series(): void
    {
        $settings = app(SettingsService::class);
        $lookback = $settings->requiredInt('forecasting.default_history_months', 1, 36);
        $horizon = $settings->requiredInt('forecasting.default_horizon_months', 1, 12);

        $customer = Customer::factory()->create(['name' => 'XX-T-'.substr(uniqid(), -5)]);
        $vendor = Vendor::factory()->create(['name' => 'XX-T-'.substr(uniqid(), -5)]);

        // Two finalized invoices last month (6000 inflow), one unpaid bill
        // this month (2000 outflow). Everything else in the window is quiet.
        $lastMonth = now()->subMonthNoOverflow();
        Invoice::factory()->create([
            'customer_id' => $customer->id, 'status' => 'finalized',
            'date' => $lastMonth->copy()->startOfMonth()->addDays(5)->toDateString(),
            'due_date' => $lastMonth->copy()->startOfMonth()->addDays(20)->toDateString(),
            'total_amount' => '4000.00', 'amount_paid' => '0.00', 'balance' => '4000.00',
        ]);
        Invoice::factory()->create([
            'customer_id' => $customer->id, 'status' => 'finalized',
            'date' => $lastMonth->copy()->startOfMonth()->addDays(10)->toDateString(),
            'due_date' => $lastMonth->copy()->startOfMonth()->addDays(25)->toDateString(),
            'total_amount' => '2000.00', 'amount_paid' => '0.00', 'balance' => '2000.00',
        ]);
        Bill::factory()->create([
            'vendor_id' => $vendor->id, 'status' => 'unpaid',
            'date' => now()->startOfMonth()->addDays(3)->toDateString(),
            'due_date' => now()->copy()->addDays(20)->toDateString(),
            'total_amount' => '2000.00', 'amount_paid' => '0.00', 'balance' => '2000.00',
        ]);
        // Cancelled paperwork is noise, not cash movement.
        Invoice::factory()->create([
            'customer_id' => $customer->id, 'status' => 'cancelled',
            'date' => $lastMonth->copy()->startOfMonth()->addDays(5)->toDateString(),
            'total_amount' => '99999.00', 'amount_paid' => '0.00', 'balance' => '0.00',
        ]);

        $result = app(ForecastingDashboardService::class)->cashFlowForecast();

        $expectedInflow = round(6000 / $lookback, 2);
        $expectedOutflow = round(2000 / $lookback, 2);

        $this->assertCount($horizon, $result['months']);
        foreach ($result['months'] as $month) {
            $this->assertSame($expectedInflow, $month['inflow']);
            $this->assertSame($expectedOutflow, $month['outflow']);
            $this->assertSame(round($expectedInflow - $expectedOutflow, 2), $month['net']);
            $this->assertArrayHasKey('confidence', $month);
        }
        $this->assertIsFloat($result['confidence']);
        $this->assertGreaterThanOrEqual(0, $result['confidence']);
        $this->assertLessThanOrEqual(100, $result['confidence']);
        $this->assertContains($result['trend'], ['up', 'down', 'stable']);
    }

    public function test_finance_payload_strips_cash_flow_without_the_forecasting_grant(): void
    {
        $role = Role::create([
            'name' => 'CF Role '.substr(uniqid(), -6),
            'slug' => 'cflw-'.substr(uniqid(), -6),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            \App\Modules\Auth\Models\Permission::query()
                ->whereIn('slug', ['accounting.dashboard.view'])->pluck('id')->all(),
        );
        $user = User::factory()->create([
            'role_id' => $role->id,
            'email' => 'cflw+'.substr(uniqid(), -8).'@t.test',
        ]);

        $panels = app(FinanceDashboardService::class)->summary($user);

        $this->assertArrayNotHasKey('cash_flow_forecast', $panels);
        // Its own domain panels are untouched.
        $this->assertArrayHasKey('revenue_forecast', $panels);
    }

    public function test_finance_payload_carries_cash_flow_with_the_grant_and_widget_paths_agree(): void
    {
        $panels = app(FinanceDashboardService::class)->summary($this->userWithRole('finance_officer'));

        $this->assertArrayHasKey('cash_flow_forecast', $panels);
        $this->assertArrayHasKey('months', $panels['cash_flow_forecast']);

        $scalar = app(DashboardWidgetDataService::class)
            ->summaries(['forecast.cash_flow'], $this->userWithRole('finance_officer'));

        $this->assertSame('currency', $scalar['forecast.cash_flow']['kind']);

        $rich = app(CashFlowWidgetAnalytics::class)
            ->payload('forecast.cash_flow', $this->userWithRole('finance_officer'));

        // Empty books: the rich path degrades to the scalar, never an axis
        // with no points behind it.
        $this->assertSame([], $rich);
    }
}
