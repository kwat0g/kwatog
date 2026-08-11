<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Support\WidgetScope;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WidgetScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_department_id_resolves_from_the_linked_employee(): void
    {
        $department = Department::factory()->create();
        $employee = Employee::factory()->create(['department_id' => $department->id]);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
            'employee_id' => $employee->id,
        ]);

        $this->assertSame($department->id, app(WidgetScope::class)->departmentId($user));
    }

    public function test_department_id_is_null_without_a_linked_employee(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
            'employee_id' => null,
        ]);

        $this->assertNull(app(WidgetScope::class)->departmentId($user));
    }

    /**
     * The company-wide gate must honour the system_admin short-circuit in
     * User::hasPermission — admin's cached slug array does NOT contain every
     * permission, so an in_array check here would wrongly scope admin down.
     */
    public function test_company_wide_follows_permission_including_system_admin(): void
    {
        $scope = app(WidgetScope::class);

        $admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $deptHead = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'department_head')->value('id'),
        ]);

        $this->assertTrue($scope->isCompanyWide($admin, 'loans.write_off'));
        $this->assertFalse($scope->isCompanyWide($deptHead, 'loans.write_off'));
    }
}
