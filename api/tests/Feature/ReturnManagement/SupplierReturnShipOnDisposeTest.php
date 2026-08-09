<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
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
use Tests\TestCase;

/**
 * 2026-08-08 — supplier return ships at disposition time (P2P twin of the
 * customer restock). Disposing a line as 'return_to_supplier' creates the
 * ReturnToVendor movement immediately at the declared location, so the goods
 * leave stock the moment the disposition is recorded — not at a later,
 * separate completion step. complete() is idempotent.
 */
class SupplierReturnShipOnDisposeTest extends TestCase
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
        ]);
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
            'description'       => 'Resin for ship-on-dispose test',
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
            'bill_number'       => 'BILL-SD-' . substr(uniqid(), -5),
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
            'rma_number'        => 'RMA-SD-' . substr(uniqid(), -5),
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
            'return_request_id'  => $rma->id,
            'item_id'            => $ctx['item']->id,
            'quantity'           => '18.000',
            'returned_quantity'  => '18.000',
            'unit_price'         => '10.00',
            'total'              => '180.00',
            'source_po_item_id'  => $ctx['poItem']->id,
            'source_grn_item_id' => $ctx['grnItem']->id,
            'source_bill_item_id'=> $ctx['billItem']->id,
        ]);

        return $rma->load('items');
    }

    public function test_dispose_return_to_supplier_without_a_location_is_rejected(): void
    {
        $admin = $this->admin();
        $ctx   = $this->receivedShipment($admin);
        $rma   = $this->inspectedRma($admin, $ctx);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'return_to_supplier',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');

        $this->assertNull($rma->fresh()->disposition_status, 'Nothing may be disposed without naming the shipping location.');
    }

    public function test_dispose_return_to_supplier_ships_the_goods_out_immediately(): void
    {
        $admin = $this->admin();
        $ctx   = $this->receivedShipment($admin);
        $rma   = $this->inspectedRma($admin, $ctx);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'return_to_supplier',
                    'notes'       => 'Moisture out of spec.',
                ]],
                'location_id' => $ctx['location']->hash_id,
            ])
            ->assertOk();

        $rma->refresh();

        // The goods leave the ledger the moment the disposition is recorded.
        $movement = StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->firstOrFail();
        $this->assertSame(StockMovementType::ReturnToVendor, $movement->movement_type);
        $this->assertSame('18.000', (string) $movement->quantity);

        $level = StockLevel::where('item_id', $ctx['item']->id)
            ->where('location_id', $ctx['location']->id)
            ->firstOrFail();
        $this->assertSame('82.000', (string) $level->quantity, '100 on shelf − 18 shipped back immediately.');

        $line = $rma->items->first()->fresh();
        $this->assertSame('18.000', (string) $line->stock_movement_quantity);
        $this->assertNotNull($rma->stock_movement_id);

        // The credit note was still raised and the receipt reversed.
        $this->assertNotNull($rma->credit_note_id);
        $this->assertSame('82.000', (string) $ctx['grnItem']->fresh()->quantity_received);

        // The API surfaces the shipment facts for the detail-page banner.
        $this->actingAs($admin)
            ->getJson("/api/v1/return-management/return-requests/{$rma->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.moved_quantity', '18')
            ->assertJsonPath('data.stock_movement.movement_type', 'return_to_vendor')
            ->assertJsonPath('data.stock_movement.from_location.code', $ctx['location']->code);
    }

    public function test_complete_after_dispose_ship_is_idempotent_and_needs_no_location(): void
    {
        $admin = $this->admin();
        $ctx   = $this->receivedShipment($admin);
        $rma   = $this->inspectedRma($admin, $ctx);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'return_to_supplier',
                ]],
                'location_id' => $ctx['location']->hash_id,
            ])
            ->assertOk();

        // Already shipped at dispose — completing closes the RMA without a
        // location and without moving the goods a second time.
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [])
            ->assertOk();

        $this->assertSame(ReturnRequestStatus::Completed, $rma->fresh()->status);
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('reference_type', 'return_request')
                ->where('reference_id', $rma->id)
                ->count(),
            'Complete must not create a second movement for already-shipped lines.',
        );
    }
}
