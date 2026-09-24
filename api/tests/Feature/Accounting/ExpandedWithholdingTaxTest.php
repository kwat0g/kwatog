<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\BillPaymentStatus;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Enums\WithholdingTaxType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillPayment;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Accounting\Services\Bir2307Service;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Common\Support\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpandedWithholdingTaxTest extends TestCase
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
            'name' => 'Test User', 'email' => 'user_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
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

    private function approvePayment(BillService $service, Bill $bill, BillPayment $payment): BillPayment
    {
        $financeChecker = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $service->approvePayment($bill->fresh(), $payment->fresh(), $financeChecker);

        $vp = User::factory()->create(['role_id' => Role::where('slug', 'vice_president')->value('id')]);

        return $service->approvePayment($bill->fresh(), $payment->fresh(), $vp);
    }

    public function test_goods_vendor_bill_calculates_ewt_1_percent(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'Resin Supplier',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-001',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '10', 'unit_price' => '1000.00'],
            ],
        ], $user);

        // Subtotal 10,000, VAT 12% = 1,200, total 11,200
        // EWT = 10,000 × 0.01 = 100.00
        $this->assertSame('10000.00', (string) $bill->subtotal);
        $this->assertSame('1200.00', (string) $bill->vat_amount);
        $this->assertSame('11200.00', (string) $bill->total_amount);
        $this->assertSame(WithholdingTaxType::Goods, $bill->withholding_tax_type);
        $this->assertSame('0.0100', (string) $bill->ewt_rate);
        $this->assertSame('100.00', (string) $bill->ewt_amount);
    }

    public function test_services_vendor_bill_calculates_ewt_2_percent(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'Consulting',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::Services->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-001',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Service', 'quantity' => '1', 'unit_price' => '5000.00'],
            ],
        ], $user);

        // EWT = 5,000 × 0.02 = 100.00
        $this->assertSame('100.00', (string) $bill->ewt_amount);
    }

    public function test_none_vendor_bill_has_zero_ewt(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'Local Vendor',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::None->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-001',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Item', 'quantity' => '1', 'unit_price' => '1000.00'],
            ],
        ], $user);

        $this->assertSame('0.00', (string) $bill->ewt_amount);
        $this->assertSame(WithholdingTaxType::None, $bill->withholding_tax_type);
    }

    public function test_full_payment_posts_ewt_je_and_settles_balance(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'Goods Vendor',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $ewtAccountId = Account::query()->where('code', '2051')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-001',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '10', 'unit_price' => '1000.00'],
            ],
        ], $user);

        // Bill total 11,200; EWT 100
        $this->assertSame('11200.00', (string) $bill->total_amount);
        $this->assertSame('100.00', (string) $bill->ewt_amount);

        // Full payment: amount = 11,200, ewt = 100, cash = 11,100
        $payment = $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-12',
            'amount' => '11200.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'idempotency_key' => 'full-payment',
        ], $user);

        $payment = $this->approvePayment($svc, $bill, $payment);
        $bill->refresh();

        // Bill should be paid
        $this->assertSame(BillStatus::Paid, $bill->status);
        $this->assertSame('11200.00', (string) $bill->amount_paid);
        $this->assertSame('0.00', (string) $bill->balance);

        // Payment should have EWT and cash recorded
        $this->assertSame('100.00', (string) $payment->ewt_amount);
        $this->assertSame('11100.00', (string) $payment->cash_amount);

        // JE should have 3 lines: AP, Cash, EWT Payable
        $je = $payment->journalEntry;
        $this->assertNotNull($je);
        $this->assertCount(3, $je->lines);

        // Check JE balance
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit);
    }

    public function test_two_partial_payments_withhold_exactly_bill_ewt_total(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'Goods Vendor',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-001',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '10', 'unit_price' => '1000.00'],
            ],
        ], $user);

        // First payment: 50% = 5,600
        $payment1 = $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-12',
            'amount' => '5600.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'idempotency_key' => 'payment-1',
        ], $user);
        $payment1 = $this->approvePayment($svc, $bill, $payment1);

        // Second payment: remaining 5,600
        $payment2 = $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-15',
            'amount' => '5600.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'idempotency_key' => 'payment-2',
        ], $user);
        $payment2 = $this->approvePayment($svc, $bill, $payment2);

        // Total EWT withheld should equal bill.ewt_amount (100)
        $totalEwt = Money::add((string) $payment1->ewt_amount, (string) $payment2->ewt_amount);
        $this->assertSame('100.00', $totalEwt);
    }

    public function test_partial_payment_ewt_is_not_skewed_by_a_truncated_ratio(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'Goods Vendor',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);
        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-RATIO',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => Account::query()->where('code', '5010')->firstOrFail()->hash_id, 'description' => 'Resin', 'quantity' => '10', 'unit_price' => '5880.00'],
            ],
        ], $user);
        $this->assertSame('65856.00', (string) $bill->total_amount);
        $this->assertSame('588.00', (string) $bill->ewt_amount);

        // 65268 / 65856 = 0.99107… — truncating the ratio to 0.9910 withheld
        // 582.71 against a 2307 base of 58,275.00 that certifies 582.75.
        $payment = $this->approvePayment($svc, $bill, $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => Account::query()->where('code', '1020')->firstOrFail()->hash_id,
            'payment_date' => '2026-04-12',
            'amount' => '65268.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ], $user));

        $this->assertSame('582.75', (string) $payment->ewt_amount);
        $this->assertSame('64685.25', (string) $payment->cash_amount);
    }

    public function test_pending_payment_shows_the_cash_the_approver_will_disburse(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'Goods Vendor',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);
        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-PEND',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => Account::query()->where('code', '5010')->firstOrFail()->hash_id, 'description' => 'Resin', 'quantity' => '10', 'unit_price' => '5880.00'],
            ],
        ], $user);
        $payment = $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => Account::query()->where('code', '1020')->firstOrFail()->hash_id,
            'payment_date' => '2026-04-12',
            'amount' => '65268.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ], $user);

        $fin = User::factory()->create([
            'role_id' => Role::where('slug', 'finance_officer')->value('id'),
            'is_active' => true,
        ]);
        $row = $this->actingAs($fin)->getJson('/api/v1/bills/'.$bill->hash_id)
            ->assertOk()
            ->json('data.payments.0');

        // Same figures posting withholds (see the truncated-ratio test).
        $this->assertSame('pending_approval', $row['status']);
        $this->assertSame('582.75', $row['ewt_amount']);
        $this->assertSame('64685.25', $row['cash_amount']);
        // A projection only: nothing is withheld until the payment posts.
        $this->assertSame('0.00', (string) $payment->fresh()->ewt_amount);
    }

    public function test_payment_exceeding_unreserved_balance_is_rejected(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'Goods Vendor',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-001',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => false,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Item', 'quantity' => '1', 'unit_price' => '10000.00'],
            ],
        ], $user);

        // Create first pending payment for full balance
        $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-12',
            'amount' => '10000.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'idempotency_key' => 'payment-1',
        ], $user);

        // Try to create second payment for same amount (should fail)
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('exceeds the unreserved balance');
        $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-15',
            'amount' => '10000.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'idempotency_key' => 'payment-2',
        ], $user);
    }

    public function test_voided_payment_reverses_ewt_je(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'Goods Vendor',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-001',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '10', 'unit_price' => '1000.00'],
            ],
        ], $user);

        $payment = $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-12',
            'amount' => '11200.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'idempotency_key' => 'payment-1',
        ], $user);
        $payment = $this->approvePayment($svc, $bill, $payment);

        $bill->refresh();
        $this->assertSame(BillStatus::Paid, $bill->status);

        // Void the payment
        $voidUser = User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
        $svc->voidPayment($bill->fresh(), $payment->fresh(), [
            'void_date' => '2026-04-20',
            'reason' => 'Testing void',
        ], $voidUser);

        $bill->refresh();
        $this->assertSame(BillStatus::Unpaid, $bill->status);
        $this->assertSame('0.00', (string) $bill->amount_paid);
        $this->assertSame('11200.00', (string) $bill->balance);
    }

    public function test_bir_2307_quarterly_summary_generated(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create([
            'name' => 'Goods Supplier',
            'payment_terms_days' => 30,
            'tin' => '123456789012',
            'address' => '123 Main St',
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;

        $svc = app(BillService::class);

        // Create bill for April (Q2, month 4)
        $bill = $svc->create([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-001',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '10', 'unit_price' => '1000.00'],
            ],
        ], $user);

        // Payment in April
        $payment = $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-12',
            'amount' => '11200.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
            'idempotency_key' => 'payment-1',
        ], $user);
        $payment = $this->approvePayment($svc, $bill, $payment);

        // Generate BIR 2307 for Q2 (April-June)
        $bir2307Svc = app(Bir2307Service::class);
        $data = $bir2307Svc->forVendorQuarter($vendor, 2026, 2);

        // Check vendor data
        $this->assertSame('Goods Supplier', $data['vendor']['name']);
        $this->assertSame('123456789012', $data['vendor']['tin']);

        // Check summary
        $this->assertSame('100.00', $data['summary']['total_tax_withheld']);
        // Income payment is net of VAT: 11,200 × 10,000 / 11,200. A 4-dp ratio
        // (0.8928) used to understate it as 9,999.36.
        $this->assertSame('10000.00', $data['summary']['total_gross']);

        // Check income payments
        $this->assertNotEmpty($data['income_payments']);
        $this->assertSame(4, $data['income_payments'][0]['month']); // April
        $this->assertSame('WC158', $data['income_payments'][0]['atc']); // ATC for goods
    }

    public function test_post_draft_rederives_ewt_from_vendor_classification(): void
    {
        $user = $this->newUser();
        // Create vendor with NO withholding tax initially
        $vendor = Vendor::create([
            'name' => 'Dynamic Vendor',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::None->value,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        // Create a draft bill when vendor has type none
        $bill = $svc->createDraft([
            ...$this->serviceException(),
            'bill_number' => 'INV-2026-ReDrive',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => true,
            'items' => [
                ['expense_account_id' => $expenseId, 'description' => 'Item', 'quantity' => '100', 'unit_price' => '100.00'],
            ],
        ], $user);

        // Verify draft has zero EWT (vendor type is None)
        $this->assertSame(WithholdingTaxType::None, $bill->withholding_tax_type);
        $this->assertSame('0.00', (string) $bill->ewt_amount);

        // Now change vendor classification to Goods (1%)
        $vendor->forceFill(['withholding_tax_type' => WithholdingTaxType::Goods->value])->save();

        // Post the draft — postDraft should re-derive EWT from vendor's CURRENT classification
        $fin = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $posted = $svc->postDraft($bill->fresh(), $fin);

        // After posting, bill should have Goods EWT (subtotal 10000 × 0.01 = 100.00)
        $this->assertSame(WithholdingTaxType::Goods, $posted->withholding_tax_type);
        $this->assertSame('0.0100', (string) $posted->ewt_rate);
        $this->assertSame('100.00', (string) $posted->ewt_amount);
    }

    public function test_bir_2307_endpoint_requires_bills_view_and_validates_quarter(): void
    {
        // Create vendor
        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'payment_terms_days' => 30,
            'tin' => '111111111111',
            'address' => 'Test Address',
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);

        // Test permission gate: user without accounting.bills.view should get 403
        $employee = User::factory()->create([
            'role_id' => Role::where('slug', 'employee')->value('id'),
            'is_active' => true,
        ]);
        $response = $this->actingAs($employee)->getJson(
            '/api/v1/vendors/' . $vendor->hash_id . '/bir-2307?year=2026&quarter=2'
        );
        $this->assertSame(403, $response->status());

        // Test with finance_officer who has accounting.bills.view
        $fin = User::factory()->create([
            'role_id' => Role::where('slug', 'finance_officer')->value('id'),
            'is_active' => true,
        ]);
        $response = $this->actingAs($fin)->getJson(
            '/api/v1/vendors/' . $vendor->hash_id . '/bir-2307?year=2026&quarter=2'
        );
        $this->assertSame(200, $response->status());
        $this->assertArrayHasKey('data', $response->json());

        // Test quarter validation: quarter=5 should return 422
        $response = $this->actingAs($fin)->getJson(
            '/api/v1/vendors/' . $vendor->hash_id . '/bir-2307?year=2026&quarter=5'
        );
        $this->assertSame(422, $response->status());
    }

    public function test_bir_2307_pdf_endpoint_streams_pdf(): void
    {
        $user = $this->newUser('finance_officer');
        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'payment_terms_days' => 30,
            'tin' => '111111111111',
            'address' => 'Test Address',
            'withholding_tax_type' => WithholdingTaxType::Goods->value,
        ]);

        // Test permission gate: user without accounting.bills.view should get 403
        $employee = $this->newUser('employee');
        $response = $this->actingAs($employee)->get(
            '/api/v1/vendors/' . $vendor->hash_id . '/bir-2307/pdf?year=2026&quarter=2'
        );
        $this->assertSame(403, $response->status());

        // Test with finance_officer who has accounting.bills.view
        $response = $this->actingAs($user)->get(
            '/api/v1/vendors/' . $vendor->hash_id . '/bir-2307/pdf?year=2026&quarter=2'
        );
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename=', $response->headers->get('Content-Disposition'));
    }

    public function test_vendor_api_sets_withholding_tax_type(): void
    {
        $admin = $this->newUser('system_admin');
        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'payment_terms_days' => 30,
            'withholding_tax_type' => WithholdingTaxType::None->value,
        ]);

        // Update vendor with withholding_tax_type = services
        $response = $this->actingAs($admin)->putJson(
            '/api/v1/vendors/' . $vendor->hash_id,
            [
                'name' => 'Test Vendor',
                'payment_terms_days' => 30,
                'withholding_tax_type' => 'services',
            ]
        );

        $this->assertSame(200, $response->status());
        $this->assertSame('services', $response->json('data.withholding_tax_type'));

        // Verify database updated
        $vendor->refresh();
        $this->assertSame(WithholdingTaxType::Services, $vendor->withholding_tax_type);

        // Test invalid value should return 422
        $response = $this->actingAs($admin)->putJson(
            '/api/v1/vendors/' . $vendor->hash_id,
            [
                'name' => 'Test Vendor',
                'payment_terms_days' => 30,
                'withholding_tax_type' => 'bogus',
            ]
        );

        $this->assertSame(422, $response->status());
    }
}
