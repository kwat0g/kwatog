<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Accounting\Models\Account;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Warehouse notification when customer-return stock clears MRB.
 *
 * The independent MRB release moves the goods into good stock and alerts
 * inventory viewers. Notification failure must not roll back that movement.
 */
class ReturnRestockNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
    }

    /** A warehouse role holding inventory and return-request view access. */
    private function warehouseUser(): User
    {
        $role = Role::query()->create(['name' => 'Warehouse Notify Test', 'slug' => 'warehouse-notify-test']);
        $permissions = Permission::query()->whereIn('slug', ['inventory.view', 'return_management.view'])->pluck('id');
        $role->permissions()->attach($permissions->all());

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function warehouseStaff(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'is_active' => true,
        ]);
    }

    private function releaseMrb(ReturnRequest $rma, User $reviewer): MaterialReviewRecord
    {
        $line = $rma->items->firstOrFail()->fresh();
        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $line->id)->firstOrFail();
        $quarantine = WarehouseLocation::query()->with('zone')->findOrFail($line->quarantine_location_id);
        $targetZone = WarehouseZone::factory()->create([
            'warehouse_id' => $quarantine->zone->warehouse_id,
            'zone_type' => 'finished_goods',
        ]);
        $target = WarehouseLocation::factory()->create(['zone_id' => $targetZone->id]);

        $this->actingAs($reviewer)
            ->postJson("/api/v1/inventory/mrb/{$mrb->hash_id}/release", [
                'disposition' => 'use_as_is',
                'target_location_id' => $target->hash_id,
                'inspected_quantity' => '8.000',
                'evidence_reference' => 'NOTIFICATION-REINSPECTION-'.$rma->rma_number,
                'recovery_passed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'released');

        return $mrb->fresh();
    }

    /** A role with no inventory access at all. */
    private function outsider(): User
    {
        $role = Role::query()->create(['name' => 'No Access Test', 'slug' => 'no-access-test']);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function inspectedRma(User $by, Item $item): ReturnRequest
    {
        $customer = Customer::create(['name' => 'Notification Scenario Customer', 'payment_terms_days' => 30]);
        // The return line's product must map to the finished-good item
        // (items.code == products.part_number) or disposition is rejected.
        $product = Product::create([
            'part_number'        => (string) $item->code,
            'name'               => 'Returned finished good',
            'revenue_account_id' => Account::query()->where('code', '4010')->value('id'),
        ]);
        $item->update(['item_type' => ItemType::FinishedGood]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-NTF-' . substr(uniqid(), -5),
            'customer_id'    => $customer->id,
            'status'         => 'finalized',
            'subtotal'       => '1000.00',
            'vat_amount'     => '120.00',
            'total_amount'   => '1120.00',
            'balance'        => '1120.00',
            'date'           => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'created_by'     => $by->id,
        ]);
        $invoiceLine = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'revenue_account_id' => Account::query()->where('code', '4010')->firstOrFail()->id,
            'product_id' => $product->id,
            'description' => 'Returned stock',
            'quantity' => '8.00',
            'unit_price' => '100.00',
            'total' => '800.00',
        ]);

        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-NTF-' . substr(uniqid(), -5),
            'type'        => 'customer_return',
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $customer->id,
            'invoice_id'  => $invoice->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        $line = ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'product_id'        => $product->id,
            'item_id'           => $item->id,
            'quantity'          => '8.000',
            'returned_quantity' => '8.000',
            'unit_price'        => '100.00',
            'total'             => '800.00',
            'source_invoice_item_id' => $invoiceLine->id,
        ]);

        $zone = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $movement = app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $item->id,
            toLocationId: $quarantine->id,
            quantity: '8.000',
            unitCost: '0.00',
            referenceType: 'return_request',
            referenceId: $rma->id,
            createdBy: $by->id,
        ));
        $line->update([
            'quarantine_location_id' => $quarantine->id,
            'quarantine_movement_id' => $movement->id,
            'quarantine_status' => 'held',
        ]);

        Inspection::create([
            'inspection_number' => 'QC-NTF-'.substr(uniqid(), -8),
            'stage'             => InspectionStage::CustomerReturn->value,
            'status'            => InspectionStatus::Passed->value,
            'product_id'        => $product->id,
            'entity_type'       => InspectionEntityType::ReturnRequest->value,
            'entity_id'         => $rma->id,
            'batch_quantity'    => 8,
            'sample_size'       => 8,
            'accept_count'      => 8,
            'reject_count'      => 0,
            'defect_count'      => 0,
            'inspector_id'      => $by->id,
        ]);

        return $rma->load('items');
    }

    public function test_restock_dispose_notifies_everyone_with_inventory_view(): void
    {
        $admin = $this->admin();
        $warehouse = $this->warehouseUser();
        $reviewer = $this->warehouseStaff();
        $item = Item::factory()->create();
        $loc = WarehouseLocation::factory()->create();
        $rma = $this->inspectedRma($admin, $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
                'location_id'  => $loc->hash_id,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $warehouse->id,
            'type' => 'return.restocked',
        ]);
        $this->assertSame('held', MaterialReviewRecord::query()->sole()->status->value);
        $released = $this->releaseMrb($rma, $reviewer);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $warehouse->id,
            'type'          => 'return.restocked',
        ]);

        $row = DB::table('notifications')
            ->where('notifiable_id', $warehouse->id)
            ->where('type', 'return.restocked')
            ->first();
        $data = json_decode($row->data, true);
        $this->assertStringContainsString($rma->rma_number, $data['message']);
        $this->assertStringContainsString('8 unit(s)', $data['message'], 'Quantity renders without trailing decimal zeros.');
        $this->assertSame("/return-management/{$rma->hash_id}", $data['link_to']);

        $this->actingAs($reviewer)
            ->postJson("/api/v1/inventory/mrb/{$released->hash_id}/release", [
                'disposition' => 'use_as_is',
                'target_location_id' => WarehouseLocation::findOrFail($released->release_location_id)->hash_id,
                'inspected_quantity' => '8.000',
                'evidence_reference' => 'DUPLICATE-REINSPECTION-'.$rma->rma_number,
                'recovery_passed' => true,
            ])
            ->assertUnprocessable();

        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $warehouse->id)
            ->where('type', 'return.restocked')
            ->count());
    }

    public function test_restock_dispose_notifies_wildcard_system_admin(): void
    {
        // system_admin holds a '*' permission, not the explicit inventory.view
        // slug — the whereHas on role.permissions must still reach them.
        $admin = $this->admin();
        $reviewer = $this->warehouseStaff();
        $item = Item::factory()->create();
        $loc = WarehouseLocation::factory()->create();
        $rma = $this->inspectedRma($admin, $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
                'location_id'  => $loc->hash_id,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $admin->id,
            'type' => 'return.restocked',
        ]);
        $this->releaseMrb($rma, $reviewer);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
            'type'          => 'return.restocked',
        ]);
    }

    public function test_restock_dispose_skips_users_without_inventory_access(): void
    {
        $admin = $this->admin();
        $outsider = $this->outsider();
        $warehouse = $this->warehouseUser();
        $hiddenRmaViewer = $this->warehouseUser();
        UserPermissionOverride::create([
            'user_id' => $hiddenRmaViewer->id,
            'permission_id' => Permission::query()->where('slug', 'return_management.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $admin->id,
            'reason' => 'Notification route access test.',
        ]);
        $hiddenRmaViewer->flushPermissionsCache();
        $reviewer = $this->warehouseStaff();
        $item = Item::factory()->create();
        $loc = WarehouseLocation::factory()->create();
        $rma = $this->inspectedRma($admin, $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
                'location_id'  => $loc->hash_id,
            ])
            ->assertOk();

        $this->releaseMrb($rma, $reviewer);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $warehouse->id,
            'type' => 'return.restocked',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $outsider->id,
            'type'          => 'return.restocked',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $hiddenRmaViewer->id,
            'type'          => 'return.restocked',
        ]);
    }

    public function test_scrap_dispose_fires_no_restock_notification(): void
    {
        $admin = $this->admin();
        $this->warehouseUser();
        $item = Item::factory()->create();
        $rma = $this->inspectedRma($admin, $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'scrap',
                ]],
            ])
            ->assertOk();

        $this->assertSame(0, DB::table('notifications')->where('type', 'return.restocked')->count());
    }

    public function test_supplier_return_ship_dispose_fires_no_restock_notification(): void
    {
        $admin = $this->admin();
        $this->warehouseUser();
        $item = Item::factory()->create();
        $rma = $this->inspectedRma($admin, $item);
        $rma->update(['type' => 'supplier_return']);

        // No vendor/PO lineage — but the location guard fires first for the
        // requested return_to_supplier movement, proving no restock alert leaks
        // from the supplier side even when movement is attempted.
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'return_to_supplier',
                ]],
                'location_id'  => WarehouseLocation::factory()->create()->hash_id,
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('notifications')->where('type', 'return.restocked')->count());
    }
}
