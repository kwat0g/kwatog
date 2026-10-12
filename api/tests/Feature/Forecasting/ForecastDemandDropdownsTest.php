<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Demand-page dropdowns must load for every forecasting.view holder without
 * requiring full CRM/accounting list reads.
 *
 * Regression: /forecasting/demand fired GET /crm/products + GET /customers
 * on load, so ppc_head (no accounting.customers.view) and production /
 * purchasing / warehouse (neither read) toasted "You do not have permission
 * to perform this action." on their own page. The page now uses scoped
 * option endpoints gated on forecasting.view. Granting the full lists was
 * rejected: WorkOrderFormOptionsTest pins production_manager to NO
 * crm.products.view (cost-bearing full read), so the page must not need it.
 */
class ForecastDemandDropdownsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    public static function forecastViewers(): array
    {
        return [
            'ppc_head' => ['ppc_head'],
            'production_manager' => ['production_manager'],
            'purchasing_officer' => ['purchasing_officer'],
            'warehouse_staff' => ['warehouse_staff'],
            'finance_officer' => ['finance_officer'],
        ];
    }

    #[DataProvider('forecastViewers')]
    public function test_every_forecast_viewer_loads_both_demand_dropdowns(string $slug): void
    {
        $product = Product::factory()->create(['include_forecast_in_mrp' => true]);
        Customer::factory()->create();

        $user = $this->userWithRole($slug);

        $products = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/crm/products/options?per_page=200')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($products);
        $row = $products[0];
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('part_number', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('include_forecast_in_mrp', $row);
        // Cost-bearing full-read fields must never leak into the dropdown.
        $this->assertArrayNotHasKey('standard_cost', $row);
        $this->assertArrayNotHasKey('base_selling_price', $row);

        $this->assertSame($product->hash_id, collect($products)->firstWhere('part_number', $product->part_number)['id']);

        $customers = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/forecasting/customers/options')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($customers);
        $this->assertArrayHasKey('id', $customers[0]);
        $this->assertArrayHasKey('name', $customers[0]);
        // Credit/TIN payload of the full customer read must not leak here.
        $this->assertArrayNotHasKey('credit_limit', $customers[0]);
        $this->assertArrayNotHasKey('tin', $customers[0]);
    }

    public function test_demand_dropdowns_stay_forbidden_without_the_forecasting_read(): void
    {
        $user = $this->userWithRole('employee');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/crm/products/options')
            ->assertForbidden();
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/forecasting/customers/options')
            ->assertForbidden();
    }

    public function test_full_list_boundaries_are_unchanged(): void
    {
        // Pins the WorkOrderFormOptionsTest boundary from this side: the page
        // fix must not have smuggled full reads onto these roles.
        $manager = $this->userWithRole('production_manager');
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/crm/products')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/customers')->assertForbidden();

        $ppc = $this->userWithRole('ppc_head');
        $this->actingAs($ppc, 'sanctum')->getJson('/api/v1/crm/products')->assertOk();
        $this->actingAs($ppc, 'sanctum')->getJson('/api/v1/customers')->assertForbidden();
    }
}
