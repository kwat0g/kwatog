<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Common\Services\ApprovalService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveRequestService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveRequestBulkApproveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stand in for `EmployeeService::create()`, which seeds one balance row per
     * active leave type inside the employee-insert transaction.
     *
     * Submission refuses an employee/type/year with no initialized balance, so a
     * factory-built employee cannot file at all. Without this the four tests below
     * die on the balance guard before reaching the batch behaviour they measure.
     * Both this year and the next are seeded because the request dates are
     * relative to `now()` and a late-December run would cross the boundary.
     */
    private function balanceFor(Employee $employee, LeaveType $type, float $credits = 20.0): void
    {
        foreach ([(int) now()->year, (int) now()->year + 1] as $year) {
            EmployeeLeaveBalance::create([
                'employee_id'   => $employee->id,
                'leave_type_id' => $type->id,
                'year'          => $year,
                'total_credits' => $credits,
                'used'          => 0,
                'remaining'     => $credits,
            ]);
        }
    }

    public function test_bulk_approve_dept_partial_success(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
            WorkflowSeeder::class,
        ]);

        $deptHeadRole = Role::query()->where('slug', 'department_head')->firstOrFail();
        $department = Department::query()->firstOrFail();
        $approverEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $approver = User::factory()->create([
            'role_id'   => $deptHeadRole->id,
            'employee_id' => $approverEmployee->id,
            'is_active' => true,
        ]);

        $svc = app(LeaveRequestService::class);

        $emp  = Employee::factory()->create(['department_id' => $department->id]);
        $type = LeaveType::query()->first();
        $date = now()->addWeek()->toDateString();
        $this->balanceFor($emp, $type);

        // r1: properly submitted -> PendingDept with approval records.
        $r1 = $svc->submit($emp->id, [
            'start_date'    => $date,
            'end_date'      => $date,
            'leave_type_id' => $type->id,
        ]);

        // r2: wrong-state row (PendingHr) without approval records -> should fail.
        $r2 = LeaveRequest::factory()->create([
            'employee_id' => $emp->id,
            'status'      => LeaveRequestStatus::PendingHr->value,
        ]);

        $result = $svc->bulkApproveDept([$r1->id, $r2->id, 999999], $approver, 'batch ok');

        $this->assertCount(1, $result['approved'], 'failed='.json_encode($result['failed']));
        $this->assertCount(2, $result['failed']);

        $failedIds = array_column($result['failed'], 'id');
        $this->assertContains($r2->id, $failedIds);
        $this->assertContains(999999, $failedIds);

        $this->assertSame(LeaveRequestStatus::PendingHr, $r1->fresh()->status);
    }

    /**
     * `failed[].reason` is rendered into a toast verbatim by the SPA, so what
     * lands there is a user-facing contract, not a debugging detail.
     *
     * The segregation-of-duties refusal comes from `ApprovalService` as a
     * `ForbiddenActionException`, not a `BusinessRuleException`. Narrowing the
     * catch to business rules alone — done to stop a QueryException putting SQL
     * on screen — swallowed this sentence and logged an error per row for a
     * refusal the system makes on purpose. It was `abort(403, …)` at the time,
     * so no catch could name it and the repair had to infer intent from a status
     * code; `bulkFailureReason` now matches the type. `chain-leave.spec.ts`
     * asserts this same sentence reaches the user on the single-row path; the
     * bulk path must not disagree with it.
     */
    public function test_bulk_approve_surfaces_the_segregation_of_duties_refusal(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
            WorkflowSeeder::class,
        ]);

        $svc  = app(LeaveRequestService::class);
        $department = Department::query()->firstOrFail();
        $emp  = Employee::factory()->create(['department_id' => $department->id]);
        $type = LeaveType::query()->first();
        $date = now()->addWeek()->toDateString();
        $this->balanceFor($emp, $type);

        // The approver IS the requester: `LeaveRequest::approvalSubmitterId()`
        // resolves the submitter through `users.employee_id`, so linking the
        // department head to this employee is what arms the SoD guard.
        $approver = User::factory()->create([
            'role_id'     => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $emp->id,
            'is_active'   => true,
        ]);

        $own = $svc->submit($emp->id, [
            'start_date'    => $date,
            'end_date'      => $date,
            'leave_type_id' => $type->id,
        ]);

        $result = $svc->bulkApproveDept([$own->id], $approver, null);

        $this->assertCount(0, $result['approved']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame(
            'You cannot act on a record you submitted.',
            $result['failed'][0]['reason'],
            'The SoD refusal must reach the user, not be replaced by generic copy.',
        );
        // And the row is untouched — the guard refuses before the update.
        $this->assertSame(LeaveRequestStatus::PendingDept, $own->fresh()->status);
    }

    /**
     * The other half of the same contract: an exception that is NOT authored user
     * copy must not reach the screen. A `QueryException` used to put
     * `SQLSTATE[…] … insert into "approval_records" …` into the toast, table and
     * column names included.
     */
    public function test_bulk_approve_replaces_an_unexpected_exception_with_generic_copy(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
            WorkflowSeeder::class,
        ]);

        $department = Department::query()->firstOrFail();
        $emp  = Employee::factory()->create(['department_id' => $department->id]);
        $type = LeaveType::query()->first();
        $date = now()->addWeek()->toDateString();

        $svc = app(LeaveRequestService::class);
        $this->balanceFor($emp, $type);
        $req = $svc->submit($emp->id, [
            'start_date'    => $date,
            'end_date'      => $date,
            'leave_type_id' => $type->id,
        ]);

        $approverEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $approver = User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $approverEmployee->id,
            'is_active' => true,
        ]);

        // Stand in for any non-HTTP, non-business-rule failure. Binding the
        // collaborator is how we reach the residual arm without depending on a
        // particular database error to occur.
        $this->instance(ApprovalService::class, new class extends ApprovalService
        {
            public function __construct() {}

            public function approve(\Illuminate\Database\Eloquent\Model $approvable, User $user, ?string $remarks = null): void
            {
                throw new \RuntimeException(
                    'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "approval_records_pkey"',
                );
            }
        });

        $result = app(LeaveRequestService::class)->bulkApproveDept([$req->id], $approver, null);

        $this->assertCount(1, $result['failed']);
        $reason = $result['failed'][0]['reason'];
        $this->assertStringNotContainsString('SQLSTATE', $reason);
        $this->assertStringNotContainsString('approval_records', $reason);
        $this->assertSame(
            'An unexpected error stopped this request. It has been logged for support.',
            $reason,
        );
    }

    public function test_list_search_filters_by_request_no_and_employee(): void    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            LeaveTypeSeeder::class,
            WorkflowSeeder::class,
        ]);

        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);

        $emp  = Employee::factory()->create(['first_name' => 'Ana', 'last_name' => 'Dela Cruz']);
        $type = LeaveType::query()->first();
        $svc  = app(LeaveRequestService::class);
        $this->balanceFor($emp, $type);

        $r1 = $svc->submit($emp->id, [
            'start_date'    => now()->addWeek()->toDateString(),
            'end_date'      => now()->addWeek()->toDateString(),
            'leave_type_id' => $type->id,
        ]);
        $r2 = $svc->submit($emp->id, [
            'start_date'    => now()->addWeeks(2)->toDateString(),
            'end_date'      => now()->addWeeks(2)->toDateString(),
            'leave_type_id' => $type->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/leaves/requests?search=dela%20cruz')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin)
            ->getJson('/api/v1/leaves/requests?search=' . $r1->leave_request_no)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $r1->hash_id);

        $this->actingAs($admin)
            ->getJson('/api/v1/leaves/requests?search=no-such-name-xyz')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
