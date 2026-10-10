<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemUomConversionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unused_conversion_factor_can_be_updated(): void
    {
        [$admin, $item, $pcs, $box, $conversion] = $this->itemWithConversion();

        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $box->hash_id,
                'to_uom_id' => $pcs->hash_id,
                'factor' => '24',
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $conversion->hash_id)
            ->assertJsonPath('data.factor', '24.000000');
        $this->actingAs($admin)
            ->getJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions")
            ->assertOk()
            ->assertJsonPath('data.0.locked_by_history', false);
    }

    public function test_conversion_factor_cannot_change_after_a_material_issue_uses_it(): void
    {
        [$admin, $item, $pcs, $box, $conversion] = $this->itemWithConversion();
        $this->recordMaterialIssue($item, $box->code, $admin);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->getJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions")
            ->assertOk()
            ->assertJsonPath('data.0.locked_by_history', true);
        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $box->hash_id,
                'to_uom_id' => $pcs->hash_id,
                'factor' => '24',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change or remove a UOM conversion while a purchasing document, receipt, or material issue uses it.');

        $this->assertSame('12.000000', (string) $conversion->fresh()->factor);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_conversion_cannot_be_deleted_after_a_material_issue_uses_it(): void
    {
        [$admin, $item, , $box, $conversion] = $this->itemWithConversion();
        $this->recordMaterialIssue($item, $box->code, $admin);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions/{$conversion->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change or remove a UOM conversion while a purchasing document, receipt, or material issue uses it.');

        $this->assertDatabaseHas('item_uom_conversions', ['id' => $conversion->id, 'factor' => '12.000000']);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_conversion_cannot_change_or_be_deleted_after_a_receipt_uses_it(): void
    {
        [$admin, $item, $pcs, $box, $conversion] = $this->itemWithConversion();
        $this->recordReceipt($item, $box->code, $admin);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $box->hash_id,
                'to_uom_id' => $pcs->hash_id,
                'factor' => '24',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change or remove a UOM conversion while a purchasing document, receipt, or material issue uses it.');
        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions/{$conversion->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change or remove a UOM conversion while a purchasing document, receipt, or material issue uses it.');

        $this->assertSame('12.000000', (string) $conversion->fresh()->factor);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_open_purchase_order_prevents_conversion_change_and_delete(): void
    {
        [$admin, $item, $pcs, $box, $conversion] = $this->itemWithConversion();
        $purchaseOrder = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Sent->value,
            'created_by' => $admin->id,
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => '24.000',
            'unit' => $box->code,
            'unit_price' => '1.00',
            'total' => '24.00',
            'quantity_received' => '0.000',
        ]);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $box->hash_id,
                'to_uom_id' => $pcs->hash_id,
                'factor' => '24',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change or remove a UOM conversion while a purchasing document, receipt, or material issue uses it.');
        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions/{$conversion->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change or remove a UOM conversion while a purchasing document, receipt, or material issue uses it.');

        $this->assertSame('12.000000', (string) $conversion->fresh()->factor);
        $this->assertDatabaseHas('item_uom_conversions', ['id' => $conversion->id, 'factor' => '12.000000']);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_closed_purchase_order_keeps_conversion_history_immutable(): void
    {
        [$admin, $item, $pcs, $box, $conversion] = $this->itemWithConversion();
        $purchaseOrder = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Closed->value,
            'created_by' => $admin->id,
        ]);
        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => '24.000',
            'unit' => $box->code,
            'unit_price' => '1.00',
            'total' => '24.00',
            'quantity_received' => '0.000',
        ]);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->getJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions")
            ->assertOk()
            ->assertJsonPath('data.0.locked_by_history', true);
        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $box->hash_id,
                'to_uom_id' => $pcs->hash_id,
                'factor' => '24',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change or remove a UOM conversion while a purchasing document, receipt, or material issue uses it.');
        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions/{$conversion->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change or remove a UOM conversion while a purchasing document, receipt, or material issue uses it.');

        $this->assertSame('12.000000', (string) $conversion->fresh()->factor);
        $this->assertDatabaseHas('item_uom_conversions', ['id' => $conversion->id, 'factor' => '12.000000']);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_uom_code_cannot_change_or_be_deleted_while_an_issue_references_it(): void
    {
        [$admin, $item, , $box] = $this->itemWithConversion(withConversion: false);
        $this->recordMaterialIssue($item, $box->code, $admin);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/uoms/{$box->hash_id}", ['code' => 'CTN', 'name' => 'Carton'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot change a UOM code while items, products, or conversions reference it.');
        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/uoms/{$box->hash_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot delete a UOM that is referenced by items, products, or conversions.');

        $this->assertSame('BOX', $box->fresh()->code);
        $this->assertNull($box->fresh()->deleted_at);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_uom_code_cannot_change_or_be_deleted_while_a_receipt_references_it(): void
    {
        [$admin, $item, , $box] = $this->itemWithConversion(withConversion: false);
        $purchaseOrder = PurchaseOrder::factory()->create();
        $purchaseOrderItem = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => '24.000',
            'unit' => 'BOX',
            'unit_price' => '1.00',
            'total' => '24.00',
            'quantity_received' => '24.000',
        ]);
        $grn = GoodsReceiptNote::factory()->create(['purchase_order_id' => $purchaseOrder->id]);
        GrnItem::query()->create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'received_uom_code' => $box->code,
            'quantity_received' => '24.000',
            'quantity_accepted' => '24.000',
            'unit_cost' => '1.0000',
        ]);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->putJson("/api/v1/inventory/uoms/{$box->hash_id}", ['code' => 'CTN', 'name' => 'Carton'])
            ->assertUnprocessable();
        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/uoms/{$box->hash_id}")
            ->assertUnprocessable();

        $this->assertSame('BOX', $box->fresh()->code);
        $this->assertNull($box->fresh()->deleted_at);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_conversion_cannot_be_configured_from_the_item_base_unit_to_itself(): void
    {
        $admin = $this->systemAdmin();
        $pcs = Uom::query()->create(['code' => 'PCS', 'name' => 'Piece']);
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);

        $this->actingAs($admin)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $pcs->hash_id,
                'to_uom_id' => $pcs->hash_id,
                'factor' => '2',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Source unit must differ from the item base unit.');

        $this->assertDatabaseCount('item_uom_conversions', 0);
    }

    public function test_uom_archive_scope_lists_records_for_restore(): void
    {
        $admin = $this->systemAdmin();
        $uom = Uom::query()->create(['code' => 'BOX', 'name' => 'Box']);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/inventory/uoms/{$uom->hash_id}")
            ->assertNoContent();
        $this->actingAs($admin)
            ->getJson('/api/v1/inventory/uoms')
            ->assertOk()
            ->assertJsonMissing(['id' => $uom->hash_id]);
        $this->actingAs($admin)
            ->getJson('/api/v1/inventory/uoms?trashed=only')
            ->assertOk()
            ->assertJsonPath('data.0.id', $uom->hash_id);
        $this->actingAs($admin)
            ->getJson('/api/v1/inventory/uoms?trashed=with')
            ->assertOk()
            ->assertJsonPath('data.0.id', $uom->hash_id);
        $this->actingAs($admin)
            ->patchJson("/api/v1/inventory/uoms/{$uom->hash_id}/restore")
            ->assertOk();

        $this->assertNull($uom->fresh()->deleted_at);
    }

    public function test_employee_cannot_manage_uom_or_conversion_endpoints(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
            'is_active' => true,
        ]);
        $pcs = Uom::query()->create(['code' => 'PCS', 'name' => 'Piece']);
        $box = Uom::query()->create(['code' => 'BOX', 'name' => 'Box']);
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $conversion = ItemUomConversion::query()->create([
            'item_id' => $item->id,
            'from_uom_id' => $box->id,
            'to_uom_id' => $pcs->id,
            'factor' => '12.000000',
        ]);
        $uomCount = Uom::withTrashed()->count();
        $conversionCount = ItemUomConversion::query()->count();

        $this->actingAs($employee)
            ->postJson('/api/v1/inventory/uoms', ['code' => 'CASE', 'name' => 'Case'])
            ->assertForbidden();
        $this->actingAs($employee)
            ->putJson("/api/v1/inventory/uoms/{$pcs->hash_id}", ['code' => 'EA', 'name' => 'Each'])
            ->assertForbidden();
        $this->actingAs($employee)
            ->deleteJson("/api/v1/inventory/uoms/{$box->hash_id}")
            ->assertForbidden();
        $this->actingAs($employee)
            ->patchJson("/api/v1/inventory/uoms/{$box->hash_id}/restore")
            ->assertForbidden();
        $this->actingAs($employee)
            ->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
                'from_uom_id' => $box->hash_id,
                'to_uom_id' => $pcs->hash_id,
                'factor' => '24',
            ])
            ->assertForbidden();
        $this->actingAs($employee)
            ->deleteJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions/{$conversion->hash_id}")
            ->assertForbidden();

        $box->delete();
        $this->assertSame($uomCount, Uom::withTrashed()->count());
        $this->assertSame($conversionCount, ItemUomConversion::query()->count());
        $this->assertSame('PCS', $pcs->fresh()->code);
        $this->assertNotNull($box->fresh()->deleted_at);
    }

    /** @return array{User, Item, Uom, Uom, ItemUomConversion|null} */
    private function itemWithConversion(bool $withConversion = true): array
    {
        $admin = $this->systemAdmin();
        $pcs = Uom::query()->create(['code' => 'PCS', 'name' => 'Piece']);
        $box = Uom::query()->create(['code' => 'BOX', 'name' => 'Box']);
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $conversion = $withConversion ? ItemUomConversion::query()->create([
            'item_id' => $item->id,
            'from_uom_id' => $box->id,
            'to_uom_id' => $pcs->id,
            'factor' => '12.000000',
        ]) : null;

        return [$admin, $item, $pcs, $box, $conversion];
    }

    private function recordMaterialIssue(Item $item, string $uomCode, User $actor): void
    {
        $location = WarehouseLocation::factory()->create();
        $slip = MaterialIssueSlip::query()->create([
            'slip_number' => 'UOM-'.strtoupper(fake()->unique()->bothify('??####')),
            'issued_date' => now()->toDateString(),
            'issued_by' => $actor->id,
            'created_by' => $actor->id,
            'status' => MaterialIssueStatus::Issued,
            'total_value' => '24.00',
        ]);
        MaterialIssueSlipItem::query()->create([
            'material_issue_slip_id' => $slip->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_issued' => '24.000',
            'unit_cost' => '1.0000',
            'total_cost' => '24.00',
            'issued_uom_code' => $uomCode,
        ]);
    }

    private function recordReceipt(Item $item, string $uomCode, User $actor): void
    {
        $purchaseOrder = PurchaseOrder::factory()->create();
        $purchaseOrderItem = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => '24.000',
            'unit' => $uomCode,
            'unit_price' => '1.00',
            'total' => '24.00',
            'quantity_received' => '24.000',
        ]);
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'received_by' => $actor->id,
        ]);
        GrnItem::query()->create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'received_uom_code' => $uomCode,
            'quantity_received' => '24.000',
            'quantity_accepted' => '24.000',
            'unit_cost' => '1.0000',
        ]);
    }

    private function systemAdmin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
    }
}
