<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Common\Models\ApprovalDelegation;
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OvertimeApprovalBadgeParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DepartmentSeeder::class, PositionSeeder::class]);
        Cache::flush();
    }

    public function test_overtime_approval_badge_matches_actionable_filtered_list(): void
    {
        $production = Department::query()->where('code', 'PROD')->firstOrFail();
        $quality = Department::query()->where('code', 'QC')->firstOrFail();
        $approverEmployee = Employee::factory()->create(['department_id' => $production->id]);
        $approver = User::factory()->withRole('department_head')->create(['employee_id' => $approverEmployee->id]);
        $ownRequest = $this->overtime($approverEmployee);
        $inDepartment = $this->overtime(Employee::factory()->create(['department_id' => $production->id]));
        $this->overtime(Employee::factory()->create(['department_id' => $production->id]));
        $this->overtime(Employee::factory()->create(['department_id' => $quality->id]));
        $this->overtime(Employee::factory()->create(['department_id' => $production->id]), OvertimeStatus::Approved);
        $this->overtime(Employee::factory()->create(['department_id' => $production->id]), OvertimeStatus::Rejected);

        $this->assertNotSame('', $ownRequest->hash_id);
        $this->assertNotSame('', $inDepartment->hash_id);
        $this->assertSame(2, $this->assertBadgeMatchesList($approver));
    }

    public function test_delegate_gets_only_the_delegated_department_action_queue(): void
    {
        $production = Department::query()->where('code', 'PROD')->firstOrFail();
        $quality = Department::query()->where('code', 'QC')->firstOrFail();
        $delegatorEmployee = Employee::factory()->create(['department_id' => $production->id]);
        $delegator = User::factory()->withRole('department_head')->create(['employee_id' => $delegatorEmployee->id]);
        $delegateEmployee = Employee::factory()->create(['department_id' => $production->id]);
        $delegate = $this->userWithPermissions('Overtime Delegate', ['attendance.view'], $delegateEmployee->id);

        ApprovalDelegation::create([
            'delegator_user_id' => $delegator->id,
            'delegate_user_id' => $delegate->id,
            'role_slug' => 'department_head',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->overtime($delegatorEmployee);
        $this->overtime(Employee::factory()->create(['department_id' => $production->id]));
        $this->overtime(Employee::factory()->create(['department_id' => $quality->id]));

        $this->assertTrue(ApprovalDelegation::canActForPermission($delegate->fresh(), 'attendance.ot.approve'));
        $this->assertSame(2, $this->assertBadgeMatchesList($delegate->fresh()));
    }

    public function test_view_only_user_cannot_load_the_actionable_signal(): void
    {
        $production = Department::query()->where('code', 'PROD')->firstOrFail();
        $employee = Employee::factory()->create(['department_id' => $production->id]);
        $viewer = $this->userWithPermissions('Overtime Viewer', ['attendance.view'], $employee->id);
        $this->overtime(Employee::factory()->create(['department_id' => $production->id]));

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/attendance/overtime-requests?signal=awaiting_my_approval')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->assertArrayNotHasKey('overtime', $this->badges($viewer));
    }

    private function assertBadgeMatchesList(User $user): int
    {
        $badgeCount = $this->badges($user)['overtime']['count'];
        $listCount = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/attendance/overtime-requests?signal=awaiting_my_approval')
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

    private function overtime(Employee $employee, OvertimeStatus $status = OvertimeStatus::Pending): OvertimeRequest
    {
        $request = OvertimeRequest::factory()->create([
            'employee_id' => $employee->id,
        ]);
        $request->status = $status;
        $request->save();

        return $request;
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(string $name, array $permissions, int $employeeId): User
    {
        $role = Role::create(['name' => $name, 'slug' => str($name)->slug('_')->value()]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id, 'employee_id' => $employeeId]);
    }
}
