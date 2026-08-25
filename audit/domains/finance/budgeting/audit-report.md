# M030 — Budgeting audit report

- Audit date: 2026-08-24
- Domain/module: finance / budgeting
- Tier: 2
- Surface: L
- Dependencies: chart-of-accounts-periods, journal-ledger, employee-master
- Roles: system admin, finance officer
- Status: Plan Ready
- Source changes: none; this session produced audit artifacts only.

## Scope and summary

The audit covered the budget API and SPA, budget creation and line-item
validation, fiscal-year selection, submit/approve/close transitions, budget
enforcement as consumed by purchasing and bills, the GL actuals rebuild,
durable outbox handoff, dashboard readers, data-model constraints, and focused
tests.

The module has authenticated, feature-gated routes, separate view/manage/
approve permissions, exact-money threshold classification, and a durable
outbox handoff for actuals rebuild requests. The central control is not yet
production-ready: no canonical production writer maintains budgets.total_spent
or budgets.total_committed, while enforcement and the overview read those
header columns. The actuals job updates line-level actual_total instead, so
budget enforcement can remain at the original allocation even after posted GL
activity or approved commitments.

Additional risks are an unguarded budget state machine and missing
maker-checker enforcement, stale persisted line variance after actuals sync,
an all-at-once per-line rebuild job, unsafe fiscal-year request coercion,
duplicate/ineligible account lines, date-window-blind current-year selection,
and SPA/report lifecycle gaps. These findings change financial-state authority
or cross-module contracts, so no production source fixes were applied.

## Findings

### F-001 — Header spend and commitment totals have no canonical production writer

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: The budget migration creates total_spent and total_committed as
  ordinary decimal columns defaulting to zero at
  api/database/migrations/0162_create_budgeting_tables.php:28-30.
  BudgetService::create() calculates only total_allocated from the submitted
  lines at api/app/Modules/Accounting/Services/BudgetService.php:24-38.
  BudgetEnforcementService::checkAvailability() makes its exact decision from
  the header totals at api/app/Modules/Accounting/Services/
  BudgetEnforcementService.php:47-52. SyncBudgetActuals updates only
  BudgetLineItem.actual_total at api/app/Modules/Accounting/Jobs/
  SyncBudgetActuals.php:62-75, and does not aggregate that result back to the
  budget header. The overview and dashboard also read header totals at
  BudgetService.php:112-134 and
  api/app/Modules/Dashboard/Services/DashboardWidgetDataService.php:239-249.
  A repository-wide production-code search found no writer for these two
  budget columns; the wiring tests seed them directly instead
  (BudgetEnforcementWiringTest.php:52-58).
- Impact: Posted journal activity and approved purchasing commitments do not
  reduce the amount used by the enforcement gate unless some unlocated
  operational process writes the header manually. A newly created budget
  therefore remains available at its full allocation for the main control
  path, while overview/dashboard spend can remain zero. The line-level
  budget-vs-actual view and the header-based enforcement view can report
  different realities.
- Recommendation: Define one authoritative budget-consumption contract.
  Either derive enforcement and overview from posted GL and approved
  commitment sources, or maintain the header aggregates transactionally from
  those sources. Include account/department allocation rules, backfill
  existing budgets, and add tests for posted JE, approved PO, bill, reversal,
  cancellation, and concurrent update paths before enabling blocking mode.

### F-002 — Submit, approve, and close are unguarded state writes

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: BudgetService::submit(), approve(), and close() directly update
  status without checking the current state, reloading under lock, or wrapping
  the transition in a transaction at
  api/app/Modules/Accounting/Services/BudgetService.php:48-78. The controller
  calls those methods without an additional guard at
  api/app/Modules/Accounting/Controllers/BudgetController.php:198-217.
  The database lifecycle migrations constrain values to the allowed enum but
  do not constrain transitions at
  api/database/migrations/2026_08_13_220000_add_remaining_lifecycle_status_checks.php:21
  and 2026_08_13_221000_add_enum_lifecycle_status_guards.php:21.
  Finance Officer receives the whole budgeting module, including manage and
  approve, through RolePermissionSeeder.php:494-507; there is no budgeting
  maker-checker rule in SodConflictRuleSeeder.php:20-59.
- Impact: Direct callers can approve a draft without submission, submit or
  approve a closed budget, close a draft, and race two transitions against a
  stale route-bound model. A finance officer can create, submit, and approve
  the same budget without a server-side separation check. The service writes
  active while the enum and active scope also retain an approved state,
  increasing ambiguity for readers and future writers.
- Recommendation: Define the allowed transition graph and enforce it inside a
  locked service transaction using a fresh row. Decide whether approval means
  approved or active, enforce fiscal-year status/date policy, prevent
  self-approval according to the agreed SoD policy, and record transition
  actor/reason evidence. Add sequential and PostgreSQL interleaving tests for
  every invalid transition and stale request.

### F-003 — Actuals rebuild leaves the persisted line variance stale

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: budget_line_items.variance is a normal decimal column with a zero
  default, not a generated expression, at
  api/database/migrations/0162_create_budgeting_tables.php:55-57.
  SyncBudgetActuals writes actual_total but never variance at
  api/app/Modules/Accounting/Jobs/SyncBudgetActuals.php:62-75.
  BudgetService::budgetVsActual() returns the stored variance and derives
  variance_pct from it at api/app/Modules/Accounting/Services/
  BudgetService.php:168-173. The SPA renders that field directly in
  pages/budgeting/detail.tsx:271-280 and
  pages/budgeting/budget-vs-actual.tsx:210-218. The existing job test asserts
  only actual_total at api/tests/Feature/Accounting/
  SyncBudgetActualsJobTest.php:44-46.
- Impact: After a successful sync, a line can show a new actual and an old
  zero variance. The row percentage and total report are then internally
  inconsistent: the total variance is recomputed from totals at
  BudgetService.php:178-185, while the row variance remains stale.
- Recommendation: Make variance a generated/database-derived value or update
  it atomically with actual_total using the agreed sign convention. Add
  assertions for actual_total, variance, row percentage, and report totals,
  including reruns and negative/credit-normal accounts.

### F-004 — Actuals rebuild is an all-line N+1 transaction with no completion state

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: SyncBudgetActuals loads every line item into memory at
  api/app/Modules/Accounting/Jobs/SyncBudgetActuals.php:49-52, then runs a
  separate JournalEntryLine aggregate query for every line inside one
  transaction at :62-77. The queued job timeout is 120 seconds at :33-36.
  Permanent listener failure only writes a log entry at
  api/app/Modules/Accounting/Listeners/RunBudgetActualsSyncOnRequested.php:48-55.
  The SPA reports that the rebuild was queued and immediately invalidates the
  query at pages/budgeting/budget-vs-actual.tsx:29-35; it has no run status or
  last-success timestamp.
- Impact: A large budget can exceed the worker timeout or hold one long
  transaction, rolling back every line rather than making progress. Users see
  a queued-success toast but no authoritative completion or failure state and
  may continue using stale figures.
- Recommendation: Aggregate journal movement by account once, update lines in
  bounded chunks with a deliberate snapshot/locking policy, and persist a
  durable run status tied to the outbox request. Expose pending/completed/
  failed state and last successful sync time to the report UI.

### F-005 — Manual sync accepts invalid fiscal-year targets and can silently retarget

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: syncActuals() decodes a hash, accepts digit strings, and converts
  every other non-null value to null at
  api/app/Modules/Accounting/Controllers/BudgetController.php:331-342.
  It does not validate that the requested fiscal year exists before returning
  202. The explicit job resolver uses find() and throws only later when no row
  exists at api/app/Modules/Accounting/Jobs/SyncBudgetActuals.php:83-94.
  Therefore an invalid numeric ID creates a successful-looking doomed job,
  while an invalid hash can become a request for the active year.
- Impact: An operator or malformed client request can rebuild the wrong fiscal
  year or receive a 202 for work that will fail asynchronously. The response
  does not identify a validated target or provide a recoverable failure state.
- Recommendation: Validate and decode the target with one request contract;
  reject an invalid or missing fiscal year with 422 before staging the outbox
  event. Keep the explicit historical-year policy documented and make command
  line options use the same validation.

### F-006 — Budget line input permits duplicate, inactive, and non-leaf accounts

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: BudgetController::store() checks only exists:accounts,id and
  numeric|min:0 month values at
  api/app/Modules/Accounting/Controllers/BudgetController.php:139-157.
  There is no distinct account rule, account active/leaf/type policy, or
  two-decimal contract. The migration has no unique budget/account line key
  or nonnegative checks at
  api/database/migrations/0162_create_budgeting_tables.php:39-58.
  The SPA loads the unfiltered paginated account list at
  pages/budgeting/create.tsx:47-50, then permits selecting the same account
  on multiple lines at :239-264.
- Impact: Repeated account lines are each assigned the same GL actual by the
  rebuild, so the budget-vs-actual report can double-count actuals. Inactive
  or group accounts can be selected even though the COA model exposes active
  and leaf state. Three-decimal or oversized input is silently rounded by the
  decimal column after the service has summed it with native PHP arithmetic.
- Recommendation: Reuse the M025 account eligibility policy, validate account
  uniqueness and permitted account types at the service boundary, enforce
  the invariant in the database, and use a canonical decimal/money path for
  totals. Add duplicate, inactive, group-account, precision, and migration
  constraint tests.

### F-007 — Default fiscal-year selection ignores the stored date window

- Classification: Incomplete
- Tags: [medium] [same-session-ok]
- Evidence: FiscalYear::scopeCurrent() matches only the start-date year at
  api/app/Modules/Accounting/Models/FiscalYear.php:40-47.
  BudgetService::getCurrentFiscalYear() uses that scope and otherwise picks
  the latest active year at api/app/Modules/Accounting/Services/
  BudgetService.php:189-193. Budget enforcement uses this fallback whenever a
  fiscal year is omitted at BudgetEnforcementService.php:25-29, while the
  schema stores explicit start_date and end_date values.
- Impact: A non-calendar fiscal year is not current after the calendar year
  changes, and when no start-year match exists the fallback can select a
  future or otherwise wrong active year. Default overview, budget checks, and
  the no-argument sync/report UI can therefore use the wrong budget period.
- Recommendation: Define current as an active row whose start/end window
  contains today, make fallback behavior explicit, and add calendar,
  non-calendar, gap, overlapping, and future-year tests.

### F-008 — SPA department/report views are capped, client-filtered, and not sync-aware

- Classification: Incomplete
- Tags: [medium] [same-session-ok]
- Evidence: The overview list requests only per_page 50 at
  pages/budgeting/index.tsx:32-35, and the department view requests 100
  budgets then filters by department name in the browser at
  pages/budgeting/departments.tsx:41-51. The API caps budget pagination at
  100 and has no lower-bound validation for per_page at
  api/app/Modules/Accounting/Controllers/BudgetController.php:95-107.
  Neither view sends a fiscal-year filter; the report page also queues a
  rebuild without a selected year and immediately re-fetches at
  pages/budgeting/budget-vs-actual.tsx:24-35.
- Impact: A department with more than 100 budgets is silently incomplete, and
  duplicate department names can mix records. The UI labels data with the
  browser's current year while the server may use its fallback year. A
  successful queue response can leave the user looking at pre-sync data with
  no indication of when it will change.
- Recommendation: Add server-side department and fiscal-year filters, use
  pagination or a bounded report endpoint, show the authoritative fiscal-year
  label, and poll/display the durable sync run state.

### F-009 — Draft budgets cannot edit their line allocations in the product

- Classification: Missing
- Tags: [medium] [same-session-ok]
- Evidence: The API update validates and writes only budget_type and name at
  api/app/Modules/Accounting/Controllers/BudgetController.php:170-195; it
  cannot change accounts, monthly values, fiscal year, or department. The
  client wrapper exposes only that partial update at
  spa/src/api/accounting/budgeting.ts:58-59, while the detail page offers
  submit, approve, and close but no draft edit action at
  spa/src/pages/budgeting/detail.tsx:106-175.
- Impact: A saved draft with a wrong account or monthly allocation cannot be
  corrected through the normal product workflow. Users must abandon/recreate
  a budget, increasing duplicate drafts and weakening the meaning of the
  draft state.
- Recommendation: Either implement a complete draft edit transaction with
  duplicate/eligibility/total revalidation and stale-state handling, or
  explicitly make drafts immutable after creation and remove the misleading
  PUT contract. Add UI and API tests for draft-only editing and terminal
  rejection.

### F-010 — Budget resources expose raw foreign keys and float money values

- Classification: Polish
- Tags: [medium] [same-session-ok]
- Evidence: BudgetResource returns raw fiscal_year_id and department_id and
  casts monetary fields to float at
  api/app/Modules/Accounting/Resources/BudgetResource.php:15-31.
  BudgetLineItemResource likewise returns raw budget_id/account_id and casts
  every monthly, annual, actual, and variance amount to float at
  api/app/Modules/Accounting/Resources/BudgetLineItemResource.php:14-37.
  The SPA types model those foreign keys as numbers while the public entity
  identifiers and nested account/department identifiers use HashIDs.
- Impact: The budgeting API is inconsistent with the identifier contract used
  by its own nested resources and exposes internal primary keys. Float
  serialization also makes the report contract less precise for large or
  cent-sensitive values, even though the enforcement decision path uses
  Money internally.
- Recommendation: Align foreign-key serialization with the module's HashID
  contract and choose an explicit money wire format (decimal strings or a
  documented numeric display boundary). Update the TypeScript types and
  contract tests together.

## Verified strengths and scope decisions

- The route surface is authenticated and feature-gated, with 13 registered
  budget routes and separate budgeting.view, budgeting.manage, and
  budgeting.approve middleware.
- BudgetConsumptionLevel compares exact money amounts rather than rounded
  percentages. The focused unit and boundary tests cover the 99.95% edge,
  exact exhaustion, cent values, and two-budget aggregation.
- Actuals requests are staged through EventOutbox in a transaction, are
  replayable, and deduplicate duplicate requests in one scheduler tick. The
  durable handoff tests passed.
- Database status checks reject unknown budget status values. They are useful
  enum guards but do not replace transition authorization or locking.
- Budget transfers and revisions were not counted as missing functionality:
  migrations 0456 and 0459 explicitly record those tables as scope cuts.

## Verification

- Container-backed focused backend suite: 25 tests passed, 50 assertions.
- SPA TypeScript typecheck: passed.
- Targeted SPA ESLint for the budgeting API, pages, and types: passed with
  zero warnings.
- PHP syntax checks for BudgetController, BudgetService,
  BudgetEnforcementService, and SyncBudgetActuals: passed.
- php artisan route:list --path=budgets: 13 routes resolved.
- The first host-side test attempt could not resolve the Compose-only db
  hostname; rerunning through the repository's transient api Compose service
  passed. The existing unrelated worktree changes were preserved.
