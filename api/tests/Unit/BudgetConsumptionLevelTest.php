<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Accounting\Support\BudgetConsumptionLevel;
use PHPUnit\Framework\TestCase;

/**
 * Tranche B / D1 — consumption classification must compare money to money.
 *
 * The defect this replaces rounded consumption to one decimal and compared the
 * percentage against a ratio, so everything from 99.95% up became 100.0 and
 * classified `exhausted`. Every fixture below uses adversarial cent values:
 * round thousands are exactly where float and decimal agree and so prove nothing.
 */
class BudgetConsumptionLevelTest extends TestCase
{
    /** @var array{warning: float, critical: float, exhausted: float, overdrawn: float} */
    private const RATIOS = [
        'warning' => 0.8,
        'critical' => 0.95,
        'exhausted' => 1.0,
        'overdrawn' => 1.2,
    ];

    private function classify(string $consumedAfter, string $allocated): string
    {
        return BudgetConsumptionLevel::classify($consumedAfter, $allocated, self::RATIOS);
    }

    public function test_the_99_95_percent_band_is_critical_not_exhausted(): void
    {
        // The regression case. 999,500.00 of 1,000,000.00 is 99.95% consumed:
        // over the 95% critical threshold, under the 100% exhausted threshold.
        $this->assertSame('critical', $this->classify('999500.00', '1000000.00'));
    }

    public function test_exactly_one_hundred_percent_is_exhausted(): void
    {
        // Guards against over-correcting: spending the budget to the last
        // centavo must still require Finance acknowledgment.
        $this->assertSame('exhausted', $this->classify('1000000.00', '1000000.00'));
    }

    public function test_one_centavo_below_the_ceiling_is_critical(): void
    {
        $this->assertSame('critical', $this->classify('999999.99', '1000000.00'));
    }

    public function test_one_centavo_above_the_ceiling_is_exhausted(): void
    {
        $this->assertSame('exhausted', $this->classify('1000000.01', '1000000.00'));
    }

    public function test_unchanged_bands_still_classify_as_before(): void
    {
        $this->assertSame('ok', $this->classify('799999.99', '1000000.00'));
        $this->assertSame('warning', $this->classify('800000.00', '1000000.00'));
        $this->assertSame('critical', $this->classify('950000.00', '1000000.00'));
        $this->assertSame('overdrawn', $this->classify('1200000.00', '1000000.00'));
    }

    public function test_zero_allocation_is_ok_and_never_divides(): void
    {
        $this->assertSame('ok', $this->classify('0.00', '0.00'));
        $this->assertSame('ok', $this->classify('500.00', '0.00'));
    }

    public function test_cent_values_that_a_float_ratio_would_misplace(): void
    {
        // 0.1 + 0.2 !== 0.3 in binary floating point. These allocations and
        // amounts are chosen so a float pathway lands on the wrong side.
        $this->assertSame('critical', $this->classify('0.29', '0.30'));
        $this->assertSame('exhausted', $this->classify('0.30', '0.30'));
    }
}
