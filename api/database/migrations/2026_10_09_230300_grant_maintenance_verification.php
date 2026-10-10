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
            'slug' => 'maintenance.wo.verify', 'name' => 'Verify Maintenance Repair',
            'module' => 'maintenance', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('slug', 'maintenance.wo.verify')->value('id');
        foreach (DB::table('roles')->whereIn('slug', ['production_manager', 'maintenance_supervisor'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
        DB::table('settings')->insertOrIgnore([
            'key' => 'maintenance.closed_loop.enabled', 'value' => json_encode(false),
            'group' => 'maintenance', 'label' => 'Verified Maintenance Recovery',
            'description' => 'Require independent recovery verification for newly created maintenance orders.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve operational activation and grants on application rollback.
    }
};
