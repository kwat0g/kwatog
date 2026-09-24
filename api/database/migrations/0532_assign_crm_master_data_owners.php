<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'ppc_head' => ['crm.view', 'crm.products.view', 'crm.products.manage'],
            'customer_service_officer' => ['crm.price_agreements.manage'],
        ];

        foreach ($grants as $roleSlug => $permissionSlugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if ($roleId === null) {
                continue;
            }

            $permissionIds = DB::table('permissions')->whereIn('slug', $permissionSlugs)->pluck('id');
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $grants = [
            'ppc_head' => ['crm.view', 'crm.products.view', 'crm.products.manage'],
            'customer_service_officer' => ['crm.price_agreements.manage'],
        ];

        foreach ($grants as $roleSlug => $permissionSlugs) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
            if ($roleId === null) {
                continue;
            }

            $permissionIds = DB::table('permissions')->whereIn('slug', $permissionSlugs)->pluck('id');
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }
    }
};
