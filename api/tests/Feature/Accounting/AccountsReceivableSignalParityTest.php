<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoicePaymentSubmission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\Dashboard\Services\BadgeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountsReceivableSignalParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_ar_badges_match_filtered_invoice_lists_and_payment_review_is_narrow(): void
    {
        $finance = $this->user('finance_officer');
        $otherOwner = $this->user('vice_president');

        Invoice::factory()->create([
            'status' => 'draft', 'created_by' => $finance->id,
        ]);
        Invoice::factory()->create([
            'status' => 'draft', 'created_by' => $otherOwner->id,
        ]);
        Invoice::factory()->create([
            'status' => 'finalized', 'balance' => '100.00', 'amount_paid' => '0.00',
            'due_date' => today('Asia/Manila')->subDay(),
        ]);
        Invoice::factory()->create([
            'status' => 'partial', 'balance' => '60.00', 'amount_paid' => '40.00',
            'due_date' => today('Asia/Manila'),
        ]);
        $pendingPaymentInvoice = Invoice::factory()->create([
            'status' => 'finalized', 'balance' => '50.00', 'amount_paid' => '0.00',
            'due_date' => today('Asia/Manila')->addDay(),
        ]);
        Invoice::factory()->create([
            'status' => 'paid', 'balance' => '0.00', 'amount_paid' => '100.00',
            'due_date' => today('Asia/Manila')->subDay(),
        ]);
        Invoice::factory()->create([
            'status' => 'partial', 'balance' => '0.00', 'amount_paid' => '100.00',
            'due_date' => today('Asia/Manila')->subDay(),
        ]);
        Invoice::factory()->create([
            'status' => 'cancelled', 'balance' => '10.00', 'amount_paid' => '0.00',
            'due_date' => today('Asia/Manila')->subDay(),
        ]);
        BadgeService::touch();
        $versionBeforePayment = BadgeService::version();
        $paymentSubmission = $this->paymentSubmission($pendingPaymentInvoice, $finance);
        $this->assertGreaterThan($versionBeforePayment, BadgeService::version());

        $badgeData = $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');

        foreach ([
            'draft' => 'draft_invoices',
            'unpaid' => 'unpaid_invoices',
            'overdue' => 'overdue_invoices',
            'payment_review' => 'payment_review_invoices',
        ] as $signal => $badgeKey) {
            $list = $this->actingAs($finance, 'sanctum')
                ->getJson('/api/v1/invoices?signal='.$signal.'&per_page=100')
                ->assertOk();

            $this->assertSame($list->json('meta.total'), $badgeData[$badgeKey]['count'], $signal);
        }

        $this->assertSame(1, $badgeData['draft_invoices']['count']);
        $this->assertSame(3, $badgeData['unpaid_invoices']['count']);
        $this->assertSame(1, $badgeData['overdue_invoices']['count']);
        $this->assertSame(1, $badgeData['payment_review_invoices']['count']);
        $this->assertSame(
            [$pendingPaymentInvoice->hash_id],
            $this->actingAs($finance, 'sanctum')
                ->getJson('/api/v1/invoices?signal=payment_review')
                ->assertOk()->json('data.*.id'),
        );
        $this->actingAs($otherOwner, 'sanctum')
            ->getJson('/api/v1/invoices?signal=payment_review')
            ->assertForbidden();
        $otherOwnerBadges = $this->actingAs($otherOwner, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()->json('data');
        $this->assertArrayNotHasKey('payment_review_invoices', $otherOwnerBadges);

        $systemAdmin = $this->user('system_admin');
        $adminBadges = $this->actingAs($systemAdmin, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()->json('data');
        $this->assertSame(0, $adminBadges['payment_review_invoices']['count']);
        $this->actingAs($systemAdmin, 'sanctum')
            ->getJson('/api/v1/invoices?signal=payment_review')
            ->assertForbidden();

        $versionBeforeReview = BadgeService::version();
        $paymentSubmission->update([
            'status' => 'rejected',
            'reviewed_by' => $finance->id,
            'reviewed_at' => now(),
            'rejection_reason' => 'Reference could not be confirmed.',
        ]);
        $this->assertGreaterThan($versionBeforeReview, BadgeService::version());
        $this->assertSame(0, $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')->assertOk()
            ->json('data.payment_review_invoices.count'));
        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/v1/invoices?signal=payment_review')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
        ]);
    }

    private function paymentSubmission(Invoice $invoice, User $reviewer): InvoicePaymentSubmission
    {
        $portalUser = CustomerPortalUser::create([
            'customer_id' => $invoice->customer_id,
            'name' => 'AR signal customer',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);

        return InvoicePaymentSubmission::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'submitted_by' => $portalUser->id,
            'amount' => $invoice->balance,
            'balance_at_submission' => $invoice->balance,
            'sending_bank' => 'BDO',
            'sending_bank_normalized' => 'bdo',
            'reference_number' => 'REF-'.Str::random(12),
            'reference_normalized' => Str::upper(Str::random(12)),
            'payment_date' => today('Asia/Manila'),
            'status' => 'pending',
            'proof_path' => 'test/proof.pdf',
            'proof_file_name' => 'proof.pdf',
            'proof_mime' => 'application/pdf',
            'proof_size' => 1,
            'proof_sha256' => str_repeat('a', 64),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::random(20)),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ]);
    }
}
