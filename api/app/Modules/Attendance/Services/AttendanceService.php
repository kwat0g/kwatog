<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Attendance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(
        private readonly DTRComputationService $dtr,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Attendance::query()->with(['employee:id,employee_no,first_name,middle_name,last_name,suffix,department_id', 'employee.department', 'shift']);

        if (!empty($filters['employee_id'])) {
            $q->where('employee_id', $filters['employee_id']);
        }
        if (!empty($filters['department_id'])) {
            $q->whereHas('employee', fn ($e) => $e->where('department_id', $filters['department_id']));
        }
        if (!empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (!empty($filters['from'])) $q->where('date', '>=', $filters['from']);
        if (!empty($filters['to'])) $q->where('date', '<=', $filters['to']);
        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $q->whereHas('employee', function ($e) use ($term) {
                $e->where('employee_no', 'ilike', "%{$term}%")
                  ->orWhere('first_name', 'ilike', "%{$term}%")
                  ->orWhere('last_name', 'ilike', "%{$term}%");
            });
        }

        $sort = $filters['sort'] ?? 'date';
        $dir  = $filters['direction'] ?? 'desc';
        if (in_array($sort, ['date', 'status', 'regular_hours', 'overtime_hours'], true)) {
            $q->orderBy($sort, $dir);
        }

        return $q->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function create(array $data): Attendance
    {
        return DB::transaction(function () use ($data) {
            $a = Attendance::create($data + ['is_manual_entry' => true]);
            $a = $this->dtr->computeForRecord($a);
            $a->save();
            return $a->load(['employee', 'shift']);
        });
    }

    public function update(Attendance $a, array $data): Attendance
    {
        return DB::transaction(function () use ($a, $data) {
            $a->update($data);
            $a = $this->dtr->computeForRecord($a);
            $a->save();
            return $a->fresh(['employee', 'shift']);
        });
    }

    public function delete(Attendance $a): void
    {
        $a->delete();
    }

    public function recomputeForEmployeeOnDate(int $employeeId, string $date): ?Attendance
    {
        $a = Attendance::where('employee_id', $employeeId)->where('date', $date)->first();
        if (! $a) return null;
        $a = $this->dtr->computeForRecord($a);
        $a->save();
        return $a;
    }
}
