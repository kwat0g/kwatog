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
            'slug' => 'crm.products.prices.manage',
            'name' => 'Manage Product Selling Prices',
            'module' => 'crm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->insertOrIgnore([
            'slug' => 'crm.price_agreements.approve',
            'name' => 'Approve Price Proposals',
            'module' => 'crm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pricePermissionId = DB::table('permissions')->where('slug', 'crm.products.prices.manage')->value('id');
        $salesRoleId = DB::table('roles')->where('slug', 'sales_officer')->value('id');
        if ($pricePermissionId !== null && $salesRoleId !== null) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $salesRoleId, 'permission_id' => $pricePermissionId]);
            Cache::forget("auth:role_perms:{$salesRoleId}");
        }

        $approvalId = DB::table('permissions')->where('slug', 'crm.price_agreements.approve')->value('id');
        $financeRoleId = DB::table('roles')->where('slug', 'finance_officer')->value('id');
        if ($approvalId !== null && $financeRoleId !== null) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $financeRoleId, 'permission_id' => $approvalId]);
            Cache::forget("auth:role_perms:{$financeRoleId}");
        }

        $customerServiceRoleId = DB::table('roles')->where('slug', 'customer_service_officer')->value('id');
        $customerPricingPermissions = DB::table('permissions')
            ->whereIn('slug', ['crm.price_agreements.manage', 'crm.price_agreements.propose'])
            ->pluck('id');
        if ($customerServiceRoleId !== null && $customerPricingPermissions->isNotEmpty()) {
            DB::table('role_permissions')->where('role_id', $customerServiceRoleId)->whereIn('permission_id', $customerPricingPermissions)->delete();
            Cache::forget("auth:role_perms:{$customerServiceRoleId}");
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'crm.products.prices.manage')->value('id');
        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        DB::table('permissions')->insertOrIgnore([
            'slug' => 'crm.price_agreements.approve',
            'name' => 'Approve Price Proposals',
            'module' => 'crm',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approvalId = DB::table('permissions')->where('slug', 'crm.price_agreements.approve')->value('id');
        $financeRoleId = DB::table('roles')->where('slug', 'finance_officer')->value('id');
        if ($approvalId !== null && $financeRoleId !== null) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $financeRoleId, 'permission_id' => $approvalId]);
            Cache::forget("auth:role_perms:{$financeRoleId}");
        }

        $customerServiceRoleId = DB::table('roles')->where('slug', 'customer_service_officer')->value('id');
        if ($customerServiceRoleId !== null) {
            foreach (DB::table('permissions')->whereIn('slug', ['crm.price_agreements.manage', 'crm.price_agreements.propose'])->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $customerServiceRoleId, 'permission_id' => $permissionId]);
            }
            Cache::forget("auth:role_perms:{$customerServiceRoleId}");
        }
    }
};
