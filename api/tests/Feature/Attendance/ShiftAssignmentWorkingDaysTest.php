<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Services\ShiftAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class ShiftAssignmentWorkingDaysTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    private function svc(): ShiftAssignmentService
    {
        return app(ShiftAssignmentService::class);
    }

    public function test_no_assignment_means_monday_to_saturday_work_and_sunday_rest(): void
    {
        [$emp] = $this->worker();

        $this->assertTrue($this->svc()->isWorkingDay($emp, CarbonImmutable::parse('2026-10-10'))); // Saturday
        $this->assertFalse($this->svc()->isWorkingDay($emp, CarbonImmutable::parse('2026-10-11'))); // Sunday
    }

    public function test_null_working_days_keeps_the_sunday_default(): void
    {
        [$emp] = $this->worker();
        $this->assignShift($emp, $this->shift(), null);

        $this->assertTrue($this->svc()->isWorkingDay($emp, CarbonImmutable::parse('2026-10-07')));
        $this->assertFalse($this->svc()->isWorkingDay($emp, CarbonImmutable::parse('2026-10-11')));
    }

    public function test_configured_working_days_are_followed(): void
    {
        [$mf] = $this->worker();
        [$st] = $this->worker();
        $shift = $this->shift();
        $this->assignShift($mf, $shift, [1, 2, 3, 4, 5]);   // Monday–Friday
        $this->assignShift($st, $shift, [7, 1, 2, 3, 4]);   // Sunday–Thursday

        $this->assertFalse($this->svc()->isWorkingDay($mf, CarbonImmutable::parse('2026-10-10'))); // Saturday
        $this->assertTrue($this->svc()->isWorkingDay($mf, CarbonImmutable::parse('2026-10-09')));  // Friday
        $this->assertTrue($this->svc()->isWorkingDay($st, CarbonImmutable::parse('2026-10-11')));  // Sunday
        $this->assertFalse($this->svc()->isWorkingDay($st, CarbonImmutable::parse('2026-10-09'))); // Friday
    }

    public function test_assignment_for_respects_the_date_range_and_current_still_returns_the_shift(): void
    {
        [$emp] = $this->worker();
        $shift = $this->shift();
        $this->assignShift($emp, $shift, null);

        $this->assertNotNull($this->svc()->assignmentFor($emp, CarbonImmutable::parse('2026-10-07')));
        $this->assertNull($this->svc()->assignmentFor($emp, CarbonImmutable::parse('2025-12-31')));
        $this->assertSame($shift->id, $this->svc()->current($emp, CarbonImmutable::parse('2026-10-07'))?->id);
    }
}
