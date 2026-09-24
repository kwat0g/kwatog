<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Enums\WithholdingTaxType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PDF Blade view rendering tests for EWT and RFQ charges.
 *
 * Tests that the Blade templates render HTML correctly when:
 * 1. Bill with EWT shows EWT rows in totals and payment history
 * 2. Bill without EWT hides EWT rows
 * 3. PO with header/line charges shows charge rows and line notes
 * 4. PO without charges hides charge rows and line notes
 */
class PdfEwtAndChargesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed([ChartOfAccountsSeeder::class, SettingsSeeder::class, WorkflowSeeder::class]);
    }

    private function newUser(string $role = 'system_admin'): User
    {
        $roleId = Role::query()->where('slug', $role)->value('id');
        return User::create([
            'name' => 'Test User',
            'email' => 'user_' . uniqid() . '@x.test',
            'password' => bcrypt('Password1!'),
            'role_id' => $roleId,
        ]);
    }

    private function serviceException(): array
    {
        return [
            'provenance_type' => 'service',
            'exception_evidence' => 'Test service for EWT testing.',
            'exception_approved' => true,
        ];
    }

    private function approvePayment(BillService $service, Bill $bill, $payment): void
    {
        $financeChecker = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $service->approvePayment($bill->fresh(), $payment->fresh(), $financeChecker);

        $vp = User::factory()->create(['role_id' => Role::where('slug', 'vice_president')->value('id')]);
        $service->approvePayment($bill->fresh(), $payment->fresh(), $vp);
    }

    public function test_bill_with_ewt_shows_ewt_rows_in_html(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'XX-T-' . substr(uniqid(), -5),
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-' . substr(uniqid(), -5),
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '10', 'unit_price' => '1000.00'],
            ],
        ], $user);

        // Record payment
        $payment = $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-12',
            'amount' => '11200.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'idempotency_key' => 'test-ewt-payment',
        ], $user);
        $this->approvePayment($svc, $bill, $payment);

        $bill->load(['vendor', 'items.expenseAccount', 'payments.cashAccount']);

        // Render the view to HTML
        $html = view('pdf.bill', ['bill' => $bill])->render();

        // Assert EWT rows appear in totals section
        $this->assertStringContainsString('Less: EWT WC158 (1%)', $html, 'EWT label should show ATC code and rate');
        $this->assertStringContainsString('(100.00)', $html, 'EWT amount should appear in parentheses');
        $this->assertStringContainsString('Net Payable to Supplier', $html, 'Net payable line should appear');
        $this->assertStringContainsString('11,100.00', $html, 'Net payable amount should be total - ewt (formatted with comma)');

        // Assert EWT and Cash columns appear in payment history table header
        $this->assertStringContainsString('Payment History', $html, 'Payment history section should exist');
        $this->assertStringContainsString('<th class="r">EWT</th>', $html, 'EWT column header should exist');
        $this->assertStringContainsString('<th class="r">Cash</th>', $html, 'Cash column header should exist');
    }

    public function test_bill_without_ewt_hides_ewt_rows_in_html(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'XX-T-' . substr(uniqid(), -5),
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::None->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-' . substr(uniqid(), -5),
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Item', 'quantity' => '1', 'unit_price' => '1000.00'],
            ],
        ], $user);

        // Record payment
        $payment = $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-12',
            'amount' => '1120.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'idempotency_key' => 'test-no-ewt-payment',
        ], $user);
        $this->approvePayment($svc, $bill, $payment);

        $bill->load(['vendor', 'items.expenseAccount', 'payments.cashAccount']);

        // Render the view to HTML
        $html = view('pdf.bill', ['bill' => $bill])->render();

        // Assert EWT rows do NOT appear
        $this->assertStringNotContainsString('Less: EWT', $html, 'EWT label should not appear when ewt_amount is 0');
        $this->assertStringNotContainsString('Net Payable to Supplier', $html, 'Net payable line should not appear when ewt_amount is 0');

        // Assert EWT and Cash columns do NOT appear in payment history
        $this->assertStringNotContainsString('<th class="r">EWT</th>', $html, 'EWT column header should not appear when ewt_amount is 0');
        $this->assertStringNotContainsString('<th class="r">Cash</th>', $html, 'Cash column header should not appear when ewt_amount is 0');
    }

    public function test_po_with_header_and_line_charges_shows_charge_rows_in_html(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'XX-T-' . substr(uniqid(), -5),
            'payment_terms_days' => 30,
        ]);

        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $user->id,
            'vendor_id' => $vendor->id,
            'rfq_freight_amount' => '200.00',
            'rfq_other_charges' => '0.00',
            'subtotal' => '2050.00',
            'vat_amount' => '0.00',
            'total_amount' => '2050.00',
        ]);

        $item1 = Item::factory()->create(['item_type' => ItemType::RawMaterial]);
        $item2 = Item::factory()->create(['item_type' => ItemType::RawMaterial]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item1->id,
            'description' => 'Material A',
            'quantity' => '100.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '1050.00',
            'rfq_line_freight_amount' => '50.00',
            'rfq_line_other_charges' => '0.00',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item2->id,
            'description' => 'Material B',
            'quantity' => '50.000',
            'unit' => 'kg',
            'unit_price' => '20.00',
            'total' => '1000.00',
            'rfq_line_freight_amount' => '0.00',
            'rfq_line_other_charges' => '0.00',
        ]);

        $po->load(['vendor', 'items.item']);
        $approvals = collect();

        // Render the view to HTML
        $html = view('pdf.purchase-order', [
            'po' => $po,
            'now' => now(),
            'approvals' => $approvals,
        ])->render();

        // Assert header charge rows appear
        $this->assertStringContainsString('Lines:', $html, 'Lines row should appear when header charges exist');
        $this->assertStringContainsString('Freight &amp; other charges:', $html, 'Freight & other charges row should appear');
        $this->assertStringContainsString('1,850.00', $html, 'Lines subtotal (2050 - 200) should appear');
        $this->assertStringContainsString('200.00', $html, 'Header charges amount should appear');

        // Assert line charge notes appear
        $this->assertStringContainsString('Total includes line freight/charges ₱ 50.00', $html, 'Line 1 freight note should appear');
        // Line 2 has 0 charges, so it should not have the note
    }

    public function test_po_without_charges_hides_charge_rows_in_html(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'XX-T-' . substr(uniqid(), -5),
            'payment_terms_days' => 30,
        ]);

        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $user->id,
            'vendor_id' => $vendor->id,
            'rfq_freight_amount' => '0.00',
            'rfq_other_charges' => '0.00',
            'subtotal' => '1000.00',
            'vat_amount' => '0.00',
            'total_amount' => '1000.00',
        ]);

        $item = Item::factory()->create(['item_type' => ItemType::RawMaterial]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Plain Material',
            'quantity' => '100.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'rfq_line_freight_amount' => '0.00',
            'rfq_line_other_charges' => '0.00',
        ]);

        $po->load(['vendor', 'items.item']);
        $approvals = collect();

        // Render the view to HTML
        $html = view('pdf.purchase-order', [
            'po' => $po,
            'now' => now(),
            'approvals' => $approvals,
        ])->render();

        // Assert header charge rows do NOT appear
        $this->assertStringNotContainsString('Lines:', $html, 'Lines row should not appear when no header charges');
        $this->assertStringNotContainsString('Freight &amp; other charges:', $html, 'Freight & other charges row should not appear when no charges');

        // Assert line charge notes do NOT appear
        $this->assertStringNotContainsString('Total includes line freight/charges', $html, 'Line freight note should not appear when no line charges');

        // Subtotal should still appear in totals
        $this->assertStringContainsString('1,000.00', $html, 'Subtotal should still appear');
    }
}
