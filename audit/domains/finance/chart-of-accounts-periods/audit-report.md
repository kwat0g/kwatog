# M025 — Chart of accounts and accounting periods audit report

- Audit date: 2026-08-27
- Domain/module: finance / chart-of-accounts-periods
- Tier: 2
- Surface: M
- Dependencies: auth-session, rbac
- Roles: system admin, finance officer
- Status: Plan Ready
- Claimed by: agent-b
- Test database: `ogami_test_m025_agent_b`

## Scope and disposition

This audit covers the Accounting COA and fiscal-period API/services, their
import boundary, the COA and periods SPA surfaces, and the focused tests. The
module has working HashID resources, transaction-backed hierarchy and period
writes, decimal-string balances, active-account posting guards, and
PostgreSQL period locking.

Two small, contained issues were fixed and verified in this session:

- the COA create/edit forms now mirror the backend code/type/balance/name
  contract;
- the duplicate-period PostgreSQL test resets migration state after committing
  its independent-connection fixtures.

The remaining findings include financial classification, cross-module posting,
permission-boundary, import-authority, period-model, schema, and acceptance
decisions. They are therefore left Plan Ready for separate implementation and
re-audit. No production financial logic, shared configuration, registry file,
dependency module, or M034 was changed.

## Pass 1 — Discovery

### Backend/API surface

- `api/app/Modules/Accounting/routes.php:24-31` exposes authenticated,
  accounting-feature-gated COA list/tree/show, CRUD, activation/deactivation,
  and period list/close/reopen routes. COA view, metadata manage, and status
  permissions are separate.
- `api/app/Modules/Accounting/Controllers/AccountController.php:20-45`
  delegates list/tree/show and status/metadata mutations to the Accounting
  services. `tree()` explicitly resolves `AccountResource` around the service
  tree at `:27-31`.
- `api/app/Modules/Accounting/Services/AccountService.php:21-45` provides a
  flat paginated list; `:49-122` builds a balance-enriched hierarchy and
  detects legacy cycles; `:125-234` owns create/update/status writes under an
  ordered account lock.
- `api/app/Modules/Accounting/Services/AccountingPeriodService.php:32-45`
  provides year/status filtering; `:52-114` owns close and duplicate recovery;
  `:147-190` owns reopen and the posting guard; `:229-260` owns scheduler
  relock.
- `api/app/Modules/Accounting/Imports/AccountImporter.php:18-79` supports
  required `code`, `name`, `type`, and `normal_balance`, plus description,
  parent code, and optional `is_active`, then delegates to `AccountService`.
- `api/app/Modules/Accounting/Resources/AccountResource.php:10-38` emits a
  HashID and decimal-string balance fields; `AccountingPeriodResource.php:8-32`
  emits a HashID, enum status label, and actor metadata.

### Frontend surface

- `spa/src/pages/accounting/coa/index.tsx:19-130` loads the balance-enriched
  tree with loading, error/retry, empty, stale-placeholder, search, and
  mutation feedback states; `:151-202` renders role-aware rows.
- `spa/src/pages/accounting/coa/create.tsx:21-37` and
  `spa/src/pages/accounting/coa/edit.tsx:18-21` provide RHF/Zod metadata
  forms, server-validation handling, draft safety, cancel actions, and
  pending submit states.
- `spa/src/pages/accounting/periods.tsx:41-113` provides URL-backed year and
  status filters; `:115-209` renders period rows, close/reopen actions,
  pagination, and loading/error/empty/data states; `:211-250` owns the reopen
  reason modal.
- `spa/src/routes/accountingRoutes.tsx:44-52` lazy-loads the Accounting pages
  under the module guard and applies page-level permission guards.

### Tests, diffs, and mtimes

- `api/tests/Feature/Accounting/ChartOfAccountsHardeningTest.php:39-141`
  covers hierarchy, cycle safety, inactive posting, generic status mutation,
  and importer invariants.
- `api/tests/Feature/Accounting/ChartOfAccountsAuthorizationTest.php:31-107`
  covers the HTTP view/manage/status permission matrix, but only for the
  exercised endpoints.
- `api/tests/Feature/Accounting/AccountingPeriodCloseRegressionTest.php:40-96`
  covers sequential close, reopen/relock, missing-row reopen, and stale-row
  recheck.
- `api/tests/Feature/Accounting/AccountingPeriodPostingConcurrencyTest.php:68-205`
  now contains independent-connection PostgreSQL interleavings for manual
  post, system post, later close, and scheduler relock.
- `api/tests/Feature/Accounting/ConfiguredControlAccountTypeTest.php:39-179`
  covers the centralized control-account type policy and a wrong-type AP
  posting that commits no bill or journal.
- `spa/src/pages/accounting/coa/index.permissions.test.tsx:56-104` covers the
  four isolated row-grant combinations and status confirmation handler;
  `spa/src/pages/accounting/periods.test.ts:1-25` covers the synthetic
  current-period helper.
- Before this session, `git diff --name-status` showed only the coordinator's
  generated `audit/00-MODULE-REGISTRY.md` diff. The module source/test mtimes
  identify the current hardening commits: the policy/test and row-matrix work
  was written on 2026-08-27, while the Accounting services, routes, and
  inherited audit docs were last written on 2026-08-26. This audit did not
  regenerate or edit the registry.

## Pass 2 — Hardening

### F-001 — Configured GL role enforcement is not fail-closed for every role

- Classification: **Broken**
- Scope: **large**
- Session recommendation: **separate-recommended**
- Evidence: `AccountingAccountPolicyService::typeFor()` is keyed by the
  incoming account code and returns the first matching `match` arm at
  `api/app/Modules/Accounting/Services/AccountingAccountPolicyService.php:46-55`.
  `controlAccountId()` then resolves one type for that code at `:66-72`.
  The existing regression test deliberately pins the silent collision:
  `api/tests/Feature/Accounting/ConfiguredControlAccountTypeTest.php:106-123`
  points `vat_input_code` at the AP code, observes `Liability`, and accepts the
  same account ID. AP requires liability while VAT Input requires asset, so the
  VAT Input line can be posted to a liability account while the journal remains
  balanced.
- The same authority is bypassed by downstream configured-code writers:
  Payroll bulk-plucks configured accounts without active/type checks at
  `api/app/Modules/Payroll/Services/PayrollGlPostingService.php:174-186`;
  GRNI does the same at
  `api/app/Modules/Inventory/Services/GrnGlPostingService.php:113-125`; and
  Return Management resolves the configured default revenue ID with a raw
  query at `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1318-1350`.
  These consumers are outside the M025 edit boundary, but they are part of the
  configured-account contract M025 owns.
- Impact: unique AP/AR/VAT/discount settings are type-checked on the current
  Accounting writers, but conflicting settings and several cross-module
  writers can still produce semantically misclassified financial postings.
- Recommendation: key the policy by role rather than code, reject incompatible
  duplicate role assignments before posting, include every configured semantic
  role, and route downstream writers through the typed resolver. Coordinate the
  cross-module changes with M026 and the Payroll, Inventory, Assets, HR, and
  Return Management owners.

### F-003 — CSV metadata and status authority do not match the importer

- Classification: **Incomplete**
- Scope: **medium**
- Session recommendation: **separate-recommended**
- Evidence: `AccountImporter` documents and accepts optional `is_active` at
  `api/app/Modules/Accounting/Imports/AccountImporter.php:14-17,64-75`.
  The shared import metadata advertises only `description` and `parent_code`
  for `coa` at `api/app/Common/Services/Import/MasterDataImportService.php:57-74`.
  Import routes are gated by only `admin.import.manage` at
  `api/app/Modules/Admin/routes.php:191-203`; they do not also require the
  separate `accounting.coa.deactivate` authority.
- Impact: the import UI cannot discover or guide users for a supported status
  column, and a trusted migration-capability holder can create an inactive
  account without the COA status permission. The importer correctly delegates
  to `AccountService`, so this is a contract/authority gap rather than an
  invariant bypass.
- Recommendation: derive metadata from the importer, including `is_active`,
  and decide whether migration staff are an intentional status-authority
  exception. Enforce and test that decision across dry-run, commit, rollback,
  and inactive-row cases. The metadata half is shared infrastructure and was
  not modified here.

### F-004 — Split COA permissions still do not form a usable SPA workflow

- Classification: **Incomplete**
- Scope: **medium**
- Session recommendation: **separate-recommended**
- Evidence: the API grants independent view, metadata, and status authorities
  at `api/app/Modules/Accounting/routes.php:25-31`. However, the actual COA
  page requires only `accounting.coa.view` at
  `spa/src/routes/accountingRoutes.tsx:47-52`, and the only sidebar entry is
  also view-gated at `spa/src/components/layout/Sidebar.tsx:475-480`.
  A manage-only edit route then fetches its account through
  `accountsApi.show()` in `spa/src/pages/accounting/coa/edit.tsx:29-32`, while
  the corresponding API show route requires view at
  `api/app/Modules/Accounting/routes.php:25-27`.
  The six passing tests at
  `spa/src/pages/accounting/coa/index.permissions.test.tsx:67-79` render
  `TreeRow` in isolation and do not exercise route discovery or the account
  fetch.
- Impact: the API's manage-only and deactivate-only combinations pass their
  direct authorization tests, but a custom role holding only either grant
  cannot use the corresponding page workflow. The row component advertises a
  status-only action that the real route cannot reach.
- Recommendation: choose explicitly whether view is a prerequisite for manage
  and status grants. If independent grants are required, provide a deliberate
  read/status workflow and matching API guard; otherwise make the implication
  explicit and change the row/API tests. Do not silently broaden account
  visibility as part of a UI-only fix.

### F-005 — The period `Open` filter does not match the absence-means-open model

- Classification: **Incomplete**
- Scope: **medium**
- Session recommendation: **separate-recommended**
- Evidence: `AccountingPeriodService::assertPostingAllowed()` treats a missing
  row as open at `api/app/Modules/Accounting/Services/AccountingPeriodService.php:191-210`.
  `close()` creates a row directly as closed at `:94-114`, while the SPA's
  `Open` filter and table render only persisted API rows at
  `spa/src/pages/accounting/periods.tsx:82-100,115-186`.
  The only synthetic open row is the current-month empty-state helper at
  `spa/src/pages/accounting/periods.tsx:122-133` and
  `spa/src/pages/accounting/implicitOpenCurrentPeriod.ts:3-18`.
- Impact: selecting `Open` normally returns no rows even though untouched
  months are open, and after one row exists the page offers no close action for
  another absent month. A filtered empty state also describes a filtered result
  as if no period rows exist.
- Recommendation: either materialize/list a bounded set of open months or
  remove the unreachable filter and add a deliberate month-picker/current-month
  close workflow. Record the product decision in the API/UI contract before
  changing the surface.

### F-007 — M025 acceptance coverage is still incomplete at the HTTP/resource boundary

- Classification: **Missing**
- Scope: **large**
- Session recommendation: **separate-recommended**
- Evidence: the hardening suite exercises service behavior at
  `api/tests/Feature/Accounting/ChartOfAccountsHardeningTest.php:39-141`, and
  the authorization suite exercises only list, update, and status combinations
  at `api/tests/Feature/Accounting/ChartOfAccountsAuthorizationTest.php:31-107`.
  There is no focused assertion for `AccountController::tree()`'s wrapped
  response at `api/app/Modules/Accounting/Controllers/AccountController.php:27-31`,
  nor a complete route-level matrix for show/create/tree, validation, parent
  status policy, and resource fields. The SPA grant test only renders a row,
  not `accountingRoutes` or the sidebar.
- Impact: a service regression can leave the HTTP response shape, HashID
  serialization, FormRequest boundary, or real page authorization broken while
  the current tests remain green. Import metadata and cross-module typed
  writers likewise have no M025 acceptance gate.
- Recommendation: add a focused M025 acceptance suite for tree/show/CRUD and
  activation responses, FormRequest/RBAC combinations, parent/child policies,
  import metadata, and the real SPA route/page permission matrix. Keep
  cross-module writer tests with their owning modules but require the shared
  typed-account contract.

### F-009 — Period service accepts out-of-contract values from internal callers

- Classification: **Incomplete**
- Scope: **medium**
- Session recommendation: **separate-recommended**
- Evidence: HTTP close requests constrain year to 2000–2100 and month to 1–12
  at `api/app/Modules/Accounting/Requests/CloseAccountingPeriodRequest.php:16-21`.
  The service close path validates only the month at
  `api/app/Modules/Accounting/Services/AccountingPeriodService.php:52-57,213-223`;
  it has no service-level year check. Reopen trims a reason and rejects only an
  empty string at `:147-155`, while its HTTP request also requires 3–500
  characters at `api/app/Modules/Accounting/Requests/ReopenAccountingPeriodRequest.php:16-22`.
  The original period migration has `smallInteger` year/month columns and no
  range checks at `api/database/migrations/0198_create_accounting_periods_table.php:13-26`.
- Verification: an isolated-database probe invoked
  `AccountingPeriodService::close(1999, 1, new User())` and created a closed
  1999-01 row; the probe removed that row from the isolated database afterward.
- Impact: a console, job, seeder, or future service caller can create a period
  outside the HTTP contract or persist a one-character/overlong reopen reason.
  The normal controller path is protected, so this is an internal-boundary and
  defense-in-depth gap rather than a current HTTP bypass.
- Recommendation: define the supported internal period range and reason
  contract, enforce it in the service, and add compatible database checks after
  preflighting existing data. Keep the invalid-internal-input error mapping
  deliberate rather than turning caller defects into operator-facing 422s.

### F-010 — COA enum values are not protected by database constraints

- Classification: **Missing**
- Scope: **medium**
- Session recommendation: **separate-recommended**
- Evidence: `AccountType` and `NormalBalance` are backed enums at
  `api/app/Modules/Accounting/Enums/AccountType.php:7-18` and
  `api/app/Modules/Accounting/Enums/NormalBalance.php:7-18`, but the accounts migration defines both columns as
  unconstrained strings at `api/database/migrations/0038_create_accounts_table.php:20-32`.
  `AccountService` validates these values only before its normal create path at
  `api/app/Modules/Accounting/Services/AccountService.php:125-143,327-339`.
  The current lifecycle-check migration protects `accounting_periods.status`,
  but does not add an equivalent `accounts.type` or `accounts.normal_balance`
  check at `api/database/migrations/2026_08_13_220000_add_remaining_lifecycle_status_checks.php:20-25`.
- Impact: a raw SQL/import/seed path that bypasses the service can persist an
  invalid classification; later enum casting, tree balance calculation, or a
  configured-account lookup can fail or operate on corrupted financial master
  data. The normal HTTP/import paths currently validate, so this is a missing
  database guard rather than evidence of an existing bad row.
- Recommendation: preflight existing values and add PostgreSQL/SQLite guards
  for the two enum columns, with a migration test. Keep the service validation
  as the user-facing error boundary.

## Pass 3 — Polish

### F-011 — COA form validation contract drift: fixed this session

- Classification: **Incomplete** (now **fixed + verified**)
- Scope: **small**
- Session recommendation: **same-session-ok**
- Before evidence: the create form accepted codes up to 20 characters, names
  only up to 100 characters, and arbitrary type/normal-balance strings; the edit
  form also capped names at 100. The backend requires a 3–6 digit code, enum
  type/normal-balance values, and a 150-character name at
  `api/app/Modules/Accounting/Requests/StoreAccountRequest.php:16-23`.
- After evidence: `spa/src/pages/accounting/coa/create.tsx:21-37` now enforces
  the same code pattern, type and balance values, and 150-character name;
  `spa/src/pages/accounting/coa/edit.tsx:18-21` accepts the backend name length.
- Impact before the fix: valid 101–150-character names were rejected in the
  SPA, while malformed type/balance/code values reached the server instead of
  receiving immediate form feedback.
- Verification: SPA ESLint and `npx tsc --noEmit` passed; the focused COA and
  periods suite passed 2 files / 7 tests.

### F-012 — Duplicate-period test committed fixtures without class isolation: fixed this session

- Classification: **Incomplete** (now **fixed + verified**)
- Scope: **small**
- Session recommendation: **same-session-ok**
- Before evidence: the PostgreSQL competitor requires committing the
  `RefreshDatabase` transaction at
  `api/tests/Feature/Accounting/AccountingPeriodDuplicateRecoveryTest.php:50-54`,
  but the class had no migration-state reset; its committed user and period
  could contaminate the next test class.
- After evidence: the class now resets
  `RefreshDatabaseState::$migrated` in
  `api/tests/Feature/Accounting/AccountingPeriodDuplicateRecoveryTest.php:25-36`,
  matching the committed-fixture ownership of the period concurrency harness.
- Verification: PHP syntax passed and the isolated PostgreSQL test passed 1
  test / 3 assertions on `ogami_test_m025_agent_b`.

## Prior findings rechecked

- **F-002 duplicate-period recovery — resolved.** The `23505` catch now starts
  recovery in a new transaction after Laravel rolls back the failed insert at
  `api/app/Modules/Accounting/Services/AccountingPeriodService.php:56-90`.
  The duplicate-recovery test passed against PostgreSQL; no service change was
  needed in this session.
- **F-006 close-versus-post barrier — resolved in the inherited source/tests.**
  Close and posting use the same advisory/row-lock order, and the current
  concurrency harness covers existing and row-less periods plus scheduler
  relock at `api/tests/Feature/Accounting/AccountingPeriodPostingConcurrencyTest.php:68-205`.
  The focused backend run passed.
- **F-008 periods pagination narrowing — resolved.** The page captures
  `periodsQ.data` before deriving rows/meta at
  `spa/src/pages/accounting/periods.tsx:72-75`; full SPA typechecking is now
  clean.
- The inherited service-level hierarchy, cycle, inactive-account, generic
  status, importer-delegation, and scheduler-recheck findings remain fixed in
  their current forms. Accounting Bill, Invoice, and Credit Note writers now
  use the centralized typed policy; F-001 still records its collision and
  cross-module residuals.

## Verification

All backend assertions below used `DB_DATABASE=ogami_test_m025_agent_b` inside
the API container; the shared `ogami_test` database was not used.

- Focused backend command:
  `docker compose exec -T -e DB_DATABASE=ogami_test_m025_agent_b api php artisan test --filter='(ChartOfAccountsHardeningTest|ChartOfAccountsAuthorizationTest|AccountingPeriodAuthorizationTest|AccountingPeriodTest|AccountingPeriodCloseRegressionTest|AccountingPeriodDuplicateRecoveryTest|AccountingPeriodPostingConcurrencyTest|ConfiguredControlAccountTypeTest)'`
  — **37 tests passed / 107 assertions**.
- Duplicate-recovery rerun after F-012:
  `docker compose exec -T -e DB_DATABASE=ogami_test_m025_agent_b api php artisan test --filter=AccountingPeriodDuplicateRecoveryTest`
  — **1 test passed / 3 assertions**.
- SPA focused tests:
  `npm run test -- --run src/pages/accounting/coa/index.permissions.test.tsx src/pages/accounting/periods.test.ts`
  — **2 files, 7 tests passed**.
- SPA `npx eslint` on the changed COA forms — passed.
- SPA `npx tsc --noEmit` — passed with no output.
- `php -l` on the changed backend test — passed.
- `git diff --check` on the changed module files — passed.
- The backend suite still prints existing PHPUnit doc-comment metadata
  deprecation warnings in unrelated CRM, Dashboard, Return Management, and
  Supply Chain tests; no assertion failed.

## Decisions and deferred work

1. Decide whether import staff with `admin.import.manage` may set COA status or
   must also hold `accounting.coa.deactivate`.
2. Decide whether absent period rows are the complete representation of open
   months or whether the periods surface should materialize a bounded set.
3. Decide whether custom roles may hold manage/status without view, then make
   that implication explicit across API, route, sidebar, and tests.
4. Coordinate typed configured-account enforcement with dependent modules; no
   dependency source was modified here.

## Audit boundary

Dependency modules were read only for configured GL-consumer context. All
writes in this session were limited to M025-owned audit files, the M025 COA
form files, and the M025 duplicate-period test. The coordinator-owned
`audit/00-MODULE-REGISTRY.md` diff was preserved unchanged, and M034 was not
read or modified as a target.
