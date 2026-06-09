<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Exceptions\ThreeWayMatchException;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Services\ThreeWayMatchService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H-6 — Bill qty must not exceed accepted GRN qty beyond qty tolerance.
 *
 * matchForPo() now treats a GRN-coverage shortfall as a hard block. Previously
 * GRN was only surfaced as informational context.
 */
class ThreeWayMatchGrnCoverageTest extends TestCase
{
    use RefreshDatabase;

    private ThreeWayMatchService $service;
    private User $user;
    private Item $item;
    private WarehouseLocation $location;
    private Vendor $vendor;
    private Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->service  = app(ThreeWayMatchService::class);
        $roleId         = Role::query()->where('slug', 'system_admin')->value('id');
        $this->user     = User::create([
            'name' => 'Finance', 'email' => 'grn_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $roleId,
        ]);
        $this->vendor   = Vendor::create(['name' => 'GRN Vendor', 'payment_terms_days' => 30]);
        $this->item     = Item::factory()->create(['code' => 'RM-GRN-001']);
        $this->location = WarehouseLocation::factory()->create();
        $this->expenseAccount = Account::query()->where('code', '5010')->firstOrFail();
    }

    private function makePo(float $poQty, float $poPrice): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create([
            'created_by' => $this->user->id,
            'vendor_id'  => $this->vendor->id,
            'status'     => 'approved',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $this->item->id,
            'description'       => 'Test item',
            'quantity'          => $poQty,
            'unit'              => 'pcs',
            'unit_price'        => $poPrice,
            'total'             => $poQty * $poPrice,
            'quantity_received' => 0,
        ]);
        return $po->fresh(['items.item']);
    }

    private function attachGrn(PurchaseOrder $po, float $qtyAccepted, float $unitCost): void
    {
        $poi = $po->items->first();
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $this->vendor->id,
            'received_by'       => $this->user->id,
        ]);
        GrnItem::create([
            'goods_receipt_note_id'  => $grn->id,
            'purchase_order_item_id' => $poi->id,
            'item_id'                => $this->item->id,
            'location_id'            => $this->location->id,
            'quantity_received'      => $qtyAccepted,
            'quantity_accepted'      => $qtyAccepted,
            'unit_cost'              => $unitCost,
        ]);
    }

    private function billLines(float $billQty, float $billPrice): array
    {
        return [[
            'item_id'     => (string) $this->item->id,
            'description' => 'Test item',
            'quantity'    => $billQty,
            'unit_price'  => $billPrice,
        ]];
    }

    public function test_bill_qty_equals_grn_qty_equals_po_qty_returns_matched(): void
    {
        $po = $this->makePo(100.0, 10.00);
        $this->attachGrn($po, 100.0, 10.00);

        $result = $this->service->matchForPo($po, $this->billLines(100.0, 10.00));

        $this->assertSame('matched', $result->overallStatus);
        $this->assertSame('matched', $result->lines[0]['status']);
        $this->assertSame('ok', $result->lines[0]['grn_status']);
    }

    public function test_no_grn_at_all_blocks_the_bill(): void
    {
        $po = $this->makePo(100.0, 10.00);
        // No GRN attached — receiving never happened.

        $result = $this->service->matchForPo($po, $this->billLines(100.0, 10.00));

        $this->assertSame('blocked', $result->overallStatus,
            'Bill against a PO with zero GRN must block — cannot pay for goods never received.');
        $this->assertSame('short', $result->lines[0]['grn_status']);
        $this->assertSame('block', $result->lines[0]['severity']);
    }

    public function test_bill_qty_within_tolerance_of_grn_qty_returns_matched(): void
    {
        // PO 100, GRN 100, Bill 103 → over-GRN by 3% (within 5% tol).
        // Note: bill 103 vs PO 100 also lifts overall to has_variances.
        $po = $this->makePo(100.0, 10.00);
        $this->attachGrn($po, 100.0, 10.00);

        $result = $this->service->matchForPo($po, $this->billLines(103.0, 10.00));

        $this->assertSame('has_variances', $result->overallStatus,
            'Within-tolerance variance lifts overall to has_variances.');
        $this->assertSame('ok', $result->lines[0]['grn_status'],
            'GRN status must be ok when bill qty is within tolerance of GRN qty.');
        $this->assertSame('ok', $result->lines[0]['severity']);
    }

    public function test_bill_qty_exceeds_grn_qty_beyond_tolerance_returns_blocked(): void
    {
        // PO 100, GRN 80 (partial receipt), Bill 100 → over-GRN by 25% (> 5% tol).
        $po = $this->makePo(100.0, 10.00);
        $this->attachGrn($po, 80.0, 10.00);

        $result = $this->service->matchForPo($po, $this->billLines(100.0, 10.00));

        $this->assertSame('blocked', $result->overallStatus,
            'Bill qty exceeding accepted GRN qty beyond tolerance must block.');
        $this->assertSame('short', $result->lines[0]['grn_status']);
        $this->assertSame('block', $result->lines[0]['severity']);
        // qty/price both match PO exactly → grn_short surfaces as the line status.
        $this->assertSame('grn_short', $result->lines[0]['status']);
    }

    public function test_allow_override_bypasses_grn_block_in_bill_service(): void
    {
        $po = $this->makePo(100.0, 10.00);
        $this->attachGrn($po, 80.0, 10.00); // partial receipt

        $svc = app(BillService::class);

        // Without override — must throw.
        try {
            $svc->create([
                'bill_number'       => 'INV-GRN-FAIL',
                'vendor_id'         => $this->vendor->hash_id,
                'purchase_order_id' => $po->hash_id,
                'date'              => '2026-04-10',
                'is_vatable'        => false,
                'items'             => [[
                    'expense_account_id' => $this->expenseAccount->hash_id,
                    'item_id'            => $this->item->hash_id,
                    'description'        => 'Test item',
                    'quantity'           => '100',
                    'unit_price'         => '10.00',
                ]],
            ], $this->user);
            $this->fail('Expected ThreeWayMatchException for GRN shortfall.');
        } catch (ThreeWayMatchException $e) {
            $this->assertStringContainsString('blocked', strtolower($e->getMessage()));
        }

        // With override — must succeed and persist the override snapshot.
        $bill = $svc->create([
            'bill_number'       => 'INV-GRN-OVR',
            'vendor_id'         => $this->vendor->hash_id,
            'purchase_order_id' => $po->hash_id,
            'date'              => '2026-04-10',
            'is_vatable'        => false,
            'allow_override'    => true,
            'items'             => [[
                'expense_account_id' => $this->expenseAccount->hash_id,
                'item_id'            => $this->item->hash_id,
                'description'        => 'Test item',
                'quantity'           => '100',
                'unit_price'         => '10.00',
            ]],
        ], $this->user);

        $this->assertNotNull($bill->id, 'Bill must be created when allow_override=true even on GRN-block.');
        $this->assertTrue((bool) $bill->has_variances, 'has_variances must be true on overridden bill.');
        $this->assertNotNull($bill->three_way_match_snapshot, 'Match snapshot must be persisted for audit.');
    }
}
