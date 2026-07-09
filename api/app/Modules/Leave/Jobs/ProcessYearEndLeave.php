<?php

declare(strict_types=1);

namespace App\Modules\Leave\Jobs;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Models\ProcessedYearEndLeaveType;
use App\Modules\Leave\Models\YearEndLeaveDisposition;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Enums\PayrollAdjustmentType;
use App\Modules\Payroll\Models\PayrollAdjustment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OGAMI-104 / REC-10 — Year-end leave disposition (SINGLE SOURCE OF TRUTH).
 *
 * For each active employee × active leave type, this job decides — once, here —
 * what happens to the remaining balance, and records it per-employee in
 * `year_end_leave_dispositions`:
 *
 *   - Convertible type (is_convertible_year_end): the convertible portion
 *     (remaining × conversion_rate) is ENCASHED, the rest FORFEITED, and the
 *     balance zeroed. The cash value (days × daily rate) becomes an APPROVED
 *     PayrollAdjustment (Underpayment, +net) that the employee's next payroll
 *     run picks up and pays. days_carried = 0.
 *   - Non-convertible type: min(remaining, max_carryover_days ?? remaining) is
 *     CARRIED FORWARD, the excess FORFEITED, and the balance zeroed. No cash.
 *     ResetLeaveBalancesForYear seeds next year from days_carried — it does NOT
 *     re-read the raw remaining, so the two mechanisms can never double-handle.
 *
 * Idempotent: per (leave_type_id, year) via processed_year_end_leave_types AND
 * per (employee, type, year) via the disposition table's unique index.
 */
class ProcessYearEndLeave implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * @param User $runBy  Who triggered processing (audit + adjustment.created_by).
     * @param int|null $year  The year that is ENDING. Defaults to the current
     *   calendar year — correct for the scheduled Dec-31 23:00 run. Manual
     *   runs in a later month MUST pass the explicit target year.
     * @param array<int>|null $leaveTypeIds  Optional subset of leave type IDs.
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
            ->get(['id', 'pay_type', 'daily_rate', 'basic_monthly_salary']);

        $totalEmployees = 0;
        $totalConverted = 0.0;
        $totalCarried   = 0.0;
        $totalForfeited = 0.0;
        $skipped        = 0;

        DB::transaction(function () use (
            $year, $types, $employees,
            &$totalEmployees, &$totalConverted, &$totalCarried, &$totalForfeited, &$skipped
        ) {
            foreach ($types as $lt) {
                // Idempotency: skip a type+year already processed.
                $already = ProcessedYearEndLeaveType::query()
                    ->where('leave_type_id', $lt->id)
                    ->where('year', $year)
                    ->exists();
                if ($already) {
                    $skipped++;
                    Log::info("Year-end leave already processed for type {$lt->id} / {$year} — skipping.");
                    continue;
                }

                $rate      = max(0.0, min(1.0, (float) ($lt->conversion_rate ?? 0.0)));
                $cap       = $lt->max_carryover_days !== null ? (float) $lt->max_carryover_days : null;
                $isConvert = (bool) $lt->is_convertible_year_end;

                $typeConverted = 0.0;
                $typeCarried   = 0.0;
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

                    if ($isConvert) {
                        $converted = round($remaining * $rate, 1);
                        $carried   = 0.0;
                        $forfeited = round($remaining - $converted, 1);
                    } else {
                        $carried   = $cap !== null ? round(min($remaining, $cap), 1) : round($remaining, 1);
                        $converted = 0.0;
                        $forfeited = round($remaining - $carried, 1);
                    }

                    // Zero the balance — its disposition now lives in the
                    // disposition table (single source of truth).
                    $bal->used = (float) $bal->total_credits;
                    $bal->remaining = 0.0;
                    $bal->save();

                    // Encashment → approved PayrollAdjustment (next run pays it).
                    $adjustmentId = null;
                    $cashValue    = 0.0;
                    if ($converted > 0) {
                        $cashValue = round($converted * $this->dailyRate($emp), 2);
                        if ($cashValue > 0) {
                            $adjustmentId = $this->createEncashmentAdjustment($emp, $lt, $year, $converted, $cashValue);
                        }
                    }

                    YearEndLeaveDisposition::query()->updateOrCreate(
                        ['employee_id' => $emp->id, 'leave_type_id' => $lt->id, 'year' => $year],
                        [
                            'days_converted'        => $converted,
                            'days_carried'          => $carried,
                            'days_forfeited'        => $forfeited,
                            'cash_value'            => $cashValue,
                            'payroll_adjustment_id' => $adjustmentId,
                            'processed_at'          => Carbon::now(),
                        ],
                    );

                    $typeConverted += $converted;
                    $typeCarried   += $carried;
                    $typeForfeited += $forfeited;
                    $typeEmployees++;
                }

                ProcessedYearEndLeaveType::query()->create([
                    'leave_type_id'   => $lt->id,
                    'year'            => $year,
                    'processed_at'    => Carbon::now(),
                    'processed_by'    => $this->runBy->id,
                    'employees_count' => $typeEmployees,
                    'days_converted'  => round($typeConverted, 1),
                    'days_forfeited'  => round($typeForfeited, 1),
                ]);

                $totalEmployees += $typeEmployees;
                $totalConverted += $typeConverted;
                $totalCarried   += $typeCarried;
                $totalForfeited += $typeForfeited;
            }
        });

        Log::info("Year-end leave processing complete for {$year}.", [
            'year'            => $year,
            'total_employees' => $totalEmployees,
            'total_converted' => round($totalConverted, 1),
            'total_carried'   => round($totalCarried, 1),
            'total_forfeited' => round($totalForfeited, 1),
            'skipped_types'   => $skipped,
            'run_by'          => $this->runBy->id,
        ]);
    }

    /** Daily rate, mirroring FinalPayService: daily_rate else monthly/22. */
    private function dailyRate(Employee $emp): float
    {
        return (float) ($emp->daily_rate ?: ((float) ($emp->basic_monthly_salary ?? 0) / 22));
    }

    /**
     * Create an APPROVED encashment adjustment. It has no originating payroll
     * (payroll_period_id / original_payroll_id are nullable per migration 0265)
     * — applyApprovedAdjustments() picks it up by employee + approved +
     * unapplied on the next non-13th-month run. Status is set via property
     * assignment because it is not mass-assignable.
     */
    private function createEncashmentAdjustment(
        Employee $emp,
        LeaveType $lt,
        int $year,
        float $days,
        float $cashValue,
    ): int {
        $adj = new PayrollAdjustment();
        $adj->fill([
            'payroll_period_id'   => null,
            'employee_id'         => $emp->id,
            'original_payroll_id' => null,
            'type'                => PayrollAdjustmentType::Underpayment->value,
            'amount'              => number_format($cashValue, 2, '.', ''),
            'reason'              => "Year-end leave encashment {$year}: {$days} day(s) of {$lt->name}",
            'created_by'          => $this->runBy->id,
        ]);
        $adj->status      = PayrollAdjustmentStatus::Approved;
        $adj->approved_by = $this->runBy->id;
        $adj->save();

        return $adj->id;
    }
}
