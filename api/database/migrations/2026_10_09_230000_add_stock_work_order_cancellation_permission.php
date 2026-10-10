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
            'slug' => 'production.wo.cancel_stock', 'name' => 'Cancel Unstarted Stock Work Order',
            'module' => 'production', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('slug', 'production.wo.cancel_stock')->value('id');
        foreach (DB::table('roles')->where('slug', 'ppc_head')->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    public function down(): void
    {
        // Preserve grants that may predate this migration or have been customized.
    }
};
