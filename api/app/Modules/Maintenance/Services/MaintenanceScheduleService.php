<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Common\Support\SearchOperator;
use App\Modules\Maintenance\Enums\MaintainableType;
use App\Modules\Maintenance\Enums\MaintenanceScheduleInterval;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sprint 8 — Task 69. Preventive maintenance schedules.
 *
 * Time-based schedules (`hours` / `days`) compute next_due_at by adding the
 * interval to last_performed_at (or to now() at creation time).
 *
 * Shot-based schedules trigger when the mold's current_shot_count crosses the
 * threshold; the cron job is responsible for materialising a WO.
 */
class MaintenanceScheduleService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $q = MaintenanceSchedule::query();

        foreach (['maintainable_type', 'interval_type'] as $f) {
            if (! empty($filters[$f])) $q->where($f, $filters[$f]);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOL));
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where('description', SearchOperator::like(), $term);
        }

        return $q->orderBy('next_due_at')
            ->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function show(MaintenanceSchedule $schedule): MaintenanceSchedule
    {
        $schedule->loadCount('workOrders');
        return $schedule;
    }

    public function create(array $data): MaintenanceSchedule
    {
        return DB::transaction(function () use ($data) {
            $type = MaintainableType::from((string) $data['maintainable_type']);
            $interval = MaintenanceScheduleInterval::from((string) $data['interval_type']);

            // Validate target exists
            $exists = match ($type) {
                MaintainableType::Machine => Machine::query()->whereKey((int) $data['maintainable_id'])->exists(),
                MaintainableType::Mold    => Mold::query()->whereKey((int) $data['maintainable_id'])->exists(),
            };
            if (! $exists) {
                throw new RuntimeException("Target {$type->value}#{$data['maintainable_id']} not found.");
            }
            if ($interval === MaintenanceScheduleInterval::Shots && $type !== MaintainableType::Mold) {
                throw new RuntimeException('Shot-based schedules are only valid for molds.');
            }

            $schedule = MaintenanceSchedule::create([
                'maintainable_type' => $type->value,
                'maintainable_id'   => (int) $data['maintainable_id'],
                'schedule_type'     => $data['schedule_type'] ?? 'preventive',
                'description'       => $data['description'],
                'interval_type'     => $interval->value,
                'interval_value'    => (int) $data['interval_value'],
                'last_performed_at' => $data['last_performed_at'] ?? null,
                'is_active'         => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOL),
            ]);
            $schedule->next_due_at = $this->computeNextDueAt($schedule);
            $schedule->save();

            return $schedule;
        });
    }

    public function update(MaintenanceSchedule $schedule, array $data): MaintenanceSchedule
    {
        return DB::transaction(function () use ($schedule, $data) {
            $schedule->fill(array_intersect_key($data, array_flip([
                'description', 'interval_type', 'interval_value',
                'is_active', 'last_performed_at',
            ])));
            $schedule->next_due_at = $this->computeNextDueAt($schedule);
            $schedule->save();
            return $schedule;
        });
    }

    public function delete(MaintenanceSchedule $schedule): void
    {
        $schedule->delete();
    }

    /**
     * Recompute next_due_at after a maintenance WO completes for this schedule.
     */
    public function recomputeNextDueAt(MaintenanceSchedule $schedule, ?Carbon $completedAt = null): MaintenanceSchedule
    {
        $schedule->last_performed_at = $completedAt ?? now();
        $schedule->next_due_at = $this->computeNextDueAt($schedule);
        $schedule->save();
        return $schedule;
    }

    /**
     * Schedules whose next_due_at <= now and have no currently-open WO.
     * Used by the daily cron.
     */
    public function dueNow()
    {
        return MaintenanceSchedule::query()
            ->active()
            ->whereIn('interval_type', [
                MaintenanceScheduleInterval::Hours->value,
                MaintenanceScheduleInterval::Days->value,
            ])
            ->due()
            ->whereDoesntHave('workOrders', fn (Builder $q) => $q->whereNotIn('status', ['completed', 'cancelled']))
            ->get();
    }

    /**
     * Mold-shot schedules currently exceeding the configured shot threshold.
     */
    public function moldShotSchedulesAtOrAboveThreshold(float $thresholdPct = 100.0)
    {
        $rows = MaintenanceSchedule::query()
            ->active()
            ->where('maintainable_type', MaintainableType::Mold->value)
            ->where('interval_type', MaintenanceScheduleInterval::Shots->value)
            ->whereDoesntHave('workOrders', fn (Builder $q) => $q->whereNotIn('status', ['completed', 'cancelled']))
            ->get();

        return $rows->filter(function (MaintenanceSchedule $s) use ($thresholdPct) {
            $mold = Mold::find($s->maintainable_id);
            if (! $mold) return false;
            $threshold = (int) round(($s->interval_value * $thresholdPct) / 100.0);
            return (int) $mold->current_shot_count >= $threshold;
        })->values();
    }

    private function computeNextDueAt(MaintenanceSchedule $schedule): ?Carbon
    {
        if ($schedule->interval_type === MaintenanceScheduleInterval::Shots) {
            // Shot-based: not date-driven; surface a "next due" hint as null.
            return null;
        }
        $base = $schedule->last_performed_at ?: now();
        $base = $base instanceof Carbon ? $base->copy() : Carbon::parse($base);
        return match ($schedule->interval_type) {
            MaintenanceScheduleInterval::Hours => $base->addHours((int) $schedule->interval_value),
            MaintenanceScheduleInterval::Days  => $base->addDays((int) $schedule->interval_value),
            default                            => null,
        };
    }
}
