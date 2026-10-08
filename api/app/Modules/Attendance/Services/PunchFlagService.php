<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Services\SettingsService;
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\OvertimeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Review flags for HR on a day's app punches. Computed on read, never stored and
 * never used to refuse a punch: each is a signal for a person to look at, not proof.
 * Coordinates are purged after 90 days, so location flags only exist inside that window.
 */
class PunchFlagService
{
    public const SHARED_DEVICE = 'shared_device';
    public const REPEATED_LOCATION = 'repeated_location';
    public const IMPLAUSIBLE_ACCURACY = 'implausible_accuracy';
    public const UNAPPROVED_OVERTIME = 'unapproved_overtime';

    /** Real GPS jitters; the same 6-decimal point this many earlier times is a pasted location. */
    private const REPEAT_THRESHOLD = 3;
    private const REPEAT_LOOKBACK_DAYS = 14;
    /** A browser fix claiming better than this is not a real GPS reading. */
    private const MIN_REAL_ACCURACY_M = 1.0;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param Collection<int, AttendancePunch> $punches
     * @return array<int, list<string>> punch id => flag codes (only punches with a flag)
     */
    public function for(Attendance $attendance, Collection $punches): array
    {
        $shiftEnd = $this->shiftEnd($attendance);
        $hasApprovedOt = OvertimeRequest::query()
            ->where('employee_id', $attendance->employee_id)
            ->whereDate('date', $attendance->date->toDateString())
            ->where('status', OvertimeStatus::Approved->value)
            ->exists();
        $otMinimum = $this->settings->requiredInt('attendance.ot.minimum_minutes', 0);

        $out = [];
        foreach ($punches as $p) {
            $flags = [];
            if ($p->latitude !== null && $p->longitude !== null) {
                if ($this->sharedDevice($p)) {
                    $flags[] = self::SHARED_DEVICE;
                }
                if ($this->repeatedLocation($p)) {
                    $flags[] = self::REPEATED_LOCATION;
                }
            }
            if ((float) $p->accuracy_m < self::MIN_REAL_ACCURACY_M) {
                $flags[] = self::IMPLAUSIBLE_ACCURACY;
            }
            if ($p->direction === PunchDirection::Out && ! $hasApprovedOt && $shiftEnd !== null
                && $p->punched_at->greaterThan($shiftEnd->addMinutes($otMinimum))) {
                $flags[] = self::UNAPPROVED_OVERTIME;
            }
            if ($flags !== []) {
                $out[$p->id] = $flags;
            }
        }

        return $out;
    }

    /** Another employee punched from the same browser at the very same coordinates that day. */
    private function sharedDevice(AttendancePunch $p): bool
    {
        if ($p->user_agent === null) {
            return false;
        }

        return AttendancePunch::query()
            ->where('employee_id', '!=', $p->employee_id)
            ->where('latitude', $p->latitude)
            ->where('longitude', $p->longitude)
            ->where('user_agent', $p->user_agent)
            ->whereBetween('punched_at', [$p->punched_at->startOfDay(), $p->punched_at->endOfDay()])
            ->exists();
    }

    private function repeatedLocation(AttendancePunch $p): bool
    {
        return AttendancePunch::query()
            ->where('employee_id', $p->employee_id)
            ->where('id', '!=', $p->id)
            ->where('latitude', $p->latitude)
            ->where('longitude', $p->longitude)
            ->whereBetween('punched_at', [$p->punched_at->subDays(self::REPEAT_LOOKBACK_DAYS), $p->punched_at])
            ->count() >= self::REPEAT_THRESHOLD;
    }

    private function shiftEnd(Attendance $attendance): ?CarbonImmutable
    {
        $attendance->loadMissing('shift');
        $shift = $attendance->shift;
        if ($shift === null) {
            return null;
        }
        $day = CarbonImmutable::parse($attendance->date->toDateString());
        $start = $day->setTimeFromTimeString((string) $shift->start_time);
        $end = $day->setTimeFromTimeString((string) $shift->end_time);

        return $end->lessThanOrEqualTo($start) ? $end->addDay() : $end;
    }
}
