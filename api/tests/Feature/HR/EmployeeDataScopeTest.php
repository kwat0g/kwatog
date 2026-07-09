<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Services\EmployeeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REC-11 — permission-driven row-level department scope on the employee list.
 *
 * Proves the cross-dept leak is closed WITHOUT relying on role-slug equality:
 * visibility is resolved from the user's grants + linked employee department.
 */
class EmployeeDataScopeTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->svc = app(EmployeeService::class);
    }

    private function dept(string $name): Department
    {
        return Department::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 3)).substr(uniqid(), -3)]);
    }

    private function employeeIn(Department $d, string $last): Employee
    {
        $pos = Position::create(['title' => 'Operator', 'department_id' => $d->id]);
        return Employee::create([
            'employee_no' => 'OGM-'.substr(uniqid(), -6),
            'first_name' => 'Test', 'last_name' => $last,
            'birth_date' => '1990-01-01', 'gender' => 'male', 'civil_status' => 'single',
            'nationality' => 'Filipino', 'street_address' => '1 St', 'city' => 'Dasma', 'province' => 'Cavite',
            'mobile_number' => '09171234567', 'email' => strtolower($last).'_'.substr(uniqid(), -4).'@x.test',
            'emergency_contact_name' => 'K', 'emergency_contact_phone' => '09181234567',
            'department_id' => $d->id, 'position_id' => $pos->id,
            'employment_type' => 'regular', 'pay_type' => 'monthly', 'date_hired' => '2025-01-01',
            'basic_monthly_salary' => '20000.00', 'status' => 'active',
        ]);
    }

    private function userFor(Employee $emp, string $roleSlug): User
    {
        return User::factory()->create([
            'role_id'     => Role::query()->where('slug', $roleSlug)->value('id'),
            'employee_id' => $emp->id,
        ]);
    }

    public function test_view_all_permission_sees_every_department(): void
    {
        $prod = $this->dept('Production');
        $fin  = $this->dept('Finance');
        $this->employeeIn($prod, 'Alpha');
        $this->employeeIn($fin, 'Bravo');

        // hr_officer holds hr.employees.view_sensitive → sees all.
        $hrEmp = $this->employeeIn($fin, 'Officer');
        $hr = $this->userFor($hrEmp, 'hr_officer');

        $result = $this->svc->list([], $hr);
        $this->assertSame(3, $result->total());
    }

    public function test_department_head_sees_only_their_department(): void
    {
        $prod = $this->dept('Production');
        $fin  = $this->dept('Finance');
        $headEmp = $this->employeeIn($prod, 'Head');
        $this->employeeIn($prod, 'Peer');      // same dept — visible
        $this->employeeIn($fin, 'Outsider');   // other dept — must NOT leak

        $head = $this->userFor($headEmp, 'department_head');

        $result = $this->svc->list([], $head);
        // Only the two Production employees are visible.
        $this->assertSame(2, $result->total());
        $depts = collect($result->items())->pluck('department_id')->unique();
        $this->assertSame([$prod->id], $depts->values()->all());
    }

    public function test_plain_employee_sees_only_themselves(): void
    {
        $prod = $this->dept('Production');
        $me = $this->employeeIn($prod, 'Me');
        $this->employeeIn($prod, 'Colleague');

        $user = $this->userFor($me, 'employee');

        $result = $this->svc->list([], $user);
        $this->assertSame(1, $result->total());
        $this->assertSame($me->id, $result->items()[0]->id);
    }

    public function test_user_with_no_department_and_no_grant_sees_nothing(): void
    {
        $prod = $this->dept('Production');
        $this->employeeIn($prod, 'Someone');

        // A user with no linked employee (no department, no self anchor).
        $orphan = User::factory()->create([
            'role_id'     => Role::query()->where('slug', 'employee')->value('id'),
            'employee_id' => null,
        ]);

        $result = $this->svc->list([], $orphan);
        // Deny-all rather than leak an unscoped list.
        $this->assertSame(0, $result->total());
    }
}
