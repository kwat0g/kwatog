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
    private function asProduction(): void
    {
        app()->detectEnvironment(fn () => 'production');
    }

    private function setSafeDefaults(): void
    {
        config()->set('app.debug', false);
        config()->set('hashids.connections.main.salt', 'real-random-salt-1234567890abcdef');
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
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

    public function test_aggregates_multiple_failures_into_one_message(): void
    {
        $this->asProduction();
        config()->set('app.debug', true);
        config()->set('hashids.connections.main.salt', 'change_me');
        config()->set('app.key', '');

        try {
            ProductionAssertions::assertSafeOrFail();
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_DEBUG', $e->getMessage());
            $this->assertStringContainsString('HASHIDS_SALT', $e->getMessage());
            $this->assertStringContainsString('APP_KEY', $e->getMessage());
        }
    }
}
