<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\AccountingPeriodStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PostgreSQL proof that duplicate-period recovery starts a fresh transaction. */
class AccountingPeriodDuplicateRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const COMPETITOR_CONNECTION = 'm025_period_competitor';

    /**
     * The competitor must observe committed fixtures, so the normal
     * RefreshDatabase transaction is intentionally committed in the test.
     * Rebuild before the next test class instead of leaking the user and
     * period fixtures into its transaction.
     */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }

    public function test_close_recovers_when_a_non_cooperating_writer_wins_the_insert(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL duplicate-key transaction semantics.');
        }

        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);

        // The competitor must commit independently of the test transaction so
        // the first close insert can see a real unique-key conflict.
        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
        $base = config('database.connections.'.config('database.default'));
        config(['database.connections.'.self::COMPETITOR_CONNECTION => $base]);
        DB::purge(self::COMPETITOR_CONNECTION);
        DB::connection(self::COMPETITOR_CONNECTION)->getPdo();

        $competitorInserted = false;
        $dispatcher = AccountingPeriod::getEventDispatcher();
        AccountingPeriod::creating(function (AccountingPeriod $period) use (&$competitorInserted): void {
            if ($competitorInserted || $period->year !== 2032 || $period->month !== 1) {
                return;
            }

            $now = now();
            DB::connection(self::COMPETITOR_CONNECTION)->table('accounting_periods')->insert([
                'year' => 2032,
                'month' => 1,
                'status' => AccountingPeriodStatus::Open->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $competitorInserted = true;
        });

        try {
            $closed = app(AccountingPeriodService::class)->close(2032, 1, $admin);
        } finally {
            AccountingPeriod::setEventDispatcher($dispatcher);
            DB::table('accounting_periods')->where('year', 2032)->where('month', 1)->delete();
            DB::purge(self::COMPETITOR_CONNECTION);
        }

        $this->assertTrue($competitorInserted);
        $this->assertSame(AccountingPeriodStatus::Closed, $closed->status);
        $this->assertNotNull($closed->getKey());
    }
}
