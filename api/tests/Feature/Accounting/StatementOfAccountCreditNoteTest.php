<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Collection;
use App\Modules\Accounting\Models\CreditNoteApplication;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\CreditNoteService;
use App\Modules\Accounting\Services\StatementOfAccountService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AB-02 — the customer Statement of Account must show credit-note
 * applications as ledger rows so the closing balance reconciles with the
 * aging total on the same page.
 */
class StatementOfAccountCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private CreditNoteService $creditNotes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->creditNotes = app(CreditNoteService::class);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function postedJournalEntry(User $by): JournalEntry
    {
        return JournalEntry::create([
            'entry_number' => 'JE-SOA-'.uniqid(),
            'date' => now()->toDateString(),
            'description' => 'Posted target document fixture',
            'total_debit' => '1.00',
            'total_credit' => '1.00',
            'status' => JournalEntryStatus::Posted,
            'posted_at' => now(),
            'posted_by' => $by->id,
        ]);
    }

    private function invoice(Customer $customer, User $by, string $total): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV-T-'.substr(uniqid(), -5), 'customer_id' => $customer->id,
            'status' => 'finalized', 'subtotal' => $total, 'vat_amount' => '0.00',
            'total_amount' => $total, 'amount_paid' => '0.00', 'balance' => $total,
            'date' => '2026-05-01', 'due_date' => '2026-05-31',
            'journal_entry_id' => $this->postedJournalEntry($by)->id,
            'created_by' => $by->id,
        ]);
    }

    private function applyCredit(User $by, Customer $customer, Invoice $invoice, string $amount, string $appliedAt): CreditNoteApplication
    {
        $cn = $this->creditNotes->finalize($this->creditNotes->create([
            'type' => 'customer', 'date' => Carbon::parse($appliedAt)->toDateString(),
            'is_vatable' => false, 'customer_id' => $customer->id, 'invoice_id' => $invoice->id,
            'lines' => [['account_id' => (string) Account::query()->where('code', '4010')->value('id'), 'description' => 'Credit', 'amount' => $amount]],
        ], $by), $by);

        $application = $this->creditNotes->apply($cn, ['amount' => $amount, 'invoice_id' => $invoice->id], $by);
        $application->forceFill(['created_at' => Carbon::parse($appliedAt)])->save();

        return $application->fresh(['creditNote']);
    }

    private function statement(Customer $customer, string $asOf): array
    {
        return app(StatementOfAccountService::class)->forCustomer($customer, $asOf);
    }

    /** @param array{aging: array{current: string, d30_days: string, d60_days: string, d90_plus: string}} $statement */
    private function agingTotal(array $statement): string
    {
        return Money::add(
            $statement['aging']['current'],
            $statement['aging']['d30_days'],
            $statement['aging']['d60_days'],
            $statement['aging']['d90_plus'],
        );
    }

    public function test_applied_credit_note_reduces_closing_balance_to_match_aging(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $invoice = $this->invoice($customer, $by, '10000.00');
        $this->applyCredit($by, $customer, $invoice, '4000.00', '2026-06-10 10:00:00');

        $statement = $this->statement($customer, '2026-06-30');

        $this->assertSame('6000.00', (string) $statement['closing_balance']);
        $agingTotal = $this->agingTotal($statement);
        $this->assertSame('6000.00', $agingTotal);
        $this->assertSame((string) $statement['closing_balance'], $agingTotal, 'Closing balance must equal the aging total for the same as-of date');
        $this->assertSame((string) $statement['closing_balance'], (string) $statement['total_outstanding']);
    }

    public function test_credit_application_appears_as_a_negative_ledger_row(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $invoice = $this->invoice($customer, $by, '10000.00');
        $application = $this->applyCredit($by, $customer, $invoice, '4000.00', '2026-06-10 10:00:00');

        $statement = $this->statement($customer, '2026-06-30');

        $creditRows = array_values(array_filter($statement['transactions'], static fn (array $t): bool => $t['type'] === 'credit_note'));
        $this->assertCount(1, $creditRows);
        $this->assertSame('2026-06-10', $creditRows[0]['date']);
        $this->assertSame('-4000.00', $creditRows[0]['amount']);
        $this->assertSame($application->creditNote->credit_note_number, $creditRows[0]['reference']);
        $this->assertSame('6000.00', $creditRows[0]['running_balance']);
    }

    public function test_collection_and_credit_application_order_and_running_balance(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $invoice = $this->invoice($customer, $by, '10000.00');
        $this->applyCredit($by, $customer, $invoice, '4000.00', '2026-06-10 10:00:00');
        Collection::create([
            'invoice_id' => $invoice->id,
            'cash_account_id' => Account::query()->where('code', '1010')->firstOrFail()->id,
            'collection_date' => '2026-06-12',
            'amount' => '3000.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ]);

        $statement = $this->statement($customer, '2026-06-30');

        $rows = array_map(static fn (array $t): array => [$t['date'], $t['type'], $t['amount'], $t['running_balance']], $statement['transactions']);
        $this->assertSame([
            ['2026-05-01', 'invoice', '10000.00', '10000.00'],
            ['2026-06-10', 'credit_note', '-4000.00', '6000.00'],
            ['2026-06-12', 'payment', '-3000.00', '3000.00'],
        ], $rows);

        $this->assertSame('3000.00', (string) $statement['closing_balance']);
        $this->assertSame((string) $statement['closing_balance'], $this->agingTotal($statement));
    }

    public function test_credit_application_after_the_as_of_date_is_excluded_everywhere(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $invoice = $this->invoice($customer, $by, '10000.00');
        $this->applyCredit($by, $customer, $invoice, '4000.00', '2026-07-05 10:00:00');

        $statement = $this->statement($customer, '2026-06-30');

        $creditRows = array_filter($statement['transactions'], static fn (array $t): bool => $t['type'] === 'credit_note');
        $this->assertCount(0, $creditRows, 'Applications after the as-of date must not appear in the ledger');
        $this->assertSame('10000.00', (string) $statement['closing_balance']);
        $agingTotal = $this->agingTotal($statement);
        $this->assertSame('10000.00', $agingTotal);
        $this->assertSame((string) $statement['closing_balance'], $agingTotal);
    }
}
