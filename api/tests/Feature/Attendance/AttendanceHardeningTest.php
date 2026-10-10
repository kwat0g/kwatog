<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\DTRImportService;
use App\Modules\Attendance\Services\HolidayService;
use App\Modules\Attendance\Services\OvertimeService;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Attendance\Services\ShiftService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AttendanceHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_overtime_decisions_are_scoped_to_the_approvers_department(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $target = Employee::factory()->create();
        $approverEmployee = Employee::factory()->create();
        $approver = User::factory()->create([
            'employee_id' => $approverEmployee->id,
            'role_id' => Role::where('slug', 'department_head')->value('id'),
        ]);
        $ot = $this->overtime($target);

        $this->assertRule(
            fn () => app(OvertimeService::class)->approve($ot, $approver),
            'You may only decide overtime requests for your department.',
        );
        $this->assertSame(OvertimeStatus::Pending, $ot->fresh()->status);

        $reject = $this->overtime($target, '2026-04-11');
        $this->assertRule(
            fn () => app(OvertimeService::class)->reject($reject, $approver, 'Wrong department.'),
            'You may only decide overtime requests for your department.',
        );
        $this->assertSame(OvertimeStatus::Pending, $reject->fresh()->status);

        $sameDepartmentEmployee = Employee::factory()->create([
            'department_id' => $target->department_id,
            'position_id' => $target->position_id,
        ]);
        $sameDepartmentApprover = User::factory()->create([
            'employee_id' => $sameDepartmentEmployee->id,
            'role_id' => Role::where('slug', 'department_head')->value('id'),
        ]);
        $allowed = app(OvertimeService::class)->approve($this->overtime($target, '2026-04-12'), $sameDepartmentApprover);
        $this->assertSame(OvertimeStatus::Approved, $allowed->fresh()->status);

        $bulk = $this->overtime($target, '2026-04-13');
        $bulkResult = app(OvertimeService::class)->bulkApprove([$bulk->id], $approver);
        $this->assertCount(0, $bulkResult['approved']);
        $this->assertSame('You may only decide overtime requests for your department.', $bulkResult['failed'][0]['reason']);

        $cancel = $this->overtime($target, '2026-04-14');
        $this->assertRule(
            fn () => app(OvertimeService::class)->cancel($cancel, $approver),
            'You may only decide overtime requests for your department.',
        );
    }

    public function test_manual_attendance_writes_are_blocked_for_a_locked_payroll_period(): void
    {
        $employee = Employee::factory()->create();
        $this->lockedPeriod($employee, PayrollPeriodStatus::Finalized, '2026-04-10', '2026-04-15');
        $service = app(AttendanceService::class);

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-04-10',
            'remarks' => 'original',
        ]);
        $this->assertRule(
            fn () => $service->update($attendance, ['remarks' => 'changed']),
            'Attendance for 2026-04-10 is locked',
        );
        $this->assertSame('original', $attendance->fresh()->remarks);

        $this->assertRule(
            fn () => $service->delete($attendance),
            'Attendance for 2026-04-10 is locked',
        );
        $this->assertNotSoftDeleted('attendances', ['id' => $attendance->id]);

        $trashed = Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-04-11',
        ]);
        $trashed->delete();
        $this->assertRule(
            fn () => $service->restore($trashed),
            'Attendance for 2026-04-11 is locked',
        );
        $this->assertSoftDeleted('attendances', ['id' => $trashed->id]);

        $this->assertRule(
            fn () => $service->create(['employee_id' => $employee->id, 'date' => '2026-04-12']),
            'Attendance for 2026-04-12 is locked',
        );
        $this->assertDatabaseMissing('attendances', [
            'employee_id' => $employee->id,
            'date' => '2026-04-12',
        ]);
    }

    public function test_computed_payroll_inputs_stay_frozen_after_employee_transfer(): void
    {
        $employee = Employee::factory()->create();
        $period = $this->lockedPeriod($employee, PayrollPeriodStatus::Computed, '2026-04-01', '2026-04-15');
        Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);
        $newDepartment = Department::create(['name' => 'Transfer destination', 'code' => 'TRN']);
        $employee->forceFill(['department_id' => $newDepartment->id])->save();
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-04-10',
            'remarks' => 'computed source',
        ]);

        $this->assertRule(
            fn () => app(AttendanceService::class)->update($attendance, ['remarks' => 'changed after compute']),
            'Attendance for 2026-04-10 is locked by payroll period',
        );
        $this->assertSame('computed source', $attendance->fresh()->remarks);
    }

    public function test_paired_csv_import_allows_correction_in_a_voided_payroll_period(): void
    {
        $employee = Employee::factory()->create();
        $this->lockedPeriod($employee, PayrollPeriodStatus::Voided, '2026-04-20', '2026-04-25');
        $path = tempnam(sys_get_temp_dir(), 'attendance-hardening').'.csv';
        file_put_contents($path, "employee_no,date,time_in,time_out\n{$employee->employee_no},2026-04-21,08:00,17:00\n");
        $file = new UploadedFile($path, 'dtr.csv', 'text/csv', null, true);

        $result = app(DTRImportService::class)->import($file);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'date' => '2026-04-21',
        ]);
    }

    public function test_shift_assignments_close_only_the_current_range_and_reject_future_overlap(): void
    {
        $employee = Employee::factory()->create();
        $oldShift = $this->shift('Old shift');
        $newShift = $this->shift('New shift');
        $service = app(ShiftAssignmentService::class);

        $service->assignToEmployee($employee->id, $oldShift->id, '2026-05-01');
        $service->assignToEmployee($employee->id, $newShift->id, '2026-05-15');

        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employee->id,
            'shift_id' => $oldShift->id,
            'effective_date' => '2026-05-01',
            'end_date' => '2026-05-14',
        ]);
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employee->id,
            'shift_id' => $newShift->id,
            'effective_date' => '2026-05-15',
            'end_date' => null,
        ]);

        $future = Employee::factory()->create();
        $service->assignToEmployee($future->id, $oldShift->id, '2026-06-15');
        $this->assertRule(
            fn () => $service->assignToEmployee($future->id, $newShift->id, '2026-06-01', '2026-06-30'),
            'This employee already has a shift starting June 15, 2026. End or remove it before assigning another.',
        );
        $this->assertSame(1, EmployeeShiftAssignment::where('employee_id', $future->id)->count());
    }

    public function test_inactive_shift_cannot_be_assigned_to_an_employee(): void
    {
        $employee = Employee::factory()->create();
        $shift = $this->shift('Inactive shift');
        $shift->update(['is_active' => false]);

        $this->assertRule(
            fn () => app(ShiftAssignmentService::class)->assignToEmployee(
                $employee->id,
                $shift->id,
                '2026-05-01',
            ),
            'Only active shifts can be assigned.',
        );
        $this->assertDatabaseMissing('employee_shift_assignments', [
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
        ]);
    }

    public function test_shift_with_an_open_assignment_cannot_be_deactivated(): void
    {
        $employee = Employee::factory()->create();
        $shift = $this->shift('Assigned shift');
        $effectiveDate = now()->addDay()->toDateString();
        app(ShiftAssignmentService::class)->assignToEmployee($employee->id, $shift->id, $effectiveDate);

        $this->assertRule(
            fn () => app(ShiftService::class)->update($shift, ['is_active' => false]),
            'Cannot deactivate a shift with current or future employee assignments.',
        );
        $this->assertTrue((bool) $shift->fresh()->is_active);
    }

    public function test_shift_time_change_recomputes_unlocked_attendance_rows(): void
    {
        $employee = Employee::factory()->create();
        $shift = $this->shift('Recomputed shift');
        $attendance = app(AttendanceService::class)->create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'time_in' => '2026-06-15 08:00:00',
            'time_out' => '2026-06-15 16:30:00',
            'shift_id' => $shift->id,
        ]);
        $this->assertSame('7.50', (string) $attendance->regular_hours);

        app(ShiftService::class)->update($shift, ['end_time' => '16:00']);

        $this->assertSame('7.00', (string) $attendance->fresh()->regular_hours);
        $this->assertSame(0, (int) $attendance->fresh()->undertime_minutes);
    }

    public function test_holiday_creation_recomputes_unlocked_attendance_rows(): void
    {
        $employee = Employee::factory()->create();
        $shift = $this->shift('Holiday recalculation shift');
        $attendance = app(AttendanceService::class)->create([
            'employee_id' => $employee->id,
            'date' => '2030-06-17',
            'time_in' => '2030-06-17 08:00:00',
            'time_out' => '2030-06-17 17:00:00',
            'shift_id' => $shift->id,
        ]);
        $this->assertSame('1.00', (string) $attendance->day_type_rate);

        app(HolidayService::class)->create([
            'name' => 'Added after DTR import',
            'date' => '2030-06-17',
            'type' => 'regular',
            'is_recurring' => false,
        ]);

        $this->assertSame('regular', (string) $attendance->fresh()->holiday_type);
        $this->assertSame('2.00', (string) $attendance->fresh()->day_type_rate);
    }

    public function test_holidays_are_unique_by_active_date_and_shift_updates_merge_times(): void
    {
        $holidays = app(HolidayService::class);
        $holidays->create([
            'name' => 'First holiday',
            'date' => '2026-07-04',
            'type' => 'regular',
            'is_recurring' => false,
        ]);

        $this->assertRule(
            fn () => $holidays->create([
                'name' => 'Second holiday',
                'date' => '2026-07-04',
                'type' => 'special_non_working',
                'is_recurring' => false,
            ]),
            'Only one active holiday may be recorded for 2026-07-04',
        );

        $shift = $this->shift('Merged-time shift');
        $this->assertRule(
            fn () => app(ShiftService::class)->update($shift, ['end_time' => '08:00']),
            'End time cannot be the same as start time.',
        );
        $this->assertSame('17:00:00', (string) $shift->fresh()->end_time);
    }

    public function test_soft_deleted_attendance_shift_and_holiday_can_be_restored_through_the_routes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->withRole('system_admin')->create();
        $employee = Employee::factory()->create();

        $shift = $this->shift('Restore shift');
        $holiday = Holiday::create([
            'name' => 'Restore holiday',
            'date' => '2026-08-01',
            'type' => 'regular',
            'is_recurring' => false,
        ]);
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => '2026-08-01',
        ]);
        $shift->delete();
        $holiday->delete();
        $attendance->delete();

        $this->actingAs($admin)->patchJson("/api/v1/attendance/shifts/{$shift->hash_id}/restore")->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/attendance/holidays/{$holiday->hash_id}/restore")->assertOk();
        $this->actingAs($admin)->patchJson("/api/v1/attendance/attendances/{$attendance->hash_id}/restore")->assertOk();

        $this->assertNotSoftDeleted('shifts', ['id' => $shift->id]);
        $this->assertNotSoftDeleted('holidays', ['id' => $holiday->id]);
        $this->assertNotSoftDeleted('attendances', ['id' => $attendance->id]);
    }

    public function test_working_days_are_preserved_and_legacy_calendars_are_not_guessed(): void
    {
        $service = app(ShiftAssignmentService::class);
        $shift = $this->shift('Calendar shift');
        foreach ([[1, 2, 3, 4, 5], [7], [1, 2, 3, 4, 5, 6], null] as $days) {
            $employee = Employee::factory()->create();
            $service->assignToEmployee($employee->id, $shift->id, '2026-10-01', '2026-10-31', $days);
            $assignment = EmployeeShiftAssignment::where('employee_id', $employee->id)->firstOrFail();
            $this->assertSame($days, $assignment->working_days);
        }
        foreach ([[], [0], [8], [1, 1], ['1'], [1.5]] as $invalid) {
            $this->assertRule(
                fn () => $service->assignToEmployee(Employee::factory()->create()->id, $shift->id, '2026-10-01', null, $invalid),
                'Working days must contain unique ISO weekdays from 1 to 7.',
            );
        }
    }

    public function test_assignment_api_requires_calendar_and_hr_configuration_preserves_dates_and_audit(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->withRole('system_admin')->create();
        $employee = Employee::factory()->create();
        $shift = $this->shift('API calendar shift');
        $payload = ['shift_id' => $shift->hash_id, 'effective_date' => '2026-10-01'];
        $this->actingAs($admin)->postJson("/api/v1/attendance/shifts/assign-employee/{$employee->hash_id}", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('working_days');
        $this->postJson('/api/v1/attendance/shifts/bulk-assign', $payload + ['department_id' => $employee->department->hash_id])
            ->assertUnprocessable()->assertJsonValidationErrors('working_days');
        foreach ([[], [1, 1], [8], ['1']] as $invalid) {
            $this->postJson("/api/v1/attendance/shifts/assign-employee/{$employee->hash_id}", $payload + ['working_days' => $invalid])
                ->assertUnprocessable();
        }
        app(ShiftAssignmentService::class)->assignToEmployee($employee->id, $shift->id, '2026-10-01', '2026-10-31');
        $assignment = EmployeeShiftAssignment::where('employee_id', $employee->id)->firstOrFail();
        $this->getJson('/api/v1/attendance/shift-assignments')->assertOk()->assertJsonPath('data.0.working_days', null);
        $this->patchJson("/api/v1/attendance/shift-assignments/{$assignment->hash_id}/working-days", ['working_days' => [7]])
            ->assertOk()->assertJsonPath('data.working_days', [7]);
        $this->assertSame('2026-10-01', $assignment->fresh()->effective_date->toDateString());
        $this->assertSame('2026-10-31', $assignment->fresh()->end_date->toDateString());
        $audit = AuditLog::where('model_type', EmployeeShiftAssignment::class)
            ->where('model_id', $assignment->id)->where('action', 'updated')->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame([7], $audit->new_values['working_days']);
        $outsider = User::factory()->withRole('employee')->create();
        $this->actingAs($outsider)->patchJson("/api/v1/attendance/shift-assignments/{$assignment->hash_id}/working-days", ['working_days' => [1]])
            ->assertForbidden();
        $this->assertSame([7], $assignment->fresh()->working_days);
        $inactive = User::factory()->withRole('system_admin')->create(['is_active' => false]);
        $this->actingAs($inactive)->patchJson("/api/v1/attendance/shift-assignments/{$assignment->hash_id}/working-days", ['working_days' => [1]])
            ->assertUnauthorized();
        $this->assertSame([7], $assignment->fresh()->working_days);
    }

    private function overtime(Employee $employee, string $date = '2026-04-10'): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'hours_requested' => 2,
            'reason' => 'Attendance hardening test',
        ]);
    }

    private function lockedPeriod(
        Employee $employee,
        PayrollPeriodStatus $status,
        string $start,
        string $end,
    ): PayrollPeriod {
        $period = PayrollPeriod::factory()->create([
            'period_start' => $start,
            'period_end' => $end,
            'scope_department_ids' => [$employee->department_id],
            'scope_employment_types' => [$employee->employment_type->value],
            'scope_pay_types' => [$employee->pay_type->value],
        ]);
        $period->forceFill(['status' => $status->value])->save();

        return $period;
    }

    private function shift(string $name): Shift
    {
        return Shift::create([
            'name' => $name,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'break_minutes' => 60,
            'is_night_shift' => false,
            'is_extended' => false,
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    private function assertRule(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected a business rule violation containing: '.$message);
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
