<?php

declare(strict_types=1);

namespace Tests\Feature\Leave;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
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

/**
 * Row-level visibility of leave requests, pinned as a matrix.
 *
 * Written BEFORE the role-slug cleanup so the refactor has something to be
 * measured against: the four `role?->slug === 'system_admin'` branches in the
 * Leave module are provably redundant (User::hasPermission short-circuits for
 * that role, so the `leave.approve_hr` check beside each one is already true for
 * an admin), and LeaveRequestService hand-rolls the department scope that
 * DepartmentScope centralizes. Neither change may move a single row in or out of
 * anyone's list, and that is what these tests hold still.
 *
 * The matrix:
 *   system_admin      → every request
 *   leave.approve_hr  → every request
 *   leave.approve_dept→ own + own department, and nothing from another department
 *   plain employee    → only its own
 *   no linked employee→ nothing (never "everything")
 */
class LeaveRequestVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private LeaveType $type;

    private Department $alpha;

    private Department $beta;

    private Employee $alphaHead;

    private Employee $alphaMember;

    private Employee $betaMember;

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

        $this->type = LeaveType::query()->firstOrFail();

        $departments = Department::query()->orderBy('id')->take(2)->get();
        $this->alpha = $departments->first();
        $this->beta = $departments->last();
        $this->assertNotSame(
            $this->alpha->id,
            $this->beta->id,
            'the fixture needs two distinct departments to prove cross-department isolation',
        );

        $this->alphaHead = Employee::factory()->create(['department_id' => $this->alpha->id]);
        $this->alphaMember = Employee::factory()->create(['department_id' => $this->alpha->id]);
        $this->betaMember = Employee::factory()->create(['department_id' => $this->beta->id]);
    }

    private function userFor(string $roleSlug, ?Employee $employee = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'employee_id' => $employee?->id,
            'email' => 'vis+'.substr(uniqid(), -8).'@t.test',
            'is_active' => true,
        ]);
    }

    /** One request per employee, so every list is a subset of a known three. */
    private function seedOneRequestEach(): void
    {
        $day = now()->addWeek();

        foreach ([$this->alphaHead, $this->alphaMember, $this->betaMember] as $i => $employee) {
            LeaveRequest::factory()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $this->type->id,
                'start_date' => $day->copy()->addDays($i * 3)->toDateString(),
                'end_date' => $day->copy()->addDays($i * 3)->toDateString(),
            ]);
        }
    }

    /** @return array<int, int> employee ids visible to $user, sorted */
    private function visibleEmployeeIds(User $user): array
    {
        $ids = app(LeaveRequestService::class)
            ->list([], $user)
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    public function test_system_admin_sees_every_request(): void
    {
        $this->seedOneRequestEach();

        $this->assertSame(
            collect([$this->alphaHead->id, $this->alphaMember->id, $this->betaMember->id])->sort()->values()->all(),
            $this->visibleEmployeeIds($this->userFor('system_admin')),
        );
    }

    public function test_hr_sees_every_request(): void
    {
        $this->seedOneRequestEach();

        $this->assertSame(
            collect([$this->alphaHead->id, $this->alphaMember->id, $this->betaMember->id])->sort()->values()->all(),
            $this->visibleEmployeeIds($this->userFor('hr_officer')),
        );
    }

    /**
     * A department head reads its own department AND its own record, and nothing
     * from another department. This is the assertion the DepartmentScope
     * migration must not weaken.
     */
    public function test_department_head_sees_own_department_only(): void
    {
        $this->seedOneRequestEach();

        $visible = $this->visibleEmployeeIds($this->userFor('department_head', $this->alphaHead));

        $this->assertContains($this->alphaHead->id, $visible);
        $this->assertContains($this->alphaMember->id, $visible);
        $this->assertNotContains($this->betaMember->id, $visible, 'a department head read another department');
    }

    public function test_a_plain_employee_sees_only_its_own(): void
    {
        $this->seedOneRequestEach();

        $this->assertSame(
            [$this->alphaMember->id],
            $this->visibleEmployeeIds($this->userFor('employee', $this->alphaMember)),
        );
    }

    /**
     * A department head with no linked employee record has no department to
     * scope to. It must collapse to nothing, never widen to everything.
     */
    public function test_an_unlinked_department_head_sees_nothing(): void
    {
        $this->seedOneRequestEach();

        $this->assertSame([], $this->visibleEmployeeIds($this->userFor('department_head', null)));
    }

    public function test_an_unlinked_employee_sees_nothing(): void
    {
        $this->seedOneRequestEach();

        $this->assertSame([], $this->visibleEmployeeIds($this->userFor('employee', null)));
    }

    /** A null user is a console/system path and is deliberately unscoped. */
    public function test_a_null_user_is_not_scoped(): void
    {
        $this->seedOneRequestEach();

        $this->assertCount(3, app(LeaveRequestService::class)->list([], null)->items());
    }

    /* ─── Endpoint-level rules that also carry a redundant admin branch ─── */

    public function test_only_hr_or_admin_may_file_for_another_employee(): void
    {
        $payload = fn (Employee $for): array => [
            'employee_id' => $for->hash_id,
            'leave_type_id' => $this->type->hash_id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Filed by someone else',
        ];

        // A plain employee filing for a colleague is refused.
        $this->actingAs($this->userFor('employee', $this->alphaMember))
            ->postJson('/api/v1/leaves/requests', $payload($this->betaMember))
            ->assertForbidden();

        // HR may file for anyone. Deliberately unlinked: `users.employee_id` is
        // UNIQUE, and the rule under test is about the grant, not the identity.
        $this->actingAs($this->userFor('hr_officer'))
            ->postJson('/api/v1/leaves/requests', $payload($this->betaMember))
            ->assertCreated();

        // So may the administrator — today via a role-name branch, and after the
        // cleanup via the same hasPermission short-circuit HR goes through.
        $this->actingAs($this->userFor('system_admin'))
            ->postJson('/api/v1/leaves/requests', $payload($this->alphaMember))
            ->assertCreated();
    }

    public function test_balance_visibility_matches_the_leave_matrix(): void
    {
        $url = fn (Employee $e): string => "/api/v1/leaves/balances/{$e->hash_id}";

        // Admin: any employee.
        $this->actingAs($this->userFor('system_admin'))
            ->getJson($url($this->betaMember))
            ->assertOk();

        // HR: any employee.
        $this->actingAs($this->userFor('hr_officer'))
            ->getJson($url($this->betaMember))
            ->assertOk();

        // Department head: own department yes, another department no.
        $head = $this->userFor('department_head', $this->alphaHead);
        $this->actingAs($head)->getJson($url($this->alphaMember))->assertOk();
        $this->actingAs($head)->getJson($url($this->betaMember))->assertForbidden();
    }
}
