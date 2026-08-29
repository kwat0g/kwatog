<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\TrashedFilter;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    public function __construct(
        private readonly DTRComputationService $dtr,
        private readonly AttendanceDateMutabilityGuard $mutability,
    ) {}

    /**
     * Lazily resolved to avoid the AttendanceService <-> OvertimeService
     * circular dependency at container construction time.
     */
    private function overtime(): OvertimeService
    {
        return app(OvertimeService::class);
    }

    public function list(array $filters, ?User $user = null): LengthAwarePaginator
    {
        $q = Attendance::query()->with(['employee:id,employee_no,first_name,middle_name,last_name,suffix,department_id', 'employee.department', 'shift']);

        TrashedFilter::apply($q, $filters);

        if (!empty($filters['employee_id'])) {
            $empId = \App\Common\Support\HashIdFilter::decode(
                $filters['employee_id'], \App\Modules\HR\Models\Employee::class,
            );
            if ($empId) $q->where('employee_id', $empId);
        }
        if (!empty($filters['department_id'])) {
            $deptId = \App\Common\Support\HashIdFilter::decode(
                $filters['department_id'], \App\Modules\HR\Models\Department::class,
            );
            if ($deptId) {
                $q->whereHas('employee', fn ($e) => $e->where('department_id', $deptId));
            }
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
        // `sort` was whitelisted but `direction` was not, and Laravel's
        // Query\Builder::orderBy() throws InvalidArgumentException on anything
        // that is not asc/desc — so `?direction=x` was a 500 on a list endpoint.
        // Normalise instead of trusting the query string.
        $dir = strtolower((string) ($filters['direction'] ?? 'desc'));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }
        if (in_array($sort, ['date', 'status', 'regular_hours', 'overtime_hours'], true)) {
            $q->orderBy($sort, $dir);
        }

        return $q->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function create(array $data): Attendance
    {
        return DB::transaction(function () use ($data) {
            $this->mutability->assertMutable((int) $data['employee_id'], (string) $data['date']);
            $this->assertDayNotArchived((int) $data['employee_id'], (string) $data['date']);
            $a = Attendance::create($data + ['is_manual_entry' => true]);
            $a = $this->dtr->computeForRecord($a);
            $a->save();
            $this->overtime()->autoDetectFromAttendance($a);
            return $a->load(['employee', 'employee.department', 'shift']);
        });
    }

    /**
     * `attendances_employee_id_date_unique` is a plain UNIQUE constraint on
     * (employee_id, date) — NOT partial on `deleted_at IS NULL` — so an
     * ARCHIVED row still occupies that employee-day. Without this check the
     * INSERT below raised `UniqueConstraintViolationException`, which is
     * unmapped and therefore reached the client as a 500 carrying the whole
     * statement. The archive action is exposed in the SPA, so this is a normal
     * operator sequence, not a corner case.
     *
     * Restoring is deliberately left to the operator: un-archiving a day has
     * payroll consequences and must be an explicit decision, not an implicit
     * consequence of re-entering the same date. `Leave` already reads this
     * table through `withTrashed()` for the same reason
     * (LeaveRequestService::markAttendance()).
     */
    private function assertDayNotArchived(int $employeeId, string $date): void
    {
        $archived = Attendance::onlyTrashed()
            ->where('employee_id', $employeeId)
            ->where('date', $date)
            ->lockForUpdate()
            ->exists();

        if ($archived) {
            throw new BusinessRuleException(sprintf(
                'An archived attendance record already exists for %s. Restore that record and correct it instead of adding a second one.',
                $date,
            ));
        }
    }

    public function update(Attendance $a, array $data): Attendance
    {
        return DB::transaction(function () use ($a, $data) {
            $this->mutability->assertMutable((int) $a->employee_id, (string) $a->date);
            $authoritative = Attendance::query()
                ->lockForUpdate()
                ->findOrFail($a->id);
            $authoritative->update($data);
            $authoritative = $this->dtr->computeForRecord($authoritative);
            $authoritative->save();
            $this->overtime()->autoDetectFromAttendance($authoritative);
            return $authoritative->fresh(['employee', 'employee.department', 'shift']);
        });
    }

    public function delete(Attendance $a): void
    {
        DB::transaction(function () use ($a): void {
            $this->mutability->assertMutable((int) $a->employee_id, (string) $a->date);
            $authoritative = Attendance::query()
                ->lockForUpdate()
                ->findOrFail($a->id);
            $authoritative->delete();
        });
    }

    public function restore(Attendance $a): void
    {
        DB::transaction(function () use ($a): void {
            $this->mutability->assertMutable((int) $a->employee_id, (string) $a->date);
            $authoritative = Attendance::withTrashed()
                ->lockForUpdate()
                ->findOrFail($a->id);
            $authoritative->restore();
        });
    }

    public function recomputeForEmployeeOnDate(int $employeeId, string $date): ?Attendance
    {
        return DB::transaction(function () use ($employeeId, $date): ?Attendance {
            $this->mutability->assertMutable($employeeId, $date);
            $a = Attendance::query()
                ->with(['employee', 'shift'])
                ->where('employee_id', $employeeId)
                ->where('date', $date)
                ->lockForUpdate()
                ->first();
            if (! $a) return null;
            $a = $this->dtr->computeForRecord($a);
            $a->save();
            $this->overtime()->autoDetectFromAttendance($a);
            return $a;
        });
    }
}
