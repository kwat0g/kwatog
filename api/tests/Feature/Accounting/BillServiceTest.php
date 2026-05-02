<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ChartOfAccountsSeeder::class);
    }

    public function test_bill_creates_balanced_je_and_recording_payment_settles_balance(): void
    {
        $user = User::create([
            'name' => 'Finance', 'email' => 'fin_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
        ]);
        $vendor = Vendor::create(['name' => 'Acme Resin Co.', 'payment_terms_days' => 30]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id; // Direct Materials
        $cashId    = Account::query()->where('code', '1020')->firstOrFail()->hash_id; // Cash in Bank

        $svc = app(BillService::class);

        $bill = $svc->create([
            'bill_number' => 'INV-2026-001',
            'vendor_id'   => $vendor->hash_id,
            'date'        => '2026-04-10',
            'is_vatable'  => true,
            'items'       => [
                ['expense_account_id' => $expenseId, 'description' => 'Resin Type A', 'quantity' => '10', 'unit_price' => '500.00'],
            ],
        ], $user);

        // Subtotal 5000, VAT 12% = 600, total 5600
        $this->assertSame('5000.00', (string) $bill->subtotal);
        $this->assertSame('600.00',  (string) $bill->vat_amount);
        $this->assertSame('5600.00', (string) $bill->total_amount);
        $this->assertSame('5600.00', (string) $bill->balance);
        $this->assertSame(BillStatus::Unpaid, $bill->status);
        $this->assertNotNull($bill->journal_entry_id);

        // JE must be balanced and posted.
        $je = $bill->journalEntry;
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit);
        $this->assertSame('posted', $je->status->value);

        // Pay half.
        $payment = $svc->recordPayment($bill->fresh(), [
            'cash_account_id'  => $cashId,
            'payment_date'     => '2026-04-12',
            'amount'           => '2800.00',
            'payment_method'   => PaymentMethod::BankTransfer->value,
            'reference_number' => 'BANK-001',
        ], $user);

        $bill->refresh();
        $this->assertSame('2800.00', (string) $bill->amount_paid);
        $this->assertSame('2800.00', (string) $bill->balance);
        $this->assertSame(BillStatus::Partial, $bill->status);
        $this->assertNotNull($payment->journal_entry_id);

        // Pay the rest.
        $svc->recordPayment($bill->fresh(), [
            'cash_account_id'  => $cashId,
            'payment_date'     => '2026-04-15',
            'amount'           => '2800.00',
            'payment_method'   => PaymentMethod::BankTransfer->value,
        ], $user);

        $bill->refresh();
        $this->assertSame('5600.00', (string) $bill->amount_paid);
        $this->assertSame('0.00',    (string) $bill->balance);
        $this->assertSame(BillStatus::Paid, $bill->status);
    }

    public function test_overpayment_is_rejected(): void
    {
        $user = User::create([
            'name' => 'F', 'email' => 'f_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
        ]);
        $vendor = Vendor::create(['name' => 'X']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId    = Account::query()->where('code', '1020')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            'bill_number' => 'B-1', 'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10', 'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'x', 'quantity' => '1', 'unit_price' => '100.00']],
        ], $user);

        $this->expectException(\RuntimeException::class);
        $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date'    => '2026-04-11',
            'amount'          => '101.00',
            'payment_method'  => PaymentMethod::Cash->value,
        ], $user);
    }
}
