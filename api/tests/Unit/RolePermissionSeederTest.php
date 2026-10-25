<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_role_reference_fails_before_any_catalog_write(): void
    {
        $permissionsBefore = Permission::query()->count();
        $rolesBefore = Role::query()->count();
        $seeder = new class extends RolePermissionSeeder
        {
            protected function roleCatalog(): array
            {
                return [
                    'broken_role' => [
                        'name' => 'Broken Role',
                        'description' => 'Test fixture',
                        'permissions' => ['missing.permission'],
                    ],
                ];
            }
        };

        try {
            $seeder->run();
            $this->fail('The invalid catalog should fail before writing.');
        } catch (\LogicException) {
            // Expected preflight failure.
        }

        $this->assertSame($permissionsBefore, Permission::query()->count());
        $this->assertSame($rolesBefore, Role::query()->count());
    }

    public function test_write_failure_rolls_back_catalog_roles_and_pivots(): void
    {
        $permissionsBefore = Permission::query()->count();
        $rolesBefore = Role::query()->count();
        $pivotsBefore = (int) DB::table('role_permissions')->count();
        $seeder = new class extends RolePermissionSeeder
        {
            protected function roleCatalog(): array
            {
                return [
                    'system_admin' => [
                        'name' => 'System Administrator',
                        'description' => 'Test fixture',
                        'permissions' => '*',
                    ],
                ];
            }

            protected function syncRolePermissions(Role $role, array $permissionIds): void
            {
                throw new \RuntimeException('Injected role sync failure.');
            }
        };

        try {
            $seeder->run();
            $this->fail('The injected write failure should abort the transaction.');
        } catch (\RuntimeException) {
            // Expected transaction rollback.
        }

        $this->assertSame($permissionsBefore, Permission::query()->count());
        $this->assertSame($rolesBefore, Role::query()->count());
        $this->assertSame($pivotsBefore, (int) DB::table('role_permissions')->count());
    }

    public function test_decision_center_permission_follows_the_decision_role_matrix(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $permissionId = Permission::query()->where('slug', 'dss.view')->value('id');

        $decisionRoles = [
            'system_admin', 'vice_president', 'ppc_head', 'sales_officer', 'purchasing_officer',
            'warehouse_staff', 'production_manager', 'qc_inspector', 'maintenance_supervisor',
            'finance_officer', 'hr_officer', 'department_head', 'customer_service_officer', 'impex_officer',
        ];
        foreach (Role::query()->get() as $role) {
            $pivot = DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->where('permission_id', $permissionId)
                ->exists();

            $this->assertSame(in_array($role->slug, $decisionRoles, true), $pivot, "Unexpected DSS access for {$role->slug}.");
        }
    }

    public function test_dss_access_migration_revokes_legacy_grants_from_non_decision_roles(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $permissionId = Permission::query()->where('slug', 'dss.view')->value('id');
        $decisionRoles = [
            'system_admin', 'vice_president', 'ppc_head', 'sales_officer', 'purchasing_officer',
            'warehouse_staff', 'production_manager', 'qc_inspector', 'maintenance_supervisor',
            'finance_officer', 'hr_officer', 'department_head', 'customer_service_officer', 'impex_officer',
        ];

        foreach (Role::query()->whereNotIn('slug', $decisionRoles)->get() as $role) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        }

        $migration = require database_path('migrations/2026_10_23_090000_restrict_dss_access_to_decision_roles.php');
        $migration->up();

        foreach (Role::query()->whereNotIn('slug', $decisionRoles)->get() as $role) {
            $this->assertDatabaseMissing('role_permissions', [
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        }
    }
}
