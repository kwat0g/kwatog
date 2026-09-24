<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Support\SearchOperator;
use App\Modules\HR\Models\Skill;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SkillService
{
    /** @param array<string, mixed> $filters */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Skill::query()
            ->when(
                array_key_exists('active', $filters) && $filters['active'] !== '',
                fn(Builder $q) => $q->where('is_active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN)),
                fn(Builder $q) => $q->where('is_active', true),
            )
            ->when($filters['category'] ?? null, fn(Builder $q, $v) => $q->where('category', $v))
            ->when($filters['q'] ?? null, fn(Builder $q, $v) => $q->where('name', SearchOperator::like(), SearchOperator::contains($v)))
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Skill
    {
        return DB::transaction(fn() => Skill::create($data));
    }

    /** @param array<string, mixed> $data */
    public function update(Skill $skill, array $data): Skill
    {
        return DB::transaction(function () use ($skill, $data) {
            $skill->fill($data)->save();
            return $skill->refresh();
        });
    }

    public function deactivate(Skill $skill): Skill
    {
        return DB::transaction(function () use ($skill) {
            $skill->update(['is_active' => false]);
            return $skill->refresh();
        });
    }
}
