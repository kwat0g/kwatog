<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Services\ThreeWayMatchService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H-7 — matchForBill aligns bill lines to PO lines by item_id FK.
 *
 * Pre-H-7 the alignment was by index position, which silently corrupted the
 * variance check whenever a bill line was skipped or reordered.
 */
class BillMatchAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private ThreeWayMatchService $service;
    private User $user;
    private Vendor $vendor;
    private Item $itemA;
    private Item $itemB;
    private WarehouseLocation $location;
    private Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->service  = app(ThreeWayMatchService::class);
        $roleId         = Role::query()->where('slug', 'system_admin')->value('id');
        $this->user     = User::create([
            'name' => 'F', 'email' => 'aln_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $roleId,
        ]);
        $this->vendor   = Vendor::create(['name' => 'Aln Vendor', 'payment_terms_days' => 30]);
        $this->itemA    = Item::factory()->create(['code' => 'RM-A']);
        $this->itemB    = Item::factory()->create(['code' => 'RM-B']);
        $this->location = WarehouseLocation::factory()->create();
        $this->expenseAccount = Account::query()->where('code', '5010')->firstOrFail();
    }

    /** Build a PO with two items A then B. Both fully received via GRN. */
    private function makePoWithTwoLines(): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create([
            'created_by' => $this->user->id,
            'vendor_id'  => $this->vendor->id,
            'status'     => 'approved',
        ]);
        $poiA = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $this->itemA->id,
            'description'       => 'Item A',
            'quantity'          => 10, 'unit' => 'pcs',
            'unit_price'        => 5.00, 'total' => 50.00,
            'quantity_received' => 0,
        ]);
        $poiB = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $this->itemB->id,
            'description'       => 'Item B',
            'quantity'          => 20, 'unit' => 'pcs',
            'unit_price'        => 7.00, 'total' => 140.00,
            'quantity_received' => 0,
        ]);

        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $this->vendor->id,
            'received_by'       => $this->user->id,
        ]);
        foreach ([[$poiA, $this->itemA, 10], [$poiB, $this->itemB, 20]] as [$poi, $item, $qty]) {
            GrnItem::create([
                'goods_receipt_note_id'  => $grn->id,
                'purchase_order_item_id' => $poi->id,
                'item_id'                => $item->id,
                'location_id'            => $this->location->id,
                'quantity_received'      => $qty,
                'quantity_accepted'      => $qty,
                'unit_cost'              => $poi->unit_price,
            ]);
        }

        return $po->fresh(['items.item']);
    }

    private function buildBill(PurchaseOrder $po, array $lines): Bill
    {
        $bill = Bill::create([
            'bill_number'      => 'BILL-'.uniqid(),
            'vendor_id'        => $this->vendor->id,
            'purchase_order_id'=> $po->id,
            'date'             => '2026-04-10',
            'due_date'         => '2026-05-10',
            'is_vatable'       => false,
            'subtotal'         => 0,
            'vat_amount'       => 0,
            'total_amount'     => 0,
            'amount_paid'      => 0,
            'balance'          => 0,
            'status'           => 'unpaid',
            'created_by'       => $this->user->id,
        ]);
        foreach ($lines as $ln) {
            BillItem::create([
                'bill_id'            => $bill->id,
                'expense_account_id' => $this->expenseAccount->id,
                'item_id'            => $ln['item_id'],
                'description'        => $ln['description'],
                'quantity'           => $ln['quantity'],
                'unit_price'         => $ln['unit_price'],
                'total'              => $ln['quantity'] * $ln['unit_price'],
            ]);
        }
        return $bill->fresh('items');
    }

    public function test_skipped_bill_line_aligns_correctly_via_fk(): void
    {
        // PO has Items A, B. Bill only carries B (skip A).
        // Index alignment would wrongly map bill[0]=B onto PO[0]=A and report
        // a price/qty variance on A that doesn't exist. FK alignment correctly
        // matches B-to-B and reports 100% qty variance on A (no bill line).
        $po = $this->makePoWithTwoLines();
        $bill = $this->buildBill($po, [[
            'item_id'     => $this->itemB->id,
            'description' => 'Item B',
            'quantity'    => 20,
            'unit_price'  => 7.00,
        ]]);

        $result = $this->service->matchForBill($bill);
        $this->assertNotNull($result);

        // Lines come back in PO order: A then B.
        $byItem = collect($result->lines)->keyBy('item_id');

        // A — no bill line → bill_qty=0 AND bill_price=0 → both qty and price
        // come out as 100% variance ('both' status), correctly blocked. The
        // crucial point is the variance is reported on PO line A, not silently
        // aligned to bill line B.
        $this->assertContains($byItem[$this->itemA->id]['status'], ['qty_variance', 'both'],
            'PO line A with no matching bill line must surface a qty variance, not silently aligned to a wrong line.');
        $this->assertSame(100.0, $byItem[$this->itemA->id]['quantity_variance_pct']);
        $this->assertSame('block', $byItem[$this->itemA->id]['severity']);

        // B — fully matched.
        $this->assertSame('matched', $byItem[$this->itemB->id]['status'],
            'PO line B must match its actual bill line, not be misaligned by index.');
        $this->assertSame('ok', $byItem[$this->itemB->id]['severity']);

        $this->assertSame('blocked', $result->overallStatus);
    }

    public function test_reordered_bill_lines_align_correctly_via_fk(): void
    {
        // PO order: A, B. Bill order: B, A. Index alignment would mismatch
        // both lines. FK alignment matches them correctly.
        $po = $this->makePoWithTwoLines();
        $bill = $this->buildBill($po, [
            ['item_id' => $this->itemB->id, 'description' => 'Item B', 'quantity' => 20, 'unit_price' => 7.00],
            ['item_id' => $this->itemA->id, 'description' => 'Item A', 'quantity' => 10, 'unit_price' => 5.00],
        ]);

        $result = $this->service->matchForBill($bill);
        $this->assertNotNull($result);

        $byItem = collect($result->lines)->keyBy('item_id');

        $this->assertSame('matched', $byItem[$this->itemA->id]['status'],
            'Reordered Item A must still match its bill line via FK.');
        $this->assertSame('matched', $byItem[$this->itemB->id]['status'],
            'Reordered Item B must still match its bill line via FK.');
        $this->assertSame('matched', $result->overallStatus);
    }
}
