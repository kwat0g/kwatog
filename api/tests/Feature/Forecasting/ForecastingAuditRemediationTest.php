<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Forecasting\Services\ForecastingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ForecastingAuditRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    public function test_invalid_hash_filters_fail_closed_in_forecast_list(): void
    {
        DemandForecast::factory()->create();

        $this->actingAs($this->admin())
            ->getJson('/api/v1/forecasting/demand-forecasts?product_id=not-a-hash')
            ->assertNotFound();

        $this->actingAs($this->admin())
            ->getJson('/api/v1/forecasting/demand-forecasts?customer_id=not-a-hash')
            ->assertNotFound();
    }

    public function test_invalid_optional_customer_hash_does_not_become_total_scope(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())
            ->getJson('/api/v1/forecasting/demand-forecasts/historical'
                .'?product_id='.$product->hash_id.'&customer_id=not-a-hash')
            ->assertNotFound();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/forecasting/demand-forecasts/recompute', [
                'product_id' => $product->hash_id,
                'customer_id' => 'not-a-hash',
                'method' => 'moving_avg',
                'horizon_months' => 1,
                'lookback_months' => 3,
            ])
            ->assertNotFound();
    }

    public function test_invalid_customer_hash_cannot_be_written_as_a_total_manual_forecast(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())
            ->postJson('/api/v1/forecasting/demand-forecasts/manual', [
                'product_id' => $product->hash_id,
                'customer_id' => 'not-a-hash',
                'forecast_year' => 2027,
                'forecast_month' => 1,
                'forecasted_quantity' => '10.00',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('demand_forecasts', 0);
    }

    public function test_options_only_expose_methods_accepted_by_recompute(): void
    {
        $methods = $this->actingAs($this->admin())
            ->getJson('/api/v1/forecasting/demand-forecasts/options')
            ->assertOk()
            ->json('data.methods');

        $this->assertSame(['moving_avg', 'weighted_avg'], array_column($methods, 'value'));
    }

    public function test_scheduled_generation_writes_the_next_horizon_without_manual_overwrite(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        $this->artisan('forecasting:generate', [
            '--horizon' => 1,
            '--lookback' => 3,
        ])
            ->expectsOutputToContain('Generated 1 demand forecast rows.')
            ->assertExitCode(0);

        $next = now()->startOfMonth()->addMonthNoOverflow();
        $this->assertDatabaseHas('demand_forecasts', [
            'product_id' => $product->id,
            'customer_id' => null,
            'forecast_year' => $next->year,
            'forecast_month' => $next->month,
            'method' => DemandForecast::METHOD_MOVING_AVG,
        ]);
    }

    public function test_scheduled_generation_rejects_manual_method(): void
    {
        $this->artisan('forecasting:generate', ['--method' => DemandForecast::METHOD_MANUAL])
            ->expectsOutputToContain('Method must be moving_avg or weighted_avg.')
            ->assertExitCode(1);
    }

    public function test_reconciliation_rolls_back_when_a_batch_row_fails(): void
    {
        $product = Product::factory()->create();
        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'forecast_year' => 2025,
            'forecast_month' => 1,
            'forecasted_quantity' => '10.00',
            'actual_quantity' => null,
            'variance' => null,
        ]);
        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'forecast_year' => 2025,
            'forecast_month' => 2,
            'forecasted_quantity' => '20.00',
            'actual_quantity' => null,
            'variance' => null,
        ]);

        $service = Mockery::mock(ForecastingService::class)->makePartial();
        $service->shouldReceive('historicalDemand')
            ->once()
            ->andReturn([['year' => 2025, 'month' => 1, 'qty' => '0.00']]);
        $service->shouldReceive('historicalDemand')
            ->once()
            ->andThrow(new \RuntimeException('history unavailable'));

        try {
            $service->reconcileActuals();
            $this->fail('Expected reconciliation to surface the failed row.');
        } catch (\RuntimeException $e) {
            $this->assertSame('history unavailable', $e->getMessage());
        }

        $this->assertDatabaseHas('demand_forecasts', [
            'forecast_year' => 2025,
            'forecast_month' => 1,
            'actual_quantity' => null,
            'variance' => null,
        ]);
        $this->assertDatabaseHas('demand_forecasts', [
            'forecast_year' => 2025,
            'forecast_month' => 2,
            'actual_quantity' => null,
            'variance' => null,
        ]);
    }

    public function test_accuracy_uses_one_authoritative_scope_per_product_month(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();

        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => null,
            'forecast_year' => 2026,
            'forecast_month' => 1,
            'forecasted_quantity' => '100.00',
            'actual_quantity' => '100.00',
        ]);
        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => $customerA->id,
            'forecast_year' => 2026,
            'forecast_month' => 1,
            'forecasted_quantity' => '60.00',
            'actual_quantity' => '60.00',
        ]);
        DemandForecast::factory()->create([
            'product_id' => $product->id,
            'customer_id' => $customerB->id,
            'forecast_year' => 2026,
            'forecast_month' => 1,
            'forecasted_quantity' => '40.00',
            'actual_quantity' => '40.00',
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/forecasting/accuracy/summary?year=2026')
            ->assertOk()
            ->assertJsonPath('data.periods_evaluated', 1)
            ->assertJsonCount(1, 'data.monthly');

        $this->actingAs($this->admin())
            ->getJson('/api/v1/forecasting/accuracy/products?year=2026')
            ->assertOk()
            ->assertJsonPath('data.0.periods_evaluated', 1);
    }
}
