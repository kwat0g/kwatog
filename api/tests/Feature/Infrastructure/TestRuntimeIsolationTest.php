<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestRuntimeIsolationTest extends TestCase
{
    public function test_api_suite_uses_isolated_runtime_drivers(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('log', config('broadcasting.default'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('session.driver'));
    }

    public function test_api_suite_uses_its_dedicated_postgres_database(): void
    {
        $database = config('database.connections.pgsql.database');

        $this->assertTrue(
            $database === 'ogami_test' || str_starts_with($database, 'ogami_test_'),
            'The API suite must use an ogami_test database.',
        );
        $this->assertSame(getenv('DB_DATABASE'), $database);
        $this->assertSame($database, DB::selectOne('select current_database() as name')->name);
        $this->assertFalse(app()->configurationIsCached());
    }
}
