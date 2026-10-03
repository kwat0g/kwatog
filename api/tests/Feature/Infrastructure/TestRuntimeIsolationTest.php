<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

class TestRuntimeIsolationTest extends TestCase
{
    public function test_api_suite_uses_process_local_cache(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
    }

    public function test_api_suite_uses_its_dedicated_postgres_database(): void
    {
        $database = config('database.connections.pgsql.database');

        $this->assertTrue(
            $database === 'ogami_test' || str_starts_with($database, 'ogami_test_'),
            'The API suite must use an ogami_test database.',
        );
    }
}
