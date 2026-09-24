<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Support\HashIdFilter;
use App\Common\Support\TrashedFilter;
use App\Common\Support\SearchOperator;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Training;
use App\Modules\HR\Models\EmployeeTraining;
use App\Common\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TrainingService
{
    /** @param array<string, mixed> $filters */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Training::query()
            ->with('department');

        TrashedFilter::apply($query, $filters);

        $query
            ->when(
                array_key_exists('active', $filters) && $filters['active'] !== '',
                fn(Builder $q) => $q->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN)),
            )
            ->when($filters['certification'] ?? null, fn(Builder $q, $v) => $q->where('is_certification', (bool) $v))
            // TrainingController::index() forwards the raw query bag, so the SPA's
            // hash string would hit a bigint column (Postgres 22P02 → 500).
            ->when($filters['department_id'] ?? null, fn(Builder $q, $v) => $q->where('department_id', HashIdFilter::decode($v, Department::class) ?? 0))
            ->when($filters['q'] ?? null, fn(Builder $q, $v) => $q->where('name', SearchOperator::like(), SearchOperator::contains($v)))
            ->orderBy('name');

        return $query->paginate((int) ($filters['per_page'] ?? 25));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Training
    {
        return DB::transaction(fn() => Training::create($data));
    }

    /** @param array<string, mixed> $data */
    public function update(Training $training, array $data): Training
    {
        return DB::transaction(function () use ($training, $data) {
            $training->fill($data)->save();
            return $training->refresh();
        });
    }

    public function delete(Training $training): void
    {
        DB::transaction(function () use ($training): void {
            if (EmployeeTraining::query()->where('training_id', $training->id)->exists()) {
                throw new BusinessRuleException('Cannot archive training: employee assignment history exists.');
            }

            $training->delete();
        });
    }
}
