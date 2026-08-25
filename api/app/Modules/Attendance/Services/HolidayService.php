<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Support\TrashedFilter;
use App\Modules\Attendance\Models\Holiday;
use Carbon\CarbonInterface;
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
        if (!empty($filters['search'])) $q->where('name', 'ilike', "%{$filters['search']}%");
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
            return $h;
        });
    }

    public function update(Holiday $h, array $data): Holiday
    {
        return DB::transaction(function () use ($h, $data) {
            $locked = Holiday::query()->lockForUpdate()->findOrFail($h->getKey());
            $oldYear = $locked->date->year;
            if (array_key_exists('date', $data)) {
                $this->assertDateAvailable((string) $data['date'], $locked->getKey());
            }
            $locked->update($data);
            $locked->refresh();
            $this->bustCache($oldYear);
            $this->bustCache($locked->date->year);
            return $locked;
        });
    }

    public function delete(Holiday $h): void
    {
        DB::transaction(function () use ($h): void {
            $locked = Holiday::query()->lockForUpdate()->findOrFail($h->getKey());
            $year = $locked->date->year;
            $locked->delete();
            $this->bustCache($year);
        });
    }

    public function restore(Holiday $h): void
    {
        DB::transaction(function () use ($h): void {
            $locked = Holiday::withTrashed()->lockForUpdate()->findOrFail($h->getKey());
            $this->assertDateAvailable((string) $locked->date, $locked->getKey());
            $locked->restore();
            $this->bustCache($locked->date->year);
        });
    }

    /** Used by DTR engine. */
    public function forDate(CarbonInterface $date): ?Holiday
    {
        $year = $date->year;
        $cached = Cache::remember(
            "holidays:{$year}",
            now()->addDay(),
            fn () => $this->loadYear($year),
        );
        $key = $date->toDateString();
        $row = $cached[$key] ?? null;
        if (!$row) return null;
        return Holiday::find($row['id']);
    }

    /** @return array<string, array{id:int, name:string, type:string}> */
    private function loadYear(int $year): array
    {
        return Holiday::query()
            ->whereYear('date', $year)
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn ($h) => [
                $h->date->toDateString() => ['id' => $h->id, 'name' => $h->name, 'type' => $h->type->value],
            ])
            ->all();
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
    }
}
