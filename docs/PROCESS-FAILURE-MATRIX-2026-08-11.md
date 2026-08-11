# Process Failure-Path Audit — 2026-08-11

This is the exhaustive failure-path inventory for the current ERP process
surface. It covers HTTP state transitions, cross-module events, outbox and
queue execution, scheduled commands/jobs, derived rebuilds, notifications,
external providers, and deployment workers.

The audit explicitly tested the scenarios that create a stuck, missing,
duplicated, broken, or falsely finished process:

- duplicate HTTP clicks and concurrent workers;
- a crash before commit, after commit, after outbox publication, and after an
  external provider accepts a request;
- Redis/queue outage, worker timeout, dead-lettering, and stale leases;
- deleted or misconfigured automation actors and missing business settings;
- partial batch failure, invalid source data, and a scheduler outage spanning
  the scheduled instant; and
- deployment, migration, cache, worker, scheduler, backup, and rollback
  ordering.

## Control matrix

| Process boundary | Duplicate / concurrency control | Failure truth and recovery | Result |
|---|---|---|---|
| HTTP state transitions and cross-module writes | Authoritative row reload + `lockForUpdate()` inside the transaction; status guards; unique keys where the business result is one-per-source | Transaction rollback leaves the source actionable; stale replay becomes a safe no-op or business error; chain outcome/bottleneck records the handoff | Closed for the audited Return, Loan, Overtime, GRN, Stock/GL, Production, Delivery/Invoice, Complaint/NCR, Supplier Invoice, and Payroll GL boundaries |
| Domain event → `event_outbox` | Outbox row is recorded in the same transaction as the source mutation; explicit dedupe keys where repeated triggers are possible | After-commit enqueue is an optimization only; minute-level `outbox:dispatch` reclaims pending/stale rows; failed rows remain visible for replay | Closed; staging still required |
| Outbox → queued listener | Event codec allowlist prevents unserializable/unknown payloads; `WithoutOverlapping` keys serialize entity work; a UUID lease token fences a reclaimed stale worker from overwriting a newer worker | Listener lifecycle telemetry records queued/processing/succeeded/failed; stale/null leases are reclaimed; failed queue jobs are included in automation health; chain recovery can replay or resolve; manual-required outcomes are not marked completed | Closed for registered chain listeners; external side effects remain at-least-once |
| Payroll compute | Durable `PayrollComputationRequested`; per-period overlap lock; unique payroll rows; stale `Processing` reaper | Listener checks the authoritative status; catastrophic failure releases the claim; `payroll:reap-stale-runs` repairs crashed workers; Redis lease is now above the 1,800-second job timeout | Closed; production invariant is `retry_after > 1800` (default 2400) |
| Payroll finalization → GL/bank/payslip | Durable handoff events and per-payroll delivery state; payslip claims are locked and bounded | Retry routes, dead-letter logging, chain bottlenecks, and manual-required outcomes preserve an operator path; `payroll:reconcile-payslip-emails` requeues only failed/pending/stale-queued payslips without replaying unrelated finalization listeners | Closed at source; real bank/provider verification remains external |
| Year-end leave processing and rollover | Durable request deduped by year/scope; active automation actor selection; year/type disposition idempotency; per-year overlap lock | Missing/inactive actor is `manual_required`; January 1–7 re-stages the prior year; rollover fails closed when any positive prior balance lacks a disposition, so it cannot silently double-handle leave | Closed in local code; large datasets and the January recovery window must be load-tested in staging |
| Budget actuals rebuild | Durable request deduped per target and scheduler minute; target fiscal year is explicit | Missing fiscal year now throws; listener retry/dead-letter path remains visible; rerun is a derived rebuild | Closed; route reachability and queue handoff covered |
| Scheduled exports → render → mail → advance schedule | Atomic database lease token with expiry prevents concurrent runners; explicit recipient validation; failed command is non-zero | Failure clears the lease, preserves `next_run_at`, records `last_error`, and retries on the next tick; abandoned leases expire; admin API exposes attempt/error/running state | Closed with an explicit at-least-once external-mail limitation |
| Preventive/predictive maintenance | Daily request is recorded in `event_outbox`; queued listener has bounded overlap and retries; schedule/work-order and machine/corrective-work-order rows are locked and rechecked | Missing actor throws; failed listener remains in queue/failed-job telemetry; duplicate delivery returns the existing open work order; `--force` creates a reviewed recovery request | Closed in local code; execute one real missed sweep in staging |
| Monthly depreciation | Monthly request is recorded in `event_outbox`; queued listener has a period lock and retries; unique asset/period records and stable asset locks prevent overlapping periods from overwriting accumulated balances | Missing actor throws; permanent failure is logged; rerun is idempotent; explicit synchronous command remains available for backfill | Closed in local code; execute one real missed-month backfill in staging |
| MRP and stale-run reapers | Run rows and source rows are transactionally reaped; orphan draft PRs are scoped to the dead run window | Per-row errors now make the command non-zero while other rows continue; hourly reaper repairs a crashed worker | Closed for stale-run visibility |
| Payroll calendar creation | Auto-period idempotency key plus overlap checks; explicit target year/month methods; daily in-window reconciliation | A missed day-14/last-day tick is recovered during the active half-month window; a Draft period whose durable compute request was not staged returns non-zero; full-window backfill remains explicit | Closed for active-window scheduler misses; older periods require reviewed target-date backfill |
| KPI and supplier monthly snapshots | `updateOrCreate` period keys; vendor/definition work is isolated so one failure does not stop the batch | Partial results return failed codes/vendor IDs and the command exits non-zero; explicit year/month options support backfill | Closed for false-green partial batches; prolonged scheduler outage still needs an operator backfill |
| Forecast actual reconciliation | Only unreconciled elapsed forecasts are selected; row updates are idempotent | A later run naturally catches up all elapsed null rows | Closed |
| Safety-stock rebuild | Item-level processing is isolated; locked items are skipped by policy | Item errors are counted separately from expected no-data skips; command exits non-zero on failure | Closed for false-green partial batches |
| AR dunning | Tier state prevents lower-tier resend; invoice is reloaded and row-locked per candidate; queued mail is deferred with `afterCommit` | Missing customer email is `blocked`; queue/notification exceptions are `failed`; command exits non-zero and leaves the invoice eligible for review/retry | Closed for false-green reporting and concurrent duplicate tiers; provider acceptance remains at-least-once |
| Alert checks | Each domain check is isolated so one broken source does not stop the other checks | Failed check labels are returned and `alerts:run` exits non-zero; critical-email delivery remains a separate best-effort side effect | Closed for scheduler truthfulness; notification delivery is an external prerequisite |
| Scheduled command lock and scheduler evidence | All registered `withoutOverlapping()` schedules now have an explicit bounded 10- or 120-minute expiry; `onOneServer()` uses shared cache/Redis; tick/task state is persisted in `scheduler_tick_runs` and `scheduler_task_runs`; production scheduler has a container healthcheck | `schedule:run-fail-fast` observes `ScheduledTaskFailed`; durable task state survives restart; `scheduler:health` fails for no/stale/failed ticks, stuck tasks, failed latest task state, or a restart gap; terminal evidence is retained 90 days and pruned without deleting stuck rows | Closed for lock wedging, false-green ticks, restart-gap visibility, and process-level health; exact business-period repair remains operator/policy-specific |
| Notification persistence / realtime / digest | In-app rows are written durably before `afterCommit` side effects; duplicate recipients are coalesced; digest subscribers are bounded in chunks | Realtime broker failure is isolated from the durable inbox row; queued notification listeners rethrow infrastructure failures; digest enqueue failures are counted and make the command non-zero; failed queue jobs surface through automation health | Closed for false-green local delivery paths; provider delivery remains at-least-once |
| Queue worker / Redis | AOF persistence; job-specific timeouts; local and production workers are aligned to the 1,800-second longest listener; retry lease exceeds that timeout; failed-jobs storage | Worker restart/redelivery is expected and guarded by idempotency/overlap locks; deployment starts workers only after migrations; `failed_jobs` count/age drives dashboard and hourly chain alerts | Closed in code/config; verify real Redis failover in staging |
| Deployment and schema | One-shot Compose migration dependency plus deploy script/Make ordering; bounded DB health wait; consumers start only after migration and cache rebuild | Failed migration stops API/consumers; deployment smoke failures are non-zero; scheduler failures restart the container; rollback retains recent releases; config/route cache runs after migration | Closed in local workflow/config; execute a staging rollback drill |
| Backups and restore | Atomic timestamped compressed backups, archive validation, archive retention, host-cron retention, explicit off-site upload failure, persistent host path; audit archives stream to a temp gzip and publish atomically | `db:backup`/host cron/deploy backup are non-zero on dump, copy, tool, or upload failure; corrupt audit archives are rebuilt; scratch PostgreSQL restore drill passes locally | Closed in local scripts; verify backup freshness, off-site copy, and app health on the VPS |

## Explicit residual risks

1. External mail/provider delivery is at-least-once. If the provider accepts a
   message and the process dies before the database success update, a retry can
   produce a duplicate. The system preserves the failed schedule/invoice
   state and operator evidence; exactly-once requires provider-side idempotency
   receipts.
2. A scheduler outage longer than a whole calendar period can still miss a
   monthly snapshot/depreciation invocation or an entire payroll cutoff. The
   durable tick ledger now preserves the restart gap and `scheduler:health`
   fails after recovery; supplier/KPI/depreciation commands accept explicit
   target backfills, and payroll accepts explicit target year/month values.
   The local regressions cover target-period idempotency and active-window
   recovery; staging must still exercise a real missed-period repair against
   operational data before release.
3. Notification-only listeners intentionally do not block the source business
   transaction. Queued listener infrastructure failures now rethrow for queue
   retry/dead-letter evidence; nested best-effort notifications and realtime
   broadcast failures are isolated after the durable inbox row is written.
   Mail/SMS/provider credentials and delivery receipts must be verified in
   staging.
4. `PostPayrollToGlJob` is a legacy compatibility job with no current
   application caller after the durable GL handoff. It now stages the durable
   `PayrollGlPostingRequested` outbox path instead of posting directly; remove
   it only after a deployment-reference audit confirms no old queue payloads or
   operator runbooks still dispatch it.
5. Real supplier transport, bank submission, and SMTP credentials are external
   prerequisites and cannot be proven by the local test database.

## Operator recovery map

```text
pending/stale outbox       → outbox:dispatch → chain bottleneck/replay UI
failed chain listener      → chain recovery replay or manual-required resolve
stuck payroll Processing    → payroll:reap-stale-runs → retry compute
stuck export lease         → wait for processing_until → inspect last_error → next tick
failed monthly snapshot    → rerun command with explicit --year/--month
missed maintenance sweep   → maintenance:request-preventive-generation --force
missed depreciation period  → assets:request-monthly-depreciation --year=Y --month=M --force
missed payroll cutoff       → payroll:reconcile-auto-periods; for a closed window use payroll:auto-create-period --half=H --year=Y --month=M
missed year-end leave       → leave:process-year-end --year=Y, then hr:reset-leave-balances --year=Y+1
stale/restarted scheduler   → scheduler:health --stale-minutes=15; inspect scheduler_tick_runs/task_runs
failed dunning             → inspect command/logs → rerun after provider/data fix
failed queue job           → failed_jobs + queue worker logs → replay after review
failed/stale payslip email → payroll:reconcile-payslip-emails → inspect failed_jobs/provider state
deployment/schema issue    → stop consumers → restore/release rollback runbook
```

## Evidence required before claiming live completion

- full backend and SPA release gates;
- migrations through `2026_08_11_170000` on PostgreSQL;
- real Redis worker replay with zero failed jobs;
- real worker interruption with Redis lease reclamation and second-attempt completion;
- queue lease, scheduler restart, and stale-lease recovery checks;
- one missed-month/backfill scenario;
- one provider timeout and one provider-accepted-before-crash scenario; and
- backup freshness/off-site copy plus a restore drill from the latest deployed
  artifact.

## Local verification status — 2026-08-11

The repository/container gate is complete for execution and static analysis:
PostgreSQL migrations through `2026_08_11_170000` apply cleanly, the isolated
backend passes **1,564 tests / 5,431 assertions** plus PHPStan, and the focused
scheduler/queue/chain/digest/payslip/notification recovery suites pass **83
tests / 265 assertions**. The SPA passes **24 files / 202 tests**, ESLint,
TypeScript, token audit, production build, PHP/shell syntax, production
Compose validation, and diff checks pass. `make chain-smoke` applies the
complete migration chain, runs a real Redis worker, publishes three outbox
messages, and ends with zero failed jobs and disposable-state cleanup.
`make worker-recovery-smoke` kills a real worker and verifies Redis reclaim plus
exactly-once completion on attempt two. The audited PHP files also pass their
scoped Pint check. Repository-wide Pint is still non-green because the
pre-existing baseline contains **1,531 style issues across 2,165 files**;
mass-formatting that unrelated dirty surface was deliberately not performed.
A fresh backup restores into a scratch PostgreSQL with `users=14` and
`event_outbox=0`; the host-cron copy path also validates successfully and
fails closed when configured S3 tooling is unavailable.

The disposable `make worker-recovery-smoke` drill kills a real Redis queue
worker while a test-only probe is executing, waits for the bounded retry lease,
and verifies the reclaimed job completes on attempt two exactly once. It uses a
unique Redis prefix and does not touch application tables; its namespace was
verified empty after the drill.

The remaining evidence is deployment-specific staging work: scheduler-restart,
a missed-month backfill against real operational data, provider
timeout/accepted-before-crash scenarios, Redis failover, and backup
freshness/restore verification.
