<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Common\Services\EmailBrandingService;
use App\Common\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F-040 — transactional email branding must resolve from settings only.
 *
 * EmailBrandingService::value() used to fall back to env() between the
 * settings row and the hardcoded default. Production runs `config:cache`
 * (docker/php/prod-entrypoint.sh:15), after which env() returns null, so that
 * tier was dead in the only environment that mattered while still firing
 * locally — a silent dev/prod divergence.
 *
 * WHY email.brand_name IS THE PROBE KEY. It is the key the removed tier broke
 * outright: value() derived the variable name EMAIL_BRAND_NAME from the
 * setting key, while .env and .env.example both define
 * COMPANY_EMAIL_BRAND_NAME. The tier therefore read a variable the project
 * never documented, and the documented one was never read.
 *
 * WHY THE SENTINEL IS WRITTEN TO ALL THREE SOURCES. Laravel's Env repository
 * reads adapters in order: ServerConstAdapter ($_SERVER), EnvConstAdapter
 * ($_ENV), then PutenvAdapter (getenv()). A putenv()-only sentinel is
 * therefore shadowed by any value already sitting in $_SERVER. That is not
 * hypothetical here: docker-compose.yml:11 injects APP_ENV=local into the
 * container and phpunit.xml declares APP_ENV=testing without force="true", so
 * PHPUnit cannot override it and Laravel loads the dev .env for the whole
 * suite. .env pins every company.* branding variable to a string byte-
 * identical to its hardcoded fallback, which makes tier 2 and tier 3
 * indistinguishable for those keys. Writing all three sources removes this
 * test's dependence on the probe variable happening to be absent from .env.
 */
class EmailBrandingResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE_KEY = 'email.brand_name';
    private const PROBE_ENV = 'EMAIL_BRAND_NAME';
    private const FALLBACK = 'Ogami Philippines';
    private const SENTINEL = 'ENV-LEAK-SENTINEL';

    private string|false $originalServer;

    private string|false $originalEnv;

    private string|false $originalPutenv;

    protected function setUp(): void
    {
        parent::setUp();

        // Capture absent-vs-set per source so tearDown can put each one back
        // exactly. Blindly unsetting a key that legitimately held a value
        // would leak into whichever test runs next — the same class of
        // ordering bug this test exists to catch.
        $this->originalServer = isset($_SERVER[self::PROBE_ENV]) ? (string) $_SERVER[self::PROBE_ENV] : false;
        $this->originalEnv = isset($_ENV[self::PROBE_ENV]) ? (string) $_ENV[self::PROBE_ENV] : false;
        $this->originalPutenv = getenv(self::PROBE_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->originalServer === false) {
            unset($_SERVER[self::PROBE_ENV]);
        } else {
            $_SERVER[self::PROBE_ENV] = $this->originalServer;
        }

        if ($this->originalEnv === false) {
            unset($_ENV[self::PROBE_ENV]);
        } else {
            $_ENV[self::PROBE_ENV] = $this->originalEnv;
        }

        // putenv() with a bare name unsets the variable; NAME=value restores it.
        if ($this->originalPutenv === false) {
            putenv(self::PROBE_ENV);
        } else {
            putenv(self::PROBE_ENV.'='.$this->originalPutenv);
        }

        parent::tearDown();
    }

    public function test_environment_is_not_a_branding_source_when_the_setting_is_empty(): void
    {
        app(SettingsService::class)->set(self::PROBE_KEY, '');
        $this->injectEnvSentinel();

        // Guard the premise: if a future seeder populates this key, the test
        // would pass for the wrong reason.
        $this->assertSame(
            '',
            (string) app(SettingsService::class)->get(self::PROBE_KEY),
            'Probe setting must be empty for this test to exercise the fallback path.',
        );
        // Guard the mechanism: if the sentinel is not visible to env(), this
        // test cannot detect an env tier and would pass vacuously.
        $this->assertSame(
            self::SENTINEL,
            env(self::PROBE_ENV),
            'Sentinel must reach env() or this test cannot detect an environment tier.',
        );

        $brand = app(EmailBrandingService::class)->data();

        $this->assertSame(
            self::FALLBACK,
            $brand['name'],
            'Branding fell through to env(); settings must be the only configured source.',
        );
        $this->assertStringNotContainsString('SENTINEL', (string) $brand['name']);
    }

    public function test_settings_row_is_authoritative_over_the_hardcoded_default(): void
    {
        app(SettingsService::class)->set(self::PROBE_KEY, 'Ogami Philippines (Transactional)');
        $this->injectEnvSentinel();

        $brand = app(EmailBrandingService::class)->data();

        $this->assertSame('Ogami Philippines (Transactional)', $brand['name']);
    }

    /**
     * Make the sentinel visible to every adapter Laravel's Env repository
     * consults, so no earlier source can shadow it.
     */
    private function injectEnvSentinel(): void
    {
        $_SERVER[self::PROBE_ENV] = self::SENTINEL;
        $_ENV[self::PROBE_ENV] = self::SENTINEL;
        putenv(self::PROBE_ENV.'='.self::SENTINEL);
    }
}
