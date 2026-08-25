<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['mrp.plans.view', 'mrp.runs.view'];

    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'production_manager')->value('id');
        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('slug', self::PERMISSIONS)->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'production_manager')->value('id');
        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('slug', self::PERMISSIONS)->pluck('id');
        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }
};
