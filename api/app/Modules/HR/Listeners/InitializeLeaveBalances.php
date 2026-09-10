<?php

declare(strict_types=1);

namespace App\Modules\HR\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalanceService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Series C — Task C3. Fallback that initialises this calendar year's leave
 * balances for a newly hired employee, pro-rated against their hire date.
 *
 * EmployeeService::create() seeds the same rows synchronously via
 * LeaveBalanceService::seedProratedFor(); this listener delegates to that
 * same seeder so pre-existing employees (created before the synchronous
 * seed existed) still get initialised on event replay.
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

        $created = app(LeaveBalanceService::class)->seedProratedFor($emp);

        if ($created > 0) {
            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'leave_balances_initialized',
                "Initialized {$created} leave balance row(s) for the new employee.",
            );

            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'skipped',
            LeaveType::query()->where('is_active', true)->exists()
                ? 'leave_balances_already_present'
                : 'no_active_leave_types',
        );
    }
}
