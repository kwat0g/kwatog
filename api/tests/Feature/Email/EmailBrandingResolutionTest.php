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
 * COMPANY_CERTIFICATION is the probe key because phpunit.xml does not set it,
 * and its hardcoded fallback differs from any env value we inject. The other
 * company keys are configured in phpunit.xml to exactly their fallback
 * strings, so they cannot distinguish tier 2 from tier 3.
 */
class EmailBrandingResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE_KEY = 'company.certification';
    private const PROBE_ENV = 'COMPANY_CERTIFICATION';
    private const FALLBACK = 'IATF 16949:2016 Certified';

    protected function tearDown(): void
    {
        // Calling putenv() with a bare name unsets the variable, so the
        // sentinel cannot leak into any test that runs after this one.
        putenv(self::PROBE_ENV);

        parent::tearDown();
    }

    public function test_environment_is_not_a_branding_source_when_the_setting_is_empty(): void
    {
        app(SettingsService::class)->set(self::PROBE_KEY, '');
        putenv(self::PROBE_ENV.'=ENV-LEAK-SENTINEL');

        // Guard the premise: if a future seeder populates this key, the test
        // would pass for the wrong reason.
        $this->assertSame(
            '',
            (string) app(SettingsService::class)->get(self::PROBE_KEY),
            'Probe setting must be empty for this test to exercise the fallback path.',
        );

        $brand = app(EmailBrandingService::class)->data();

        $this->assertSame(
            self::FALLBACK,
            $brand['certification'],
            'Branding fell through to env(); settings must be the only configured source.',
        );
        $this->assertStringNotContainsString('SENTINEL', (string) $brand['certification']);
    }

    public function test_settings_row_is_authoritative_over_the_hardcoded_default(): void
    {
        app(SettingsService::class)->set(self::PROBE_KEY, 'IATF 16949:2016 Certified (Ogami Cavite)');
        putenv(self::PROBE_ENV.'=ENV-LEAK-SENTINEL');

        $brand = app(EmailBrandingService::class)->data();

        $this->assertSame('IATF 16949:2016 Certified (Ogami Cavite)', $brand['certification']);
    }
}
