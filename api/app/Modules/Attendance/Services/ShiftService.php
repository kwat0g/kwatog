<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Shift::query();
        if (!empty($filters['search'])) $q->where('name', 'ilike', "%{$filters['search']}%");
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        return $q->orderBy('name')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function create(array $data): Shift
    {
        return DB::transaction(function () use ($data) {
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
            if (($data['is_default'] ?? null) === true) {
                Shift::query()->whereKeyNot($shift->getKey())->where('is_default', true)->update(['is_default' => false]);
                $data['is_active'] = true;
            }

            if ($shift->is_default && (($data['is_default'] ?? true) === false || ($data['is_active'] ?? true) === false)) {
                throw new BusinessRuleException('Choose another default shift before disabling or unmarking this one.');
            }

            $shift->update($data);
            return $shift->fresh();
        });
    }

    public function delete(Shift $shift): void
    {
        if ($shift->is_default) {
            throw new BusinessRuleException('Choose another default shift before deleting this one.');
        }
        if ($shift->assignments()->exists()) {
            throw new BusinessRuleException('Cannot delete shift: employees are assigned.');
        }
        $shift->delete();
    }
}
