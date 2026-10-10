<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Collection;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\OfficialReceipt;
use App\Modules\Accounting\Services\InvoicePaymentSubmissionService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoicePaymentSubmissionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Invoice $invoice;
    protected CustomerPortalUser $portal;
    protected User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class, SettingsSeeder::class]);
        Storage::fake('local');
        $this->invoice = Invoice::factory()->create([
            'status' => 'finalized', 'date' => now('Asia/Manila')->toDateString(),
            'subtotal' => '17500.00', 'vat_amount' => '2100.00', 'total_amount' => '19600.00',
            'amount_paid' => '0.00', 'balance' => '19600.00',
        ]);
        $this->portal = CustomerPortalUser::create([
            'customer_id' => $this->invoice->customer_id, 'name' => 'Toyota customer',
            'email' => Str::uuid().'@test.local', 'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $this->finance = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
    }

    protected function payload(): array
    {
        return ['amount' => '19600.00', 'sending_bank' => 'BDO', 'reference_number' => 'REF-123',
            'payment_date' => now('Asia/Manila')->toDateString(), 'idempotency_key' => (string) Str::uuid()];
    }

    protected function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }

    public function test_submission_does_not_post_and_verification_posts_once(): void
    {
        $service = app(InvoicePaymentSubmissionService::class);
        $before = [Collection::count(), JournalEntry::count(), OfficialReceipt::count()];
        $row = $service->submit($this->invoice, $this->payload(), $this->proof(), $this->portal);
        $this->assertSame('pending', $row->status->value);
        $this->assertSame('19600.00', (string) $this->invoice->fresh()->balance);
        $this->assertSame($before, [Collection::count(), JournalEntry::count(), OfficialReceipt::count()]);
        Storage::disk('local')->assertExists($row->proof_path);
        $bank = Account::where('code', '1020')->firstOrFail()->hash_id;
        $verified = $service->verify($this->invoice, $row, $bank, $this->finance);
        $this->assertSame('verified', $verified->status->value);
        $this->assertSame('paid', $this->invoice->fresh()->status->value);
        $this->assertSame('0.00', (string) $this->invoice->fresh()->balance);
        $this->assertSame([$before[0] + 1, $before[1] + 1, $before[2] + 1], [Collection::count(), JournalEntry::count(), OfficialReceipt::count()]);
        $this->assertSame($verified->id, $service->verify($this->invoice, $row, $bank, $this->finance)->id);
        $this->assertSame($before[0] + 1, Collection::count());
    }
    private function rejected(callable $action): void
    {
        try { $action(); $this->fail('Unsafe payment action succeeded.'); }
        catch (\Illuminate\Validation\ValidationException|BusinessRuleException|\App\Common\Exceptions\ForbiddenActionException|\App\Modules\Accounting\Exceptions\ClosedPeriodException $error) {
            $this->assertNotEmpty($error->getMessage());
        }
    }

    public function test_identical_submission_replay_preserves_one_proof_and_changed_payload_is_rejected(): void
    {
        $service = app(InvoicePaymentSubmissionService::class);
        $payload = $this->payload();
        $row = $service->submit($this->invoice, $payload, $this->proof(), $this->portal);
        $this->assertSame($row->id, $service->submit($this->invoice, $payload, $this->proof(), $this->portal)->id);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->rejected(fn () => $service->submit($this->invoice, [...$payload, 'reference_number' => 'changed'], $this->proof(), $this->portal));
        $this->assertDatabaseCount('invoice_payment_submissions', 1);
    }

    public function test_money_shape_current_balance_date_and_proof_are_checked_before_storage(): void
    {
        $service = app(InvoicePaymentSubmissionService::class);
        foreach (['19600.001', '1e4', '-19600', '19601.00', '0.00', '99999999999999.99'] as $amount) {
            $this->rejected(fn () => $service->submit($this->invoice, [...$this->payload(), 'amount' => $amount], $this->proof(), $this->portal));
        }
        foreach ([now('Asia/Manila')->subDay()->toDateString(), now('Asia/Manila')->addDay()->toDateString()] as $date) {
            $this->rejected(fn () => $service->submit($this->invoice, [...$this->payload(), 'payment_date' => $date], $this->proof(), $this->portal));
        }
        foreach (['sending_bank', 'reference_number'] as $field) {
            $this->rejected(fn () => $service->submit($this->invoice, [...$this->payload(), $field => '  '], $this->proof(), $this->portal));
        }
        $pdf = $this->proof();
        $this->rejected(fn () => $service->submit($this->invoice, $this->payload(), new UploadedFile($pdf->getPathname(), 'proof.png', null, null, true), $this->portal));
        $this->rejected(fn () => $service->submit($this->invoice, $this->payload(), UploadedFile::fake()->create('proof.pdf', 10241, 'application/pdf'), $this->portal));
        $this->assertDatabaseCount('invoice_payment_submissions', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_closed_invoices_never_accept_payment(): void
    {
        foreach (['draft', 'paid', 'cancelled'] as $status) {
            $this->invoice->update(['status' => $status]);
            $this->rejected(fn () => app(InvoicePaymentSubmissionService::class)->submit($this->invoice, $this->payload(), $this->proof(), $this->portal));
        }
        $this->assertDatabaseCount('invoice_payment_submissions', 0);
    }

    public function test_pending_payment_blocks_cancellation_manual_collection_and_another_submission(): void
    {
        $service = app(InvoicePaymentSubmissionService::class);
        $service->submit($this->invoice, $this->payload(), $this->proof(), $this->portal);
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
        $invoiceService = app(\App\Modules\Accounting\Services\InvoiceService::class);
        $this->rejected(fn () => $invoiceService->cancel($this->invoice, $admin));
        $this->rejected(fn () => $invoiceService->recordCollection($this->invoice, [
            'amount' => '10.00', 'cash_account_id' => Account::where('code', '1020')->firstOrFail()->hash_id,
            'collection_date' => now()->toDateString(), 'payment_method' => 'cash', 'manual_reason' => 'Verified cash exception',
        ], $admin));
        $this->rejected(fn () => $service->submit($this->invoice, $this->payload(), $this->proof(), $this->portal));
        $this->assertSame('finalized', $this->invoice->fresh()->status->value);
        $this->assertSame(0, Collection::count());
    }

    public function test_rejection_preserves_history_releases_reference_and_conflicting_decisions_fail(): void
    {
        $service = app(InvoicePaymentSubmissionService::class);
        $row = $service->submit($this->invoice, $this->payload(), $this->proof(), $this->portal);
        $this->rejected(fn () => $service->reject($this->invoice, $row, '  ', $this->finance));
        $service->reject($this->invoice, $row, 'Bank record does not match.', $this->finance);
        $this->assertSame($row->id, $service->reject($this->invoice, $row, 'Bank record does not match.', $this->finance)->id);
        $this->rejected(fn () => $service->verify($this->invoice, $row, Account::where('code', '1020')->firstOrFail()->hash_id, $this->finance));
        $second = $service->submit($this->invoice, $this->payload(), $this->proof(), $this->portal);
        $this->assertNotSame($row->id, $second->id);
        $this->assertDatabaseCount('invoice_payment_submissions', 2);
        $this->assertSame(0, Collection::count());
    }

    public function test_normalized_reference_cannot_be_reused_on_another_invoice(): void
    {
        $service = app(InvoicePaymentSubmissionService::class);
        $service->submit($this->invoice, $this->payload(), $this->proof(), $this->portal);
        $other = Invoice::factory()->create(['customer_id' => $this->portal->customer_id, 'status' => 'finalized', 'date' => now()->toDateString(), 'balance' => '19600.00']);
        $this->rejected(fn () => $service->submit($other, [...$this->payload(), 'sending_bank' => ' bdo ', 'reference_number' => ' ref-123 '], $this->proof(), $this->portal));
        $this->assertDatabaseCount('invoice_payment_submissions', 1);
    }

    public function test_stale_balance_missing_or_changed_proof_and_nonbank_account_never_post(): void
    {
        $service = app(InvoicePaymentSubmissionService::class);
        $row = $service->submit($this->invoice, $this->payload(), $this->proof(), $this->portal);
        $bank = Account::where('code', '1020')->firstOrFail()->hash_id;
        $this->invoice->update(['balance' => '19500.00']);
        $this->rejected(fn () => $service->verify($this->invoice, $row, $bank, $this->finance));
        $this->invoice->update(['balance' => '19600.00']);
        $this->rejected(fn () => $service->verify($this->invoice, $row, Account::where('code', '1010')->firstOrFail()->hash_id, $this->finance));
        Storage::disk('local')->put($row->proof_path, 'changed evidence');
        $this->rejected(fn () => $service->verify($this->invoice, $row, $bank, $this->finance));
        Storage::disk('local')->delete($row->proof_path);
        $this->rejected(fn () => $service->verify($this->invoice, $row, $bank, $this->finance));
        $this->assertSame('pending', $row->fresh()->status->value);
        $this->assertSame(0, Collection::count());
        $this->assertSame(0, OfficialReceipt::count());
    }

    public function test_receipt_failure_rolls_back_collection_journal_and_verification(): void
    {
        $service = app(InvoicePaymentSubmissionService::class);
        $row = $service->submit($this->invoice, $this->payload(), $this->proof(), $this->portal);
        $this->mock(\App\Modules\Accounting\Services\OfficialReceiptService::class, function ($mock): void {
            $mock->shouldReceive('issueForCollection')->once()->andThrow(new \RuntimeException('Receipt failed'));
        });
        try { $service->verify($this->invoice, $row, Account::where('code', '1020')->firstOrFail()->hash_id, $this->finance); $this->fail('Verification should fail.'); }
        catch (\RuntimeException $error) { $this->assertSame('Receipt failed', $error->getMessage()); }
        $this->assertSame('pending', $row->fresh()->status->value);
        $this->assertSame('19600.00', (string) $this->invoice->fresh()->balance);
        $this->assertSame([0, 0, 0], [Collection::count(), JournalEntry::count(), OfficialReceipt::count()]);
        Storage::disk('local')->assertExists($row->proof_path);
    }

    public function test_manual_service_rejects_overprecision_without_rounding(): void
    {
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
        $this->rejected(fn () => app(\App\Modules\Accounting\Services\InvoiceService::class)->recordCollection($this->invoice, [
            'amount' => '1.999', 'cash_account_id' => Account::where('code', '1020')->firstOrFail()->hash_id,
            'collection_date' => now()->toDateString(), 'payment_method' => 'cash', 'manual_reason' => 'Verified cash exception',
        ], $admin));
        $this->assertSame(0, Collection::count());
    }

    public function test_closed_period_rolls_back_and_verified_replay_cannot_change_bank(): void
    {
        $service = app(InvoicePaymentSubmissionService::class);
        $row = $service->submit($this->invoice, $this->payload(), $this->proof(), $this->portal);
        $bank = Account::where('code', '1020')->firstOrFail();
        $period = \App\Modules\Accounting\Models\AccountingPeriod::create(['year' => now()->year, 'month' => now()->month, 'status' => 'closed']);
        $this->rejected(fn () => $service->verify($this->invoice, $row, $bank->hash_id, $this->finance));
        $this->assertSame('pending', $row->fresh()->status->value);
        $this->assertSame([0, 0, 0], [Collection::count(), JournalEntry::count(), OfficialReceipt::count()]);
        $period->update(['status' => 'open']);
        $service->verify($this->invoice, $row, $bank->hash_id, $this->finance);
        $other = Account::create(['code' => '102001', 'name' => 'Second bank', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $bank->id, 'is_active' => true]);
        $this->rejected(fn () => $service->verify($this->invoice, $row, $other->hash_id, $this->finance));
        $this->assertSame([1, 1, 1], [Collection::count(), JournalEntry::count(), OfficialReceipt::count()]);
    }

    public function test_applied_credit_changes_balance_and_requires_reconciliation_of_pending_payment(): void
    {
        $journal = app(\App\Modules\Accounting\Services\JournalEntryService::class);
        $posted = $journal->postSystem($journal->create([
            'date' => now()->toDateString(), 'description' => 'Posted invoice fixture',
            'lines' => [
                ['account_id' => Account::where('code', '1030')->value('id'), 'debit' => '19600.00', 'credit' => '0.00'],
                ['account_id' => Account::where('code', '4010')->value('id'), 'debit' => '0.00', 'credit' => '19600.00'],
            ],
        ], $this->finance), $this->finance->id);
        $this->invoice->update(['journal_entry_id' => $posted->id, 'is_vatable' => false]);
        $service = app(InvoicePaymentSubmissionService::class);
        $row = $service->submit($this->invoice, $this->payload(), $this->proof(), $this->portal);
        $credits = app(\App\Modules\Accounting\Services\CreditNoteService::class);
        $credit = $credits->finalize($credits->create([
            'type' => 'customer', 'date' => now()->toDateString(), 'is_vatable' => false,
            'customer_id' => $this->invoice->customer_id, 'invoice_id' => $this->invoice->id,
            'lines' => [['account_id' => Account::where('code', '4010')->value('id'), 'description' => 'Reviewed credit', 'amount' => '100.00']],
        ], $this->finance), $this->finance);
        $credits->apply($credit, ['amount' => '100.00', 'invoice_id' => $this->invoice->id], $this->finance);
        $this->assertSame('19500.00', (string) $this->invoice->fresh()->balance);
        $this->rejected(fn () => $service->verify($this->invoice, $row, Account::where('code', '1020')->firstOrFail()->hash_id, $this->finance));
        $this->assertSame('pending', $row->fresh()->status->value);
        $this->assertSame(0, Collection::count());
    }

}
