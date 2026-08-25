# M008 — Alerts audit report

- Audit date: 2026-08-24
- Domain/module: platform / alerts
- Tier: 4
- Surface: S
- Dependencies: auth-session, notifications
- Roles: system_admin, hr_officer, finance_officer, production_manager, ppc_head, purchasing_officer
- Status: Plan Ready
- Source changes: none; this session produced audit artifacts only.

## Scope and summary

The audit covered the alert enum/catalog, threshold engine, persistence and
deduplication, critical-email fan-out, scheduler-health handoff, settings,
API/RBAC routes and resources, dashboard/action-center consumers, SPA list and
dismiss/read behavior, migrations, release configuration, and focused tests.

The module has a real cross-module implementation: five authenticated,
permission-gated API routes; a scheduled engine with per-check failure
isolation; a durable scheduler execution ledger; hashed public alert and
entity identifiers for the monitored models; severity/type filtering; and
focused AR, scheduler, email-recipient, authentication, and rate-limit tests.

It is not production-ready as an operational control surface. The creation
path performs a non-atomic check-then-insert, critical-email failure is not
retryable for an existing alert, AP due-soon behavior does not match the
usual window interpretation of its setting, alerts have no automatic
resolution or retention policy, and the full-table threshold scan contains
unbounded loads and per-row queries. The SPA also lags the backend enum and
does not expose pagination or the existing read action. These findings affect
alert correctness, recovery, data volume, and cross-module contracts, so no
production source fixes were applied.

## Production readiness lens

Assessment: Risky. This is a release-surface assessment, not an approval to
enable the alert engine in production.

High-value blockers:

- Concurrent or independently triggered raises can create duplicate alerts and
  duplicate critical fan-out because deduplication is not enforced atomically.
- A critical email send failure is caught and surfaced through a fallback, but
  the existing alert is returned on every later run without another email
  attempt.
- The active-alert list is a dismissed/not-dismissed history rather than a
  current-condition view; there is no resolution/rearm contract or alert
  retention.
- The AP due-soon query can miss bills that are inside the intended due-soon
  window, depending on the product decision for that setting.

Evidence checked:

- Backend engine, model, migration, controller, resource, request, enum,
  settings, scheduler command/registration, scheduler ledger, and critical
  notification.
- SPA API/types/page/route and dashboard/action-center readers.
- Recent alert-related commits, all direct alert feature tests, relevant auth
  tests, notification recipient regression tests, release compose
  configuration, and repository-wide alert call sites.
- Serial container-backed tests: 8 alert/notification tests with 29
  assertions; 18 authentication/rate-limit tests with 60 assertions.
- SPA typecheck and targeted ESLint; PHP syntax checks for the five core
  backend files; alert route listing.

Evidence still missing:

- Production alert volume, query plans, and actual engine runtime at expected
  item, invoice, bill, work-order, and output counts.
- Live queue/mail-provider handoff and delivery/complaint behavior, including
  the configured role audience for every critical type.
- Operator decision on whether scheduler_stale and mrp_run_failed must email,
  and whether AP due soon means an exact offset or an inclusive range.
- A production migration/rollback and retention procedure that preserves
  required alert history.

Next action: resolve the lifecycle, deduplication, delivery, AP-window, and
retention contracts in a coordinated implementation session, then re-audit
the API and SPA together under the acceptance gates in action-plan.md.

## Findings

### F-001 — Alert deduplication is a non-atomic check-then-insert

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: AlertEngineService::raise() searches for a recent undismissed
  match at api/app/Common/Services/AlertEngineService.php:60-75 and inserts
  at :77-85, with no transaction, lock, unique deduplication key, or
  conflict-recovery path. The alerts migration has only ordinary indexes at
  api/database/migrations/0111_create_alerts_table.php:34-37. The service is
  called by the scheduled alerts:run path and by other command/service paths
  including chain bottlenecks, MRP automation, and automatic purchase orders
  (for example api/app/Console/Commands/RunChainBottleneckCheck.php:97 and
  api/app/Modules/MRP/Services/MrpAutomationService.php:76-109).
- Impact: Two scheduler/manual/worker invocations can both observe no match
  and create duplicate rows. Critical duplicates also invoke the email fan-out
  twice. The scheduler's onOneServer and withoutOverlapping controls do not
  protect independent callers of raise().
- Recommendation: Define a stable deduplication key and enforce it at the
  database/transaction boundary, or use an advisory/row-lock strategy with
  duplicate-conflict recovery. Make the winning row and critical delivery
  decision deterministic, and add PostgreSQL interleaving tests.

### F-002 — Failed critical email delivery is not retryable for an existing alert

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: raise() returns the existing alert immediately at
  api/app/Common/Services/AlertEngineService.php:72-75, so
  emailCritical() at :87-89 runs only when a new row is inserted. The
  delivery method stamps notified_email_at after Notification::send() at
  :583-584, catches failures at :585-598, and never reads that timestamp on a
  later raise. CriticalAlertEmail uses Queueable but does not implement
  ShouldQueue at api/app/Common/Notifications/CriticalAlertEmail.php:11-22.
  The migration stores only a nullable notified_email_at at
  api/database/migrations/0111_create_alerts_table.php:28-32; there is no
  attempt, failed-at, provider-message, or retry state.
- Impact: If the provider handoff fails, the in-app fallback can be recorded
  but the critical email remains unsent indefinitely while the standing alert
  continues to deduplicate. Operators cannot distinguish delivered from
  permanently failed delivery from the alert row.
- Recommendation: Use a durable notification/outbox or explicit delivery
  attempts with bounded retry/backoff and failure state. Make the alert
  delivery status observable, and test provider rejection, retry, recovery,
  and duplicate-run behavior.

### F-003 — AP due-soon behavior selects one exact date, not a due-soon window

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: checkFinance() filters bills with
  whereDate('due_date', today + apDueSoonDays) at
  api/app/Common/Services/AlertEngineService.php:392-397 and writes the
  configured number into the message at :398-410. The seeded setting is 3
  with the description “Days before a bill due date when an informational
  alert is raised” at
  api/database/migrations/0290_seed_alert_approval_and_quality_policy_settings.php:24-25.
- Impact: With the normal “within the next N days” interpretation, bills due
  today, tomorrow, and two days from now are not selected when the setting is
  three; only bills exactly three days out are raised. If exact-offset behavior
  is intended, the current name/description and operator expectation are
  ambiguous.
- Recommendation: Decide and document exact-offset versus inclusive-window
  semantics, implement the corresponding lower/upper date bounds, and add
  boundary tests for today, N-1, N, N+1, overdue, null, and timezone cases.

### F-004 — Alerts never auto-resolve or rearm from monitored state

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: Alert::scopeActive() is only is_dismissed = false at
  api/app/Common/Models/Alert.php:52-55. raise() creates or returns rows but
  has no recovery transition at
  api/app/Common/Services/AlertEngineService.php:60-91. The API's default
  list is also is_dismissed = false at
  api/app/Common/Controllers/AlertController.php:35-41, while the SPA labels
  the result as active and says the system is healthy only when the list is
  empty at spa/src/pages/alerts/index.tsx:92-99 and :132-140.
- Impact: A recovered stock, machine, AR, quality, or scheduler condition
  remains active until a user dismisses it. A threshold escalation can leave
  both an old warning and a new critical row open. Unread/open counts become
  historical acknowledgement counts rather than current operational health.
- Recommendation: Choose an explicit lifecycle: derive current state and
  resolve/rearm by a stable condition key, or deliberately define alerts as
  acknowledgement history and rename the active/unread surfaces. Add
  recovery, escalation, dismissal, and reappearance tests.

### F-005 — The alerts table has no retention or archive path

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: The alerts migration creates timestamps and indexes but no
  retention metadata or archive mechanism at
  api/database/migrations/0111_create_alerts_table.php:17-37. The scheduled
  cleanup paths cover notifications, audit logs, and scheduler evidence at
  api/routes/console.php:208-245, but no alerts prune/archive command or
  schedule exists. Engine statistics also use global Alert::count() and
  recent table counts at
  api/app/Common/Services/AlertEngineService.php:117-141.
- Impact: Dismissed and recovered alert history grows indefinitely. List,
  count, dashboard, and future dedup queries accumulate operational data and
  eventually degrade without a documented history requirement or deletion
  boundary.
- Recommendation: Define the required alert history and archive/retention
  policy, including audit/export and backup behavior. Add a bounded,
  idempotent retention command, indexes/partition strategy appropriate to
  observed volume, and tests that never remove active or required evidence.

### F-006 — The scheduled engine is unbounded and contains repeated per-row queries

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: The inventory check loads all active items at once at
  api/app/Common/Services/AlertEngineService.php:166-173 and checks approved
  suppliers inside the low-stock item loop at :203-213. Production loads all
  breakdown machines, molds, and overdue work orders at :228-291 and then
  performs Machine::find() per qualifying OEE row at :312-329. Finance loads
  invoice and bill rows and calls Invoice::find() and Bill::find() per row at
  :347-410; quality calls Product::find() per qualifying row at
  :422-453. The command runs every fifteen minutes at
  api/routes/console.php:53-57.
- Impact: Data size and the number of qualifying rows directly increase memory,
  query count, runtime, email work, and scheduler overlap risk. A normal
  operational growth curve can turn the health scan into a timeout or a
  scheduler backlog without a measured runtime budget.
- Recommendation: Replace full loads with bounded/chunked queries and
  set-based joins/aggregates, measure query count and runtime, and define
  behavior when a run exceeds its interval. Separate detection from delivery
  and add volume/regression tests at the expected production scale.

### F-007 — The SPA alert contract omits backend alert types

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: AlertType includes chain_bottleneck and scheduler_stale at
  api/app/Common/Enums/AlertType.php:54-63, and the options endpoint exposes
  every enum case at api/app/Common/Controllers/AlertController.php:59-64.
  The SPA AlertType union ends at mrp_data_error at
  spa/src/types/alerts.ts:7-23, and TYPE_ICON has no entries for either new
  value at spa/src/pages/alerts/index.tsx:21-38. The fallback icon at :160
  masks the mismatch at runtime, so the stale union is not caught by
  typecheck.
- Impact: New backend alerts cannot be represented in the client filter/type
  contract and render with only a generic fallback. Future client code can
  reject or mishandle valid API values while CI remains green.
- Recommendation: Generate or share the enum contract, add both values and
  icons/labels, and add an API-to-SPA contract test that fails when backend
  options contain an unrepresented type.

### F-008 — The SPA has no pagination despite a paginated API

- Classification: Incomplete
- Tags: [medium] [same-session-ok]
- Evidence: AlertController paginates at
  api/app/Common/Controllers/AlertController.php:49-56. The page hard-codes
  page 1 and per_page 50 at spa/src/pages/alerts/index.tsx:46-55 and reads
  only meta.total at :92; there are no next/previous controls or additional
  page requests through the end of the component at :220.
- Impact: Once an active or dismissed set exceeds 50 rows, operators cannot
  reach the remaining alerts. The displayed total can imply complete review
  while the page renders only the first server page.
- Recommendation: Add accessible pagination or an explicitly tested infinite
  list, preserve filters across pages, and test counts above one page for both
  active and dismissed views.

### F-009 — The alert read lifecycle is exposed but not usable or per-user

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: The backend markRead() writes one global is_read boolean at
  api/app/Common/Services/AlertEngineService.php:105-112 and exposes the
  endpoint at api/routes/api.php:112-115. The SPA client wraps it at
  spa/src/api/alerts.ts:26-27, but the alert page never calls markRead; it
  only displays a new chip from is_read at
  spa/src/pages/alerts/index.tsx:164-182. The endpoint named unread-count
  instead counts every non-dismissed critical/warning row and ignores
  is_read at api/app/Common/Controllers/AlertController.php:81-87.
- Impact: Alerts remain visually new through the normal page workflow, and
  marking one read would not change the endpoint called unread-count. Because
  read state is global, one operator's acknowledgement changes every user's
  view if the product intends personal unread state.
- Recommendation: Decide whether this is a shared acknowledgement queue or a
  per-user inbox. Align names and counts, add read_at/read_by or a user-scoped
  read relation when needed, wire the intended page interaction, and test
  dismiss/read/count behavior across two users.

### F-010 — is_dismissed accepts arbitrary input and coerces it to false

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: ListAlertsRequest validates is_dismissed only as sometimes at
  api/app/Common/Requests/ListAlertsRequest.php:21-30. AlertController then
  passes the value through filter_var without requiring a boolean at
  api/app/Common/Controllers/AlertController.php:35-37.
- Impact: A malformed client value is silently interpreted as false instead
  of producing a validation error, which can return the unresolved view for
  a request that the caller believed was rejected or true.
- Recommendation: Use a boolean validation rule and consume the normalized
  value; add request tests for true/false, invalid strings, arrays, and
  omitted input.

### F-011 — Engine run statistics are not run-scoped and by_type is never filled

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: runAllChecks() documents by_type but initializes it empty and
  never populates it at
  api/app/Common/Services/AlertEngineService.php:114-143. It derives raised
  from the global Alert::count() delta at :120 and :135, while severity
  counts are global recent-row counts at :138-141.
- Impact: Concurrent or externally raised alerts can make the command report
  a misleading raised count, and operators have no per-type run result even
  though the return contract promises it.
- Recommendation: Track created IDs/counts inside the run, return explicit
  check failures and by-type counts, and emit metrics/log fields that
  distinguish this run from standing table volume.

### F-012 — Critical alert email audiences are incomplete by default

- Classification: Question / policy gap
- Tags: [medium] [separate-recommended]
- Evidence: SchedulerStale and MrpRunFailed are critical in
  api/app/Common/Enums/AlertType.php:94-103, but the seeded critical role map
  at api/database/migrations/0417_seed_alert_critical_notification_roles.php:11-26
  has no entries for either type. emailCritical() returns when the type has
  no configured role slugs at
  api/app/Common/Services/AlertEngineService.php:544-550.
- Impact: These critical conditions are in-app only unless an administrator
  adds an audience. That may be intentional, but the severity label and
  release operation do not make the exception visible to operators.
- Recommendation: Confirm the policy explicitly. Either seed and test an
  accountable recipient audience, or document that these critical types are
  intentionally in-app-only and change the UI/runbook wording accordingly.

## Positive controls and non-findings

- API routes require Sanctum authentication and separate alerts.view and
  alerts.dismiss permissions at api/routes/api.php:101-115; the focused auth
  and rate-limit suite passed.
- FormRequest authorization protects list access, and enum rules constrain
  severity/type arrays at api/app/Common/Requests/ListAlertsRequest.php:14-25.
- AlertResource does not expose the raw entity_id field, and all monitored
  entity models inspected for the engine use HasHashId.
- Per-check safe() isolation allows inventory, production, finance, quality,
  and scheduler failures to be reported without aborting every check at
  api/app/Common/Services/AlertEngineService.php:146-155.
- SchedulerStale is documented as stalled/partial scheduler detection rather
  than a dead-man switch; the external Docker healthcheck remains the dead
  scheduler control in docker-compose.prod.yml:181-189.

## Verification

- Container-backed alert suite: 8 passed, 29 assertions.
- Container-backed authentication/rate-limit suite: 18 passed, 60 assertions.
- SPA typecheck: passed.
- Targeted SPA ESLint for alert sources: passed with zero warnings.
- PHP syntax checks for AlertEngineService, AlertController, AlertResource,
  Alert, and RunAlertEngine: passed.
- php artisan route:list --path=alerts: 5 routes resolved.
- No application source files outside audit/domains/platform/alerts were
  modified by this session; pre-existing user changes were preserved.
