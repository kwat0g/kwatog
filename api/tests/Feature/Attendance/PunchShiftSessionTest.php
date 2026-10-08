<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Services\SettingsService;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Exceptions\PunchRejectedException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Services\PunchService;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Attendance\Concerns\PunchFixtures;
use Tests\TestCase;

class PunchShiftSessionTest extends TestCase
{
    use PunchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plant();
        app(SettingsService::class)->set('attendance.punch_early_out_grace_minutes', 0);
    }

    private function punch($employee)
    {
        return app(PunchService::class)->punch($employee, 14.2860, 120.9345, 20.0, '127.0.0.1', 'phpunit');
    }

    private function assertRejected(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected PunchRejectedException [{$code}]");
        } catch (PunchRejectedException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    public function test_open_night_session_uses_its_stored_shift_after_assignment_rotates(): void
    {
        $night = $this->shift('22:00:00', '06:00:00', true);
        $day = $this->shift('08:00:00', '16:00:00');
        [$employee] = $this->worker();
        $assignments = app(ShiftAssignmentService::class);
        $assignments->assignToEmployee($employee->id, $night->id, '2026-10-06');
        $assignments->assignToEmployee($employee->id, $day->id, '2026-10-07');

        $this->at('2026-10-06 22:00:00');
        $in = $this->punch($employee);
        $row = Attendance::query()->findOrFail($in->attendance_id);
        $this->assertSame($night->id, $row->shift_id);

        $this->at('2026-10-07 06:00:00');
        $status = app(PunchService::class)->status($employee);
        $this->assertSame('out', $status['next_action']);
        $this->assertSame($night->name, $status['shift']['name']);
        $this->assertSame('2026-10-07 06:00', \Carbon\CarbonImmutable::parse($status['earliest_out'])->format('Y-m-d H:i'));

        $out = $this->punch($employee);
        $this->assertSame(PunchDirection::Out, $out->direction);
        $this->assertSame($row->id, $out->attendance_id);
        $this->assertSame('2026-10-06', $row->fresh()->date->toDateString());
        $this->assertSame('2026-10-07 06:00', $row->fresh()->time_out->format('Y-m-d H:i'));
    }

    public function test_midnight_uses_previous_workday_window_and_enforces_earliest_out(): void
    {
        $night = $this->shift('22:00:00', '06:00:00', true);
        $day = $this->shift('08:00:00', '16:00:00');
        [$employee] = $this->worker();
        $assignments = app(ShiftAssignmentService::class);
        $assignments->assignToEmployee($employee->id, $night->id, '2026-10-06');
        $assignments->assignToEmployee($employee->id, $day->id, '2026-10-07');

        $this->at('2026-10-06 22:00:00');
        $this->punch($employee);
        $this->at('2026-10-07 00:00:00');
        $status = app(PunchService::class)->status($employee);
        $this->assertSame($night->name, $status['shift']['name']);
        $this->assertSame('2026-10-07 06:00', \Carbon\CarbonImmutable::parse($status['earliest_out'])->format('Y-m-d H:i'));
        $this->assertRejected('too_early_out', fn () => $this->punch($employee));
        $this->assertSame(1, AttendancePunch::query()->where('employee_id', $employee->id)->count());
    }

    public function test_inactive_stored_shift_cannot_be_replaced_by_new_assignment(): void
    {
        $this->assertUnusableStoredShiftRejected(false);
    }

    public function test_archived_stored_shift_cannot_be_replaced_by_new_assignment(): void
    {
        $this->assertUnusableStoredShiftRejected(true);
    }

    private function assertUnusableStoredShiftRejected(bool $archive): void
    {
        $night = $this->shift('22:00:00', '06:00:00', true);
        $day = $this->shift('08:00:00', '16:00:00');
        [$employee] = $this->worker();
        $assignments = app(ShiftAssignmentService::class);
        $assignments->assignToEmployee($employee->id, $night->id, '2026-10-06');
        $assignments->assignToEmployee($employee->id, $day->id, '2026-10-07');
        $this->at('2026-10-06 22:00:00');
        $this->punch($employee);

        if ($archive) {
            $night->delete();
        } else {
            $night->update(['is_active' => false]);
        }

        $this->at('2026-10-07 06:00:00');
        $status = app(PunchService::class)->status($employee);
        $this->assertSame('out', $status['next_action']);
        $this->assertNull($status['shift']);
        $this->assertNull($status['earliest_out']);
        $this->assertRejected('no_shift', fn () => $this->punch($employee));
        $this->assertSame(1, AttendancePunch::query()->where('employee_id', $employee->id)->count());
    }

    public function test_missing_open_attendance_cannot_attach_out_to_a_new_day(): void
    {
        $this->shift('22:00:00', '06:00:00', true);
        [$employee] = $this->worker();
        $this->at('2026-10-06 22:00:00');
        $in = $this->punch($employee);
        AttendancePunch::query()->whereKey($in->id)->update(['attendance_id' => null]);

        $this->at('2026-10-07 06:00:00');
        $status = app(PunchService::class)->status($employee);
        $this->assertNull($status['shift']);
        $this->assertNull($status['earliest_out']);
        $this->assertRejected('row_removed', fn () => $this->punch($employee));
        $this->assertSame(1, AttendancePunch::query()->where('employee_id', $employee->id)->count());
    }

    public function test_open_session_row_guards_still_reject_manual_leave_and_archived_days(): void
    {
        $this->shift('22:00:00', '06:00:00', true);
        [$manual] = $this->worker();
        [$leave] = $this->worker();
        [$archived] = $this->worker();
        $this->at('2026-10-06 22:00:00');
        $manualIn = $this->punch($manual);
        $leaveIn = $this->punch($leave);
        $archivedIn = $this->punch($archived);
        Attendance::query()->findOrFail($manualIn->attendance_id)->update(['is_manual_entry' => true]);
        Attendance::query()->findOrFail($leaveIn->attendance_id)->update(['status' => AttendanceStatus::OnLeave]);
        Attendance::query()->findOrFail($archivedIn->attendance_id)->delete();

        $this->at('2026-10-07 06:00:00');
        $this->assertRejected('manual_entry', fn () => $this->punch($manual));
        $this->assertRejected('on_leave', fn () => $this->punch($leave));
        $this->assertRejected('row_removed', fn () => $this->punch($archived));
        $this->assertSame(3, AttendancePunch::query()->count());
    }

    public function test_stale_night_session_starts_new_day_and_normal_day_punches_still_work(): void
    {
        $night = $this->shift('22:00:00', '06:00:00', true);
        $day = $this->shift('08:00:00', '16:00:00');
        [$employee] = $this->worker();
        $assignments = app(ShiftAssignmentService::class);
        $assignments->assignToEmployee($employee->id, $night->id, '2026-10-06');
        $assignments->assignToEmployee($employee->id, $day->id, '2026-10-07');

        $this->at('2026-10-06 22:00:00');
        $this->punch($employee);
        $this->at('2026-10-07 08:00:00');
        $this->assertSame($night->name, app(PunchService::class)->status($employee)['shift']['name']);
        $this->at('2026-10-07 16:00:01');
        $this->assertSame('in', app(PunchService::class)->status($employee)['next_action']);
        $this->at('2026-10-08 08:00:00');
        $in = $this->punch($employee);
        $this->assertSame(PunchDirection::In, $in->direction);
        $this->assertSame($day->id, Attendance::query()->findOrFail($in->attendance_id)->shift_id);
        $this->at('2026-10-08 16:00:00');
        $out = $this->punch($employee);
        $this->assertSame(PunchDirection::Out, $out->direction);
        $this->assertSame($in->attendance_id, $out->attendance_id);
        $this->assertSame(2, Attendance::query()->where('employee_id', $employee->id)->count());
    }
}
