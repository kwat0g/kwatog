<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(
        private readonly DTRComputationService $dtr,
    ) {}

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
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

        // Row-level filtering. Admin and HR Officer see everything.
        // Department Head sees their dept's records. Everyone else sees only their own.
        if ($user) {
            $roleSlug = $user->role?->slug;
            $isAdmin = $roleSlug === 'system_admin';
            $isHrFull = $user->hasPermission('attendance.import') || $user->hasPermission('attendance.edit');
            if (! $isAdmin && ! $isHrFull) {
                $employeeId = $user->employee_id;
                if ($user->hasPermission('attendance.ot.approve')) {
                    $deptId = \App\Modules\HR\Models\Employee::query()->whereKey($employeeId)->value('department_id');
                    $q->where(function ($qq) use ($employeeId, $deptId) {
                        $qq->where('employee_id', $employeeId);
                        if ($deptId) $qq->orWhereHas('employee', fn ($e) => $e->where('department_id', $deptId));
                    });
                } else {
                    $q->where('employee_id', $employeeId);
                }
            }
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
