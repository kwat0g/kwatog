<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Support;

/**
 * The catalog of purpose-built dashboards, keyed by the permission that
 * gates each one.
 *
 * This is the ONLY place a dashboard's identity lives. It deliberately
 * records no role names: a user reaches a dashboard by holding its
 * permission, never by matching a role slug. Adding a role, or moving a
 * permission between roles, changes who lands where without touching this
 * file — which is the property the old SPA-side `ROLE_DASHBOARDS` map
 * (a `Record<roleSlug, …>` switch) could not offer.
 *
 * `path` is the SPA route. The matching `PermissionGuard` on that route
 * (spa/src/routes/dashboardRoutes.tsx) repeats the same permission as
 * defence in depth; the API remains the authority.
 */
final class DashboardCatalog
{
    /**
     * Fallback for a user who qualifies for no purpose-built dashboard:
     * the generic widget-layout home, which every authenticated user has.
     */
    public const DEFAULT_PATH = '/dashboard/default';

    /**
     * @return array<int, array{key: string, path: string, permission: string, name: string}>
     */
    public static function all(): array
    {
        return [
            // Cross-functional executive surface (DSS 01-matrix §B). It lists
            // ahead of nothing — the winner is decided by live rarity, not
            // order — but it is deliberately the ONLY catalog entry whose
            // permission is a company-wide READ rather than a module's own
            // dashboard. `dashboard.company.view` has two holders
            // (system_admin, vice_president), so system_admin keeps landing on
            // `admin` (one holder) while the vice president moves here from
            // `/dashboard/plant-manager` (three holders).
            ['key' => 'company',       'path' => '/dashboard/company',       'permission' => 'dashboard.company.view',       'name' => 'Company Overview'],
            // The five role dashboards DSS 01-matrix §D3 specifies for roles that
            // had no slug of their own and therefore fell through to the generic
            // widget grid. Each is held by exactly one role (maintenance by two),
            // so nothing else in the catalog can out-rank it for them — and the
            // admin wildcard holds all five while still landing on `admin`, since
            // rarity then ties and `admin` sorts ahead of every other one-holder
            // key. That is asserted in DashboardDispatchTest rather than left to
            // arithmetic.
            ['key' => 'logistics',     'path' => '/dashboard/logistics',     'permission' => 'dashboard.logistics.view',     'name' => 'ImpEx Logistics'],
            ['key' => 'maintenance',   'path' => '/dashboard/maintenance',   'permission' => 'dashboard.maintenance.view',   'name' => 'Maintenance'],
            ['key' => 'department',    'path' => '/dashboard/department',    'permission' => 'dashboard.department.view',    'name' => 'Department'],
            ['key' => 'sales',         'path' => '/dashboard/sales',         'permission' => 'dashboard.sales.view',         'name' => 'Sales'],
            ['key' => 'customer-service', 'path' => '/dashboard/customer-service', 'permission' => 'dashboard.customer_service.view', 'name' => 'Customer Service'],
            ['key' => 'admin',         'path' => '/dashboard/admin',         'permission' => 'dashboard.admin.view',         'name' => 'System Administrator'],
            ['key' => 'plant-manager', 'path' => '/dashboard/plant-manager', 'permission' => 'dashboard.plant_manager.view', 'name' => 'Plant Manager'],
            ['key' => 'hr',            'path' => '/dashboard/hr',            'permission' => 'dashboard.hr.view',            'name' => 'HR'],
            ['key' => 'ppc',           'path' => '/dashboard/ppc',           'permission' => 'dashboard.ppc.view',           'name' => 'PPC'],
            // Canonical path is /dashboard/finance; /dashboard/accounting
            // redirects to it. The permission kept its original slug.
            ['key' => 'finance',       'path' => '/dashboard/finance',       'permission' => 'dashboard.accounting.view',    'name' => 'Finance'],
            ['key' => 'purchasing',    'path' => '/dashboard/purchasing',    'permission' => 'dashboard.purchasing.view',    'name' => 'Purchasing'],
            ['key' => 'warehouse',     'path' => '/dashboard/warehouse',     'permission' => 'dashboard.warehouse.view',     'name' => 'Warehouse'],
            ['key' => 'quality',       'path' => '/dashboard/quality',       'permission' => 'dashboard.quality.view',       'name' => 'Quality'],
        ];
    }
}
