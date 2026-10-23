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
            'slug' => 'crm.products.customer_access.manage',
            'name' => 'Manage Customer Product Access',
            'module' => 'crm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('slug', 'crm.products.customer_access.manage')->value('id');
        $salesRoleId = DB::table('roles')->where('slug', 'sales_officer')->value('id');
        if ($permissionId !== null && $salesRoleId !== null) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $salesRoleId,
                'permission_id' => $permissionId,
            ]);
            Cache::forget("auth:role_perms:{$salesRoleId}");
        }
    }

    public function down(): void
    {
        // Keep grants intact if administrators customized this permission after deployment.
    }
};
