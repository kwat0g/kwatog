<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Attendance\Services\OvertimeService;
use Tests\TestCase;

class AutoDetectOvertimeTest extends TestCase
{
    private function svc(): OvertimeService
    {
        return app(OvertimeService::class);
    }

    public function test_extra_minutes_zero_when_punched_out_at_shift_end(): void
    {
        $minutes = $this->svc()->extraMinutesPastShiftEnd(
            shiftEnd: '2026-06-13 17:00:00',
            timeOut:  '2026-06-13 17:00:00',
        );
        $this->assertSame(0, $minutes);
    }

    public function test_extra_minutes_clamped_to_zero_when_left_early(): void
    {
        $minutes = $this->svc()->extraMinutesPastShiftEnd(
            shiftEnd: '2026-06-13 17:00:00',
            timeOut:  '2026-06-13 16:30:00',
        );
        $this->assertSame(0, $minutes);
    }

    public function test_extra_minutes_45_when_45_min_past(): void
    {
        $minutes = $this->svc()->extraMinutesPastShiftEnd(
            shiftEnd: '2026-06-13 17:00:00',
            timeOut:  '2026-06-13 17:45:00',
        );
        $this->assertSame(45, $minutes);
    }

    public function test_extra_minutes_handles_cross_midnight(): void
    {
        // Night shift ending 06:00 next day; out at 07:30 -> 90 min extra.
        $minutes = $this->svc()->extraMinutesPastShiftEnd(
            shiftEnd: '2026-06-14 06:00:00',
            timeOut:  '2026-06-14 07:30:00',
        );
        $this->assertSame(90, $minutes);
    }
}
