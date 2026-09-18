<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseRoleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_purchasing_cannot_open_warehouse_execution_surfaces(): void
    {
        $purchasing = $this->userWithRole('purchasing_officer');

        foreach ([
            '/api/v1/inventory/material-issues',
            '/api/v1/inventory/stock-adjustments',
            '/api/v1/inventory/warehouse-map',
            '/api/v1/inventory/transfer-orders',
        ] as $path) {
            $this->actingAs($purchasing)->getJson($path)->assertForbidden();
        }
    }

    public function test_warehouse_and_finance_can_open_their_operational_queues(): void
    {
        $warehouse = $this->userWithRole('warehouse_staff');
        $finance = $this->userWithRole('finance_officer');

        foreach ([
            '/api/v1/inventory/material-issues',
            '/api/v1/inventory/stock-adjustments',
            '/api/v1/inventory/warehouse-map',
            '/api/v1/inventory/transfer-orders',
        ] as $path) {
            $this->actingAs($warehouse)->getJson($path)->assertOk();
        }

        $this->actingAs($finance)
            ->getJson('/api/v1/inventory/stock-adjustments?status=pending')
            ->assertOk();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);
    }
}
