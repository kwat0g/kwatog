<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\WarehouseService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAuditControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_zone_type_cannot_be_reclassified_while_stock_or_reservations_remain(): void
    {
        $zone = WarehouseZone::factory()->create(['zone_type' => WarehouseZoneType::RawMaterials]);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $item = Item::factory()->create();
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '0.000',
            'reserved_quantity' => '1.000',
            'weighted_avg_cost' => '0.0000',
            'lock_version' => 0,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('stock or reservations');

        app(WarehouseService::class)->updateZone($zone, [
            'zone_type' => WarehouseZoneType::Quarantine,
        ]);
    }

    public function test_zone_metadata_can_be_renamed_without_moving_stock(): void
    {
        $zone = WarehouseZone::factory()->create(['name' => 'Raw']);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $item = Item::factory()->create();
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '2.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '5.0000',
            'lock_version' => 0,
        ]);

        $updated = app(WarehouseService::class)->updateZone($zone, ['name' => 'Raw materials']);

        $this->assertSame('Raw materials', $updated->name);
        $this->assertSame('2.000', (string) StockLevel::query()->where('item_id', $item->id)->value('quantity'));
    }

    public function test_warehouse_service_can_block_a_location_for_receiving(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $location = WarehouseLocation::factory()->create(['is_blocked' => false]);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/locations/{$location->hash_id}", [
                'zone_id' => WarehouseZone::query()->findOrFail($location->zone_id)->hash_id,
                'code' => $location->code,
                'rack' => $location->rack,
                'bin' => $location->bin,
                'is_active' => true,
                'is_blocked' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_blocked', true);

        $this->assertTrue((bool) $location->fresh()->is_blocked);
    }

    public function test_stocked_location_cannot_be_deactivated(): void
    {
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        $item = Item::factory()->create();
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '1.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '1.0000',
            'lock_version' => 0,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Cannot deactivate a location with stock or reservations.');

        app(WarehouseService::class)->updateLocation($location, ['is_active' => 'false']);
    }

    public function test_location_cannot_change_zones_while_stock_remains(): void
    {
        $from = WarehouseZone::factory()->create();
        $to = WarehouseZone::factory()->create(['warehouse_id' => $from->warehouse_id]);
        $location = WarehouseLocation::factory()->create(['zone_id' => $from->id]);
        $item = Item::factory()->create();
        StockLevel::create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '1.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '1.0000',
            'lock_version' => 0,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('move a location');

        app(WarehouseService::class)->updateLocation($location, ['zone_id' => $to->id]);
    }

    public function test_soft_deleted_item_can_be_restored_through_its_restore_route(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $item = Item::factory()->create();
        $item->delete();

        $this->actingAs($admin)
            ->patchJson("/api/v1/inventory/items/{$item->hash_id}/restore")
            ->assertOk();

        $this->assertNotNull(Item::query()->find($item->id));
    }

    public function test_abc_recomputation_has_a_reachable_manage_route(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/inventory/items/recompute-abc')
            ->assertOk()
            ->assertJsonPath('data.A', 0)
            ->assertJsonPath('data.B', 0)
            ->assertJsonPath('data.C', 0);
    }
}
