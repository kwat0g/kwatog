<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\Position;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PositionService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Position::query()
            ->with('department')
            ->withCount('employees');

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where('title', 'ilike', "%{$term}%");
        }
        if (!empty($filters['department_id'])) {
            $deptId = \App\Common\Support\HashIdFilter::decode(
                $filters['department_id'],
                \App\Modules\HR\Models\Department::class,
            );
            if ($deptId) $query->where('department_id', $deptId);
        }

        $sort = $filters['sort'] ?? 'title';
        $dir = $filters['direction'] ?? 'asc';
        if (in_array($sort, ['title', 'salary_grade'], true)) {
            $query->orderBy($sort, $dir);
        }

        return $query->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function create(array $data): Position
    {
        return DB::transaction(fn () => Position::create($data)
            ->load('department')
            ->loadCount('employees'));
    }

    public function update(Position $position, array $data): Position
    {
        return DB::transaction(function () use ($position, $data) {
            $position->update($data);
            return $position->fresh('department')->loadCount('employees');
        });
    }

    public function delete(Position $position): void
    {
        if ($position->employees()->exists()) {
            throw new RuntimeException('Cannot delete position: employees assigned.');
        }
        $position->delete();
    }
}
