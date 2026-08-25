<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\TrashedFilter;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Enums\EmployeeStatus;
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
            ->with(['parent', 'headEmployee'])
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
        return DB::transaction(function () use ($data): Department {
            $this->validateHierarchy(null, $data);

            return Department::create($data)
                ->load(['parent', 'headEmployee'])
                ->loadCount(['positions', 'employees']);
        });
    }

    public function update(Department $department, array $data): Department
    {
        return DB::transaction(function () use ($department, $data) {
            $locked = Department::query()->lockForUpdate()->findOrFail($department->id);
            $this->validateHierarchy($locked, $data);
            $locked->update($data);
            return $locked->fresh(['parent', 'headEmployee'])
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

    /** @param array<string, mixed> $data */
    private function validateHierarchy(?Department $department, array $data): void
    {
        $parentId = array_key_exists('parent_id', $data)
            ? ($data['parent_id'] === null ? null : (int) $data['parent_id'])
            : $department?->parent_id;

        if ($parentId !== null) {
            $parent = Department::query()->find($parentId);
            if (! $parent) {
                throw new BusinessRuleException('Parent department does not exist or is archived.');
            }
            if ($department && $parentId === (int) $department->id) {
                throw new BusinessRuleException('A department cannot be its own parent.');
            }

            $seen = [];
            $cursor = $parentId;
            while ($cursor !== null) {
                if (isset($seen[$cursor])) {
                    throw new BusinessRuleException('The department hierarchy already contains a cycle.');
                }
                $seen[$cursor] = true;
                if ($department && $cursor === (int) $department->id) {
                    throw new BusinessRuleException('A department cannot be placed below one of its descendants.');
                }
                $cursor = Department::query()->whereKey($cursor)->value('parent_id');
            }
        }

        if (array_key_exists('head_employee_id', $data) && $data['head_employee_id'] !== null) {
            $targetDepartmentId = $department?->id;
            $head = Employee::query()->find((int) $data['head_employee_id']);
            if (! $head) {
                throw new BusinessRuleException('Department head employee does not exist or is archived.');
            }
            if ($targetDepartmentId === null || (int) $head->department_id !== (int) $targetDepartmentId) {
                throw new BusinessRuleException('Department head must belong to the department.');
            }
            if ($head->status !== EmployeeStatus::Active) {
                throw new BusinessRuleException('Department head must be an active employee.');
            }
        }
    }
}
