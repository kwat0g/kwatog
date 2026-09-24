<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Support\TrashedFilter;
use App\Common\Support\SearchOperator;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Holiday;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HolidayService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = Holiday::query();
        TrashedFilter::apply($q, $filters);
        if (!empty($filters['search'])) $q->where('name', SearchOperator::like(), SearchOperator::contains($filters['search']));
        if (!empty($filters['type'])) $q->where('type', $filters['type']);
        if (!empty($filters['year'])) {
            $q->whereYear('date', (int) $filters['year']);
        }
        if (!empty($filters['from'])) $q->where('date', '>=', $filters['from']);
        if (!empty($filters['to'])) $q->where('date', '<=', $filters['to']);

        return $q->orderBy('date')->paginate(min((int) ($filters['per_page'] ?? 50), 200));
    }

    public function create(array $data): Holiday
    {
        return DB::transaction(function () use ($data) {
            $this->assertDateAvailable((string) $data['date']);
            $h = Holiday::create($data);
            $this->bustCache($h->date->year);
            $this->recomputeAttendanceForRule($h->date->toDateString(), (bool) $h->is_recurring);
            return $h;
        });
    }

    public function update(Holiday $h, array $data): Holiday
    {
        return DB::transaction(function () use ($h, $data) {
            $locked = Holiday::query()->lockForUpdate()->findOrFail($h->getKey());
            $oldYear = $locked->date->year;
            $oldDate = $locked->date->toDateString();
            $oldRecurring = (bool) $locked->is_recurring;
            if (array_key_exists('date', $data)) {
                $this->assertDateAvailable((string) $data['date'], $locked->getKey());
            }
            $locked->update($data);
            $locked->refresh();
            $this->bustCache($oldYear);
            $this->bustCache($locked->date->year);
            if (array_key_exists('date', $data) || array_key_exists('is_recurring', $data)) {
                $this->recomputeAttendanceForRule($oldDate, $oldRecurring);
                $this->recomputeAttendanceForRule($locked->date->toDateString(), (bool) $locked->is_recurring);
            } elseif (array_key_exists('type', $data)) {
                $this->recomputeAttendanceForRule($locked->date->toDateString(), (bool) $locked->is_recurring);
            }
            return $locked;
        });
    }

    public function delete(Holiday $h): void
    {
        DB::transaction(function () use ($h): void {
            $locked = Holiday::query()->lockForUpdate()->findOrFail($h->getKey());
            $year = $locked->date->year;
            $date = $locked->date->toDateString();
            $recurring = (bool) $locked->is_recurring;
            $locked->delete();
            $this->bustCache($year);
            $this->recomputeAttendanceForRule($date, $recurring);
        });
    }

    public function restore(Holiday $h): void
    {
        DB::transaction(function () use ($h): void {
            $locked = Holiday::withTrashed()->lockForUpdate()->findOrFail($h->getKey());
            $this->assertDateAvailable((string) $locked->date, $locked->getKey());
            $locked->restore();
            $this->bustCache($locked->date->year);
            $this->recomputeAttendanceForRule($locked->date->toDateString(), (bool) $locked->is_recurring);
        });
    }

    /** Used by DTR engine. */
    public function forDate(CarbonInterface $date): ?Holiday
    {
        $year = $date->year;
        $cacheVersion = (int) Cache::get('holidays:cache_version', 0);
        $cached = Cache::remember(
            "holidays:{$year}:{$cacheVersion}",
            now()->addDay(),
            fn () => $this->loadYear($year),
        );
        $key = $date->toDateString();
        $row = $cached[$key] ?? null;
        if (!$row) return null;

        $holiday = new Holiday();
        $holiday->forceFill([
            'id' => $row['id'],
            'name' => $row['name'],
            'date' => $key,
            'type' => $row['type'],
        ]);
        $holiday->exists = true;

        return $holiday;
    }

    /** @return array<string, true> */
    public function datesBetween(CarbonInterface $start, CarbonInterface $end): array
    {
        $dates = array_fill_keys(
            Holiday::query()
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->pluck('date')
                ->map(static fn (mixed $date): string => CarbonImmutable::parse((string) $date)->toDateString())
                ->all(),
            true,
        );
        $recurringMonthDays = array_fill_keys(
            Holiday::query()->where('is_recurring', true)->get(['date'])
                ->map(static fn (Holiday $holiday): string => $holiday->date->format('m-d'))
                ->all(),
            true,
        );

        for ($date = CarbonImmutable::parse($start->toDateString());
            $date->lte($end);
            $date = $date->addDay()) {
            if (isset($recurringMonthDays[$date->format('m-d')])) {
                $dates[$date->toDateString()] = true;
            }
        }

        return $dates;
    }

    /** @return array<string, array{id:int, name:string, type:string}> */
    private function loadYear(int $year): array
    {
        $holidays = Holiday::query()
            ->whereYear('date', $year)
            ->orderBy('date')
            ->orderBy('id')
            ->get();
        $byDate = [];
        foreach ($holidays as $holiday) {
            $byDate[$holiday->date->toDateString()] = $this->holidayData($holiday);
        }

        foreach (Holiday::query()->where('is_recurring', true)->orderBy('id')->get() as $holiday) {
            $month = (int) $holiday->date->format('m');
            $day = (int) $holiday->date->format('d');
            if (! checkdate($month, $day, $year)) {
                continue;
            }
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $byDate[$date] ??= $this->holidayData($holiday);
        }

        return $byDate;
    }

    /** @return array{id:int, name:string, type:string} */
    private function holidayData(Holiday $holiday): array
    {
        return ['id' => $holiday->id, 'name' => $holiday->name, 'type' => $holiday->type->value];
    }

    private function assertDateAvailable(string $date, ?int $ignoreId = null): void
    {
        $query = Holiday::query()->whereDate('date', $date)->lockForUpdate();
        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw new \App\Common\Exceptions\BusinessRuleException(
                "Only one active holiday may be recorded for {$date}. Choose the existing holiday or archive it first.",
            );
        }
    }

    private function bustCache(int $year): void
    {
        Cache::forget("holidays:{$year}");
        Cache::put('holidays:cache_version', (int) Cache::get('holidays:cache_version', 0) + 1);
    }

    private function recomputeAttendanceForRule(string $date, bool $recurring): void
    {
        $ruleDate = CarbonImmutable::parse($date);
        $query = Attendance::query()->whereHas('employee');
        if ($recurring) {
            $query->whereMonth('date', $ruleDate->month)->whereDay('date', $ruleDate->day);
        } else {
            $query->whereDate('date', $ruleDate->toDateString());
        }

        $mutability = app(AttendanceDateMutabilityGuard::class);
        foreach ($query->get(['employee_id', 'date']) as $attendance) {
            $attendanceDate = $attendance->date->toDateString();
            if (! $mutability->isMutable((int) $attendance->employee_id, $attendanceDate)) {
                continue;
            }

            app(AttendanceService::class)->recomputeForEmployeeOnDate(
                (int) $attendance->employee_id,
                $attendanceDate,
            );
        }
    }
}
