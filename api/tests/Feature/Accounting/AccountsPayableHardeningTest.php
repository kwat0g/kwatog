<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\BillPaymentStatus;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\BillPayment;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Resources\SupplierBillResource;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Accounting\Services\VendorService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AccountsPayableHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function user(string $role = 'system_admin'): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'is_active' => true,
        ]);
    }

    private function serviceBill(User $user, string $number = 'AP-HARDEN-1'): Bill
    {
        $vendor = Vendor::create(['name' => 'AP Hardening Vendor']);
        $expense = Account::query()->where('code', '5010')->firstOrFail();

        return app(BillService::class)->create([
            'bill_number' => $number,
            'vendor_id' => $vendor->hash_id,
            'provenance_type' => 'service',
            'exception_evidence' => 'Approved service completion evidence.',
            'exception_approved' => true,
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [[
                'expense_account_id' => $expense->hash_id,
                'description' => 'AP hardening fixture',
                'quantity' => '1',
                'unit_price' => '100.00',
            ]],
        ], $user);
    }

    public function test_draft_bill_cannot_receive_a_payment(): void
    {
        $user = $this->user();
        $vendor = Vendor::create(['name' => 'Draft Vendor']);
        $expense = Account::query()->where('code', '5010')->firstOrFail();
        $draft = app(BillService::class)->createDraft([
            'bill_number' => 'AP-DRAFT-1',
            'vendor_id' => $vendor->hash_id,
            'provenance_type' => 'service',
            'exception_evidence' => 'Draft fixture evidence.',
            'exception_approved' => true,
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [[
                'expense_account_id' => $expense->hash_id,
                'description' => 'Draft payable',
                'quantity' => '1',
                'unit_price' => '100.00',
            ]],
        ], $user);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('unpaid or partially paid');
        app(BillService::class)->recordPayment($draft, [
            'cash_account_id' => Account::query()->where('code', '1020')->firstOrFail()->hash_id,
            'payment_date' => now()->toDateString(),
            'amount' => '10.00',
            'payment_method' => PaymentMethod::Cash->value,
        ], $user);
    }

    public function test_bill_lines_require_an_active_expense_account(): void
    {
        $user = $this->user();
        $vendor = Vendor::create(['name' => 'Account Vendor']);
        $asset = Account::query()->where('code', '1100')->firstOrFail();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('active expense account');
        app(BillService::class)->create([
            'bill_number' => 'AP-WRONG-ACCOUNT',
            'vendor_id' => $vendor->hash_id,
            'provenance_type' => 'service',
            'exception_evidence' => 'Wrong account fixture.',
            'exception_approved' => true,
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [[
                'expense_account_id' => $asset->hash_id,
                'description' => 'Wrong account',
                'quantity' => '1',
                'unit_price' => '100.00',
            ]],
        ], $user);
    }

    public function test_payment_requires_a_cash_asset_account_and_void_rebuilds_balance(): void
    {
        $maker = $this->user();
        $checker = $this->user('finance_officer');
        $bill = $this->serviceBill($maker, 'AP-VOID-1');
        $service = app(BillService::class);
        $payment = $service->recordPayment($bill, [
            'cash_account_id' => Account::query()->where('code', '1020')->firstOrFail()->hash_id,
            'payment_date' => now()->toDateString(),
            'amount' => '40.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ], $maker);

        $this->assertSame(BillPaymentStatus::Posted, $payment->status);

        try {
            $service->recordPayment($bill->fresh(), [
                'cash_account_id' => Account::query()->where('code', '2010')->firstOrFail()->hash_id,
                'payment_date' => now()->toDateString(),
                'amount' => '1.00',
                'payment_method' => PaymentMethod::Cash->value,
            ], $maker);
            $this->fail('A liability account must not be accepted as a cash account.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('cash or bank asset', $e->getMessage());
        }

        $voided = $service->voidPayment($bill, $payment, [
            'reason' => 'Bank transfer was rejected and will be reissued.',
        ], $checker);

        $this->assertSame(BillPaymentStatus::Voided, $voided->status);
        $this->assertNotNull($voided->void_reversal_journal_entry_id);
        $this->assertSame('0.00', (string) $bill->fresh()->amount_paid);
        $this->assertSame(BillStatus::Unpaid, $bill->fresh()->status);
    }

    public function test_aging_is_reconstructed_at_the_requested_date(): void
    {
        $vendor = Vendor::create(['name' => 'Historical Vendor']);
        $bill = Bill::create([
            'bill_number' => 'AP-HISTORY-1',
            'vendor_id' => $vendor->id,
            'date' => '2026-01-01',
            'due_date' => '2026-01-15',
            'is_vatable' => false,
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
            'amount_paid' => '100.00',
            'balance' => '0.00',
            'status' => BillStatus::Paid,
        ]);
        BillPayment::create([
            'bill_id' => $bill->id,
            'cash_account_id' => Account::query()->where('code', '1020')->firstOrFail()->id,
            'payment_date' => '2026-02-01',
            'amount' => '100.00',
            'payment_method' => PaymentMethod::Cash,
            'status' => BillPaymentStatus::Posted,
        ]);

        $service = app(BillService::class);
        $beforePayment = $service->aging(Carbon::createFromFormat('!Y-m-d', '2026-01-31'));
        $afterPayment = $service->aging(Carbon::createFromFormat('!Y-m-d', '2026-02-28'));

        $this->assertSame('100.00', $beforePayment['buckets']['total']);
        $this->assertSame('0.00', $afterPayment['buckets']['total']);
    }

    public function test_supplier_resource_does_not_expose_internal_ap_controls(): void
    {
        $bill = Bill::create([
            'bill_number' => 'AP-RESOURCE-1',
            'vendor_id' => Vendor::create(['name' => 'Resource Vendor'])->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => false,
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
            'amount_paid' => '0.00',
            'balance' => '100.00',
            'status' => BillStatus::Unpaid,
            'provenance_type' => 'service',
            'exception_evidence' => 'Internal evidence',
            'exception_owner_id' => ($user = $this->user())->id,
            'exception_approved_by' => $user->id,
            'exception_approved_at' => now(),
            'three_way_override_reason' => 'Internal reason',
        ]);
        BillItem::create([
            'bill_id' => $bill->id,
            'expense_account_id' => Account::query()->where('code', '5010')->value('id'),
            'description' => 'Supplier resource fixture',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'total' => '100.00',
        ]);

        $data = (new SupplierBillResource($bill))->toArray(Request::create('/supplier/invoices'));

        $this->assertArrayNotHasKey('exception_evidence', $data);
        $this->assertArrayNotHasKey('three_way_override_reason', $data);
        $this->assertArrayNotHasKey('payments', $data);

        $bill->load('items.expenseAccount');
        $data = (new SupplierBillResource($bill))->resolve(Request::create('/supplier/invoices'));
        $this->assertArrayNotHasKey('expense_account', $data['items'][0]);
    }

    public function test_vendor_list_aggregates_open_balance_and_restore_reaches_trashed_vendor(): void
    {
        $vendor = Vendor::create(['name' => 'Restore Vendor']);
        Bill::create([
            'bill_number' => 'AP-VENDOR-LIST-1',
            'vendor_id' => $vendor->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => false,
            'subtotal' => '125.00',
            'vat_amount' => '0.00',
            'total_amount' => '125.00',
            'amount_paid' => '0.00',
            'balance' => '125.00',
            'status' => BillStatus::Unpaid,
        ]);

        $row = app(VendorService::class)->list(['per_page' => 25])->first();
        $this->assertSame('125.00', (string) $row->open_balance);

        $vendor->delete();
        $restored = app(VendorService::class)->restore($vendor);
        $this->assertNull($restored->deleted_at);
    }
}
