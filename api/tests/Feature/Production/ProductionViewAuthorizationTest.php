<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionViewAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_granular_work_order_read_does_not_bypass_the_production_module_gate(): void
    {
        $role = Role::create([
            'name' => 'Production Work Order Reader',
            'slug' => 'production-work-order-reader',
            'is_system' => false,
        ]);
        $workOrderView = Permission::create([
            'name' => 'View Work Orders',
            'slug' => 'production.work_orders.view',
            'module' => 'production',
        ]);
        $role->permissions()->attach($workOrderView);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/production/work-orders')
            ->assertForbidden();
    }
}
