<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = ['hr.employees.reset_password', 'hr.employees.deactivate_account'];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'hr_officer')->value('id');
        if ($roleId === null) {
            return;
        }

        DB::table('role_permissions')->where('role_id', $roleId)
            ->whereIn('permission_id', DB::table('permissions')->whereIn('slug', self::PERMISSIONS)->select('id'))
            ->delete();
        Cache::forget("auth:role_perms:{$roleId}");
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'hr_officer')->value('id');
        if ($roleId === null) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('slug', self::PERMISSIONS)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        Cache::forget("auth:role_perms:{$roleId}");
    }
};
