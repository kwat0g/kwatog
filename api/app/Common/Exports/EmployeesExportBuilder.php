<?php

declare(strict_types=1);

namespace App\Common\Exports;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;

/**
 * WS-E.1 — First concrete builder: HR employees.
 *
 * Streams employees through a chunked Eloquent cursor so memory stays
 * flat regardless of headcount. Sensitive columns (SSS / TIN / bank
 * account) are intentionally NOT included; the existing
 * `hr.employees.export` permission separates "can export the public
 * roster" from "can see masked-PII".
 */
class EmployeesExportBuilder implements ExportBuilder
{
    public function permission(): string
    {
        return 'hr.employees.export';
    }

    public function headers(): array
    {
        return [
            'Employee No',
            'First Name',
            'Middle Name',
            'Last Name',
            'Department',
            'Position',
            'Employment Type',
            'Pay Type',
            'Date Hired',
            'Date Regularized',
            'Status',
        ];
    }

    public function rows(array $filters, User $requester): iterable
    {
        $query = Employee::query()->with(['department', 'position']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', $term)
                  ->orWhere('last_name', 'like', $term)
                  ->orWhere('employee_no', 'like', $term);
            });
        }

        foreach ($query->orderBy('employee_no')->cursor() as $emp) {
            yield [
                $emp->employee_no,
                $emp->first_name,
                $emp->middle_name,
                $emp->last_name,
                $emp->department?->name ?? '',
                $emp->position?->title ?? '',
                $emp->employment_type instanceof \BackedEnum ? $emp->employment_type->value : $emp->employment_type,
                $emp->pay_type instanceof \BackedEnum ? $emp->pay_type->value : $emp->pay_type,
                optional($emp->date_hired)->format('Y-m-d'),
                optional($emp->date_regularized)->format('Y-m-d'),
                $emp->status instanceof \BackedEnum ? $emp->status->value : $emp->status,
            ];
        }
    }

    public function filename(): string
    {
        return 'employees-'.now()->format('Ymd-His');
    }
}
