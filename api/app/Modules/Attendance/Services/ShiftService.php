<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Shift;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        return DB::transaction(fn () => Shift::create($data));
    }

    public function update(Shift $shift, array $data): Shift
    {
        return DB::transaction(function () use ($shift, $data) {
            $shift->update($data);
            return $shift->fresh();
        });
    }

    public function delete(Shift $shift): void
    {
        if ($shift->assignments()->exists()) {
            throw new RuntimeException('Cannot delete shift: employees are assigned.');
        }
        $shift->delete();
    }
}
