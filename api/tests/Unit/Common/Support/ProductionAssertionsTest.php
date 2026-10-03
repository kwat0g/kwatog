<?php

declare(strict_types=1);

namespace Tests\Unit\Common\Support;

use App\Common\Support\ProductionAssertions;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 2 Task 12 — proves the production boot guard rejects dev defaults
 * for HASHIDS_SALT, APP_DEBUG, and APP_KEY, and stays silent in any
 * non-production environment.
 */
class ProductionAssertionsTest extends TestCase
{
    public function test_reverb_default_origins_are_restricted(): void
    {
        $origins = config('reverb.apps.apps.0.allowed_origins');

        $this->assertNotEmpty($origins);
        $this->assertNotContains('*', $origins);
        foreach ($origins as $origin) {
            $this->assertSame($origin, parse_url($origin, PHP_URL_HOST) ?: $origin);
        }
    }

    private function asProduction(): void
    {
        app()->detectEnvironment(fn () => 'production');
    }

    private function setSafeDefaults(): void
    {
        config()->set('app.debug', false);
        config()->set('hashids.connections.main.salt', 'real-random-salt-1234567890abcdef');
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('app.server_name', 'erp.ogami.test');
        config()->set('reverb.apps.apps.0.allowed_origins', ['erp.ogami.test']);
        // Set explicitly rather than relying on the ambient test env: the
        // assertion below must fail for the right reason, not because
        // SESSION_SECURE_COOKIE happened to be unset.
        config()->set('session.secure', true);
    }

    public function test_no_throw_in_local_environment_even_with_dev_defaults(): void
    {
        app()->detectEnvironment(fn () => 'local');
        config()->set('app.debug', true);
        config()->set('hashids.connections.main.salt', 'change_me');
        config()->set('app.key', 'base64:dev_default');

        ProductionAssertions::assertSafeOrFail();
        $this->assertTrue(true, 'No exception thrown outside production.');
    }

    public function test_no_throw_in_testing_environment_even_with_dev_defaults(): void
    {
        app()->detectEnvironment(fn () => 'testing');
        config()->set('app.debug', true);
        config()->set('hashids.connections.main.salt', 'change_me');

        ProductionAssertions::assertSafeOrFail();
        $this->assertTrue(true, 'No exception thrown in testing environment.');
    }

    public function test_passes_in_production_with_clean_config(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();

        ProductionAssertions::assertSafeOrFail();
        $this->assertTrue(true, 'Clean production config boots cleanly.');
    }

    public function test_throws_when_app_debug_true_in_production(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('app.debug', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_DEBUG/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_throws_when_hashids_salt_is_default(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('hashids.connections.main.salt', 'change_me');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HASHIDS_SALT/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_throws_when_hashids_salt_is_change_me_prefixed(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('hashids.connections.main.salt', 'change_me_to_a_long_random_string');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HASHIDS_SALT/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_throws_when_hashids_salt_is_empty(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('hashids.connections.main.salt', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HASHIDS_SALT/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_throws_when_app_key_is_dev_default(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('app.key', 'base64:dev_default_key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_KEY/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_throws_when_app_key_is_empty(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('app.key', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_KEY/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_throws_when_server_name_is_missing_or_localhost(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('app.server_name', 'localhost');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SERVER_NAME/');

        ProductionAssertions::assertSafeOrFail();
    }

    /**
     * M001 re-audit — every dev env template ships SESSION_SECURE_COOKIE=false,
     * so a production deployment seeded from one would serve the whole
     * cookie-based auth model over plaintext HTTP with nothing objecting.
     */
    public function test_throws_when_session_cookie_is_not_secure_in_production(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('session.secure', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SESSION_SECURE_COOKIE/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_throws_when_demo_data_is_enabled_in_production(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('app.seed_demo_data', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SEED_DEMO_DATA/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_demo_seed_guard_rejects_staging_environment(): void
    {
        app()->detectEnvironment(static fn (): string => 'staging');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Demo data may only be seeded in local or testing environments.');

            ProductionAssertions::assertDemoSeedingAllowed();
        } finally {
            app()->detectEnvironment(static fn (): string => 'testing');
        }
    }

    public function test_demo_seed_guard_rejects_a_non_test_database_in_testing(): void
    {
        app()->detectEnvironment(static fn (): string => 'testing');
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.url', null);
        config()->set('database.connections.pgsql.host', 'db');
        config()->set('database.connections.pgsql.database', 'ogami');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Demo data may only be seeded into a dedicated local test database when APP_ENV=testing.');

        ProductionAssertions::assertDemoSeedingAllowed();
    }

    public function test_demo_seed_guard_rejects_a_remote_test_named_database_url(): void
    {
        app()->detectEnvironment(static fn (): string => 'testing');
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.database', 'ogami_test');
        config()->set('database.connections.pgsql.host', 'db');
        config()->set('database.connections.pgsql.url', 'postgres://test:fake@production-db.example/ogami_test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Demo data may only be seeded into a dedicated local test database when APP_ENV=testing.');

        ProductionAssertions::assertDemoSeedingAllowed();
    }

    public function test_throws_when_reverb_accepts_every_origin_in_production(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('reverb.apps.apps.0.allowed_origins', ['*']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/REVERB_ALLOWED_ORIGINS/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_throws_when_reverb_uses_a_wildcard_subdomain_in_production(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('reverb.apps.apps.0.allowed_origins', ['*.ogami.test']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/REVERB_ALLOWED_ORIGINS/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_throws_when_reverb_has_no_allowed_origin_in_production(): void
    {
        $this->asProduction();
        $this->setSafeDefaults();
        config()->set('reverb.apps.apps.0.allowed_origins', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/REVERB_ALLOWED_ORIGINS/');

        ProductionAssertions::assertSafeOrFail();
    }

    public function test_aggregates_multiple_failures_into_one_message(): void
    {
        $this->asProduction();
        config()->set('app.debug', true);
        config()->set('hashids.connections.main.salt', 'change_me');
        config()->set('app.key', '');
        config()->set('app.server_name', 'localhost');
        config()->set('session.secure', false);

        try {
            ProductionAssertions::assertSafeOrFail();
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_DEBUG', $e->getMessage());
            $this->assertStringContainsString('HASHIDS_SALT', $e->getMessage());
            $this->assertStringContainsString('APP_KEY', $e->getMessage());
            $this->assertStringContainsString('SERVER_NAME', $e->getMessage());
            $this->assertStringContainsString('SESSION_SECURE_COOKIE', $e->getMessage());
        }
    }
}
