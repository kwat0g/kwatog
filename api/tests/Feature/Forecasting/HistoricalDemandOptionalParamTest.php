<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: GET /forecasting/demand-forecasts/historical without `months_back`.
 *
 * `months_back` is validated as nullable, so it is absent from the validated
 * array when the SPA omits it (spa/src/api/forecasting.ts types it optional).
 * The controller read `$data['months_back'] ?: 12` — `?:` still evaluates the
 * left operand, so an absent key raised "Undefined array key" → HTTP 500.
 */
class HistoricalDemandOptionalParamTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_historical_demand_defaults_months_back_when_omitted(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())
            ->getJson('/api/v1/forecasting/demand-forecasts/historical'
                .'?product_id='.$product->hash_id)
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_historical_demand_honours_explicit_months_back(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())
            ->getJson('/api/v1/forecasting/demand-forecasts/historical'
                .'?product_id='.$product->hash_id.'&months_back=6')
            ->assertOk();
    }

    public function test_unknown_product_hash_is_404_not_500(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/api/v1/forecasting/demand-forecasts/historical?product_id=not-a-real-id')
            ->assertStatus(404);
    }
}
