<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Shift;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ShiftService
{
    private const DTR_INPUT_FIELDS = [
        'start_time', 'end_time', 'break_minutes', 'grace_minutes',
        'is_night_shift', 'is_extended', 'auto_ot_hours',
    ];

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Shift::query();
        TrashedFilter::apply($q, $filters);
        if (! empty($filters['search'])) {
            $q->where('name', SearchOperator::like(), SearchOperator::contains($filters['search']));
        }
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
            $this->lockAffectedEmployees($shift);
            $locked = Shift::query()->lockForUpdate()->findOrFail($shift->getKey());
            $recomputeDtr = array_intersect(array_keys($data), self::DTR_INPUT_FIELDS) !== [];
            $this->assertDistinctTimes(
                $data['start_time'] ?? $locked->start_time,
                $data['end_time'] ?? $locked->end_time,
            );

            if (($data['is_default'] ?? null) === true) {
                Shift::query()->whereKeyNot($locked->getKey())->where('is_default', true)->update(['is_default' => false]);
                $data['is_active'] = true;
            }

            $willDeactivate = array_key_exists('is_active', $data)
                && ! filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            if ($willDeactivate && $locked->is_active && $locked->assignments()
                ->where(function ($query): void {
                    $query->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString());
                })->exists()) {
                throw new BusinessRuleException('Cannot deactivate a shift with current or future employee assignments.');
            }

            if ($locked->is_default && (($data['is_default'] ?? true) === false || ($data['is_active'] ?? true) === false)) {
                throw new BusinessRuleException('Choose another default shift before disabling or unmarking this one.');
            }

            $locked->fill($data);
            $rulesChanged = $locked->isDirty(self::DTR_INPUT_FIELDS);
            $locked->save();
            if ($rulesChanged) {
                $employeeIds = $locked->assignments()->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
                app(WorkOrderAssignmentService::class)->assertCalendarChangesSafe($employeeIds);
                if (Attendance::withTrashed()->where('shift_id', $locked->id)->whereHas('punches')->exists()) {
                    throw new BusinessRuleException('This shift has recorded punch history. Create a new shift and assign it from a future date.');
                }
                $history = Attendance::withTrashed()->where('shift_id', $locked->id)->whereHas('employee')
                    ->whereExists(fn ($query) => $query->selectRaw('1')->from('payroll_periods')
                        ->whereIn('status', ['processing', 'computed', 'approved', 'finalized', 'disbursed'])
                        ->whereColumn('period_start', '<=', 'attendances.date')->whereColumn('period_end', '>=', 'attendances.date'))->cursor();
                foreach ($history as $attendance) {
                    if (! app(AttendanceDateMutabilityGuard::class)->isMutable((int) $attendance->employee_id, $attendance->date->toDateString())) {
                        throw new BusinessRuleException('This shift has recorded punch or locked payroll history. Create a new shift and assign it from a future date.');
                    }
                }
            }

            if ($recomputeDtr) {
                $attendanceDays = Attendance::query()
                    ->where('shift_id', $locked->getKey())
                    ->whereHas('employee')
                    ->get(['employee_id', 'date']);
                foreach ($attendanceDays as $attendance) {
                    if (! app(AttendanceDateMutabilityGuard::class)->isMutable(
                        (int) $attendance->employee_id,
                        $attendance->date->toDateString(),
                    )) {
                        continue;
                    }

                    app(AttendanceService::class)->recomputeForEmployeeOnDate(
                        (int) $attendance->employee_id,
                        $attendance->date->toDateString(),
                    );
                }
            }

            if ($rulesChanged) {
                foreach ($employeeIds as $employeeId) {
                    app(WorkOrderAssignmentService::class)->notifyStaffingChange($employeeId, 'shift-'.$locked->id.'-'.sha1(json_encode($data)), 'HR shift hours changed. Review the saved WO crew and schedule.');
                }
            }

            return $locked->fresh();
        });
    }

    public function restore(Shift $shift): void
    {
        DB::transaction(function () use ($shift): void {
            $this->lockAffectedEmployees($shift);
            $locked = Shift::withTrashed()->lockForUpdate()->findOrFail($shift->getKey());
            $locked->restore();
        });
    }

    public function delete(Shift $shift): void
    {
        DB::transaction(function () use ($shift): void {
            $this->lockAffectedEmployees($shift);
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

    private function lockAffectedEmployees(Shift $shift): void
    {
        Employee::whereIn('id', $shift->assignments()->select('employee_id'))
            ->orWhereIn('id', Attendance::where('shift_id', $shift->id)->select('employee_id'))
            ->orderBy('id')->lockForUpdate()->get();
    }

    private function assertDistinctTimes(mixed $start, mixed $end): void
    {
        if (substr((string) $start, 0, 5) === substr((string) $end, 0, 5)) {
            throw new BusinessRuleException('End time cannot be the same as start time.');
        }
    }
}
