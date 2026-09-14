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

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Re-audit method and status

This re-audit read the current checkout at `56e0d431` against the requested
Accounting + Budgeting scope, the 2026-09-06 scope map, the prior report, and all
requested project guidance. No application code, migration, test, registry, or
configuration was changed. No tests or Docker commands were run. The only write
is this appended report section.

All seven prior findings were rechecked. None is closed in the current source:

| ID | Current status | Changed status | Current evidence |
|---|---|---|---|
| ACC-BUD-001 | Unresolved | Expanded | Enforcement still performs a derived read with no atomic reservation or source claim. |
| ACC-BUD-002 | Unresolved | Expanded | Budget and FiscalYear still lack enum casts; the sync-run status is also string constants without an enum/check contract. |
| ACC-BUD-003 | Unresolved | Unchanged | Budget list, fiscal-year, and options query failures still have no independent rendered error state or stale indicator. |
| ACC-BUD-004 | Unresolved | Unchanged | Budget create/edit still uses local state rather than RHF/Zod and does not map server validation to fields. |
| ACC-BUD-005 | Unresolved | Expanded | Budget UI still converts decimal strings to numbers; BudgetLineItem also exposes a float-returning money helper. |
| ACC-BUD-006 | Unresolved | Unchanged | Budget-vs-actual still has a plain failure message and no successful-empty or sync-status failure recovery state. |
| ACC-BUD-007 | Unresolved | Unchanged | Budget detail lifecycle mutations still invalidate only the detail query. |

No prior finding changed to a resolved status. Findings below are only the
materially unresolved or new evidence found in this pass.

### ACC-BUD-001

- **Category:** Risk
- **Severity:** High
- **Status:** Unresolved, with current approval-path evidence
- **Location:** `api/app/Modules/Accounting/Services/BudgetEnforcementService.php:28-57,101-111,183-210`; directly relevant call site `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:539-545`
- **Evidence/reproduction:** `checkAvailability()` hydrates derived GL/PO totals and compares the requested amount, but never locks the budget aggregate, reserves the amount, or records an idempotent source claim. `assess()` persists only warning metadata. PO approval calls `enforce()` outside the approval transaction, so two concurrent approval requests can read the same available amount and both pass before either PO becomes an open commitment.
- **Reproduction:** Set one department budget's remaining amount below the sum of two pending PO amounts, enable `budgeting.enforcement_mode=block`, and approve both POs concurrently. The read-only checks can both pass; the subsequent open-PO commitment total can exceed the approved allocation. `BudgetEnforcementWiringTest` covers serial allow/block behavior, not this reservation race.
- **Estimated effort:** L
- **Cross-module note:** The authoritative reservation must be owned by the Purchasing source transaction and Accounting consumption model together; a UI warning or a second client-side budget state will not close this boundary.

### ACC-BUD-002

- **Category:** Bad practice
- **Severity:** Medium
- **Status:** Unresolved, with sync-run status included in the evidence
- **Location:** `api/app/Modules/Accounting/Models/Budget.php:41-44`; `api/app/Modules/Accounting/Models/FiscalYear.php:30-33`; `api/app/Modules/Accounting/Models/BudgetActualsSyncRun.php:13-16,30-37`
- **Evidence/reproduction:** `Budget` has no enum cast for `budget_type` or `status`, `FiscalYear` has no enum cast or FiscalYear status enum, and `BudgetActualsSyncRun` stores four status strings as constants with no enum cast. Services and resources therefore compare and label raw strings (`BudgetService.php:22-30,360-364`; `BudgetResource.php:32-33`; `FiscalYearResource.php:20-21`).
- **Reproduction:** Hydrate a Budget, FiscalYear, or BudgetActualsSyncRun from a persisted row and inspect its status/type. It is a string, so ordinary model assignment can bypass the typed model contract even where a migration check exists for only part of the lifecycle.
- **Estimated effort:** S
- **Cross-module note:** Preserve the legacy `approved` budget status while introducing casts; Purchasing consumption currently treats both `approved` and `active` as live statuses.

### ACC-BUD-003

- **Category:** Gap
- **Severity:** Medium
- **Status:** Unresolved
- **Location:** `spa/src/pages/budgeting/index.tsx:30-64,68-83,191-220`
- **Evidence/reproduction:** Only the overview query's error is rendered. `fiscalYearsQuery.error`, `budgetListQuery.error`, and the options query error are not rendered. A failed budget list reaches the same `No budgets found` branch as a successful empty list, and none of these queries uses `placeholderData` or exposes a stale/refetch state when filters change.
- **Reproduction:** Return HTTP 500 from `/budgets`, `/budgets/fiscal-years`, or `/budgets/options` while the overview succeeds. The page can show an empty budget panel or infinite threshold fallbacks instead of a retryable error and can present old/new query transitions without stale context.
- **Estimated effort:** S

### ACC-BUD-004

- **Category:** Gap
- **Severity:** Medium
- **Status:** Unresolved
- **Location:** `spa/src/pages/budgeting/create.tsx:38-55,92-117,127-155`
- **Evidence/reproduction:** The form remains local state plus a hand-written `submit()`; it is not React Hook Form + Zod. Errors from budget, accounts, departments, and options queries are silent. `reportMutationError` receives the mutation error without field-level mapping, and the submit button's `disabled` expression omits `saveMutation.isPending` even though it shows a loading state.
- **Reproduction:** Return a 422 containing `line_items.0.account_id` or another line-specific error and observe no field error. Double-click Create during a slow save; the page-level disabled expression does not prevent a second mutation request while the first is pending.
- **Estimated effort:** M

### ACC-BUD-005

- **Category:** Risk
- **Severity:** Medium
- **Status:** Unresolved, with a backend helper also violating the decimal contract
- **Location:** `spa/src/pages/budgeting/index.tsx:144-147`; `spa/src/pages/budgeting/create.tsx:122-124`; `spa/src/pages/budgeting/departments.tsx:61-65,100`; `spa/src/pages/budgeting/budget-vs-actual.tsx:57-74`; `api/app/Modules/Accounting/Models/BudgetLineItem.php:51-60`
- **Evidence/reproduction:** Budget pages parse API decimal strings with `Number()` for totals, chart values, percentages, and absolute values. The backend `BudgetLineItem::monthAmount()` also returns a decimal column as `float`. These are presentation paths today, but they can drift at cent-sensitive or large values and contradict the repository's decimal-string money contract.
- **Reproduction:** Supply several line/month values such as `0.10`, `0.20`, and large 15,2 values, then compare client-derived totals/percentages with the API's exact `total_*`, `variance`, and `utilization_pct` strings/numbers. The client calculations need not equal the server's exact arithmetic.
- **Estimated effort:** M

### ACC-BUD-006

- **Category:** Gap
- **Severity:** Medium
- **Status:** Unresolved
- **Location:** `spa/src/pages/budgeting/budget-vs-actual.tsx:39-44,61-74,95-116`
- **Evidence/reproduction:** Report failure renders only plain red text with no retry action. A successful `{ rows: [] }` response renders the report shell and empty tables without a contextual empty state. `syncStatusQuery.error` is not rendered, so a failed status request is indistinguishable from no status.
- **Reproduction:** Fail `/budgets/budget-vs-actual` and observe no retry control; return zero rows and observe no no-data explanation; fail `/budgets/sync-actuals/status` and observe no status error or manual recovery path.
- **Estimated effort:** S

### ACC-BUD-007

- **Category:** Risk
- **Severity:** Low
- **Status:** Unresolved
- **Location:** `spa/src/pages/budgeting/detail.tsx:69-97`; list/summary consumers `spa/src/pages/budgeting/index.tsx:43-57`
- **Evidence/reproduction:** Submit, approve, and close each invalidate only `['budget', id]`. They do not invalidate `['budgets', ...]` or `['budget-overview', ...]`, so the overview/list can retain the old status and aggregate after a successful detail action.
- **Reproduction:** Open the overview and a budget detail, complete a lifecycle action in the detail, then return to the overview without a full reload. The list and summary can show stale data until an unrelated refetch or stale-time expiry.
- **Estimated effort:** S

### ACC-BUD-008

- **Category:** Risk
- **Severity:** Medium
- **Location:** `spa/src/pages/accounting/invoices/create.tsx:28-34,84-89,148-150`; `spa/src/pages/accounting/bills/create.tsx:29-36,167-172,258-260`; `spa/src/pages/accounting/credit-notes/index.tsx:39-42,149-153`
- **Evidence/reproduction:** Accounting money/quantity inputs use `z.coerce.number()`, calculate subtotals/VAT/totals with JavaScript arithmetic, and reconstruct API values with `String(number)`. The API resources and backend services use exact decimal strings, so the form preview and submitted decimal representation can diverge at binary floating-point rounding boundaries.
- **Reproduction:** Enter two-decimal quantities and prices whose product lands on a half-cent after multiplication, or many cent-sensitive lines, and compare the form's `toFixed(2)` subtotal/VAT/total with the exact totals returned by the server. The client is recomputing a financial result with floats instead of displaying server/exact-decimal arithmetic.
- **Estimated effort:** M

### ACC-BUD-009

- **Category:** Stuck process
- **Severity:** Medium
- **Location:** `spa/src/pages/accounting/credit-notes/detail.tsx:51-54,99-100`
- **Evidence/reproduction:** Credit-note detail has no `refetch` handler and collapses both a failed request and a missing record into `Credit note not found.` A transient API failure therefore removes the operator's only detail-page recovery action and misstates the cause.
- **Reproduction:** Make `/accounting/credit-notes/{id}` return a transient 500. The page shows the not-found text with no retry button, so the operator must leave or manually reload before finalizing or applying the credit.
- **Estimated effort:** S

### ACC-BUD-010

- **Category:** Bad practice
- **Severity:** Medium
- **Location:** `spa/src/types/accounting.ts:116-124,258-266`; corresponding resources `api/app/Modules/Accounting/Resources/BillItemResource.php:15` and `api/app/Modules/Accounting/Resources/InvoiceItemResource.php:15`
- **Evidence/reproduction:** `BillItem.id` and `InvoiceItem.id` are declared as `number` in the SPA types, while both API resources return `$this->hash_id`, a string. This contradicts the project-wide HashID contract and gives consumers an incorrect compile-time type for IDs used in row keys, links, and follow-up requests.
- **Reproduction:** Treat a typed bill/invoice item response as returned by the API and assign `items[0].id` to a `string`, or pass it to an API helper requiring a string. TypeScript reports the wrong contract even though the runtime response is a hash string; numeric assumptions can also break consumers.
- **Estimated effort:** S

### ACC-BUD-011

- **Category:** Stuck process
- **Severity:** Medium
- **Location:** `spa/src/pages/accounting/credit-notes/detail.tsx:58-70`
- **Evidence/reproduction:** The credit-note application dialog loads open invoices or bills once with `per_page: 100`, filters them client-side, and has no search or pagination. A valid target after the first 100 records is not presented, and the apply endpoint has no alternate UI path on this page.
- **Reproduction:** Give one customer or vendor more than 100 open documents, with the intended target after the first page. Open Apply credit and inspect the selector; the target is absent, leaving Finance unable to apply the credit to that document from the supported workflow.
- **Estimated effort:** M

### Clean areas confirmed

- Journal-entry creation, update, posting, reversal, maker-checker, closed-period gating, source-reference validation, exact money arithmetic, and aggregate locking remain materially controlled by `JournalEntryService` and its state machine.
- Accounting period close/reopen and concurrent first-row creation retain the service-level locking and duplicate-recovery controls; the re-audit found no source regression.
- Budget create/update/submit/approve/close transactions, leaf-account validation, duplicate line uniqueness, non-negative database invariants, and exact server-side budget totals remain present.
- Budget actuals synchronization retains the durable outbox/run record, same-minute deduplication, bounded job processing, failure rethrow/status recording, and monthly scheduler registration at `api/routes/console.php:338-343`.
- Accounting API resources reviewed for journal entries, bills, invoices, credit notes, accounts, vendors, customers, budgets, and fiscal years expose hashed integer IDs; the new ID-contract finding is limited to two SPA type declarations.
- Accounting and budgeting route files remain lazy-loaded and guarded by the expected module and permission layers; no bearer-token or local-storage authentication issue was found in this scope.
- The sampled Accounting list/report pages continue to provide skeleton, retryable error, contextual empty, placeholder/stale, semantic-status, and token-based rendering states; the exceptions recorded above are the credit-note detail and budgeting surfaces specifically identified.

### Final statement

This is a read-only code-reading re-audit of commit `56e0d431`. No application
code, migrations, tests, registry, scheduler configuration, or other source files
were changed. The only change is this appended section in
`audit/accounting-budgeting.md`.
