<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\CreditNoteStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\CreditNoteService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
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

    public function test_applying_customer_credit_reduces_invoice_balance(): void
    {
        $by = $this->admin();
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-CN-'.substr(uniqid(), -5), 'customer_id' => $customer->id,
            'status' => 'finalized', 'subtotal' => '1000.00', 'vat_amount' => '120.00',
            'total_amount' => '1120.00', 'amount_paid' => '0.00', 'balance' => '1120.00',
            'date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
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

        $cn->refresh();
        $this->assertSame('560.00', (string) $cn->applied_amount);
        // CN total = 500 + 12% VAT = 560; fully applied → balance 0, status applied.
        $this->assertSame('0.00', (string) $cn->balance);
        $this->assertSame('applied', $cn->status->value);
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
            'created_by' => $by->id,
        ]);

        // Supplier credit lines credit an expense account (use 5010 COGS-ish or any expense).
        $expenseId = (string) Account::query()->where('type', 'expense')->value('id');

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
