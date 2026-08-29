<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Quality\Enums\CalibrationStatus;
use App\Modules\Quality\Models\CalibrationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * OGAMI-016 — calibration register management + due/overdue evaluation.
 */
class CalibrationService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function create(array $data): CalibrationRecord
    {
        $this->assertFrequencyIsPresentWhenSupplied($data);
        $this->assertDateOrder(
            $this->asDateString($data['last_calibration_date'] ?? null),
            $this->asDateString($data['next_calibration_date'] ?? null),
        );

        return DB::transaction(function () use ($data) {
            $record = new CalibrationRecord();
            $record->fill($this->withDerived($data));
            $record->save();

            return $record;
        });
    }

    public function update(CalibrationRecord $record, array $data): CalibrationRecord
    {
        $this->assertFrequencyIsPresentWhenSupplied($data);

        return DB::transaction(function () use ($record, $data) {
            // Lock-then-patch, for two reasons.
            //
            // 1. Concurrency: the previous implementation re-filled the model
            //    from the representation the request arrived with, so the later
            //    of two concurrent PATCHes silently reverted the earlier one's
            //    fields. Re-reading under a row lock makes the stored row the
            //    authority and the request a patch on top of it.
            // 2. Correctness: that merge went through `$record->toArray()`,
            //    which carries `id`, `created_at` and `updated_at`. Under
            //    `Model::preventSilentlyDiscardingAttributes()` — on in every
            //    non-production environment — `fill()` refuses those outright,
            //    so every PATCH raised MassAssignmentException and the edit
            //    screen answered 500. Only the validated patch keys are applied
            //    now.
            $locked = CalibrationRecord::query()->lockForUpdate()->findOrFail($record->getKey());

            $patch = array_intersect_key($data, array_flip($locked->getFillable()));
            unset($patch['status']);
            $locked->fill($patch);

            // A patch may supply one half of the pair, so the invariant is
            // checked against the merged result, not against the input. An
            // untouched schedule is left alone: a row that predates this guard
            // must stay repairable field by field.
            if (array_key_exists('last_calibration_date', $data) || array_key_exists('next_calibration_date', $data)) {
                $this->assertDateOrder(
                    $locked->last_calibration_date?->toDateString(),
                    $locked->next_calibration_date?->toDateString(),
                );
            }

            $locked->status = $this->statusFor(
                $locked->next_calibration_date?->toDateString(),
                $this->requestedStatus($data) ?? $locked->status,
            );
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * Record a fresh calibration: stamps last/next dates from frequency and
     * resets status to active.
     */
    public function recordCalibration(CalibrationRecord $record, string $onDate): CalibrationRecord
    {
        return DB::transaction(function () use ($record, $onDate) {
            // Lock-then-guard: re-read so a concurrent entry cannot regress the
            // register from a stale snapshot.
            $locked = CalibrationRecord::query()->lockForUpdate()->findOrFail($record->getKey());
            $last = CarbonImmutable::parse($onDate);

            if ($last->startOfDay()->gt(CarbonImmutable::today())) {
                throw new BusinessRuleException('Calibration date cannot be in the future.');
            }

            // Never regress: a backdated entry must not roll last/next dates
            // back after a newer calibration already committed.
            if ($locked->last_calibration_date !== null
                && $last->lte(CarbonImmutable::parse($locked->last_calibration_date))) {
                return $locked->fresh();
            }

            $locked->last_calibration_date = $last->toDateString();
            $locked->next_calibration_date = $last->addDays($locked->frequency_days)->toDateString();
            $locked->status = $this->statusFor($locked->next_calibration_date?->toDateString(), $locked->status);
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * Recompute due/overdue across the register. Returns counts per status.
     * Intended for the `calibration:check-due` scheduled command.
     *
     * @return array{due:int, overdue:int}
     */
    public function recomputeStatuses(): array
    {
        $due = 0; $overdue = 0;
        CalibrationRecord::query()
            ->where('status', '!=', CalibrationStatus::Retired->value)
            ->orderBy('id')
            ->chunk(200, function ($records) use (&$due, &$overdue) {
                foreach ($records as $r) {
                    $new = $this->statusFor($r->next_calibration_date?->toDateString(), $r->status);
                    if ($new !== $r->status) {
                        $r->status = $new;
                        $r->save();
                    }
                    if ($new === CalibrationStatus::Due) $due++;
                    if ($new === CalibrationStatus::Overdue) $overdue++;
                }
            });

        return ['due' => $due, 'overdue' => $overdue];
    }

    private function withDerived(array $data): array
    {
        // Preserve an explicit Retired status; otherwise derive from dates.
        $current = $this->requestedStatus($data);

        if ($current !== CalibrationStatus::Retired) {
            $next = $this->asDateString($data['next_calibration_date'] ?? null);
            $data['status'] = $this->statusFor($next, $current ?? CalibrationStatus::Active)->value;
        }

        return $data;
    }

    private function requestedStatus(array $data): ?CalibrationStatus
    {
        $status = $data['status'] ?? null;
        if ($status === null) {
            return null;
        }

        return $status instanceof CalibrationStatus ? $status : CalibrationStatus::from((string) $status);
    }

    private function asDateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : CarbonImmutable::parse((string) $value)->toDateString();
    }

    /**
     * A register entry claims a history and a schedule. Neither may be
     * impossible: an instrument cannot have been calibrated in the future, and
     * its next due date cannot precede the calibration it is measured from.
     *
     * Both map to one correctable input, so they surface as field-level 422s
     * rather than as a BusinessRuleException.
     */
    private function assertDateOrder(?string $last, ?string $next): void
    {
        if ($last !== null && CarbonImmutable::parse($last)->startOfDay()->gt(CarbonImmutable::today())) {
            throw ValidationException::withMessages([
                'last_calibration_date' => ['The last calibration date cannot be in the future.'],
            ]);
        }

        if ($last === null || $next === null) {
            return;
        }

        if (CarbonImmutable::parse($next)->startOfDay()->lt(CarbonImmutable::parse($last)->startOfDay())) {
            throw ValidationException::withMessages([
                'next_calibration_date' => ['The next calibration date cannot be earlier than the last calibration date.'],
            ]);
        }
    }

    private function assertFrequencyIsPresentWhenSupplied(array $data): void
    {
        if (array_key_exists('frequency_days', $data) && $data['frequency_days'] === null) {
            throw new BusinessRuleException('Calibration frequency is required when supplied.');
        }
    }

    private function statusFor(?string $nextDate, CalibrationStatus $current): CalibrationStatus
    {
        if ($current === CalibrationStatus::Retired) {
            return CalibrationStatus::Retired;
        }
        if ($nextDate === null) {
            return CalibrationStatus::Active;
        }

        $next  = CarbonImmutable::parse($nextDate)->startOfDay();
        $today = CarbonImmutable::now()->startOfDay();

        if ($today->gt($next)) {
            return CalibrationStatus::Overdue;
        }
        $window = $this->settings->get('quality.calibration.due_window_days');
        if (! is_numeric($window) || (int) $window < 0) {
            throw new \App\Common\Exceptions\BusinessRuleException('Required business setting quality.calibration.due_window_days is missing or invalid.');
        }
        if ($today->diffInDays($next, false) <= (int) $window) {
            return CalibrationStatus::Due;
        }

        return CalibrationStatus::Active;
    }
}
