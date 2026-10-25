<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREVIOUS_DESCRIPTION = 'Handles customer inquiries, complaints and return cases; reads customer, product and price records for support. Does not create or change customer master records or access the Sales Orders workspace.';
    private const CURRENT_DESCRIPTION = 'Handles customer inquiries, complaints and return cases; reads customer and product records for support. Does not create or change customer master records, access sales orders or view customer price agreements.';

    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'customer_service_officer')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'crm.price_agreements.view')->value('id');

        if ($roleId !== null && $permissionId !== null) {
            DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
            Cache::forget("auth:role_perms:{$roleId}");
        }

        DB::table('roles')->where('slug', 'customer_service_officer')
            ->where('description', self::PREVIOUS_DESCRIPTION)
            ->update(['description' => self::CURRENT_DESCRIPTION]);
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'customer_service_officer')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'crm.price_agreements.view')->value('id');

        if ($roleId !== null && $permissionId !== null) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
            Cache::forget("auth:role_perms:{$roleId}");
        }

        DB::table('roles')->where('slug', 'customer_service_officer')
            ->where('description', self::CURRENT_DESCRIPTION)
            ->update(['description' => self::PREVIOUS_DESCRIPTION]);
    }
};
