<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ChainStepRun;
use App\Modules\Accounting\Enums\CreditNoteStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\CreditNoteApplication;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Services\CreditNoteService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\SalesOrder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REC-13 — credit-note instrument. The reconciliation-critical assertions:
 *   - finalize posts a BALANCED, VAT-reversing journal entry;
 *   - applying a customer credit reduces the invoice balance + advances status;
 *   - the supplier (AP) mirror reduces the bill balance;
 *   - over-applying beyond the credit's balance is rejected.
 */
class CreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private CreditNoteService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->svc = app(CreditNoteService::class);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function revenueAccountId(): string
    {
        return (string) Account::query()->where('code', '4010')->value('id');
    }

    private function postedJournalEntry(User $by): JournalEntry
    {
        return JournalEntry::create([
            'entry_number' => 'JE-CN-'.uniqid(),
            'date' => now()->toDateString(),
            'description' => 'Posted target document fixture',
            'total_debit' => '1.00',
            'total_credit' => '1.00',
            'status' => JournalEntryStatus::Posted,
            'posted_at' => now(),
            'posted_by' => $by->id,
        ]);
    }

    public function test_finalize_posts_a_balanced_vat_reversing_entry(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);

        $cn = $this->svc->create([
            'type'        => 'customer',
            'date'        => now()->toDateString(),
            'is_vatable'  => true,
            'customer_id' => $customer->id,
            'reason'      => 'Price dispute',
            'lines'       => [
                ['account_id' => $this->revenueAccountId(), 'description' => 'Overbilled units', 'amount' => '1000.00'],
            ],
        ], $by);

        $this->assertSame(CreditNoteStatus::Draft, $cn->status);
        $this->assertSame('1000.00', $cn->subtotal);
        $this->assertSame('120.00', $cn->vat_amount);
        $this->assertSame('1120.00', $cn->total_amount);

        $finalized = $this->svc->finalize($cn, $by);
        $this->assertSame(CreditNoteStatus::Finalized, $finalized->status);
        $this->assertNotNull($finalized->credit_note_number);
        $this->assertNotNull($finalized->journal_entry_id);

        // The posted JE must be balanced (debit == credit).
        $je = \App\Modules\Accounting\Models\JournalEntry::find($finalized->journal_entry_id);
        $this->assertNotNull($je);
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit, 'Credit-note JE must balance');
        // DR revenue (1000) + DR VAT output (120) = CR AR (1120).
        $this->assertSame('1120.00', (string) $je->total_debit);
    }

    public function test_unapplied_finalized_credit_note_can_be_voided_with_a_balanced_reversal(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Voidable Credit', 'payment_terms_days' => 30]);
        $finalized = $this->svc->finalize($this->svc->create([
            'type' => 'customer',
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'customer_id' => $customer->id,
            'lines' => [[
                'account_id' => $this->revenueAccountId(),
                'description' => 'Void fixture',
                'amount' => '100.00',
            ]],
        ], $by), $by);

        $void = $this->svc->void($finalized, $by, 'Duplicate credit note');

        $this->assertSame(CreditNoteStatus::Void, $void->status);
        $this->assertSame('0.00', (string) $void->balance);
        $this->assertNotNull($void->void_reversal_journal_entry_id);
        $this->assertSame(
            JournalEntryStatus::Reversed,
            JournalEntry::query()->findOrFail($finalized->journal_entry_id)->status,
        );
        $this->assertSame(
            '100.00',
            (string) JournalEntry::query()->findOrFail($void->void_reversal_journal_entry_id)->total_debit,
        );
    }

    public function test_applied_credit_note_cannot_be_voided_in_place(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Applied Credit', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CN-APPLIED-'.substr(uniqid(), -5),
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'is_vatable' => false,
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
            'amount_paid' => '0.00',
            'balance' => '100.00',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'journal_entry_id' => $this->postedJournalEntry($by)->id,
            'created_by' => $by->id,
        ]);
        $cn = $this->svc->finalize($this->svc->create([
            'type' => 'customer', 'date' => now()->toDateString(), 'is_vatable' => false,
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'lines' => [['account_id' => $this->revenueAccountId(), 'description' => 'Applied', 'amount' => '50.00']],
        ], $by), $by);
        $this->svc->apply($cn, ['invoice_id' => $invoice->id, 'amount' => '50.00'], $by);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('unwound before it can be voided');
        $this->svc->void($cn, $by, 'Attempted direct void');
    }

    public function test_customer_credit_note_inherits_vat_treatment_from_source_invoice(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Exempt Customer', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CN-EXEMPT-'.substr(uniqid(), -5),
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'is_vatable' => false,
            'subtotal' => '1000.00',
            'vat_amount' => '0.00',
            'total_amount' => '1000.00',
            'amount_paid' => '0.00',
            'balance' => '1000.00',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'journal_entry_id' => $this->postedJournalEntry($by)->id,
            'created_by' => $by->id,
        ]);

        $creditNote = $this->svc->create([
            'type' => 'customer',
            'date' => now()->toDateString(),
            // A caller must not make an exempt source taxable by supplying true.
            'is_vatable' => true,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'lines' => [[
                'account_id' => $this->revenueAccountId(),
                'description' => 'Exempt return',
                'amount' => '100.00',
            ]],
        ], $by);

        $this->assertFalse($creditNote->is_vatable);
        $this->assertSame('0.00', (string) $creditNote->vat_amount);
        $this->assertSame('100.00', (string) $creditNote->total_amount);
    }

    public function test_credit_note_rejects_a_source_invoice_that_was_never_finalized(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Draft Source Co', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CN-DRAFT-'.substr(uniqid(), -5),
            'customer_id' => $customer->id,
            'status' => 'draft',
            'is_vatable' => false,
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
            'amount_paid' => '0.00',
            'balance' => '100.00',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'created_by' => $by->id,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('finalized, partially paid, or paid invoice');

        $this->svc->create([
            'type' => 'customer',
            'date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'lines' => [[
                'account_id' => $this->revenueAccountId(),
                'description' => 'Credit against an unposted source',
                'amount' => '50.00',
            ]],
        ], $by);
    }

    public function test_finalize_rejects_a_credit_note_whose_source_was_cancelled(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Cancelled Source Co', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CN-CANCELLED-'.substr(uniqid(), -5),
            'customer_id' => $customer->id,
            'status' => 'finalized',
            'is_vatable' => false,
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
            'amount_paid' => '0.00',
            'balance' => '100.00',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'journal_entry_id' => $this->postedJournalEntry($by)->id,
            'created_by' => $by->id,
        ]);

        $creditNote = $this->svc->create([
            'type' => 'customer',
            'date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'lines' => [[
                'account_id' => $this->revenueAccountId(),
                'description' => 'Return credit',
                'amount' => '50.00',
            ]],
        ], $by);

        // The source is cancelled after the credit note was staged — the
        // finalize boundary must re-check, not trust the draft-time state.
        $invoice->forceFill(['status' => 'cancelled'])->save();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('finalized, partially paid, or paid invoice');

        $this->svc->finalize($creditNote, $by);
    }

    public function test_customer_credit_note_cannot_reference_another_customers_invoice(): void
    {
        $by = $this->admin();
        $declaredCustomer = Customer::create(['name' => 'Declared Customer', 'payment_terms_days' => 30]);
        $sourceCustomer = Customer::create(['name' => 'Source Customer', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CN-PARTY-'.substr(uniqid(), -5),
            'customer_id' => $sourceCustomer->id,
            'status' => 'finalized',
            'is_vatable' => false,
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
            'amount_paid' => '0.00',
            'balance' => '100.00',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'journal_entry_id' => $this->postedJournalEntry($by)->id,
            'created_by' => $by->id,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('different customer');

        $this->svc->create([
            'type' => 'customer',
            'date' => now()->toDateString(),
            'customer_id' => $declaredCustomer->id,
            'invoice_id' => $invoice->id,
            'lines' => [[
                'account_id' => $this->revenueAccountId(),
                'description' => 'Cross-party credit',
                'amount' => '50.00',
            ]],
        ], $by);
    }

    public function test_applying_customer_credit_reduces_invoice_balance(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CN-'.substr(uniqid(), -5), 'customer_id' => $customer->id,
            'status' => 'finalized', 'subtotal' => '1000.00', 'vat_amount' => '120.00',
            'total_amount' => '1120.00', 'amount_paid' => '0.00', 'balance' => '1120.00',
            'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'journal_entry_id' => $this->postedJournalEntry($by)->id,
            'created_by' => $by->id,
        ]);

        $cn = $this->svc->finalize($this->svc->create([
            'type' => 'customer', 'date' => now()->toDateString(), 'is_vatable' => true,
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'lines' => [['account_id' => $this->revenueAccountId(), 'description' => 'Credit', 'amount' => '500.00']],
        ], $by), $by);

        // Apply 560 (500 + 12% VAT) against the invoice.
        $this->svc->apply($cn, ['amount' => '560.00', 'invoice_id' => $invoice->id], $by);

        $invoice->refresh();
        $this->assertSame('560.00', (string) $invoice->balance, 'Invoice balance must drop by the applied amount');
        $this->assertSame('partial', $invoice->status->value);
        $this->assertTrue(ChainStepRun::query()
            ->where('chain', 'o2c')
            ->where('entity_type', 'invoice')
            ->where('entity_id', $invoice->id)
            ->where('step', 'partial')
            ->exists(), 'Customer credit application must publish the invoice chain step.');

        $cn->refresh();
        $this->assertSame('560.00', (string) $cn->applied_amount);
        // CN total = 500 + 12% VAT = 560; fully applied → balance 0, status applied.
        $this->assertSame('0.00', (string) $cn->balance);
        $this->assertSame('applied', $cn->status->value);
    }

    public function test_fully_applying_customer_credit_promotes_linked_sales_order_to_paid(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $so = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $by->id,
            'status' => SalesOrderStatus::Invoiced->value,
        ]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CN-PAID-'.substr(uniqid(), -5),
            'customer_id' => $customer->id,
            'sales_order_id' => $so->id,
            'status' => 'finalized',
            'subtotal' => '500.00',
            'vat_amount' => '60.00',
            'total_amount' => '560.00',
            'amount_paid' => '0.00',
            'balance' => '560.00',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'journal_entry_id' => $this->postedJournalEntry($by)->id,
            'created_by' => $by->id,
        ]);

        $cn = $this->svc->finalize($this->svc->create([
            'type' => 'customer', 'date' => now()->toDateString(), 'is_vatable' => true,
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'lines' => [['account_id' => $this->revenueAccountId(), 'description' => 'Credit', 'amount' => '500.00']],
        ], $by), $by);

        $this->svc->apply($cn, ['amount' => '560.00', 'invoice_id' => $invoice->id], $by);

        $this->assertSame(SalesOrderStatus::Paid, $so->fresh()->status);
    }

    public function test_applying_a_stale_credit_note_cannot_be_replayed(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CN-'.substr(uniqid(), -5), 'customer_id' => $customer->id,
            'status' => 'finalized', 'subtotal' => '1000.00', 'vat_amount' => '120.00',
            'total_amount' => '1120.00', 'amount_paid' => '0.00', 'balance' => '1120.00',
            'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'journal_entry_id' => $this->postedJournalEntry($by)->id,
            'created_by' => $by->id,
        ]);

        $cn = $this->svc->finalize($this->svc->create([
            'type' => 'customer', 'date' => now()->toDateString(), 'is_vatable' => true,
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'lines' => [['account_id' => $this->revenueAccountId(), 'description' => 'Credit', 'amount' => '500.00']],
        ], $by), $by);
        $stale = $cn->fresh();

        $this->svc->apply($cn, ['amount' => '560.00', 'invoice_id' => $invoice->id], $by);

        try {
            $this->svc->apply($stale, ['amount' => '560.00', 'invoice_id' => $invoice->id], $by);
            $this->fail('A stale finalized credit note must not be applied twice.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('Only a finalized credit note can be applied.', $e->getMessage());
        }

        $this->assertSame(1, CreditNoteApplication::query()->where('credit_note_id', $cn->id)->count());
        $this->assertSame('560.00', (string) $invoice->fresh()->balance);
    }

    public function test_supplier_credit_reduces_bill_balance(): void
    {
        $by = $this->admin();
        $vendor = Vendor::create(['name' => 'Supplier Co', 'payment_terms_days' => 30]);
        $bill = Bill::create([
            'bill_number' => 'BILL-CN-'.substr(uniqid(), -5), 'vendor_id' => $vendor->id,
            'status' => 'unpaid', 'subtotal' => '2000.00', 'vat_amount' => '240.00',
            'total_amount' => '2240.00', 'amount_paid' => '0.00', 'balance' => '2240.00',
            'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'journal_entry_id' => $this->postedJournalEntry($by)->id,
            'created_by' => $by->id,
        ]);

        // Supplier credit lines credit an expense account (use 5010 COGS-ish or any expense).
        $expenseId = (string) Account::query()->where('code', '5010')->value('id');

        $cn = $this->svc->finalize($this->svc->create([
            'type' => 'supplier', 'date' => now()->toDateString(), 'is_vatable' => true,
            'vendor_id' => $vendor->id, 'bill_id' => $bill->id,
            'lines' => [['account_id' => $expenseId, 'description' => 'Damaged goods', 'amount' => '1000.00']],
        ], $by), $by);

        $this->svc->apply($cn, ['amount' => '1120.00', 'bill_id' => $bill->id], $by);

        $bill->refresh();
        $this->assertSame('1120.00', (string) $bill->balance);
        $this->assertSame('partial', $bill->status->value);
    }

    public function test_over_applying_beyond_credit_balance_is_rejected(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CN-'.substr(uniqid(), -5), 'customer_id' => $customer->id,
            'status' => 'finalized', 'subtotal' => '5000.00', 'vat_amount' => '600.00',
            'total_amount' => '5600.00', 'amount_paid' => '0.00', 'balance' => '5600.00',
            'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'journal_entry_id' => $this->postedJournalEntry($by)->id,
            'created_by' => $by->id,
        ]);

        $cn = $this->svc->finalize($this->svc->create([
            'type' => 'customer', 'date' => now()->toDateString(), 'is_vatable' => true,
            'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'lines' => [['account_id' => $this->revenueAccountId(), 'description' => 'Credit', 'amount' => '100.00']],
        ], $by), $by);

        // CN balance is 112.00; applying 500 must be rejected.
        $this->expectException(\RuntimeException::class);
        $this->svc->apply($cn, ['amount' => '500.00', 'invoice_id' => $invoice->id], $by);
    }

    public function test_customer_credit_note_rejects_a_non_revenue_line_account(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Typed Account Co', 'payment_terms_days' => 30]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('must be of type revenue');

        $this->svc->create([
            'type' => 'customer',
            'date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'lines' => [[
                'account_id' => (string) Account::query()->where('code', '1010')->value('id'),
                'description' => 'Invalid asset line',
                'amount' => '100.00',
            ]],
        ], $by);
    }

    public function test_credit_note_routes_are_permission_gated(): void
    {
        // Unauthenticated → 401.
        $this->postJson('/api/v1/accounting/credit-notes', [])->assertStatus(401);
    }

    public function test_index_and_show_return_credit_notes(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $cn = $this->svc->finalize($this->svc->create([
            'type' => 'customer', 'date' => now()->toDateString(), 'is_vatable' => true,
            'customer_id' => $customer->id,
            'lines' => [['account_id' => $this->revenueAccountId(), 'description' => 'Credit', 'amount' => '300.00']],
        ], $by), $by);

        // index — the GET path that would fatal if list() were missing.
        $this->actingAs($by)
            ->getJson('/api/v1/accounting/credit-notes')
            ->assertStatus(200)
            ->assertJsonPath('data.0.credit_note_number', $cn->credit_note_number);

        // show — exercises show() + relation loading.
        $this->actingAs($by)
            ->getJson("/api/v1/accounting/credit-notes/{$cn->hash_id}")
            ->assertStatus(200)
            ->assertJsonPath('data.total_amount', '336.00')
            ->assertJsonPath('data.customer.name', 'Acme');
    }
}
