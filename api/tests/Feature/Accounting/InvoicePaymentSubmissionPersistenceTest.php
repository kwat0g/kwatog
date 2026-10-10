<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoicePaymentSubmission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoicePaymentSubmissionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_stores_exact_money_and_finance_has_no_manual_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $invoice = Invoice::factory()->create();
        $portal = CustomerPortalUser::create([
            'customer_id' => $invoice->customer_id, 'name' => 'Customer',
            'email' => Str::uuid().'@test.local', 'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $row = InvoicePaymentSubmission::create([
            'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'submitted_by' => $portal->id,
            'amount' => '19600.00', 'balance_at_submission' => '19600.00',
            'sending_bank' => 'BDO', 'sending_bank_normalized' => 'bdo',
            'reference_number' => 'REF-1', 'reference_normalized' => 'ref-1',
            'payment_date' => now()->toDateString(), 'status' => 'pending',
            'proof_path' => 'private/test.pdf', 'proof_file_name' => 'test.pdf', 'proof_mime' => 'application/pdf',
            'proof_size' => 128, 'proof_sha256' => str_repeat('a', 64),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('b', 64),
        ]);
        $this->assertSame('19600.00', (string) $row->fresh()->amount);
        $this->assertSame($row->id, $invoice->paymentSubmissions()->first()->id);
        $finance = User::factory()->create(['role_id' => Role::where('slug', 'finance_officer')->value('id')]);
        $this->assertFalse($finance->hasPermission('accounting.invoices.collect_manual'));
        $this->assertTrue($finance->hasPermission('accounting.invoices.collect'));
    }
    public function test_empty_submission_migration_can_roll_back_and_reapply_without_changing_legacy_collections(): void
    {
        $this->seed([RolePermissionSeeder::class, \Database\Seeders\ChartOfAccountsSeeder::class]);
        $invoice = Invoice::factory()->create();
        $collection = \App\Modules\Accounting\Models\Collection::create([
            'invoice_id' => $invoice->id, 'cash_account_id' => \App\Modules\Accounting\Models\Account::where('code', '1020')->value('id'),
            'amount' => '123.45', 'collection_date' => now()->toDateString(), 'payment_method' => 'cash',
        ]);
        $before = \Illuminate\Support\Facades\DB::table('collections')->get()->map(fn ($row) => (array) $row)->all();
        $migration = require database_path('migrations/2026_10_09_050000_create_invoice_payment_submissions.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('invoice_payment_submissions'));
        $migration->up();
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('collections')->get()->map(fn ($row) => (array) $row)->all());
        $collection->update(['manual_reason' => 'Preserved audited exception']);
        try { $migration->down(); $this->fail('Rollback discarded audit provenance.'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('Preserve evidence', $error->getMessage()); }
        $this->assertSame('123.45', (string) $collection->fresh()->amount);
    }

}
