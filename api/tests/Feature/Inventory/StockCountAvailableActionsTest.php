<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountAvailableActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_count_responses_project_actions_for_the_current_user_and_state(): void
    {
        $managerRole = Role::firstOrCreate(['slug' => 'stock_count_actions_manager'], ['name' => 'Stock Count Actions Manager']);
        foreach (['inventory.stock_count.view', 'inventory.stock_count.manage'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'inventory']);
            $managerRole->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $manager = User::factory()->create(['role_id' => $managerRole->id, 'is_active' => true]);

        $warehouse = Warehouse::factory()->create();
        $zone = WarehouseZone::factory()->create(['warehouse_id' => $warehouse->id]);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $item = Item::factory()->create(['is_active' => true]);
        StockLevel::factory()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '1.000',
        ]);

        $created = $this->actingAs($manager)->postJson('/api/v1/inventory/stock-counts', [
            'title' => 'Action projection test',
            'scope' => 'zone',
            'warehouse_id' => $warehouse->hash_id,
            'zone_id' => $zone->hash_id,
        ])->assertCreated();
        $created->assertJsonPath('data.available_actions', ['start', 'cancel']);

        $sessionId = $created->json('data.id');
        $this->actingAs($manager)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/start")
            ->assertOk()
            ->assertJsonPath('data.available_actions', ['cancel']);

        $detail = $this->actingAs($manager)->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk();
        $detail->assertJsonPath('data.available_actions', ['cancel']);
        $detail->assertJsonPath('data.items.0.available_actions', ['record_count']);
        $itemId = $detail->json('data.items.0.id');

        $this->actingAs($manager)
            ->postJson("/api/v1/inventory/stock-counts/items/{$itemId}/count", [
                'counted_quantity' => '2.000',
            ])
            ->assertOk()
            ->assertJsonPath('data.available_actions', ['record_count']);

        $checker = User::factory()->create(['role_id' => $managerRole->id, 'is_active' => true]);
        $checkerDetail = $this->actingAs($checker)->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk();
        $checkerDetail->assertJsonPath('data.items.0.available_actions', ['record_count', 'approve_variance']);

        $viewerRole = Role::firstOrCreate(['slug' => 'stock_count_actions_viewer'], ['name' => 'Stock Count Actions Viewer']);
        $viewPermission = Permission::firstOrCreate(
            ['slug' => 'inventory.stock_count.view'],
            ['name' => 'inventory.stock_count.view', 'module' => 'inventory'],
        );
        $viewerRole->permissions()->syncWithoutDetaching([$viewPermission->id]);
        $viewer = User::factory()->create(['role_id' => $viewerRole->id, 'is_active' => true]);

        $viewerDetail = $this->actingAs($viewer)->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk();
        $viewerDetail->assertJsonPath('data.available_actions', []);
        $viewerDetail->assertJsonPath('data.items.0.available_actions', []);
    }
}
