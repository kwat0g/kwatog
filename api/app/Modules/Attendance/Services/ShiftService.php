<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\TrashedFilter;
use App\Modules\Attendance\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Shift::query();
        TrashedFilter::apply($q, $filters);
        if (!empty($filters['search'])) $q->where('name', 'ilike', "%{$filters['search']}%");
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        return $q->orderBy('name')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function create(array $data): Shift
    {
        return DB::transaction(function () use ($data) {
            $this->assertDistinctTimes($data['start_time'] ?? null, $data['end_time'] ?? null);
            if ($data['is_default'] ?? false) {
                Shift::query()->where('is_default', true)->update(['is_default' => false]);
                $data['is_active'] = true;
            }

            return Shift::create($data);
        });
    }

    public function update(Shift $shift, array $data): Shift
    {
        return DB::transaction(function () use ($shift, $data) {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->getKey());
            $this->assertDistinctTimes(
                $data['start_time'] ?? $locked->start_time,
                $data['end_time'] ?? $locked->end_time,
            );

            if (($data['is_default'] ?? null) === true) {
                Shift::query()->whereKeyNot($locked->getKey())->where('is_default', true)->update(['is_default' => false]);
                $data['is_active'] = true;
            }

            if ($locked->is_default && (($data['is_default'] ?? true) === false || ($data['is_active'] ?? true) === false)) {
                throw new BusinessRuleException('Choose another default shift before disabling or unmarking this one.');
            }

            $locked->update($data);
            return $locked->fresh();
        });
    }

    public function restore(Shift $shift): void
    {
        DB::transaction(function () use ($shift): void {
            $locked = Shift::withTrashed()->lockForUpdate()->findOrFail($shift->getKey());
            $locked->restore();
        });
    }

    public function delete(Shift $shift): void
    {
        DB::transaction(function () use ($shift): void {
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->getKey());
            if ($locked->is_default) {
                throw new BusinessRuleException('Choose another default shift before deleting this one.');
            }
            if ($locked->assignments()->exists()) {
                throw new BusinessRuleException('Cannot delete shift: employees are assigned.');
            }
            $locked->delete();
        });
    }

    private function assertDistinctTimes(mixed $start, mixed $end): void
    {
        if (substr((string) $start, 0, 5) === substr((string) $end, 0, 5)) {
            throw new BusinessRuleException('End time cannot be the same as start time.');
        }
    }
}
