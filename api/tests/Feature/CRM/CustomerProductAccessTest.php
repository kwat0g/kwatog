<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerProductAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_staff_can_set_exact_portal_catalog_and_mode_for_customer(): void
    {
        $customer = Customer::factory()->create(['portal_catalog_mode' => 'restricted']);
        $standard = Product::factory()->create(['portal_visibility' => 'standard']);
        $restricted = Product::factory()->create(['portal_visibility' => 'restricted']);
        $staff = $this->staff();

        $this->actingAs($staff)->getJson("/api/v1/crm/customers/{$customer->hash_id}/product-access")
            ->assertOk()
            ->assertJsonPath('data.portal_catalog_mode', 'restricted');

        $response = $this->putJson("/api/v1/crm/customers/{$customer->hash_id}/product-access", [
            'portal_catalog_mode' => 'restricted',
            'product_ids' => [$restricted->hash_id],
        ]);
        $response->assertOk()
            ->assertJsonPath('data.portal_catalog_mode', 'restricted');
        $products = collect($response->json('data.products'))->keyBy('id');
        $this->assertTrue($products[$restricted->hash_id]['is_authorized']);
        $this->assertFalse($products[$standard->hash_id]['is_authorized']);

        $this->assertTrue($restricted->portalCustomers()->whereKey($customer->id)->exists());
        $this->assertFalse($standard->portalCustomers()->whereKey($customer->id)->exists());
    }

    private function staff(): User
    {
        $role = Role::create(['name' => 'Catalog Manager '.uniqid(), 'slug' => 'catalog_manager_'.uniqid()]);
        foreach (['accounting.customers.view', 'accounting.customers.manage'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'crm']);
            $role->permissions()->attach($permission->id);
        }

        return User::factory()->create(['role_id' => $role->id]);
    }
}
