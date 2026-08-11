<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class BillServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function newUser(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        return User::create([
            'name' => 'Finance', 'email' => 'fin_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => $roleId,
        ]);
    }

    public function test_bill_creates_balanced_je_and_recording_payment_settles_balance(): void
    {
        $user = $this->newUser();
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
        $user = $this->newUser();
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

    public function test_record_payment_rejects_a_stale_open_bill_after_it_is_paid(): void
    {
        $user      = $this->newUser();
        $vendor    = Vendor::create(['name' => 'Stale Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId    = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $svc       = app(BillService::class);
        $bill      = $svc->create([
            'bill_number' => 'B-STALE-1', 'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10', 'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '1', 'unit_price' => '100.00']],
        ], $user);

        DB::table('bills')->where('id', $bill->id)->update([
            'status'      => BillStatus::Paid->value,
            'amount_paid' => '100.00',
            'balance'     => '0.00',
        ]);

        $exception = null;
        try {
            $svc->recordPayment($bill, [
                'cash_account_id' => $cashId,
                'payment_date'    => '2026-04-11',
                'amount'          => '10.00',
                'payment_method'  => PaymentMethod::Cash->value,
            ], $user);
        } catch (RuntimeException $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception, 'A stale open bill must not accept payment after the persisted row is paid.');
        $this->assertSame('Bill is already fully paid.', $exception->getMessage());

        $row = DB::table('bills')->where('id', $bill->id)->first();
        $this->assertSame(BillStatus::Paid->value, $row->status);
        $this->assertSame('100.00', $row->amount_paid);
        $this->assertSame('0.00', $row->balance);
        $this->assertSame(0, DB::table('bill_payments')->where('bill_id', $bill->id)->count());
        $this->assertSame(0, DB::table('journal_entries')->where('reference_type', 'bill_payment')->count());
    }

    public function test_record_payment_uses_the_locked_balance_when_caller_is_stale(): void
    {
        $user      = $this->newUser();
        $vendor    = Vendor::create(['name' => 'Authoritative Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId    = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $svc       = app(BillService::class);
        $bill      = $svc->create([
            'bill_number' => 'B-LOCKED-1', 'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10', 'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '1', 'unit_price' => '100.00']],
        ], $user);

        DB::table('bills')->where('id', $bill->id)->update([
            'status'      => BillStatus::Partial->value,
            'amount_paid' => '60.00',
            'balance'     => '40.00',
        ]);

        $payment = $svc->recordPayment($bill, [
            'cash_account_id' => $cashId,
            'payment_date'    => '2026-04-11',
            'amount'          => '40.00',
            'payment_method'  => PaymentMethod::Cash->value,
        ], $user);

        $row = DB::table('bills')->where('id', $bill->id)->first();
        $this->assertSame('100.00', $row->amount_paid);
        $this->assertSame('0.00', $row->balance);
        $this->assertSame(BillStatus::Paid->value, $row->status);
        $this->assertSame('40.00', (string) $payment->amount);
        $this->assertSame(1, DB::table('bill_payments')->where('bill_id', $bill->id)->count());
        $this->assertSame(1, DB::table('journal_entries')->where('reference_type', 'bill_payment')->count());
    }

    public function test_cannot_bill_against_cancelled_po(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create(['name' => 'Cancelled PO Vendor', 'payment_terms_days' => 30]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $po = \App\Modules\Purchasing\Models\PurchaseOrder::create([
            'po_number'    => 'PO-202604-9999',
            'vendor_id'    => $vendor->id,
            'date'         => '2026-04-01',
            'subtotal'     => '1000.00',
            'vat_amount'   => '0.00',
            'total_amount' => '1000.00',
            'is_vatable'   => false,
            'created_by'   => $user->id,
        ]);
        $po->forceFill(['status' => \App\Modules\Purchasing\Enums\PurchaseOrderStatus::Cancelled->value])->save();

        $svc = app(BillService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $svc->create([
            'bill_number'       => 'INV-CANCEL-1',
            'vendor_id'         => $vendor->hash_id,
            'purchase_order_id' => $po->hash_id,
            'date'              => '2026-04-10',
            'is_vatable'        => false,
            'items'             => [
                ['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '1', 'unit_price' => '1000.00'],
            ],
        ], $user);
    }
}
