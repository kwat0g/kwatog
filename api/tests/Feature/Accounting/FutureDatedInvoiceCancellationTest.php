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
use Tests\TestCase;

class FutureDatedInvoiceCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function financeUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
            'is_active' => true,
        ]);
    }

    public function test_cancelling_a_future_dated_prebill_reverses_on_or_after_its_source_date(): void
    {
        $maker = $this->financeUser();
        $approver = $this->financeUser();
        $poster = $this->financeUser();
        $customer = Customer::create(['name' => 'Future cancellation '.uniqid()]);
        $revenue = Account::query()->where('code', '4010')->firstOrFail();
        $sourceDate = now()->addDay()->toDateString();

        $created = $this->actingAs($maker)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->hash_id,
            'lifecycle_type' => 'prebill',
            'prebill_reason' => 'Future-dated invoice cancellation test.',
            'date' => $sourceDate,
            'due_date' => now()->addDays(31)->toDateString(),
            'is_vatable' => false,
            'items' => [[
                'revenue_account_id' => $revenue->hash_id,
                'description' => 'Prepaid equipment',
                'quantity' => '1.00',
                'unit_price' => '100.00',
            ]],
        ])->assertCreated();

        $invoice = Invoice::query()->where('created_by', $maker->id)->firstOrFail();
        $approveUrl = '/api/v1/invoices/'.$invoice->hash_id.'/approve-prebill';
        $finalizeUrl = '/api/v1/invoices/'.$invoice->hash_id.'/finalize';
        $cancelUrl = '/api/v1/invoices/'.$invoice->hash_id.'/cancel';
        $this->assertSame($invoice->hash_id, $created->json('data.id'));
        $this->assertSame('draft', $invoice->status->value);
        $this->assertNull($invoice->prebill_approved_by);
        $this->assertNull($invoice->journal_entry_id);

        $this->actingAs($approver)->postJson($approveUrl)->assertOk();
        $approved = $invoice->fresh();
        $this->assertSame('draft', $approved->status->value);
        $this->assertSame($approver->id, $approved->prebill_approved_by);
        $this->assertNull($approved->journal_entry_id);
        $this->assertSame(0, JournalEntry::query()->count());

        $this->actingAs($poster)->patchJson($finalizeUrl)->assertOk();
        $finalized = $invoice->fresh();
        $this->assertSame('finalized', $finalized->status->value);
        $this->assertSame($approver->id, $finalized->prebill_approved_by);
        $this->assertSame($sourceDate, $finalized->journalEntry->date->toDateString());
        $this->assertSame('posted', $finalized->journalEntry->status->value);
        $this->assertSame('100.00', (string) $finalized->journalEntry->total_debit);
        $this->assertSame('100.00', (string) $finalized->journalEntry->total_credit);
        $this->assertSame(1, JournalEntry::query()->count());

        $this->actingAs($poster)->patchJson($cancelUrl)->assertOk();

        $cancelled = $invoice->fresh();
        $source = $finalized->journalEntry->fresh();
        $reversal = JournalEntry::query()
            ->where('reference_type', 'journal_entry_reversal')
            ->where('reference_id', $source->id)
            ->firstOrFail();

        $this->assertSame('cancelled', $cancelled->status->value);
        $this->assertSame('0.00', (string) $cancelled->balance);
        $this->assertSame('reversed', $source->status->value);
        $this->assertSame($reversal->id, $source->reversed_by_entry_id);
        $this->assertSame($sourceDate, $reversal->date->toDateString());
        $this->assertSame('posted', $reversal->status->value);
        $this->assertSame('100.00', (string) $reversal->total_debit);
        $this->assertSame('100.00', (string) $reversal->total_credit);
        $this->assertSame(2, JournalEntry::query()->count());
    }
}
