<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Common\Exceptions\BusinessRuleException;
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
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
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

    public function test_paired_csv_import_uses_the_same_payroll_write_fence(): void
    {
        $employee = Employee::factory()->create();
        $this->lockedPeriod($employee, PayrollPeriodStatus::Voided, '2026-04-20', '2026-04-25');
        $path = tempnam(sys_get_temp_dir(), 'attendance-hardening').'.csv';
        file_put_contents($path, "employee_no,date,time_in,time_out\n{$employee->employee_no},2026-04-21,08:00,17:00\n");
        $file = new UploadedFile($path, 'dtr.csv', 'text/csv', null, true);

        $result = app(DTRImportService::class)->import($file);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('Attendance for 2026-04-21 is locked', $result['errors'][0]['message']);
        $this->assertDatabaseMissing('attendances', [
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
            'overlaps an existing future assignment',
        );
        $this->assertSame(1, EmployeeShiftAssignment::where('employee_id', $future->id)->count());
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

    private function overtime(Employee $employee, string $date = '2026-04-10'): OvertimeRequest
    {
        return OvertimeRequest::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'hours_requested' => 2,
            'reason' => 'Attendance hardening test',
            'status' => OvertimeStatus::Pending->value,
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
