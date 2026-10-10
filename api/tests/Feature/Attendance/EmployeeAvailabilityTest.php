<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\EmployeeAvailabilityService;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmployeeAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function worker(bool $night = false): array
    {
        $employee = Employee::factory()->create();
        $shift = Shift::create(['name' => 'Availability shift', 'start_time' => $night ? '22:00' : '08:00',
            'end_time' => $night ? '06:00' : '16:00', 'is_active' => true]);
        EmployeeShiftAssignment::create(['employee_id' => $employee->id, 'shift_id' => $shift->id,
            'effective_date' => '2026-01-01', 'working_days' => $night ? [5] : [1, 2, 3, 4, 5]]);

        return [$employee, $shift];
    }

    public function test_presence_states_and_production_response_remain_identical(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00', config('app.timezone')));
        [$employee, $shift] = $this->worker();
        $availability = app(EmployeeAvailabilityService::class);
        $production = app(WorkOrderAssignmentService::class);
        $check = function (string $state, ?string $reason) use ($availability, $production, $employee): void {
            $presence = $availability->presence($employee, now());
            $this->assertSame($state, $presence['state']);
            $this->assertSame($reason, $presence['reason_code']);
            $this->assertSame($presence, $production->presence($employee, now()));
        };
        $check('unknown', 'unverified');
        $attendance = Attendance::create(['employee_id' => $employee->id, 'shift_id' => $shift->id,
            'date' => now()->toDateString(), 'status' => 'present', 'time_in' => now()->subHour()]);
        $check('present', null);
        $employee->load('shiftAssignments.shift', 'attendances.punches');
        $check('present', null);
        $employee->unsetRelation('shiftAssignments')->unsetRelation('attendances');
        $attendance->update(['time_in' => now()->subDays(2)]);
        $check('blocked', 'shift_mismatch');
        $attendance->update(['time_in' => now()->subHour()]);
        $attendance->update(['status' => 'absent']);
        $check('blocked', 'absence');
        $attendance->update(['status' => 'present', 'time_out' => now()->subMinutes(30)]);
        $check('blocked', 'clocked_out');
        AttendancePunch::create(['employee_id' => $employee->id, 'attendance_id' => $attendance->id,
            'direction' => 'in', 'punched_at' => now()->subMinutes(10), 'accuracy_m' => 5, 'distance_m' => 0]);
        $check('present', null);
        $attendance->update(['is_manual_entry' => true]);
        $check('blocked', 'clocked_out');
        $attendance->update(['is_manual_entry' => false]);
        $attendance->delete();
        $check('blocked', 'archived');
        $attendance->restore();
        $leave = LeaveRequest::factory()->approved()->create(['employee_id' => $employee->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->toDateString()]);
        $check('blocked', 'leave');
        $leave->update(['half_day_period' => 'am', 'days' => 0.5]);
        $this->assertStringContainsString('half-day leave', $availability->coverageBlocker($employee, now(), now()->addHour()));
        $leave->delete();
        EmployeeShiftAssignment::where('employee_id', $employee->id)->update(['working_days' => null]);
        $check('blocked', 'missing_calendar');
    }

    public function test_previous_day_night_shift_covers_interval_using_its_work_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-10 02:00', config('app.timezone')));
        [$employee, $shift] = $this->worker(true);
        Attendance::create(['employee_id' => $employee->id, 'shift_id' => $shift->id,
            'date' => '2026-10-09', 'status' => 'present', 'time_in' => now()->subHours(4)]);
        Holiday::create(['name' => 'Next calendar day', 'date' => '2026-10-10', 'type' => 'regular', 'is_recurring' => false]);
        $availability = app(EmployeeAvailabilityService::class);
        $this->assertSame('present', $availability->presence($employee, now())['state']);
        $this->assertSame('2026-10-09', $availability->presence($employee, now())['work_date']);
        $this->assertNull($availability->coverageBlocker($employee, now(), now()->setTime(6, 0)));
        $this->assertNotNull($availability->coverageBlocker($employee, now(), now()->setTime(6, 1)));
        $this->assertNotNull($availability->coverageBlocker($employee, now(), now()));
        LeaveRequest::factory()->approved()->create(['employee_id' => $employee->id,
            'start_date' => '2026-10-09', 'end_date' => '2026-10-09']);
        $this->assertStringContainsString('Approved leave', $availability->coverageBlocker($employee, now(), now()->addHour()));
    }

    public function test_future_coverage_checks_attendance_without_claiming_presence(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 10:00', config('app.timezone')));
        [$employee, $shift] = $this->worker();
        $start = now()->addDay();
        $availability = app(EmployeeAvailabilityService::class);
        $this->assertNull($availability->coverageBlocker($employee, $start, $start->copy()->addHour()));
        Attendance::create(['employee_id' => $employee->id, 'shift_id' => $shift->id,
            'date' => $start->toDateString(), 'status' => 'absent']);
        $this->assertNotNull($availability->coverageBlocker($employee, $start, $start->copy()->addHour()));
    }
}
