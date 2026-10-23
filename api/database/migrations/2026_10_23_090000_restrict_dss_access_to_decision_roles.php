<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROLE_SLUGS = [
        'vice_president', 'ppc_head', 'sales_officer', 'purchasing_officer', 'warehouse_staff',
        'production_manager', 'qc_inspector', 'maintenance_supervisor',
        'finance_officer', 'hr_officer', 'department_head', 'customer_service_officer', 'impex_officer',
    ];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'dss.view')->value('id');
        if ($permissionId === null) {
            return;
        }

        $allowedRoleIds = DB::table('roles')->whereIn('slug', self::ROLE_SLUGS)->pluck('id');
        $revokedRoleIds = DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->whereNotIn('role_id', $allowedRoleIds)
            ->pluck('role_id');
        DB::table('role_permissions')->whereIn('role_id', $revokedRoleIds)->where('permission_id', $permissionId)->delete();
        foreach ($revokedRoleIds as $roleId) {
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'dss.view')->value('id');
        if ($permissionId === null) {
            return;
        }

        // The prior migration granted dss.view to every role; rollback restores that state.
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }
};
