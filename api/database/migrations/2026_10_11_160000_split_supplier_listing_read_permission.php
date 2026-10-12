<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->insertGetId([
            'slug' => 'purchasing.supplier_listings.view',
            'name' => 'View Supplier Item Listings',
            'module' => 'purchasing',
            'description' => 'Read supplier item listing review queues without granting decision authority.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reviewPermissionId = DB::table('permissions')
            ->where('slug', 'purchasing.supplier_listings.review')
            ->value('id');

        if ($reviewPermissionId !== null) {
            $grants = DB::table('role_permissions')->where('permission_id', $reviewPermissionId)->get(['role_id']);
            foreach ($grants as $grant) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $grant->role_id,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'purchasing.supplier_listings.view')->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
