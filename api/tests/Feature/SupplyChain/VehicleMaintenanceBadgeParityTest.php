<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Models\Vehicle;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VehicleMaintenanceBadgeParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_maintenance_badge_matches_fleet_filter_and_excludes_retired_and_archived_vehicles(): void
    {
        $this->vehicle('FLT-001', 'maintenance');
        $this->vehicle('FLT-002', 'retired');
        $archived = $this->vehicle('FLT-003', 'maintenance');
        $archived->delete();

        $viewer = $this->userWithPermission('supply_chain.view');
        $badges = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $fleet = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/supply-chain/vehicles?status=maintenance')
            ->assertOk();

        $this->assertSame(1, $badges['vehicles_maintenance']['count']);
        $this->assertSame($badges['vehicles_maintenance']['count'], $fleet->json('meta.total'));

        $employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
        $employeeBadges = $this->actingAs($employee, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
        $this->assertArrayNotHasKey('vehicles_maintenance', $employeeBadges);
        $this->getJson('/api/v1/supply-chain/vehicles?status=maintenance')->assertForbidden();
    }

    private function userWithPermission(string $permission): User
    {
        $role = Role::query()->create([
            'name' => 'Test supply chain viewer',
            'slug' => 'test_supply_chain_viewer',
            'description' => 'Test role',
            'is_system' => false,
        ]);
        $role->permissions()->sync([DB::table('permissions')->where('slug', $permission)->value('id')]);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function vehicle(string $plate, string $status): Vehicle
    {
        return Vehicle::query()->create([
            'plate_number' => $plate,
            'name' => $plate,
            'vehicle_type' => 'truck',
            'status' => $status,
        ]);
    }
}
