<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Observers\JournalEntryObserver;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatementCacheAfterCommitTest extends TestCase
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

    public function test_statement_cache_tags_are_flushed_after_commit(): void
    {
        $tags = ['financial_statements', 'finance_dashboard'];
        Cache::tags($tags)->put('commit-probe', 'old-snapshot', 300);
        $observer = new JournalEntryObserver;

        DB::transaction(function () use ($tags, $observer): void {
            $observer->created(new JournalEntry);

            self::assertSame('old-snapshot', Cache::tags($tags)->get('commit-probe'));
        });

        self::assertNull(Cache::tags($tags)->get('commit-probe'));
    }
}
