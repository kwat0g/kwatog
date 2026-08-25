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
