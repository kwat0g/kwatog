<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Production\Services\ProductionDashboardService;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionDashboardCacheAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $transactions = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_dashboard_cache_invalidation_waits_for_outer_commit(): void
    {
        Cache::put('dashboard:production:version', 'before-generation', 604800);
        Cache::put('dashboard:production:vbefore-generation', ['revision' => 'before'], 60);

        DB::transaction(function (): void {
            ProductionDashboardService::forgetCache();

            self::assertSame('before-generation', Cache::get('dashboard:production:version'));
            self::assertSame(
                ['revision' => 'before'],
                Cache::get('dashboard:production:vbefore-generation'),
            );
        });

        self::assertNotSame('before-generation', Cache::get('dashboard:production:version'));
        // A request that started before commit may still finish its fill, but
        // it can only populate the retired generation, which new reads ignore.
        Cache::put('dashboard:production:vbefore-generation', ['revision' => 'stale'], 30);
        self::assertSame(['revision' => 'stale'], Cache::get('dashboard:production:vbefore-generation'));
        self::assertNotSame('before-generation', Cache::get('dashboard:production:version'));
    }
}
