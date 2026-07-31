<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseMapHashBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_bin_detail_accepts_the_hash_id_returned_by_the_map(): void
    {
        $location = WarehouseLocation::factory()->create();
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/inventory/warehouse-map/bins/{$location->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.location.id', $location->hash_id)
            ->assertJsonPath('data.location.code', $location->code);
    }
}
