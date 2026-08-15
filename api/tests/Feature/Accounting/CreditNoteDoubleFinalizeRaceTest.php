<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\CreditNoteStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\CreditNoteService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credit-note finalize race — the P01-01 shape on the GL.
 *
 * `CreditNoteService::finalize` guards on the passed model outside the
 * transaction (Invoice/Bill were already hardened; the credit note was missed).
 * Two concurrent finalizers both read `draft`, both post the VAT-reversing
 * journal entry and both assign a credit-note number → the GL gets two entries
 * for one credit note. The row must be locked and re-read inside the
 * transaction so the second finalizer is rejected.
 */
class CreditNoteDoubleFinalizeRaceTest extends TestCase
{
    use RefreshDatabase;

    private CreditNoteService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->svc = app(CreditNoteService::class);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function draftCreditNote(User $by): CreditNote
    {
        $customer = Customer::create(['name' => 'Acme', 'payment_terms_days' => 30]);

        return $this->svc->create([
            'type'        => 'customer',
            'date'        => now()->toDateString(),
            'is_vatable'  => true,
            'customer_id' => $customer->id,
            'reason'      => 'Price dispute',
            'lines'       => [
                ['account_id' => (string) Account::query()->where('code', '4010')->value('id'), 'description' => 'Overbilled units', 'amount' => '1000.00'],
            ],
        ], $by);
    }

    public function test_second_concurrent_finalize_is_blocked_and_posts_no_second_je(): void
    {
        $by = $this->admin();
        $cn = $this->draftCreditNote($by);

        // Two concurrent finalizers each fetched the row while it was draft.
        $finalizerA = CreditNote::query()->findOrFail($cn->id);
        $finalizerB = CreditNote::query()->findOrFail($cn->id);

        // Finalizer A commits: one balanced JE, number assigned, status finalized.
        $this->svc->finalize($finalizerA, $by);
        $this->assertSame(CreditNoteStatus::Finalized, CreditNote::query()->findOrFail($cn->id)->status);
        $this->assertSame(1, JournalEntry::query()
            ->where('reference_type', 'credit_note')
            ->where('reference_id', $cn->id)
            ->count());

        // Finalizer B acts on its stale instance — must be blocked, not re-posted.
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Only draft credit notes');

        $this->svc->finalize($finalizerB, $by);
    }
}
