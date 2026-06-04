<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
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
}
