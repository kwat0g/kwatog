<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\AccountingPeriodStatus;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/**
 * M025 — real PostgreSQL interleavings for the period advisory barrier.
 *
 * The parent holds the month lock while a child enters the actual posting or
 * scheduler service. Releasing the lock after changing the authoritative row
 * proves that the child cannot commit from an earlier OPEN read.
 */
class AccountingPeriodPostingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> */
    private array $fixtureJournalIds = [];

    /** @var array<int, int> */
    private array $fixtureUserIds = [];

    /** @var array<int, array{year:int, month:int}> */
    private array $fixturePeriods = [];

    private string $currentWorkerResultPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupFixtures();
        } finally {
            parent::tearDown();
        }
    }

    public function test_manual_post_waits_for_close_on_an_existing_period(): void
    {
        $this->requirePostgresProcessSupport();

        $admin = $this->admin();
        $period = $this->openPeriod(2031, 8);
        $je = $this->draft($admin, '2031-08-15');
        $this->commitFixtureTransaction();

        $result = $this->runBlockedWorker(
            function () use ($je, $admin): void {
                app(JournalEntryService::class)->post(
                    JournalEntry::findOrFail($je->id),
                    User::findOrFail($admin->id),
                );
            },
            function () use ($period, $admin): void {
                DB::table('accounting_periods')->where('id', $period->id)->update([
                    'status' => AccountingPeriodStatus::Closed->value,
                    'closed_at' => now(),
                    'closed_by' => $admin->id,
                    'updated_at' => now(),
                ]);
            },
        );

        $this->assertStringContainsString(ClosedPeriodException::class, $result);
        $this->assertSame('draft', JournalEntry::findOrFail($je->id)->status->value);
    }

    public function test_system_post_waits_for_close_on_a_rowless_period(): void
    {
        $this->requirePostgresProcessSupport();

        $admin = $this->admin();
        $je = $this->draft($admin, '2031-09-15');
        $this->fixturePeriods[] = ['year' => 2031, 'month' => 9];
        $this->commitFixtureTransaction();

        $result = $this->runBlockedWorker(
            function () use ($je, $admin): void {
                app(JournalEntryService::class)->postSystem(
                    JournalEntry::findOrFail($je->id),
                    $admin->id,
                );
            },
            function () use ($admin): void {
                $now = now();
                DB::table('accounting_periods')->insert([
                    'year' => 2031,
                    'month' => 9,
                    'status' => AccountingPeriodStatus::Closed->value,
                    'closed_at' => $now,
                    'closed_by' => $admin->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            },
        );

        $this->assertStringContainsString(ClosedPeriodException::class, $result);
        $this->assertSame('draft', JournalEntry::findOrFail($je->id)->status->value);
    }

    public function test_post_can_commit_before_a_later_close_authority_change(): void
    {
        $this->requirePostgresProcessSupport();

        $admin = $this->admin();
        $period = $this->openPeriod(2031, 10);
        $je = $this->draft($admin, '2031-10-15');
        $this->commitFixtureTransaction();

        $result = $this->runBlockedWorker(
            function () use ($je, $admin): void {
                app(JournalEntryService::class)->post(
                    JournalEntry::findOrFail($je->id),
                    User::findOrFail($admin->id),
                );
            },
            static function (): void {
                // Releasing the advisory lock without closing models the
                // allowed happens-before ordering: post commits first.
            },
        );

        $this->assertSame('ok', $result);
        $this->assertSame('posted', JournalEntry::findOrFail($je->id)->status->value);

        app(AccountingPeriodService::class)->close(2031, 10, $admin);
        $this->assertSame(
            AccountingPeriodStatus::Closed,
            AccountingPeriod::findOrFail($period->id)->status,
        );
    }

    public function test_scheduler_rechecks_a_period_after_waiting_for_the_month_lock(): void
    {
        $this->requirePostgresProcessSupport();

        $admin = $this->admin();
        $period = AccountingPeriod::create([
            'year' => 2031,
            'month' => 11,
            'status' => AccountingPeriodStatus::Reopened,
        ]);
        $period->update([
            'reopened_at' => now()->subHours(49),
            'reopened_by' => $admin->id,
            'reopen_reason' => 'Concurrency harness',
        ]);
        $this->fixturePeriods[] = ['year' => 2031, 'month' => 11];
        $this->commitFixtureTransaction();

        $result = $this->runBlockedWorker(
            function (): void {
                $count = app(AccountingPeriodService::class)->relockStaleReopenedPeriods(48);
                file_put_contents($this->workerResultPath(), (string) $count);
            },
            function () use ($period): void {
                DB::table('accounting_periods')->where('id', $period->id)->update([
                    'reopened_at' => now()->subHour(),
                    'updated_at' => now(),
                ]);
            },
            workerWritesResult: true,
        );

        $this->assertSame('0', $result);
        $fresh = AccountingPeriod::findOrFail($period->id);
        $this->assertSame(AccountingPeriodStatus::Reopened, $fresh->status);
        $this->assertTrue($fresh->reopened_at->isAfter(now()->subHours(2)));
    }

    private function requirePostgresProcessSupport(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for two-connection concurrency coverage.');
        }
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        $this->fixtureUserIds[] = $admin->id;

        return $admin;
    }

    private function openPeriod(int $year, int $month): AccountingPeriod
    {
        $period = AccountingPeriod::create([
            'year' => $year,
            'month' => $month,
            'status' => AccountingPeriodStatus::Open,
        ]);
        $this->fixturePeriods[] = ['year' => $year, 'month' => $month];

        return $period;
    }

    private function draft(User $admin, string $date): JournalEntry
    {
        $cash = Account::query()->where('code', '1010')->firstOrFail();
        $equity = Account::query()->where('code', '3010')->firstOrFail();
        $je = app(JournalEntryService::class)->create([
            'date' => $date,
            'description' => 'Period lock concurrency harness',
            'lines' => [
                ['account_id' => $cash->hash_id, 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $equity->hash_id, 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $admin);
        $this->fixtureJournalIds[] = $je->id;

        return $je;
    }

    /**
     * @param callable():void $worker
     * @param callable():void $afterWorkerStarted
     */
    private function runBlockedWorker(callable $worker, callable $afterWorkerStarted, bool $workerWritesResult = false): string
    {
        $resultPath = $this->workerResultPath();
        $this->currentWorkerResultPath = $resultPath;
        @unlink($resultPath);

        DB::beginTransaction();
        DB::select(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ['accounting-period:2031-'.str_pad((string) $this->latestFixtureMonth(), 2, '0', STR_PAD_LEFT)],
        );

        [$parentSocket, $childSocket] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($parentSocket);
            $base = config('database.connections.'.config('database.default'));
            config(['database.connections.period_harness' => $base, 'database.default' => 'period_harness']);
            DB::connection('period_harness')->getPdo();
            fwrite($childSocket, "ready\n");
            fgets($childSocket);

            try {
                $worker();
                if (! $workerWritesResult) {
                    file_put_contents($resultPath, 'ok');
                }
            } catch (Throwable $e) {
                file_put_contents($resultPath, get_class($e).':'.$e->getMessage());
            } finally {
                fclose($childSocket);
            }
            exit(0);
        }

        fclose($childSocket);
        stream_set_timeout($parentSocket, 30);
        $this->assertSame('ready', trim((string) fgets($parentSocket)));
        fwrite($parentSocket, "go\n");
        usleep(250000);
        $this->assertSame('', (string) @file_get_contents($resultPath), 'Worker must wait on the period advisory lock.');

        $afterWorkerStarted();
        DB::commit();
        pcntl_waitpid($pid, $status);
        fclose($parentSocket);

        $result = (string) @file_get_contents($resultPath);
        @unlink($resultPath);

        return $result;
    }

    private function latestFixtureMonth(): int
    {
        $period = end($this->fixturePeriods);
        if ($period !== false) {
            return $period['month'];
        }

        // The row-less test registers its month only when cleanup metadata is
        // needed; its journal date is the source of truth for the lock key.
        return 9;
    }

    private function workerResultPath(): string
    {
        return $this->currentWorkerResultPath !== ''
            ? $this->currentWorkerResultPath
            : sys_get_temp_dir().'/m025-period-race-'.getmypid().'.result';
    }

    private function commitFixtureTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
    }

    private function cleanupFixtures(): void
    {
        if ($this->fixtureJournalIds !== []) {
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $this->fixtureJournalIds)->delete();
            DB::table('journal_entries')->whereIn('id', $this->fixtureJournalIds)->delete();
        }
        foreach ($this->fixturePeriods as $period) {
            DB::table('accounting_periods')
                ->where('year', $period['year'])
                ->where('month', $period['month'])
                ->delete();
        }
        if ($this->fixtureUserIds !== []) {
            DB::table('users')->whereIn('id', $this->fixtureUserIds)->delete();
        }
    }
}
