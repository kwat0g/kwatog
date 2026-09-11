<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Partially-accepted GRNs are billable.
 *
 * partialAccept() moves real stock and posts the GRNI journal, yet the AP
 * paths only accepted GrnStatus::Accepted — so accepted goods could exist
 * with no payable. These tests pin the single billable predicate on the
 * auto-stage path and the manual AP provenance path, and confirm the
 * accepted-quantity line source.
 */
class PartialAcceptedGrnBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GrnService $grnSvc;

    private BillService $billSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);

        $this->grnSvc = app(GrnService::class);
        $this->billSvc = app(BillService::class);
    }

    /** Build an approved PO (100 kg @ 12.50) and receive 80 kg into a pending_qc GRN. */
    private function makePendingGrn(): GoodsReceiptNote
    {
        $item = Item::factory()->create(['is_active' => true]);
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin batch',
            'quantity'          => '100.000',
            'unit'              => 'kg',
            'unit_price'        => '12.50',
            'total'             => '1250.00',
            'quantity_received' => '0.000',
        ]);

        return $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => WarehouseLocation::factory()->create()->id,
            'quantity_received'      => '80.000',
            'unit_cost'              => '12.50',
        ]], ['received_date' => now()->toDateString()], $this->user);
    }

    private function passInspections(GoodsReceiptNote $grn): void
    {
        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);
    }

    public function test_auto_stage_bills_only_the_accepted_quantity_of_a_partial_grn(): void
    {
        $grn = $this->makePendingGrn();
        $this->passInspections($grn);

        $line = $grn->items()->first();
        $partial = $this->grnSvc->partialAccept($grn->fresh(), [
            $line->id => '40.000',
        ], $this->user);
        $this->assertSame(GrnStatus::PartialAccepted, $partial->status);

        // The outbox after-commit may not fire inside a test transaction;
        // replay the accepted event so the listener is exercised.
        event(new GoodsReceiptNoteAccepted($partial));

        $bill = Bill::where('goods_receipt_note_id', $partial->id)->firstOrFail();
        $this->assertSame(BillStatus::Draft, $bill->status);
        $this->assertSame('40.00', (string) $bill->items()->first()->quantity);
        $this->assertSame('12.50', (string) $bill->items()->first()->unit_price);
        $this->assertSame('500.00', (string) $bill->subtotal);
        $this->assertSame('60.00', (string) $bill->vat_amount);
        $this->assertSame('560.00', (string) $bill->total_amount);
    }

    public function test_manual_ap_allows_a_partially_accepted_grn_for_the_accepted_quantity(): void
    {
        // Suppress the auto-stage listener so this test isolates the manual
        // AP provenance path (assertBillProvenance).
        Event::fake([GoodsReceiptNoteAccepted::class]);

        $grn = $this->makePendingGrn();
        $this->passInspections($grn);

        $line = $grn->items()->first();
        $partial = $this->grnSvc->partialAccept($grn->fresh(), [
            $line->id => '40.000',
        ], $this->user);
        $this->assertSame(GrnStatus::PartialAccepted, $partial->status);
        $this->assertSame(0, Bill::where('goods_receipt_note_id', $partial->id)->count());

        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $bill = $this->billSvc->createDraft([
            'bill_number'           => 'INV-PARTIAL-001',
            'vendor_id'             => $partial->vendor->hash_id,
            'purchase_order_id'     => $partial->purchaseOrder->hash_id,
            'goods_receipt_note_id' => $partial->hash_id,
            'provenance_type'       => 'stock',
            'date'                  => now()->toDateString(),
            'is_vatable'            => false,
            'items'                 => [[
                'expense_account_id' => $expenseId,
                'item_id'            => $line->item->hash_id,
                'description'        => 'Resin batch (accepted 40)',
                'quantity'           => '40.00',
                'unit'               => 'kg',
                'unit_price'         => '12.50',
            ]],
        ], $this->user);

        $this->assertSame(BillStatus::Draft, $bill->status);
        $this->assertSame('500.00', (string) $bill->subtotal);
        $this->assertSame('40.00', (string) $bill->items()->first()->quantity);
    }

    public function test_a_fully_accepted_grn_still_bills_the_received_quantity(): void
    {
        $grn = $this->makePendingGrn();
        $this->passInspections($grn);

        $accepted = $this->grnSvc->accept($grn->fresh(), $this->user);
        $this->assertSame(GrnStatus::Accepted, $accepted->status);

        $bill = Bill::where('goods_receipt_note_id', $accepted->id)->firstOrFail();
        $this->assertSame(BillStatus::Draft, $bill->status);
        $this->assertSame('80.00', (string) $bill->items()->first()->quantity);
        $this->assertSame('1000.00', (string) $bill->subtotal);
        $this->assertSame('1120.00', (string) $bill->total_amount);
    }

    public function test_a_partially_accepted_grn_cannot_be_billed_twice(): void
    {
        $grn = $this->makePendingGrn();
        $this->passInspections($grn);

        $line = $grn->items()->first();
        $partial = $this->grnSvc->partialAccept($grn->fresh(), [
            $line->id => '40.000',
        ], $this->user);

        // Two replays of the accepted event must still yield one payable.
        event(new GoodsReceiptNoteAccepted($partial));
        event(new GoodsReceiptNoteAccepted($partial->fresh()));

        $this->assertSame(1, Bill::where('goods_receipt_note_id', $partial->id)->count());
    }
}
