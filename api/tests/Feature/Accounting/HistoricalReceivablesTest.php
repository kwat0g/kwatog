<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Collection;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Accounting\Services\StatementOfAccountService;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalReceivablesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    public function test_aging_and_statement_reconstruct_balances_at_the_requested_cutoff(): void
    {
        $customer = Customer::factory()->create(['name' => 'Historical Toyota']);
        $cashAccount = Account::query()->where('code', '1010')->firstOrFail();

        $paidAfterCutoff = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-HIST-001',
            'date' => '2026-05-01',
            'due_date' => '2026-05-15',
            'status' => InvoiceStatus::Paid,
            'total_amount' => '1000.00',
            'amount_paid' => '1000.00',
            'balance' => '0.00',
        ]);
        Collection::create([
            'invoice_id' => $paidAfterCutoff->id,
            'cash_account_id' => $cashAccount->id,
            'collection_date' => '2026-06-15',
            'amount' => '1000.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ]);

        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-HIST-002',
            'date' => '2026-07-02',
            'due_date' => '2026-07-30',
            'status' => InvoiceStatus::Finalized,
            'total_amount' => '200.00',
            'amount_paid' => '0.00',
            'balance' => '200.00',
        ]);

        Invoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-HIST-003',
            'date' => '2026-05-05',
            'due_date' => '2026-05-20',
            'status' => InvoiceStatus::Cancelled,
            'total_amount' => '500.00',
            'amount_paid' => '0.00',
            'balance' => '0.00',
            'cancelled_at' => Carbon::parse('2026-06-10 10:00:00'),
        ]);

        $asOf = Carbon::parse('2026-06-01');
        $aging = app(InvoiceService::class)->aging($asOf);

        $this->assertSame('1500.00', $aging['buckets']['total']);
        $this->assertSame('1500.00', $aging['buckets']['d1_30']);

        $statement = app(StatementOfAccountService::class)->forCustomer($customer, '2026-06-01');
        $this->assertSame('1500.00', (string) $statement['closing_balance']);
        $this->assertSame('1500.00', (string) $statement['total_outstanding']);

        $current = app(InvoiceService::class)->aging(Carbon::parse('2026-06-30'));
        $this->assertSame('0.00', $current['buckets']['total']);
    }
}
