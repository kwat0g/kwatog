<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-08-08 — purchasing notification on supplier-return ship-out.
 *
 * Disposing a supplier-return line as 'return_to_supplier' ships the goods
 * back out (ReturnToVendor) and alerts everyone with purchasing access so the
 * shipment is tracked and the vendor credit is followed up. Best-effort: a
 * failing notification must never roll back the stock movement.
 */
class SupplierReturnShipNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        app(\App\Common\Services\SettingsService::class)->set('budgeting.enforcement_mode', 'off');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
    }

    /** A purchasing role holding only purchasing.po.view. */
    private function purchasingUser(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'purchasing.po.view'],
            ['name' => 'View Purchase Orders', 'module' => 'purchasing'],
        );
        $role = Role::query()->create(['name' => 'Purchasing Notify Test', 'slug' => 'purchasing-notify-test']);
        $role->permissions()->attach($permission);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    /** A role with no purchasing access at all. */
    private function outsider(): User
    {
        $role = Role::query()->create(['name' => 'No Access Test', 'slug' => 'no-access-test']);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    /** A vendor with 100kg received, accepted, stocked (100 on shelf) and billed. */
    private function receivedShipment(User $by): array
    {
        $vendor   = Vendor::factory()->create(['created_by' => null]);
        $item     = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $expense  = Account::query()->where('type', 'expense')->firstOrFail();

        $po = PurchaseOrder::factory()->create([
            'vendor_id'    => $vendor->id,
            'created_by'   => $by->id,
            'subtotal'     => '1000.00',
            'vat_amount'   => '120.00',
            'total_amount' => '1120.00',
            'is_vatable'   => true,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Received])->save();

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin for ship-notify test',
            'quantity'          => '100.00',
            'unit'              => 'kg',
            'unit_price'        => '10.00',
            'total'             => '1000.00',
            'quantity_received' => '100.00',
        ]);

        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'received_by'       => $by->id,
            'status'            => 'accepted',
        ]);
        $grnItem = GrnItem::create([
            'goods_receipt_note_id'  => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => $location->id,
            'quantity_received'      => '100.000',
            'quantity_accepted'      => '100.000',
            'unit_cost'              => '10.0000',
        ]);

        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $item->id,
            toLocationId: $location->id,
            quantity: '100',
            unitCost: '10.00',
            referenceType: 'opening',
            createdBy: $by->id,
        ));

        $bill = Bill::create([
            'bill_number'       => 'BILL-SN-' . substr(uniqid(), -5),
            'vendor_id'         => $vendor->id,
            'purchase_order_id' => $po->id,
            'status'            => 'unpaid',
            'subtotal'          => '1000.00',
            'vat_amount'        => '120.00',
            'total_amount'      => '1120.00',
            'amount_paid'       => '0.00',
            'balance'           => '1120.00',
            'date'              => now()->toDateString(),
            'due_date'          => now()->addDays(30)->toDateString(),
            'is_vatable'        => true,
            'created_by'        => $by->id,
        ]);
        $billItem = BillItem::create([
            'bill_id'            => $bill->id,
            'expense_account_id' => $expense->id,
            'item_id'            => $item->id,
            'description'        => 'Received resin',
            'quantity'           => '100.00',
            'unit'               => 'kg',
            'unit_price'         => '10.00',
            'total'              => '1000.00',
        ]);

        return compact('vendor', 'item', 'location', 'po', 'poItem', 'grnItem', 'bill', 'billItem');
    }

    /** An inspected supplier RMA returning 18 of the 100kg. */
    private function inspectedRma(User $by, array $ctx): ReturnRequest
    {
        $rma = ReturnRequest::create([
            'rma_number'        => 'RMA-SN-' . substr(uniqid(), -5),
            'type'              => 'supplier_return',
            'status'            => ReturnRequestStatus::Inspected->value,
            'purchase_order_id' => $ctx['po']->id,
            'bill_id'           => $ctx['bill']->id,
            'vendor_id'         => $ctx['vendor']->id,
            'reason_code'       => 'quality_issue',
            'return_date'       => now()->toDateString(),
            'created_by'        => $by->id,
        ]);

        ReturnRequestItem::create([
            'return_request_id'   => $rma->id,
            'item_id'             => $ctx['item']->id,
            'quantity'            => '18.000',
            'returned_quantity'   => '18.000',
            'unit_price'          => '10.00',
            'total'               => '180.00',
            'source_po_item_id'   => $ctx['poItem']->id,
            'source_grn_item_id'  => $ctx['grnItem']->id,
            'source_bill_item_id' => $ctx['billItem']->id,
        ]);

        return $rma->load('items');
    }

    public function test_ship_dispose_notifies_purchasing_po_view_holders(): void
    {
        $admin = $this->admin();
        $purchasing = $this->purchasingUser();
        $ctx = $this->receivedShipment($admin);
        $rma = $this->inspectedRma($admin, $ctx);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'return_to_supplier',
                ]],
                'location_id'  => $ctx['location']->hash_id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $purchasing->id,
            'type'          => 'return.shipped_to_vendor',
        ]);

        $row = DB::table('notifications')
            ->where('notifiable_id', $purchasing->id)
            ->where('type', 'return.shipped_to_vendor')
            ->first();
        $data = json_decode($row->data, true);
        $this->assertStringContainsString($rma->rma_number, $data['message']);
        $this->assertStringContainsString('18 unit(s)', $data['message'], 'Quantity renders without trailing decimal zeros.');
        $this->assertSame("/return-management/{$rma->hash_id}", $data['link_to']);
    }

    public function test_ship_dispose_notifies_wildcard_system_admin(): void
    {
        $admin = $this->admin();
        $ctx = $this->receivedShipment($admin);
        $rma = $this->inspectedRma($admin, $ctx);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'return_to_supplier',
                ]],
                'location_id'  => $ctx['location']->hash_id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
            'type'          => 'return.shipped_to_vendor',
        ]);
    }

    public function test_ship_dispose_skips_users_without_purchasing_access(): void
    {
        $admin = $this->admin();
        $outsider = $this->outsider();
        $ctx = $this->receivedShipment($admin);
        $rma = $this->inspectedRma($admin, $ctx);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'return_to_supplier',
                ]],
                'location_id'  => $ctx['location']->hash_id,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $outsider->id,
            'type'          => 'return.shipped_to_vendor',
        ]);
    }

    public function test_customer_restock_fires_no_supplier_ship_notification(): void
    {
        $admin = $this->admin();
        $this->purchasingUser();
        $item = Item::factory()->create();
        $loc = WarehouseLocation::factory()->create();

        $customer = Customer::create(['name' => 'Ship-Notify Customer', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-SN-' . substr(uniqid(), -5),
            'customer_id'    => $customer->id,
            'status'         => 'finalized',
            'subtotal'       => '800.00',
            'vat_amount'     => '96.00',
            'total_amount'   => '896.00',
            'balance'        => '896.00',
            'date'           => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'created_by'     => $admin->id,
        ]);
        $invoiceLine = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'revenue_account_id' => Account::query()->where('code', '4010')->firstOrFail()->id,
            'description' => 'Returned stock',
            'quantity' => '8.00',
            'unit_price' => '100.00',
            'total' => '800.00',
        ]);
        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-SN-' . substr(uniqid(), -5),
            'type'        => 'customer_return',
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $customer->id,
            'invoice_id'  => $invoice->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $admin->id,
        ]);
        $line = ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'item_id'           => $item->id,
            'quantity'          => '8.000',
            'returned_quantity' => '8.000',
            'unit_price'        => '100.00',
            'total'             => '800.00',
            'source_invoice_item_id' => $invoiceLine->id,
        ]);
        $zone = \App\Modules\Inventory\Models\WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $movement = app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $item->id,
            toLocationId: $quarantine->id,
            quantity: '8.000',
            unitCost: '0.00',
            referenceType: 'return_request',
            referenceId: $rma->id,
            createdBy: $admin->id,
        ));
        $line->update([
            'quarantine_location_id' => $quarantine->id,
            'quarantine_movement_id' => $movement->id,
            'quarantine_status' => 'held',
        ]);
        $rma->load('items');

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
                'location_id'  => $loc->hash_id,
            ])
            ->assertOk();

        $this->assertSame(0, DB::table('notifications')->where('type', 'return.shipped_to_vendor')->count());
    }
}
