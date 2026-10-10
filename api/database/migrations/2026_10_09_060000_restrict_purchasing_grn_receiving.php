<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BEFORE = 'Runs purchase requests, orders, RFQs, supplier selection and performance; receives purchased goods, tracks inbound shipments and supplier returns, and reads bills, vendors and outbound delivery status; financial approvals follow the designated workflow.';

    private const AFTER = 'Runs purchase requests, orders, RFQs, supplier selection and performance; tracks purchased goods receipts, inbound shipments and supplier returns, and reads bills, vendors and outbound delivery status; financial approvals follow the designated workflow.';

    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'purchasing_officer')->value('id');
        if ($roleId === null) {
            return;
        }

        DB::table('role_permissions')->where('role_id', $roleId)
            ->whereIn('permission_id', DB::table('permissions')->where('slug', 'inventory.grn.create')->select('id'))
            ->delete();
        DB::table('roles')->where('id', $roleId)->where('description', self::BEFORE)
            ->update(['description' => self::AFTER]);
        Cache::forget("auth:role_perms:{$roleId}");
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'purchasing_officer')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'inventory.grn.create')->value('id');
        if ($roleId === null || $permissionId === null) {
            return;
        }

        DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        DB::table('roles')->where('id', $roleId)->where('description', self::AFTER)
            ->update(['description' => self::BEFORE]);
        Cache::forget("auth:role_perms:{$roleId}");
    }
};
