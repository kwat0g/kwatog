<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use Illuminate\Support\Carbon;

/**
 * Weekday-deterministic dates for Leave fixtures.
 *
 * `LeaveRequestService::submit()` refuses a full-day range whose business-day
 * total is zero (`LeaveRequestService.php:204-208`, commit a2deee8c), and
 * `businessDaysInclusive()` excludes Sunday. A fixture built as
 * `now()->addWeek()` preserves today's weekday, so on a Sunday every such
 * fixture lands on a Sunday and is refused — seven tests across three files
 * failed that way on 2026-08-30 with no behavioural change behind them.
 *
 * `workDate()` is the fix, and it is not new: it was already private to
 * LeaveRequestHardeningTest, which is why that file's ten tests were the only
 * ones unaffected. Lifting it into a trait gives the module one definition
 * instead of a copy per file.
 */
trait BusinessDayFixtures
{
    /**
     * A date $days out from now, moved forward off Sunday so a full-day range
     * starting there always contains at least one business day.
     */
    protected function workDate(int $days = 14): string
    {
        $date = Carbon::now()->addDays($days)->startOfDay();
        while ($date->isSunday()) {
            $date = $date->addDay();
        }

        return $date->toDateString();
    }
}
