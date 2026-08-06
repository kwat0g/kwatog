<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\TrashedFilter;
use App\Modules\HR\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DepartmentService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Department::query()
            ->with(['parent', 'headEmployee'])
            ->withCount(['positions', 'employees']);

        TrashedFilter::apply($query, $filters);
        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('name', 'ilike', "%{$term}%")
                  ->orWhere('code', 'ilike', "%{$term}%");
            });
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (!empty($filters['parent_id'])) {
            $parentId = \App\Common\Support\HashIdFilter::decode(
                $filters['parent_id'], Department::class,
            );
            if ($parentId) $query->where('parent_id', $parentId);
        }

        $sort = $filters['sort'] ?? 'name';
        $dir = $filters['direction'] ?? 'asc';
        if (in_array($sort, ['name', 'code', 'is_active'], true)) {
            $query->orderBy($sort, $dir);
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);
        return $query->paginate($perPage);
    }

    /** @return Collection<int, Department> */
    public function tree(array $filters = []): Collection
    {
        $query = Department::query()
            ->with(['headEmployee'])
            ->withCount(['positions', 'employees'])
            ->orderBy('name');

        TrashedFilter::apply($query, $filters);

        return $query->get();
    }

    public function show(Department $department): Department
    {
        return $department->load(['parent', 'children', 'positions', 'headEmployee'])
            ->loadCount(['positions', 'employees']);
    }

    public function create(array $data): Department
    {
        return DB::transaction(fn () => Department::create($data)
            ->load(['parent', 'headEmployee'])
            ->loadCount(['positions', 'employees']));
    }

    public function update(Department $department, array $data): Department
    {
        return DB::transaction(function () use ($department, $data) {
            // Prevent self-parenting / cycle (one-level safe-guard).
            if (!empty($data['parent_id']) && (int) $data['parent_id'] === $department->id) {
                throw new \InvalidArgumentException('A department cannot be its own parent.');
            }
            $department->update($data);
            return $department->fresh(['parent', 'headEmployee'])
                ->loadCount(['positions', 'employees']);
        });
    }

    public function delete(Department $department): void
    {
        if ($department->positions()->exists()) {
            throw new BusinessRuleException('Cannot delete department: positions exist.');
        }
        if ($department->employees()->exists()) {
            throw new BusinessRuleException('Cannot delete department: employees assigned.');
        }
        if ($department->children()->exists()) {
            throw new BusinessRuleException('Cannot delete department: child departments exist.');
        }
        $department->delete();
    }
}
