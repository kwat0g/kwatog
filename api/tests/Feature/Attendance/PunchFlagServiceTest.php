<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Services\PunchFlagService;
use App\Modules\Attendance\Services\PunchService;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchFlagServiceTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        $this->shift(); // 06:00–14:00
    }

    private function punch(Employee $emp, float $accuracy = 20.0, string $ua = 'phpunit'): AttendancePunch
    {
        return app(PunchService::class)->punch($emp, 14.2860, 120.9345, $accuracy, '127.0.0.1', $ua, null);
    }

    /** @return array<int, list<string>> */
    private function flagsFor(Employee $emp): array
    {
        $a = Attendance::query()->where('employee_id', $emp->id)->orderByDesc('date')->firstOrFail();
        $punches = AttendancePunch::query()->where('attendance_id', $a->id)->get();

        return app(PunchFlagService::class)->for($a, $punches);
    }

    public function test_a_normal_day_has_no_flags(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 14:00:00');
        $this->punch($emp);

        $this->assertSame([], $this->flagsFor($emp));
    }

    public function test_the_same_browser_at_the_same_spot_for_two_employees_is_flagged(): void
    {
        [$a] = $this->worker();
        [$b] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($a);
        $this->at('2026-10-07 06:01:00');
        $this->punch($b);

        $this->assertContains(PunchFlagService::SHARED_DEVICE, array_merge(...array_values($this->flagsFor($b))));
    }

    public function test_a_different_browser_is_not_a_shared_device(): void
    {
        [$a] = $this->worker();
        [$b] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($a, 20.0, 'phone-a');
        $this->at('2026-10-07 06:01:00');
        $this->punch($b, 20.0, 'phone-b');

        $this->assertSame([], $this->flagsFor($b));
    }

    public function test_the_same_exact_spot_on_many_days_is_flagged(): void
    {
        [$emp] = $this->worker();
        foreach (['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06', '2026-10-07'] as $day) {
            $this->at("{$day} 06:00:00");
            $this->punch($emp);
            $this->at("{$day} 14:00:00");
            $this->punch($emp);
        }

        $this->assertContains(PunchFlagService::REPEATED_LOCATION, array_merge(...array_values($this->flagsFor($emp))));
    }

    public function test_a_too_perfect_accuracy_is_flagged(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp, 0.0);

        $this->assertContains(PunchFlagService::IMPLAUSIBLE_ACCURACY, array_merge(...array_values($this->flagsFor($emp))));
    }

    public function test_staying_past_shift_end_without_approved_overtime_is_flagged_and_approval_clears_it(): void
    {
        [$emp] = $this->worker();
        $this->at('2026-10-07 06:00:00');
        $this->punch($emp);
        $this->at('2026-10-07 15:30:00');
        $out = $this->punch($emp);

        $this->assertSame(PunchDirection::Out, $out->direction);
        $this->assertSame([PunchFlagService::UNAPPROVED_OVERTIME], $this->flagsFor($emp)[$out->id]);

        $this->approveOvertime($emp, '2026-10-07');
        $this->assertSame([], $this->flagsFor($emp));
    }
}
