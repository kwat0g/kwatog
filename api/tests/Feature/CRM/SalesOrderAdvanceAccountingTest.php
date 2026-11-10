<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Resources\InvoiceResource;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderAdvance;
use App\Modules\CRM\Models\SalesOrderAdvanceApplication;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Services\SalesOrderAdvanceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesOrderAdvanceAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class, SettingsSeeder::class]);
        Storage::fake('local');
    }

    public function test_fixed_and_percentage_advance_requirements_are_snapshotted_and_capped_by_order_total(): void
    {
        $sales = User::factory()->create();
        $fixedOrder = $this->order('1000.00');
        $percentOrder = $this->order('2000.00');
        $service = app(SalesOrderAdvanceService::class);

        $fixed = $service->configure($fixedOrder, '250.00', null, $sales);
        $percentage = $service->configure($percentOrder, null, '25.00', $sales);

        $this->assertSame('250.00', (string) $fixed->required_amount);
        $this->assertSame('25.00', (string) $percentage->percentage);
        $this->assertSame('500.00', (string) $percentage->required_amount);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->configure($this->order('100.00'), '100.01', null, $sales);
    }

    public function test_percentage_requirement_tracks_an_unconfirmed_order_total_change(): void
    {
        $sales = User::factory()->create();
        $order = $this->order('1000.00');
        $service = app(SalesOrderAdvanceService::class);
        $advance = $service->configure($order, null, '25.00', $sales);
        $order->update(['total_amount' => '2000.00']);

        $updated = $service->recalculateConfiguredRequirement($order->fresh());

        $this->assertSame($advance->id, $updated->id);
        $this->assertSame('2000.00', (string) $updated->order_total_snapshot);
        $this->assertSame('500.00', (string) $updated->required_amount);
    }

    public function test_customer_submits_private_proof_and_finance_posts_advance_to_liability_once(): void
    {
        $order = $this->order('1000.00');
        $portal = CustomerPortalUser::create([
            'customer_id' => $order->customer_id, 'name' => 'Buyer', 'email' => Str::uuid().'@test.local',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $finance = User::factory()->create();
        $service = app(SalesOrderAdvanceService::class);
        $configured = $service->configure($order, null, '25.00', User::factory()->create());
        $order->update(['status' => 'confirmed']);
        $beforeEntries = JournalEntry::count();

        $pending = $service->submitEvidence($order, [
            'amount' => '250.00', 'sending_bank' => 'BDO', 'reference_number' => 'ADV-001',
            'payment_date' => now('Asia/Manila')->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ], $this->proof(), $portal);

        $this->assertSame('pending', $pending->status);
        $this->assertSame('250.00', (string) $pending->submitted_amount);
        $this->assertSame($beforeEntries, JournalEntry::count());
        Storage::disk('local')->assertExists($pending->proof_path);

        $bank = Account::query()->where('code', '1020')->firstOrFail();
        $verified = $service->verify($pending, $bank->hash_id, $finance);
        $this->assertSame('verified', $verified->status);
        $this->assertSame($verified->id, $service->verify($pending, $bank->hash_id, $finance)->id);
        $entry = JournalEntry::query()->with('lines')->findOrFail($verified->journal_entry_id);
        $this->assertSame('posted', $entry->status->value);
        $this->assertSame('250.00', (string) $entry->lines->firstWhere('account_id', $bank->id)->debit);
        $this->assertSame('250.00', (string) $entry->lines->firstWhere('account_id', Account::query()->where('code', '2090')->value('id'))->credit);
        $this->assertSame($beforeEntries + 1, JournalEntry::count());
        $this->assertNotNull($configured->fresh());
    }

    public function test_second_payment_attempt_is_blocked_while_finance_reviews_the_first(): void
    {
        $order = $this->order('1000.00');
        $portal = CustomerPortalUser::create([
            'customer_id' => $order->customer_id, 'name' => 'Buyer', 'email' => Str::uuid().'@test.local',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $service = app(SalesOrderAdvanceService::class);
        $service->configure($order, '250.00', null, User::factory()->create());
        $order->update(['status' => 'confirmed']);
        $service->submitEvidence($order, [
            'amount' => '250.00', 'sending_bank' => 'BDO', 'reference_number' => 'ADV-PENDING-001',
            'payment_date' => now('Asia/Manila')->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ], $this->proof(), $portal);

        $this->expectException(ValidationException::class);
        $service->submitEvidence($order, [
            'amount' => '250.00', 'sending_bank' => 'BDO', 'reference_number' => 'ADV-PENDING-002',
            'payment_date' => now('Asia/Manila')->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ], $this->proof(), $portal);
    }

    public function test_exact_customer_submission_replay_returns_the_existing_attempt(): void
    {
        $order = $this->order('1000.00');
        $portal = CustomerPortalUser::create([
            'customer_id' => $order->customer_id, 'name' => 'Buyer', 'email' => Str::uuid().'@test.local',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $service = app(SalesOrderAdvanceService::class);
        $service->configure($order, '250.00', null, User::factory()->create());
        $order->update(['status' => 'confirmed']);
        $payment = [
            'amount' => '250.00', 'sending_bank' => 'BDO', 'reference_number' => 'ADV-REPLAY-001',
            'payment_date' => now('Asia/Manila')->toDateString(),
            'idempotency_key' => '7d91b407-8f47-4922-974b-196a541f71c2',
        ];
        $first = $service->submitEvidence($order, $payment, $this->proof(), $portal);
        $replay = $service->submitEvidence($order, $payment, $this->proof(), $portal);

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, SalesOrderAdvance::query()->where('sales_order_id', $order->id)->count());
    }

    public function test_another_customers_portal_user_cannot_submit_advance_evidence(): void
    {
        $order = $this->order('1000.00');
        $service = app(SalesOrderAdvanceService::class);
        $service->configure($order, '250.00', null, User::factory()->create());
        $order->update(['status' => 'confirmed']);
        $foreignCustomer = Customer::factory()->create();
        $foreignPortal = CustomerPortalUser::create([
            'customer_id' => $foreignCustomer->id, 'name' => 'Other Buyer', 'email' => Str::uuid().'@test.local',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);

        try {
            $service->submitEvidence($order, [
                'amount' => '250.00', 'sending_bank' => 'BDO', 'reference_number' => 'ADV-CROSS-TENANT',
                'payment_date' => now('Asia/Manila')->toDateString(), 'idempotency_key' => (string) Str::uuid(),
            ], $this->proof(), $foreignPortal);
            $this->fail('A customer portal user cannot submit another customer’s payment proof.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }

        $this->assertSame('configured', SalesOrderAdvance::query()->where('sales_order_id', $order->id)->firstOrFail()->status);
    }

    public function test_customer_advance_proof_rejects_unsupported_mime_types(): void
    {
        $order = $this->order('1000.00');
        $portal = CustomerPortalUser::create([
            'customer_id' => $order->customer_id, 'name' => 'Buyer', 'email' => Str::uuid().'@test.local',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $service = app(SalesOrderAdvanceService::class);
        $service->configure($order, '250.00', null, User::factory()->create());
        $order->update(['status' => 'confirmed']);

        $this->expectException(ValidationException::class);
        $service->submitEvidence($order, [
            'amount' => '250.00', 'sending_bank' => 'BDO', 'reference_number' => 'ADV-BAD-MIME',
            'payment_date' => now('Asia/Manila')->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ], UploadedFile::fake()->createWithContent('notes.txt', 'not a bank receipt'), $portal);
    }

    public function test_verified_advance_applies_to_invoice_balance_only_once(): void
    {
        $order = $this->order('1000.00');
        $invoice = Invoice::factory()->create([
            'customer_id' => $order->customer_id, 'sales_order_id' => $order->id,
            'status' => 'finalized', 'date' => now('Asia/Manila')->toDateString(),
            'total_amount' => '600.00', 'amount_paid' => '0.00', 'balance' => '600.00',
        ]);
        $portal = CustomerPortalUser::create([
            'customer_id' => $order->customer_id, 'name' => 'Buyer', 'email' => Str::uuid().'@test.local',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $service = app(SalesOrderAdvanceService::class);
        $advance = $service->configure($order, '400.00', null, User::factory()->create());
        $order->update(['status' => 'confirmed']);
        $advance = $service->submitEvidence($order, [
            'amount' => '400.00', 'sending_bank' => 'BDO', 'reference_number' => 'ADV-002',
            'payment_date' => now('Asia/Manila')->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ], $this->proof(), $portal);
        $advance = $service->verify($advance, Account::query()->where('code', '1020')->firstOrFail()->hash_id, User::factory()->create());
        $beforeEntries = JournalEntry::count();

        $applications = $service->applyToInvoice($order, $invoice, User::factory()->create());
        $service->applyToInvoice($order, $invoice->fresh(), User::factory()->create());

        $this->assertCount(1, $applications);
        $this->assertSame('400.00', (string) $applications[0]->amount);
        $this->assertSame('200.00', (string) $invoice->fresh()->balance);
        $this->assertSame('400.00', (string) $invoice->fresh()->amount_paid);
        $applicationEntry = JournalEntry::query()->findOrFail($applications[0]->journal_entry_id);
        $this->assertSame('sales_order_advance_application', $applicationEntry->reference_type);
        $this->assertSame($applications[0]->id, $applicationEntry->reference_id);
        $this->assertSame($beforeEntries + 1, JournalEntry::count());
    }

    public function test_invoice_resource_shows_customer_advance_application_amounts(): void
    {
        $order = $this->order('1000.00');
        $invoice = Invoice::factory()->create([
            'customer_id' => $order->customer_id, 'sales_order_id' => $order->id,
            'status' => 'partial', 'date' => now('Asia/Manila')->toDateString(),
            'total_amount' => '600.00', 'amount_paid' => '250.00', 'balance' => '350.00',
        ]);
        $advance = app(SalesOrderAdvanceService::class)->configure($order, '250.00', null, User::factory()->create());
        $application = SalesOrderAdvanceApplication::query()->create([
            'sales_order_advance_id' => $advance->id, 'invoice_id' => $invoice->id, 'amount' => '250.00',
            'journal_entry_id' => null, 'applied_by' => User::factory()->create()->id, 'applied_at' => now(),
        ]);
        $invoice->load(['advanceApplications', 'customer', 'salesOrder', 'items', 'collections', 'paymentSubmissions']);

        $payload = (new InvoiceResource($invoice))->toArray(request());

        $this->assertSame($application->hash_id, $payload['advance_applications'][0]['id']);
        $this->assertSame('250.00', $payload['advance_applications'][0]['amount']);
    }

    public function test_unpaid_advance_gates_production_until_finance_waives_it(): void
    {
        $order = $this->order('1000.00');
        $service = app(SalesOrderAdvanceService::class);
        $advance = $service->configure($order, '250.00', null, User::factory()->create());
        $order->update(['status' => 'confirmed']);

        $this->assertFalse($service->releaseProductionIfReady($order));
        $this->assertNull($order->fresh()->mrp_released_at);

        $waived = $service->waive($advance, 'Customer is approved for deferred payment.', User::factory()->create());

        $this->assertSame('waived', $waived->status);
        $this->assertNotNull($order->fresh()->mrp_released_at);
        $this->assertFalse($service->releaseProductionIfReady($order->fresh()));
    }

    public function test_pending_advance_is_excluded_from_every_planning_relevant_order_query(): void
    {
        $order = $this->order('1000.00');
        SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'quantity' => '10', 'quantity_delivered' => '0']);
        $service = app(SalesOrderAdvanceService::class);
        $advance = $service->configure($order, '250.00', null, User::factory()->create());
        $order->update(['status' => 'confirmed']);

        $this->assertFalse(SalesOrder::query()->planningRelevant()->whereKey($order->id)->exists());

        $advance->update(['status' => 'rejected']);
        $retry = SalesOrderAdvance::query()->create([
            'sales_order_id' => $order->id, 'customer_id' => $order->customer_id, 'attempt_number' => 2,
            'order_total_snapshot' => $order->total_amount, 'required_amount' => '250.00',
            'status' => 'configured', 'configured_by' => User::factory()->create()->id,
        ]);
        $service->waive($retry, 'Approved deferred payment.', User::factory()->create());

        $this->assertTrue(SalesOrder::query()->planningRelevant()->whereKey($order->id)->exists());
    }

    public function test_verified_advance_cannot_be_cancelled_until_its_unapplied_balance_is_refunded(): void
    {
        $order = $this->order('1000.00');
        $portal = CustomerPortalUser::create([
            'customer_id' => $order->customer_id, 'name' => 'Buyer', 'email' => Str::uuid().'@test.local',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $service = app(SalesOrderAdvanceService::class);
        $advance = $service->configure($order, '250.00', null, User::factory()->create());
        $order->update(['status' => 'confirmed']);
        $advance = $service->submitEvidence($order, [
            'amount' => '250.00', 'sending_bank' => 'BDO', 'reference_number' => 'ADV-REFUND-001',
            'payment_date' => now('Asia/Manila')->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ], $this->proof(), $portal);
        $finance = User::factory()->create();
        $bank = Account::query()->where('code', '1020')->firstOrFail();
        $advance = $service->verify($advance, $bank->hash_id, $finance);

        try {
            $service->assertCancellable($order);
            $this->fail('An unapplied, verified customer advance must block cancellation.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Resolve the verified customer advance', $error->getMessage());
        }

        $refunded = $service->refundUnapplied($advance, $bank->hash_id, 'REFUND-001', 'Order cancelled.', $finance);
        $this->assertSame('refunded', $refunded->status);
        $this->assertSame('250.00', (string) $refunded->refunded_amount);
        $service->assertCancellable($order->fresh());

        $this->expectException(BusinessRuleException::class);
        $service->refundUnapplied($refunded, $bank->hash_id, 'REFUND-002', 'Duplicate refund.', $finance);
    }

    private function order(string $total): SalesOrder
    {
        return SalesOrder::factory()->create([
            'status' => 'draft', 'subtotal' => $total, 'vat_amount' => '0.00', 'total_amount' => $total,
        ]);
    }

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('advance-proof.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }
}
