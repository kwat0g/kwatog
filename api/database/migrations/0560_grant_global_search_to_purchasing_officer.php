<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Buyers were the one office role without global search, so the RFQ, PO and
 * vendor search groups were unreachable for the people who use them most.
 * Every group keeps its module's own row scope; this only opens the endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'purchasing_officer')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'search.global')->value('id');
        if ($roleId !== null && $permissionId !== null) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'purchasing_officer')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'search.global')->value('id');
        if ($roleId !== null && $permissionId !== null) {
            DB::table('role_permissions')->where(['role_id' => $roleId, 'permission_id' => $permissionId])->delete();
        }
    }
};
