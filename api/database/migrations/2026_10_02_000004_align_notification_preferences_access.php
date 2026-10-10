<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'notifications.preferences.manage')->value('id');
        $readId = DB::table('permissions')->where('slug', 'notifications.view')->value('id');
        if (! $permissionId || ! $readId) return;
        $roleIds = DB::table('roles')->whereIn('slug', ['purchasing_officer', 'warehouse_staff', 'qc_inspector', 'impex_officer', 'driver'])
            ->whereIn('id', DB::table('role_permissions')->where('permission_id', $readId)->select('role_id'))->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
        // Existing per-user preference revokes stay authoritative.
    }

    public function down(): void
    {
        // This restores a documented self-service default using an existing
        // permission. Do not revoke customized role access on rollback.
    }
};
