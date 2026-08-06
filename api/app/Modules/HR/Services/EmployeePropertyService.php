<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Support\TrashedFilter;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeProperty;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EmployeePropertyService
{
    public function list(Employee $employee, array $filters): LengthAwarePaginator
    {
        $query = $employee->property()->getQuery();
        TrashedFilter::apply($query, $filters);
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        return $query->orderByDesc('date_issued')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function create(Employee $employee, array $data): EmployeeProperty
    {
        return DB::transaction(function () use ($employee, $data) {
            $data['employee_id'] = $employee->id;
            return EmployeeProperty::create($data);
        });
    }

    public function update(EmployeeProperty $property, array $data): EmployeeProperty
    {
        $property->update($data);
        return $property->fresh();
    }

    public function delete(EmployeeProperty $property): void
    {
        $property->delete();
    }
}