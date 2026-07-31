<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\AttendanceService;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoDetectOvertimeFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function makeShift(array $overrides = []): Shift
    {
        return Shift::create(array_merge([
            'name'          => 'Test Office ' . fake()->unique()->numberBetween(1, 999999),
            'start_time'    => '08:00:00',
            'end_time'      => '17:00:00',
            'break_minutes' => 60,
            'is_night_shift'=> false,
            'is_extended'   => false,
            'auto_ot_hours' => null,
            'is_active'     => true,
        ], $overrides));
    }

    public function test_attendance_save_creates_draft_ot_when_45_min_past_shift_end(): void
    {
        $employee = Employee::factory()->create();
        $shift    = $this->makeShift();

        app(AttendanceService::class)->create([
            'employee_id' => $employee->id,
            'date'        => '2026-06-15',
            'time_in'     => '2026-06-15 08:00:00',
            'time_out'    => '2026-06-15 17:45:00',
            'shift_id'    => $shift->id,
        ]);

        $ot = OvertimeRequest::where('employee_id', $employee->id)
            ->whereDate('date', '2026-06-15')
            ->first();

        $this->assertNotNull($ot, 'Expected an auto-detected OT row.');
        $this->assertTrue((bool) $ot->is_auto_detected);
        $this->assertEqualsWithDelta(0.8, (float) $ot->hours_requested, 0.05);
        $this->assertSame('pending', $ot->status->value);
    }

    public function test_attendance_save_does_not_duplicate_when_ot_already_exists(): void
    {
        $employee = Employee::factory()->create();
        $shift    = $this->makeShift();

        // Pre-existing manual OT row for the same date.
        OvertimeRequest::factory()->create([
            'employee_id'      => $employee->id,
            'date'             => '2026-06-15',
            'is_auto_detected' => false,
        ]);

        app(AttendanceService::class)->create([
            'employee_id' => $employee->id,
            'date'        => '2026-06-15',
            'time_in'     => '2026-06-15 08:00:00',
            'time_out'    => '2026-06-15 19:00:00',
            'shift_id'    => $shift->id,
        ]);

        $count = OvertimeRequest::where('employee_id', $employee->id)
            ->whereDate('date', '2026-06-15')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_attendance_save_skips_when_below_threshold(): void
    {
        $employee = Employee::factory()->create();
        $shift    = $this->makeShift();

        app(AttendanceService::class)->create([
            'employee_id' => $employee->id,
            'date'        => '2026-06-15',
            'time_in'     => '2026-06-15 08:00:00',
            'time_out'    => '2026-06-15 17:15:00', // 15 min below default 30 threshold
            'shift_id'    => $shift->id,
        ]);

        $this->assertFalse(
            OvertimeRequest::where('employee_id', $employee->id)
                ->whereDate('date', '2026-06-15')
                ->exists()
        );
    }

    public function test_feature_flag_off_disables_detection(): void
    {
        app(\App\Common\Services\SettingsService::class)
            ->set('attendance.auto_ot_detect.enabled', false, 'attendance');
        \Illuminate\Support\Facades\Cache::forget('settings:attendance.auto_ot_detect.enabled');

        $employee = Employee::factory()->create();
        $shift    = $this->makeShift();

        app(AttendanceService::class)->create([
            'employee_id' => $employee->id,
            'date'        => '2026-06-15',
            'time_in'     => '2026-06-15 08:00:00',
            'time_out'    => '2026-06-15 19:00:00',
            'shift_id'    => $shift->id,
        ]);

        $this->assertFalse(
            OvertimeRequest::where('employee_id', $employee->id)
                ->whereDate('date', '2026-06-15')
                ->exists()
        );
    }

    public function test_unassigned_employee_uses_persisted_default_shift(): void
    {
        $employee = Employee::factory()->create();
        $default = $this->makeShift([
            'name' => 'Configured Default',
            'start_time' => '07:00:00',
            'end_time' => '15:00:00',
            'break_minutes' => 60,
            'is_default' => true,
        ]);

        $attendance = app(AttendanceService::class)->create([
            'employee_id' => $employee->id,
            'date' => '2026-06-16',
            'time_in' => '2026-06-16 07:00:00',
            'time_out' => '2026-06-16 15:00:00',
        ]);

        $this->assertSame($default->id, $attendance->shift_id);
        $this->assertSame('7.00', $attendance->regular_hours);
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'shift_id' => $default->id,
        ]);
    }

    public function test_missing_default_shift_fails_instead_of_inventing_schedule(): void
    {
        $employee = Employee::factory()->create();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('No active default shift is configured');

        app(AttendanceService::class)->create([
            'employee_id' => $employee->id,
            'date' => '2026-06-16',
            'time_in' => '2026-06-16 08:00:00',
            'time_out' => '2026-06-16 17:00:00',
        ]);
    }
}
