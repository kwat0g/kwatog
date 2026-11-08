<?php

declare(strict_types=1);

namespace Tests\Unit\E2E;

use App\Common\Support\E2EClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class E2EClockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'testing',
            'app.e2e.enabled' => true,
            'app.e2e.database' => 'ogami_e2e',
            'app.e2e.compose_project' => 'ogami-erp-e2e',
            'app.e2e.control_token' => str_repeat('b', 64),
            'session.driver' => 'database',
            'session.table' => 'sessions',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'db',
            'database.connections.pgsql.database' => 'ogami_e2e',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_freezing_persists_one_utc_instant_and_applies_it_to_mutable_and_immutable_carbon(): void
    {
        $repository = Mockery::mock();
        $repository->shouldReceive('put')->once()->with(
            E2EClock::CACHE_KEY,
            '2026-11-03T04:00:00+00:00',
            60 * 24 * 30,
        );
        Cache::shouldReceive('store')->once()->with('redis')->andReturn($repository);
        $sessions = Mockery::mock();
        $sessions->shouldReceive('update')->once()->with([
            'last_activity' => CarbonImmutable::parse('2026-11-03T04:00:00+00:00')->timestamp,
        ])->andReturn(1);
        DB::shouldReceive('table')->once()->with('sessions')->andReturn($sessions);

        $result = app(E2EClock::class)->freeze('2026-11-03T12:00:00+08:00');

        $this->assertSame('2026-11-03T04:00:00+00:00', $result->toIso8601String());
        $this->assertSame('2026-11-03T04:00:00+00:00', Carbon::now('UTC')->toIso8601String());
        $this->assertSame('2026-11-03T04:00:00+00:00', CarbonImmutable::now('UTC')->toIso8601String());
    }

    public function test_clock_apply_clears_carbon_time_when_no_frozen_instant_exists(): void
    {
        Carbon::setTestNow('2001-01-01T00:00:00Z');
        CarbonImmutable::setTestNow('2001-01-01T00:00:00Z');

        $repository = Mockery::mock();
        $repository->shouldReceive('get')->once()->with(E2EClock::CACHE_KEY)->andReturn(null);
        Cache::shouldReceive('store')->once()->with('redis')->andReturn($repository);

        app(E2EClock::class)->apply();

        $this->assertNotSame('2001-01-01T00:00:00+00:00', Carbon::now('UTC')->toIso8601String());
        $this->assertNotSame('2001-01-01T00:00:00+00:00', CarbonImmutable::now('UTC')->toIso8601String());
    }

    public function test_clear_rebases_database_sessions_to_real_time(): void
    {
        Carbon::setTestNow('2026-11-03T04:00:00+00:00');
        CarbonImmutable::setTestNow('2026-11-03T04:00:00+00:00');

        $repository = Mockery::mock();
        $repository->shouldReceive('forget')->once()->with(E2EClock::CACHE_KEY);
        Cache::shouldReceive('store')->once()->with('redis')->andReturn($repository);
        $sessions = Mockery::mock();
        $sessions->shouldReceive('update')->once()->withArgs(static function (array $values): bool {
            return isset($values['last_activity'])
                && abs($values['last_activity'] - time()) <= 1;
        })->andReturn(1);
        DB::shouldReceive('table')->once()->with('sessions')->andReturn($sessions);

        app(E2EClock::class)->clear();

        $this->assertNotSame('2026-11-03T04:00:00+00:00', Carbon::now('UTC')->toIso8601String());
        $this->assertNotSame('2026-11-03T04:00:00+00:00', CarbonImmutable::now('UTC')->toIso8601String());
    }
}
