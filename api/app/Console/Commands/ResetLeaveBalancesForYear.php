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
    protected $signature = 'hr:reset-leave-balances {--year= : Target year (default: current)}';

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

        $types = LeaveType::query()->where('is_active', true)->get();
        $employees = Employee::query()->where('status', 'active')->get(['id']);

        // A rollover without a year-end disposition is not safe: the raw
        // remaining balance may already have been converted/forfeited by a
        // partially completed run, and carrying it again can double-handle
        // leave. Refuse the whole rollover until the durable year-end request
        // is recovered, so the January retry window can safely run it first.
        $missingDispositionBalances = DB::table('employee_leave_balances as balances')
            ->join('employees', 'employees.id', '=', 'balances.employee_id')
            ->where('employees.status', 'active')
            ->where('balances.year', $prior)
            ->where('balances.remaining', '>', 0)
            ->whereIn('balances.leave_type_id', $types->pluck('id'))
            ->whereNotExists(function ($query) use ($prior): void {
                $query->selectRaw('1')
                    ->from('year_end_leave_dispositions as dispositions')
                    ->whereColumn('dispositions.employee_id', 'balances.employee_id')
                    ->whereColumn('dispositions.leave_type_id', 'balances.leave_type_id')
                    ->where('dispositions.year', $prior);
            })
            ->select(['balances.employee_id', 'balances.leave_type_id'])
            ->limit(25)
            ->get();

        if ($missingDispositionBalances->isNotEmpty()) {
            $this->error(sprintf(
                'Cannot roll leave balances to %d: %d or more positive prior-year balance(s) have no year-end disposition. Recover `leave:process-year-end --year=%d` first.',
                $year,
                $missingDispositionBalances->count(),
                $prior,
            ));

            return self::FAILURE;
        }

        DB::transaction(function () use ($year, $prior, $types, $employees, &$created) {
            foreach ($employees as $emp) {
                foreach ($types as $lt) {
                    // REC-10 — consume the year-end disposition (single source
                    // of truth) rather than re-reading the prior raw remaining.
                    // ProcessYearEndLeave already decided convert/carry/forfeit;
                    // we only seed next year from days_carried. This makes the
                    // two mechanisms order-independent — no double-handling.
                    $disp = DB::table('year_end_leave_dispositions')
                        ->where('employee_id', $emp->id)
                        ->where('leave_type_id', $lt->id)
                        ->where('year', $prior)
                        ->first();

                    if ($disp) {
                        $carried = (float) $disp->days_carried;
                    } else {
                        // A zero/missing prior balance has nothing to carry.
                        // Positive balances were rejected by the preflight
                        // guard above when they lacked a disposition.
                        $priorRow = EmployeeLeaveBalance::query()
                            ->where('employee_id', $emp->id)
                            ->where('leave_type_id', $lt->id)
                            ->where('year', $prior)
                            ->first();
                        $carried = 0.0;
                    }

                    $total = (float) $lt->default_balance + $carried;
                    $existed = EmployeeLeaveBalance::query()
                        ->where('employee_id', $emp->id)
                        ->where('leave_type_id', $lt->id)
                        ->where('year', $year)
                        ->exists();

                    EmployeeLeaveBalance::query()->updateOrInsert(
                        [
                            'employee_id' => $emp->id,
                            'leave_type_id' => $lt->id,
                            'year' => $year,
                        ],
                        [
                            'total_credits' => round($total, 1),
                            'used' => 0,
                            'remaining' => round($total, 1),
                            'updated_at' => Carbon::now(),
                            'created_at' => Carbon::now(),
                        ]
                    );
                    if (! $existed) {
                        $created++;
                    }
                }
            }
        });

        $ms = (int) round((microtime(true) - $start) * 1000);
        $this->info("Leave balances rolled over to {$year} in {$ms}ms — created {$created} new rows.");

        return self::SUCCESS;
    }
}
