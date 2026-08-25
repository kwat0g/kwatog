<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M052 H-08 — production_manager is view-only on product routings.
 *
 * `RolePermissionSeeder` granted the whole `production` module to
 * production_manager, which included `production.routings.manage`. The
 * documented boundary (docs/AUTO-BROWSER-TESTS.md §1.3) gives routing
 * authorship to ppc_head and leaves production_manager read-only on planning
 * master data. Publishing a routing version re-costs the product's active BOM
 * and re-generates work instructions, so this is a write, not an oversight
 * action. The seeder now excludes it; this revokes the already-granted row
 * from deployed databases. `production.routings.view` is deliberately left in
 * place — the role has to be able to read the plan it is running.
 */
return new class extends Migration
{
    private const PERMISSION = 'production.routings.manage';
    private const ROLE = 'production_manager';

    public function up(): void
    {
        [$roleId, $permissionId] = $this->ids();
        if ($roleId === null || $permissionId === null) {
            return;
        }

        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->delete();
    }

    public function down(): void
    {
        [$roleId, $permissionId] = $this->ids();
        if ($roleId === null || $permissionId === null) {
            return;
        }

        DB::table('role_permissions')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }

    /** @return array{0: ?int, 1: ?int} */
    private function ids(): array
    {
        foreach (['roles', 'permissions', 'role_permissions'] as $table) {
            if (! Schema::hasTable($table)) {
                return [null, null];
            }
        }

        $roleId = DB::table('roles')->where('slug', self::ROLE)->value('id');
        $permissionId = DB::table('permissions')->where('slug', self::PERMISSION)->value('id');

        return [
            $roleId === null ? null : (int) $roleId,
            $permissionId === null ? null : (int) $permissionId,
        ];
    }
};
