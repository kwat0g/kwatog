<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Enums\ExportFrequency;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ExportFrequencyTest extends TestCase
{
    public function test_daily_advances_one_day(): void
    {
        $now = CarbonImmutable::create(2026, 5, 7, 8, 0, 0);
        $next = ExportFrequency::Daily->nextRunFrom($now, null, null, '06:00');
        // 06:00 today is in the past → next is tomorrow 06:00.
        $this->assertSame('2026-05-08 06:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_daily_uses_today_when_time_still_future(): void
    {
        $now = CarbonImmutable::create(2026, 5, 7, 4, 0, 0);
        $next = ExportFrequency::Daily->nextRunFrom($now, null, null, '06:00');
        $this->assertSame('2026-05-07 06:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function test_monthly_clamps_to_28_to_avoid_february_overflow(): void
    {
        $now = CarbonImmutable::create(2026, 1, 31, 12, 0, 0);
        // Asking for "31" should clamp to 28 to avoid Feb skip.
        $next = ExportFrequency::Monthly->nextRunFrom($now, null, 31, '06:00');
        $this->assertSame(28, $next->day);
    }

    public function test_weekly_lands_on_target_dow(): void
    {
        $now = CarbonImmutable::create(2026, 5, 7, 8, 0, 0); // Thursday
        $next = ExportFrequency::Weekly->nextRunFrom($now, 1 /* Monday */, null, '06:00');
        $this->assertSame(1, $next->dayOfWeek);
        $this->assertGreaterThan($now, $next);
    }
}
