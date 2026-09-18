<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Services\UserAdminService;
use App\Modules\Admin\Support\CreatedUser;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accounts are created FOR an employee record (employee-based creation).
 * `createForEmployee` replaced the old standalone path: identity comes from
 * the HR record, eligibility (no account yet, regular/probationary, not
 * separated) is re-checked under lock inside the transaction.
 */
class UserAdminCreateForEmployeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_created_user_dto_with_user_and_temp_password(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $svc = app(UserAdminService::class);
        $roleId = (int) Role::where('slug', 'finance_officer')->value('id');
        $employee = Employee::factory()->create([
            'email' => 'cli-caller@t.test',
        ]);

        $result = $svc->createForEmployee([
            'employee_id' => $employee->id,
            'email' => null,
            'role_id' => $roleId,
        ]);

        $this->assertInstanceOf(CreatedUser::class, $result);
        $this->assertSame('cli-caller@t.test', $result->user->email);
        $this->assertSame($employee->id, (int) $result->user->employee_id);
        $this->assertSame($employee->full_name, $result->user->name);
        $this->assertNotEmpty($result->tempPassword);
        $this->assertGreaterThanOrEqual(8, mb_strlen($result->tempPassword));
    }

    public function test_typed_email_wins_over_employee_record(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $svc = app(UserAdminService::class);
        $roleId = (int) Role::where('slug', 'finance_officer')->value('id');
        $employee = Employee::factory()->create([
            'email' => 'hr-record@t.test',
        ]);

        $result = $svc->createForEmployee([
            'employee_id' => $employee->id,
            'email' => 'typed@t.test',
            'role_id' => $roleId,
        ]);

        $this->assertSame('typed@t.test', $result->user->email);
    }

    public function test_generates_email_when_employee_has_none(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $svc = app(UserAdminService::class);
        $roleId = (int) Role::where('slug', 'finance_officer')->value('id');
        $employee = Employee::factory()->create([
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'email' => null,
        ]);

        $result = $svc->createForEmployee([
            'employee_id' => $employee->id,
            'email' => null,
            'role_id' => $roleId,
        ]);

        $this->assertSame('juan.cruz@ogami.ph', $result->user->email);
    }

    public function test_works_outside_http_context(): void
    {
        // Simulate Artisan/queued-job: createForEmployee must NOT depend on
        // request()->attributes to return the temp password.
        $this->seed(RolePermissionSeeder::class);

        $svc = app(UserAdminService::class);
        $roleId = (int) Role::where('slug', 'finance_officer')->value('id');
        $employee = Employee::factory()->create([
            'email' => 'queued-caller@t.test',
        ]);

        $result = $svc->createForEmployee([
            'employee_id' => $employee->id,
            'email' => null,
            'role_id' => $roleId,
            'temp_password' => 'KnownTempPwd1!',
        ]);

        // Temp passwords are generated, never caller-supplied, on the
        // employee-based path.
        $this->assertNotSame('KnownTempPwd1!', $result->tempPassword);
        $this->assertSame('queued-caller@t.test', $result->user->email);
    }

    public function test_rejects_employee_that_already_has_an_account(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $svc = app(UserAdminService::class);
        $roleId = (int) Role::where('slug', 'finance_officer')->value('id');
        $employee = Employee::factory()->create(['email' => 'taken@t.test']);
        User::factory()->create([
            'role_id' => Role::where('slug', 'employee')->value('id'),
            'employee_id' => $employee->id,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $svc->createForEmployee([
            'employee_id' => $employee->id,
            'email' => null,
            'role_id' => $roleId,
        ]);
    }

    public function test_rejects_terminated_employee(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $svc = app(UserAdminService::class);
        $roleId = (int) Role::where('slug', 'finance_officer')->value('id');
        $employee = Employee::factory()->create([
            'email' => 'terminated@t.test',
            'status' => 'terminated',
        ]);

        $this->expectException(\RuntimeException::class);
        $svc->createForEmployee([
            'employee_id' => $employee->id,
            'email' => null,
            'role_id' => $roleId,
        ]);
    }

    public function test_candidates_exclude_employees_with_accounts_and_ineligible_types(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $department = Department::factory()->create();

        $withAccount = Employee::factory()->create(['last_name' => 'Aaa', 'email' => 'with-account@t.test', 'department_id' => $department->id]);
        User::factory()->create([
            'role_id' => Role::where('slug', 'employee')->value('id'),
            'employee_id' => $withAccount->id,
        ]);
        Employee::factory()->create(['last_name' => 'Bbb', 'employment_type' => 'contractual', 'department_id' => $department->id]);
        $eligible = Employee::factory()->create(['last_name' => 'Ccc', 'email' => 'eligible@t.test', 'department_id' => $department->id]);

        $candidates = app(UserAdminService::class)->employeeCandidates(departmentHash: $department->hash_id);

        $ids = array_column($candidates, 'id');
        $this->assertContains($eligible->hash_id, $ids);
        $this->assertNotContains($withAccount->hash_id, $ids);
        $this->assertNotContains(
            Employee::where('last_name', 'Bbb')->firstOrFail()->hash_id,
            $ids,
        );
    }

    public function test_candidates_search_covers_employee_no_last_and_first_name(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $department = Department::factory()->create();

        $byNo = Employee::factory()->create(['employee_no' => 'OGM-777001', 'first_name' => 'Maria', 'last_name' => 'Santos', 'department_id' => $department->id]);
        $byLast = Employee::factory()->create(['first_name' => 'Ana', 'last_name' => 'Reyes', 'department_id' => $department->id]);
        $byFirst = Employee::factory()->create(['first_name' => 'Carlos', 'last_name' => 'Dela Cruz', 'department_id' => $department->id]);

        $svc = app(UserAdminService::class);
        $hash = $department->hash_id;

        $this->assertContains($byNo->hash_id, array_column($svc->employeeCandidates('777001', $hash), 'id'));
        $this->assertContains($byLast->hash_id, array_column($svc->employeeCandidates('reyes', $hash), 'id'));
        $this->assertContains($byFirst->hash_id, array_column($svc->employeeCandidates('carlos', $hash), 'id'));
    }

    public function test_candidates_without_department_returns_nothing(): void
    {
        // Department-first flow: no department selected means no company-wide
        // fallback — an absent or unknown department yields an empty list.
        $this->seed(RolePermissionSeeder::class);

        Employee::factory()->create(['last_name' => 'Zzz']);

        $this->assertSame([], app(UserAdminService::class)->employeeCandidates());
        $this->assertSame([], app(UserAdminService::class)->employeeCandidates(departmentHash: 'bogus-hash'));
    }

    public function test_candidates_only_include_the_selected_department(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $deptA = Department::factory()->create();
        $deptB = Department::factory()->create();

        $inA = Employee::factory()->create(['last_name' => 'Aaa', 'department_id' => $deptA->id]);
        Employee::factory()->create(['last_name' => 'Bbb', 'department_id' => $deptB->id]);

        $ids = array_column(
            app(UserAdminService::class)->employeeCandidates(departmentHash: $deptA->hash_id),
            'id',
        );

        $this->assertContains($inA->hash_id, $ids);
        $this->assertNotContains(
            Employee::where('last_name', 'Bbb')->firstOrFail()->hash_id,
            $ids,
        );
    }

    public function test_endpoint_requires_department_and_scopes_results(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'email' => 'candidates-admin+'.uniqid().'@t.test',
        ]);

        $department = Department::factory()->create();
        $inDept = Employee::factory()->create(['last_name' => 'InDept', 'department_id' => $department->id]);
        $otherDept = Department::factory()->create();
        Employee::factory()->create(['last_name' => 'Elsewhere', 'department_id' => $otherDept->id]);

        // Missing department_id → validation error, not a company-wide list.
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/users/employee-candidates')
            ->assertStatus(422);

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/users/employee-candidates?department_id={$department->hash_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inDept->hash_id);

        // Search narrows WITHIN the selected department only.
        $this->actingAs($admin)
            ->getJson("/api/v1/admin/users/employee-candidates?department_id={$department->hash_id}&search=InDept")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inDept->hash_id);

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/users/employee-candidates?department_id={$department->hash_id}&search=Elsewhere")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
