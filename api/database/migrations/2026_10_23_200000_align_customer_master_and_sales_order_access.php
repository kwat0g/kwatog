<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DESCRIPTIONS = [
        'customer_service_officer' => [
            'Owns customer records, inquiries, complaints and return cases; reads product, price and sales-order records to support customers. Does not assign products to customers, set selling prices, propose price offers or create sales orders.',
            'Handles customer inquiries, complaints and return cases; reads customer, product and price records for support. Does not create or change customer master records or access the Sales Orders workspace.',
            'Owns customer records, inquiries, complaints and return cases; reads product, price and sales-order records to support customers. Does not assign products to customers, set selling prices, propose price offers or create sales orders.',
        ],
        'sales_officer' => [
            'Owns customer product access, selling prices, customer-specific price proposals and sales orders. Reads customer records; does not manage inquiries, complaints or return cases. Finance approves customer price offers.',
            'Owns customer master records, customer product access, selling prices, customer-specific price proposals and sales orders. Finance approves customer price offers. Does not manage inquiries, complaints or return cases.',
            'Owns customer product access, selling prices, customer-specific price proposals and sales orders. Reads customer records; does not manage inquiries, complaints or return cases. Finance approves customer price offers.',
        ],
    ];

    public function up(): void
    {
        $customerManageId = DB::table('permissions')->where('slug', 'accounting.customers.manage')->value('id');
        $salesOrdersViewId = DB::table('permissions')->where('slug', 'crm.sales_orders.view')->value('id');
        $salesRoleId = DB::table('roles')->where('slug', 'sales_officer')->value('id');
        $customerServiceRoleId = DB::table('roles')->where('slug', 'customer_service_officer')->value('id');

        if ($customerManageId !== null && $salesRoleId !== null) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $salesRoleId,
                'permission_id' => $customerManageId,
            ]);
            Cache::forget("auth:role_perms:{$salesRoleId}");
        }

        if ($customerServiceRoleId !== null) {
            DB::table('role_permissions')->where('role_id', $customerServiceRoleId)
                ->whereIn('permission_id', array_filter([$customerManageId, $salesOrdersViewId]))
                ->delete();
            Cache::forget("auth:role_perms:{$customerServiceRoleId}");
        }

        foreach (self::DESCRIPTIONS as $slug => [$previous, $current]) {
            DB::table('roles')->where('slug', $slug)->where('description', $previous)
                ->update(['description' => $current]);
        }
    }

    public function down(): void
    {
        $customerManageId = DB::table('permissions')->where('slug', 'accounting.customers.manage')->value('id');
        $salesOrdersViewId = DB::table('permissions')->where('slug', 'crm.sales_orders.view')->value('id');
        $salesRoleId = DB::table('roles')->where('slug', 'sales_officer')->value('id');
        $customerServiceRoleId = DB::table('roles')->where('slug', 'customer_service_officer')->value('id');

        if ($customerManageId !== null && $salesRoleId !== null) {
            DB::table('role_permissions')->where('role_id', $salesRoleId)->where('permission_id', $customerManageId)->delete();
            Cache::forget("auth:role_perms:{$salesRoleId}");
        }

        if ($customerServiceRoleId !== null) {
            foreach (array_filter([$customerManageId, $salesOrdersViewId]) as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $customerServiceRoleId,
                    'permission_id' => $permissionId,
                ]);
            }
            Cache::forget("auth:role_perms:{$customerServiceRoleId}");
        }

        foreach (self::DESCRIPTIONS as $slug => [$previous, $current, $rollback]) {
            DB::table('roles')->where('slug', $slug)->where('description', $current)
                ->update(['description' => $rollback]);
        }
    }
};
