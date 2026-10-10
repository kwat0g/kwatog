<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseScanEvent;
use App\Modules\Inventory\Services\BarcodeScanResolverService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_records_require_workflow_permission_and_filter_eligible_numbers(): void
    {
        $role = Role::query()->create(['name' => 'Context scanner', 'slug' => 'context-scanner-test']);
        $view = Permission::query()->create(['name' => 'Inventory view', 'slug' => 'inventory.view', 'module' => 'inventory']);
        $manage = Permission::query()->create(['name' => 'Count management', 'slug' => 'inventory.stock_count.manage', 'module' => 'inventory']);
        $role->permissions()->attach($view);
        $user = User::factory()->create(['role_id' => $role->id]);
        $eligible = StockCountSession::query()->create([
            'session_number' => 'SC-SEARCH-001', 'title' => 'Active count', 'created_by' => $user->id, 'status' => 'in_progress',
        ]);
        StockCountSession::query()->create([
            'session_number' => 'SC-SEARCH-002', 'title' => 'Finished count', 'created_by' => $user->id, 'status' => 'completed',
        ]);
        $url = '/api/v1/inventory/scan/records?context=stock_count_session_id&search=SC-SEARCH';
        $this->actingAs($user, 'sanctum')->getJson($url)->assertForbidden();
        $role->permissions()->attach($manage);
        $user->unsetRelation('role');
        $user->flushPermissionsCache();
        $this->actingAs($user, 'sanctum')->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', $eligible->hash_id)->assertJsonPath('data.0.label', 'SC-SEARCH-001');
        $this->getJson('/api/v1/inventory/scan/records?context=stock_count_session_id&search=NO-MATCH')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/scan/records?context=invalid')->assertUnprocessable();
    }

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
            ->assertJsonPath('data.entity.code', $location->code)
            ->assertJsonCount(0, 'data.suggested_actions');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/scan/resolve', [
            'barcode' => $item->code, 'context' => ['material_issue_id' => app('hashids')->encode(42)],
        ])->assertForbidden();

        $po = PurchaseOrder::factory()->create(['status' => 'approved']);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/scan/resolve', ['barcode' => $po->po_number])
            ->assertForbidden();

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
            'context' => [],
        ])->assertOk()->assertJsonPath('data.type', 'unknown');

        $event = WarehouseScanEvent::query()->sole();
        $this->assertSame('UNKNOWN-999', $event->barcode);
        $this->assertFalse($event->is_recognized);
        $this->assertNull($event->context);
    }

    public function test_count_action_link_identifies_its_session_and_line(): void
    {
        $role = Role::query()->create(['name' => 'Count scanner', 'slug' => 'count-scanner-test']);
        $permission = Permission::query()->create(['name' => 'Inventory view', 'slug' => 'inventory.view', 'module' => 'inventory']);
        $role->permissions()->attach($permission);
        $manage = Permission::query()->create(['name' => 'Count manage', 'slug' => 'inventory.stock_count.manage', 'module' => 'inventory']);
        $role->permissions()->attach($manage);
        $user = User::factory()->create(['role_id' => $role->id]);
        $item = Item::factory()->create(['code' => 'COUNT-LINK-ITEM']);
        $location = WarehouseLocation::factory()->create();
        $session = StockCountSession::query()->create([
            'session_number' => 'SC-LINK-001', 'title' => 'Scanner link', 'created_by' => $user->id, 'status' => 'in_progress',
        ]);
        $line = StockCountItem::query()->create([
            'session_id' => $session->id, 'location_id' => $location->id, 'item_id' => $item->id, 'status' => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/inventory/scan/resolve', [
            'barcode' => $item->code, 'context' => ['stock_count_session_id' => $session->hash_id],
        ])->assertOk()->assertJsonPath(
            'data.suggested_actions.0.href',
            "/inventory/stock-count?session_id={$session->hash_id}&count_item_id={$line->hash_id}",
        );
    }
}
