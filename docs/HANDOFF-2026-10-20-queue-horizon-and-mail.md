# Handoff — queue/Horizon rework + SES mail (2026-10-20)

Everything an incoming agent needs to continue without re-deriving it. Two workstreams
landed in one session: **(A) the queue system was rebuilt on Horizon with isolated lanes**
and **(B) SES mail was configured and verified — but it has since been switched off by
another session, see §B2.** Read §B2 before doing anything with mail.

Repo: `/home/ec2-user/kwatog` (this box is the VPS; live stack is `docker-compose.yml` +
`docker-compose.vps.yml`, project name `kwatog`). Branch: `fix/ui-ux-wave1-shell`.

---

## 0. Current state in one screen

| Thing | State |
|---|---|
| Queue runtime | `ogami-horizon` container, Horizon v5.50.1, healthy, 5 supervisors / 7 workers |
| Lanes | `high`, `default`, `mail`, `automation`, `bulk` — one supervisor each |
| Old containers | `ogami-queue`, `ogami-queue-low` **removed** |
| Old systemd worker | `ogami-queue.service` **disabled + inactive**; unit file deleted from repo; backup at `/home/ec2-user/ogami-queue.service.disabled.bak` |
| Lease bug | fixed — `retry_after` 2400 → **3900**, and per-supervisor leases |
| Poll latency | replaced 3s sleep with `block_for=5` (BLPOP) |
| Mail | SES SMTP active; queued integration test accepted (see §B2) |
| Drift guard | `api/tests/Feature/Queue/QueueLaneAssignmentTest.php` — 11 tests, 114 assertions, green; covers 25 queued mailables |
| failed_jobs | 17 rows, all pre-existing and non-mail (15 missing-settings, 1 outbox `PayrollPeriod#8`, 1 maintenance automation actor) |

**Read this before touching the working tree.** `git status` is dirty with ~70 files that are
**not** part of this workstream — the branch also carries in-flight delivery ship-to / driver /
customer-import work (`SupplyChain/*`, `Accounting/*Customer*`, `DocumentController`,
`DocumentType`, `0579_add_delivery_contact_*`, `DeliveryReceiptService`, the delivery PDF view and
its tests), a UI/UX wave (`spa/src/components/{layout,ui}`, `crm/customers/*`, `driver/*`,
`types/*`, `vite.config.ts`), and assorted docs/notes. Those files were already modified when
this session started and this handoff does **not** describe them.

The queue/mail work is exactly: every `*/Mail/*`, `*/Jobs/*`, `*/Listeners/*`,
`HR/Notifications/*`, `config/horizon.php`, `config/queue.php`, `HorizonServiceProvider.php`,
`routes/console.php`, `composer.{json,lock}`, both compose files, `docker/systemd/` (deletion),
`scripts/deploy-update.sh`, `scripts/ogami-healthcheck.sh`, `Makefile`,
`.github/workflows/deploy.yml`, the six `docs/*.md` renames, and
`tests/Feature/Queue/QueueLaneAssignmentTest.php`.

---

# Part A — Queue rework

## A1. The diagnosis (measured, not assumed)

The reported symptom was "important jobs are slow while the email queue is busy". The
causes were three unrelated things, none of them "we need more workers":

1. **Mail shared the busiest lane.** All 25 mailables (21 module + 4 shared) landed on `default`, together with
   67 listeners. A send is ~450ms (measured on `PortalPasswordResetMail`), so a
   200-recipient run held that lane ~90s in front of any approval or GRN notice.
2. **Business automation shared a lane with backups.** `RunAutomaticMrpJob`,
   `ProcessPayrollJob`, depreciation, year-end leave, budget sync and PM generation were
   all on `low`, together with `CreateBackupJob` / `RestoreBackupJob`. `low` had exactly
   one worker and `RestoreBackupJob` has a 3600s timeout — so a restore was head-of-line
   blocking MRP for up to an hour. **This is what "MRP is slow" actually was.**
3. **An undocumented third worker.** `/etc/systemd/system/ogami-queue.service` ran
   `docker exec ogami-api php artisan queue:work --tries=3 --timeout=1800 --sleep=3 --max-time=3600`.
   It had **drifted from the repo copy** (`docker/systemd/ogami-queue.service`), missing
   `--queue=high,default`, so it only drained `default`; it ran inside the API container
   competing with php-fpm; it exits hourly by design and systemd restarted it
   (`NRestarts=403`).

Plus a **real bug**: `retry_after` is a **per-connection lease**, and it was 2400s while
the bulk timeout is 3600s. A restore running past 40 minutes was re-reserved while still
executing — duplicate destructive work. The old `docker-compose.yml` comment claimed the
opposite ("the low lane's longest lease still applies"), which is false.

Two more measured facts drove the design:

* `queue:work --queue=high,default` is **serial**: priority decides what a worker picks
  *next*, never what it interrupts. A 15s `default` job delayed every `high` job by 15s.
* `--sleep=3` meant a job pushed just after an empty poll waited up to 3s on an otherwise
  idle worker. Measured worst case: **2.565s** for a `high` job behind one slow `default`
  job.

**Why Horizon rather than hand-rolled workers:** it is the first-party answer for exactly
these three properties — one pool per queue, per-supervisor `timeout`/`retry_after`, and
wait-time autoscaling — and it adds metrics/visibility, which is what made this problem
invisible in the first place. It also honours a per-supervisor lease; its default when a
supervisor omits one is **60s** (`Laravel\Horizon\Connectors\RedisConnector`), which would
have made the duplicate-execution bug worse, so every lane states both numbers explicitly.

## A2. Lane contract

Defined in `api/config/horizon.php`. The lane is chosen **in code** by the producer; this
file only decides how many processes serve it.

| Lane | Serves | balance | timeout | lease (`retry_after`) | tries |
|---|---|---|---|---|---|
| `high` | realtime bus / outbox (`DispatchOutboxMessage`) | auto 1→4 | 120 | 300 | 3 |
| `default` | interactive listeners (approvals, escalations, GL) | auto 2→8 | 300 | 900 | 3 |
| `mail` | 25 mailables + 2 queued notifications + payslip fan-out | auto 2→6 | 120 | 300 | 3 |
| `automation` | MRP, payroll, depreciation, year-end leave, budget sync, PM | auto 1→3 | 1800 | 2100 | 3 |
| `bulk` | backup, restore, exports | single process | 3600 | 3900 | **1** |

`bulk` has `tries => 1` deliberately: a retried restore replays half-applied work.

Production overrides live in `horizon.environments.production` (larger `maxProcesses`);
`local` keeps the sizes small. The live stack runs `APP_ENV=local`, so the `local` block is
what is in force here.

## A3. Files changed

**New**
- `api/config/horizon.php` — the lane contract above, plus `waits` thresholds per lane.
- `api/app/Providers/HorizonServiceProvider.php` — gate + explicit `Horizon::auth()`.
- `api/app/Common/Listeners/NotifySystemAdminsOnQueueLongWait.php` — deduplicated in-app
  alerts from Horizon's `LongWaitDetected` event.
- `api/tests/Feature/Queue/QueueLaneAssignmentTest.php` — lane drift guard;
  `HorizonLongWaitNotificationTest.php` — long-wait alert behavior.

**Wiring**
- `api/bootstrap/providers.php` — `HorizonServiceProvider` registered (added by `horizon:install`).
- `api/composer.json` / `api/composer.lock` — `laravel/horizon ^5.50` (+ `laravel/sentinel`, its dep).
- `api/routes/console.php` — `horizon:snapshot` every 5 minutes (metrics history).
- `api/config/queue.php` — `retry_after` default **3900** with a corrected comment
  explaining that the lease is per *connection*, and `block_for` (default 5).
- `api/.env` — `REDIS_QUEUE_RETRY_AFTER=3900` (was 2400; this env value overrides config).
- `api/app/Providers/HorizonServiceProvider.php` registers `LongWaitDetected` for an
  in-app alert to active `system_admin` users; `queue.long_wait` is in the notification catalog.

**Producers moved onto a lane**
- 21 mailables under `api/app/Modules/*/Mail/*.php` — each constructor now does
  `$this->onQueue('mail');` immediately before `$this->afterCommit();`.
- Four shared queued mailables under `api/app/Common/Mail/` also use `mail`:
  `EmailIntegrationTestMail`, `NotificationDigestMail`, `ScheduledExportMail`, and
  `UserNotificationMail`. The drift guard sweeps all `app/**/Mail` directories.
- `api/app/Modules/HR/Notifications/EmployeeWelcomeNotification.php` and
  `EmployeePasswordResetNotification.php` — same call after `parent::__construct()`.
- `api/app/Common/Jobs/CreateBackupJob.php`, `RestoreBackupJob.php` → `bulk`.
- `SyncBudgetActuals`, `RunMonthlyDepreciationJob`, `ProcessYearEndLeave`,
  `RunAutomaticMrpJob`, `GeneratePreventiveMaintenanceJob`, `ProcessPayrollJob` → `automation`.
- `SendPayslipEmailJob` → `mail`.
- Five listeners that run their work inline via `viaQueue()` → `automation`:
  `RunBudgetActualsSyncOnRequested`, `RunMonthlyDepreciationOnRequested`,
  `RunYearEndLeaveOnRequested`, `GeneratePreventiveMaintenanceOnRequested`,
  `RunPayrollComputationOnRequested`.

**Infrastructure / operations**
- `docker-compose.yml`, `docker-compose.prod.yml` — `queue` + `queue-low` replaced by one
  `horizon` service (`php artisan horizon`, `stop_grace_period: 60s`, healthcheck
  `horizon:status | grep -q 'is running'`).
- `docker/systemd/ogami-queue.service` — **deleted** (git rm). It was the drifted duplicate.
- `scripts/deploy-update.sh`, `Makefile`, `.github/workflows/deploy.yml`,
  `scripts/ogami-healthcheck.sh` — service list `queue` → `horizon`; `queue:restart` →
  `horizon:terminate`; healthcheck expects `ogami-horizon`.
- `docs/DEPLOY.md`, `docs/DEMO-SCRIPT.md`, `docs/SEP-4-DEMO.md`,
  `docs/PROCUREMENT-E2E-MANUAL-TEST-RUNBOOK.md`, `docs/RFQ-BIDDING-E2E-TEST-GUIDE.md`,
  `docs/testing/pricing-risk-resolution.md` — same rename, so the documented procedures
  don't 404.

## A4. Invariants — do not regress these

1. **One supervisor per queue.** Two supervisors on one queue is untested double
   consumption.
2. **`retry_after` > `timeout` on every supervisor.** Equal or lower means Redis hands the
   job to a second worker mid-flight. Horizon defaults the lease to 60s if omitted.
3. **The connection-wide floor must exceed the longest timeout** (3900 > 3600), because
   `config/queue.php`'s lease is per connection, not per queue.
4. **A producer must never name a lane with no supervisor** — that job sits in Redis
   forever, with no error. This is exactly what `low` would have become.
5. **Mailables set the lane in the constructor.** A trait/base class **cannot** carry
   `$queue`: `Illuminate\Bus\Queueable` already declares `public $queue`, and redeclaring
   it with a default is a fatal error
   (`ParentMail and Queueable define the same property ($queue) … considered incompatible`).
   An inherited parent default fails the same way. Verified experimentally, twice.

All five are asserted by `QueueLaneAssignmentTest`. It also sweeps every file under
`api/app` for `onQueue('…')` and fails if the lane isn't in the contract — so adding a job
on a dead lane breaks the build rather than silently queueing forever.

## A5. Measurements (live stack, same box)

| Scenario | Before | After |
|---|---|---|
| `high` pickup, idle lane | 176ms, up to 2.565s after a poll | **17ms** |
| `high` pickup during a 6s `default` listener + 40-mail burst | 2.565s (same lane as mail) | **40ms** |
| `automation` pickup, idle | n/a — shared lane with backup | **4–21ms** |
| `automation` pickup during the 40-mail burst | — | ~1s (CPU contention; returns to single-digit ms when idle) |
| `bulk` pickup during burst | — | 1.3s |

Method: probe closures write `microtime(true)` marks to `storage/app/*.log`; push time vs
execution start. See §A8 for the exact harness and its one pitfall.

## A6. Operations

```bash
# status / health
docker compose exec horizon php artisan horizon:status
docker compose ps ogami-horizon

# after changing worker code or config/horizon.php
docker compose exec horizon php artisan horizon:terminate
docker compose restart horizon
# `horizon:terminate` exits the master but does not restart this container.

# metrics history (also scheduled every 5 min)
docker compose exec horizon php artisan horizon:snapshot

# lane depths
docker compose exec redis redis-cli LLEN ogami_erp_api_queues:mail

# dashboard: NOT exposed publicly (nginx routes everything but /api, /sanctum,
# /storage, /ws to Vite). Reach it over a tunnel, gate still applies:
ssh -L 8081:127.0.0.1:80 <vps>   # then http://127.0.0.1:8081/horizon
```

Dashboard access is gated to `system_admin` (verified: admin allow, `employee` deny, guest
deny). `Horizon::auth()` is set **explicitly** because Horizon's default authorisation
short-circuits to "allowed" whenever `APP_ENV=local` — which is what this VPS runs, so the
default would publish job payloads to the internet.

## A7. Adding work to the queue (the two rules)

* New **job / listener**: pick an existing lane, set it (`onQueue('automation')` in the
  constructor, or `viaQueue()` on a listener). Never invent a lane without adding a
  supervisor. `QueueLaneAssignmentTest` will fail if you name one that doesn't exist.
* New **mailable**: `$this->onQueue('mail');` in the constructor (the test sweeps for
  this string). Queued **notification**: same call after `parent::__construct()`.
* Tests run with `QUEUE_CONNECTION=sync` (`api/phpunit.xml:264`), where lane names are
  ignored — so a green suite proves nothing about lane correctness. The guard test is what
  does.

## A8. Probe pitfall (cost me three failed jobs)

Probing with **arrow-function closures** pushed via tinker fails to hydrate
(`Error: Call to a member function getName() on null` in `BoundMethod`) and leaves failed
rows behind. Use explicit `function () use ($log) { … }` closures defined in a **file** on
disk (tinker `--execute="require '/tmp/probe.php';"`), never `eval`'d code. The three
failed rows from this were deleted by matching the error signature; `failed_jobs` is back
to its pre-existing 17.

---

# Part B — SES mail

## B1. What was configured and verified (2026-10-20, ~17:45–18:15 UTC)

Provider: **Amazon SES Mail Manager SMTP ingress**, region `ap-southeast-1` (pinned from the
SMTP greeting, not guessed).

Verified, in order:
- TCP + STARTTLS on 587 → `220 Ready to start TLS`.
- SMTP AUTH → `235 Authentication successful`.
- Raw SMTP conversation outside Laravel → `250 OK s8b1jl7kv19h6s7ma2n0uk1tqsramvmrl8nb92g2`.
- `Mail::raw` through the app → sent.
- Queued path through a real `ShouldQueue` mailable → worker log `PortalPasswordResetMail … 448.22ms DONE`, queue drained.
- Two test messages addressed to `bobokaba434@gmail.com` were accepted by SES. **Inbox
  arrival was never verifiable from this box** — that limitation was stated, not glossed.

The block written into `api/.env` (and mirrored into root `.env` for the
`docker-compose.prod.yml` path) was:

```ini
MAIL_MAILER=smtp
MAIL_HOST=7s7miqfkt3xa.uemp.mail-manager-smtp.amazonaws.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=inp-6zfo5toinc7vswotjaauqtff
MAIL_PASSWORD=<the Mail Manager ingress SMTP password>
MAIL_FROM_ADDRESS="donotreply@ogamiph.dev"
MAIL_FROM_NAME="Ogami Philippines"
```

Notes worth keeping: the credential is a **Mail Manager ingress** secret (not the 44-char
IAM SMTP format); `PayslipMail` is the one mailable that is **not** queued, so a transport
fault there surfaces as an HTTP 500 on the payslip action instead of in `failed_jobs`.

## B2. Current state — SES SMTP active

On 2026-10-20, user-provided Mail Manager credentials were restored in both root `.env`
and `api/.env`. The credential values stay in those untracked files; never copy them into
tracked files or logs. The test below used `sales@ogami.com.ph`; user later clarified this
is a mock sender and set the app sender to `donotreply@ogamiph.dev`.

Sent `EmailIntegrationTestMail` to `bobokaba434@gmail.com` through Horizon. The job
completed on queue `mail` (`560.75ms DONE`), the queue drained, and no SMTP transport error
appeared in Horizon logs. This confirms SMTP accepted the message; inbox arrival cannot be
verified from this host.

**SMTP is now enabled for all app mail**, not only integration tests. Do not mass-trigger
invoice/PO mail to the `@example.test` demo records; SES bounce rate above ~5% risks
suspension. `.env` files must stay untracked.

The Mail Manager ingress endpoint is active and has an unconditional `Send to internet`
rule. Its rule set has no log delivery configured. The sender identity and the rule action's
SES IAM permissions still need verification before another delivery test.

**2026-10-21 update:** the user confirms the `ogamiph.dev` identity is verified and the
account is out of sandbox; the earlier console screenshot showing "Verification pending"
was stale. The two career emails found in `laravel.log` were both rendered while
`MAIL_MAILER=log` (before the SMTP restore), so they never reached SES. If a post-restore
test still misses the inbox, inspect the rule action's IAM role and add log delivery to the
rule set; the ingress accepts mail before the rule runs.

---

# Part C — Pre-existing failures (not from this work)

Proven by parking **only** the 39 queue-related files (`git stash push -- <those>`), then
re-running: the failures are byte-for-byte identical with those files at HEAD.

* **9 E2E failures** — `OrderToCashChainTest` (3), `ProcureToPayChainTest` (5),
  `AssetsMaintenanceAuditTest` (1). These belong to this branch's in-flight
  delivery/customer work, not the queue.
* **1 notification failure** — `WorkOrderScheduleReminderTest` expects 2
  `production.assignment.review` rows, gets 4 (dedupe broken). Fails identically at HEAD.

Latest validation on a fresh isolated database:
- Focused queue, long-wait alert, backup recovery, notification catalog, and scheduled-export
  mail tests: **36 passed / 232 assertions**.
- Broader `--filter='Notification|Mail|QueueLane'`: **205 passed / 1 known pre-existing
  failure / 745 assertions**. Failure remains `WorkOrderScheduleReminderTest` expecting 2
  rows but receiving 4; handoff §C records that this is identical at HEAD.
- PHPStan on the four shared mailables and queue drift test passed; `git diff --check` passed;
  `horizon:status` reports running.

Full repository suite was not rerun in this continuation.

---

# Part D — Open items

1. **Decide the mail state** (§B2) and, if restoring SES, re-enter the credential.
2. The 9 E2E + 1 notification failures above.
3. **Completed.** Restore pauses and drains every non-`bulk` Horizon lane before maintenance,
   then resumes those lanes on success or failure. `QueueLaneAssignmentTest` covers the drain
   and resume behavior.
4. **Completed.** `LongWaitDetected` notifies active `system_admin` users through
   `NotificationService::sendInApp()`. Alerts deduplicate for a rolling five minutes and
   listener errors release the throttle before propagating. `HorizonLongWaitNotificationTest`
   covers event wiring, recipient scope, deduplication, and error propagation.
