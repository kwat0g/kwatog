<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';
        if (in_array($sort, ['name', 'slug', 'created_at'], true)) {
            $query->orderBy($sort, $direction);
        }

        return $query->withCount(['permissions', 'users'])
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function show(Role $role): Role
    {
        return $role->load('permissions');
    }

    public function create(array $data): Role
    {
        return DB::transaction(fn () => Role::create($data));
    }

    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data) {
            $role->update($data);
            return $role->fresh();
        });
    }

    public function delete(Role $role): void
    {
        // Refuse to delete system roles or roles still in use.
        abort_if($role->slug === 'system_admin', 422, 'The system administrator role cannot be deleted.');
        abort_if($role->users()->exists(), 422, 'Cannot delete a role that still has assigned users.');

        DB::transaction(function () use ($role) {
            $role->permissions()->detach();
            $role->delete();
        });
    }

    /**
     * WS-B.1 — Clone an existing role into a new one with the same
     * permission set. The new role's name and slug are derived from the
     * source unless explicit overrides are passed.
     *
     * @param  array{name?:string, slug?:string, description?:string|null}  $overrides
     */
    public function clone(Role $source, array $overrides = []): Role
    {
        return DB::transaction(function () use ($source, $overrides) {
            $baseName = $overrides['name'] ?? ($source->name.' (Copy)');
            $baseSlug = $overrides['slug'] ?? $this->uniqueSlug($source->slug.'_copy');

            $clone = Role::create([
                'name'        => $baseName,
                'slug'        => $baseSlug,
                'description' => array_key_exists('description', $overrides)
                    ? $overrides['description']
                    : $source->description,
            ]);

            // Copy the permission set verbatim.
            $permissionIds = $source->permissions()->pluck('permissions.id')->all();
            if (! empty($permissionIds)) {
                $clone->permissions()->sync($permissionIds);
            }

            // Bust permission slug cache for any user that picks up the new
            // role later — coarse, matches the existing pattern.
            Cache::flush();

            return $clone->fresh('permissions');
        });
    }

    private function uniqueSlug(string $candidate): string
    {
        $slug = $candidate;
        $i = 2;
        while (Role::query()->where('slug', $slug)->exists()) {
            $slug = $candidate.'_'.$i++;
        }
        return $slug;
    }

    /**
     * @param  array<int, string>  $slugs
     */
    public function syncPermissions(Role $role, array $slugs): Role
    {
        return DB::transaction(function () use ($role, $slugs) {
            $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
            $role->permissions()->sync($ids);

            // Bust cached permission slug list for affected users.
            // Coarse flush — refine in Sprint 8.
            Cache::flush();

            return $role->fresh('permissions');
        });
    }

    /**
     * @return array<string, array<int, array{id: string, slug: string, name: string, module: string}>>
     */
    public function permissionMatrix(): array
    {
        return Permission::query()
            ->orderBy('module')
            ->orderBy('slug')
            ->get()
            ->groupBy('module')
            ->map(fn ($perms) => $perms->map(fn ($p) => [
                'id'     => $p->hash_id,
                'slug'   => $p->slug,
                'name'   => $p->name,
                'module' => $p->module,
            ])->all())
            ->all();
    }
}
