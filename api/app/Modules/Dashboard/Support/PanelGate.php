<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Support;

use App\Modules\Auth\Models\User;

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
     * For the dashboards cached per user id, per-viewer panel sets are already
     * cache-safe. FinanceDashboardService is not: it caches under ONE shared key
     * (`finance_dashboard:summary:v2`) across every caller, so gating its panels
     * per user would serve one viewer's payload to another — a gate that leaks
     * is worse than no gate.
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
}
