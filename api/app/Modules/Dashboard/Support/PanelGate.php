<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Support;

use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place a bespoke dashboard panel says "who may read this?".
 *
 * The eight purpose-built dashboards were gated ONCE, at the route
 * (`permission:dashboard.plant_manager.view` and friends) — page-level,
 * all-or-nothing. Every panel inside then ran unconditionally, so a role that
 * held the page grant received data its own module would have refused it: the
 * Plant Manager dashboard handed cash, AR, AP and revenue to production_manager,
 * a role with no `accounting.*` grant at all. The widget registry never had this
 * problem, because a `dashboard_widgets` row declares its own permission and
 * DashboardLayoutService strips what the viewer cannot hold.
 *
 * This brings the bespoke pages to the same rule: a panel declares its
 * permission next to the query that fills it, and a refused panel is OMITTED —
 * not nulled, not zeroed. Zeroing would be worse than leaking: "AR outstanding
 * ₱0.00" reads as a settled ledger rather than as a panel you may not see.
 *
 * Omission is deliberately SILENT, matching the widget strip. The SPA renders
 * the panels that arrive.
 *
 * Closures, not values: a refused panel's query must never run. Otherwise the
 * data is still read (and still logged by the query log) for someone who may
 * not have it, and the permission check saves nothing but the render.
 */
final class PanelGate
{
    /**
     * @param  array<string, array{0: ?string, 1: callable}>  $panels  key => [permission|null, fn]
     * @return array<string, mixed>
     */
    public function panels(User $user, array $panels): array
    {
        $out = [];

        foreach ($panels as $key => [$permission, $resolve]) {
            if ($permission !== null && ! $user->hasPermission($permission)) {
                continue;
            }

            $out[$key] = $resolve();
        }

        return $out;
    }

    /**
     * KPI headline tiles. A list, not a map — refused tiles are dropped and the
     * list is re-indexed so the SPA's `kpis.map` stays a dense array.
     *
     * @param  array<int, array{0: ?string, 1: callable}>  $kpis
     * @return array<int, mixed>
     */
    public function kpis(User $user, array $kpis): array
    {
        $out = [];

        foreach ($kpis as [$permission, $resolve]) {
            if ($permission !== null && ! $user->hasPermission($permission)) {
                continue;
            }

            $out[] = $resolve();
        }

        return $out;
    }

    /**
     * A stable fingerprint of how this viewer answers a given set of gates.
     *
     * For dashboards cached per user id, per-viewer panel sets are already
     * cache-safe. FinanceDashboardService deliberately shares summaries among
     * callers with the same gate answers. Its key includes this signature and a
     * dashboard cache generation, so callers with different panel grants cannot
     * receive one another's payload — a gate that leaks is worse than no gate.
     *
     * Keying by user id instead would give every finance user their own entry and
     * throw away the sharing that made a shared key worth having. This keys by
     * the ANSWERS: two callers who hold the same subset share one entry, and a
     * caller who holds a different subset gets its own.
     *
     * @param  array<int, string>  $permissions  every gate the payload consults
     */
    public function signature(User $user, array $permissions): string
    {
        $held = array_filter(
            $permissions,
            static fn (string $permission): bool => $user->hasPermission($permission),
        );
        sort($held);

        return substr(hash('sha256', implode('|', $held)), 0, 16);
    }

    /**
     * Cache partition for per-viewer dashboard payloads. These values include
     * panel sets and, for employee-linked users, department-scoped aggregates.
     * A user ID alone is not enough: the same account can lose grants or move
     * to a different department while its old 30-second snapshot is cached.
     */
    public function cacheScope(User $user): string
    {
        $permissions = array_values(array_unique($user->permission_slugs));
        sort($permissions, SORT_STRING);

        $departmentId = $user->employee_id === null
            ? null
            : DB::table('employees')->where('id', (int) $user->employee_id)->value('department_id');

        return substr(hash('sha256', json_encode([
            'user' => (int) $user->id,
            'role_id' => $user->role_id === null ? null : (int) $user->role_id,
            'role_slug' => $user->role?->slug,
            'employee_id' => $user->employee_id === null ? null : (int) $user->employee_id,
            'department_id' => $departmentId === null ? null : (int) $departmentId,
            'permissions' => $permissions,
        ], JSON_THROW_ON_ERROR)), 0, 24);
    }
}
