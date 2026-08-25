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
}
