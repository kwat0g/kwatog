<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\Forecasting\Services\ForecastMrpService;
use App\Modules\MRP\Services\BomService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class ForecastMrpToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->value('id'),
        ]);
    }

    public function test_manager_can_change_product_mrp_inclusion(): void
    {
        $product = Product::factory()->create(['include_forecast_in_mrp' => false]);

        $this->actingAs($this->user('system_admin'))
            ->patchJson("/api/v1/forecasting/products/{$product->hash_id}/mrp-inclusion", [
                'include_forecast_in_mrp' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $product->hash_id)
            ->assertJsonPath('data.include_forecast_in_mrp', true);

        $this->assertTrue($product->refresh()->include_forecast_in_mrp);
    }

    public function test_employee_cannot_change_product_mrp_inclusion(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->user('employee'))
            ->patchJson("/api/v1/forecasting/products/{$product->hash_id}/mrp-inclusion", [
                'include_forecast_in_mrp' => true,
            ])
            ->assertForbidden();
    }

    public function test_projection_only_explodes_opted_in_active_products(): void
    {
        $included = Product::factory()->create(['include_forecast_in_mrp' => true]);
        $excluded = Product::factory()->create(['include_forecast_in_mrp' => false]);
        $inactive = Product::factory()->create([
            'include_forecast_in_mrp' => true,
            'is_active' => false,
        ]);

        foreach ([$included, $excluded, $inactive] as $product) {
            DemandForecast::factory()->create([
                'product_id' => $product->id,
                'forecast_year' => 2026,
                'forecast_month' => 8,
                'forecasted_quantity' => 100,
            ]);
        }

        $bom = Mockery::mock(BomService::class);
        $bom->shouldReceive('explode')
            ->once()
            ->with($included->id, 100.0)
            ->andReturn(new Collection());

        $result = (new ForecastMrpService($bom))->project(2026, 8);

        $this->assertCount(1, $result['products']);
        $this->assertSame($included->hash_id, $result['products'][0]['product_id']);
        $this->assertSame('100.00', $result['products'][0]['forecasted_quantity']);
        $this->assertFalse($result['products'][0]['has_bom']);
    }
}
