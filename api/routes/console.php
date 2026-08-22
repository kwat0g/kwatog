<?php

declare(strict_types=1);

use App\Modules\Accounting\Services\AccountingPeriodService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    echo Inspiring::quote().PHP_EOL;
})->purpose('Display an inspiring quote');

/*
 * Sprint 8 — Task 69. Daily cron — stage a durable request to materialise
 * preventive maintenance WOs (time-based schedules due now, mold-shot
 * schedules at 100% threshold).
 */
Schedule::command('maintenance:request-preventive-generation')
    ->dailyAt('02:00')
    ->name('maintenance:generate-preventive')
    ->withoutOverlapping(120)
    ->onOneServer();

/*
 * Sprint 8 — Task 70. Stage monthly depreciation on the 1st at 03:00 for
 * the previous calendar month. The outbox/listener path is retryable and the
 * execution remains idempotent.
 */
Schedule::command('assets:request-monthly-depreciation')
    ->monthlyOn(1, '03:00')
    ->name('assets:run-monthly-depreciation')
    ->withoutOverlapping(120)
    ->onOneServer();

/* ─── Automation tasks A1–A10 ─────────────────────────────────────── */

// A1 — Daily MRP run
Schedule::command('mrp:run-daily')
    ->dailyAt('06:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// OGAMI-015 — Hourly reaper for hung Running MRP runs. Marks runs whose
// whose heartbeat is older than 2h as Failed. Draft auto-PRs are reconciled by
// the next MRP run because ownership is not safe to infer from timestamps.
Schedule::command('mrp:reap-stale-runs')
    ->hourly()
    ->withoutOverlapping(120)
    ->onOneServer();

// A2 — Alert engine every 15 minutes
Schedule::command('alerts:run')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// OGAMI-001 — Auto-relock reopened accounting periods that have been open for > 48h
Schedule::call(function () {
    app(AccountingPeriodService::class)->relockStaleReopenedPeriods(48);
})
    ->hourly()
    ->name('accounting:relock-periods')
    ->withoutOverlapping(120)
    ->onOneServer();

// A3 — Auto payroll period creation
//   On the 14th at 23:00 → create period for 16th–end-of-month
//   On the last day at 23:00 → create period for 1st–15th of next month
Schedule::command('payroll:auto-create-period --half=second')
    ->monthlyOn(14, '23:00')
    ->withoutOverlapping(120)
    ->onOneServer();
Schedule::command('payroll:auto-create-period --half=first')
    ->lastDayOfMonth('23:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// Recovery sweep for a scheduler outage inside an active cutoff window. The
// command only creates the current first/second half, so a period missed for a
// complete window still requires an explicit year/month operator backfill.
Schedule::command('payroll:reconcile-auto-periods')
    ->dailyAt('23:30')
    ->withoutOverlapping(120)
    ->onOneServer();

// Hourly reaper for payroll periods wedged at Processing by a crashed compute
// worker (OOM, SIGKILL, container restart). Puts them back on Computed/Draft so
// the list and pipeline views self-heal. Idempotent — re-runs are no-ops.
Schedule::command('payroll:reap-stale-runs')
    ->hourly()
    ->withoutOverlapping(120)
    ->onOneServer();

// Recover payslip child jobs after finalization has already completed. This
// targets only pending/failed/stale-queued delivery rows, so it never replays
// the unrelated bank-file, GL, or notification listeners for the period.
Schedule::command('payroll:reconcile-payslip-emails')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// A5 — Preventive maintenance evaluation runs the existing Sprint 8
//      job; the new running-hours recompute runs daily before that job.
Schedule::command('maintenance:recompute-hours')
    ->dailyAt('06:30')
    ->withoutOverlapping(120)
    ->onOneServer();

// A7 — Approval escalation every 6 hours
Schedule::command('approvals:run-escalations')
    ->everySixHours()
    ->withoutOverlapping(120)
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
    ->withoutOverlapping(120)
    ->onOneServer();

// A10 — End-of-day production summary email at 18:00 (and weekly Friday)
Schedule::command('production:send-daily-summary')
    ->dailyAt('18:00')
    ->withoutOverlapping(120)
    ->onOneServer();
Schedule::command('production:send-weekly-summary')
    ->fridays()
    ->at('18:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// U4 — Onboarding reminders. Daily at 09:00, notifies HR for any
// employee onboarding open > 3 days without completion.
Schedule::command('hr:onboarding-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// Recruitment lifecycle recovery — hourly scan for applications, interviews,
// and postings that can wait indefinitely without an operator prompt.
Schedule::command('recruitment:check-bottlenecks')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer();

// Series C — Task C5. Chain bottleneck scan, hourly. Idempotent (24h
// dedup window inside the command), so re-running is safe.
Schedule::command('chain:check-bottlenecks')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer();

// Cross-module domain-event outbox. The queue push after a business commit is
// only an optimization; this minute-level poll recovers rows when Redis or a
// worker is unavailable during the after-commit enqueue callback.
Schedule::command('outbox:dispatch --limit=100')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

// Reclaim supplier dispatch rows left pending by a crashed worker. Failed
// provider rows stay for explicit `--retry-failed` review; portal/manual rows
// are human proof states and are never retried by this sweep.
Schedule::command('supplier:dispatch-recover --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// Series C — Task C3. Leave-year recovery window. The first task re-stages
// the prior year's durable year-end disposition after a Dec-31 outage; the
// second retries rollover only after that handoff has had time to complete.
// Both commands are idempotent and run for the first seven days of January so
// a transient worker/scheduler outage does not permanently lose the window.
Schedule::command('leave:process-year-end --previous-year')
    ->dailyAt('00:00')
    ->name('leave:recover-year-end')
    ->when(static fn (): bool => Carbon::now()->month === 1 && Carbon::now()->day <= 7)
    ->withoutOverlapping(120)
    ->onOneServer();
Schedule::command('hr:reset-leave-balances')
    ->dailyAt('00:30')
    ->when(static fn (): bool => Carbon::now()->month === 1 && Carbon::now()->day <= 7)
    ->withoutOverlapping(120)
    ->onOneServer();

// Series E (Task E2) — every 5 minutes scan for due scheduled exports
// and fire them off. Idempotent (each row's next_run_at advances on
// success), so re-runs are safe.
Schedule::command('exports:run-due')
    ->everyFiveMinutes()
    ->withoutOverlapping(120)
    ->onOneServer();

// Notification prune — delete read notifications older than 90 days.
Schedule::command('notifications:prune --days=90')
    ->dailyAt('02:30')
    ->withoutOverlapping(120)
    ->onOneServer();

// Prune expired permission overrides weekly on Sundays.
Schedule::command('overrides:prune-expired')
    ->sundays()
    ->at('02:45')
    ->withoutOverlapping(120)
    ->onOneServer();

// OGAMI-018 — Archive audit logs older than 12 months on the 1st at 04:00.
// ARCHIVE-ONLY: audit_logs is append-only (PostgreSQL BEFORE DELETE trigger
// from 2026_06_09_100001_add_audit_log_immutability_trigger.php RAISES on any
// delete). The command exports old rows to gzipped JSON under
// storage/app/audit-archives/ and never deletes the source rows. Idempotent
// (one file per closed month), so re-runs are safe no-ops.
Schedule::command('audit:prune --months=12')
    ->monthlyOn(1, '04:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// OGAMI-018 — Daily full backup at 03:17 (off-peak, off-:00 to avoid the
// global cron stampede). Wraps scripts/db-backup.sh plus the private-file
// archive (gzip/tar + retention + optional S3). Backups underpin the restore
// drill documented in docs/RESTORE-DRILL.md.
Schedule::command('db:full-backup')
    ->dailyAt('03:17')
    ->withoutOverlapping(120)
    ->onOneServer();

// Keep scheduler evidence bounded without deleting a running/stuck record.
Schedule::command('scheduler:prune-ledger --days=90')
    ->dailyAt('03:30')
    ->withoutOverlapping(120)
    ->onOneServer();

// T1.4 — Demand-driven safety stock recompute, nightly at 02:15.
Schedule::command('inventory:recompute-safety-stock')
    ->dailyAt('02:15')
    ->withoutOverlapping(120)
    ->onOneServer();

// T1.5 — AR dunning emails. Daily at 07:00 (after overnight batch jobs).
Schedule::command('ar:run-dunning')
    ->dailyAt('07:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// Rollout observability — emits plan coverage, missed QC triggers, scanner
// recognition, and overdue Action Center work to the scheduler log hourly.
Schedule::command('operations:rollout-health')
    ->hourlyAt(10)
    ->withoutOverlapping(120)
    ->onOneServer();

// T3.4.C — Daily training expiry alerts at 06:30 (30/14/7/expired tiers).
// Idempotent within the same day — `alreadyFired()` short-circuits via
// `last_alert_level` so re-runs are safe.
Schedule::command('training:check-expiries')
    ->dailyAt('06:30')
    ->withoutOverlapping(120)
    ->onOneServer();

// OGAMI-016 — recompute calibration due/overdue statuses daily at 06:50.
Schedule::command('calibration:check-due')
    ->dailyAt('06:50')
    ->withoutOverlapping(120)
    ->onOneServer();

// OGAMI-016 — batch unread notifications into a per-user email digest at 07:05.
Schedule::command('notifications:send-digest')
    ->dailyAt('07:05')
    ->withoutOverlapping(120)
    ->onOneServer();

// OGAMI-104 — Year-end leave forfeiture/conversion. Scheduled Dec 31 at 23:00
// so it runs just before the year rolls over. Idempotent via
// processed_year_end_leave_types table, so re-runs are safe no-ops.
Schedule::command('leave:process-year-end')
    ->yearlyOn(12, 31, '23:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// Budget-vs-actual GL sync on the 1st at 03:00 (after monthly depreciation).
// Idempotent: re-runs overwrite actual_total with current GL balance each time.
Schedule::command('budget:sync-actuals')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// CAPA effectiveness loop (IATF 16949 §10.2.1) — notify owners of due/overdue
// verification checks daily at 02:05. Idempotent.
Schedule::command('ncr:check-effectiveness')
    ->dailyAt('02:05')
    ->withoutOverlapping(120)
    ->onOneServer();

// ADV11 — Monthly forecast accuracy reconciliation on the 2nd at 04:00.
// Backfills actual_quantity & variance for elapsed forecast periods. Idempotent.
Schedule::command('forecasting:reconcile-actuals')
    ->monthlyOn(2, '04:00')
    ->name('forecasting:reconcile-actuals')
    ->withoutOverlapping(120)
    ->onOneServer();

// Task 14 — KPI monthly snapshot on 2nd at 03:00 for the previous month.
// Iterates all active KpiDefinition rows, calls each calculator, and upserts
// KpiSnapshot rows. Idempotent (updateOrCreate keyed by definition+period).
Schedule::command('kpi:compute-monthly')
    ->monthlyOn(2, '03:00')
    ->name('kpi:compute-monthly')
    ->withoutOverlapping(120)
    ->onOneServer();
