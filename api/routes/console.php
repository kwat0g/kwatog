<?php

declare(strict_types=1);

use App\Modules\Assets\Jobs\RunMonthlyDepreciationJob;
use App\Modules\Maintenance\Jobs\GeneratePreventiveMaintenanceJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

// OGAMI-015 — Hourly reaper for hung Running MRP runs. Marks runs whose
// started_at is older than 2h as Failed and cancels their orphan draft
// auto-PRs. Idempotent, so re-runs are safe no-ops.
Schedule::command('mrp:reap-stale-runs')
    ->hourly()
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

// T3.1.C — NCR SLA escalation every 15 minutes.
Schedule::command('ncr:escalate')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// T3.2.B — 8D SLA escalation (D3 / D4 / finalize) every 15 minutes.
Schedule::command('complaints:check-8d-slas')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// Series F / Task F4 — Monthly supplier performance recompute on the 1st at 02:00.
Schedule::command('purchasing:recompute-supplier-performance')
    ->monthlyOn(1, '02:00')
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

// Notification prune — delete read notifications older than 90 days.
Schedule::command('notifications:prune --days=90')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

// Prune expired permission overrides weekly on Sundays.
Schedule::command('overrides:prune-expired')
    ->sundays()
    ->at('02:45')
    ->withoutOverlapping()
    ->onOneServer();

// OGAMI-018 — Archive audit logs older than 12 months on the 1st at 04:00.
// ARCHIVE-ONLY: audit_logs is append-only (PostgreSQL BEFORE DELETE trigger
// from 2026_06_09_100001_add_audit_log_immutability_trigger.php RAISES on any
// delete). The command exports old rows to gzipped JSON under
// storage/app/audit-archives/ and never deletes the source rows. Idempotent
// (one file per closed month), so re-runs are safe no-ops.
Schedule::command('audit:prune --months=12')
    ->monthlyOn(1, '04:00')
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();

// OGAMI-018 — Daily database backup at 03:17 (off-peak, off-:00 to avoid the
// global cron stampede). Wraps scripts/db-backup.sh (pg_dump + gzip +
// retention + optional S3). Backups underpin the restore drill documented in
// docs/RESTORE-DRILL.md.
Schedule::command('db:backup')
    ->dailyAt('03:17')
    ->withoutOverlapping()
    ->onOneServer();

// T1.4 — Demand-driven safety stock recompute, nightly at 02:15.
Schedule::command('inventory:recompute-safety-stock')
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->onOneServer();

// T1.5 — AR dunning emails. Daily at 07:00 (after overnight batch jobs).
Schedule::command('ar:run-dunning')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->onOneServer();

// T3.4.C — Daily training expiry alerts at 06:30 (30/14/7/expired tiers).
// Idempotent within the same day — `alreadyFired()` short-circuits via
// `last_alert_level` so re-runs are safe.
Schedule::command('training:check-expiries')
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->onOneServer();

// T3.6.C — Monthly COPQ rollup snapshot on the 1st at 02:30. Snaps the
// PRIOR calendar month via CopqService::snapshot(). Idempotent
// (updateOrCreate keyed by period_year+period_month).
Schedule::command('copq:snap-monthly')
    ->monthlyOn(1, '02:30')
    ->withoutOverlapping()
    ->onOneServer();

// T3.5.D — Daily controlled-document review reminders at 06:45.
// Idempotent — re-fires only after `last_review_alert_at` is older than 7 days
// or the doc has been re-reviewed (clears the alert stamp).
Schedule::command('docs:check-reviews')
    ->dailyAt('06:45')
    ->withoutOverlapping()
    ->onOneServer();

// OGAMI-016 — recompute calibration due/overdue statuses daily at 06:50.
Schedule::command('calibration:check-due')
    ->dailyAt('06:50')
    ->withoutOverlapping()
    ->onOneServer();

// OGAMI-016 — batch unread notifications into a per-user email digest at 07:05.
Schedule::command('notifications:send-digest')
    ->dailyAt('07:05')
    ->withoutOverlapping()
    ->onOneServer();
