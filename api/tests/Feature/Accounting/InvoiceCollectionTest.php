<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InvoiceCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function newUser(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        return User::create([
            'name'     => 'Finance',
            'email'    => 'fin_' . uniqid() . '@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    private function accountHashId(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->hash_id;
    }

    /**
     * Build a finalized invoice (Draft → Finalized) via the service.
     * Uses account 4010 (Sales Revenue) as revenue line.
     */
    private function makeFinalizedInvoice(
        InvoiceService $svc,
        User $user,
        Customer $customer,
        string $unitPrice = '1000.00',
        bool $isVatable = false,
        ?string $date = null,
        ?string $dueDate = null,
    ): \App\Modules\Accounting\Models\Invoice {
        $revenueId = $this->accountHashId('4010');
        $invoiceDate = $date ?? '2026-04-01';
        $invoice = $svc->create([
            'customer_id' => $customer->hash_id,
            'date'        => $invoiceDate,
            'due_date'    => $dueDate ?? '2026-04-30',
            'is_vatable'  => $isVatable,
            'items'       => [
                [
                    'revenue_account_id' => $revenueId,
                    'description'        => 'Wiper bushings',
                    'quantity'           => '10',
                    'unit_price'         => $unitPrice,
                ],
            ],
        ], $user);

        return $svc->finalize($invoice, $user);
    }

    // ─── Test 1: Full collection ─────────────────────────────────────────────

    public function test_full_collection_marks_invoice_paid_and_posts_balanced_je(): void
    {
        $user     = $this->newUser();
        $customer = Customer::create(['name' => 'Toyota PH', 'payment_terms_days' => 30]);
        $svc      = app(InvoiceService::class);
        $cashId   = $this->accountHashId('1010'); // Cash on Hand

        $invoice = $this->makeFinalizedInvoice($svc, $user, $customer, '1000.00', false);
        // 10 × 1000, no VAT → total = 10000.00
        $this->assertSame('10000.00', (string) $invoice->total_amount);
        $this->assertSame(InvoiceStatus::Finalized, $invoice->status);

        $coll = $svc->recordCollection($invoice->fresh(), [
            'cash_account_id'  => $cashId,
            'collection_date'  => '2026-04-15',
            'amount'           => '10000.00',
            'payment_method'   => PaymentMethod::Cash->value,
            'reference_number' => 'REC-001',
        ], $user);

        $invoice->refresh();

        // Invoice fields
        $this->assertSame(InvoiceStatus::Paid, $invoice->status, 'Invoice should be Paid after full collection.');
        $this->assertSame('10000.00', (string) $invoice->amount_paid);
        $this->assertSame('0.00', (string) $invoice->balance);

        // Collection record
        $this->assertNotNull($coll->id);
        $this->assertSame('10000.00', (string) $coll->amount);
        $this->assertNotNull($coll->journal_entry_id);

        // JE must be balanced and posted
        $je = $coll->journalEntry;
        $this->assertSame(JournalEntryStatus::Posted, $je->status, 'Collection JE must be posted.');
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit, 'JE must be balanced.');

        // Dr Cash / Cr AR structure
        $cashAccount = Account::query()->where('code', '1010')->firstOrFail();
        $arAccount   = Account::query()->where('code', '1100')->firstOrFail();

        $cashLine = $je->lines->firstWhere('account_id', $cashAccount->id);
        $arLine   = $je->lines->firstWhere('account_id', $arAccount->id);

        $this->assertNotNull($cashLine, 'Cash line must exist in collection JE.');
        $this->assertNotNull($arLine, 'AR line must exist in collection JE.');
        $this->assertSame('10000.00', (string) $cashLine->debit, 'Cash must be debited.');
        $this->assertSame('0.00', (string) $cashLine->credit);
        $this->assertSame('0.00', (string) $arLine->debit);
        $this->assertSame('10000.00', (string) $arLine->credit, 'AR must be credited.');
    }

    // ─── Test 2: Partial collection ─────────────────────────────────────────

    public function test_partial_collection_marks_invoice_partial_and_reduces_balance(): void
    {
        $user     = $this->newUser();
        $customer = Customer::create(['name' => 'Nissan PH', 'payment_terms_days' => 30]);
        $svc      = app(InvoiceService::class);
        $cashId   = $this->accountHashId('1010');

        $invoice = $this->makeFinalizedInvoice($svc, $user, $customer, '1000.00', false);
        // total = 10000.00

        $svc->recordCollection($invoice->fresh(), [
            'cash_account_id' => $cashId,
            'collection_date' => '2026-04-10',
            'amount'          => '4000.00',
            'payment_method'  => PaymentMethod::BankTransfer->value,
        ], $user);

        $invoice->refresh();

        $this->assertSame(InvoiceStatus::Partial, $invoice->status, 'Invoice should be Partial after partial collection.');
        $this->assertSame('4000.00', (string) $invoice->amount_paid);
        $this->assertSame('6000.00', (string) $invoice->balance, 'Balance should be total minus paid.');
    }

    // ─── Test 3: Overpayment rejected ────────────────────────────────────────

    public function test_overpayment_is_rejected(): void
    {
        $user     = $this->newUser();
        $customer = Customer::create(['name' => 'Honda PH', 'payment_terms_days' => 30]);
        $svc      = app(InvoiceService::class);
        $cashId   = $this->accountHashId('1010');

        $invoice = $this->makeFinalizedInvoice($svc, $user, $customer, '500.00', false);
        // total = 5000.00

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeds outstanding balance/i');

        $svc->recordCollection($invoice->fresh(), [
            'cash_account_id' => $cashId,
            'collection_date' => '2026-04-10',
            'amount'          => '5001.00',  // 1 peso over
            'payment_method'  => PaymentMethod::Cash->value,
        ], $user);
    }

    // ─── Test 4: Second collection on paid invoice rejected ──────────────────

    public function test_collection_on_paid_invoice_is_rejected(): void
    {
        $user     = $this->newUser();
        $customer = Customer::create(['name' => 'Suzuki PH', 'payment_terms_days' => 30]);
        $svc      = app(InvoiceService::class);
        $cashId   = $this->accountHashId('1010');

        $invoice = $this->makeFinalizedInvoice($svc, $user, $customer, '200.00', false);
        // total = 2000.00

        // First: fully settle
        $svc->recordCollection($invoice->fresh(), [
            'cash_account_id' => $cashId,
            'collection_date' => '2026-04-10',
            'amount'          => '2000.00',
            'payment_method'  => PaymentMethod::Cash->value,
        ], $user);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);

        // Second: must be rejected
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/status is paid/i');

        $svc->recordCollection($invoice->fresh(), [
            'cash_account_id' => $cashId,
            'collection_date' => '2026-04-11',
            'amount'          => '100.00',
            'payment_method'  => PaymentMethod::Cash->value,
        ], $user);
    }

    // ─── Test 5: Aging buckets ────────────────────────────────────────────────

    public function test_aging_buckets_open_invoices_by_days_past_due(): void
    {
        $user     = $this->newUser();
        $customer = Customer::create(['name' => 'Yamaha PH', 'payment_terms_days' => 30]);
        $svc      = app(InvoiceService::class);
        $revenueId = $this->accountHashId('4010');

        // Helper to create a finalized invoice with a specific due date
        $makeInvoice = function (string $dueDate) use ($svc, $user, $customer, $revenueId): \App\Modules\Accounting\Models\Invoice {
            $invoice = $svc->create([
                'customer_id' => $customer->hash_id,
                'date'        => '2026-01-01',
                'due_date'    => $dueDate,
                'is_vatable'  => false,
                'items'       => [
                    [
                        'revenue_account_id' => $revenueId,
                        'description'        => 'Part',
                        'quantity'           => '1',
                        'unit_price'         => '1000.00',
                    ],
                ],
            ], $user);
            return $svc->finalize($invoice, $user);
        };

        // asOf = 2026-06-01 (today in the test context per CLAUDE.md)
        $asOf = Carbon::parse('2026-06-01');

        // current: due_date >= asOf (due today or future)
        $makeInvoice('2026-06-01');   // due today → current

        // d1_30: 1-30 days past due relative to asOf
        $makeInvoice('2026-05-15');   // 17 days past due → d1_30

        // d31_60: 31-60 days past due
        $makeInvoice('2026-04-15');   // 47 days past due → d31_60

        // d61_90: 61-90 days past due
        $makeInvoice('2026-03-15');   // 78 days past due → d61_90

        // d91_plus: >90 days past due
        $makeInvoice('2026-01-15');   // 137 days past due → d91_plus

        $result  = $svc->aging($asOf);
        $buckets = $result['buckets'];

        // Keys present
        $this->assertArrayHasKey('current',  $buckets);
        $this->assertArrayHasKey('d1_30',    $buckets);
        $this->assertArrayHasKey('d31_60',   $buckets);
        $this->assertArrayHasKey('d61_90',   $buckets);
        $this->assertArrayHasKey('d91_plus', $buckets);
        $this->assertArrayHasKey('total',    $buckets);

        // One invoice (1000.00) per bucket
        $this->assertSame('1000.00', $buckets['current'],  'Current bucket wrong.');
        $this->assertSame('1000.00', $buckets['d1_30'],    'd1_30 bucket wrong.');
        $this->assertSame('1000.00', $buckets['d31_60'],   'd31_60 bucket wrong.');
        $this->assertSame('1000.00', $buckets['d61_90'],   'd61_90 bucket wrong.');
        $this->assertSame('1000.00', $buckets['d91_plus'], 'd91_plus bucket wrong.');
        $this->assertSame('5000.00', $buckets['total'],    'Total bucket wrong.');

        // by_customer: one customer with 5000.00 total
        $this->assertCount(1, $result['by_customer']);
        $cust = $result['by_customer'][0];
        $this->assertSame('Yamaha PH', $cust['customer_name']);
        $this->assertSame('5000.00', $cust['total']);

        // Paid invoices must NOT appear in aging
        $cashId = $this->accountHashId('1010');
        $inv    = $makeInvoice('2026-05-01'); // would be d1_30 range
        $svc->recordCollection($inv->fresh(), [
            'cash_account_id' => $cashId,
            'collection_date' => '2026-06-01',
            'amount'          => '1000.00',
            'payment_method'  => PaymentMethod::Cash->value,
        ], $user);

        $result2  = $svc->aging($asOf);
        // Paid invoice should not inflate the total
        $this->assertSame('5000.00', $result2['buckets']['total'], 'Paid invoices must be excluded from aging.');
    }
}
