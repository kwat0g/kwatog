<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\TrashedFilter;
use App\Common\Support\SearchOperator;
use App\Modules\HR\Models\Position;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PositionService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Position::query()
            ->with('department')
            ->withCount('employees');

        TrashedFilter::apply($query, $filters);

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where('title', SearchOperator::like(), SearchOperator::contains($term));
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

        return $query->with(['department:id,code,name'])
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function create(array $data): Position
    {
        return DB::transaction(function () use ($data) {
            $this->assertUniqueActiveTitle($data['title'], (int) $data['department_id']);

            return Position::create($data)
                ->load('department')
                ->loadCount('employees');
        });
    }

    public function update(Position $position, array $data): Position
    {
        return DB::transaction(function () use ($position, $data) {
            $this->assertUniqueActiveTitle(
                (string) ($data['title'] ?? $position->title),
                (int) ($data['department_id'] ?? $position->department_id),
                $position,
            );
            $position->update($data);
            return $position->fresh('department')->loadCount('employees');
        });
    }

    public function delete(Position $position): void
    {
        if ($position->employees()->exists()) {
            throw new BusinessRuleException('Cannot delete position: employees assigned.');
        }
        $position->delete();
    }

    private function assertUniqueActiveTitle(string $title, int $departmentId, ?Position $ignore = null): void
    {
        $exists = Position::query()
            ->where('department_id', $departmentId)
            ->whereRaw('lower(btrim(title)) = lower(btrim(?))', [$title])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'title' => 'A position with this title already exists in the selected department.',
            ]);
        }
    }
}
