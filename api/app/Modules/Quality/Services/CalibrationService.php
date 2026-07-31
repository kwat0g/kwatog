<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Services\SettingsService;
use App\Modules\Quality\Enums\CalibrationStatus;
use App\Modules\Quality\Models\CalibrationRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * OGAMI-016 — calibration register management + due/overdue evaluation.
 */
class CalibrationService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function create(array $data): CalibrationRecord
    {
        return DB::transaction(function () use ($data) {
            $record = new CalibrationRecord();
            $record->fill($this->withDerived($data));
            $record->save();

            return $record;
        });
    }

    public function update(CalibrationRecord $record, array $data): CalibrationRecord
    {
        return DB::transaction(function () use ($record, $data) {
            $record->fill($this->withDerived(array_merge($record->toArray(), $data)));
            $record->save();

            return $record->fresh();
        });
    }

    /**
     * Record a fresh calibration: stamps last/next dates from frequency and
     * resets status to active.
     */
    public function recordCalibration(CalibrationRecord $record, string $onDate): CalibrationRecord
    {
        $last = CarbonImmutable::parse($onDate);
        $record->last_calibration_date = $last->toDateString();
        $record->next_calibration_date = $last->addDays($record->frequency_days)->toDateString();
        $record->status = $this->statusFor($record->next_calibration_date?->toDateString(), $record->status);
        $record->save();

        return $record->fresh();
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
        $current = isset($data['status'])
            ? (is_string($data['status']) ? CalibrationStatus::from($data['status']) : $data['status'])
            : null;

        if ($current !== CalibrationStatus::Retired) {
            $next = $data['next_calibration_date'] ?? null;
            $next = $next instanceof \DateTimeInterface ? $next->format('Y-m-d') : $next;
            $data['status'] = $this->statusFor($next, $current ?? CalibrationStatus::Active)->value;
        }

        return $data;
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
