# M030 — budgeting fix log

Audit date: 2026-08-24  
Fix session: 2026-08-25  
Final disposition: ✅ Verified

## Claim and scope

- Claimed `finance/budgeting` after registry regeneration because it was the
  first unlocked Tier 2 `📋 Plan Ready` module.
- Read the existing audit report and action plan before fixing; no discovery
  or plan rewrite was repeated.
- Changes were limited to the budgeting backend, budgeting SPA, budgeting
  tests, and this module's audit artifacts. Existing unrelated worktree changes
  were preserved.

## Findings fixed

### F-001 — canonical consumption source

- Before: overview, enforcement, dashboard, and line-level actuals relied on
  persisted header snapshots without a canonical posted-ledger/commitment
  derivation.
- After: `api/app/Modules/Accounting/Services/BudgetConsumptionService.php:86`
  derives posted GL actuals and allocates them to live budget lines;
  `api/app/Modules/Accounting/Services/BudgetConsumptionService.php:296`
  derives open purchase-order commitments net of bills; and
  `api/app/Modules/Accounting/Services/BudgetService.php:157` hydrates
  source-derived totals before returning an overview. Dashboard readers and
  enforcement use the same service.

### F-002 — lifecycle state machine and separation of duties

- Before: lifecycle transitions were not centrally constrained, writes were not
  consistently locked, and approval could be performed by the submitter.
- After: `api/app/Modules/Accounting/Services/BudgetService.php:22` defines
  the allowed transitions; `api/app/Modules/Accounting/Services/BudgetService.php:96`
  and `api/app/Modules/Accounting/Services/BudgetService.php:114` lock rows inside transactions, validate transitions, and enforce
  maker-checker approval; draft updates and creates are transactional at
  `api/app/Modules/Accounting/Services/BudgetService.php:44` and `:64`.

### F-003 — actuals and variance correctness

- Before: sync could leave line variance stale and mixed source aggregation
  with per-line work.
- After: `api/app/Modules/Accounting/Jobs/SyncBudgetActuals.php:49` obtains
  one derived aggregate, updates lines in bounded `chunkById` batches at
  `api/app/Modules/Accounting/Jobs/SyncBudgetActuals.php:60`, and writes exact
  centavo variances before refreshing headers.

### F-004 — sync scalability and recoverability

- Before: actuals rebuild had no durable run ledger or progress/failure state.
- After: `api/database/migrations/2026_08_25_130000_create_budget_actuals_sync_runs.php:13`
  creates the run ledger; `api/app/Modules/Accounting/Models/BudgetActualsSyncRun.php:11`
  models its lifecycle; and `api/app/Modules/Accounting/Jobs/SyncBudgetActuals.php:108`
  records running, progress, completed, and failed states. The status API is
  exposed at `api/app/Modules/Accounting/Controllers/BudgetController.php:416`.

### F-005 — fiscal-year target validation

- Before: omitted or arbitrary sync targets could select the wrong year or
  reach the job with an invalid identifier.
- After: `api/app/Modules/Accounting/Services/BudgetFiscalYearResolver.php:17`
  accepts validated explicit years and otherwise requires an active year whose
  date window contains today; the request service, command, controller, and
  job all use that resolver (`api/app/Modules/Accounting/Services/BudgetActualsSyncService.php:26`,
  `api/app/Console/Commands/SyncBudgetActualsCommand.php:31`, and
  `api/app/Modules/Accounting/Jobs/SyncBudgetActuals.php:44`).

### F-006 — budget-line/account invariants

- Before: duplicate accounts, inactive/non-leaf accounts, incompatible
  account types, and unsafe decimal/size values could enter a budget.
- After: `api/app/Modules/Accounting/Services/BudgetService.php:265`
  normalizes and validates every line, while
  `api/database/migrations/2026_08_25_131000_harden_budget_invariants.php:25`
  adds duplicate-line detection, uniqueness, and nonnegative database checks.

### F-007 — fiscal-year/date-window invariants

- Before: current-year fallback could choose a latest year outside today's
  window, and fiscal-year/date invariants were not database-enforced.
- After: `api/app/Modules/Accounting/Models/FiscalYear.php:45` uses the
  inclusive current date window and
  `api/database/migrations/2026_08_25_131000_harden_budget_invariants.php:37`
  enforces fiscal-year date ordering and nonnegative budget/monthly values.

### F-008 — SPA filtering and pagination

- Before: budgeting pages filtered or paged incomplete client-side data and
  did not keep the selected fiscal year authoritative across views.
- After: `api/app/Modules/Accounting/Controllers/BudgetController.php:127`
  applies server-side filters and returns pagination metadata at
  `api/app/Modules/Accounting/Controllers/BudgetController.php:156`;
  `spa/src/pages/budgeting/index.tsx:30` and
  `spa/src/pages/budgeting/departments.tsx:24` drive fiscal-year, status,
  department/company-wide, page, and page-size state through the API.

### F-009 — sync lifecycle in the SPA

- Before: the rebuild action treated dispatch as completion and offered no
  durable progress or failure feedback.
- After: `api/app/Modules/Accounting/routes.php:147` exposes run status,
  `spa/src/api/accounting/budgeting.ts:87` consumes it, and
  `spa/src/pages/budgeting/budget-vs-actual.tsx:39` polls the selected fiscal
  year's durable run and renders progress, completion, and failure states.

### F-010 — public identifier and money contracts / draft editing

- Before: public budgeting contracts exposed numeric identifiers and mixed
  numeric money values; the SPA lacked a complete draft edit path.
- After: `api/app/Modules/Accounting/Resources/BudgetResource.php:17` and
  `api/app/Modules/Accounting/Resources/BudgetLineItemResource.php:16`
  encode identifiers and return money as strings; the matching contracts are
  defined in `spa/src/types/budgeting.ts:12`; and
  `spa/src/pages/budgeting/create.tsx:57` loads and submits complete draft
  fields and line items through the edit route in
  `spa/src/routes/advancedRoutes.tsx:39`.

## Verification

- Isolated container-backed budgeting suite: **22 tests, 52 assertions
  passed**, including the new GL/commitment/lifecycle/fiscal-year coverage in
  `api/tests/Feature/Accounting/BudgetConsumptionAndLifecycleTest.php:29`.
- Fresh migrations completed as part of the isolated PostgreSQL run.
- Targeted budgeting SPA ESLint: passed with zero warnings.
- PHP syntax checks and `git diff --check` for the scoped changes: passed.
- Repository-wide SPA typecheck remains blocked by unrelated syntax errors in
  `spa/src/pages/crm/sales-orders/create.tsx` (outside budgeting scope); no
  budgeting type errors were reported by the isolated budgeting check.

## Deferred findings

None within `finance/budgeting`. The unrelated CRM typecheck failure is an
external repository gate, not a deferred budgeting fix.
