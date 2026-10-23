<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'ppc_head')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'crm.sales_orders.view')->value('id');

        if ($roleId !== null && $permissionId !== null) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'ppc_head')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'crm.sales_orders.view')->value('id');

        if ($roleId !== null && $permissionId !== null) {
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }
};
