<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Services\DssParameterService;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DssParameterCacheAfterCommitTest extends TestCase
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

    public function test_parameter_invalidation_retires_pre_commit_cache_fills(): void
    {
        $service = app(DssParameterService::class);
        $service->set('test.detector', 'threshold', ['limit' => 1]);
        self::assertSame(['limit' => 1], $service->get('test.detector', 'threshold'));
        $generationCache = Cache::store('generations');
        $versionKey = 'dss:params:version:'.hash('sha256', "test.detector\0threshold");
        $version = (string) $generationCache->get($versionKey);
        $restoreVersion = (string) $generationCache->get('dss:params:restore_version');
        $cacheKey = "dss:params:test.detector:threshold:v{$restoreVersion}.{$version}";

        DB::transaction(function () use ($service, $cacheKey, $generationCache, $versionKey, $version): void {
            $service->set('test.detector', 'threshold', ['limit' => 2]);

            self::assertTrue(Cache::has($cacheKey));
            self::assertSame(['limit' => 1], Cache::get($cacheKey));
            self::assertSame($version, $generationCache->get($versionKey));
        });

        self::assertNotSame($version, $generationCache->get($versionKey));
        Cache::put($cacheKey, ['limit' => 999], 3600);
        self::assertSame(['limit' => 2], $service->get('test.detector', 'threshold'));
        self::assertSame(['limit' => 999], Cache::get($cacheKey));
    }
}
