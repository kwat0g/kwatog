<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Services\Government\BirTaxComputationService;
use App\Modules\Payroll\Services\Government\PagibigComputationService;
use App\Modules\Payroll\Services\Government\PhilhealthComputationService;
use App\Modules\Payroll\Services\Government\SssComputationService;
use Database\Seeders\GovernmentTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Reference cases for the four government deduction services.
 *
 * Values are pinned to the seeded 2024 SSS / PhilHealth / Pag-IBIG rows and
 * the TRAIN-Law BIR semi-monthly table. Update the assertions if the seed
 * data changes (which itself should be a rare, audited event).
 */
class GovComputationServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // gov-table service caches results for 5 min — flush per test.
        $this->seed(GovernmentTableSeeder::class);
    }

    // ─── SSS ───────────────────────────────────────────────────────

    public function test_sss_low_bracket(): void
    {
        $svc = app(SssComputationService::class);
        $r = $svc->compute('4000');
        $this->assertSame('180.00', $r['ee']);
        $this->assertSame('390.00', $r['er']);
    }

    public function test_sss_mid_bracket(): void
    {
        $svc = app(SssComputationService::class);
        // 15,500 falls in 15,250–15,749.99 → EE 697.50, ER 1,482.50
        $r = $svc->compute('15500');
        $this->assertSame('697.50', $r['ee']);
        $this->assertSame('1482.50', $r['er']);
    }

    public function test_sss_at_or_above_top_bracket_caps(): void
    {
        $svc = app(SssComputationService::class);
        $r = $svc->compute('150000');
        $this->assertSame('1350.00', $r['ee']);
        $this->assertSame('2910.00', $r['er']);
    }

    public function test_sss_zero_salary_is_zero(): void
    {
        $svc = app(SssComputationService::class);
        $r = $svc->compute('0');
        $this->assertSame('0.00', $r['ee']);
        $this->assertSame('0.00', $r['er']);
    }

    // ─── PhilHealth ───────────────────────────────────────────────

    public function test_philhealth_floors_below_10k(): void
    {
        $svc = app(PhilhealthComputationService::class);
        $r = $svc->compute('8000');
        // basis = 10_000 → ee = 10000 × 0.0225 = 225
        $this->assertSame('225.00', $r['ee']);
        $this->assertSame('225.00', $r['er']);
    }

    public function test_philhealth_at_25k(): void
    {
        $svc = app(PhilhealthComputationService::class);
        $r = $svc->compute('25000');
        // 25000 × 0.0225 = 562.50
        $this->assertSame('562.50', $r['ee']);
        $this->assertSame('562.50', $r['er']);
    }

    public function test_philhealth_caps_at_100k(): void
    {
        $svc = app(PhilhealthComputationService::class);
        $r = $svc->compute('120000');
        // basis = 100,000 → ee = 100000 × 0.0225 = 2250
        $this->assertSame('2250.00', $r['ee']);
        $this->assertSame('2250.00', $r['er']);
    }

    // ─── Pag-IBIG ─────────────────────────────────────────────────

    public function test_pagibig_low_bracket(): void
    {
        $svc = app(PagibigComputationService::class);
        $r = $svc->compute('1500');
        // basis 1500 → ee = 1500 × 0.01 = 15, er = 1500 × 0.02 = 30
        $this->assertSame('15.00', $r['ee']);
        $this->assertSame('30.00', $r['er']);
    }

    public function test_pagibig_high_bracket_caps_at_10k(): void
    {
        $svc = app(PagibigComputationService::class);
        $r = $svc->compute('30000');
        // basis = 10000 → ee = 10000 × 0.02 = 200, er = 10000 × 0.02 = 200
        $this->assertSame('200.00', $r['ee']);
        $this->assertSame('200.00', $r['er']);
    }

    public function test_pagibig_mid_bracket(): void
    {
        $svc = app(PagibigComputationService::class);
        $r = $svc->compute('5000');
        // basis = 5000 → ee = 5000 × 0.02 = 100, er = 5000 × 0.02 = 100
        $this->assertSame('100.00', $r['ee']);
        $this->assertSame('100.00', $r['er']);
    }

    // ─── BIR ──────────────────────────────────────────────────────

    public function test_bir_exempt_bracket(): void
    {
        $svc = app(BirTaxComputationService::class);
        $this->assertSame('0.00', $svc->compute('10000'));
    }

    public function test_bir_15percent_bracket(): void
    {
        $svc = app(BirTaxComputationService::class);
        // 15000 - 10416 = 4584; 4584 × 0.15 = 687.60
        $this->assertSame('687.60', $svc->compute('15000'));
    }

    public function test_bir_20percent_bracket(): void
    {
        $svc = app(BirTaxComputationService::class);
        // 25000 - 16666 = 8334; 8334 × 0.20 = 1666.80; 937.50 + 1666.80 = 2604.30
        $this->assertSame('2604.30', $svc->compute('25000'));
    }

    public function test_bir_25percent_bracket(): void
    {
        $svc = app(BirTaxComputationService::class);
        // 50000 - 33332 = 16668; 16668 × 0.25 = 4167.00; 4270.83 + 4167.00 = 8437.83
        $this->assertSame('8437.83', $svc->compute('50000'));
    }

    public function test_bir_30percent_bracket(): void
    {
        $svc = app(BirTaxComputationService::class);
        // 100000 - 83332 = 16668; 16668 × 0.30 = 5000.40; 16770.83 + 5000.40 = 21771.23
        $this->assertSame('21771.23', $svc->compute('100000'));
    }

    public function test_bir_zero_taxable_is_zero(): void
    {
        $svc = app(BirTaxComputationService::class);
        $this->assertSame('0.00', $svc->compute('0'));
    }
}
