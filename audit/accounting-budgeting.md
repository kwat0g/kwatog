# Accounting + Budgeting Read-Only Audit

Date: 2026-09-10

## Scope and method

Inspected only the requested Accounting module, directly relevant shared code and
provider/scheduler references, Accounting and Budgeting SPA pages/API/routes, and
directly relevant migrations and tests. Read `CLAUDE.md`, `docs/PATTERNS.md`,
`docs/SCHEMA.md`, `docs/DESIGN-SYSTEM.md`, `docs/SEEDS.md`, and
`docs/SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md` before reviewing implementation.
No application code or tests were modified or executed. The only write from this
audit is this report.

Definitions used: **Gap/Missing** means a required control or behavior is absent;
**Risk** means the control exists but can fail under a realistic condition;
**Broken process** means a supported workflow cannot reach its intended result;
**Stuck process** means an operator can be left without a usable next action;
**Bad practice** means a standards violation that may not currently break the
workflow.

## Executive summary

The canonical journal path is comparatively strong: it uses decimal-string money,
transaction boundaries, row locks, a state machine, closed-period checks, source
reference validation, maker-checker enforcement, and focused regression coverage.
Budget actuals synchronization also has a durable outbox/run model and failure
propagation.

The current findings are:

| ID | Category | Severity | Summary | Status |
|---|---|---:|---|---|
| ACC-BUD-001 | Risk | High | Budget availability is advisory/read-only and not reserved atomically | Newly discovered |
| ACC-BUD-002 | Bad practice | Medium | Accounting status columns are not represented as enum casts in models | Newly discovered |
| ACC-BUD-003 | Gap | Medium | Budget overview/list silently hides secondary query failures and has no stale state | Newly discovered |
| ACC-BUD-004 | Gap | Medium | Budget create/edit is not a RHF/Zod form and lacks complete pending/server-error handling | Newly discovered |
| ACC-BUD-005 | Risk | Medium | Budgeting UI converts exact decimal money to JavaScript numbers | Newly discovered |
| ACC-BUD-006 | Gap | Medium | Budget-vs-actual failure and empty states leave no retry/empty recovery path | Newly discovered |
| ACC-BUD-007 | Risk | Low | Budget mutation success invalidates detail but not the budget list | Newly discovered |

## Findings

### ACC-BUD-001

- **Category:** Risk
- **Severity:** High
- **Location:** `api/app/Modules/Accounting/Services/BudgetEnforcementService.php:28-79`
- **Evidence/reproduction:** `checkAvailability()` reads active budgets and
  derives available funds without locking a budget, creating a reservation, or
  recording a spend/commit claim. `assess()` then only writes warning metadata
  (`:106-111`) and returns. Two concurrent purchase/bill creation paths can both
  observe the same remaining amount and both proceed, producing commitments over
  the approved allocation. The exact-money arithmetic prevents rounding errors
  but does not prevent the time-of-check/time-of-use race.
- **Reproduction:** With a budget having one small remaining balance, issue two
  concurrent spend requests whose amounts together exceed that balance. Both
  availability checks can pass before either downstream document updates the
  commitment source. The existing `BudgetEnforcementWiringTest` verifies serial
  allow/block behavior, not this concurrent reservation boundary.
- **Estimated effort:** M/L: define the authoritative commitment boundary with
  Purchasing, lock/update it transactionally, add an idempotent source claim, and
  add a two-connection PostgreSQL regression test.
- **Cross-module note:** This is specifically an Accounting Budgeting ↔
  Purchasing boundary. Do not fix it by adding a second UI-only budget state;
  the server-side source transaction must own the reservation.

### ACC-BUD-002

- **Category:** Bad practice
- **Severity:** Medium
- **Location:** `api/app/Modules/Accounting/Models/Budget.php:41-44` and
  `api/app/Modules/Accounting/Models/FiscalYear.php:30-33`
- **Evidence/reproduction:** `Budget` has no `status` or `budget_type` enum cast,
  and `FiscalYear` has no `status` enum cast. The services compare raw strings
  (`BudgetService.php:22-30,360-364`; `Budget.php:114-116`) and the resource
  reconstructs status labels with `BudgetStatus::tryFrom()`
  (`BudgetResource.php:32-33`). This is inconsistent with the repository rule
  that all status/type fields use enums and leaves ordinary model writes able to
  carry strings without typed conversion.
- **Reproduction:** Instantiate a `Budget`/`FiscalYear` from a persisted row and
  inspect `status`; it is a string, unlike `JournalEntry::status`, which is cast
  to `JournalEntryStatus` (`JournalEntry.php:35-41`). A raw model assignment can
  therefore bypass the typed model contract even though database status checks
  reject unknown values.
- **Estimated effort:** S: add the enum casts and update comparisons/tests to use
  enum values consistently; confirm factories and legacy `approved` handling.
- **Cross-module note:** Budget status is consumed by Purchasing commitment
  calculations and dashboard/report queries, so the cast change must preserve the
  legacy `approved` compatibility path.

### ACC-BUD-003

- **Category:** Gap
- **Severity:** Medium
- **Location:** `spa/src/pages/budgeting/index.tsx:30-64,68-83,191-220`
- **Evidence/reproduction:** The page handles the overview loading/error path,
  but `fiscalYearsQuery.error`, `budgetListQuery.error`, and
  `budgetOptionsQuery` errors are not rendered. A failed budget list is rendered
  as `No budgets found` at line 219, which misrepresents an API failure as an
  empty dataset. There is also no `placeholderData`/stale indicator for the
  budget list or overview when changing fiscal year/status.
- **Reproduction:** Make `/budgets`, `/budgets/fiscal-years`, or
  `/budgets/options` return 500 while the overview request succeeds. The page
  either shows an empty budget panel or falls back to infinite thresholds rather
  than a retryable error state. Change the fiscal year with cached data and no
  stale/refetch indicator is exposed.
- **Estimated effort:** S: add independent `QueryErrorState`/retry handling,
  contextual empty state only for successful empty responses, and
  `placeholderData` plus a subtle refetch indicator.
- **Cross-module note:** This is a Finance operator UX issue, not an access
  control. The same state contract should be used by Accounting report pages so
  a missing budget does not look like a zero budget.

### ACC-BUD-004

- **Category:** Gap
- **Severity:** Medium
- **Location:** `spa/src/pages/budgeting/create.tsx:38-55,82-117,127-155`
- **Evidence/reproduction:** The create/edit surface uses local state and a
  hand-written `submit()` instead of the mandated React Hook Form + Zod contract.
  Only fiscal year loading is handled; errors from the budget, accounts,
  departments, and options queries are silent. Server validation errors are
  delegated to a generic `reportMutationError` and are not mapped to individual
  fields. The submit button is disabled for missing fiscal year/name, but not
  generally disabled by `saveMutation.isPending` (line 155), so repeated clicks
  can issue duplicate requests. There is no form error state for failed initial
  option loads.
- **Reproduction:** Return a 422 with `line_items.0.account_id` or duplicate
  account errors; verify no field-level error is attached. Double-click Create
  during a slow response; the button's `disabled` expression does not include
  `saveMutation.isPending`.
- **Estimated effort:** M: migrate to the documented RHF/Zod form pattern or
  implement equivalent exhaustive field mapping, pending disable/loading text,
  and independent query error/retry states.
- **Cross-module note:** The form depends on HR departments and Accounting leaf
  accounts. Its client schema should mirror BudgetController validation without
  reimplementing the server authorization boundary.

### ACC-BUD-005

- **Category:** Risk
- **Severity:** Medium
- **Location:** `spa/src/pages/budgeting/index.tsx:144-147`,
  `spa/src/pages/budgeting/create.tsx:122-124`,
  `spa/src/pages/budgeting/departments.tsx:61-65,100`,
  `spa/src/pages/budgeting/budget-vs-actual.tsx:57-74`
- **Evidence/reproduction:** API decimal values are strings by contract, but
  these pages use `Number(...)` for totals, monthly amounts, chart data, and
  percentages. For large allocations or cent-sensitive values, binary floating
  point can produce display totals and comparisons that differ from the exact
  server values. The backend correctly uses `Money` for decisions; the SPA
  should not recreate financial totals with JS floats.
- **Reproduction:** Supply values such as `0.10`, `0.20`, and large 15,2 values
  across multiple lines/months. Compare the displayed client sum and percentage
  with the API's exact `total_*`, `variance`, and `utilization_pct` values.
- **Estimated effort:** M: use exact decimal/string helpers for display and
  derive presentation percentages from server-provided values; retain numbers
  only at chart-library boundaries after controlled formatting.
- **Cross-module note:** Budget-vs-actual is a GL report. A client-side mismatch
  can undermine trust in the Accounting ledger even when the posted journal and
  server report are correct.

### ACC-BUD-006

- **Category:** Gap
- **Severity:** Medium
- **Location:** `spa/src/pages/budgeting/budget-vs-actual.tsx:61-62,104-116`
- **Evidence/reproduction:** On report failure the page renders plain red text
  (`Failed to load budget vs actual data.`) with no retry action. When the API
  successfully returns zero rows, it renders the cards and empty tables without
  the required contextual empty state. The sync status query can also fail
  silently. This violates the five-state page contract and can strand Finance
  users after a failed GL synchronization/report request.
- **Reproduction:** Fail `/budgets/budget-vs-actual` and observe no retry button;
  return `{rows: []}` and observe empty panels rather than a no-data explanation;
  fail `/budgets/sync-actuals/status` and observe no status/error state.
- **Estimated effort:** S: use `QueryErrorState` with retry, add an explicit
  empty report state, and expose sync-status failure with a manual refresh path.
- **Cross-module note:** The report reads posted GL actuals and durable sync-run
  status. Copy should distinguish “no posted movement” from “sync/report failed.”

### ACC-BUD-007

- **Category:** Risk
- **Severity:** Low
- **Location:** `spa/src/pages/budgeting/detail.tsx:69-97`
- **Evidence/reproduction:** Submit, approve, and close mutations invalidate only
  `['budget', id]` at lines 72, 82, and 92. They do not invalidate `['budgets', ...]`
  or `['budget-overview', ...]`, which are the list/summary keys used by
  `budgeting/index.tsx:43-57`. After an action succeeds, navigating back can show
  the previous status and totals until an unrelated refetch/stale-time event.
- **Reproduction:** Open the overview and a budget detail in separate tabs,
  submit/approve/close in the detail tab, then return to the overview without a
  full reload. The list and summary cache can retain the old status/aggregate.
- **Estimated effort:** S: invalidate the list and overview key families on each
  successful lifecycle mutation and add a focused SPA mutation-cache test.
- **Cross-module note:** Status changes affect budget enforcement eligibility and
  Purchasing commitments; stale Finance UI is especially misleading around the
  `active` boundary.

## Roadmap status

The roadmap was treated as authoritative for known findings; known findings are
not relabeled as regressions:

- **F-020 (open):** generic `(reference_type, reference_id)` source integrity
  remains an Accounting structural weakness. `SourceReferenceRegistry` and
  `JournalEntryService` provide validation for registered writers, but the
  roadmap explicitly says this finding is open. It is a roadmap-open known item,
  not counted above as newly discovered.
- **F-022 (open):** immutable material-detail audit attribution remains open. It
  is relevant to financial auditability but was not duplicated as a new finding
  because the roadmap already owns it.
- **F-030 (open):** deployed restore/recovery proof remains open. No local
  Accounting source inspection can prove staging backup restore, scheduler
  restart, Redis failover, or deployed rollback, so this is recorded as a
  roadmap-open proof boundary, not a source regression.
- **F-025 (verified within scope):** automated GL writers use the canonical
  journal posting lifecycle. The reviewed `JournalEntryService` state machine,
  period checks, locks, and `JournalLedgerInvariantTest`/mutation-contract tests
  support the roadmap’s verified status.
- **F-034 (verified):** the roadmap records the API route audit as complete. No
  route reachability regression was counted from static inspection alone.
- **Budget actuals durable handoff:** the current worktree has focused tests for
  durable outbox requests and same-tick deduplication. No regression was raised
  against that control.

## Checked areas with no finding

- Journal-entry creation and update: balanced decimal money, at least two lines,
  debit/credit XOR validation, source-reference rejection for manual entries,
  transaction wrapping, and closed-period checks.
- Journal-entry posting/reversal: authoritative row locks, line/account locking,
  lifecycle state machine, maker-checker/self-post override, reversal linkage,
  and reversal reason attribution.
- Journal-entry API exposure: primary IDs, line IDs, and reversal IDs are hashed;
  decimal amounts remain strings; no raw integer primary key was found in the
  reviewed Journal Entry resource.
- Accounting period controls: close/reopen service and focused duplicate,
  authorization, close-regression, and posting-concurrency tests were present.
- Budget create/update/submit/approve/close server transactions: the service
  locks the budget aggregate, normalizes line items, rejects duplicate accounts,
  rejects inactive/parent/ineligible accounts, and recalculates totals.
- Budget database invariants: unique budget/account lines, fiscal-year date
  ordering, non-negative allocations/months, and lifecycle status checks are
  present in the reviewed migrations.
- Budget actuals synchronization: durable outbox/run records, same-minute
  deduplication, bounded chunk processing, failed-job rethrow, and focused
  handoff/job tests.
- Budget routes and SPA lazy loading: Budgeting routes are lazy-loaded and
  wrapped in module and permission guards; Accounting routes likewise use the
  module/permission pattern.
- Accounting list/report pages sampled: most reviewed report/list pages use
  skeletons, retryable error states, contextual empty states, placeholder data,
  semantic chips, token-based classes, and monospace/tabular financial values.
- Sensitive Accounting master data: reviewed vendor/customer resources mask TIN
  fields by permission; no new encryption or bearer-token issue was found in the
  inspected Accounting surface.
- Scheduled Accounting invocation: `budget:sync-actuals` is registered at
  `api/routes/console.php:338-343` with overlap and one-server controls. The
  schedule itself was not found to be a current defect.

## Residual verification limits

This was a source audit only. The report does not claim live route-table
resolution, PostgreSQL concurrent behavior, browser layout behavior, deployed
queue/scheduler recovery, or backup restoration because those would require
execution environments and, in some cases, database-mutating tests. Those limits
are explicitly covered by the roadmap’s open/verified status above.
