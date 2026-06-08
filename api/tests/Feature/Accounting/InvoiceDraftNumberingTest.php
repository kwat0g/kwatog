<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C-3 — Invoice draft numbering.
 *
 * Invariants under test:
 *   - Drafts created by InvoiceService::create have NULL invoice_number.
 *   - InvoiceService::finalize stamps the real INV-YYYYMM-NNNN number from the
 *     DocumentSequenceService.
 *   - Cancelling a never-finalized draft does not consume a number from the
 *     sequence — the next finalize still gets the leading sequence number.
 */
class InvoiceDraftNumberingTest extends TestCase
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
            'name'     => 'Finance',
            'email'    => 'fin_' . uniqid() . '@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    private function accountHashId(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->hash_id;
    }

    private function makeDraft(InvoiceService $svc, User $user, Customer $customer): \App\Modules\Accounting\Models\Invoice
    {
        return $svc->create([
            'customer_id' => $customer->hash_id,
            'date'        => '2026-04-01',
            'due_date'    => '2026-04-30',
            'is_vatable'  => false,
            'items'       => [
                [
                    'revenue_account_id' => $this->accountHashId('4010'),
                    'description'        => 'Wiper bushings',
                    'quantity'           => '10',
                    'unit_price'         => '1000.00',
                ],
            ],
        ], $user);
    }

    public function test_draft_invoice_has_null_invoice_number_until_finalize(): void
    {
        $user     = $this->newUser();
        $customer = Customer::create(['name' => 'Toyota PH', 'payment_terms_days' => 30]);
        $svc      = app(InvoiceService::class);

        $draft = $this->makeDraft($svc, $user, $customer);

        $this->assertSame(InvoiceStatus::Draft, $draft->status);
        $this->assertNull($draft->invoice_number, 'Draft invoices must not carry a placeholder number.');

        // Sanity: no DRAFT-* row was persisted.
        $this->assertDatabaseMissing('invoices', ['id' => $draft->id, 'invoice_number' => 'DRAFT-%']);
        $this->assertSame(null, $draft->fresh()->invoice_number);
    }

    public function test_finalize_stamps_sequence_formatted_invoice_number(): void
    {
        $user     = $this->newUser();
        $customer = Customer::create(['name' => 'Nissan PH', 'payment_terms_days' => 30]);
        $svc      = app(InvoiceService::class);

        $draft = $this->makeDraft($svc, $user, $customer);
        $this->assertNull($draft->invoice_number);

        $finalized = $svc->finalize($draft, $user);

        $this->assertSame(InvoiceStatus::Finalized, $finalized->status);
        $this->assertNotNull($finalized->invoice_number);
        $this->assertMatchesRegularExpression(
            '/^INV-\d{6}-\d{4}$/',
            $finalized->invoice_number,
            'Finalized invoice must use INV-YYYYMM-NNNN format.',
        );
    }

    public function test_cancelled_draft_does_not_burn_a_sequence_number(): void
    {
        $user     = $this->newUser();
        $customer = Customer::create(['name' => 'Honda PH', 'payment_terms_days' => 30]);
        $svc      = app(InvoiceService::class);

        // Draft #1 — will be cancelled before finalize.
        $cancelled = $this->makeDraft($svc, $user, $customer);
        $this->assertNull($cancelled->invoice_number);
        $svc->cancel($cancelled, $user);
        $this->assertNull($cancelled->fresh()->invoice_number,
            'Cancelled drafts must remain NULL — they should not get a number stamped on cancel.');

        // Draft #2 — first invoice ever finalized in this period.
        $first = $svc->finalize($this->makeDraft($svc, $user, $customer), $user);

        // Draft #3 — second invoice finalized.
        $second = $svc->finalize($this->makeDraft($svc, $user, $customer), $user);

        // Numbers must be sequential — cancelled draft did not consume a slot.
        $extractSeq = fn (string $no): int => (int) substr($no, -4);
        $this->assertSame(
            $extractSeq($first->invoice_number) + 1,
            $extractSeq($second->invoice_number),
            'Cancelled drafts must not leave gaps in the sequence.',
        );
    }
}
