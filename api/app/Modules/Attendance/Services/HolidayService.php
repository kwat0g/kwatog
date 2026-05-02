<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

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
            $h = Holiday::create($data);
            $this->bustCache($h->date->year);
            return $h;
        });
    }

    public function update(Holiday $h, array $data): Holiday
    {
        return DB::transaction(function () use ($h, $data) {
            $oldYear = $h->date->year;
            $h->update($data);
            $h->refresh();
            $this->bustCache($oldYear);
            $this->bustCache($h->date->year);
            return $h;
        });
    }

    public function delete(Holiday $h): void
    {
        $year = $h->date->year;
        $h->delete();
        $this->bustCache($year);
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
            ->get()
            ->mapWithKeys(fn ($h) => [
                $h->date->toDateString() => ['id' => $h->id, 'name' => $h->name, 'type' => $h->type->value],
            ])
            ->all();
    }

    private function bustCache(int $year): void
    {
        Cache::forget("holidays:{$year}");
    }
}
