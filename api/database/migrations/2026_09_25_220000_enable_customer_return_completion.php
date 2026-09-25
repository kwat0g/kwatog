<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'customer_service_officer')->value('id');
        if (! $roleId) {
            return; // Fresh installs receive these grants from RolePermissionSeeder.
        }
        foreach (DB::table('permissions')->whereIn('slug', ['return_management.dispose', 'return_management.complete'])->pluck('id') as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        Cache::forget('auth:role_perms:'.$roleId);
    }

    public function down(): void
    {
        // Preserve existing/custom grants; revocation is an explicit role administration action.
    }
};
