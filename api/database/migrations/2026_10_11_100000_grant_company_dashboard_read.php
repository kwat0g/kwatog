<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * `dashboard.company.view` — the company-wide read (DSS 01-matrix §B, §D4 #4).
 *
 * Why it exists: the executive overview's health strip draws KPIs owned by
 * production, quality, supply chain and accounting, and `MODULE_PERMISSIONS`
 * would otherwise admit only the holder of each module's own dashboard slug.
 * The vice president holds none of those four, so the page built for them would
 * have rendered an empty strip — 0 of its 6 tiles. Granting them the four module dashboard slugs
 * instead would widen them into four whole modules' surfaces and move their
 * landing page; this is one slug that means "may read company-wide aggregates",
 * and it appears in EVERY module's KPI set.
 *
 * Flips the decision from role-name branching to a grant: `MODULE_PERMISSIONS`
 * never mentions a role, and changing who may read company-wide numbers is a
 * `role_permissions` row, not code.
 *
 * Holders are system_admin + vice_president, decided 2026-10-11 and LOCKED by
 * `KpiVisibilityMatrixTest` so a later widening has to be deliberate.
 *
 * Written as a migration as well as a seeder edit because this project has
 * shipped grants that only existed in the seeder (see
 * 2026_10_10_000500_restore_maintenance_supervisor_visibility): a deployment
 * that re-runs migrations but not seeders would otherwise advertise the slug in
 * `RolePermissionSeeder` while nobody held it.
 *
 * Timestamp-named because `role_permissions`/`permissions` rows are also
 * written by `2026_*` migrations (same ordering caveat as CLAUDE.md records).
 */
return new class extends Migration
{
    private const SLUG = 'dashboard.company.view';

    /** Exactly these two. See the class docblock before adding a third. */
    private const ROLE_SLUGS = ['system_admin', 'vice_president'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');

        if ($permissionId === null) {
            $permissionId = (int) DB::table('permissions')->insertGetId([
                'slug' => self::SLUG,
                'name' => 'View Company Overview Dashboard',
                'module' => 'dashboards',
                'description' => 'Company-wide read: qualifies for every module KPI set and the executive overview page.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->attach($permissionId);
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', self::SLUG)->value('id');

        if ($permissionId === null) {
            return;
        }

        // Detach only. Deleting the permission row would be undone by the next
        // seed anyway (it is in the RolePermissionSeeder catalog), and a
        // rollback must not leave the slug advertised but unheld.
        $this->detach($permissionId);
    }

    private function attach(int $permissionId): void
    {
        foreach (self::ROLE_SLUGS as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    private function detach(int $permissionId): void
    {
        foreach (self::ROLE_SLUGS as $slug) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }
};
