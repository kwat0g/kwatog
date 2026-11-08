<?php

declare(strict_types=1);

namespace Tests\Unit\E2E;

use App\Common\Support\E2EEnvironment;
use RuntimeException;
use Tests\TestCase;

class E2EEnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'testing',
            'app.e2e.enabled' => true,
            'app.e2e.database' => 'ogami_e2e',
            'app.e2e.compose_project' => 'ogami-erp-e2e',
            'app.e2e.control_token' => str_repeat('a', 64),
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'db',
            'database.connections.pgsql.database' => 'ogami_e2e',
        ]);
    }

    public function test_allows_only_the_explicit_dedicated_e2e_database(): void
    {
        $this->assertSame('ogami_e2e', E2EEnvironment::assertEnabled());
    }

    public function test_refuses_when_e2e_flag_is_disabled(): void
    {
        config(['app.e2e.enabled' => false]);

        $this->expectException(RuntimeException::class);
        E2EEnvironment::assertEnabled();
    }

    public function test_refuses_when_database_or_host_does_not_match_allowlist(): void
    {
        config(['database.connections.pgsql.database' => 'ogami']);

        $this->expectException(RuntimeException::class);
        E2EEnvironment::assertEnabled();
    }

    public function test_refuses_when_control_token_is_not_a_private_secret(): void
    {
        config(['app.e2e.control_token' => 'short']);

        $this->expectException(RuntimeException::class);
        E2EEnvironment::assertEnabled();
    }
}
