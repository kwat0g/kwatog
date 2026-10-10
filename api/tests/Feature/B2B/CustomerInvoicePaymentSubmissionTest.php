<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Collection;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\B2B\Models\CustomerPortalUser;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerInvoicePaymentSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_submit_required_proof_without_recording_collection(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Storage::fake('local');
        $invoice = Invoice::factory()->create(['status' => 'finalized', 'date' => now()->toDateString(), 'balance' => '19600.00']);
        $portal = CustomerPortalUser::create(['customer_id' => $invoice->customer_id, 'name' => 'Customer',
            'email' => Str::uuid().'@test.local', 'password' => bcrypt('Password1!'), 'is_active' => true]);
        $this->actingAs($portal, 'customer_portal')->getJson('/api/v1/b2b/customer/invoices/'.$invoice->hash_id)
            ->assertOk()->assertJsonPath('data.can_submit_payment', true);
        $response = $this->actingAs($portal, 'customer_portal')->post('/api/v1/b2b/customer/invoices/'.$invoice->hash_id.'/payment-submissions', [
            'amount' => '19600.00', 'sending_bank' => 'BDO', 'reference_number' => 'REF-1', 'payment_date' => now()->toDateString(),
            'idempotency_key' => (string) Str::uuid(), 'proof_file' => UploadedFile::fake()->image('proof.png'),
        ], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/v1/b2b/customer/invoices/'.$invoice->hash_id)
            ->assertOk()->assertJsonPath('data.can_submit_payment', false)
            ->assertJsonPath('data.payment_submissions.0.status', 'pending');
        $this->assertSame(0, Collection::count());
        $this->assertSame('19600.00', (string) $invoice->fresh()->balance);
        $response->assertJsonMissingPath('data.proof_path');
    }
    public function test_portal_and_finance_routes_reject_crafted_fields_and_foreign_evidence(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, \Database\Seeders\ChartOfAccountsSeeder::class]);
        Storage::fake('local');
        $invoice = Invoice::factory()->create(['status' => 'finalized', 'date' => now()->toDateString(), 'balance' => '19600.00', 'total_amount' => '19600.00']);
        $portal = CustomerPortalUser::create(['customer_id' => $invoice->customer_id, 'name' => 'Customer', 'email' => Str::uuid().'@test.local', 'password' => bcrypt('Password1!'), 'is_active' => true]);
        $url = '/api/v1/b2b/customer/invoices/'.$invoice->hash_id;
        $payload = ['amount' => '19600.00', 'sending_bank' => 'BDO', 'reference_number' => 'REF-1', 'payment_date' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid(), 'proof_file' => UploadedFile::fake()->image('proof.png')];
        $this->actingAs($portal, 'customer_portal')->post($url.'/payment-submissions', [...$payload, 'status' => 'verified'], ['Accept' => 'application/json'])->assertUnprocessable();
        $response = $this->post($url.'/payment-submissions', $payload, ['Accept' => 'application/json'])->assertCreated();
        $id = $response->json('data.id');
        $this->get($url.'/payment-submissions/'.$id.'/proof')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $foreign = Invoice::factory()->create(['status' => 'finalized']);
        $this->getJson('/api/v1/b2b/customer/invoices/'.$foreign->hash_id.'/payment-submissions/'.$id.'/proof')->assertNotFound();
        $finance = \App\Modules\Auth\Models\User::factory()->create(['role_id' => \App\Modules\Auth\Models\Role::where('slug', 'finance_officer')->value('id')]);
        $review = '/api/v1/invoices/'.$invoice->hash_id.'/payment-submissions/'.$id;
        $bank = \App\Modules\Accounting\Models\Account::where('code', '1020')->firstOrFail()->hash_id;
        auth()->shouldUse('web');
        $this->actingAs($finance, 'web')->postJson('/api/v1/invoices/'.$invoice->hash_id.'/collections', ['amount' => '19600.00'])->assertForbidden();
        $this->postJson($review.'/verify', ['cash_account_id' => $bank, 'funds_received_confirmed' => false])->assertUnprocessable();
        $this->postJson($review.'/verify', ['cash_account_id' => $bank, 'funds_received_confirmed' => true, 'amount' => '1.00'])->assertUnprocessable();
        $this->postJson($review.'/reject', ['reason' => '  '])->assertUnprocessable();
        $this->postJson($review.'/verify', ['cash_account_id' => $bank, 'funds_received_confirmed' => true])->assertOk()->assertJsonPath('data.status', 'verified');
        $this->postJson($review.'/verify', ['cash_account_id' => $bank, 'funds_received_confirmed' => true])->assertOk();
        $this->assertSame(1, Collection::count());
        $this->assertSame('0.00', (string) $invoice->fresh()->balance);
    }

}
