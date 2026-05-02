<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class LeaveTypeService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = LeaveType::query();
        if (!empty($filters['search'])) $q->where('name', 'ilike', "%{$filters['search']}%");
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        return $q->orderBy('code')->paginate(min((int) ($filters['per_page'] ?? 50), 200));
    }

    public function create(array $data): LeaveType { return LeaveType::create($data); }
    public function update(LeaveType $lt, array $data): LeaveType { $lt->update($data); return $lt->fresh(); }
    public function delete(LeaveType $lt): void { $lt->delete(); }
}
