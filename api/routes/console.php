<?php

declare(strict_types=1);

use App\Common\Console\RunDueScheduledExports;
use App\Modules\Assets\Jobs\RunMonthlyDepreciationJob;
use App\Modules\Maintenance\Jobs\GeneratePreventiveMaintenanceJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Series E (Task E2) — wire up the artisan command so dev can invoke it.
Artisan::starting(function ($artisan) {
    $artisan->resolve(RunDueScheduledExports::class);
});

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Sprint 8 — Task 69. Daily cron — materialise preventive maintenance WOs
 * (time-based schedules due now, mold-shot schedules at 100% threshold).
 */
Schedule::job(new GeneratePreventiveMaintenanceJob)
    ->dailyAt('02:00')
    ->name('maintenance:generate-preventive')
    ->withoutOverlapping();

/*
 * Sprint 8 — Task 70. Run monthly depreciation on the 1st at 03:00 for the
 * previous calendar month. Idempotent: re-runs are no-ops.
 */
Schedule::job(new RunMonthlyDepreciationJob)
    ->monthlyOn(1, '03:00')
    ->name('assets:run-monthly-depreciation')
    ->withoutOverlapping();

/* ─── Automation tasks A1–A10 ─────────────────────────────────────── */

// A1 — Daily MRP run
Schedule::command('mrp:run-daily')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->onOneServer();

// A2 — Alert engine every 15 minutes
Schedule::command('alerts:run')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// A3 — Auto payroll period creation
//   On the 14th at 23:00 → create period for 16th–end-of-month
//   On the last day at 23:00 → create period for 1st–15th of next month
Schedule::command('payroll:auto-create-period --half=second')
    ->monthlyOn(14, '23:00')
    ->onOneServer();
Schedule::command('payroll:auto-create-period --half=first')
    ->lastDayOfMonth('23:00')
    ->onOneServer();

// A5 — Preventive maintenance evaluation runs the existing Sprint 8
//      job; the new running-hours recompute runs daily before that job.
Schedule::command('maintenance:recompute-hours')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->onOneServer();

// A7 — Approval escalation every 6 hours
Schedule::command('approvals:run-escalations')
    ->everySixHours()
    ->withoutOverlapping()
    ->onOneServer();

// A10 — End-of-day production summary email at 18:00 (and weekly Friday)
Schedule::command('production:send-daily-summary')
    ->dailyAt('18:00')
    ->onOneServer();
Schedule::command('production:send-weekly-summary')
    ->fridays()
    ->at('18:00')
    ->onOneServer();

// U4 — Onboarding reminders. Daily at 09:00, notifies HR for any
// employee onboarding open > 3 days without completion.
Schedule::command('hr:onboarding-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer();

// Series C — Task C5. Chain bottleneck scan, hourly. Idempotent (24h
// dedup window inside the command), so re-running is safe.
Schedule::command('chain:check-bottlenecks')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer();

// Series C — Task C3. Yearly leave balance rollover at Jan 1 00:01.
// Idempotent (updateOrInsert keyed by emp+type+year), so re-runs in
// January are no-ops.
Schedule::command('hr:reset-leave-balances')
    ->yearlyOn(1, 1, '00:01')
    ->withoutOverlapping()
    ->onOneServer();

// Series E (Task E2) — every 5 minutes scan for due scheduled exports
// and fire them off. Idempotent (each row's next_run_at advances on
// success), so re-runs are safe.
Schedule::command('exports:run-due')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
