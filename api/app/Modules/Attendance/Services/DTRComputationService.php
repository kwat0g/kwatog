<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Enums\HolidayType;
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\Shift;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Daily Time Record (DTR) computation engine.
 *
 * Encodes Philippine labor law rules for:
 *   - Regular / OT / night-differential hour buckets.
 *   - Tardiness and undertime.
 *   - 14 holiday/rest-day combinations → day_type_rate (multiplier on basic rate
 *     for regular hours; OT/ND multipliers are layered on by payroll later).
 *
 * Pure-function entry point `compute()` is fully testable; the model-aware
 * `computeForRecord()` resolves shift, holiday, and OT-approval from the DB.
 */
class DTRComputationService
{
    private const NIGHT_BAND_START_HOUR = 22; // 22:00
    private const NIGHT_BAND_END_HOUR   = 6;  // 06:00 next day

    public function __construct(
        private readonly ShiftAssignmentService $shiftAssignments,
        private readonly HolidayService $holidays,
    ) {}

    /**
     * Compute against a persisted Attendance row, resolving shift/holiday/OT-approval.
     */
    public function computeForRecord(Attendance $a): Attendance
    {
        $date = $a->date instanceof CarbonInterface ? $a->date : Carbon::parse((string) $a->date);

        $shift = $a->shift_id ? Shift::find($a->shift_id) : $this->resolveShift($a);
        if (! $shift) {
            // Default fallback — Day Shift 06:00-14:00, 30 min break.
            $shift = $this->fallbackShift();
        } else {
            // Make sure relation is set if we resolved it ourselves.
            if (! $a->shift_id) {
                $a->shift_id = $shift->id;
            }
        }

        $holiday = $this->holidays->forDate($date);

        $isRestDay = (bool) $a->is_rest_day || $date->dayOfWeek === Carbon::SUNDAY;

        $hasApprovedOt = $a->time_out
            ? OvertimeRequest::query()
                ->where('employee_id', $a->employee_id)
                ->where('date', $date->toDateString())
                ->where('status', OvertimeStatus::Approved->value)
                ->exists()
            : false;

        $result = $this->compute([
            'date'           => $date->toDateString(),
            'time_in'        => optional($a->time_in)->toDateTimeString(),
            'time_out'       => optional($a->time_out)->toDateTimeString(),
            'shift'          => [
                'start_time'     => $this->normalizeTime($shift->start_time),
                'end_time'       => $this->normalizeTime($shift->end_time),
                'break_minutes'  => (int) $shift->break_minutes,
                'is_night_shift' => (bool) $shift->is_night_shift,
                'is_extended'    => (bool) $shift->is_extended,
                'auto_ot_hours'  => $shift->auto_ot_hours !== null ? (float) $shift->auto_ot_hours : null,
            ],
            'holiday'        => $holiday ? ['type' => $holiday->type->value] : null,
            'is_rest_day'    => $isRestDay,
            'has_approved_ot'=> $hasApprovedOt,
        ]);

        // Apply result back onto the model.
        $a->regular_hours      = $result['regular_hours'];
        $a->overtime_hours     = $result['overtime_hours'];
        $a->night_diff_hours   = $result['night_diff_hours'];
        $a->tardiness_minutes  = $result['tardiness_minutes'];
        $a->undertime_minutes  = $result['undertime_minutes'];
        $a->holiday_type       = $result['holiday_type'];
        $a->is_rest_day        = $result['is_rest_day'];
        $a->day_type_rate      = $result['day_type_rate'];
        $a->status             = $result['status'];

        return $a;
    }

    /**
     * Pure computation. Throws on data errors (sentinel for callers to log).
     *
     * @param array{
     *   date: string,
     *   time_in: ?string,
     *   time_out: ?string,
     *   shift: array{start_time:string,end_time:string,break_minutes:int,is_night_shift:bool,is_extended:bool,auto_ot_hours:?float},
     *   holiday: ?array{type:string},
     *   is_rest_day: bool,
     *   has_approved_ot: bool,
     * } $input
     *
     * @return array{
     *   regular_hours: float,
     *   overtime_hours: float,
     *   night_diff_hours: float,
     *   tardiness_minutes: int,
     *   undertime_minutes: int,
     *   holiday_type: ?string,
     *   is_rest_day: bool,
     *   day_type_rate: float,
     *   status: string,
     * }
     */
    public function compute(array $input): array
    {
        $date  = CarbonImmutable::parse($input['date']);
        $shift = $input['shift'];
        $holiday = $input['holiday'];
        $isRestDay = (bool) $input['is_rest_day'];
        $hasApprovedOt = (bool) $input['has_approved_ot'];

        $shiftStart = $this->shiftAnchor($date, $shift['start_time'], false);
        $shiftEnd   = $this->shiftAnchor($date, $shift['end_time'], $shift['is_night_shift']);
        if ($shift['is_night_shift'] && $shiftEnd->lte($shiftStart)) {
            $shiftEnd = $shiftEnd->addDay();
        } elseif (! $shift['is_night_shift'] && $shiftEnd->lte($shiftStart)) {
            // Defensive: end before start on a non-night shift means overnight day shift (rare); roll forward.
            $shiftEnd = $shiftEnd->addDay();
        }
        $scheduledMin = max(0, $shiftStart->diffInMinutes($shiftEnd) - $shift['break_minutes']);

        // ── No time_in: didn't work today.
        if (empty($input['time_in'])) {
            return $this->notWorked($holiday, $isRestDay);
        }

        $timeIn = CarbonImmutable::parse($input['time_in']);

        // ── No time_out: still on the clock; only tardiness applies.
        if (empty($input['time_out'])) {
            $tardyMin = $timeIn->gt($shiftStart) ? min(480, $shiftStart->diffInMinutes($timeIn)) : 0;
            return [
                'regular_hours'      => 0.00,
                'overtime_hours'     => 0.00,
                'night_diff_hours'   => 0.00,
                'tardiness_minutes'  => $tardyMin,
                'undertime_minutes'  => 0,
                'holiday_type'       => $holiday['type'] ?? null,
                'is_rest_day'        => $isRestDay,
                'day_type_rate'      => $this->dayTypeRate($holiday, $isRestDay, worked: false),
                'status'             => AttendanceStatus::Present->value,
            ];
        }

        $timeOut = CarbonImmutable::parse($input['time_out']);

        // Cross-midnight: night shift may have time_out on the next day.
        if ($timeOut->lte($timeIn)) {
            if ($shift['is_night_shift']) {
                $timeOut = $timeOut->addDay();
            } else {
                throw new InvalidArgumentException("time_out ({$timeOut}) is not after time_in ({$timeIn}).");
            }
        }

        $totalMin  = $timeIn->diffInMinutes($timeOut);
        $workedMin = max(0, $totalMin - $shift['break_minutes']);

        // Tardiness — late arrival vs scheduled start.
        $tardyMin = $timeIn->gt($shiftStart) ? min(480, $shiftStart->diffInMinutes($timeIn)) : 0;

        // Undertime — left before scheduled end (only when worked < scheduled). Extended OT period
        // does not contribute to undertime (we measure relative to shift_end).
        $undertimeMin = $timeOut->lt($shiftEnd) ? max(0, $timeOut->diffInMinutes($shiftEnd)) : 0;

        // Regular vs overtime split.
        // We compute the overlap of [timeIn, timeOut] with [shiftStart, shiftEnd] minus break,
        // then any excess outside that window is potential OT.
        $overlap   = $this->minutesIntersection($timeIn, $timeOut, $shiftStart, $shiftEnd);
        $regularMin = max(0, $overlap - $shift['break_minutes']);
        $regularMin = min($regularMin, $scheduledMin);

        // Excess minutes outside the scheduled window.
        $excess = $workedMin - $regularMin;
        $excess = max(0, $excess);

        $otMin = 0;
        if ($shift['is_extended']) {
            $autoOtHours = $shift['auto_ot_hours'] ?? 0.0;
            $autoOtMin   = (int) round($autoOtHours * 60);
            $otMin = min($excess, $autoOtMin);
        } elseif ($hasApprovedOt) {
            // Min 30 minutes; cap at 4 hours (240 min). Rounded down to whole minutes.
            if ($excess >= 30) {
                $otMin = min($excess, 240);
            }
        }

        // Night differential — minutes worked between 22:00 and 06:00 (next day).
        $ndMin = $this->nightDiffMinutes($timeIn, $timeOut);

        $rate = $this->dayTypeRate($holiday, $isRestDay, worked: true);

        $status = $this->resolveStatus(
            holiday: $holiday,
            isRestDay: $isRestDay,
            workedMin: $workedMin,
            scheduledMin: $scheduledMin,
            tardyMin: $tardyMin,
        );

        return [
            'regular_hours'      => round($regularMin / 60, 2),
            'overtime_hours'     => round($otMin / 60, 2),
            'night_diff_hours'   => round($ndMin / 60, 2),
            'tardiness_minutes'  => $tardyMin,
            'undertime_minutes'  => $undertimeMin,
            'holiday_type'       => $holiday['type'] ?? null,
            'is_rest_day'        => $isRestDay,
            'day_type_rate'      => $rate,
            'status'             => $status,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function notWorked(?array $holiday, bool $isRestDay): array
    {
        if ($holiday !== null) {
            $isRegular = $holiday['type'] === HolidayType::Regular->value;
            return [
                'regular_hours'      => 0.00,
                'overtime_hours'     => 0.00,
                'night_diff_hours'   => 0.00,
                'tardiness_minutes'  => 0,
                'undertime_minutes'  => 0,
                'holiday_type'       => $holiday['type'],
                'is_rest_day'        => $isRestDay,
                // Regular holiday not worked = paid (1.00). Special non-working not worked = no work no pay (0.00).
                'day_type_rate'      => $isRegular ? 1.00 : 0.00,
                'status'             => AttendanceStatus::Holiday->value,
            ];
        }
        if ($isRestDay) {
            return [
                'regular_hours'      => 0.00,
                'overtime_hours'     => 0.00,
                'night_diff_hours'   => 0.00,
                'tardiness_minutes'  => 0,
                'undertime_minutes'  => 0,
                'holiday_type'       => null,
                'is_rest_day'        => true,
                'day_type_rate'      => 0.00,
                'status'             => AttendanceStatus::RestDay->value,
            ];
        }
        return [
            'regular_hours'      => 0.00,
            'overtime_hours'     => 0.00,
            'night_diff_hours'   => 0.00,
            'tardiness_minutes'  => 0,
            'undertime_minutes'  => 0,
            'holiday_type'       => null,
            'is_rest_day'        => false,
            'day_type_rate'      => 1.00,
            'status'             => AttendanceStatus::Absent->value,
        ];
    }

    /**
     * day_type_rate is the multiplier applied by payroll to the *regular* portion of basic pay.
     * OT premium and night-differential premium are layered on TOP of this by the payroll engine.
     */
    private function dayTypeRate(?array $holiday, bool $isRestDay, bool $worked): float
    {
        $type = $holiday['type'] ?? null;

        if ($type === HolidayType::Regular->value) {
            if (! $worked) return 1.00;             // paid even when not worked
            return $isRestDay ? 2.60 : 2.00;
        }
        if ($type === HolidayType::SpecialNonWorking->value) {
            if (! $worked) return 0.00;             // no work no pay
            return $isRestDay ? 1.50 : 1.30;
        }
        if ($isRestDay) {
            return $worked ? 1.30 : 0.00;
        }
        return 1.00;
    }

    private function resolveStatus(
        ?array $holiday,
        bool $isRestDay,
        int $workedMin,
        int $scheduledMin,
        int $tardyMin,
    ): string {
        if ($workedMin <= 0) {
            if ($holiday !== null) return AttendanceStatus::Holiday->value;
            if ($isRestDay) return AttendanceStatus::RestDay->value;
            return AttendanceStatus::Absent->value;
        }
        if ($scheduledMin > 0 && $workedMin < ($scheduledMin / 2)) {
            return AttendanceStatus::Halfday->value;
        }
        if ($tardyMin > 0) {
            return AttendanceStatus::Late->value;
        }
        return AttendanceStatus::Present->value;
    }

    private function minutesIntersection(
        CarbonInterface $aStart,
        CarbonInterface $aEnd,
        CarbonInterface $bStart,
        CarbonInterface $bEnd,
    ): int {
        $start = $aStart->gt($bStart) ? $aStart : $bStart;
        $end   = $aEnd->lt($bEnd) ? $aEnd : $bEnd;
        if ($end->lte($start)) return 0;
        return $start->diffInMinutes($end);
    }

    /**
     * Sum the minutes of [in,out] that fall between 22:00 and 06:00 (next day).
     * Inclusive at start, exclusive at end (standard time-window semantics).
     */
    private function nightDiffMinutes(CarbonInterface $in, CarbonInterface $out): int
    {
        $startDate = $in->copy()->startOfDay();
        $endDate   = $out->copy()->startOfDay();

        $minutes = 0;
        for ($d = $startDate; $d->lte($endDate); $d = $d->addDay()) {
            // Two bands per day: previous-night band (00:00-06:00 of $d) and current-night band ($d 22:00 - $d+1 06:00).
            // The "previous-night" band of date X is the same as "current-night" of date X-1, so we only count current.
            $bandStart = $d->copy()->setTime(self::NIGHT_BAND_START_HOUR, 0);
            $bandEnd   = $d->copy()->addDay()->setTime(self::NIGHT_BAND_END_HOUR, 0);
            $minutes += $this->minutesIntersection($in, $out, $bandStart, $bandEnd);
        }

        // Also account for the early-morning portion of the *first* day (00:00-06:00 of startDate).
        $earlyStart = $startDate->copy()->setTime(0, 0);
        $earlyEnd   = $startDate->copy()->setTime(self::NIGHT_BAND_END_HOUR, 0);
        $minutes += $this->minutesIntersection($in, $out, $earlyStart, $earlyEnd);

        return $minutes;
    }

    private function shiftAnchor(CarbonImmutable $date, string $hhmm, bool $isNight): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm) + [1 => 0]);
        return $date->setTime($h, $m, 0);
    }

    private function normalizeTime(string|CarbonInterface|null $value): string
    {
        if ($value === null) return '00:00';
        if ($value instanceof CarbonInterface) return $value->format('H:i');
        // DB may return "HH:MM:SS"; trim seconds.
        return substr($value, 0, 5);
    }

    private function resolveShift(Attendance $a): ?Shift
    {
        $date = $a->date instanceof CarbonInterface ? $a->date : Carbon::parse((string) $a->date);
        if (! $a->employee) return null;
        return $this->shiftAssignments->current($a->employee, $date);
    }

    private function fallbackShift(): Shift
    {
        return new Shift([
            'name'           => 'Default',
            'start_time'     => '08:00',
            'end_time'       => '17:00',
            'break_minutes'  => 60,
            'is_night_shift' => false,
            'is_extended'    => false,
            'auto_ot_hours'  => null,
        ]);
    }
}
