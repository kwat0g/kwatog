<?php

declare(strict_types=1);

namespace App\Modules\Leave\Jobs;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Models\ProcessedYearEndLeaveType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OGAMI-104 — Year-end leave forfeiture/conversion.
 *
 * For convertible leave types: remaining days are split per conversion_rate
 * (convertible → payroll-encashable; the rest → forfeited).
 * For non-convertible types: all unused remaining → forfeited to 0.
 *
 * Idempotent: records processed (leave_type_id + year) in a tracking table
 * so re-running the same type+year is a no-op.
 */
class ProcessYearEndLeave implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param User $runBy  The user who triggered processing (for logging / audit).
     * @param int|null $year  Target year; defaults to the PREVIOUS year (the year ending).
     * @param array<int>|null $leaveTypeIds  Optional subset of leave type IDs to process.
     */
    public function __construct(
        public User $runBy,
        public ?int $year = null,
        public ?array $leaveTypeIds = null,
    ) {}

    public function handle(): void
    {
        $year = $this->year ?? Carbon::now()->year;

        $query = LeaveType::query()->where('is_active', true);

        if ($this->leaveTypeIds !== null) {
            $query->whereIn('id', $this->leaveTypeIds);
        }

        $types = $query->get();

        $employees = Employee::query()
            ->where('status', 'active')
            ->get(['id']);

        $totalEmployees = 0;
        $totalConverted = 0.0;
        $totalForfeited = 0.0;
        $skippedDueToIdempotency = 0;

        DB::transaction(function () use ($year, $types, $employees, &$totalEmployees, &$totalConverted, &$totalForfeited, &$skippedDueToIdempotency) {
            foreach ($types as $lt) {
                // Check idempotency — skip this type+year if already processed.
                $alreadyProcessed = ProcessedYearEndLeaveType::query()
                    ->where('leave_type_id', $lt->id)
                    ->where('year', $year)
                    ->exists();

                if ($alreadyProcessed) {
                    $skippedDueToIdempotency++;
                    Log::info("Year-end leave already processed for type {$lt->id} / {$year} — skipping.", [
                        'leave_type_id' => $lt->id,
                        'year'          => $year,
                    ]);
                    continue;
                }

                $typeConverted = 0.0;
                $typeForfeited = 0.0;
                $typeEmployees = 0;

                foreach ($employees as $emp) {
                    $bal = EmployeeLeaveBalance::query()
                        ->where('employee_id', $emp->id)
                        ->where('leave_type_id', $lt->id)
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->first();

                    if (! $bal || (float) $bal->remaining <= 0) {
                        continue;
                    }

                    $remaining = (float) $bal->remaining;

                    if ($lt->is_convertible_year_end) {
                        $rate = max(0.0, min(1.0, (float) ($lt->conversion_rate ?? 0.0)));
                        $convertibleAmount = round($remaining * $rate, 1);
                        $forfeitedAmount = round($remaining - $convertibleAmount, 1);
                    } else {
                        $convertibleAmount = 0.0;
                        $forfeitedAmount = $remaining;
                    }

                    // Zero out remaining balance.
                    // For convertible types: mark used = total_credits so the
                    // balance is fully consumed for the year.
                    $bal->used = (float) $bal->total_credits;
                    $bal->remaining = 0.0;
                    $bal->save();

                    $typeConverted += $convertibleAmount;
                    $typeForfeited += $forfeitedAmount;
                    $typeEmployees++;
                }

                // Mark this type+year as processed for idempotency.
                ProcessedYearEndLeaveType::query()->create([
                    'leave_type_id'   => $lt->id,
                    'year'            => $year,
                    'processed_at'    => Carbon::now(),
                    'processed_by'    => $this->runBy->id,
                    'employees_count' => $typeEmployees,
                    'days_converted'  => round($typeConverted, 1),
                    'days_forfeited'  => round($typeForfeited, 1),
                ]);

                $totalEmployees    += $typeEmployees;
                $totalConverted    += $typeConverted;
                $totalForfeited    += $typeForfeited;
            }
        });

        Log::info("Year-end leave processing complete for {$year}. Processed {$totalEmployees} employee-records, converted {$totalConverted} days, forfeited {$totalForfeited} days. Skipped {$skippedDueToIdempotency} already-processed types.", [
            'year'              => $year,
            'total_employees'   => $totalEmployees,
            'total_converted'   => $totalConverted,
            'total_forfeited'   => $totalForfeited,
            'skipped_types'     => $skippedDueToIdempotency,
            'run_by'            => $this->runBy->id,
        ]);
    }
}
