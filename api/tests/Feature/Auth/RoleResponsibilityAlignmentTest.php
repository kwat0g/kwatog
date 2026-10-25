<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Common\Services\Export\ExportColumnRegistry;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            'Vice President' => ['vice_president',
                ['purchasing.view', 'purchasing.pr.approve', 'purchasing.po.approve', 'loans.approve', 'hr.salary_adjustments.act', 'accounting.bills.payment_approve', 'assets.dispose.approve'],
                ['purchasing.chain.view', 'purchasing.rfq.manage', 'purchasing.po.create', 'hr.employees.edit', 'payroll.periods.compute', 'accounting.bills.pay']],
            'HR Officer' => ['hr_officer',
                ['hr.employees.view_sensitive', 'attendance.edit', 'leave.approve_hr', 'payroll.periods.compute', 'admin.gov_tables.manage', 'hr.separation.view_all'],
                ['payroll.periods.approve', 'payroll.periods.request_correction', 'payroll.periods.finalize', 'accounting.journal.post', 'production.wo.record', 'hr.profile_updates.finance_review', 'loans.write_off.request', 'loans.write_off.approve']],
            'Finance Officer' => ['finance_officer',
                ['accounting.journal.post', 'payroll.periods.approve', 'payroll.periods.request_correction', 'payroll.periods.finalize', 'budgeting.approve', 'loans.approve'],
                ['payroll.periods.create', 'payroll.periods.compute', 'payroll.periods.void', 'payroll.periods.force_unlock', 'accounting.customers.manage', 'hr.employees.edit', 'production.wo.record', 'quality.inspections.manage']],
            'Sales Officer' => ['sales_officer',
                ['accounting.customers.view', 'accounting.customers.manage', 'crm.sales_orders.view', 'crm.products.customer_access.manage', 'crm.products.prices.manage',
                    'crm.price_agreements.view', 'crm.price_agreements.propose', 'crm.sales_orders.create', 'crm.sales_orders.confirm'],
                ['accounting.invoices.create', 'accounting.bills.pay', 'payroll.periods.view', 'accounting.journal.post',
                    'crm.price_agreements.manage', 'crm.price_agreements.approve',
                    'crm.complaints.view', 'crm.complaints.manage', 'crm.inquiries.view', 'crm.inquiries.manage',
                    'return_management.view', 'return_management.manage', 'inventory.view', 'return_management.receive',
                    'return_management.inspect', 'return_management.dispose', 'return_management.complete']],
            'Customer Service Officer' => ['customer_service_officer',
                ['accounting.customers.view', 'crm.products.view', 'crm.complaints.manage',
                    'crm.inquiries.view', 'crm.inquiries.manage', 'return_management.view', 'return_management.manage',
                    'return_management.dispose', 'return_management.complete'],
                ['crm.sales_orders.create', 'crm.sales_orders.update', 'crm.sales_orders.confirm', 'crm.sales_orders.cancel',
                    'accounting.customers.manage', 'crm.sales_orders.view', 'crm.products.customer_access.manage',
                    'crm.products.prices.manage', 'crm.price_agreements.view', 'crm.price_agreements.manage',
                    'crm.price_agreements.propose', 'crm.price_agreements.approve', 'return_management.receive',
                    'return_management.inspect', 'return_management.approve',
                    'inventory.view', 'accounting.credit_notes.manage']],
            'Production Manager' => ['production_manager',
                ['production.wo.record', 'production.dashboard.view', 'dashboard.plant_manager.view', 'mrp.machines.view',
                    // Chain participation: step 2 of company_loan, step 1 of
                    // salary_adjustment (2026-09-10 audit).
                    'loans.view', 'loans.approve', 'hr.salary_adjustments.view', 'hr.salary_adjustments.act'],
                ['dashboard.ppc.view', 'quality.inspections.manage', 'purchasing.po.approve', 'purchasing.pr.approve', 'attendance.edit', 'inventory.stock_count.view']],
            'PPC Head' => ['ppc_head',
                ['mrp.boms.manage', 'mrp.plans.run', 'forecasting.manage', 'production.wo.confirm'],
                ['attendance.edit', 'leave.approve_hr', 'loans.view', 'payroll.periods.view',
                    'return_management.manage', 'return_management.receive', 'return_management.inspect',
                    'return_management.dispose', 'return_management.complete']],
            'Purchasing Officer' => ['purchasing_officer',
                ['purchasing.pr.create', 'purchasing.po.create', 'purchasing.po.view_all', 'purchasing.po.responses.resolve', 'purchasing.po.send', 'inventory.view', 'supply_chain.shipments.view'],
                ['inventory.grn.create', 'supply_chain.view', 'supply_chain.fleet.manage', 'purchasing.po.sod_override', 'purchasing.po.approve', 'purchasing.pr.approve', 'accounting.bills.pay', 'production.wo.record', 'attendance.edit', 'inventory.stock_count.view']],
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
                ['supply_chain.view', 'supply_chain.shipments.manage', 'supply_chain.fleet.manage', 'purchasing.view'],
                ['purchasing.pr.create', 'purchasing.po.approve', 'accounting.view', 'hr.employees.view']],
            'Department Head' => ['department_head',
                ['attendance.ot.approve', 'leave.approve_dept', 'loans.view', 'loans.approve', 'purchasing.pr.create'],
                ['leave.approve_hr', 'loans.write_off', 'purchasing.pr.approve', 'purchasing.po.approve', 'payroll.periods.finalize', 'hr.employees.create']],
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

    public function test_all_roles_have_only_their_specific_cross_module_routes(): void
    {
        $request = \App\Modules\Purchasing\Models\PurchaseRequest::factory()->create();
        $reads = [
            '/api/v1/loans' => 'loans.view',
            '/api/v1/supply-chain/shipments' => 'supply_chain.shipments.view',
            '/api/v1/supply-chain/vehicles' => 'supply_chain.view',
            '/api/v1/hr/clearances' => 'hr.separation.view',
            '/api/v1/admin/gov-tables/options' => 'admin.gov_tables.manage',
            '/api/v1/crm/complaints' => 'crm.complaints.view',
            '/api/v1/payroll-periods' => 'payroll.periods.view',
        ];
        foreach (Role::all() as $role) {
            $user = User::factory()->create(['role_id' => $role->id]);
            foreach ($reads as $path => $permission) {
                $response = $this->actingAs($user)->getJson($path);
                ($user->hasPermission($permission) || ($permission === 'supply_chain.shipments.view' && $user->hasPermission('supply_chain.view'))) ? $response->assertOk() : $response->assertForbidden();
            }
            foreach ([
                '/api/v1/purchasing/purchase-orders' => 'purchasing.po.create',
                '/api/v1/purchasing/purchase-requests' => 'purchasing.pr.create',
                "/api/v1/purchasing/purchase-requests/{$request->hash_id}/rfqs" => 'purchasing.rfq.manage',
                '/api/v1/hr/employees' => 'hr.employees.create',
                '/api/v1/production/work-orders' => 'production.wo.create',
            ] as $path => $permission) {
                if (! $user->hasPermission($permission)) {
                    $this->actingAs($user)->postJson($path, [])->assertForbidden();
                }
            }
        }
    }

    public function test_purchasing_can_read_receipts_but_only_warehouse_can_record_receiving(): void
    {
        $buyer = User::factory()->create(['role_id' => Role::where('slug', 'purchasing_officer')->value('id')]);
        $warehouse = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $grn = GoodsReceiptNote::factory()->create();

        $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/inventory/grn')->assertOk();
        $this->getJson("/api/v1/inventory/grn/{$grn->hash_id}")->assertOk();
        $this->getJson('/api/v1/inventory/grn/options')->assertOk();
        $this->getJson('/api/v1/inventory/grn/receivable-purchase-orders')->assertForbidden();
        $this->postJson('/api/v1/inventory/grn', [])->assertForbidden();
        $this->postJson('/api/v1/inventory/receive-goods', [])->assertForbidden();
        foreach (['finalize', 'accept', 'reject', 'reject-remainder'] as $action) {
            $this->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/{$action}", [])->assertForbidden();
        }

        $this->actingAs($warehouse, 'sanctum')->getJson('/api/v1/inventory/grn/receivable-purchase-orders')->assertOk();
        $this->postJson('/api/v1/inventory/grn', [])->assertUnprocessable();
    }

    public function test_grn_role_upgrade_changes_only_the_buyer_grant_and_clears_cached_permissions(): void
    {
        $role = Role::where('slug', 'purchasing_officer')->firstOrFail();
        $permissionId = Permission::where('slug', 'inventory.grn.create')->value('id');
        $role->permissions()->syncWithoutDetaching([$permissionId]);
        $role->update(['description' => 'Custom purchasing description']);
        $buyer = User::factory()->create(['role_id' => $role->id]);
        $this->assertTrue($buyer->hasPermission('inventory.grn.create'));
        $before = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get();

        $migration = require database_path('migrations/2026_10_09_060000_restrict_purchasing_grn_receiving.php');
        $migration->up();
        $migration->up();

        $expected = $before->reject(fn ($pivot) => $pivot->role_id === $role->id && $pivot->permission_id === $permissionId)->values();
        $this->assertEquals($expected, DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get());
        $this->assertFalse($buyer->fresh()->hasPermission('inventory.grn.create'));
        $this->assertTrue($buyer->fresh()->hasPermission('inventory.view'));
        $this->assertSame('Custom purchasing description', $role->fresh()->description);

        $migration->down();
        $this->assertEquals($before, DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get());
        $this->assertTrue($buyer->fresh()->hasPermission('inventory.grn.create'));
        $this->assertSame('Custom purchasing description', $role->fresh()->description);
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
