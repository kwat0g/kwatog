<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemUomStockIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_uom_cannot_change_while_stock_exists_without_movement_history(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $location = WarehouseLocation::factory()->create();
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '5.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '10.0000',
            'lock_version' => 0,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/items/{$item->hash_id}", ['unit_of_measure' => 'BOX'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change the base unit while stock is on hand or reserved, or after a movement, count, open purchasing document, BOM, or UOM conversion uses the item.');

        $this->assertSame('PCS', $item->fresh()->unit_of_measure);
        $this->assertSame('5.000', (string) $item->stockLevels()->value('quantity'));
    }

    public function test_base_uom_cannot_change_after_an_open_stock_count_snapshots_it(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $location = WarehouseLocation::factory()->create(['is_active' => true]);
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '0.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '10.0000',
            'lock_version' => 0,
        ]);

        $sessionId = $this->actingAs($admin)
            ->postJson('/api/v1/inventory/stock-counts', [
                'title' => 'UOM snapshot integrity',
                'scope' => 'zone',
                'zone_id' => $location->zone->hash_id,
            ])
            ->assertCreated()
            ->json('data.id');
        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/stock-counts/{$sessionId}/start")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.items.0.item.unit_of_measure', 'PCS');

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/items/{$item->hash_id}", ['unit_of_measure' => 'BOX'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change the base unit while stock is on hand or reserved, or after a movement, count, open purchasing document, BOM, or UOM conversion uses the item.');

        $this->assertSame('PCS', $item->fresh()->unit_of_measure);
        $this->actingAs($admin)
            ->getJson("/api/v1/inventory/stock-counts/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.items.0.item.unit_of_measure', 'PCS');
    }

    public function test_base_uom_cannot_change_while_conversion_rows_use_it_as_the_base(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        ItemUomConversion::query()->create([
            'item_id' => $item->id,
            'from_uom_id' => Uom::query()->where('code', 'BOX')->value('id'),
            'to_uom_id' => Uom::query()->where('code', 'PCS')->value('id'),
            'factor' => '12.000000',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/items/{$item->hash_id}", ['unit_of_measure' => 'BOX'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change the base unit while stock is on hand or reserved, or after a movement, count, open purchasing document, BOM, or UOM conversion uses the item.');

        $this->assertSame('PCS', $item->fresh()->unit_of_measure);
    }

    public function test_base_uom_cannot_change_while_an_approved_purchase_order_uses_it(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $purchaseOrder = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $admin->id,
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => '12.00',
            'unit' => 'PCS',
            'unit_price' => '10.00',
            'total' => '120.00',
            'quantity_received' => '0.00',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/items/{$item->hash_id}", ['unit_of_measure' => 'BOX'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change the base unit while stock is on hand or reserved, or after a movement, count, open purchasing document, BOM, or UOM conversion uses the item.');

        $this->assertSame('PCS', $item->fresh()->unit_of_measure);
        $this->assertDatabaseMissing('stock_movements', ['item_id' => $item->id]);
    }

    public function test_base_uom_cannot_change_while_an_open_purchase_request_uses_it(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $purchaseRequest = PurchaseRequest::factory()->create([
            'status' => PurchaseRequestStatus::Pending->value,
            'requested_by' => $admin->id,
        ]);
        PurchaseRequestItem::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => '12.00',
            'unit' => 'PCS',
            'estimated_unit_price' => '10.00',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/items/{$item->hash_id}", ['unit_of_measure' => 'BOX'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change the base unit while stock is on hand or reserved, or after a movement, count, open purchasing document, BOM, or UOM conversion uses the item.');

        $this->assertSame('PCS', $item->fresh()->unit_of_measure);
    }

    public function test_base_uom_cannot_change_while_a_bom_uses_the_component_unit(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $bom = Bom::query()->create(['product_id' => Product::factory()->create()->id]);
        BomItem::query()->create([
            'bom_id' => $bom->id,
            'item_id' => $item->id,
            'quantity_per_unit' => '2.0000',
            'unit' => 'PCS',
            'waste_factor' => '0.00',
            'sort_order' => 0,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/items/{$item->hash_id}", ['unit_of_measure' => 'BOX'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change the base unit while stock is on hand or reserved, or after a movement, count, open purchasing document, BOM, or UOM conversion uses the item.');

        $this->assertSame('PCS', $item->fresh()->unit_of_measure);
    }

    public function test_unused_item_may_change_base_uom(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/items/{$item->hash_id}", ['unit_of_measure' => 'BOX'])
            ->assertOk()
            ->assertJsonPath('data.unit_of_measure', 'BOX');
    }

    public function test_canonicalizing_the_same_uom_does_not_count_as_a_base_unit_change(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'pcs']);
        $location = WarehouseLocation::factory()->create();
        StockLevel::query()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '5.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '10.0000',
            'lock_version' => 0,
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/items/{$item->hash_id}", [
                'unit_of_measure' => 'PCS',
                'standard_cost' => '11.0000',
            ])
            ->assertOk()
            ->assertJsonPath('data.unit_of_measure', 'PCS')
            ->assertJsonPath('data.standard_cost', '11.0000');

        $this->assertSame('5.000', (string) $item->stockLevels()->value('quantity'));
    }

    public function test_item_store_accepts_case_insensitive_uom_and_stores_the_catalog_code(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $category = ItemCategory::factory()->create(['item_type' => 'raw_material']);

        $this->actingAs($admin)
            ->postJson('/api/v1/inventory/items', [
                'code' => 'UOM-CASE-STORE',
                'name' => 'Case-insensitive UOM',
                'category_id' => $category->hash_id,
                'item_type' => 'raw_material',
                'unit_of_measure' => 'pcs',
                'standard_cost' => '10.0000',
                'reorder_method' => 'fixed_quantity',
                'reorder_point' => '0.000',
                'safety_stock' => '0.000',
                'lead_time_days' => 0,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.unit_of_measure', 'PCS');
    }

    public function test_item_update_accepts_lower_case_uom_for_the_existing_base_unit(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'pcs']);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/items/{$item->hash_id}", ['unit_of_measure' => 'pcs'])
            ->assertOk()
            ->assertJsonPath('data.unit_of_measure', 'PCS');

        $this->assertSame('PCS', $item->fresh()->unit_of_measure);
    }

    public function test_uom_delete_is_blocked_by_a_lower_case_item_base_unit(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $uom = Uom::query()->where('code', 'PCS')->firstOrFail();
        Item::factory()->create(['unit_of_measure' => 'pcs']);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/uoms/{$uom->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot delete a UOM that is referenced by items, products, or conversions.');

        $this->assertNull($uom->fresh()->deleted_at);
    }

    public function test_uom_delete_is_blocked_by_a_trashed_item_base_unit(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $uom = Uom::query()->where('code', 'PCS')->firstOrFail();
        Item::factory()->create(['unit_of_measure' => 'pcs'])->delete();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/uoms/{$uom->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot delete a UOM that is referenced by items, products, or conversions.');

        $this->assertNull($uom->fresh()->deleted_at);
    }

    public function test_uom_delete_is_blocked_by_a_product_unit(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $uom = Uom::query()->where('code', 'PCS')->firstOrFail();
        Product::factory()->create(['unit_of_measure' => 'PCS']);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/uoms/{$uom->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot delete a UOM that is referenced by items, products, or conversions.');

        $this->assertNull($uom->fresh()->deleted_at);
    }

    public function test_uom_delete_is_blocked_by_a_trashed_product_unit(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $uom = Uom::query()->where('code', 'PCS')->firstOrFail();
        Product::factory()->create(['unit_of_measure' => 'PCS'])->delete();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/uoms/{$uom->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot delete a UOM that is referenced by items, products, or conversions.');

        $this->assertNull($uom->fresh()->deleted_at);
    }

    public function test_uom_code_cannot_change_while_an_item_uses_it(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $uom = Uom::query()->where('code', 'PCS')->firstOrFail();
        Item::factory()->create(['unit_of_measure' => 'pcs']);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/uoms/{$uom->hash_id}", ['code' => 'EA', 'name' => 'Pieces'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change a UOM code while items, products, or conversions reference it.');

        $this->assertSame('PCS', $uom->fresh()->code);
    }

    public function test_uom_code_cannot_change_while_a_product_uses_it(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $uom = Uom::query()->where('code', 'PCS')->firstOrFail();
        Product::factory()->create(['unit_of_measure' => 'PCS']);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/uoms/{$uom->hash_id}", ['code' => 'EA', 'name' => 'Pieces'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change a UOM code while items, products, or conversions reference it.');

        $this->assertSame('PCS', $uom->fresh()->code);
    }

    public function test_uom_code_cannot_change_while_a_conversion_uses_it(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $uom = Uom::query()->where('code', 'BOX')->firstOrFail();
        ItemUomConversion::query()->create([
            'item_id' => $item->id,
            'from_uom_id' => $uom->id,
            'to_uom_id' => Uom::query()->where('code', 'PCS')->value('id'),
            'factor' => '12.000000',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/uoms/{$uom->hash_id}", ['code' => 'CASE', 'name' => 'Box'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change a UOM code while items, products, or conversions reference it.');

        $this->assertSame('BOX', $uom->fresh()->code);
    }

    public function test_referenced_uom_name_can_change_without_changing_its_code(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $uom = Uom::query()->where('code', 'PCS')->firstOrFail();
        Item::factory()->create(['unit_of_measure' => 'pcs']);

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/uoms/{$uom->hash_id}", ['code' => 'PCS', 'name' => 'Piece units'])
            ->assertOk()
            ->assertJsonPath('data.code', 'PCS')
            ->assertJsonPath('data.name', 'Piece units');

        $audit = AuditLog::query()
            ->where('model_type', $uom->getMorphClass())
            ->where('model_id', $uom->id)
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('PCS', $audit->old_values['code']);
        $this->assertSame('Piece units', $audit->new_values['name']);
    }

    public function test_unused_uom_can_be_deleted_and_restored(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $uom = Uom::query()->where('code', 'BOX')->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/uoms/{$uom->hash_id}")
            ->assertNoContent();
        $this->assertNotNull($uom->fresh()->deleted_at);

        $this->actingAs($admin)
            ->patchJson("/api/v1/inventory/uoms/{$uom->hash_id}/restore")
            ->assertOk();
        $this->assertNull($uom->fresh()->deleted_at);
    }

    public function test_conversion_endpoint_rejects_unknown_uom_ids_without_writing(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $pcs = Uom::query()->where('code', 'PCS')->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => 'not-a-unit',
                'to_uom_id' => $pcs->hash_id,
                'factor' => '12.000000',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('item_uom_conversions', 0);
    }

    public function test_conversion_target_must_match_the_item_base_unit(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $kg = Uom::query()->create(['code' => 'KG', 'name' => 'Kilogram']);
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $box = Uom::query()->where('code', 'BOX')->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $box->hash_id,
                'to_uom_id' => $kg->hash_id,
                'factor' => '12.000000',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Target unit must match the item base unit.');

        $this->assertDatabaseCount('item_uom_conversions', 0);
    }

    public function test_conversion_create_is_audited_and_uses_hash_ids(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $pcs = Uom::query()->where('code', 'PCS')->firstOrFail();
        $box = Uom::query()->where('code', 'BOX')->firstOrFail();

        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $box->hash_id,
                'to_uom_id' => $pcs->hash_id,
                'factor' => '12.000000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.from_uom.code', 'BOX')
            ->assertJsonPath('data.to_uom.code', 'PCS')
            ->assertJsonPath('data.factor', '12.000000');

        $conversion = ItemUomConversion::query()->firstOrFail();
        $audit = AuditLog::query()
            ->where('model_type', $conversion->getMorphClass())
            ->where('model_id', $conversion->id)
            ->where('action', 'created')
            ->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame('12.000000', (string) $audit->new_values['factor']);
    }

    public function test_conversion_factor_cannot_round_down_to_zero(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $pcs = Uom::query()->where('code', 'PCS')->firstOrFail();
        $box = Uom::query()->where('code', 'BOX')->firstOrFail();
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $box->hash_id,
                'to_uom_id' => $pcs->hash_id,
                'factor' => '0.0000001',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('item_uom_conversions', 0);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_conversion_factor_must_fit_the_database_precision(): void
    {
        $admin = $this->systemAdmin();
        $this->seedUoms();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $pcs = Uom::query()->where('code', 'PCS')->firstOrFail();
        $box = Uom::query()->where('code', 'BOX')->firstOrFail();
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $box->hash_id,
                'to_uom_id' => $pcs->hash_id,
                'factor' => '1000000000000',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('item_uom_conversions', 0);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    private function systemAdmin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
    }

    private function seedUoms(): void
    {
        Uom::query()->create(['code' => 'PCS', 'name' => 'Piece']);
        Uom::query()->create(['code' => 'BOX', 'name' => 'Box']);
    }
}
