<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Holiday;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class EmployeeAvailabilityService
{
    public function shiftInterval(Employee $employee, Carbon $at, bool $allowHoliday = false): ?array
    {
        $at = $at->copy()->timezone(config('app.timezone'));
        foreach ([$at->copy()->startOfDay()->subDay(), $at->copy()->startOfDay()] as $date) {
            $assignment = app(ShiftAssignmentService::class)->assignmentFor($employee, $date);
            $shift = $assignment?->shift;
            if (! $shift?->is_active || ! is_array($assignment->working_days)
                || ! in_array($date->dayOfWeekIso, $assignment->working_days, true)) {
                continue;
            }
            if (! $allowHoliday && $this->isHoliday($date, $employee->getRelationValue('productionHolidays'))) {
                continue;
            }
            $start = Carbon::parse($date->toDateString().' '.$shift->start_time, config('app.timezone'));
            $end = Carbon::parse($date->toDateString().' '.$shift->end_time, config('app.timezone'));
            if ($end->lte($start)) {
                $end->addDay();
            }
            if ($start->lte($at) && $at->lt($end)) {
                return ['start' => $start, 'end' => $end, 'work_date' => $date->toDateString(), 'shift_id' => $shift->id];
            }
        }

        return null;
    }

    public function isHoliday(Carbon $date, ?Collection $holidays = null): bool
    {
        if ($holidays !== null) {
            return $holidays->contains(fn ($holiday) => $holiday->date->toDateString() === $date->toDateString()
                || ($holiday->is_recurring && $holiday->date->format('m-d') === $date->format('m-d')));
        }

        return Holiday::query()->whereDate('date', $date->toDateString())->exists()
            || Holiday::query()->where('is_recurring', true)->whereRaw("to_char(date, 'MM-DD') = ?", [$date->format('m-d')])->exists();
    }

    public function attendanceFor(Employee $employee, string $workDate): ?Attendance
    {
        return $employee->relationLoaded('attendances')
            ? $employee->attendances->first(fn ($attendance) => $attendance->date->toDateString() === $workDate)
            : Attendance::withTrashed()->where('employee_id', $employee->id)->whereDate('date', $workDate)->first();
    }

    public function clockedIn(?Attendance $attendance, Carbon $at): bool
    {
        if (! $attendance || $attendance->trashed() || ! $attendance->time_in || $attendance->time_in->gt($at)
            || in_array($attendance->status?->value, ['absent', 'on_leave'], true)) {
            return false;
        }
        if (! $attendance->time_out || $attendance->time_out->gt($at)) {
            return true;
        }
        if ($attendance->is_manual_entry) {
            return false;
        }
        $last = $attendance->relationLoaded('punches')
            ? $attendance->punches->filter(fn ($punch) => $punch->punched_at->lte($at))->sortByDesc(fn ($punch) => $punch->punched_at->format('Y-m-d H:i:s.u').sprintf('%020d', $punch->id))->first()
            : $attendance->punches()->where('punched_at', '<=', $at)->orderByDesc('punched_at')->orderByDesc('id')->first();

        return $last?->direction?->value === 'in' && $last->punched_at->gt($attendance->time_out);
    }

    public function presence(Employee $employee, Carbon $at): array
    {
        $at = $at->copy()->timezone(config('app.timezone'));
        $interval = $this->shiftInterval($employee, $at, true);
        $workDate = $interval['work_date'] ?? $at->toDateString();
        $attendance = $this->attendanceFor($employee, $workDate);
        if (! $interval && ! $attendance) {
            $previous = $this->attendanceFor($employee, $at->copy()->subDay()->toDateString());
            if ($this->clockedIn($previous, $at) && $previous->time_in->gte($at->copy()->subHours(16))) {
                $attendance = $previous;
            }
        }
        $clockedIn = $this->clockedIn($attendance, $at);
        $assignment = app(ShiftAssignmentService::class)->assignmentFor($employee, $at);
        $missingCalendar = ! $assignment || ! is_array($assignment->working_days) || $assignment->working_days === [];
        $result = ['state' => 'blocked', 'reason_code' => $missingCalendar ? 'missing_calendar' : 'shift_coverage',
            'reason' => $missingCalendar ? 'HR working days are missing. Ask HR to set a work calendar.'
                : ($clockedIn ? 'Clocked in, but HR shift hours do not cover this time.' : 'Outside HR shift hours or working days. Review the schedule.'),
            'clocked_in' => $clockedIn, 'time_in' => $attendance?->time_in?->toIso8601String(),
            'shift_start' => $interval ? $interval['start']->toIso8601String() : null,
            'shift_end' => $interval ? $interval['end']->toIso8601String() : null,
            'work_date' => $workDate];
        if (! $interval) {
            return $result;
        }
        if ($leave = $this->leaveBlocker($employee, $interval['work_date'], $at->copy()->addSecond())) {
            return array_replace($result, ['reason_code' => 'leave', 'reason' => $leave]);
        }
        if ($attendance?->trashed()) {
            return array_replace($result, ['reason_code' => 'archived', 'reason' => 'Attendance is archived. Ask HR to restore and review it.']);
        }
        if (in_array($attendance?->status?->value, ['absent', 'on_leave'], true)) {
            return array_replace($result, ['reason_code' => 'absence', 'reason' => 'HR recorded this worker as absent or on leave.']);
        }
        if ($attendance?->time_out && $attendance->time_out->lte($at) && ! $clockedIn) {
            return array_replace($result, ['reason_code' => 'clocked_out', 'reason' => 'Worker has clocked out.']);
        }
        if ($clockedIn && $attendance->time_in->gte($interval['start']->copy()->startOfDay())
            && (int) $attendance->shift_id === (int) $interval['shift_id']) {
            return array_replace($result, ['state' => 'present', 'reason_code' => null, 'reason' => null]);
        }
        if ($attendance?->time_in) {
            return array_replace($result, ['reason_code' => 'shift_mismatch', 'reason' => 'Punch-in does not match this shift and work date. Ask HR to review it.']);
        }

        return array_replace($result, ['state' => 'unknown', 'reason_code' => 'unverified', 'reason' => 'Attendance unverified. Confirm physical presence or change workers.']);
    }

    public function leaveBlocker(Employee $employee, string $workDate, Carbon $end): ?string
    {
        $leave = $employee->relationLoaded('approvedLeaves')
            ? $employee->approvedLeaves->first(fn ($leave) => $leave->start_date->toDateString() <= $end->copy()->subSecond()->toDateString()
                && $leave->end_date->toDateString() >= $workDate)
            : LeaveRequest::where('employee_id', $employee->id)->where('status', 'approved')
                ->whereDate('start_date', '<=', $end->copy()->subSecond()->toDateString())->whereDate('end_date', '>=', $workDate)->first();
        if (! $leave) {
            return null;
        }

        // AM/PM leave has no authoritative clock interval, especially for night shifts.
        return $leave->half_day_period !== null
            ? 'Approved half-day leave requires HR to confirm working hours before assignment.'
            : 'Approved leave from '.$leave->start_date->toDateString().' to '.$leave->end_date->toDateString().'.';
    }

    public function coverageBlocker(Employee $employee, Carbon $start, Carbon $end): ?string
    {
        $start = $start->copy()->timezone(config('app.timezone'));
        $end = $end->copy()->timezone(config('app.timezone'));
        if ($end->lte($start)) {
            return 'End must be after start.';
        }
        $interval = $this->shiftInterval($employee, $start);
        $leave = $this->leaveBlocker($employee, $interval['work_date'] ?? $start->toDateString(), $end);
        if ($leave) {
            return $leave;
        }
        if (! $interval || $interval['end']->lt($end)) {
            $assignment = app(ShiftAssignmentService::class)->assignmentFor($employee, $start);

            return ! $assignment || ! is_array($assignment->working_days) || $assignment->working_days === []
                ? 'HR working days are missing. Ask HR to set a work calendar.'
                : 'HR shift hours or working days do not cover this schedule. Overtime hours alone do not extend shift coverage.';
        }

        if ($interval) {
            if ($interval['start']->lte(now()) && $interval['end']->gt(now())) {
                $presence = $this->presence($employee, now());
                if ($presence['state'] === 'blocked') {
                    return $presence['reason'];
                }
            }
            $attendance = $this->attendanceFor($employee, $interval['work_date']);
            if ($attendance?->trashed()) {
                return 'Attendance is archived. Ask HR to restore and review it.';
            }
            if (in_array($attendance?->status?->value, ['absent', 'on_leave'], true)
                || ($attendance?->time_out && $attendance->time_out->lt($end) && ($attendance->time_out->gt(now()) || ! $this->clockedIn($attendance, now())))) {
                return 'HR recorded absence, leave or clock-out for this shift.';
            }
        }

        return null;
    }
}
