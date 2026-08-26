# M025 — chart-of-accounts-periods fix log

Audit date: 2026-08-25  
Final disposition: Needs Re-audit

## Session ownership

- Registry was regenerated before claim.
- Claimed `finance / chart-of-accounts-periods` with `CLAIMED` because it was
  the highest-priority unlocked `Needs Re-audit` candidate.
- The prior report was invalidated by current uncommitted source changes. Those
  changes predate this claim and are not attributed as fixes from this session.
- Unrelated worktree changes were preserved.

## Verification of current source

The current worktree contains and this session rechecked the implementation of
the earlier hardening items:

- period close/post advisory-lock coordination and scheduler row recheck;
- COA parent type/self/descendant validation and cycle-safe tree reads;
- active-account resolution at journal creation/posting;
- dedicated account status endpoints and generic-update rejection;
- importer delegation through `AccountService`;
- paginated period filters, explicit SPA status parsing, and reopen-target reset.

No production source files were changed by this session, so there are no
before/after fix entries to claim.

## Verification commands

- `php -l` passed for the current Accounting and importer service files.
- `git diff --check` passed for the scoped source/test/audit paths.
- SPA `npm run typecheck` was started in `ogami-spa` and completed with one
  in-scope error at `spa/src/pages/accounting/periods.tsx:180` (`TS18048`) and
  four unrelated errors in HR, Assets, and CRM pages. No source fix was made.
- The requested focused backend suite could not execute assertions because the
  shared `ogami_test` database was already missing its `migrations` table and
  contained duplicate-table/duplicate-role state. It ended with 31 setup
  failures and zero assertions. No source failure is inferred from that run.

## Deferred findings

- **F-001:** configured GL account semantic types remain inconsistent across
  bill and cross-module writers; financial and cross-module scope.
- **F-002:** duplicate-period recovery needs a savepoint or outer retry after a
  PostgreSQL unique violation.
- **F-003:** importer metadata/status authority requires a common-contract and
  RBAC decision.
- **F-004:** split COA permission combinations need a dedicated SPA workflow and
  role-matrix tests.
- **F-005:** the intended representation and UI workflow for absent/open months
  needs an explicit product decision.
- **F-006:** true PostgreSQL close-versus-post interleaving tests are missing.
- **F-007:** route/RBAC and cross-writer acceptance coverage is missing.
- **F-008:** the period page's pagination metadata needs a data narrowing fix;
  the current SPA build therefore does not typecheck cleanly.

This is a Plan Ready handoff, not a claim that any deferred finding is fixed.

## 2026-08-25 — dedicated implementation session

This session resumed the existing Plan Ready handoff. It did not redo the
discovery audit or rewrite the action plan. All changes below are confined to
M025 source surfaces and M025 Accounting tests; unrelated shared worktree
changes were preserved.

### Fixed and rechecked

- **F-002 — duplicate-period recovery**
  - Before: `api/app/Modules/Accounting/Services/AccountingPeriodService.php:55-99`
    caught PostgreSQL `23505` inside the transaction that had already become
    aborted, then queried in that failed transaction.
  - After: `:56-135` lets the transaction roll back, catches only the duplicate
    SQL state outside it, and re-reads/locks/re-closes the winner in a fresh
    transaction. `api/tests/Feature/Accounting/AccountingPeriodDuplicateRecoveryTest.php:24-75`
    installs a non-cooperating insert race to prove the recovery path.

- **F-004 — split COA permission UI**
  - Before: `spa/src/pages/accounting/coa/edit.tsx:18-60` made `is_active` a
    required form field and disabled it for users without the status grant;
    `spa/src/pages/accounting/coa/index.tsx:75-84,228-236` exposed no status
    action to the independent permission.
  - After: `spa/src/pages/accounting/coa/edit.tsx:18-60,88-92` keeps metadata
    editing independent and renders status read-only. The tree at
    `spa/src/pages/accounting/coa/index.tsx:75-85,139-148,237-248` exposes
    confirmation-backed activate/deactivate actions only to
    `accounting.coa.deactivate`. `api/tests/Feature/Accounting/ChartOfAccountsAuthorizationTest.php:24-107`
    covers view-only, manage-only, status-only, and full-access API matrices.

- **F-006 — close/post interleaving coverage**
  - Added `api/tests/Feature/Accounting/AccountingPeriodPostingConcurrencyTest.php:61-193`
    with PostgreSQL two-connection coverage for manual posting against an
    existing closed period, system posting against a row-less month, the
    allowed post-before-close ordering, and scheduler stale-row rechecking.

- **F-008 — period pagination type narrowing**
  - Before: `spa/src/pages/accounting/periods.tsx:179-184` dereferenced
    `periodsQ.data.meta` without narrowing the optional query result.
  - After: `spa/src/pages/accounting/periods.tsx:73-75,180-186` captures the
    response/meta and renders pagination only when metadata exists. The old
    `periodsQ.data.meta` access is gone.

### Verification

- `php -l` passed for the changed Accounting service and all three new test
  files.
- Scoped `git diff --check` passed.
- ESLint passed for the three changed SPA pages.
- `npm run typecheck` was given 90 seconds but timed out without producing a
  result; no type error was emitted. The prior in-scope unsafe dereference was
  statically removed, but a full typecheck remains a re-audit gate.
- The targeted Laravel test run could not reach assertions because the shared
  test database host `db` could not be resolved (`SQLSTATE[08006]`). This is an
  environment/setup blocker, not evidence of a source failure.

### Still pending / deferred

- **F-001:** configured GL account semantic typing still spans Bill, Payroll,
  Inventory, and other writers. Completing it would modify cross-module files,
  which this session is not permitted to change; it also needs coordination
  with M026/downstream posting owners.
- **F-003:** importer metadata lives in the shared import service outside M025,
  and the `admin.import.manage` versus `accounting.coa.deactivate` authority
  question remains unanswered. No authority policy was guessed.
- **F-005:** the open-period filter requires the explicit product decision
  recorded in the audit report (finite open-month rows versus absent rows).
- **F-007:** the new API/RBAC matrix and PostgreSQL race suites cover part of
  the acceptance surface, but configured-account type checks across automated
  writers and SPA component-level role coverage remain pending.
- **F-006/F-008 verification:** the authored regressions and static checks are
  present, but the shared database/typecheck environment must be restored and
  the targeted gates rerun before final verification.

M025 is released as `🔁 Needs Re-audit`, with the pending items above recorded
for the next session rather than presented as a fresh Plan Ready bounce-back.

## 2026-08-27 — verification session (execution, not source-only)

The previous two sessions wrote complete code and complete logs but **never
executed a single test** — a repo-wide broken migration made `migrate:fresh`
fail, so no session reached a database. `migrate:fresh` works now. This session
ran the authored regressions on a private database
(`ogami_test_coa`) and recorded actual output.

### F-006 — close-versus-post interleaving: VERIFIED, and the harness was dishonest

The four two-connection tests were **passing their assertions all along** (16
assertions), then failing in `tearDown()`. The teardown was hand-deleting the
committed fixtures, which collides with two append-only database guarantees:

```
delete from "users" where "id" in (1)
  -> UPDATE ONLY "public"."audit_logs" SET "user_id" = NULL WHERE $1 = "user_id"
  -> SQLSTATE[P0001] Audit logs are immutable.
     (PL/pgSQL function prevent_audit_log_modification() line 3 at RAISE)

delete from "journal_entry_lines" where "journal_entry_id" in (1)
  -> SQLSTATE[P0001] Posted journal entry lines are immutable.
     (PL/pgSQL function prevent_posted_journal_line_mutation() line 19 at RAISE)
```

**Neither trigger was touched, loosened, or given an exception, and neither
needed to be.** The hard-delete was pure test scaffolding — see the analysis
section below. Fixes, all inside the test file:

- **Teardown no longer hand-deletes audited rows.**
  - Before: `api/tests/Feature/Accounting/AccountingPeriodPostingConcurrencyTest.php:52-59,327-342`
    ran `cleanupFixtures()` from `tearDown()`, deleting `journal_entry_lines`,
    `journal_entries`, `accounting_periods` and finally
    `DB::table('users')->whereIn('id', $this->fixtureUserIds)->delete()`.
  - After: `:53-63` replaces it with
    `tearDownAfterClass()` setting `RefreshDatabaseState::$migrated = false`, so
    the next test class rebuilds the schema it dirtied. The reset happens at the
    schema level instead of by chipping at the audit triggers. All
    `$fixtureJournalIds` / `$fixtureUserIds` / `$fixturePeriods` bookkeeping and
    `cleanupFixtures()` are gone.
  - Safe intra-class because each test confines itself to one distinct month of
    2031 and its own factory user; both seeders are idempotent (proven — tests
    2-4 previously re-seeded over committed state and still reached their
    bodies).

- **`MassAssignmentException` — a separate, independent fixture bug.**
  - Before: `:167-171` called
    `$period->update(['reopened_at' => …, 'reopened_by' => …, 'reopen_reason' => …])`.
    `AccountingPeriod::$fillable` is `['year','month','status']` and the model
    docblock at `api/app/Modules/Accounting/Models/AccountingPeriod.php:23-24`
    states these are "Service-managed columns (never mass-assigned from
    controllers)". The production design is correct; the fixture was wrong.
  - After: `api/tests/Feature/Accounting/AccountingPeriodPostingConcurrencyTest.php:169-179`
    uses `forceFill([...])->save()`, per the repo convention. `$fillable` was
    **not** widened for a test.

- **Removed a lock key the harness guessed at.**
  - Before: `:301-311` `latestFixtureMonth()` read the last entry of the cleanup
    bookkeeping array to build the advisory-lock key, and fell back to a
    hardcoded `return 9;` for the row-less test. The key the parent locked was
    therefore a side effect of cleanup metadata — if it disagreed with the
    service's key the test would pass while proving nothing.
  - After: `:246-268` `runBlockedWorker(int $month, …)` takes the month
    explicitly and builds the key with the same
    `sprintf('accounting-period:%04d-%02d', …)` format the service uses at
    `api/app/Modules/Accounting/Services/AccountingPeriodService.php:282-285`.
    Callers pass `8`, `9`, `10`, `11`.

### Verification — actual output

`docker compose exec -T -e DB_DATABASE=ogami_test_coa api php artisan test --filter='AccountingPeriodPostingConcurrencyTest'`

Before this session's test-side fixes:

```
  Tests:    4 failed (16 assertions)
  Duration: 42.22s
```

After:

```
   PASS  Tests\Feature\Accounting\AccountingPeriodPostingConcurrencyTest
  ✓ manual post waits for close on an existing period                   11.49s
  ✓ system post waits for close on a rowless period                     10.77s
  ✓ post can commit before a later close authority change               11.11s
  ✓ scheduler rechecks a period after waiting for the month lock        10.97s

  Tests:    4 passed (22 assertions)
  Duration: 45.01s
```

### Can the tests be honest without touching either trigger? Yes — and the
### trigger-vs-deletability question does not block them

- **Does production ever hard-delete a user? No.** `users` is soft-deleting;
  the only hard `delete from users` in the repository was this test's teardown.
  See the analysis appended at the end of this log for the evidence.
- **Was the delete load-bearing for the test? No.** It was incidental cleanup,
  needed only because the harness commits the `RefreshDatabase` transaction so a
  forked child on its own connection can see the fixtures. Committing is
  essential to the test design; hand-deleting afterwards was not.
- **Note for the human:** the underlying tension is real and untouched — a user
  with audit rows cannot be hard-deleted in production, because
  `audit_logs.user_id` is `ON DELETE SET NULL` and `audit_logs_prevent_update`
  forbids the resulting UPDATE. That is a design question, deliberately left
  open. It is written up with evidence at the end of this log. No trigger, no
  function, and no FK was modified.

### F-002 — duplicate-period recovery: VERIFIED (no code change needed)

The previous session's rewrite at
`api/app/Modules/Accounting/Services/AccountingPeriodService.php:52-118` is
correct: the `23505` catch now sits **outside** `closeInTransaction()`, so the
aborted transaction has rolled back before recovery re-acquires the advisory
lock, re-reads under `lockForUpdate()`, and re-closes the winner in a fresh
transaction. Executed for the first time:

```
   PASS  Tests\Feature\Accounting\AccountingPeriodDuplicateRecoveryTest
  ✓ close recovers when a non cooperating writer wins the insert        12.59s

  Tests:    1 passed (3 assertions)
```

### F-004 — split COA permission UI: VERIFIED (no code change needed)

```
   PASS  Tests\Feature\Accounting\ChartOfAccountsAuthorizationTest
  ✓ view only can read but cannot change metadata or status             10.70s
  ✓ manage only can edit metadata but not change status                  0.88s
  ✓ status only can deactivate and activate without metadata access      0.97s
  ✓ full coa access can edit and change status                           0.83s
```

Run in the **same process** and after the concurrency class, which also proves
the `tearDownAfterClass()` reset above works: this class's first test paid
10.70s for a `migrate:fresh` and the rest ran in <1s, and it saw none of the
concurrency class's committed rows.

### F-008 — period pagination type narrowing: VERIFIED

`spa/src/pages/accounting/periods.tsx:73-75,181-187` captures the response and
renders `DataTablePagination` only when `periodsMeta` exists. `npx tsc --noEmit`
(full SPA, run to completion — the previous session only ever timed it out) now
reports **zero** errors under `spa/src/pages/accounting/`. The only two
remaining project-wide errors are out of scope:

```
src/pages/assets/detail.tsx(6,20): error TS2307: Cannot find module 'qrcode' or its corresponding type declarations.
src/pages/assets/detail.tsx(67,14): error TS7006: Parameter 'dataUrl' implicitly has an 'any' type.
```

(The Assets module is missing the `qrcode` dependency. The HR and CRM errors the
2026-08-25 report listed have been fixed by other sessions since.)

### F-001 — configured GL roles made type-safe (in-scope half) — FIXED

The gap was real. `PostingAccountResolver::configuredIdByCode()` accepts
expected types but they are optional, and the three Accounting writers disagreed
about whether to pass them — so an **active account of the wrong class** could
receive a specifically-AP or specifically-VAT posting and produce a balanced
journal with incorrect financial classification.

- **One typed map, owned by Accounting.**
  - Before: `api/app/Modules/Accounting/Services/AccountingAccountPolicyService.php`
    returned only code strings, and the code→type rules were duplicated across
    call sites: `CreditNoteService.php:380-393` kept a private `match`,
    `InvoiceService.php:240-241,421,791` passed literal types at each call site,
    and `BillService.php:1028-1031` passed **none at all** for both
    `ap()` and `vatInput()`.
  - After: `api/app/Modules/Accounting/Services/AccountingAccountPolicyService.php:38-72`
    adds `typeFor(string $code): ?AccountType` (the one map: AR→Asset,
    AP→Liability, VAT Output→Liability, VAT Input→Asset, Discount→Revenue) and
    `controlAccountId(string $code): int`, which resolves and asserts the
    classification at the same boundary. Every type in the map is evidenced by
    an existing assertion in the codebase, not invented — see
    `InvoiceService.php:791` for discount→Revenue and the seeded COA at
    `api/database/seeders/ChartOfAccountsSeeder.php:47,53,58,64,74`.
  - `typeFor()` returns `null` for a code this policy does not own, so an
    operator-chosen expense line or a per-product revenue account is not forced
    through a rule never written for it.

- **The actual bug — `BillService` resolved AP and VAT Input untyped.**
  - Before: `api/app/Modules/Accounting/Services/BillService.php:1030`
    `return $this->postingAccounts->configuredIdByCode($code);` — no type, for
    codes reached from `:612` and `:970-971` (AP and VAT Input).
  - After: `:1028-1031` `return $this->accounts->controlAccountId($code);`

- **`CreditNoteService` — duplicate map removed.**
  - Before: `api/app/Modules/Accounting/Services/CreditNoteService.php:380-393`
    a private `match` over the four control codes, falling back to the untyped
    `idByCode()`.
  - After: `:380-383` delegates to `controlAccountId()`.

- **`InvoiceService` — third copy of the rules removed.**
  - Before: `configuredAccountId(string $code, AccountType $type)` at `:645-648`
    with the type supplied literally at `:240-241,421,791`.
  - After: `:645-653` derives the type from the map;
    `:240-241,421,796` pass only the code.

`defaultExpenseAccountId()` at `BillService.php:1013-1026` was deliberately left
alone: it already asserts `AccountType::Expense`, and its second
`lockForUpdate()` read is load-bearing (it prevents a deactivation racing the
post), so folding it into the map would have removed a lock.

- **Negative coverage added.**
  `api/tests/Feature/Accounting/ConfiguredControlAccountTypeTest.php` — 8 tests:
  the map's declared type per role, a non-control code having no required type,
  correctly-typed resolution, an **active-but-wrongly-typed** AP code refused, the
  same for VAT Input, an inactive control account still refused, and the gate
  that matters — a wrongly-typed AP code commits **no bill and no journal**.
  It also pins one property worth knowing: the map is keyed by code, so
  configuring two roles onto one account collapses them onto the first matching
  rule rather than failing.

```
   PASS  Tests\Feature\Accounting\ConfiguredControlAccountTypeTest
  ✓ each control account role declares its required type                10.54s
  ✓ a code this policy does not own has no required type                 0.76s
  ✓ correctly typed control accounts resolve                             0.84s
  ✓ an active but wrongly typed ap code is refused                       0.81s
  ✓ an active but wrongly typed vat input code is refused                0.81s
  ✓ two roles sharing one code resolve against the first rule            0.71s
  ✓ an inactive control account is still refused                         0.75s
  ✓ a wrongly typed ap code commits no bill and no journal               0.87s

  Tests:    8 passed (20 assertions)
```

Regressions for every service touched, each run as its own class:

```
   PASS  Tests\Feature\Accounting\BillServiceTest             6 passed (32 assertions)
   PASS  Tests\Feature\Accounting\CreditNoteTest              8 passed (30 assertions)
   PASS  Tests\Feature\Accounting\InvoiceCollectionTest       9 passed (61 assertions)
   PASS  Tests\Feature\Accounting\InvoiceBirFieldsTest        5 passed (16 assertions)
   PASS  Tests\Feature\Accounting\AutoCreateBillOnGrnAcceptedTest  9 passed (36 assertions)
   PASS  Tests\Feature\Accounting\ChartOfAccountsHardeningTest      7 passed (15 assertions)
   PASS  Tests\Feature\Accounting\AccountingPeriodCloseRegressionTest 5 passed (13 assertions)
```

**F-001's residual, out-of-scope half is NOT fixed.** The remaining untyped
configured-code writers live outside this module and must not be edited from an
M025 session:

- `api/app/Modules/Payroll/Services/PayrollGlPostingService.php:174-200`
- `api/app/Modules/Inventory/Services/GrnGlPostingService.php:113-125`
- `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1320`
  (`->where('code', $this->accountPolicies->revenue())->value('id')` — a raw
  query with no active check and no type check)

They can now adopt `AccountingAccountPolicyService::controlAccountId()` without
redefining any types, which is the coordination the action plan asked for. The
payroll/inventory/asset/HR control-account codes are settings owned by those
modules, so their rows belong in the map only once their owners sign off.

### F-007 — acceptance coverage: SPA COA role matrix added; suite now executes

The four SPA COA permission combinations had no regression at all. Added:

- `spa/src/pages/accounting/coa/index.tsx:188-193` — `TreeRow` is now exported.
  It already took `canManage` and `canChangeStatus` as props rather than reading
  the auth store, so the role matrix is testable without an authenticated
  shell; the export is the only change, and the default export is untouched.
- `spa/src/pages/accounting/coa/index.permissions.test.tsx` — 6 tests covering
  view-only (neither action, ledger link still present), manage-only (edit link,
  no status control), deactivate-only (status control, no edit link), full
  access (both), an inactive account offering **Activate** plus its `inactive`
  chip, and that the status control routes through the confirmation handler
  rather than mutating directly.

```
 RUN  v4.1.10 /home/kwat0g/Desktop/kwatog/spa

 Test Files  1 passed (1)
      Tests  6 passed (6)
   Duration  2.50s
```

Running vitest needed a workaround worth recording, because it will bite the
next session: the host's `spa/node_modules` is **root-owned** (uid 0) while the
agent runs as uid 1000, so Vite cannot write its bundled-config temp file into
`spa/node_modules/.vite-temp` and dies with `EACCES` before any test runs. The
canonical runner (`docker compose exec spa npm run test -- --run`, Makefile:63)
avoids this because the `spa` service mounts the named volume
`spa_node_modules`. With the `spa` service down, the fix is to put a config in a
directory you own that contains its own `node_modules`, since Vite picks the temp
location with `findNearestNodeModules(dirname(configFile))`
(`spa/node_modules/vite/dist/node/chunks/config.js:35985-35993`); module
resolution still walks up to the real `spa/node_modules`, which is readable.

Remaining F-007 gap: `AccountService::tree()` serialization has no dedicated
assertion (the cycle-safety path is covered at
`api/tests/Feature/Accounting/ChartOfAccountsHardeningTest.php`, the response
shape is not), and importer metadata coverage is blocked with F-003 below.

### Verified, whole-module: every M025 test class, each run alone

```
   PASS  AccountingPeriodPostingConcurrencyTest        4 passed (22 assertions)
   PASS  AccountingPeriodDuplicateRecoveryTest         1 passed (3 assertions)
   PASS  AccountingPeriodCloseRegressionTest           5 passed (13 assertions)
   PASS  AccountingPeriodAuthorizationTest             1 passed (5 assertions)
   PASS  AccountingPeriodTest                          7 passed (13 assertions)
   PASS  ChartOfAccountsAuthorizationTest              4 passed (16 assertions)
   PASS  ChartOfAccountsHardeningTest                  7 passed (15 assertions)
   PASS  ConfiguredControlAccountTypeTest              8 passed (20 assertions)
   PASS  BillServiceTest                               6 passed (32 assertions)
   PASS  CreditNoteTest                                8 passed (30 assertions)
   PASS  InvoiceCollectionTest                         9 passed (61 assertions)
   PASS  InvoiceBirFieldsTest                          5 passed (16 assertions)
   PASS  AutoCreateBillOnGrnAcceptedTest               9 passed (36 assertions)
   PASS  spa .../coa/index.permissions.test.tsx        6 passed
```

`npx eslint` clean on the three changed/added SPA files. `npx tsc --noEmit` clean
for `spa/src/pages/accounting/**`.

**One pre-existing failure found outside this module, NOT fixed and NOT caused
by this session:**

```
   FAILED  Tests\Feature\Accounting\AccountsPayableHardeningTest > supplier…
  Failed asserting that an array does not have the key 'payments'.
  at tests/Feature/Accounting/AccountsPayableHardeningTest.php:229
```

It is independent of everything here: line 225 calls
`(new SupplierBillResource($bill))->toArray(...)`, and `toArray()` does **not**
strip `MissingValue`, so the unloaded `whenLoaded('payments')` key at
`api/app/Modules/Accounting/Resources/SupplierBillResource.php:42` is still
present in the array. Line 232 of the same test does it correctly, with
`->resolve(...)`. This belongs to the AP / supplier-portal surface, not
chart-of-accounts-periods, so it is reported rather than touched. Whether the
defect is the assertion or the resource's supplier-facing contract needs the
owning module's judgement.

## Deferred, with the reason — 2026-08-27

### F-003 — importer metadata and status authority: DEFERRED (needs a human decision + out of module)

Both halves are blocked, for different reasons:

1. **The authority question is explicitly the human's.** Import routes authorize
   only `admin.import.manage`
   (`api/app/Modules/Admin/routes.php:191-203`), while COA status is otherwise
   gated by the separate `accounting.coa.deactivate`
   (`api/app/Modules/Accounting/routes.php:28-31`). The importer accepts
   `is_active` (`api/app/Modules/Accounting/Imports/AccountImporter.php:14-17,64-75`),
   so a migration-capability holder can create an inactive account without the
   status grant. Current source supports both readings and no policy is guessed.
2. **The metadata fix lives outside this module.** The advertised COA columns
   come from `api/app/Common/Services/Import/MasterDataImportService.php:57-74`,
   shared import infrastructure. Editing it from an M025 session would violate
   the module boundary, and the correct column list depends on answer (1) anyway
   — if import is not a trusted status authority, `is_active` should not be
   advertised at all.

### F-005 — the period `Open` filter: DEFERRED (needs a product decision)

This is question 2 of the audit report's own "Questions requiring an explicit
decision": are absent period rows the complete model of every open month, or
should the surface expose a finite set of open months? The service treats a
missing row as open
(`api/app/Modules/Accounting/Services/AccountingPeriodService.php:186-187`) and
`close()` inserts directly as `closed`, so the SPA's `Open` filter normally
returns nothing, and after one period exists the page offers no close action for
any other absent month — the synthetic current-month helper at
`spa/src/pages/accounting/implicitOpenCurrentPeriod.ts:3-18` only fires when the
whole list is empty.

The two answers produce opposite work — remove a filter and add a month picker,
versus change the period-list contract to materialise open months — and the
choice is a product decision about how accountants should navigate historical
closes. It is not an implementation detail, so it was left for the human. The
filter is misleading but not incorrect, and nothing posts wrongly because of it.

## For the human — the audit-trigger vs user-deletability question

**Nothing in this session depended on the answer, and nothing was changed.**
Recorded because the briefing asked for options and evidence.

### The facts

- `audit_logs.user_id` is `ON DELETE SET NULL`
  (`api/database/migrations/0008_create_audit_logs_table.php:15`).
- `audit_logs` carries `audit_logs_prevent_update` and
  `audit_logs_prevent_delete`, both `BEFORE ... FOR EACH ROW`, raising
  unconditionally
  (`api/database/migrations/2026_06_09_100001_add_audit_log_immutability_trigger.php:12-27`).
- Therefore deleting a user with audit rows fires the FK's `SET NULL` UPDATE,
  which the trigger refuses: **a user with audit rows cannot be hard-deleted.**

### Why this is narrower than it first looks

The application layer already agrees with the triggers, deliberately:

- `User` uses `SoftDeletes` (`api/app/Modules/Auth/Models/User.php:18`), so
  CLAUDE.md's "users are soft-deletable" is satisfied — soft deletes are an
  UPDATE on `users`, which no trigger touches.
- `AuditLog` throws from `save()` on an existing row, from `update()`, and from
  `forceDelete()` (`api/app/Common/Models/AuditLog.php:37-54`). The triggers are
  the backstop for raw queries, not the only guard.
- **No production code path hard-deletes a user.** There is no `forceDelete()`
  on `User` anywhere in `api/app/`, and every `DB::table('users')` use is a read
  (`exists()`/`count()`) — `BackupService.php:933,1592`,
  `AdminDashboardService.php:152-164`, `VerifyDemoReadiness.php:84`.

So the triggers do not block any operation the product performs today. The only
thing that ever hard-deleted an audited user was this test's teardown, and it
did not need to.

### The open question, and options

The question is only about a future requirement for **true erasure** (a data
subject demanding deletion, or purging a mistakenly-created account):

- **Option A — leave it exactly as is.** Hard deletion of an audited user is
  impossible; erasure is done by anonymising the `users` row (soft delete plus
  scrubbing PII columns) while `audit_logs.user_id` keeps pointing at the
  now-anonymous row. Preserves the audit guarantee completely. This is what the
  code already implies, and it needs no change.
- **Option B — make the FK `ON DELETE RESTRICT`.** Turns a confusing
  "Audit logs are immutable" error into an honest "this user has audit history".
  Same practical outcome, better diagnostics. Still a schema change to a
  financial-audit table, and it changes an error class some caller may depend on.
- **Option C — allow the FK's `SET NULL` specifically.** Requires the trigger to
  distinguish an FK-driven update from an application one. This trades away
  exactly the guarantee the trigger exists for: an attacker or a bug with any
  path to deleting a user could then blank the actor on their own audit trail.
  **Not recommended.**

Recommendation to consider: A (documenting it), or B if the error message matters.
Either way the four concurrency tests are unaffected — they are green under the
triggers as they stand.

## 2026-08-27 closing summary

| item | state |
|---|---|
| F-001 configured GL roles type-safe | **Fixed + verified for the Accounting writers.** Deferred: Payroll / Inventory / ReturnManagement writers are outside this module. |
| F-002 duplicate-period recovery | **Verified** (executed for the first time; no code change needed) |
| F-003 CSV metadata + status authority | **Deferred** — needs a human authority decision, and the metadata lives in shared import infrastructure |
| F-004 split COA permission UI | **Verified**, plus a new 6-test SPA role matrix |
| F-005 open-period UI contract | **Deferred** — needs a product decision on whether open months are materialised |
| F-006 close-vs-post interleaving | **Fixed + verified.** The tests were made honest without touching either immutability trigger. |
| F-007 acceptance suite | **Partial.** Added the configured-type negative suite and the SPA role matrix. Pending: `tree()` response-shape assertion, and importer metadata coverage (blocked with F-003). |
| F-008 period pagination narrowing | **Verified** — full `tsc --noEmit` run to completion, clean for `pages/accounting/**` |

Released as `🔁 Needs Re-audit` because F-003 and F-005 await human decisions and
F-001 / F-007 have residual out-of-module work. Nothing listed as verified above
is a source-only claim: every line has actual test output in this log.




