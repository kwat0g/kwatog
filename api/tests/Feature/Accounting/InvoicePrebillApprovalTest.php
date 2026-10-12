<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvoicePrebillApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        Mail::fake();
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'is_active' => true,
        ]);
    }

    private function payload(Customer $customer, Account $revenue, string $quantity = '1.00'): array
    {
        return [
            'customer_id' => $customer->hash_id,
            'lifecycle_type' => 'prebill',
            'prebill_reason' => 'Approved prebilling for confirmed customer order.',
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [[
                'revenue_account_id' => $revenue->hash_id,
                'description' => 'Prepaid equipment',
                'quantity' => $quantity,
                'unit_price' => '100.00',
            ]],
        ];
    }

    private function createPrebill(User $maker): Invoice
    {
        $customer = Customer::create([
            'name' => 'Prebill Customer '.uniqid(),
            'email' => 'prebill-'.uniqid().'@example.test',
            'payment_terms_days' => 30,
        ]);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();

        $response = $this->actingAs($maker)->postJson(
            '/api/v1/invoices',
            $this->payload($customer, $revenue),
        );

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');

        return Invoice::query()->where('created_by', $maker->id)->firstOrFail();
    }

    private function updatePayload(Invoice $invoice, Customer $customer, Account $revenue, string $quantity = '1.00'): array
    {
        $revision = $this->getJson('/api/v1/invoices/'.$invoice->hash_id)
            ->assertOk()
            ->json('data.revision');

        return [
            ...$this->payload($customer, $revenue, $quantity),
            'expected_revision' => $revision,
        ];
    }

    public function test_prebill_creation_does_not_record_the_maker_as_approver(): void
    {
        $maker = $this->user('finance_officer');
        $approver = $this->user('finance_officer');
        $invoice = $this->createPrebill($maker);

        $this->assertSame($maker->id, $invoice->created_by);
        $this->assertNull($invoice->prebill_approved_by);
        $this->assertNull($invoice->prebill_approved_at);
        $this->assertNull($invoice->journal_entry_id);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $approver->id,
            'type' => 'approval.action_required',
        ]);
    }

    public function test_display_history_is_not_persisted_as_an_invoice_attribute(): void
    {
        $maker = $this->user('finance_officer');
        $customer = Customer::create([
            'name' => 'History Customer '.uniqid(),
            'email' => 'history-'.uniqid().'@example.test',
            'payment_terms_days' => 30,
        ]);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();

        $invoice = app(\App\Modules\Accounting\Services\InvoiceService::class)
            ->create($this->payload($customer, $revenue), $maker);

        $this->assertArrayNotHasKey('status_history', $invoice->getAttributes());
        $invoice->refresh();

        $invoice->forceFill(['remarks' => 'Safe later update'])->save();
        $this->assertSame('Safe later update', $invoice->fresh()->remarks);
    }

    public function test_standard_invoice_without_confirmed_delivery_is_not_created(): void
    {
        $maker = $this->user('finance_officer');
        $customer = Customer::create([
            'name' => 'Undelivered standard invoice '.uniqid(),
            'payment_terms_days' => 30,
        ]);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();
        $payload = $this->payload($customer, $revenue);
        $payload['lifecycle_type'] = 'standard';
        unset($payload['prebill_reason']);
        $before = [
            'invoices' => Invoice::query()->count(),
            'items' => DB::table('invoice_items')->count(),
            'journal_entries' => JournalEntry::query()->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
        ];

        $response = $this->actingAs($maker)->postJson('/api/v1/invoices', $payload);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'A standard sales-order invoice requires a confirmed delivered quantity. Use the approved prebill lifecycle for prebilling.');
        $this->assertSame($before['invoices'], Invoice::query()->count());
        $this->assertSame($before['items'], DB::table('invoice_items')->count());
        $this->assertSame($before['journal_entries'], JournalEntry::query()->count());
        $this->assertSame($before['audit_logs'], DB::table('audit_logs')->count());
    }

    public function test_invoice_creation_ignores_client_controlled_status_approval_and_totals(): void
    {
        $maker = $this->user('finance_officer');
        $other = $this->user('finance_officer');
        $customer = Customer::create([
            'name' => 'Mass assignment customer '.uniqid(),
            'email' => 'mass-'.uniqid().'@example.test',
            'payment_terms_days' => 30,
        ]);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();

        $response = $this->actingAs($maker)->postJson('/api/v1/invoices', [
            ...$this->payload($customer, $revenue),
            'invoice_number' => 'CLIENT-APPROVED-1',
            'status' => 'finalized',
            'created_by' => $other->id,
            'prebill_approved_by' => $other->id,
            'prebill_approved_at' => now()->toIso8601String(),
            'journal_entry_id' => 999999,
            'total_amount' => '1.00',
            'amount_paid' => '100.00',
            'balance' => '-99.00',
        ])->assertCreated();

        $invoice = Invoice::query()->findOrFail(
            \App\Common\Support\HashIdFilter::decode($response->json('data.id'), Invoice::class),
        );
        $this->assertSame($maker->id, $invoice->created_by);
        $this->assertSame('draft', $invoice->status->value);
        $this->assertNull($invoice->invoice_number);
        $this->assertNull($invoice->prebill_approved_by);
        $this->assertNull($invoice->prebill_approved_at);
        $this->assertNull($invoice->journal_entry_id);
        $this->assertSame('100.00', (string) $invoice->total_amount);
        $this->assertSame('0.00', (string) $invoice->amount_paid);
        $this->assertSame('100.00', (string) $invoice->balance);
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_role_without_prebill_approval_permission_cannot_approve(): void
    {
        $maker = $this->user('finance_officer');
        $unauthorized = $this->user('purchasing_officer');
        $invoice = $this->createPrebill($maker);

        $this->actingAs($unauthorized)
            ->postJson('/api/v1/invoices/'.$invoice->hash_id.'/approve-prebill')
            ->assertForbidden();

        $this->assertNull($invoice->fresh()->prebill_approved_by);
        $this->assertNull($invoice->fresh()->prebill_approved_at);
        $this->assertSame('draft', $invoice->fresh()->status->value);
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_prebill_cannot_be_finalized_before_a_separate_approval(): void
    {
        $maker = $this->user('finance_officer');
        $poster = $this->user('finance_officer');
        $invoice = $this->createPrebill($maker);

        $response = $this->actingAs($poster)->patchJson(
            '/api/v1/invoices/'.$invoice->hash_id.'/finalize',
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('approved by a different user', (string) $response->json('message'));
        $this->assertSame('draft', $invoice->fresh()->status->value);
        $this->assertNull($invoice->fresh()->journal_entry_id);
    }

    public function test_prebill_approval_and_posting_require_three_distinct_actors(): void
    {
        $maker = $this->user('finance_officer');
        $approver = $this->user('finance_officer');
        $poster = $this->user('finance_officer');
        $invoice = $this->createPrebill($maker);
        $approveUrl = '/api/v1/invoices/'.$invoice->hash_id.'/approve-prebill';
        $finalizeUrl = '/api/v1/invoices/'.$invoice->hash_id.'/finalize';

        $draft = $this->actingAs($maker)->getJson('/api/v1/invoices/'.$invoice->hash_id)->assertOk();
        $draft->assertJsonPath('data.status_history.0.event_type', 'created')
            ->assertJsonPath('data.status_history.0.from_status', null)
            ->assertJsonPath('data.status_history.0.to_status', 'draft')
            ->assertJsonPath('data.status_history.0.actor_name', $maker->name);

        $selfApproval = $this->actingAs($maker)->postJson($approveUrl);
        $selfApproval->assertForbidden();
        $this->assertNull($invoice->fresh()->prebill_approved_by);
        $this->assertSame(0, JournalEntry::query()->count());

        $selfFinalize = $this->actingAs($maker)->patchJson($finalizeUrl);
        $selfFinalize->assertForbidden();
        $this->assertSame('draft', $invoice->fresh()->status->value);
        $this->assertNull($invoice->fresh()->journal_entry_id);

        $approveResponse = $this->actingAs($approver)->postJson($approveUrl);
        $approveResponse->assertOk();
        $approveResponse->assertJsonPath('data.status_history.1.event_type', 'prebill_approved')
            ->assertJsonPath('data.status_history.1.from_status', 'draft')
            ->assertJsonPath('data.status_history.1.to_status', 'draft')
            ->assertJsonPath('data.status_history.1.actor_name', $approver->name);
        $approved = $invoice->fresh();
        $this->assertSame($approver->id, $approved->prebill_approved_by);
        $this->assertNotNull($approved->prebill_approved_at);
        $this->assertSame('draft', $approved->status->value);
        $this->assertNull($approved->journal_entry_id);
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $maker->id,
            'type' => 'approval.completed',
        ]);

        $approvalAuditCount = DB::table('audit_logs')
            ->where('model_type', $invoice->getMorphClass())
            ->where('model_id', $invoice->id)
            ->where('user_id', $approver->id)
            ->where('action', 'updated')
            ->count();
        $this->assertSame(1, $approvalAuditCount);

        $duplicateApproval = $this->actingAs($approver)->postJson($approveUrl);
        $duplicateApproval->assertOk();
        $duplicateApproval->assertJsonCount(2, 'data.status_history');
        $this->assertSame($approved->prebill_approved_at->toIso8601String(), $invoice->fresh()->prebill_approved_at->toIso8601String());
        $this->assertSame($approvalAuditCount, DB::table('audit_logs')
            ->where('model_type', $invoice->getMorphClass())
            ->where('model_id', $invoice->id)
            ->where('user_id', $approver->id)
            ->where('action', 'updated')
            ->count());

        $secondApprover = $this->user('finance_officer');
        $this->actingAs($secondApprover)->postJson($approveUrl)->assertStatus(422);
        $this->assertSame($approver->id, $invoice->fresh()->prebill_approved_by);
        $this->assertSame(0, DB::table('audit_logs')
            ->where('model_type', $invoice->getMorphClass())
            ->where('model_id', $invoice->id)
            ->where('user_id', $secondApprover->id)
            ->where('action', 'updated')
            ->count());

        $approverCannotPost = $this->actingAs($approver)->patchJson($finalizeUrl);
        $approverCannotPost->assertForbidden();
        $this->assertSame('draft', $invoice->fresh()->status->value);
        $this->assertNull($invoice->fresh()->journal_entry_id);
        $this->assertSame(0, JournalEntry::query()->count());

        $postedResponse = $this->actingAs($poster)->patchJson($finalizeUrl);
        $postedResponse->assertOk();
        $postedResponse->assertJsonPath('data.status_history.2.event_type', 'status_changed')
            ->assertJsonPath('data.status_history.2.from_status', 'draft')
            ->assertJsonPath('data.status_history.2.to_status', 'finalized')
            ->assertJsonPath('data.status_history.2.actor_name', $poster->name);
        $finalized = $invoice->fresh();
        $this->assertSame('finalized', $finalized->status->value);
        $this->assertSame($approver->id, $finalized->prebill_approved_by);
        $this->assertNotNull($finalized->invoice_number);
        $this->assertSame(100.0, (float) $finalized->balance);

        $journal = JournalEntry::query()->findOrFail($finalized->journal_entry_id);
        $this->assertSame('posted', $journal->status->value);
        $this->assertSame($poster->id, $journal->posted_by);
        $this->assertSame('100.00', (string) $journal->total_debit);
        $this->assertSame('100.00', (string) $journal->total_credit);
        $this->assertSame(1, JournalEntry::query()->count());

        $this->actingAs($poster)->patchJson($finalizeUrl)->assertStatus(422);
        $this->assertSame('finalized', $invoice->fresh()->status->value);
        $this->assertSame($journal->id, $invoice->fresh()->journal_entry_id);
        $this->assertSame(1, JournalEntry::query()->count());

        $lateApproval = $this->actingAs($secondApprover)->postJson($approveUrl);
        $lateApproval->assertStatus(422);
        $this->assertSame('finalized', $invoice->fresh()->status->value);
        $this->assertSame($approver->id, $invoice->fresh()->prebill_approved_by);
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_cancelled_prebill_cannot_be_approved_finalized_or_edited(): void
    {
        $maker = $this->user('finance_officer');
        $approver = $this->user('finance_officer');
        $canceller = $this->user('finance_officer');
        $invoice = $this->createPrebill($maker);
        $approveUrl = '/api/v1/invoices/'.$invoice->hash_id.'/approve-prebill';
        $finalizeUrl = '/api/v1/invoices/'.$invoice->hash_id.'/finalize';
        $cancelUrl = '/api/v1/invoices/'.$invoice->hash_id.'/cancel';

        $this->actingAs($approver)->postJson($approveUrl)->assertOk();
        $this->actingAs($canceller)->patchJson($cancelUrl)->assertOk();

        $cancelled = $invoice->fresh();
        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertSame($canceller->id, $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(0, JournalEntry::query()->count());

        $this->actingAs($approver)->postJson($approveUrl)->assertStatus(422);
        $this->actingAs($approver)->patchJson($finalizeUrl)->assertStatus(422);
        $customer = Customer::query()->findOrFail($invoice->customer_id);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();
        $this->actingAs($maker)->putJson(
            '/api/v1/invoices/'.$invoice->hash_id,
            $this->payload($customer, $revenue, '2.00'),
        )->assertStatus(422);

        $this->actingAs($canceller)->patchJson($cancelUrl)->assertOk();
        $this->assertSame('cancelled', $invoice->fresh()->status->value);
        $this->assertSame($canceller->id, $invoice->fresh()->cancelled_by);
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_editing_an_approved_prebill_clears_approval_before_posting(): void
    {
        $maker = $this->user('finance_officer');
        $approver = $this->user('finance_officer');
        $poster = $this->user('finance_officer');
        $invoice = $this->createPrebill($maker);
        $approveUrl = '/api/v1/invoices/'.$invoice->hash_id.'/approve-prebill';
        $this->actingAs($approver)->postJson($approveUrl)->assertOk();
        $this->assertSame($approver->id, $invoice->fresh()->prebill_approved_by);

        $customer = Customer::query()->findOrFail($invoice->customer_id);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();
        $edited = $this->actingAs($maker)->putJson(
            '/api/v1/invoices/'.$invoice->hash_id,
            $this->updatePayload($invoice, $customer, $revenue, '2.00'),
        );
        $edited->assertOk();

        $fresh = $invoice->fresh();
        $this->assertSame('200.00', (string) $fresh->total_amount);
        $this->assertNull($fresh->prebill_approved_by);
        $this->assertNull($fresh->prebill_approved_at);
        $this->assertNull($fresh->journal_entry_id);
        $this->actingAs($poster)->patchJson('/api/v1/invoices/'.$invoice->hash_id.'/finalize')
            ->assertStatus(422);
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_prebill_editor_cannot_approve_a_revision_they_changed_since_last_approval(): void
    {
        $maker = $this->user('finance_officer');
        $approver = $this->user('finance_officer');
        $editor = $this->user('finance_officer');
        $laterEditor = $this->user('finance_officer');
        $invoice = $this->createPrebill($maker);
        $url = '/api/v1/invoices/'.$invoice->hash_id;

        $this->actingAs($approver)->postJson($url.'/approve-prebill')->assertOk();
        $this->assertSame($approver->id, $invoice->fresh()->prebill_approved_by);

        $customer = Customer::query()->findOrFail($invoice->customer_id);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();
        $this->actingAs($editor)->putJson($url, $this->updatePayload($invoice, $customer, $revenue, '2.00'))->assertOk();
        $this->actingAs($laterEditor)->putJson($url, $this->updatePayload($invoice, $customer, $revenue, '3.00'))->assertOk();
        $this->assertNull($invoice->fresh()->prebill_approved_by);

        $beforeApproval = $invoice->fresh()->only([
            'status', 'prebill_reason', 'prebill_approved_by', 'prebill_approved_at',
            'subtotal', 'total_amount', 'journal_entry_id',
        ]);
        $auditCount = DB::table('audit_logs')
            ->where('model_type', Invoice::class)
            ->where('model_id', $invoice->id)
            ->count();

        $this->actingAs($editor)->postJson($url.'/approve-prebill')->assertForbidden();
        $this->actingAs($laterEditor)->postJson($url.'/approve-prebill')->assertForbidden();

        $this->assertSame($beforeApproval, $invoice->fresh()->only(array_keys($beforeApproval)));
        $this->assertSame($auditCount, DB::table('audit_logs')
            ->where('model_type', Invoice::class)
            ->where('model_id', $invoice->id)
            ->count());
    }

    public function test_prebill_edit_persists_the_changed_approval_reason(): void
    {
        $maker = $this->user('finance_officer');
        $invoice = $this->createPrebill($maker);
        $customer = Customer::query()->findOrFail($invoice->customer_id);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();
        $payload = $this->updatePayload($invoice, $customer, $revenue);
        $payload['prebill_reason'] = 'Corrected prepayment terms confirmed by the customer.';

        $this->actingAs($maker)->putJson('/api/v1/invoices/'.$invoice->hash_id, $payload)->assertOk();

        $this->assertSame($payload['prebill_reason'], $invoice->fresh()->prebill_reason);
    }

    public function test_standard_invoice_cannot_be_changed_through_the_prebill_edit_endpoint(): void
    {
        $maker = $this->user('finance_officer');
        $invoice = $this->createPrebill($maker);
        $invoice->forceFill(['lifecycle_type' => 'standard'])->save();
        $customer = Customer::query()->findOrFail($invoice->customer_id);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();
        $payload = $this->updatePayload($invoice, $customer, $revenue, '2.00');
        $payload['lifecycle_type'] = 'standard';
        $before = $invoice->fresh()->only([
            'lifecycle_type', 'status', 'prebill_reason', 'subtotal', 'vat_amount',
            'total_amount', 'balance', 'prebill_approved_by', 'prebill_approved_at',
        ]);

        $response = $this->actingAs($maker)->putJson('/api/v1/invoices/'.$invoice->hash_id, $payload);

        $response->assertUnprocessable();
        $this->assertSame($before, $invoice->fresh()->only(array_keys($before)));
        $this->assertSame('100.00', (string) $invoice->items()->firstOrFail()->total);
    }

    public function test_prebill_edit_rejects_a_different_customer_instead_of_ignoring_it(): void
    {
        $maker = $this->user('finance_officer');
        $invoice = $this->createPrebill($maker);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();
        $otherCustomer = Customer::create([
            'name' => 'Different draft customer '.uniqid(),
            'payment_terms_days' => 30,
        ]);
        $payload = $this->updatePayload($invoice, $otherCustomer, $revenue);
        $before = $invoice->fresh()->only(['customer_id', 'status', 'subtotal', 'total_amount', 'balance']);

        $response = $this->actingAs($maker)->putJson('/api/v1/invoices/'.$invoice->hash_id, $payload);

        $response->assertUnprocessable();
        $this->assertSame($before, $invoice->fresh()->only(array_keys($before)));
        $this->assertSame('100.00', (string) $invoice->items()->firstOrFail()->total);
    }

    public function test_prebill_edit_rejects_a_stale_page_without_overwriting_the_newer_revision(): void
    {
        $maker = $this->user('finance_officer');
        $invoice = $this->createPrebill($maker);
        $customer = Customer::query()->findOrFail($invoice->customer_id);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();
        $stalePayload = $this->updatePayload($invoice, $customer, $revenue, '2.00');
        $url = '/api/v1/invoices/'.$invoice->hash_id;

        $this->actingAs($maker)->putJson($url, $stalePayload)->assertOk();
        $afterFirstEdit = $invoice->fresh();
        $this->assertSame('200.00', (string) $afterFirstEdit->total_amount);
        $auditCount = DB::table('audit_logs')
            ->where('model_type', Invoice::class)
            ->where('model_id', $invoice->id)
            ->count();
        $stalePayload['items'][0]['quantity'] = '3.00';

        $response = $this->actingAs($maker)->putJson($url, $stalePayload);

        $response->assertUnprocessable();
        $this->assertStringContainsString('changed since you opened it', $response->json('message'));
        $this->assertSame('200.00', (string) $invoice->fresh()->total_amount);
        $this->assertSame('200.00', (string) $invoice->items()->firstOrFail()->total);
        $this->assertSame($auditCount, DB::table('audit_logs')
            ->where('model_type', Invoice::class)
            ->where('model_id', $invoice->id)
            ->count());
    }
}
