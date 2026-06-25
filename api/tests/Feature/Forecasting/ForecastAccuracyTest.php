<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Forecasting\Models\DemandForecast;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function makeEmployee(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'employee')->value('id'),
        ]);
    }

    public function test_accuracy_endpoint_returns_mape(): void
    {
        DemandForecast::factory()->createMany([
            ['forecast_year' => 2026, 'forecast_month' => 1, 'forecasted_quantity' => 100, 'actual_quantity' => 90,  'variance' => -10],
            ['forecast_year' => 2026, 'forecast_month' => 2, 'forecasted_quantity' => 100, 'actual_quantity' => 110, 'variance' => 10],
            ['forecast_year' => 2026, 'forecast_month' => 3, 'forecasted_quantity' => 100, 'actual_quantity' => 100, 'variance' => 0],
        ]);

        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/forecasting/accuracy?year=2026')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['mape', 'bias', 'periods_evaluated', 'monthly']]);
    }

    public function test_accuracy_returns_nulls_when_no_data(): void
    {
        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/forecasting/accuracy?year=2099')
            ->assertStatus(200)
            ->assertJsonPath('data.mape', null)
            ->assertJsonPath('data.periods_evaluated', 0);
    }

    /* ─── ForecastAccuracyController tests ─── */

    public function test_accuracy_summary_returns_mape_and_bias(): void
    {
        DemandForecast::factory()->createMany([
            ['forecast_year' => 2026, 'forecast_month' => 1, 'forecasted_quantity' => 100, 'actual_quantity' => 90,  'variance' => -10],
            ['forecast_year' => 2026, 'forecast_month' => 2, 'forecasted_quantity' => 80,  'actual_quantity' => 100, 'variance' => 20],
        ]);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/forecasting/accuracy/summary?year=2026')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['mape', 'bias', 'periods_evaluated', 'monthly']]);

        $data = $response->json('data');
        $this->assertGreaterThan(0, $data['mape']);
        $this->assertEquals(2, $data['periods_evaluated']);
        $this->assertCount(2, $data['monthly']);
    }

    public function test_accuracy_by_product_filters_active_products(): void
    {
        $active = Product::factory()->create(['is_active' => true]);
        $inactive = Product::factory()->create(['is_active' => false]);

        DemandForecast::factory()->create([
            'product_id'          => $active->id,
            'forecast_year'       => 2026,
            'forecast_month'      => 1,
            'forecasted_quantity' => 100,
            'actual_quantity'     => 90,
            'variance'            => -10,
        ]);
        DemandForecast::factory()->create([
            'product_id'          => $inactive->id,
            'forecast_year'       => 2026,
            'forecast_month'      => 1,
            'forecasted_quantity' => 100,
            'actual_quantity'     => 80,
            'variance'            => -20,
        ]);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/forecasting/accuracy/products?year=2026')
            ->assertStatus(200);

        $products = $response->json('data');
        // Only the active product should appear
        $this->assertCount(1, $products);
        $this->assertEquals($active->hash_id, $products[0]['product_id']);
    }

    public function test_reconcile_actuals_command_runs_successfully(): void
    {
        // Create a forecast for a past month without actuals
        DemandForecast::factory()->create([
            'forecast_year'       => 2025,
            'forecast_month'      => 1,
            'forecasted_quantity' => 100,
            'actual_quantity'     => null,
            'variance'            => null,
        ]);

        $this->artisan('forecasting:reconcile-actuals')
            ->assertExitCode(0)
            ->expectsOutputToContain('Reconciled');
    }

    public function test_accuracy_summary_requires_permission(): void
    {
        $this->actingAs($this->makeEmployee())
            ->getJson('/api/v1/forecasting/accuracy/summary?year=2026')
            ->assertStatus(403);
    }

    public function test_accuracy_by_product_requires_permission(): void
    {
        $this->actingAs($this->makeEmployee())
            ->getJson('/api/v1/forecasting/accuracy/products?year=2026')
            ->assertStatus(403);
    }

    public function test_accuracy_summary_defaults_to_current_year(): void
    {
        DemandForecast::factory()->create([
            'forecast_year'       => (int) now()->year,
            'forecast_month'      => 1,
            'forecasted_quantity' => 100,
            'actual_quantity'     => 95,
            'variance'            => -5,
        ]);

        $response = $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/forecasting/accuracy/summary')
            ->assertStatus(200);

        $this->assertEquals(1, $response->json('data.periods_evaluated'));
    }
}
