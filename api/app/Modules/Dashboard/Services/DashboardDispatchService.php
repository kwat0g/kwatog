<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Support\DashboardCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Chooses the purpose-built dashboard a user should land on — derived from
 * their resolved permissions, never from their role's name.
 *
 * A user is a candidate for a dashboard when they hold its gating
 * permission. When several qualify (system_admin holds all of them), the
 * most specific one wins: specificity is measured as the number of roles
 * that hold the permission, read live from `role_permissions` — rarer is
 * more specific. The measure is data, not a priority list, so a new role
 * holding an existing dashboard permission changes the ranking for
 * everyone without a code edit.
 *
 * Fallback: no qualifying dashboard → DashboardCatalog::DEFAULT_PATH,
 * the generic widget-layout home every authenticated user has.
 *
 * This replaces the SPA-side `ROLE_DASHBOARDS: Record<roleSlug, …>`
 * switch (spa/src/pages/dashboard/index.tsx) — the one place a role-name
 * change could point a user at the wrong dashboard or at none.
 */
final class DashboardDispatchService
{
    public function resolve(User $user): array
    {
        $candidates = $this->qualifying($user);

        if ($candidates === []) {
            return [
                'path'       => DashboardCatalog::DEFAULT_PATH,
                'permission' => null,
                'key'        => null,
                'name'       => null,
            ];
        }

        // Ties break on `key` so the landing page is stable rather than
        // dependent on catalog order or row ordering.
        usort($candidates, fn (array $a, array $b): int => [$a['holder_count'], $a['key']] <=> [$b['holder_count'], $b['key']]);

        return $candidates[0];
    }

    /** @return array<int, array{key: string, path: string, permission: string, name: string, holder_count: int}> */
    public function qualifying(User $user): array
    {
        // system_admin resolves every permission (hasPermission bypasses
        // the cache); a plain in_array over the cached slugs would
        // wrongly exclude it.
        $catalog = DashboardCatalog::all();
        $userPerms = $user->permission_slugs;
        $isAdmin = $user->role?->slug === 'system_admin';
        $holders = $this->holderCounts();

        return array_values(array_filter(
            array_map(function (array $d) use ($userPerms, $isAdmin, $holders): ?array {
                if (! $isAdmin && ! in_array($d['permission'], $userPerms, true)) {
                    return null;
                }
                $d['holder_count'] = $holders[$d['permission']] ?? 0;
                return $d;
            }, $catalog),
        ));
    }

    /**
     * Live rarity: how many roles hold each dashboard permission. A new
     * role granted an existing dashboard permission automatically makes
     * that dashboard less specific for every other holder.
     *
     * @return array<string, int>
     */
    private function holderCounts(): array
    {
        $slugs = array_column(DashboardCatalog::all(), 'permission');

        return DB::table('permissions as p')
            ->join('role_permissions as rp', 'rp.permission_id', '=', 'p.id')
            // Roles soft-delete; a trashed role must not make a dashboard
            // look less specific than it is.
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->whereNull('r.deleted_at')
            ->whereIn('p.slug', $slugs)
            ->selectRaw('p.slug, COUNT(*) AS holders')
            ->groupBy('p.slug')
            ->pluck('holders', 'slug')
            ->all();
    }
}
