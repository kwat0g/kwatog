<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Services\HolidayService;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HolidayCacheAfterCommitTest extends TestCase
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

    public function test_holiday_generation_is_invalidated_only_after_commit(): void
    {
        Cache::forget('holidays:cache_version');
        $oldGeneration = Cache::get('holidays:cache_version', 0);

        DB::transaction(function () use ($oldGeneration): void {
            app(HolidayService::class)->create([
                'name' => 'Cache commit test',
                'date' => '2032-01-19',
                'type' => 'regular',
                'is_recurring' => false,
            ]);

            self::assertSame($oldGeneration, Cache::get('holidays:cache_version', 0));
        });

        self::assertNotSame($oldGeneration, Cache::get('holidays:cache_version'));
    }
}
