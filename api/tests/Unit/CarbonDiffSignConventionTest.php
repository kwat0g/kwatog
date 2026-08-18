<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pins Carbon's diff sign convention, because a whole class of bugs depends on it.
 *
 * Carbon 2 returned diffIn* as an ABSOLUTE magnitude. Carbon 3 (nesbot/carbon
 * 3.x, pulled in by Laravel 12) returns it SIGNED, as `argument - receiver`, and
 * as a float rather than an int. Upgrading therefore silently inverted every
 * comparison written as `$later->diffInX($earlier) < $threshold`, because the
 * left-hand side became negative and the comparison became permanently true.
 *
 * Four such sites were found and fixed:
 *
 *   PunchSessionizer      an employee's whole punch file collapsed into one day
 *   MrpEngineService      every MRP work order came out urgent
 *   DowntimeAnalytics     MTBF pinned at 0, availability pinned at null
 *   AlertEngineService    the critical AR-overdue alert could never fire
 *
 * None of them threw. They produced quietly wrong business output, which is why
 * they survived a 1,839-test suite.
 *
 * A static guard was prototyped and deliberately rejected: whether a given call
 * is safe depends on which operand is the earlier instant, which is semantic
 * rather than syntactic. Scanning for an inline comparison flags five sites in
 * this codebase and all five are correct, and the broader "no bare diffIn*" rule
 * covers 44 sites. A regex cannot make the judgement, so this test pins the
 * ASSUMPTION instead: if a future upgrade changes the convention again, this
 * fails first and names the sites that rely on it.
 */
class CarbonDiffSignConventionTest extends TestCase
{
    private const EARLIER = '2026-08-01 00:00:00';

    private const LATER = '2026-08-11 00:00:00';

    public function test_diff_is_positive_when_the_argument_is_later_than_the_receiver(): void
    {
        $earlier = Carbon::parse(self::EARLIER);
        $later = Carbon::parse(self::LATER);

        // This is the safe direction, and the one every fixed site now uses.
        $this->assertSame(10.0, $earlier->diffInDays($later));
    }

    public function test_diff_is_negative_when_the_argument_is_earlier_than_the_receiver(): void
    {
        $earlier = Carbon::parse(self::EARLIER);
        $later = Carbon::parse(self::LATER);

        // This is the trap. Under Carbon 2 this returned +10.0.
        $this->assertSame(-10.0, $later->diffInDays($earlier));
    }

    public function test_a_signed_diff_defeats_a_naive_upper_bound_comparison(): void
    {
        // The exact shape of the PunchSessionizer defect: a session boundary that
        // can never be crossed, because the left-hand side is always negative.
        $firstIn = Carbon::parse(self::EARLIER);
        $tenDaysLater = Carbon::parse(self::LATER);

        $this->assertTrue(
            $tenDaysLater->diffInHours($firstIn) < 18,
            'ten days apart still satisfies "< 18 hours" when the diff is signed'
        );

        $this->assertFalse(
            abs($tenDaysLater->diffInHours($firstIn)) < 18,
            'abs() is what makes the bound mean what it reads as'
        );
    }

    public function test_diff_returns_a_float_not_an_int(): void
    {
        // Callers that store the result in an int column or compare with
        // assertSame must cast. Carbon 2 returned int.
        $this->assertIsFloat(Carbon::parse(self::EARLIER)->diffInDays(Carbon::parse(self::LATER)));
    }
}
