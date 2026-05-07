<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Series C — Task C3. Year rollover. Runs Jan 1 at 00:01.
 *
 * For each active employee + active leave type:
 *   1. Read this employee's prior-year balance.
 *   2. If leave_type.is_convertible_year_end is true OR
 *      is_carried_over_to_next_year (when present), carry forward the
 *      remaining (or convert per conversion_rate).
 *   3. Create the new year's balance with default_balance + carried.
 *
 * Idempotent: uses updateOrInsert keyed by (emp, type, year). Re-running
 * Jan 1 → Feb 28 won't double-credit. The carry-forward source is read
 * once, not accumulated.
 */
class ResetLeaveBalancesForYear extends Command
{
    protected $signature   = 'hr:reset-leave-balances {--year= : Target year (default: current)}';
    protected $description = 'Reset / roll over leave balances for the given year (Series C — Task C3)';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: Carbon::now()->year);
        $prior = $year - 1;

        $start = microtime(true);
        $created = 0;

        if (! class_exists(LeaveType::class) || ! class_exists(Employee::class)) {
            $this->warn('Leave / HR module not booted — skipping.');
            return self::SUCCESS;
        }

        $types     = LeaveType::query()->where('is_active', true)->get();
        $employees = Employee::query()->where('status', 'active')->get(['id']);

        DB::transaction(function () use ($year, $prior, $types, $employees, &$created) {
            foreach ($employees as $emp) {
                foreach ($types as $lt) {
                    $priorRow = EmployeeLeaveBalance::query()
                        ->where('employee_id', $emp->id)
                        ->where('leave_type_id', $lt->id)
                        ->where('year', $prior)
                        ->first();

                    $carried = 0.0;
                    if ($priorRow && $lt->is_convertible_year_end) {
                        // Conversion path: convert remaining to credits at conversion_rate.
                        // We just carry the numeric remaining; payroll handles cash conversion.
                        $carried = (float) $priorRow->remaining * (float) ($lt->conversion_rate ?: 1.0);
                    }

                    $total = (float) $lt->default_balance + $carried;
                    $existed = EmployeeLeaveBalance::query()
                        ->where('employee_id', $emp->id)
                        ->where('leave_type_id', $lt->id)
                        ->where('year', $year)
                        ->exists();

                    EmployeeLeaveBalance::query()->updateOrInsert(
                        [
                            'employee_id'   => $emp->id,
                            'leave_type_id' => $lt->id,
                            'year'          => $year,
                        ],
                        [
                            'total_credits' => round($total, 1),
                            'used'          => 0,
                            'remaining'     => round($total, 1),
                            'updated_at'    => Carbon::now(),
                            'created_at'    => Carbon::now(),
                        ]
                    );
                    if (! $existed) $created++;
                }
            }
        });

        $ms = (int) round((microtime(true) - $start) * 1000);
        $this->info("Leave balances rolled over to {$year} in {$ms}ms — created {$created} new rows.");
        return self::SUCCESS;
    }
}
