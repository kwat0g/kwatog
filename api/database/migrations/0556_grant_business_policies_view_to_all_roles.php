<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the business_policies.view permission to all existing roles.
 *
 * The SPA's AppLayout calls GET /api/v1/business-policies on every page for
 * every role. The endpoint is gated by 'business_policies.view' permission,
 * which was added to the seeder's cross-cutting list (granted to all roles).
 * This migration backfills the permission row and grants it to every role
 * for deployed databases that lack the entries.
 */
return new class extends Migration
{
    private const PERMISSION_SLUG = 'business_policies.view';

    public function up(): void
    {
        // Idempotently create the permission row
        DB::table('permissions')->insertOrIgnore([
            'slug' => self::PERMISSION_SLUG,
            'name' => 'View Business Policies',
            'module' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Get the permission ID
        $permissionId = DB::table('permissions')
            ->where('slug', self::PERMISSION_SLUG)
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        // Grant to all existing roles (skip duplicates)
        $roleIds = DB::table('roles')->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        // Remove all grants of this permission
        $permissionId = DB::table('permissions')
            ->where('slug', self::PERMISSION_SLUG)
            ->value('id');

        if ($permissionId !== null) {
            DB::table('role_permissions')
                ->where('permission_id', $permissionId)
                ->delete();
        }

        // Remove the permission row
        DB::table('permissions')
            ->where('slug', self::PERMISSION_SLUG)
            ->delete();
    }
};
