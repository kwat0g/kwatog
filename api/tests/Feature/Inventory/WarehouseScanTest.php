<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Services\BarcodeScanResolverService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseScanEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_warehouse_user_can_resolve_item_and_bin_barcodes(): void
    {
        $role = Role::query()->create(['name' => 'Scanner', 'slug' => 'scanner-test']);
        $permission = Permission::query()->create(['name' => 'Inventory view', 'slug' => 'inventory.view', 'module' => 'inventory']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['role_id' => $role->id]);
        $item = Item::factory()->create(['code' => 'RESIN-001']);
        $location = WarehouseLocation::factory()->create(['code' => 'BIN-A-01']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/scan/resolve', ['barcode' => 'resin-001'])
            ->assertOk()->assertJsonPath('data.type', 'item')
            ->assertJsonPath('data.entity.id', $item->hash_id)
            ->assertJsonPath('data.suggested_actions.0.href', "/inventory/items/{$item->hash_id}");

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/scan/resolve', ['barcode' => 'bin-a-01'])
            ->assertOk()->assertJsonPath('data.type', 'warehouse_location')
            ->assertJsonPath('data.entity.code', $location->code);

        $this->assertDatabaseHas('warehouse_scan_events', [
            'user_id' => $user->id,
            'barcode' => 'RESIN-001',
            'result_type' => 'item',
            'is_recognized' => true,
        ]);
        $this->assertSame(2, WarehouseScanEvent::query()->where('user_id', $user->id)->count());
    }

    public function test_unrecognized_scan_is_recorded_with_context(): void
    {
        $role = Role::query()->create(['name' => 'Scanner', 'slug' => 'scanner-unknown-test']);
        $permission = Permission::query()->create(['name' => 'Inventory view', 'slug' => 'inventory.view', 'module' => 'inventory']);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/scan/resolve', [
            'barcode' => ' unknown-999 ',
            'context' => ['grn_id' => 'grn01'],
        ])->assertOk()->assertJsonPath('data.type', 'unknown');

        $event = WarehouseScanEvent::query()->sole();
        $this->assertSame('UNKNOWN-999', $event->barcode);
        $this->assertFalse($event->is_recognized);
        $this->assertSame(['grn_id' => 'grn01'], $event->context);
    }

    public function test_item_scan_includes_stock_count_and_material_issue_actions(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create(['code' => 'CONTEXT-ITEM']);
        $location = WarehouseLocation::factory()->create();
        $session = StockCountSession::query()->create([
            'session_number' => 'SC-CONTEXT-001',
            'title' => 'Context regression',
            'created_by' => $user->id,
        ]);
        $countItem = StockCountItem::query()->create([
            'session_id' => $session->id,
            'location_id' => $location->id,
            'item_id' => $item->id,
            'status' => 'pending',
        ]);

        $result = app(BarcodeScanResolverService::class)->resolve($item->code, [
            'stock_count_session_id' => $session->id,
            'material_issue_id' => 42,
        ]);

        $this->assertSame('item', $result['type']);
        $this->assertContains([
            'action' => 'record_count',
            'label' => 'Record cycle count',
            'params' => ['stock_count_item_id' => $countItem->id],
        ], $result['suggested_actions']);
        $this->assertContains([
            'action' => 'pick_for_issue',
            'label' => 'Open material issue picking',
            'params' => ['material_issue_id' => 42, 'item_id' => $item->hash_id],
        ], $result['suggested_actions']);
    }
}
