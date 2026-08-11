<?php

declare(strict_types=1);

namespace App\Common\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum ExportFrequency: string
{
    case Daily   = 'daily';
    case Weekly  = 'weekly';
    case Monthly = 'monthly';

    /**
     * Compute the next run timestamp from `$from` given the schedule shape.
     *
     * @param  int|null  $dayOfWeek   0=Sunday..6=Saturday (weekly only)
     * @param  int|null  $dayOfMonth  1..31 (monthly only)
     * @param  string    $timeOfDay   "HH:MM" 24-hour
     */
    public function nextRunFrom(
        CarbonInterface $from,
        ?int $dayOfWeek,
        ?int $dayOfMonth,
        string $timeOfDay = '06:00',
    ): CarbonImmutable {
        [$h, $m] = array_pad(array_map('intval', explode(':', $timeOfDay)), 2, 0);
        $base = CarbonImmutable::instance($from)->setTime($h, $m, 0);

        return match ($this) {
            self::Daily => $base->lessThanOrEqualTo($from)
                ? $base->addDay()
                : $base,
            self::Weekly => (function () use ($base, $from, $dayOfWeek) {
                $target = $dayOfWeek ?? 1; // default Monday
                $candidate = $base->next($target);
                if ($base->dayOfWeek === $target && $base->greaterThan($from)) {
                    return $base;
                }
                return $candidate;
            })(),
            self::Monthly => (function () use ($base, $from, $dayOfMonth) {
                $target = max(1, min(31, $dayOfMonth ?? 1));
                $candidate = $base->day(min($target, $base->daysInMonth));
                if ($candidate->lessThanOrEqualTo($from)) {
                    $nextMonth = $candidate->addMonthNoOverflow()->startOfMonth()->setTime(
                        $base->hour,
                        $base->minute,
                        $base->second,
                    );
                    $candidate = $nextMonth->day(min($target, $nextMonth->daysInMonth));
                }
                return $candidate;
            })(),
        };
    }
}
