<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Services\SettingsService;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Exceptions\PunchRejectedException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Support\GeoDistance;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Geofenced self-service time in/out.
 *
 * Every accepted punch is logged in attendance_punches and folded into the
 * employee-day's attendances row (first in wins, last out wins) through the
 * same DTR engine the biometric import uses, so payroll is untouched.
 */
class PunchService
{
    public const COOLDOWN_SECONDS = 60;
    public const OPEN_LOOKBACK_HOURS = 16;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ShiftAssignmentService $shifts,
        private readonly DTRComputationService $dtr,
        private readonly OvertimeService $overtime,
        private readonly AttendanceDateMutabilityGuard $mutability,
        private readonly HolidayService $holidays,
    ) {}

    public function punch(
        Employee $employee,
        float $lat,
        float $lng,
        float $accuracyM,
        ?string $ip,
        ?string $userAgent,
        ?CarbonImmutable $now = null,
    ): AttendancePunch {
        $now ??= CarbonImmutable::now();
        $distance = $this->assertInRange($lat, $lng, $accuracyM);

        return DB::transaction(function () use ($employee, $lat, $lng, $accuracyM, $ip, $userAgent, $now, $distance): AttendancePunch {
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();

            $last = $this->lastPunch($employee->id);
            if ($last !== null && $last->punched_at->greaterThan($now->subSeconds(self::COOLDOWN_SECONDS))) {
                $wait = max(1, self::COOLDOWN_SECONDS - abs($now->getTimestamp() - $last->punched_at->getTimestamp()));
                throw new PunchRejectedException('too_soon', sprintf(
                    'You just timed %s. Please wait %d more second%s before your next punch.',
                    $last->direction->value,
                    $wait,
                    $wait === 1 ? '' : 's',
                ));
            }

            $open = $this->isOpen($last, $now);
            $direction = $open ? PunchDirection::Out : PunchDirection::In;

            $shift = $this->resolveShift($employee, $now)
                ?? throw new PunchRejectedException('no_shift', 'No shift is assigned to you. Ask HR to configure a shift.');
            $anchor = $this->windowDate($shift, $now)
                ?? throw $this->outsideWindow($shift, $now);

            if ($direction === PunchDirection::In && ! in_array($employee->status, [EmployeeStatus::Active, EmployeeStatus::OnLeave], true)) {
                throw new PunchRejectedException('not_active', 'Your employment record is not active, so you cannot time in. Contact HR.');
            }

            $row = $open
                ? Attendance::withTrashed()->lockForUpdate()->find($last->attendance_id)
                : Attendance::withTrashed()->lockForUpdate()
                    ->where('employee_id', $employee->id)->where('date', $anchor->toDateString())->first();

            if ($row !== null) {
                $this->guardRow($row);
            } elseif ($open) {
                throw new PunchRejectedException('row_removed', 'Your attendance record for this shift was removed. Contact HR.');
            }

            $nonWorking = $this->isNonWorkingDay($employee, $anchor);
            if ($direction === PunchDirection::In) {
                if ($nonWorking && ! $this->hasApprovedOvertime($employee->id, $anchor)) {
                    throw new PunchRejectedException('work_not_authorized', 'Rest-day and holiday work needs an approved overtime request. File one, then try again once it is approved.');
                }
                if (! $nonWorking && ($row === null || $row->time_in === null)) {
                    $this->assertNotTooLateIn($shift, $anchor, $now);
                }
            }

            $date = $row?->date->toDateString() ?? $anchor->toDateString();
            $this->mutability->assertMutable($employee->id, $date);

            $a = $row ?? new Attendance(['employee_id' => $employee->id, 'date' => $date]);
            if ($direction === PunchDirection::In) {
                $a->time_in = $a->time_in !== null && $a->time_in->lessThan($now) ? $a->time_in : $now;
            } else {
                if ($a->time_in !== null && $now->lessThanOrEqualTo($a->time_in)) {
                    throw new PunchRejectedException('out_before_in', 'Time out must be after your time in.');
                }
                $rowDay = CarbonImmutable::parse($a->date->toDateString());
                $limit = $this->earliestOut($shift, $rowDay, $last->punched_at->toImmutable(), $this->isNonWorkingDay($employee, $rowDay));
                if ($now->lessThan($limit['at'])) {
                    $time = $limit['at']->format('g:i A');
                    $minWorkMinutes = $this->settings->requiredInt('attendance.punch_min_work_minutes', 0, 240);
                    throw new PunchRejectedException(
                        $limit['reason'],
                        $limit['reason'] === 'min_work'
                            ? "Wait until {$time} to time out (minimum {$minWorkMinutes} minutes of work)."
                            : "You can time out from {$time}. For an earlier exit, ask HR to record it.",
                    );
                }
                $a->time_out = $a->time_out !== null && $a->time_out->greaterThan($now) ? $a->time_out : $now;
            }

            $a = $this->dtr->computeForRecord($a);
            $a->save();
            $this->overtime->autoDetectFromAttendance($a);

            return AttendancePunch::create([
                'employee_id' => $employee->id,
                'attendance_id' => $a->id,
                'direction' => $direction,
                'punched_at' => $now,
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy_m' => $accuracyM,
                'distance_m' => $distance,
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 255, '') : null,
            ]);
        });
    }

    /** @return array{next_action:string, shift:?array{name:string,start:string,end:string}, punches:list<array{direction:string,punched_at:string}>, tardiness_minutes:?int, radius_m:float, earliest_out:?string} */
    public function status(Employee $employee, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $last = $this->lastPunch($employee->id);
        $open = $this->isOpen($last, $now);
        $shift = $this->resolveShift($employee, $now);

        $row = $open
            ? Attendance::query()->find($last->attendance_id)
            : Attendance::query()->where('employee_id', $employee->id)->where('date', $now->toDateString())->first();

        $punches = AttendancePunch::query()
            ->where('employee_id', $employee->id)
            ->where('punched_at', '>=', $now->subHours(self::OPEN_LOOKBACK_HOURS))
            ->orderBy('punched_at')->orderBy('id')
            ->get()
            ->map(fn (AttendancePunch $p): array => [
                'direction' => $p->direction->value,
                'punched_at' => $p->punched_at->toIso8601String(),
            ])->all();

        $earliestOut = null;
        if ($open && $row !== null && $shift !== null) {
            $rowDay = CarbonImmutable::parse($row->date->toDateString());
            $earliestOut = $this->earliestOut($shift, $rowDay, $last->punched_at->toImmutable(), $this->isNonWorkingDay($employee, $rowDay))['at']->toIso8601String();
        }

        return [
            'next_action' => $open ? PunchDirection::Out->value : PunchDirection::In->value,
            'shift' => $shift ? [
                'name' => $shift->name,
                'start' => substr((string) $shift->start_time, 0, 5),
                'end' => substr((string) $shift->end_time, 0, 5),
            ] : null,
            'punches' => $punches,
            'tardiness_minutes' => $row !== null ? (int) $row->tardiness_minutes : null,
            'radius_m' => $this->settings->requiredFloat('company.punch_radius_m', 1, 5000),
            'earliest_out' => $earliestOut,
        ];
    }

    private function assertInRange(float $lat, float $lng, float $accuracyM): float
    {
        $cap = $this->settings->requiredFloat('company.punch_max_accuracy_m', 1, 1000);
        $radius = $this->settings->requiredFloat('company.punch_radius_m', 1, 5000);
        $centreLat = $this->settings->requiredFloat('company.latitude', -90, 90);
        $centreLng = $this->settings->requiredFloat('company.longitude', -180, 180);

        if ($accuracyM > $cap) {
            throw new PunchRejectedException('low_accuracy', sprintf('GPS signal is too weak (±%d m). Move to an open area and try again.', (int) round($accuracyM)));
        }
        $distance = GeoDistance::meters($lat, $lng, $centreLat, $centreLng);
        if ($distance > $radius) {
            throw new PunchRejectedException('out_of_range', sprintf('You are %d m from the plant. Time in/out works only within %d m.', (int) round($distance), (int) round($radius)));
        }

        return round($distance, 2);
    }

    private function lastPunch(int $employeeId): ?AttendancePunch
    {
        return AttendancePunch::query()
            ->where('employee_id', $employeeId)
            ->orderByDesc('punched_at')->orderByDesc('id')
            ->first();
    }

    /** True when the last punch was an `in` recent enough to still be this shift. */
    private function isOpen(?AttendancePunch $last, CarbonImmutable $now): bool
    {
        return $last !== null
            && $last->direction === PunchDirection::In
            && $last->punched_at->greaterThanOrEqualTo($now->subHours(self::OPEN_LOOKBACK_HOURS));
    }

    private function resolveShift(Employee $employee, CarbonImmutable $now): ?Shift
    {
        return $this->shifts->current($employee, $now)
            ?? Shift::query()->where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * The date of the shift instance whose punch window contains $now, or null.
     * Today's instance is tried before yesterday's (a night shift that started
     * yesterday ends this morning). ponytail: yesterday's instance reuses
     * today's resolved shift; per-day reassignment across midnight is ignored.
     */
    private function windowDate(Shift $shift, CarbonImmutable $now): ?CarbonImmutable
    {
        $early = $this->settings->requiredInt('attendance.punch_early_minutes', 0, 720);
        $late = $this->settings->requiredInt('attendance.punch_late_minutes', 0, 720);

        foreach ([0, -1] as $offset) {
            $day = $now->startOfDay()->addDays($offset);
            [$start, $end] = $this->shiftInstance($shift, $day);
            if ($now->betweenIncluded($start->subMinutes($early), $end->addMinutes($late))) {
                return $day;
            }
        }

        return null;
    }

    /** Before today's window: say when it opens. Otherwise the generic message. */
    private function outsideWindow(Shift $shift, CarbonImmutable $now): PunchRejectedException
    {
        $early = $this->settings->requiredInt('attendance.punch_early_minutes', 0, 720);
        [$start] = $this->shiftInstance($shift, $now->startOfDay());
        if ($now->lessThan($start->subMinutes($early))) {
            return new PunchRejectedException('too_early_in', sprintf(
                'Your shift starts at %s. You can time in from %s.',
                $start->format('g:i A'),
                $start->subMinutes($early)->format('g:i A'),
            ));
        }

        return new PunchRejectedException('outside_window', 'You can only time in/out close to your shift hours. See HR if you worked outside them.');
    }

    private function guardRow(Attendance $row): void
    {
        if ($row->trashed()) {
            throw new PunchRejectedException('row_removed', 'Your attendance record for this day was removed. Contact HR.');
        }
        if ($row->status === AttendanceStatus::OnLeave) {
            throw new PunchRejectedException('on_leave', 'You have approved leave on this day, so you cannot time in/out.');
        }
        if ($row->is_manual_entry) {
            throw new PunchRejectedException('manual_entry', 'HR corrected this day\'s attendance. Contact HR to change it.');
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} shift start/end for the shift instance beginning on $day */
    private function shiftInstance(Shift $shift, CarbonImmutable $day): array
    {
        $start = $day->setTimeFromTimeString((string) $shift->start_time);
        $end = $day->setTimeFromTimeString((string) $shift->end_time);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    private function isNonWorkingDay(Employee $employee, CarbonImmutable $day): bool
    {
        return ! $this->shifts->isWorkingDay($employee, $day) || $this->holidays->forDate($day) !== null;
    }

    private function hasApprovedOvertime(int $employeeId, CarbonImmutable $day): bool
    {
        return OvertimeRequest::query()
            ->where('employee_id', $employeeId)
            ->whereDate('date', $day->toDateString())
            ->where('status', OvertimeStatus::Approved->value)
            ->exists();
    }

    private function assertNotTooLateIn(Shift $shift, CarbonImmutable $anchor, CarbonImmutable $now): void
    {
        $cutoff = $this->settings->requiredInt('attendance.punch_late_in_cutoff_minutes', 30, 720);
        [$start] = $this->shiftInstance($shift, $anchor);
        if ($now->greaterThan($start->addMinutes($cutoff))) {
            throw new PunchRejectedException('too_late_in', 'It is too late to time in for this shift. Ask HR to record your attendance.');
        }
    }

    /**
     * Earliest moment an OUT is allowed, and which rule sets it.
     * Authorized non-working-day work is bounded only by minimum work; a normal
     * shift also cannot end before `shift end − grace`. The minimum counts from the
     * LATEST IN punch so a post-shift overtime cycle is measured from its own start.
     *
     * @return array{at: CarbonImmutable, reason: string}
     */
    private function earliestOut(Shift $shift, CarbonImmutable $rowDay, CarbonImmutable $latestIn, bool $nonWorking): array
    {
        $minWorkAt = $latestIn->addMinutes($this->settings->requiredInt('attendance.punch_min_work_minutes', 0, 240));
        if ($nonWorking) {
            return ['at' => $minWorkAt, 'reason' => 'min_work'];
        }

        [, $end] = $this->shiftInstance($shift, $rowDay);
        $shiftAt = $end->subMinutes($this->settings->requiredInt('attendance.punch_early_out_grace_minutes', 0, 120));

        return $shiftAt->greaterThan($minWorkAt)
            ? ['at' => $shiftAt, 'reason' => 'too_early_out']
            : ['at' => $minWorkAt, 'reason' => 'min_work'];
    }
}
