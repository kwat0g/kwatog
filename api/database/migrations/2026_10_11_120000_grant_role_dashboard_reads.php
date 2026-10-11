<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The five remaining role dashboards from DSS 01-matrix §D3 / M-p1–M-p3, each
 * as a page permission plus the grant that makes it reachable.
 *
 * Each of these roles has had NO `dashboard.*.view` slug, so `DashboardDispatchService`
 * fell through to `/dashboard/default` — the generic widget grid. That is a
 * landing page, not a surface: it carries no cards sized to what the role
 * decides, and three of the five (logistics, maintenance, department) also
 * needed a *page* before a KPI or widget could be read anywhere at all.
 *
 *   dashboard.logistics.view        → impex_officer          /dashboard/logistics
 *   dashboard.maintenance.view      → maintenance_tech + maintenance_supervisor
 *   dashboard.department.view       → department_head
 *   dashboard.sales.view            → sales_officer
 *   dashboard.customer_service.view → customer_service_officer
 *
 * `maintenance` is the one shared page: two roles, one permission, two
 * permission-derived views (M-p2 R12/M-p3 R13). It deliberately does NOT reuse
 * `maintenance.work.assigned` as its gate — that is the mobile work-list slug
 * and `maintenance_tech` does not hold it, so the page would be invisible to one
 * of the two roles it exists for (the M6 trap).
 *
 * `dashboard.logistics.view` is ALSO added to the supply_chain KPI gate set in
 * `KpiSnapshotService::MODULE_PERMISSIONS`. That is G-P3-2's documented
 * resolution — "a second qualified gate", not a role-name branch: OTD is a
 * supply-chain reading and the officer who owns deliveries had no way to read
 * it. The gate stays a slug set, so who may read it is still a grant.
 *
 * Dispatch is rarity-ranked, so these grants are an ACCESS-MODEL change and were
 * checked against every seeded role's landing before shipping: each of the five
 * roles holds exactly one dashboard slug, and the only other holder of any of
 * them is the `system_admin` wildcard — whose landing stays `/dashboard/admin`
 * because `admin` still sorts ahead of every other one-holder key.
 *
 * A migration as well as a seeder edit: a deployment that re-runs migrations but
 * not seeders would otherwise advertise these slugs in `RolePermissionSeeder`
 * while nobody held them (the 2026_10_10_000500 lesson).
 *
 * Timestamp-named because `permissions`/`role_permissions` rows are also written
 * by `2026_*` migrations — see the ordering rule in CLAUDE.md.
 */
return new class extends Migration
{
    /**
     * slug => [display name, role slugs that hold it].
     *
     * Adding a role here is an access decision: it moves that role's landing
     * page (or adds a candidate to it) the moment the row lands.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const GRANTS = [
        'dashboard.logistics.view' => ['View ImpEx Logistics Dashboard', ['impex_officer']],
        'dashboard.maintenance.view' => ['View Maintenance Dashboard', ['maintenance_tech', 'maintenance_supervisor']],
        'dashboard.department.view' => ['View Department Dashboard', ['department_head']],
        'dashboard.sales.view' => ['View Sales Dashboard', ['sales_officer']],
        'dashboard.customer_service.view' => ['View Customer Service Dashboard', ['customer_service_officer']],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $slug => [$name, $roleSlugs]) {
            $this->grant($slug, $name, $roleSlugs);
        }
    }

    public function down(): void
    {
        foreach (self::GRANTS as $slug => [, $roleSlugs]) {
            $this->revoke($slug, $roleSlugs);
        }
    }

    /** @param list<string> $roleSlugs */
    private function grant(string $slug, string $name, array $roleSlugs): void
    {
        $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');

        if ($permissionId === null) {
            $permissionId = (int) DB::table('permissions')->insertGetId([
                'slug' => $slug,
                'name' => $name,
                'module' => 'dashboards',
                'description' => 'Gates the purpose-built dashboard this role lands on (DSS 01-matrix §D3).',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($roleSlugs as $roleSlug) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');

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

    /** @param list<string> $roleSlugs */
    private function revoke(string $slug, array $roleSlugs): void
    {
        $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');

        if ($permissionId === null) {
            return;
        }

        foreach ($roleSlugs as $roleSlug) {
            $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');

            if ($roleId === null) {
                continue;
            }

            // Detach only. The permission row itself is in the
            // RolePermissionSeeder catalog, so the next seed re-creates it —
            // and a rollback must not leave a catalogued slug unheld, which is
            // the state that makes a page exist and be unreachable.
            DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }
};
