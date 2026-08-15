<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\AccountingPeriodStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OGAMI-001 — accounting-period close/reopen lock-then-guard regression.
 *
 * close() now locks the authoritative period row before the closed-check (and
 * falls back to relocking the winner on the brand-new-row unique-index race);
 * reopen() re-reads under lock. Pins the deterministic invariants: closing an
 * already-closed period is a no-op, exactly one row per (year, month), reopen
 * requires the closed guard, and relock after reopen works.
 */
class AccountingPeriodCloseRegressionTest extends TestCase
{
    use RefreshDatabase;

    private AccountingPeriodService $svc;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->svc  = app(AccountingPeriodService::class);
        $this->user = User::factory()->create(['is_active' => true]);
    }

    public function test_close_is_idempotent_and_single_row(): void
    {
        $closed = $this->svc->close(2026, 8, $this->user);
        $this->assertSame(AccountingPeriodStatus::Closed, $closed->status);

        // Closing again must be a no-op, not a second row.
        $again = $this->svc->close(2026, 8, $this->user);
        $this->assertSame(AccountingPeriodStatus::Closed, $again->status);
        $this->assertSame(1, AccountingPeriod::query()->where('year', 2026)->where('month', 8)->count());
    }

    public function test_reopen_then_relock_cycle(): void
    {
        $this->svc->close(2026, 7, $this->user);

        $reopened = $this->svc->reopen(2026, 7, $this->user, 'Audit correction');
        $this->assertSame(AccountingPeriodStatus::Reopened, $reopened->status);

        // Relock after reopen works and clears the reopen metadata.
        $relocked = $this->svc->close(2026, 7, $this->user);
        $this->assertSame(AccountingPeriodStatus::Closed, $relocked->status);
        $this->assertNull($relocked->reopen_reason);
    }

    public function test_reopen_of_open_period_is_blocked(): void
    {
        // No period row exists for this month → treated as open.
        $this->expectException(BusinessRuleException::class);

        $this->svc->reopen(2026, 3, $this->user, 'No row to reopen');
    }

    public function test_reopen_of_reopened_period_is_blocked(): void
    {
        $this->svc->close(2026, 6, $this->user);
        $this->svc->reopen(2026, 6, $this->user, 'First reopen');

        $this->expectException(BusinessRuleException::class);

        $this->svc->reopen(2026, 6, $this->user, 'Second reopen');
    }
}
