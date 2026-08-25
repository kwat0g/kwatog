<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaveRequestHardeningTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
            WorkflowSeeder::class,
        ]);
        $this->vacation = LeaveType::query()->where('code', 'VL')->firstOrFail();
    }

    private function user(string $role, ?Employee $employee = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'employee_id' => $employee?->id,
            'email' => 'leave-hardening-'.uniqid().'@test.invalid',
            'is_active' => true,
        ]);
    }

    private function employee(int $departmentId): Employee
    {
        return Employee::factory()->create(['department_id' => $departmentId]);
    }

    private function workDate(int $days = 14): string
    {
        $date = Carbon::now()->addDays($days)->startOfDay();
        while ($date->isSunday()) {
            $date = $date->addDay();
        }

        return $date->toDateString();
    }

    private function balance(Employee $employee, LeaveType $type, float $credits = 10.0): void
    {
        EmployeeLeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => (int) now()->year,
            'total_credits' => $credits,
            'used' => 0,
            'remaining' => $credits,
        ]);
    }

    private function submit(Employee $employee, LeaveType $type, array $extra = []): LeaveRequest
    {
        $date = $extra['start_date'] ?? $this->workDate();
        $data = array_merge([
            'leave_type_id' => $type->id,
            'start_date' => $date,
            'end_date' => $extra['end_date'] ?? $date,
        ], $extra);

        $this->balance($employee, $type);

        return app(LeaveRequestService::class)->submit($employee->id, $data);
    }

    public function test_department_decision_is_scoped_on_single_and_bulk_paths(): void
    {
        $departments = Department::query()->orderBy('id')->take(2)->get();
        $alphaHead = $this->employee($departments[0]->id);
        $alphaMember = $this->employee($departments[0]->id);
        $betaMember = $this->employee($departments[1]->id);
        $head = $this->user('department_head', $alphaHead);

        $sameDepartment = $this->submit($alphaMember, $this->vacation, [
            'start_date' => $this->workDate(),
        ]);
        $otherDepartment = $this->submit($betaMember, $this->vacation, [
            'start_date' => $this->workDate(16),
        ]);

        $this->actingAs($head)
            ->patchJson("/api/v1/leaves/requests/{$sameDepartment->hash_id}/approve-dept")
            ->assertOk();

        $this->actingAs($head)
            ->patchJson("/api/v1/leaves/requests/{$otherDepartment->hash_id}/approve-dept")
            ->assertForbidden();

        $bulk = app(LeaveRequestService::class)->bulkApproveDept(
            [$otherDepartment->id],
            $head,
        );

        $this->assertSame([], $bulk['approved']);
        $this->assertSame(
            'Department heads may only decide leave requests for their own department.',
            $bulk['failed'][0]['reason'],
        );
        $this->assertSame(LeaveRequestStatus::PendingDept, $otherDepartment->fresh()->status);
    }

    public function test_cancel_is_owner_only_but_hr_can_override_and_actor_is_recorded(): void
    {
        $department = Department::query()->firstOrFail();
        $employee = $this->employee($department->id);
        $otherEmployee = $this->employee($department->id);
        $request = $this->submit($employee, $this->vacation);

        $this->actingAs($this->user('employee', $otherEmployee))
            ->patchJson("/api/v1/leaves/requests/{$request->hash_id}/cancel")
            ->assertForbidden();
        $this->assertSame(LeaveRequestStatus::PendingDept, $request->fresh()->status);

        $hr = $this->user('hr_officer');
        $this->actingAs($hr)
            ->patchJson("/api/v1/leaves/requests/{$request->hash_id}/cancel")
            ->assertOk();

        $cancelled = $request->fresh();
        $this->assertSame(LeaveRequestStatus::Cancelled, $cancelled->status);
        $this->assertSame($hr->id, $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    public function test_approval_fails_closed_when_payroll_date_is_locked(): void
    {
        $department = Department::query()->firstOrFail();
        $employee = $this->employee($department->id);
        $head = $this->user('department_head', $this->employee($department->id));
        $hr = $this->user('hr_officer');
        $date = $this->workDate();
        $request = $this->submit($employee, $this->vacation, ['start_date' => $date]);
        $request = app(LeaveRequestService::class)->approveDept($request, $head);

        $period = PayrollPeriod::factory()->create([
            'period_start' => $date,
            'period_end' => $date,
        ]);
        $period->forceFill(['status' => PayrollPeriodStatus::Finalized->value])->save();

        try {
            app(LeaveRequestService::class)->approveHR($request, $hr);
            $this->fail('Approval should be rejected for a payroll-locked attendance date.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString(
                'Attendance for '.$date.' is locked by payroll period',
                $exception->getMessage(),
            );
        }

        $this->assertSame(LeaveRequestStatus::PendingHr, $request->fresh()->status);
        $this->assertSame('0.0', (string) EmployeeLeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $this->vacation->id)
            ->where('year', now()->year)
            ->value('used'));
    }

    public function test_half_day_approval_and_cancel_do_not_turn_an_existing_dtr_into_full_day_leave(): void
    {
        $department = Department::query()->firstOrFail();
        $employee = $this->employee($department->id);
        $head = $this->user('department_head', $this->employee($department->id));
        $hr = $this->user('hr_officer');
        $owner = $this->user('employee', $employee);
        $date = $this->workDate();

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'time_in' => $date.' 08:00:00',
            'time_out' => $date.' 17:00:00',
            'regular_hours' => '8.00',
            'overtime_hours' => '1.00',
            'night_diff_hours' => '0.00',
            'tardiness_minutes' => 0,
            'undertime_minutes' => 0,
            'day_type_rate' => '1.00',
            'status' => 'present',
            'is_manual_entry' => true,
            'remarks' => 'biometric import',
        ]);

        $request = $this->submit($employee, $this->vacation, [
            'start_date' => $date,
            'half_day_period' => 'am',
        ]);
        $request = app(LeaveRequestService::class)->approveDept($request, $head);
        $request = app(LeaveRequestService::class)->approveHR($request, $hr);

        $this->assertSame('present', $attendance->fresh()->status->value);
        $this->assertSame('8.00', (string) $attendance->fresh()->regular_hours);
        $this->assertFalse((bool) $request->fresh()->attendance_snapshot[$date]['mutated']);

        app(LeaveRequestService::class)->cancel($request, $owner);

        $this->assertSame('present', $attendance->fresh()->status->value);
        $this->assertSame('biometric import', $attendance->fresh()->remarks);
    }

    public function test_full_day_cancel_restores_the_attendance_snapshot(): void
    {
        $department = Department::query()->firstOrFail();
        $employee = $this->employee($department->id);
        $head = $this->user('department_head', $this->employee($department->id));
        $hr = $this->user('hr_officer');
        $owner = $this->user('employee', $employee);
        $date = $this->workDate();

        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'regular_hours' => '8.00',
            'overtime_hours' => '0.00',
            'night_diff_hours' => '0.00',
            'tardiness_minutes' => 0,
            'undertime_minutes' => 0,
            'day_type_rate' => '1.00',
            'status' => 'present',
            'is_manual_entry' => true,
            'remarks' => 'original DTR',
        ]);

        $request = $this->submit($employee, $this->vacation, ['start_date' => $date]);
        $request = app(LeaveRequestService::class)->approveDept($request, $head);
        $request = app(LeaveRequestService::class)->approveHR($request, $hr);

        $this->assertSame('on_leave', $attendance->fresh()->status->value);
        $this->assertSame('0.00', (string) $attendance->fresh()->regular_hours);

        app(LeaveRequestService::class)->cancel($request, $owner);

        $restored = $attendance->fresh();
        $this->assertSame('present', $restored->status->value);
        $this->assertSame('8.00', (string) $restored->regular_hours);
        $this->assertSame('original DTR', $restored->remarks);
    }

    public function test_required_documents_are_server_owned(): void
    {
        Storage::fake('local');
        $department = Department::query()->firstOrFail();
        $employee = $this->employee($department->id);
        $sick = LeaveType::query()->where('code', 'SL')->firstOrFail();
        $date = $this->workDate();
        $service = app(LeaveRequestService::class);

        try {
            $service->submit($employee->id, [
                'leave_type_id' => $sick->id,
                'start_date' => $date,
                'end_date' => $date,
            ]);
            $this->fail('A required supporting document should be enforced by the service.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('supporting document is required', $exception->getMessage());
        }

        $this->balance($employee, $sick);
        $request = $service->submit($employee->id, [
            'leave_type_id' => $sick->id,
            'start_date' => $date,
            'end_date' => $date,
            'document' => UploadedFile::fake()->create('medical-proof.pdf', 20, 'application/pdf'),
        ]);

        $this->assertNotNull($request->document_path);
        Storage::disk('local')->assertExists($request->document_path);
    }

    public function test_missing_balance_is_rejected_at_submission(): void
    {
        Storage::fake('local');
        $department = Department::query()->firstOrFail();
        $employee = $this->employee($department->id);
        $date = $this->workDate();

        try {
            app(LeaveRequestService::class)->submit($employee->id, [
                'leave_type_id' => $this->vacation->id,
                'start_date' => $date,
                'end_date' => $date,
                'document' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
            ]);
            $this->fail('Submission should fail when the annual leave balance is not initialized.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('balance is not initialized', $exception->getMessage());
        }
    }

    public function test_inactive_type_is_rejected_before_balance_lookup(): void
    {
        $department = Department::query()->firstOrFail();
        $employee = $this->employee($department->id);
        $inactive = LeaveType::create([
            'name' => 'Inactive',
            'code' => 'INACTIVE',
            'default_balance' => 5,
            'is_active' => false,
        ]);
        $this->balance($employee, $inactive);
        $service = app(LeaveRequestService::class);

        try {
            $service->submit($employee->id, [
                'leave_type_id' => $inactive->id,
                'start_date' => $this->workDate(),
                'end_date' => $this->workDate(16),
            ]);
            $this->fail('Inactive leave types should not be submittable.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('not available', $exception->getMessage());
        }
    }

    public function test_cross_year_request_is_rejected_before_balance_allocation(): void
    {
        $department = Department::query()->firstOrFail();
        $employee = $this->employee($department->id);
        $service = app(LeaveRequestService::class);

        try {
            $service->submit($employee->id, [
                'leave_type_id' => $this->vacation->id,
                'start_date' => '2026-12-31',
                'end_date' => '2027-01-02',
            ]);
            $this->fail('A leave request must not span two balance years.');
        } catch (BusinessRuleException $exception) {
            $this->assertStringContainsString('cannot span calendar years', $exception->getMessage());
        }
    }

    public function test_archived_leave_type_can_be_restored_through_the_api(): void
    {
        $admin = $this->user('system_admin');
        $archived = $this->vacation;
        $archived->delete();

        $this->actingAs($admin)
            ->patchJson("/api/v1/leaves/types/{$archived->hash_id}/restore")
            ->assertOk()
            ->assertJsonPath('data.code', $archived->code);

        $this->assertFalse($archived->fresh()->trashed());
    }
}
