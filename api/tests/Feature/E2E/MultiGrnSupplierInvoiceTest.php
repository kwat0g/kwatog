<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\SupplierInvoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiGrnSupplierInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_partial_receipts_share_one_supplier_invoice_but_keep_separate_ap_payments(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class, WorkflowSeeder::class]);
        $warehouse = $this->actor('warehouse_staff');
        $quality = $this->actor('qc_inspector');
        $finance = $this->actor('finance_officer');
        $vendor = Vendor::factory()->create();
        $supplier = SupplierPortalUser::create([
            'vendor_id' => $vendor->id, 'name' => 'Supplier', 'email' => 'e2e-supplier@ogami.test',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id, 'status' => 'acknowledged', 'is_vatable' => false]);
        $item = Item::factory()->create();
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item->id, 'description' => 'Standard Poly Bag',
            'quantity' => '20000.00', 'unit' => 'pcs', 'unit_price' => '0.50', 'total' => '10000.00',
            'quantity_received' => '20000.00',
        ]);
        $location = WarehouseLocation::factory()->create();
        $service = app(BillService::class);
        $bills = [];
        foreach (['12000.00', '8000.00'] as $quantity) {
            $grn = GoodsReceiptNote::factory()->create([
                'vendor_id' => $vendor->id, 'purchase_order_id' => $po->id,
                'status' => 'accepted', 'received_by' => $warehouse->id,
                'accepted_by' => $quality->id, 'accepted_at' => now(),
            ]);
            GrnItem::create([
                'goods_receipt_note_id' => $grn->id, 'purchase_order_item_id' => $poItem->id,
                'item_id' => $item->id, 'location_id' => $location->id,
                'quantity_received' => $quantity, 'quantity_accepted' => $quantity,
                'unit_cost' => '0.50',
            ]);
            $bills[] = $service->createDraftForGrn($grn, $warehouse);
        }

        $this->assertCount(2, $bills);
        $this->assertSame(BillStatus::Draft, $bills[0]->status);
        $this->assertSame(BillStatus::Draft, $bills[1]->status);
        $this->assertSame(0, Bill::query()->whereNotNull('journal_entry_id')->count());

        Sanctum::actingAs($supplier, ['*'], 'supplier_portal');
        $this->postJson("/api/v1/b2b/supplier/purchase-orders/{$po->hash_id}/submit-invoice", [
            'bill_number' => 'SUP-E2E-001', 'date' => now()->toDateString(),
            'gross_total' => '10000.00',
            'bill_ids' => [$bills[0]->hash_id, $bills[1]->hash_id],
        ])->assertCreated();
        $invoice = SupplierInvoice::query()->where('number', 'SUP-E2E-001')->firstOrFail();
        $this->assertSame(2, $invoice->bills()->count());

        foreach ($bills as $bill) {
            $service->postDraft($bill->fresh(), $finance);
        }
        $this->assertSame(2, Bill::query()->where('status', 'unpaid')->count());

        $cash = Account::query()->where('code', '1020')->firstOrFail();
        foreach ($bills as $index => $bill) {
            $bill = $bill->fresh();
            $payment = $service->recordPayment($bill, [
                'cash_account_id' => $cash->hash_id,
                'payment_date' => now()->toDateString(),
                'amount' => (string) $bill->balance,
                'payment_method' => 'bank_transfer',
            ], $finance);
            $this->assertSame('posted', $payment->status->value);
            $this->assertSame(BillStatus::Paid, $bill->fresh()->status);
            if ($index === 0) {
                $this->getJson("/api/v1/b2b/supplier/supplier-invoices/{$invoice->hash_id}")
                    ->assertOk()->assertJsonPath('data.balance', '4000.00');
                $this->getJson('/api/v1/b2b/supplier/dashboard')
                    ->assertOk()->assertJsonPath('data.unpaid_invoice_count', 1);
                $this->getJson('/api/v1/b2b/supplier/statement-of-account')
                    ->assertOk()->assertJsonCount(1, 'data.open_invoices')
                    ->assertJsonPath('data.open_invoices.0.balance', '4000.00');
            }
        }
        $this->getJson("/api/v1/b2b/supplier/supplier-invoices/{$invoice->hash_id}")
            ->assertOk()->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.status', 'paid');
    }

    private function actor(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'is_active' => true,
        ]);
    }
}
