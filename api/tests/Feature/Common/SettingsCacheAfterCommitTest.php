<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Services\SettingsService;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsCacheAfterCommitTest extends TestCase
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

    public function test_setting_cache_invalidation_waits_until_the_write_commits(): void
    {
        $key = 'test.settings_after_commit';
        $versionKey = 'settings:version:'.hash('sha256', $key);
        $cacheKey = "settings:{$key}:vbefore-generation";
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode('new-value'),
                'group' => 'test',
                'label' => 'Cache ordering test',
                'description' => 'Verifies setting cache invalidation follows commit.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        Cache::put($versionKey, 'before-generation', 604800);
        Cache::put($cacheKey, 'old-value', 3600);

        DB::transaction(function () use ($key, $versionKey, $cacheKey): void {
            app(SettingsService::class)->set($key, 'new-value', 'test');

            self::assertSame('before-generation', Cache::get($versionKey));
            self::assertSame('old-value', Cache::get($cacheKey));
        });

        self::assertNotSame('before-generation', Cache::get($versionKey));
        // A pre-commit read can finish after commit, but the retired key is
        // unreachable from subsequent SettingsService reads.
        Cache::put($cacheKey, 'stale-old-value', 3600);
        self::assertSame('new-value', app(SettingsService::class)->get($key));
        self::assertSame('stale-old-value', Cache::get($cacheKey));
    }
}
