<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Common\Services\Export\ExportColumnRegistry;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleResponsibilityAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public static function responsibilityBoundaries(): array
    {
        return [
            'HR Officer' => ['hr_officer',
                ['hr.employees.view_sensitive', 'attendance.edit', 'leave.approve_hr', 'payroll.periods.compute'],
                ['payroll.periods.finalize', 'accounting.journal.post', 'production.wo.record']],
            'Finance Officer' => ['finance_officer',
                ['accounting.journal.post', 'payroll.periods.finalize', 'budgeting.approve', 'loans.approve'],
                ['hr.employees.edit', 'production.wo.record', 'quality.inspections.manage']],
            'Production Manager' => ['production_manager',
                ['production.wo.record', 'production.dashboard.view', 'dashboard.plant_manager.view', 'mrp.machines.view'],
                ['dashboard.ppc.view', 'quality.inspections.manage', 'purchasing.po.approve', 'attendance.edit', 'inventory.stock_count.view']],
            'PPC Head' => ['ppc_head',
                ['mrp.boms.manage', 'mrp.plans.run', 'forecasting.manage', 'production.wo.confirm'],
                ['attendance.edit', 'leave.approve_hr', 'loans.view', 'payroll.periods.view']],
            'Purchasing Officer' => ['purchasing_officer',
                ['purchasing.pr.create', 'purchasing.po.approve', 'purchasing.po.send', 'inventory.grn.create'],
                ['purchasing.po.sod_override', 'accounting.bills.pay', 'production.wo.record', 'attendance.edit', 'inventory.stock_count.view']],
            'Warehouse Staff' => ['warehouse_staff',
                ['inventory.grn.create', 'inventory.issue.create', 'inventory.stock_count.manage', 'inventory.picking.view'],
                ['purchasing.po.approve', 'accounting.view', 'quality.inspections.manage', 'payroll.periods.view']],
            'QC Inspector' => ['qc_inspector',
                ['quality.inspections.manage', 'quality.ncr.manage', 'inventory.mrb.manage'],
                ['production.wo.record', 'purchasing.po.approve', 'accounting.view', 'hr.employees.view', 'inventory.stock_count.view']],
            'Maintenance Technician' => ['maintenance_tech',
                ['maintenance.wo.create', 'maintenance.wo.complete', 'assets.view'],
                ['maintenance.wo.assign', 'maintenance.schedules.manage', 'production.wo.record', 'quality.view']],
            'ImpEx Officer' => ['impex_officer',
                ['supply_chain.view', 'supply_chain.shipments.manage', 'purchasing.view'],
                ['purchasing.pr.create', 'purchasing.po.approve', 'accounting.view', 'hr.employees.view']],
            'Department Head' => ['department_head',
                ['attendance.ot.approve', 'leave.approve_dept', 'loans.view', 'loans.approve', 'purchasing.pr.approve'],
                ['leave.approve_hr', 'loans.write_off', 'purchasing.po.approve', 'payroll.periods.finalize', 'hr.employees.create']],
            'Employee' => ['employee',
                ['attendance.view', 'leave.view', 'leave.create', 'payroll.view'],
                ['attendance.ot.create', 'loans.view', 'payroll.periods.view', 'hr.employees.view']],
            'Driver' => ['driver',
                ['supply_chain.driver.access', 'attendance.view', 'leave.view', 'payroll.view'],
                ['supply_chain.view', 'inventory.view', 'loans.view', 'hr.employees.view']],
        ];
    }

    #[DataProvider('responsibilityBoundaries')]
    public function test_seeded_roles_match_their_key_responsibilities(
        string $roleSlug,
        array $required,
        array $forbidden,
    ): void {
        $role = Role::where('slug', $roleSlug)->with('permissions')->firstOrFail();
        $permissions = $role->permissions->pluck('slug')->all();

        foreach ($required as $permission) {
            $this->assertContains($permission, $permissions, "$roleSlug is missing required permission $permission");
        }
        foreach ($forbidden as $permission) {
            $this->assertNotContains($permission, $permissions, "$roleSlug is over-granted $permission");
        }
    }

    public function test_system_admin_has_the_complete_permission_catalog(): void
    {
        $admin = Role::where('slug', 'system_admin')->firstOrFail();

        $this->assertSame(
            Permission::count(),
            $admin->permissions()->count(),
        );
    }

    public function test_ppc_keeps_self_service_but_cannot_open_back_office_hr_surfaces(): void
    {
        $role = Role::where('slug', 'ppc_head')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance/attendances')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/leaves/requests')->assertOk();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/payrolls')->assertOk();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance/shifts')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/attendance/holidays')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/leaves/calendar')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/loans')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/payroll-periods')->assertForbidden();

        // Register future-facing export modules so the test proves their
        // permission mapping instead of stopping at the registry's 404 guard.
        ExportColumnRegistry::register('payroll.register', ['employee' => ['label' => 'Employee']]);
        ExportColumnRegistry::register('payroll.gov.sss_r3', ['employee' => ['label' => 'Employee']]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/exports/payroll.register/columns')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/exports/payroll.gov.sss_r3/columns')->assertForbidden();
    }

    public function test_self_service_permissions_never_allow_cross_employee_actions(): void
    {
        $role = Role::where('slug', 'employee')->firstOrFail();
        $ownEmployee = Employee::factory()->create();
        $otherEmployee = Employee::factory()->create();
        $user = User::factory()->create([
            'role_id' => $role->id,
            'employee_id' => $ownEmployee->id,
        ]);

        $otherOvertime = OvertimeRequest::factory()->create(['employee_id' => $otherEmployee->id]);
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/attendance/overtime-requests/{$otherOvertime->hash_id}")
            ->assertForbidden();

        $leaveFixture = LeaveRequest::factory()->create(['employee_id' => $otherEmployee->id]);
        $leaveType = LeaveType::findOrFail($leaveFixture->leave_type_id);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/leaves/requests', [
            'employee_id' => $otherEmployee->hash_id,
            'leave_type_id' => $leaveType->hash_id,
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(21)->toDateString(),
            'reason' => 'Attempted cross-employee filing',
        ])->assertForbidden();
    }
}
