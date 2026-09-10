<?php

declare(strict_types=1);

namespace Tests\Unit\Payroll;

use App\Modules\Payroll\Support\EmployedDayFraction;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * HR-02 — the shared calendar-day employment fraction, exact values.
 *
 * Payroll's calculator and HR's final-pay fallback both scale a flat cutoff
 * basic by this one number; the exact fractions here are what keeps the two
 * from ever disagreeing again.
 */
class EmployedDayFractionTest extends TestCase
{
    private function fraction(
        string $periodStart,
        string $periodEnd,
        ?string $dateHired = null,
        ?string $separationDate = null,
    ): string {
        return EmployedDayFraction::of(
            Carbon::parse($periodStart),
            Carbon::parse($periodEnd),
            $dateHired !== null ? Carbon::parse($dateHired) : null,
            $separationDate !== null ? Carbon::parse($separationDate) : null,
        );
    }

    public function test_full_coverage_returns_one(): void
    {
        $this->assertSame('1.0000', $this->fraction('2026-03-01', '2026-03-15'));
    }

    public function test_mid_month_hire_is_prorated(): void
    {
        // Hired Mar 6 → 10 of 15 calendar days covered.
        $this->assertSame('0.6666', $this->fraction('2026-03-01', '2026-03-15', '2026-03-06'));
    }

    public function test_mid_month_separation_is_prorated(): void
    {
        // Separated Mar 10 → 10 of 15 calendar days covered (audit HR-02 repro).
        $this->assertSame('0.6666', $this->fraction('2026-03-01', '2026-03-15', null, '2026-03-10'));
    }

    public function test_both_ends_inside_one_cutoff_meet_in_the_middle(): void
    {
        // Hired Mar 3, separated Mar 7 → 5 of 15 days.
        $this->assertSame('0.3333', $this->fraction('2026-03-01', '2026-03-15', '2026-03-03', '2026-03-07'));
    }

    public function test_hire_on_period_start_and_separation_on_period_end_is_full(): void
    {
        $this->assertSame(
            '1.0000',
            $this->fraction('2026-03-01', '2026-03-15', '2026-03-01', '2026-03-15'),
        );
    }

    public function test_employment_before_and_after_the_cutoff_is_full(): void
    {
        $this->assertSame(
            '1.0000',
            $this->fraction('2026-03-01', '2026-03-15', '2020-01-01', '2026-12-31'),
        );
    }

    public function test_hired_after_the_cutoff_ends_owes_nothing(): void
    {
        $this->assertSame('0.0000', $this->fraction('2026-03-01', '2026-03-15', '2026-03-16'));
    }

    public function test_separated_before_the_cutoff_begins_owes_nothing(): void
    {
        $this->assertSame('0.0000', $this->fraction('2026-03-01', '2026-03-15', null, '2026-02-28'));
    }

    public function test_second_half_uses_the_real_calendar_length(): void
    {
        // Mar 16–31 is 16 days; separated Mar 20 → 5 of 16.
        $this->assertSame('0.3125', $this->fraction('2026-03-16', '2026-03-31', null, '2026-03-20'));
    }

    public function test_a_time_component_never_leaks_into_the_day_math(): void
    {
        $this->assertSame(
            '0.6666',
            EmployedDayFraction::of(
                Carbon::parse('2026-03-01 09:00'),
                Carbon::parse('2026-03-15 23:59'),
                null,
                Carbon::parse('2026-03-10 18:30'),
            ),
        );
    }
}
