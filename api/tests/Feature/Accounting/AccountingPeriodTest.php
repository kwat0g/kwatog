<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\AccountingPeriodStatus;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingPeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function user(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');

        return User::create([
            'name'     => 'Test',
            'email'    => 't_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'),
            'role_id'  => $roleId,
        ]);
    }

    private function id(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->hash_id;
    }

    /** Build a balanced JE on a given date via the service. */
    private function postJe(JournalEntryService $svc, User $user, string $date): void
    {
        $je = $svc->create([
            'date'        => $date,
            'description' => 'Test entry',
            'lines'       => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0',      'credit' => '100.00'],
            ],
        ], $user);

        $svc->post($je, $user);
    }

    public function test_closed_period_blocks_je_dated_in_that_month(): void
    {
        $user = $this->user();
        app(AccountingPeriodService::class)->close(2026, 4, $user);

        $svc = app(JournalEntryService::class);

        $this->expectException(ClosedPeriodException::class);
        $this->postJe($svc, $user, '2026-04-15');
    }

    public function test_open_month_with_no_row_allows_posting(): void
    {
        $user = $this->user();
        $svc  = app(JournalEntryService::class);

        // No accounting_periods row for 2026-05 → treated as OPEN.
        $this->postJe($svc, $user, '2026-05-10');

        $this->assertSame(0, AccountingPeriod::query()->where('year', 2026)->where('month', 5)->count());
        $this->assertTrue(true); // reached here without exception
    }

    public function test_reopened_period_allows_posting(): void
    {
        $user = $this->user();
        $periods = app(AccountingPeriodService::class);

        $periods->close(2026, 4, $user);
        $period = $periods->reopen(2026, 4, $user, 'Late adjustment approved by VP.');

        $this->assertSame(AccountingPeriodStatus::Reopened, $period->status);
        $this->assertSame('Late adjustment approved by VP.', $period->reopen_reason);
        $this->assertNotNull($period->reopened_by);

        $svc = app(JournalEntryService::class);
        // Should NOT throw.
        $this->postJe($svc, $user, '2026-04-20');
        $this->assertTrue(true);
    }

    public function test_closing_one_month_does_not_block_a_different_open_month(): void
    {
        $user = $this->user();
        app(AccountingPeriodService::class)->close(2026, 4, $user);

        $svc = app(JournalEntryService::class);

        // April is closed, but May is open → allowed.
        $this->postJe($svc, $user, '2026-05-01');
        $this->assertTrue(true);
    }

    public function test_assert_posting_allowed_throws_on_closed_period(): void
    {
        $user = $this->user();
        $periods = app(AccountingPeriodService::class);
        $periods->close(2026, 4, $user);

        $this->expectException(ClosedPeriodException::class);
        $periods->assertPostingAllowed('2026-04-30');
    }

    public function test_reopen_requires_a_closed_period(): void
    {
        $user = $this->user();
        $periods = app(AccountingPeriodService::class);

        // No row yet → cannot reopen.
        $this->expectException(\RuntimeException::class);
        $periods->reopen(2026, 7, $user, 'nope');
    }

    public function test_close_is_idempotent_on_already_closed_period(): void
    {
        $user = $this->user();
        $periods = app(AccountingPeriodService::class);

        $first  = $periods->close(2026, 4, $user);
        $second = $periods->close(2026, 4, $user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(AccountingPeriodStatus::Closed, $second->status);
        $this->assertSame(1, AccountingPeriod::query()->where('year', 2026)->where('month', 4)->count());
    }
}
