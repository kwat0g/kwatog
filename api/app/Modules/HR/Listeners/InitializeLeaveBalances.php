<?php

declare(strict_types=1);

namespace App\Modules\HR\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Series C — Task C3. Initialise this calendar year's leave balances for
 * a newly hired employee, pro-rated against their hire date.
 *
 * Pro-ration: balance = round(default_balance * remaining_days_in_year /
 * total_days_in_year, 1). Hires on Jan 1 get the full balance; hires
 * mid-year get a fraction.
 *
 * Idempotent: inserts are keyed by (employee_id, leave_type_id, year).
 * Re-firing after rollover does nothing for the current year.
 *
 * Stateful failures are rethrown for queue retry. Inserts use the database
 * unique key so duplicate employee-created events remain safe under races.
 */
class InitializeLeaveBalances implements ShouldQueue
{
    public function handle(EmployeeCreated $event): void
    {
        $emp = $event->employee;
        if (! class_exists(LeaveType::class)) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'leave_type_model_unavailable');
            return;
        }

        $hire = $emp->date_hired ? Carbon::parse((string) $emp->date_hired) : Carbon::now();
        $year = (int) $hire->year;

        $startOfYear = Carbon::create($year, 1, 1);
        $endOfYear   = Carbon::create($year, 12, 31);
        $totalDays   = $startOfYear->diffInDays($endOfYear) + 1; // 365 or 366
        $remaining   = max(1, $hire->diffInDays($endOfYear) + 1);
        $proRation   = $remaining / $totalDays;

        $created = 0;
        $activeTypes = 0;
        DB::transaction(function () use ($emp, $year, $proRation, &$created, &$activeTypes): void {
            $types = LeaveType::query()->where('is_active', true)->get();
            $activeTypes = $types->count();
            $types->each(function (LeaveType $lt) use ($emp, $year, $proRation, &$created): void {
                // Preserve rows seeded by EmployeeService::create(), while
                // letting the unique key atomically reject duplicate queued
                // events instead of racing on exists()+create().
                $credits = round((float) $lt->default_balance * $proRation, 1);
                $created += DB::table('employee_leave_balances')->insertOrIgnore([
                    'employee_id'   => $emp->id,
                    'leave_type_id' => $lt->id,
                    'year'          => $year,
                    'total_credits' => $credits,
                    'used'          => 0,
                    'remaining'     => $credits,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            });
        });

        if ($activeTypes === 0) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'no_active_leave_types');
        } else {
            app(ChainListenerRunService::class)->recordOutcome(
                $created > 0 ? 'completed' : 'skipped',
                $created > 0 ? 'leave_balances_initialized' : 'leave_balances_already_present',
                $created > 0 ? "Initialized {$created} leave balance row(s) for the new employee." : null,
            );
        }
    }
}
