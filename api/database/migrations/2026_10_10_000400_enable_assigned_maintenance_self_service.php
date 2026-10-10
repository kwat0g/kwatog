<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'slug' => 'maintenance.work.assigned',
            'name' => 'Access Assigned Maintenance Work',
            'module' => 'maintenance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleId = DB::table('roles')->where('slug', 'employee')->value('id');
        if ($roleId !== null) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => DB::table('permissions')->where('slug', 'maintenance.work.assigned')->value('id'),
            ]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'maintenance.work.assigned')->value('id');
        $roleIds = DB::table('role_permissions')->where('permission_id', $permissionId)->pluck('role_id');
        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
        foreach ($roleIds as $roleId) {
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }
};
