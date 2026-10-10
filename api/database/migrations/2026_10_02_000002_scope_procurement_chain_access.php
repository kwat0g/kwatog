<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_DESCRIPTION = 'Reviews, approves, finalizes, and disburses payroll; manages accounting ledgers.';

    private const DESCRIPTION = 'Manages accounting, budgets, loans and fixed assets; reviews and disburses payroll; approves purchases, financial returns, stock adjustments, bank changes and Finance clearance; reviews supplier quotations without running sourcing.';

    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'slug' => 'purchasing.chain.view', 'name' => 'View Company Procurement Chain',
            'module' => 'purchasing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('slug', 'purchasing.chain.view')->value('id');
        $legacyId = DB::table('permissions')->where('slug', 'purchasing.view')->value('id');
        $operatorIds = DB::table('roles')->whereIn('slug', ['system_admin', 'purchasing_officer'])
            ->whereIn('id', DB::table('role_permissions')->where('permission_id', $legacyId)->select('role_id'))
            ->pluck('id');
        foreach ($operatorIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
        // A user's existing restriction must also cover the extracted read.
        foreach (DB::table('user_permission_overrides')->where('permission_id', $legacyId)
            ->where('type', 'revoke')->whereIn('user_id', DB::table('users')->whereIn('role_id', $operatorIds)->select('id'))->get() as $override) {
            DB::table('user_permission_overrides')->insertOrIgnore([
                'user_id' => $override->user_id, 'permission_id' => $permissionId, 'type' => 'revoke',
                'granted_by' => $override->granted_by, 'reason' => $override->reason,
                'expires_at' => $override->expires_at, 'deleted_at' => $override->deleted_at,
                'created_at' => $override->created_at, 'updated_at' => $override->updated_at,
            ]);
        }
        DB::table('permissions')->where('slug', 'purchasing.view')->where('name', 'View Purchasing')
            ->update(['name' => 'View Purchase Requests and Orders']);
        DB::table('roles')->where('slug', 'finance_officer')->where('description', self::OLD_DESCRIPTION)
            ->update(['description' => self::DESCRIPTION]);
        DB::table('dashboard_widgets')->where('key', 'purchasing.supplier_perf')->where('permission', 'purchasing.view')
            ->update(['permission' => 'purchasing.suppliers.performance.view']);
        DB::table('dashboard_widgets')->where('key', 'purchasing.supplier_perf')->where('link_path', '/purchasing/approved-suppliers')
            ->update(['link_path' => '/purchasing/suppliers/performance']);
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'purchasing.chain.view')->value('id');
        foreach (DB::table('role_permissions')->where('permission_id', $permissionId)->pluck('role_id') as $roleId) {
            Cache::forget("auth:role_perms:{$roleId}");
        }
        DB::table('permissions')->where('slug', 'purchasing.chain.view')->delete();
        DB::table('permissions')->where('slug', 'purchasing.view')->where('name', 'View Purchase Requests and Orders')
            ->update(['name' => 'View Purchasing']);
        DB::table('roles')->where('slug', 'finance_officer')->where('description', self::DESCRIPTION)
            ->update(['description' => self::OLD_DESCRIPTION]);
        DB::table('dashboard_widgets')->where('key', 'purchasing.supplier_perf')->where('permission', 'purchasing.suppliers.performance.view')
            ->update(['permission' => 'purchasing.view']);
        DB::table('dashboard_widgets')->where('key', 'purchasing.supplier_perf')->where('link_path', '/purchasing/suppliers/performance')
            ->update(['link_path' => '/purchasing/approved-suppliers']);
    }
};
