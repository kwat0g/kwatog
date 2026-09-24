<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Add crm.complaints.view permission and grant to quality, CSR, and executive roles. */
return new class extends Migration {
    public function up(): void
    {
        $permId = DB::table('permissions')->where('slug', 'crm.complaints.view')->value('id');
        if (! $permId) {
            $permId = DB::table('permissions')->insertGetId([
                'slug' => 'crm.complaints.view',
                'name' => 'View Complaints',
                'module' => 'crm',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roles = DB::table('roles')
            ->whereIn('slug', [
                'customer_service_officer',
                'qc_inspector',
                'production_manager',
                'vice_president',
                'system_admin',
            ])
            ->pluck('id');

        foreach ($roles as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
        }
    }

    public function down(): void
    {
        $permId = DB::table('permissions')->where('slug', 'crm.complaints.view')->value('id');
        if ($permId) {
            DB::table('role_permissions')->where('permission_id', $permId)->delete();
            DB::table('permissions')->where('id', $permId)->delete();
        }
    }
};
