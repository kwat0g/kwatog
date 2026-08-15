<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Services\Government\BirTaxComputationService;
use App\Modules\Payroll\Services\Government\PagibigComputationService;
use App\Modules\Payroll\Services\Government\PhilhealthComputationService;
use App\Modules\Payroll\Services\Government\SssComputationService;
use Database\Seeders\GovernmentTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Reference cases for the four government deduction services.
 *
 * Values are pinned to the seeded 2024 SSS / PhilHealth / Pag-IBIG rows and
 * the effective-dated TRAIN-Law BIR semi-monthly tables. Update the
 * assertions if the seed data changes (which itself should be a rare,
 * audited event).
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
        // 2026 uses Annex E: 15000 - 10417 = 4583; × 0.15 = 687.45.
        $this->assertSame('687.45', $svc->compute('15000'));
    }

    public function test_bir_20percent_bracket(): void
    {
        $svc = app(BirTaxComputationService::class);
        // 25000 - 16667 = 8333; 8333 × 0.20 = 1666.60; 937.50 + 1666.60 = 2604.10
        $this->assertSame('2604.10', $svc->compute('25000'));
    }

    public function test_bir_25percent_bracket(): void
    {
        $svc = app(BirTaxComputationService::class);
        // 50000 - 33333 = 16667; 16667 × 0.25 = 4166.75; 4270.70 + 4166.75 = 8437.45
        $this->assertSame('8437.45', $svc->compute('50000'));
    }

    public function test_bir_30percent_bracket(): void
    {
        $svc = app(BirTaxComputationService::class);
        // 100000 - 83333 = 16667; 16667 × 0.30 = 5000.10; 16770.70 + 5000.10 = 21770.80
        $this->assertSame('21770.80', $svc->compute('100000'));
    }

    public function test_bir_effective_date_and_boundary_use_annex_d_then_annex_e(): void
    {
        $svc = app(BirTaxComputationService::class);

        // Annex D applies through 2022; Annex E starts on 2023-01-01.
        $this->assertSame(
            '1250.00',
            $svc->compute('16667', 'semi_monthly', Carbon::parse('2022-12-31')),
        );
        $this->assertSame(
            '937.50',
            $svc->compute('16667', 'semi_monthly', Carbon::parse('2023-01-01')),
        );

        // Cent-compatible boundaries are contiguous and the Annex E fixed
        // amounts are returned at the lower bound of each taxable bracket.
        $this->assertSame('0.00', $svc->compute('10416.99', 'semi_monthly', Carbon::parse('2023-06-01')));
        $this->assertSame('0.00', $svc->compute('10417.00', 'semi_monthly', Carbon::parse('2023-06-01')));
        $this->assertSame('937.50', $svc->compute('16667.00', 'semi_monthly', Carbon::parse('2023-06-01')));
        $this->assertSame('4270.70', $svc->compute('33333.00', 'semi_monthly', Carbon::parse('2023-06-01')));
        $this->assertSame('16770.70', $svc->compute('83333.00', 'semi_monthly', Carbon::parse('2023-06-01')));
        $this->assertSame('91770.70', $svc->compute('333333.00', 'semi_monthly', Carbon::parse('2023-06-01')));
    }

    public function test_bir_zero_taxable_is_zero(): void
    {
        $svc = app(BirTaxComputationService::class);
        $this->assertSame('0.00', $svc->compute('0'));
    }
}
