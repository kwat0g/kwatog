<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

        return $query->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Role $role): Role
    {
        return $role->load('permissions');
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
