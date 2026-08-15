<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use Tests\TestCase;

class TmpEnvProbeTest extends TestCase
{
    public function test_probe(): void
    {
        putenv('COMPANY_CERTIFICATION=ENV-LEAK-SENTINEL');

        fwrite(STDERR, "\n=== PROBE ===\n");
        fwrite(STDERR, 'environmentFile: '.app()->environmentFile()."\n");
        fwrite(STDERR, 'environmentPath: '.app()->environmentPath()."\n");
        fwrite(STDERR, 'APP_ENV: '.var_export(env('APP_ENV'), true)."\n");
        fwrite(STDERR, 'configurationIsCached: '.var_export(app()->configurationIsCached(), true)."\n");
        fwrite(STDERR, 'getenv: '.var_export(getenv('COMPANY_CERTIFICATION'), true)."\n");
        fwrite(STDERR, '$_ENV: '.var_export($_ENV['COMPANY_CERTIFICATION'] ?? 'ABSENT', true)."\n");
        fwrite(STDERR, '$_SERVER: '.var_export($_SERVER['COMPANY_CERTIFICATION'] ?? 'ABSENT', true)."\n");
        fwrite(STDERR, 'env(): '.var_export(env('COMPANY_CERTIFICATION'), true)."\n");
        fwrite(STDERR, 'Env::get(): '.var_export(\Illuminate\Support\Env::get('COMPANY_CERTIFICATION'), true)."\n");
        fwrite(STDERR, 'COMPANY_LEGAL_NAME $_ENV: '.var_export($_ENV['COMPANY_LEGAL_NAME'] ?? 'ABSENT', true)."\n");
        fwrite(STDERR, 'COMPANY_LEGAL_NAME env(): '.var_export(env('COMPANY_LEGAL_NAME'), true)."\n");
        putenv('PROBE_FRESH_KEY=FRESH-VALUE');
        fwrite(STDERR, 'PROBE_FRESH_KEY env(): '.var_export(env('PROBE_FRESH_KEY'), true)."\n");
        fwrite(STDERR, "=== /PROBE ===\n");

        putenv('COMPANY_CERTIFICATION');
        putenv('PROBE_FRESH_KEY');

        $this->assertTrue(true);
    }
}
