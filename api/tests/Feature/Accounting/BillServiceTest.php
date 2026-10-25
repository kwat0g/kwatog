<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Models\ApprovalRecord;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Enums\BillPaymentStatus;
use App\Modules\Accounting\Enums\PaymentMethod;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BillPayment;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Resources\BillPaymentResource;
use App\Modules\Accounting\Resources\BillResource;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BillServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed([ChartOfAccountsSeeder::class, WorkflowSeeder::class]);
    }

    private function newUser(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        return User::create([
            'name' => 'Finance', 'email' => 'fin_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => $roleId,
        ]);
    }

    private function serviceException(): array
    {
        return [
            'provenance_type' => 'service',
            'exception_evidence' => 'Approved service completion report for this test bill.',
            'exception_approved' => true,
        ];
    }

    private function approvePayment(BillService $service, \App\Modules\Accounting\Models\Bill $bill, \App\Modules\Accounting\Models\BillPayment $payment): \App\Modules\Accounting\Models\BillPayment
    {
        if ($payment->status === BillPaymentStatus::Posted) {
            return $payment;
        }

        $vp = User::factory()->create(['role_id' => Role::where('slug', 'vice_president')->value('id')]);

        return $service->approvePayment($bill->fresh(), $payment->fresh(), $vp);
    }

    public function test_bill_creates_balanced_je_and_recording_payment_settles_balance(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create(['name' => 'Acme Resin Co.', 'payment_terms_days' => 30]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id; // Direct Materials
        $cashId    = Account::query()->where('code', '1020')->firstOrFail()->hash_id; // Cash in Bank

        $svc = app(BillService::class);

        $bill = $svc->create([
            ...$this->serviceException(),
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

        try {
            $svc->recordPayment($bill->fresh(), [
                'cash_account_id' => $cashId,
                'payment_date' => '2026-04-12',
                'amount' => '1000.00',
                'payment_method' => PaymentMethod::BankTransfer->value,
            ], $user);
            $this->fail('A partial payment below half the bill total must be rejected.');
        } catch (\App\Common\Exceptions\BusinessRuleException $exception) {
            $this->assertStringContainsString('at least 50%', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('bill_payments')->where('bill_id', $bill->id)->count());

        // More than half may be paid first; the smaller final payment clears the bill.
        $payment = $svc->recordPayment($bill->fresh(), [
            'cash_account_id'  => $cashId,
            'payment_date'     => '2026-04-12',
            'amount'           => '3360.00',
            'payment_method'   => PaymentMethod::BankTransfer->value,
            'reference_number' => 'BANK-001',
            'idempotency_key'  => 'bill-payment-first',
        ], $user);

        $this->assertSame(BillPaymentStatus::Posted, $payment->status);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'type' => 'bill_payment.posted',
        ]);
        $this->assertSame('3360.00', (string) $bill->fresh()->amount_paid);
        $payment = $this->approvePayment($svc, $bill, $payment);
        $bill->refresh();
        $this->assertSame('3360.00', (string) $bill->amount_paid);
        $this->assertSame('2240.00', (string) $bill->balance);
        $this->assertSame(BillStatus::Partial, $bill->status);
        $this->assertNotNull($payment->journal_entry_id);

        // Pay the rest.
        $svc->recordPayment($bill->fresh(), [
            'cash_account_id'  => $cashId,
            'payment_date'     => '2026-04-15',
            'amount'           => '2240.00',
            'payment_method'   => PaymentMethod::BankTransfer->value,
            'idempotency_key'  => 'bill-payment-second',
        ], $user);

        $secondPayment = \App\Modules\Accounting\Models\BillPayment::query()->latest('id')->firstOrFail();
        $this->approvePayment($svc, $bill, $secondPayment);

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
            ...$this->serviceException(),
            'bill_number' => 'B-1', 'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10', 'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'x', 'quantity' => '1', 'unit_price' => '100.00']],
        ], $user);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the unreserved balance');
        $svc->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date'    => '2026-04-11',
            'amount'          => '101.00',
            'payment_method'  => PaymentMethod::Cash->value,
        ], $user);
    }

    public function test_bill_total_at_50000_requires_only_vp_even_for_smaller_installments(): void
    {
        Mail::fake();
        Queue::fake();
        $finance = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $vp = User::factory()->create(['role_id' => Role::where('slug', 'vice_president')->value('id')]);
        $vendor = Vendor::create(['name' => 'VP Threshold Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $service = app(BillService::class);
        $bill = $service->create([
            ...$this->serviceException(),
            'bill_number' => 'B-VP-THRESHOLD', 'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10', 'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '1', 'unit_price' => '50000.00']],
        ], $finance);

        $first = $service->recordPayment($bill, [
            'cash_account_id' => $cashId, 'payment_date' => '2026-04-12',
            'amount' => '30000.00', 'payment_method' => PaymentMethod::BankTransfer->value,
        ], $finance);
        $this->assertSame(BillPaymentStatus::PendingApproval, $first->status);
        $this->assertSame(['vice_president'], $first->approvalRecords->pluck('role_slug')->all());
        DB::table('user_permission_overrides')->insert([
            'user_id' => $finance->id,
            'permission_id' => DB::table('permissions')->where('slug', 'accounting.bills.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $vp->id,
            'reason' => 'Outcome notice link access test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $finance->flushPermissionsCache();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $vp->id,
            'type' => 'approval.action_required',
        ]);
        $this->assertSame('0.00', (string) $bill->fresh()->amount_paid);

        ApprovalRecord::create([
            'approvable_type' => $first->getMorphClass(),
            'approvable_id' => $first->id,
            'step_order' => 1,
            'role_slug' => 'finance_officer',
            'action' => 'superseded',
            'attempt' => 0,
            'is_current' => false,
        ]);

        $detail = (new BillResource($service->show($bill)))->resolve(Request::create('/'));
        $this->assertSame('unpaid', $detail['status']);
        $this->assertSame('Pending VP approval', $detail['status_label']);
        $this->assertTrue($detail['payment_approval_pending']);
        $paymentDetail = (new BillPaymentResource($first->fresh()->load('approvalRecords.approver')))->resolve(Request::create('/'));
        $this->assertSame(['vice_president'], array_column($paymentDetail['approval_records']->all(), 'role_slug'));
        $list = (new BillResource($service->list([])->first()))->resolve(Request::create('/'));
        $this->assertSame('Pending VP approval', $list['status_label']);

        try {
            $service->recordPayment($bill->fresh(), [
                'cash_account_id' => $cashId, 'payment_date' => '2026-04-12',
                'amount' => '20000.00', 'payment_method' => PaymentMethod::BankTransfer->value,
            ], $finance);
            $this->fail('A pending payment must not make a smaller installment count as final.');
        } catch (\App\Common\Exceptions\BusinessRuleException $exception) {
            $this->assertStringContainsString('at least 50%', $exception->getMessage());
        }

        $service->approvePayment($bill->fresh(), $first, $vp);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $finance->id,
            'type' => 'approval.completed',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $finance->id,
            'type' => 'bill_payment.posted',
        ]);
        $this->assertSame('20000.00', (string) $bill->fresh()->balance);
        $last = $service->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId, 'payment_date' => '2026-04-15',
            'amount' => '20000.00', 'payment_method' => PaymentMethod::BankTransfer->value,
        ], $finance);
        $this->assertSame(BillPaymentStatus::PendingApproval, $last->status);
        $service->approvePayment($bill->fresh(), $last, $vp);
        $this->assertSame(BillStatus::Paid, $bill->fresh()->status);
    }

    public function test_rejected_bill_payment_notifies_requester_without_posting(): void
    {
        Mail::fake();
        Queue::fake();
        $requester = $this->newUser();
        $vp = User::factory()->create(['role_id' => Role::where('slug', 'vice_president')->value('id')]);
        $vendor = Vendor::create(['name' => 'Rejected Payment Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $service = app(BillService::class);
        $bill = $service->create([
            ...$this->serviceException(),
            'bill_number' => 'B-REJECT-'.substr(uniqid(), -6),
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '1', 'unit_price' => '50000.00']],
        ], $requester);
        $payment = $service->recordPayment($bill, [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-12',
            'amount' => '30000.00',
            'payment_method' => PaymentMethod::BankTransfer->value,
        ], $requester);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $vp->id,
            'type' => 'approval.action_required',
        ]);

        $service->rejectPayment($bill->fresh(), $payment, $vp, 'Incorrect supporting documents.');

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $requester->id,
            'type' => 'approval.rejected',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $requester->id,
            'type' => 'bill_payment.posted',
        ]);
        $this->assertSame(BillPaymentStatus::Rejected, $payment->fresh()->status);
    }

    public function test_payment_date_before_bill_date_is_rejected(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create(['name' => 'Date Guard Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $service = app(BillService::class);
        $bill = $service->create([
            ...$this->serviceException(),
            'bill_number' => 'B-DATE-GUARD', 'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10', 'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '1', 'unit_price' => '100.00']],
        ], $user);

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('Payment date cannot be before the bill date.');
        $service->recordPayment($bill, [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-09',
            'amount' => '50.00',
            'payment_method' => PaymentMethod::Cash->value,
            'idempotency_key' => 'bill-payment-before-bill',
        ], $user);
    }

    public function test_payment_proof_is_kept_with_the_payment_and_downloadable(): void
    {
        Storage::fake('local');
        $user = $this->newUser();
        $vendor = Vendor::create(['name' => 'Proof Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $service = app(BillService::class);
        $bill = $service->create([
            ...$this->serviceException(),
            'bill_number' => 'B-PROOF', 'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10', 'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '1', 'unit_price' => '100.00']],
        ], $user);
        $this->actingAs($user->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', 'bill-payment-no-reference')
            ->postJson("/api/v1/bills/{$bill->hash_id}/payments", [
                'cash_account_id' => $cashId,
                'payment_date' => '2026-04-10',
                'amount' => '50.00',
                'payment_method' => PaymentMethod::BankTransfer->value,
            ])->assertUnprocessable()->assertJsonValidationErrors('reference_number');
        $this->actingAs($user->fresh(), 'sanctum')
            ->withHeader('Idempotency-Key', 'bill-payment-proof')
            ->post("/api/v1/bills/{$bill->hash_id}/payments", [
                'cash_account_id' => $cashId,
                'payment_date' => '2026-04-10',
                'amount' => '50.00',
                'payment_method' => PaymentMethod::BankTransfer->value,
                'reference_number' => 'PROOF-001',
                'proof_file' => UploadedFile::fake()->createWithContent('bank-receipt.pdf', '%PDF-1.4 payment receipt'),
            ])->assertCreated();
        $payment = BillPayment::query()->where('bill_id', $bill->id)->firstOrFail();

        $this->assertSame('bank-receipt.pdf', $payment->proof_file_name);
        Storage::disk('local')->assertExists($payment->proof_path);
        $response = $this->getJson("/api/v1/bills/{$bill->hash_id}/payments/{$payment->hash_id}/proof");
        $response->assertOk();
    }

    public function test_service_bill_exception_requires_an_authorized_approver(): void
    {
        $role = Role::create(['name' => 'AP Encoder', 'slug' => 'ap_encoder_'.uniqid()]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $vendor = Vendor::create(['name' => 'Service Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('not authorized');
        app(BillService::class)->create([
            ...$this->serviceException(),
            'bill_number' => 'SVC-UNAUTHORIZED-1',
            'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10',
            'is_vatable' => false,
            'items' => [[
                'expense_account_id' => $expenseId,
                'description' => 'Consulting service',
                'quantity' => '1',
                'unit_price' => '100.00',
            ]],
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
            ...$this->serviceException(),
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
            ...$this->serviceException(),
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
            'idempotency_key' => 'bill-payment-locked',
        ], $user);

        $this->assertSame(BillPaymentStatus::Posted, $payment->status);
        $this->approvePayment($svc, $bill, $payment);
        $row = DB::table('bills')->where('id', $bill->id)->first();
        $this->assertSame('100.00', $row->amount_paid);
        $this->assertSame('0.00', $row->balance);
        $this->assertSame(BillStatus::Paid->value, $row->status);
        $this->assertSame('40.00', (string) $payment->amount);
        $this->assertSame(1, DB::table('bill_payments')->where('bill_id', $bill->id)->count());
        $this->assertSame(1, DB::table('journal_entries')->where('reference_type', 'bill_payment')->count());
    }

    public function test_bill_payment_replay_returns_the_original_payment_and_journal(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create(['name' => 'Replay Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $service = app(BillService::class);
        $bill = $service->create([
            ...$this->serviceException(),
            'bill_number' => 'B-REPLAY-1', 'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10', 'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '1', 'unit_price' => '100.00']],
        ], $user);
        $data = [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-11',
            'amount' => '50.00',
            'payment_method' => PaymentMethod::Cash->value,
            'idempotency_key' => 'bill-payment-test-1',
        ];

        $first = $service->recordPayment($bill, $data, $user);
        $replay = $service->recordPayment($bill->fresh(), $data, $user);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, DB::table('bill_payments')->where('bill_id', $bill->id)->count());
        $this->assertSame(1, DB::table('journal_entries')->where('reference_type', 'bill_payment')->count());
        $this->approvePayment($service, $bill, $first);
        $this->assertSame(1, DB::table('journal_entries')->where('reference_type', 'bill_payment')->count());
        $this->assertSame('50.00', (string) $bill->refresh()->amount_paid);
    }

    public function test_bill_payment_replay_with_a_changed_payload_is_rejected(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create(['name' => 'Replay Payload Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $service = app(BillService::class);
        $bill = $service->create([
            ...$this->serviceException(),
            'bill_number' => 'B-REPLAY-2', 'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10', 'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '1', 'unit_price' => '100.00']],
        ], $user);

        $service->recordPayment($bill, [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-11',
            'amount' => '50.00',
            'payment_method' => PaymentMethod::Cash->value,
            'idempotency_key' => 'bill-payment-test-2',
        ], $user);

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('different payment payload');

        $service->recordPayment($bill->fresh(), [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-11',
            'amount' => '25.00',
            'payment_method' => PaymentMethod::Cash->value,
            'idempotency_key' => 'bill-payment-test-2',
        ], $user);
    }

    public function test_bill_payment_replay_with_a_changed_reference_is_rejected_without_writes(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create(['name' => 'Replay Reference Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $cashId = Account::query()->where('code', '1020')->firstOrFail()->hash_id;
        $service = app(BillService::class);
        $bill = $service->create([
            ...$this->serviceException(),
            'bill_number' => 'B-REPLAY-REFERENCE', 'vendor_id' => $vendor->hash_id,
            'date' => '2026-04-10', 'is_vatable' => false,
            'items' => [['expense_account_id' => $expenseId, 'description' => 'Resin', 'quantity' => '1', 'unit_price' => '100.00']],
        ], $user);
        $payload = [
            'cash_account_id' => $cashId,
            'payment_date' => '2026-04-11',
            'amount' => '50.00',
            'payment_method' => PaymentMethod::Cash->value,
            'reference_number' => 'BANK-ORIGINAL',
            'idempotency_key' => 'bill-payment-reference-test',
        ];

        $payment = $service->recordPayment($bill, $payload, $user);

        try {
            $service->recordPayment($bill->fresh(), [...$payload, 'reference_number' => 'BANK-CORRECTED'], $user);
            $this->fail('A changed payment reference must not be accepted as an idempotent replay.');
        } catch (\App\Common\Exceptions\BusinessRuleException $exception) {
            $this->assertSame('The idempotency key was already used with a different payment payload.', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('bill_payments')->where('bill_id', $bill->id)->count());
        $this->assertSame('BANK-ORIGINAL', $payment->fresh()->reference_number);
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
            ...$this->serviceException(),
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
