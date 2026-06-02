<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RoleService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Role::query()
            ->withCount(['users', 'permissions']);

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'ilike', '%'.$filters['search'].'%')
                  ->orWhere('slug', 'ilike', '%'.$filters['search'].'%');
            });
        }

        if (isset($filters['is_system']) && $filters['is_system'] !== '' && $filters['is_system'] !== null) {
            $query->where('is_system', filter_var($filters['is_system'], FILTER_VALIDATE_BOOLEAN));
        }

        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';
        if (in_array($sort, ['name', 'slug', 'created_at'], true)) {
            $query->orderBy($sort, $direction);
        }

        $page = $query->paginate(min((int) ($filters['per_page'] ?? 25), 100));

        // ADV4 — attach last-modified audit metadata to each role on this page.
        $ids = collect($page->items())->pluck('id')->all();
        $meta = $this->lastModifiedFor($ids);
        foreach ($page->items() as $r) {
            $r->setAttribute('last_modified_meta', $meta[$r->id] ?? ['by' => null, 'at' => null]);
        }

        return $page;
    }

    public function show(Role $role): Role
    {
        $role->load('permissions');

        // ADV4 — attach last-modified audit metadata for the detail view.
        $meta = $this->lastModifiedFor([$role->id]);
        $role->setAttribute('last_modified_meta', $meta[$role->id] ?? ['by' => null, 'at' => null]);

        return $role;
    }

    /**
     * ADV4 — Compute the most recent edit (updated, permissions_synced, or
     * cloned) for each given role from `audit_logs`, joined to the actor's
     * name. Returns a map keyed by role id; absent ids fall back to nulls
     * upstream.
     *
     * P3.8 fix: uses a correlated subquery to fetch only ONE row per role id
     * (the one with the latest created_at) instead of loading all audit rows
     * into PHP. The subquery is portable across SQLite (tests) and PostgreSQL
     * (production): `SELECT MAX(created_at) FROM audit_logs WHERE model_id = a.model_id ...`
     * is ANSI SQL and supported by both engines.
     *
     * @param  array<int, int>  $roleIds
     * @return array<int, array{by: ?string, at: ?string}>
     */
    private function lastModifiedFor(array $roleIds): array
    {
        if (empty($roleIds)) return [];

        $morph = (new Role)->getMorphClass();

        // Correlated subquery: for each row, check that its created_at equals
        // the MAX created_at for that model_id among the same filtered set.
        // This yields at most one row per model_id (the latest). Portable on
        // both SQLite and PostgreSQL.
        $rows = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.model_id', 'a.created_at', 'u.name as actor_name')
            ->where('a.model_type', $morph)
            ->whereIn('a.model_id', $roleIds)
            ->whereIn('a.action', ['updated', 'permissions_synced', 'cloned'])
            ->whereRaw('a.created_at = (
                SELECT MAX(a2.created_at)
                FROM audit_logs AS a2
                WHERE a2.model_type = a.model_type
                  AND a2.model_id   = a.model_id
                  AND a2.action     IN (\'updated\', \'permissions_synced\', \'cloned\')
            )')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $rid = (int) $r->model_id;
            if (isset($map[$rid])) continue; // guard against ties (same MAX timestamp, multiple rows)
            $map[$rid] = [
                'by' => $r->actor_name ?? null,
                'at' => $r->created_at ? Carbon::parse((string) $r->created_at)->toIso8601String() : null,
            ];
        }
        return $map;
    }

    /**
     * ADV4 — Side-by-side permission diff between two roles.
     *
     * @return array{
     *   role_a: array{id: string, name: string, slug: string, is_system: bool, permissions_count: int},
     *   role_b: array{id: string, name: string, slug: string, is_system: bool, permissions_count: int},
     *   common: array<int, array{slug: string, name: string, module: string}>,
     *   only_in_a: array<int, array{slug: string, name: string, module: string}>,
     *   only_in_b: array<int, array{slug: string, name: string, module: string}>,
     *   modules: array<string, array{common: int, only_a: int, only_b: int}>,
     * }
     */
    public function compare(Role $a, Role $b): array
    {
        $a->loadMissing('permissions');
        $b->loadMissing('permissions');

        $byA = $a->permissions->keyBy('slug');
        $byB = $b->permissions->keyBy('slug');

        $allSlugs = array_unique(array_merge($byA->keys()->all(), $byB->keys()->all()));
        $common = []; $onlyA = []; $onlyB = [];
        $modules = [];
        foreach ($allSlugs as $slug) {
            $row = $byA->get($slug) ?? $byB->get($slug);
            $entry = ['slug' => $row->slug, 'name' => $row->name, 'module' => $row->module];
            $modules[$row->module] ??= ['common' => 0, 'only_a' => 0, 'only_b' => 0];
            $hasA = $byA->has($slug);
            $hasB = $byB->has($slug);
            if ($hasA && $hasB) {
                $common[] = $entry;
                $modules[$row->module]['common']++;
            } elseif ($hasA) {
                $onlyA[] = $entry;
                $modules[$row->module]['only_a']++;
            } else {
                $onlyB[] = $entry;
                $modules[$row->module]['only_b']++;
            }
        }
        $sortByMod = fn ($x, $y) => [$x['module'], $x['slug']] <=> [$y['module'], $y['slug']];
        usort($common, $sortByMod);
        usort($onlyA, $sortByMod);
        usort($onlyB, $sortByMod);
        ksort($modules);

        $card = fn (Role $r) => [
            'id'                => $r->hash_id,
            'name'              => $r->name,
            'slug'              => $r->slug,
            'is_system'         => (bool) $r->is_system,
            'permissions_count' => $r->permissions->count(),
        ];

        return [
            'role_a'    => $card($a),
            'role_b'    => $card($b),
            'common'    => $common,
            'only_in_a' => $onlyA,
            'only_in_b' => $onlyB,
            'modules'   => $modules,
        ];
    }

    public function create(array $data): Role
    {
        return DB::transaction(fn () => Role::create([
            'name'        => $data['name'],
            'slug'        => $data['slug'],
            'description' => $data['description'] ?? null,
            'is_system'   => false, // R1: only seeders can create system roles
        ]));
    }

    public function update(Role $role, array $data): Role
    {
        // R1: refuse rename/edit on system roles. Allow description tweak only
        // through a separate path if business decides — for now: hard block.
        abort_if(
            (bool) $role->is_system,
            422,
            'System roles cannot be edited. Clone the role first to create a customizable copy.',
        );

        return DB::transaction(function () use ($role, $data) {
            $role->update($data);
            return $role->fresh();
        });
    }

    public function delete(Role $role): void
    {
        abort_if((bool) $role->is_system, 422, 'System roles cannot be deleted.');
        abort_if($role->users()->exists(), 422, 'Cannot delete a role that still has assigned users.');

        DB::transaction(function () use ($role) {
            $role->permissions()->detach();
            $role->delete();
        });
    }

    /**
     * Clone an existing role into a brand-new custom role with all
     * permissions copied. The new role is always `is_system = false`.
     */
    public function clone(Role $source, array $data): Role
    {
        return DB::transaction(function () use ($source, $data) {
            $clone = Role::create([
                'name'        => $data['name'],
                'slug'        => $data['slug'],
                'description' => $data['description'] ?? "Cloned from {$source->name}",
                'is_system'   => false,
            ]);

            $permissionIds = $source->permissions()->pluck('permissions.id')->all();
            if (! empty($permissionIds)) {
                $clone->permissions()->sync($permissionIds);
            }

            // Audit the clone explicitly so reviewers can trace lineage.
            AuditLog::create([
                'user_id'    => Auth::id(),
                'action'     => 'cloned',
                'model_type' => $clone->getMorphClass(),
                'model_id'   => $clone->getKey(),
                'old_values' => null,
                'new_values' => [
                    'cloned_from_role_id' => $source->id,
                    'cloned_from_slug'    => $source->slug,
                    'permissions_copied'  => count($permissionIds),
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $clone->load('permissions');
        });
    }

    /**
     * @param  array<int, string>  $slugs
     */
    public function syncPermissions(Role $role, array $slugs): Role
    {
        // R1: system roles are seeded by RolePermissionSeeder and must remain
        // in lockstep with the seeder so deployments don't drift. Reject any
        // direct API attempt to alter a system role's permission set —
        // including non-`system_admin` ones like `hr_officer`. Custom roles
        // (is_system=false) remain freely editable.
        abort_if(
            (bool) $role->is_system,
            422,
            $role->slug === 'system_admin'
                ? 'The System Administrator role always has every permission and cannot be edited.'
                : 'System roles cannot be edited. Clone the role first to create a customizable copy.',
        );

        return DB::transaction(function () use ($role, $slugs) {
            $existing = $role->permissions()->pluck('permissions.slug')->all();
            $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
            $role->permissions()->sync($ids);

            $added   = array_values(array_diff($slugs, $existing));
            $removed = array_values(array_diff($existing, $slugs));

            // Audit the diff so reviewers can answer "who granted X to role Y".
            AuditLog::create([
                'user_id'    => Auth::id(),
                'action'     => 'permissions_synced',
                'model_type' => $role->getMorphClass(),
                'model_id'   => $role->getKey(),
                'old_values' => ['permissions' => $existing],
                'new_values' => [
                    'permissions' => $slugs,
                    'added'       => $added,
                    'removed'     => $removed,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            // Bust cached permission slug list for affected users.
            // Coarse flush — refine to per-user-with-this-role in a future pass.
            Cache::flush();

            return $role->fresh('permissions');
        });
    }

    /**
     * @return array<string, array<int, array{id: string, slug: string, name: string, module: string, description: ?string}>>
     */
    public function permissionMatrix(): array
    {
        return Permission::query()
            ->orderBy('module')
            ->orderBy('slug')
            ->get()
            ->groupBy('module')
            ->map(fn ($perms) => $perms->map(fn ($p) => [
                'id'          => $p->hash_id,
                'slug'        => $p->slug,
                'name'        => $p->name,
                'module'      => $p->module,
                'description' => $p->description,
            ])->all())
            ->all();
    }
}
