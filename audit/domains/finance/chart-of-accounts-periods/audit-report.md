# M025 — Chart of accounts and accounting periods audit report

- Audit date: 2026-08-25
- Domain/module: finance / chart-of-accounts-periods
- Tier: 2
- Surface: M
- Dependencies: auth-session, rbac
- Roles: system admin, finance officer
- Status: Plan Ready
- Source changes: the prior report was invalidated by substantial uncommitted
  changes in the shared worktree; this session made no production-source changes.

## Scope and summary

The module has a working API and SPA surface for COA list/tree, CRUD,
activation, period list, close, reopen, and CSV setup. Current writes use
transactions, HashID resources, decimal-string balances, and shared business
exceptions. The current worktree also contains the intended hardening from the
previous report: period lifecycle and posting now share a PostgreSQL advisory
lock, account hierarchy writes lock the account set and validate cycles/type,
new journal lines pass through an active-account resolver, generic account
updates cannot change status, the importer calls `AccountService`, and the SPA
period list/reopen form has the previous pagination/filter/reset fixes.

The fresh audit found residual authority and completeness gaps:

1. Some configured GL account paths verify only that an account is active, not
   that its COA type matches its semantic role.
2. The PostgreSQL duplicate-period fallback is not transaction-safe after a
   unique violation, although the normal service paths are serialized before
   they reach it.
3. CSV metadata and status authority do not match the importer contract.
4. Split COA permissions are not represented completely in the SPA.
5. The period UI's `Open` filter does not match the absence-means-open model.
6. The new locking and hierarchy rules still lack true PostgreSQL race and
   route/RBAC acceptance coverage.
7. The current SPA period page does not typecheck because pagination metadata
   is dereferenced without narrowing the query result.

These findings include financial-state and cross-module work, so this first
post-change audit produces a Plan Ready handoff rather than applying fixes.

## Discovery

### Backend

- `api/app/Modules/Accounting/routes.php:20-38` exposes authenticated,
  accounting-feature-gated COA and period routes with separate view/manage and
  deactivate/activate permissions.
- `api/app/Modules/Accounting/Services/AccountService.php:21-42` provides a
  paginated flat list; `:49-123` builds a balance-enriched tree and fails
  deterministically on cyclic parent data; `:125-234` owns create/update,
  deactivation, and activation.
- `api/app/Modules/Accounting/Services/AccountingPeriodService.php:32-45`
  provides paginated year/status filtering; `:51-176` owns close, reopen, and
  the posting guard; `:194-251` owns scheduler relock.
- `api/app/Modules/Accounting/Imports/AccountImporter.php:18-79` supports
  required code/name/type/normal-balance columns plus description, parent code,
  and optional `is_active`, then delegates creation to `AccountService`.
- `api/app/Modules/Accounting/Services/PostingAccountResolver.php:20-123`
  centralizes account existence/active checks and can enforce types when the
  caller supplies them.

### Frontend

- `spa/src/pages/accounting/coa/index.tsx:19-130` renders the tree with
  loading/error/empty states, search, expansion, balances, and manage-gated
  create/edit links; `:151-202` renders role-aware tree rows.
- `spa/src/pages/accounting/coa/edit.tsx:20-70,75-107` edits account metadata
  and invokes dedicated activation/deactivation endpoints when authorized.
- `spa/src/pages/accounting/periods.tsx:41-209` renders paginated periods,
  year/status filters, loading/error/empty states, close/reopen actions, and
  role-aware controls; `:211-250` owns the reason modal.
- `spa/src/pages/accounting/implicitOpenCurrentPeriod.ts:3-18` creates a
  client-only current-month open target for the empty-state close action.

### Tests and verification surface

- `api/tests/Feature/Accounting/ChartOfAccountsHardeningTest.php:39-141`
  covers service-level hierarchy, cycle, inactive-posting, status-update, and
  importer regressions.
- `api/tests/Feature/Accounting/AccountingPeriodCloseRegressionTest.php:40-96`
  covers sequential idempotent close, reopen/relock, and stale-row recheck.
- `api/tests/Feature/Accounting/JournalEntryPostRaceTest.php:52-76` covers a
  stale journal draft after a concurrent terminal-state change, not a
  close-versus-post interleaving.
- `api/tests/Feature/Accounting/AccountingPeriodAuthorizationTest.php`
  covers period permission boundaries; no equivalent focused AccountController
  CRUD/activation permission suite was found.

## Prior findings rechecked

The previous report's period barrier, hierarchy validation, inactive posting,
generic status mutation, importer delegation, scheduler relock, period list,
and reopen-modal findings are no longer present in their originally reported
forms:

- Period close and posting both acquire the same transaction-scoped advisory
  lock and then lock the authoritative row at
  `api/app/Modules/Accounting/Services/AccountingPeriodService.php:55-67,154-167`
  and `api/app/Modules/Accounting/Services/JournalEntryService.php:267-287,325-337`.
- Account updates use ordered account locks and validate parent existence,
  activity, type, self-parenting, and descendant cycles at
  `api/app/Modules/Accounting/Services/AccountService.php:164-193,264-310`;
  tree reads detect legacy cycles at `:49-77`.
- New journal lines resolve active accounts at
  `api/app/Modules/Accounting/Services/JournalEntryService.php:490-519` and
  re-lock them before posting at `:285-287,335-337`.
- Generic status changes are rejected at
  `api/app/Modules/Accounting/Services/AccountService.php:147-151`, while
  status endpoints are separately permission-gated at
  `api/app/Modules/Accounting/routes.php:28-31`.
- The importer now delegates creation to the invariant-bearing service at
  `api/app/Modules/Accounting/Imports/AccountImporter.php:64-79`.
- Scheduler relock uses a transaction, advisory lock, row lock, and fresh
  status/time recheck at
  `api/app/Modules/Accounting/Services/AccountingPeriodService.php:203-229`.
- Period pagination/year/status controls and reopen-target reset are present at
  `spa/src/pages/accounting/periods.tsx:45-59,82-113,179-184,219-223`.
- The status form now uses explicit string values rather than the previously
  reported coercion at `spa/src/pages/accounting/coa/edit.tsx:20-24,42-46`.

## Findings

### F-001 — Configured GL account roles are not type-safe on every posting path

- Classification: Broken
- Scope: large
- Session recommendation: separate-recommended
- Evidence: `PostingAccountResolver::configuredIdByCode()` supports semantic
  type enforcement when types are passed at
  `api/app/Modules/Accounting/Services/PostingAccountResolver.php:99-107`.
  Invoice passes `Asset`/`Liability` for AR/VAT at
  `api/app/Modules/Accounting/Services/InvoiceService.php:240-241,645-647`,
  and Credit Note maps AP/AR/VAT codes to expected types at
  `api/app/Modules/Accounting/Services/CreditNoteService.php:380-392`.
  Bill posting resolves AP and VAT Input with the untyped helper at
  `api/app/Modules/Accounting/Services/BillService.php:968-995,1028-1031`.
  Payroll and inventory consumers also build configured-code line IDs with
  raw account queries at
  `api/app/Modules/Payroll/Services/PayrollGlPostingService.php:174-200` and
  `api/app/Modules/Inventory/Services/GrnGlPostingService.php:113-125`.
- Impact: An active account of the wrong type can receive a semantically
  specific bill/AP, VAT, payroll, GRNI, or inventory posting. The canonical
  journal boundary rejects missing/inactive accounts, but it intentionally does
  not infer semantic types from an arbitrary line. This can therefore produce
  a balanced journal with incorrect financial classification.
- Recommendation: Define one typed configuration map owned by Accounting,
  route every configured-code lookup through it, and make each automated
  writer pass the expected `AccountType` before the final journal transaction.
  Add negative tests for active-but-wrong-type control accounts and verify the
  type remains authoritative across a draft-to-post race.

### F-002 — PostgreSQL duplicate-period recovery queries after an aborted transaction

- Classification: Incomplete
- Scope: medium
- Session recommendation: separate-recommended
- Evidence: `close()` catches a PostgreSQL unique violation from `save()` and
  immediately issues a `SELECT ... FOR UPDATE` in the same outer transaction at
  `api/app/Modules/Accounting/Services/AccountingPeriodService.php:55-99`.
  PostgreSQL marks the transaction failed after a `23505` statement error;
  without a savepoint or outer retry, the recovery query cannot run in that
  transaction. The advisory lock at `:60` prevents this race among cooperating
  service callers, but the catch block claims to handle a concurrent insert
  from a non-cooperating writer or a deployment path that did not take the
  advisory lock.
- Impact: The advertised brand-new-row fallback can turn an otherwise
  recoverable duplicate close into a transaction-aborted error. This is a
  narrow edge path, but it sits on period creation and undermines the
  close-is-idempotent contract under concurrent writers.
- Recommendation: Remove the misleading in-transaction fallback in favor of a
  savepoint or retrying the whole transaction after rollback, and add a
  PostgreSQL test that exercises the duplicate insert path explicitly.

### F-003 — CSV metadata and status authority do not match the importer contract

- Classification: Incomplete
- Scope: medium
- Session recommendation: separate-recommended
- Evidence: `AccountImporter` documents and accepts optional `is_active` at
  `api/app/Modules/Accounting/Imports/AccountImporter.php:14-17,64-75`.
  The shared import schema, which is the API/UI source of truth, advertises
  only `description` and `parent_code` for COA at
  `api/app/Common/Services/Import/MasterDataImportService.php:57-74`.
  The import routes authorize only `admin.import.manage` at
  `api/app/Modules/Admin/routes.php:191-203`; they do not also require
  `accounting.coa.deactivate`.
- Impact: Import tooling cannot discover or guide users for a supported status
  column. A trusted migration-capability holder can also create an inactive
  account without the otherwise separate COA status permission. The importer
  delegation and batch atomicity are present, so this is a contract/authority
  gap rather than the previous invariant bypass.
- Recommendation: Make the metadata derive from the importer (including
  `is_active`) and explicitly decide whether import is a trusted exception to
  COA status authority. If not, add the COA permission check; if yes, document
  and audit that exception. Add dry-run/commit metadata and inactive-row tests.

### F-004 — Split COA permissions are not completely usable in the SPA

- Classification: Incomplete
- Scope: medium
- Session recommendation: separate-recommended
- Evidence: The API deliberately separates generic manage from status
  activation/deactivation at `api/app/Modules/Accounting/routes.php:25-31`.
  The tree shows the edit link only when `accounting.coa.manage` is present at
  `spa/src/pages/accounting/coa/index.tsx:80-84,182-190`, and the edit route
  itself also requires manage at `spa/src/routes/accountingRoutes.tsx:46-51`.
  The edit form makes its required `is_active` field disabled without the
  separate status permission at `spa/src/pages/accounting/coa/edit.tsx:20-24,32,40-47,99-102`.
- Impact: A role with view + deactivate can use the API but has no UI action to
  deactivate or activate an account. A role with manage but not deactivate is
  routed into a form whose required registered status control is disabled;
  native disabled controls are omitted from submitted form data, so metadata
  edits are at risk of failing client validation. There is no focused
  role-matrix/component regression covering these two legitimate permission
  combinations.
- Recommendation: Expose dedicated status actions to the status permission,
  keep generic metadata editing independent of the status field, and add
  component/API tests for view-only, manage-only, deactivate-only, and full
  COA roles.

### F-005 — The period `Open` filter does not match the absence-means-open model

- Classification: Incomplete
- Scope: medium
- Session recommendation: same-session-ok
- Evidence: The service documents a missing period row as open at
  `api/app/Modules/Accounting/Services/AccountingPeriodService.php:147-152`.
  Its `close()` path creates a row directly as `closed` at `:67-80`, while the
  SPA offers an `Open` status filter and renders only API rows at
  `spa/src/pages/accounting/periods.tsx:82-100,115-186`. The only client-side
  open row is a synthetic current-month object used by the empty-state action
  at `spa/src/pages/accounting/periods.tsx:122-133` and
  `spa/src/pages/accounting/implicitOpenCurrentPeriod.ts:3-18`.
- Impact: In normal operation, selecting `Open` generally returns no rows even
  though unpersisted months are the open state. After one period exists, the
  page does not offer a close action for another absent/open month; the helper
  only covers the current month when the entire list is empty. This makes the
  filter and historical close workflow misleading.
- Recommendation: Either model/list the relevant open months explicitly, or
  remove the unreachable filter and provide a deliberate month-picker/current
  month close action. Record the product decision in the API/UI contract.

### F-006 — No PostgreSQL interleaving regression proves close-versus-post safety

- Classification: Missing
- Scope: large
- Session recommendation: separate-recommended
- Evidence: The implementation now coordinates the posting guard and close
  with advisory/row locks at
  `api/app/Modules/Accounting/Services/AccountingPeriodService.php:55-67,154-167`
  and `api/app/Modules/Accounting/Services/JournalEntryService.php:267-287,325-337`.
  The current period regression suite exercises only sequential close,
  reopen/relock, and stale-candidate behavior at
  `api/tests/Feature/Accounting/AccountingPeriodCloseRegressionTest.php:40-96`.
  `api/tests/Feature/Accounting/JournalEntryPostRaceTest.php:52-76` tests a
  stale journal status flip, not a period lifecycle race. No focused test
  starts independent PostgreSQL transactions for close versus manual,
  system, and scheduled/automated posting.
- Impact: The highest-risk financial barrier can regress while all current
  sequential tests remain green. The code comments describe the desired
  happens-before rule, but the repository lacks executable evidence for it.
- Recommendation: Add deterministic PostgreSQL concurrency tests for an
  existing period and a row-less month, covering both `post()` and
  `postSystem()`, plus the scheduler relock path. Assert that either posting
  commits before close or is rejected after close becomes authoritative.

### F-007 — COA API/RBAC and cross-writer acceptance coverage is incomplete

- Classification: Missing
- Scope: large
- Session recommendation: separate-recommended
- Evidence: The new service-level regression file covers hierarchy and a
  manual journal inactive-account case at
  `api/tests/Feature/Accounting/ChartOfAccountsHardeningTest.php:39-112`,
  plus two importer cases at `:114-141`. The available controller test
  `api/tests/Feature/Common/ControllerSqlLeakTest.php:102-187` mocks the
  account service for rendering/error behavior rather than exercising the
  COA API contract. No focused suite covers the account route permission
  matrix, dedicated activate/deactivate child policy, type-safe configured
  AP/VAT paths, or active-but-wrong-type automated writers.
- Impact: The new invariants can regress at the HTTP boundary or in a
  cross-module writer without a failing M025 test. Service tests alone do not
  prove FormRequest authorization, route binding, response shape, or the
  frontend role matrix.
- Recommendation: Build an M025 acceptance suite covering API route/RBAC
  combinations, stale hierarchy writes, tree serialization, import metadata,
  configured account type checks, inactive-account negative cases across
  canonical writers, and the four SPA COA permission combinations.

### F-008 — Period pagination dereferences an optional query result

- Classification: Broken
- Scope: small
- Session recommendation: same-session-ok
- Evidence: The period page conditionally renders the table when
  `periods.length > 0` at `spa/src/pages/accounting/periods.tsx:136-177`, but
  passes `periodsQ.data.meta` without a data guard at `:179-184`. `npm run
  typecheck` reports `TS18048: periodsQ.data is possibly undefined` at line
  180, so the current SPA build does not typecheck even though the query data
  is logically present when rows exist.
- Impact: CI/build validation fails for an in-scope page. The runtime branch is
  likely safe because `periods` is derived from the same response, but the
  compiler cannot prove that relationship and blocks a clean deliverable.
- Recommendation: Narrow or capture the response before rendering pagination,
  then add the page typecheck/component test to the M025 verification gate.

## Questions requiring an explicit decision

1. Is `admin.import.manage` intentionally allowed to set COA `is_active`, or
   must `accounting.coa.deactivate` remain the sole status authority? Current
   source supports both interpretations, so the audit does not assume one.
2. Are absent period rows the intended representation of all open months, or
   should the periods surface expose a finite set of open months? The answer
   determines whether F-005 is a UI correction or a period-list contract
   change.

## Verification notes

- `php -l` passed for the current Accounting and importer service files.
- `git diff --check` passed for the scoped source/test/audit paths.
- SPA `npm run typecheck` completed with one in-scope error at
  `spa/src/pages/accounting/periods.tsx:180` (`TS18048`) and four unrelated
  errors in HR, Assets, and CRM pages. The in-scope error is recorded as F-008;
  unrelated source was not modified.
- The requested focused backend run could not execute assertions: the shared
  `ogami_test` database was already missing its `migrations` table and had
  duplicate-table/duplicate-role state, resulting in 31 setup failures and
  zero assertions. This is recorded as an environment limitation, not a
  source pass/fail.

## Audit boundary

Dependency modules were read only for GL-consumer context. No dependency source
was modified. The typed configured-account work should be coordinated with the
M026 journal-ledger audit and downstream AR/AP, payroll, inventory, assets, and
HR writers.
