<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P20 (audit untraced list) — JournalEntryService::post still guards on the
 * unlocked pre-transaction read while reverse() was already hardened. A stale
 * draft model handed to post() after a concurrent reversal would re-post a
 * terminal entry. post() must re-check the authoritative row under its lock.
 */
class JournalEntryPostRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function admin(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');

        return User::create([
            'name'     => 'Admin',
            'email'    => 'a_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    private function id(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->hash_id;
    }

    public function test_posting_a_stale_draft_after_concurrent_reversal_is_blocked(): void
    {
        $svc   = app(JournalEntryService::class);
        $maker = $this->admin();

        $je = $svc->create([
            'date'        => '2026-04-15',
            'description' => 'Stale draft',
            'lines'       => [
                ['account_id' => $this->id('1010'), 'debit' => '500.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0',      'credit' => '500.00'],
            ],
        ], $maker);

        // A concurrent reversal flips the authoritative row to 'reversed'
        // AFTER the draft model was loaded. The in-memory model is still
        // Draft, so an unlocked guard cannot see the flip.
        DB::table('journal_entries')->where('id', $je->id)->update(['status' => 'reversed']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Only draft entries can be posted.');

        // Posting the stale model must fail against the locked authoritative row.
        $svc->post($je, $this->admin());
    }
}
