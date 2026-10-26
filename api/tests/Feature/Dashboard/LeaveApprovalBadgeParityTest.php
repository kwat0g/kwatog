<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\ApprovalService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaveApprovalBadgeParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DepartmentSeeder::class, PositionSeeder::class, WorkflowSeeder::class]);
        Cache::flush();
    }

    public function test_department_head_badge_matches_only_approvable_rows_in_filtered_list(): void
    {
        $department = Department::query()->where('code', 'PROD')->firstOrFail();
        $otherDepartment = Department::query()->where('code', 'QC')->firstOrFail();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $approver = User::factory()->withRole('department_head')->create(['employee_id' => $employee->id]);

        $this->leave($employee, LeaveRequestStatus::PendingDept);
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::PendingDept);
        $this->leave(Employee::factory()->create(['department_id' => $otherDepartment->id]), LeaveRequestStatus::PendingDept);
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::PendingHr);
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::PendingDept, false);
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::PendingDept)->delete();
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::Approved);
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::Rejected);

        $this->assertSame(1, $this->assertBadgeMatchesList($approver));
    }

    public function test_hr_badge_excludes_own_request_and_matches_its_stage_only(): void
    {
        $department = Department::query()->where('code', 'PROD')->firstOrFail();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $hr = User::factory()->withRole('hr_officer')->create(['employee_id' => $employee->id]);

        $this->leave($employee, LeaveRequestStatus::PendingHr);
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::PendingHr);
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::PendingDept);

        $this->assertSame(1, $this->assertBadgeMatchesList($hr));
    }

    public function test_permission_holder_without_workflow_role_gets_no_actionable_count(): void
    {
        $department = Department::query()->where('code', 'PROD')->firstOrFail();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $role = Role::create(['name' => 'Scoped Leave Approver', 'slug' => 'test_scoped_leave_approver']);
        $role->permissions()->sync(Permission::query()
            ->whereIn('slug', ['leave.view', 'leave.approve_dept'])
            ->pluck('id'));
        $approver = User::factory()->create(['role_id' => $role->id, 'employee_id' => $employee->id]);
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::PendingDept);

        $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/leaves/requests?signal=awaiting_my_approval')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->assertSame(0, $this->badges($approver)['leaves']['count']);
    }

    public function test_approver_without_leave_read_permission_gets_no_badge(): void
    {
        $department = Department::query()->where('code', 'PROD')->firstOrFail();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $approver = User::factory()->withRole('department_head')->create(['employee_id' => $employee->id]);
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::PendingDept);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $approver->id,
            'permission_id' => Permission::query()->where('slug', 'leave.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $approver->id,
            'reason' => 'Leave badge read permission regression test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approver = $approver->fresh();

        $this->actingAs($approver, 'sanctum')
            ->getJson('/api/v1/leaves/requests?signal=awaiting_my_approval')
            ->assertForbidden();
        $this->assertArrayNotHasKey('leaves', $this->badges($approver));
    }

    public function test_active_department_head_delegate_matches_the_filtered_queue(): void
    {
        $department = Department::query()->where('code', 'PROD')->firstOrFail();
        $delegator = User::factory()->withRole('department_head')->create([
            'employee_id' => Employee::factory()->create(['department_id' => $department->id])->id,
        ]);
        $delegateEmployee = Employee::factory()->create(['department_id' => $department->id]);
        $role = Role::create(['name' => 'Delegated Leave Approver', 'slug' => 'test_delegated_leave_approver']);
        $role->permissions()->sync(Permission::query()
            ->whereIn('slug', ['leave.view', 'leave.approve_dept'])
            ->pluck('id'));
        $delegate = User::factory()->create(['role_id' => $role->id, 'employee_id' => $delegateEmployee->id]);
        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'department_head',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);
        $this->leave(Employee::factory()->create(['department_id' => $department->id]), LeaveRequestStatus::PendingDept);

        $this->assertSame(1, $this->assertBadgeMatchesList($delegate));
    }

    private function assertBadgeMatchesList(User $user): int
    {
        $badgeCount = $this->badges($user)['leaves']['count'];
        $listCount = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/leaves/requests?signal=awaiting_my_approval&status=approved&trashed=with')
            ->assertOk()
            ->json('meta.total');

        $this->assertSame($badgeCount, $listCount);

        return $badgeCount;
    }

    /** @return array<string, mixed> */
    private function badges(User $user): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/dashboards/badges')
            ->assertOk()
            ->json('data');
    }

    private function leave(Employee $employee, LeaveRequestStatus $status, bool $withApprovalStep = true): LeaveRequest
    {
        $request = LeaveRequest::factory()->create(['employee_id' => $employee->id]);
        $request->forceFill(['status' => $status->value])->save();
        if ($withApprovalStep) {
            app(ApprovalService::class)->submit($request, 'leave_request');
            if ($status === LeaveRequestStatus::PendingHr) {
                $request->approvalRecords()->where('step_order', 1)->firstOrFail()->update(['action' => 'skipped']);
            }
        }

        return $request;
    }
}
