<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Dashboard;

use App\Common\Support\FailOpenCache;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardCacheGenerationFailureTest extends TestCase
{
    public function test_transient_generation_write_failure_is_retried_before_retiring_marker(): void
    {
        $repository = Cache::store('array');
        $repository->forget('dashboard:cache:version');
        $repository->put('dashboard:cache:version', 'old-generation', 604800);
        $repository->put('dashboard:probe:vold-generation', 'stale-snapshot', 60);

        $attempt = 0;
        $failingRepository = \Mockery::mock(Repository::class);
        $failingRepository->shouldReceive('put')
            ->twice()
            ->with('dashboard:cache:version', \Mockery::type('string'), 604800)
            ->andReturnUsing(static function (string $key, string $generation, int $ttl) use (&$attempt, $repository): bool {
                $attempt++;

                return $attempt === 1 ? false : $repository->put($key, $generation, $ttl);
            });
        $failingRepository->shouldNotReceive('forget');
        Cache::shouldReceive('store')
            ->with('generations')
            ->twice()
            ->andReturn($failingRepository, $repository);
        Cache::shouldReceive('store')
            ->with('array')
            ->once()
            ->andReturn($repository);
        DB::shouldReceive('afterCommit')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback());

        DashboardCacheGeneration::touch();

        $generation = DashboardCacheGeneration::current();
        $value = FailOpenCache::remember(
            Cache::store('array'),
            "dashboard:probe:v{$generation}",
            60,
            static fn (): string => 'fresh-source-value',
            'dashboard.failure-test.retry',
        );

        self::assertSame(2, $attempt);
        self::assertSame('fresh-source-value', $value);
    }

    public function test_failed_generation_write_does_not_keep_old_snapshot_reachable(): void
    {
        $repository = Cache::store('array');
        $repository->forget('dashboard:cache:version');
        $repository->put('dashboard:cache:version', 'old-generation', 604800);
        $repository->put('dashboard:probe:vold-generation', 'stale-snapshot', 60);

        $failingRepository = \Mockery::mock(Repository::class);
        $failingRepository->shouldReceive('put')
            ->twice()
            ->with('dashboard:cache:version', \Mockery::type('string'), 604800)
            ->andReturn(false, false);
        $failingRepository->shouldReceive('forget')
            ->once()
            ->with('dashboard:cache:version')
            ->andReturnUsing(static fn (string $key): bool => $repository->forget($key));
        Cache::shouldReceive('store')
            ->with('generations')
            ->twice()
            ->andReturn($failingRepository, $repository);
        Cache::shouldReceive('store')
            ->with('array')
            ->once()
            ->andReturn($repository);
        DB::shouldReceive('afterCommit')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback());

        DashboardCacheGeneration::touch();

        $generation = DashboardCacheGeneration::current();
        $value = FailOpenCache::remember(
            Cache::store('array'),
            "dashboard:probe:v{$generation}",
            60,
            static fn (): string => 'fresh-source-value',
            'dashboard.failure-test',
        );

        self::assertSame('fresh-source-value', $value);
    }
}
