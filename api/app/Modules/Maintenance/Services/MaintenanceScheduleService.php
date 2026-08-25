<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Common\Support\TrashedFilter;
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
use Illuminate\Validation\ValidationException;

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
        $q = TrashedFilter::apply(MaintenanceSchedule::query(), $filters);

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
                throw ValidationException::withMessages([
                    'maintainable_id' => ["Target {$type->value}#{$data['maintainable_id']} not found."],
                ]);
            }
            $this->assertIntervalMatchesTarget($interval, $type);

            $machine = $type === MaintainableType::Machine
                ? Machine::query()->lockForUpdate()->findOrFail((int) $data['maintainable_id'])
                : null;

            $schedule = MaintenanceSchedule::create([
                'maintainable_type' => $type->value,
                'maintainable_id'   => (int) $data['maintainable_id'],
                // Not client-settable: no request rule declares schedule_type, so
                // validated() always dropped it. Matches MoldService's hardcode.
                'schedule_type'     => 'preventive',
                'description'       => $data['description'],
                'interval_type'     => $interval->value,
                'interval_value'    => (int) $data['interval_value'],
                'running_hours_baseline' => $type === MaintainableType::Machine
                    && $interval === MaintenanceScheduleInterval::Hours
                    ? (string) $machine?->running_hours_total
                    : null,
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
            // interval_type is editable, so re-run the create-time guard — else a
            // machine schedule can be PATCHed onto the mold-only shots path and
            // next_due_at silently becomes null, stranding it from every cron.
            $this->assertIntervalMatchesTarget($schedule->interval_type, $schedule->maintainable_type);
            if ($schedule->maintainable_type === MaintainableType::Machine
                && $schedule->interval_type === MaintenanceScheduleInterval::Hours
                && ($schedule->running_hours_baseline === null || $schedule->isDirty(['interval_type', 'interval_value']))) {
                $machine = Machine::query()->lockForUpdate()->find($schedule->maintainable_id);
                if ($machine) {
                    $schedule->running_hours_baseline = (string) $machine->running_hours_total;
                }
            }
            $schedule->next_due_at = $this->computeNextDueAt($schedule);
            $schedule->save();
            return $schedule;
        });
    }

    public function delete(MaintenanceSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule): void {
            $locked = MaintenanceSchedule::query()->lockForUpdate()->findOrFail($schedule->getKey());
            $locked->delete();
        });
    }

    public function restore(MaintenanceSchedule $schedule): void
    {
        DB::transaction(function () use ($schedule): void {
            $locked = MaintenanceSchedule::withTrashed()->lockForUpdate()->findOrFail($schedule->getKey());
            if ($locked->trashed()) {
                $locked->restore();
            }
        });
    }

    /**
     * Shot counting only exists on molds — a machine has no current_shot_count,
     * so a shots schedule pointed at one can never come due.
     */
    private function assertIntervalMatchesTarget(MaintenanceScheduleInterval $interval, MaintainableType $type): void
    {
        if ($interval === MaintenanceScheduleInterval::Shots && $type !== MaintainableType::Mold) {
            throw ValidationException::withMessages([
                'interval_type' => ['Shot-based schedules are only valid for molds.'],
            ]);
        }
    }

    /**
     * Recompute next_due_at after a maintenance WO completes for this schedule.
     */
    public function recomputeNextDueAt(MaintenanceSchedule $schedule, ?Carbon $completedAt = null): MaintenanceSchedule
    {
        return DB::transaction(function () use ($schedule, $completedAt) {
            // Lock-then-guard: re-read so two completions for the same schedule
            // serialize the read-modify-write instead of regressing the due date.
            $locked = MaintenanceSchedule::query()->lockForUpdate()->findOrFail($schedule->getKey());
            $completedAt = $completedAt ?? now();

            // Never regress last_performed_at: a stale, older completion must
            // not overwrite a newer one that already committed.
            if ($locked->last_performed_at !== null && $completedAt->lte($locked->last_performed_at)) {
                return $locked;
            }

            $locked->last_performed_at = $completedAt;
            if ($locked->maintainable_type === MaintainableType::Machine
                && $locked->interval_type === MaintenanceScheduleInterval::Hours) {
                $machine = Machine::query()->lockForUpdate()->find($locked->maintainable_id);
                if ($machine) {
                    $locked->running_hours_baseline = (string) $machine->running_hours_total;
                }
            }
            $locked->next_due_at = $this->computeNextDueAt($locked);
            $locked->save();
            return $locked;
        });
    }

    /**
     * Schedules whose next_due_at <= now and have no currently-open WO.
     * Used by the daily cron.
     */
    public function dueNow()
    {
        return MaintenanceSchedule::query()
            ->active()
            ->where(function (Builder $q): void {
                $q->where('interval_type', MaintenanceScheduleInterval::Days->value)
                    ->orWhere(function (Builder $q): void {
                        $q->where('interval_type', MaintenanceScheduleInterval::Hours->value)
                            ->where('maintainable_type', '!=', MaintainableType::Machine->value);
                    });
            })
            ->due()
            ->whereDoesntHave('workOrders', fn (Builder $q) => $q->whereNotIn('status', ['completed', 'cancelled']))
            ->get();
    }

    /**
     * Machine schedules whose runtime since the last maintenance baseline
     * reaches interval_value. These schedules do not use next_due_at as a gate;
     * runtime is the authoritative due signal.
     */
    public function machineHourSchedulesAtOrAboveThreshold(): iterable
    {
        return MaintenanceSchedule::query()
            ->active()
            ->where('maintainable_type', MaintainableType::Machine->value)
            ->where('interval_type', MaintenanceScheduleInterval::Hours->value)
            ->whereDoesntHave('workOrders', fn (Builder $q) => $q->whereNotIn('status', ['completed', 'cancelled']))
            ->get()
            ->filter(function (MaintenanceSchedule $s) {
                $machine = Machine::find($s->maintainable_id);
                if (! $machine) return false;
                $baseline = $s->running_hours_baseline;
                if ($baseline === null) return false;
                $dueAt = bcadd((string) $baseline, (string) $s->interval_value, 2);
                return bccomp((string) $machine->running_hours_total, $dueAt, 2) >= 0;
            })
            ->values();
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
        if ($schedule->interval_type === MaintenanceScheduleInterval::Shots
            || ($schedule->maintainable_type === MaintainableType::Machine
                && $schedule->interval_type === MaintenanceScheduleInterval::Hours)) {
            // Shot-based and machine-runtime schedules are not date-driven;
            // their due signals are the live shot/runtime thresholds.
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
