<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG 2 regression: a supplier return with no bill (so no `source_bill_item_id`)
 * must not fall back to the raw-materials ASSET account when it raises the
 * supplier credit. It uses the dedicated purchase-return expense account
 * (`accounting.accounts.purchase_return_expense_code`, code 5010).
 */
class SupplierReturnWithoutBillCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        app(\App\Common\Services\SettingsService::class)->set('budgeting.enforcement_mode', 'off');
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    /**
     * A vendor with 100kg received, accepted and stocked — but NOT billed, so
     * the supplier return has no bill and no bill line to source an account
     * from.
     *
     * @return array{vendor: Vendor, item: Item, location: WarehouseLocation, po: PurchaseOrder, poItem: PurchaseOrderItem, grnItem: GrnItem}
     */
    private function unbilledReceipt(User $by): array
    {
        $vendor = Vendor::factory()->create(['created_by' => null]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();

        $po = PurchaseOrder::factory()->create([
            'vendor_id'  => $vendor->id,
            'created_by' => $by->id,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Received])->save();

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin for unbilled supplier return',
            'quantity'          => '100.00',
            'unit'              => 'kg',
            'unit_price'        => '10.00',
            'total'             => '1000.00',
            'quantity_received' => '100.000',
            'quantity_accepted' => '100.000',
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

        return compact('vendor', 'item', 'location', 'po', 'poItem', 'grnItem');
    }

    public function test_supplier_return_without_a_bill_posts_the_credit_to_the_purchase_return_expense_account(): void
    {
        $by = $this->user();
        $ctx = $this->unbilledReceipt($by);

        $rma = ReturnRequest::create([
            'rma_number'        => 'RMA-NOBILL-' . substr(uniqid(), -5),
            'type'              => ReturnRequestType::SupplierReturn->value,
            'status'            => ReturnRequestStatus::Inspected->value,
            'purchase_order_id' => $ctx['po']->id,
            // No bill: the default supplier-return path.
            'bill_id'           => null,
            'vendor_id'         => $ctx['vendor']->id,
            'reason_code'       => 'quality_issue',
            'return_date'       => now()->toDateString(),
            'created_by'        => $by->id,
        ]);
        $line = ReturnRequestItem::create([
            'return_request_id'  => $rma->id,
            'item_id'            => $ctx['item']->id,
            'quantity'           => '20.000',
            'returned_quantity'  => '20.000',
            'unit_price'         => '10.00',
            'total'              => '200.00',
            'source_po_item_id'  => $ctx['poItem']->id,
            'source_grn_item_id' => $ctx['grnItem']->id,
            // No source_bill_item_id: there is no bill line.
        ]);

        $disposed = app(ReturnRequestService::class)->dispose($rma->load('items'), [[
            'item_id'     => $line->hash_id,
            'disposition' => 'return_to_supplier',
        ]], $by, false, $ctx['location']->id);

        $this->assertSame('disposed', $disposed->disposition_status);
        $this->assertNotNull($disposed->credit_note_id, 'A bill-less supplier return must still raise a supplier credit.');

        $credit = CreditNote::findOrFail($disposed->credit_note_id);
        $this->assertSame('supplier', $credit->type->value);
        $this->assertSame('finalized', $credit->status->value);
        $this->assertNull($credit->bill_id);

        $creditLine = $credit->lines()->with('account')->firstOrFail();
        $this->assertSame('5010', $creditLine->account->code, 'The supplier credit must post to the dedicated purchase-return expense account.');
        // 20kg × ₱10.00 = ₱200.00.
        $this->assertSame('200.00', (string) $creditLine->amount);
    }
}
