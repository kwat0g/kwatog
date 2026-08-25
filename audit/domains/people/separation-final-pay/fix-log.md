# M023 — Separation and final pay fix log

Audit date: 2026-08-24

## This session

No production-code fixes were applied. The audit produced `audit-report.md` and `action-plan.md`, ran the focused verification suite, and reproduced the employment-history cast issue with a read-only model check.

## Verification recorded

- Backend focused suite: 34 tests passed, 100 assertions.
- SPA typecheck, lint, token audit, and static RBAC audit passed.
- Route listing confirmed both the legacy direct-separation endpoint and canonical separation/clearance endpoints.
- Browser role-permission verification remains pending because no local login server was running.

## Deferred work

The fixes are deferred to separate implementation work because the primary issues require lifecycle, role/SoD, financial-policy, migration, and recovery decisions. The module was released as `📋 Plan Ready`.

## 2026-08-25 re-audit session

No production-code fixes were applied by this session. The prior report was
re-audited because the shared worktree contained uncommitted M023 changes.

### Current evidence

- The current canonical employee-detail path uses `POST /hr/employees/{employee}/separation`; the old direct-separation route is absent.
- M023 PHP syntax checks passed for the separation service, final-pay service, controller, model, resource, listeners, and employee state machine.
- Targeted SPA ESLint passed for the separation pages/APIs and employee detail; token discipline passed for 771 files; the static RBAC audit found zero referenced-but-unseeded permissions; clearance route listing found six routes.
- The focused backend suite could not produce product assertions because the shared test database/migration harness was concurrently resetting and hit an unrelated malformed migration import at `api/database/migrations/2026_08_25_140000_add_artifact_key_to_bank_file_records.php:5`, followed by missing/duplicate schema errors.
- Full SPA typecheck was interrupted after concurrent TypeScript processes produced no result; no pass claim is made.

### Deferred work

All 13 findings in the refreshed `audit-report.md` remain pending. The majority
are large/separate-recommended lifecycle, RBAC, financial-policy, migration,
notification, and recovery changes. The small API contracts are intentionally
ordered after the foundational decisions. This session left the worktree's
pre-existing source edits untouched.

## 2026-08-25 resumed Plan Ready session

No production-code fixes were applied. The existing plan was resumed as
required, but implementation stopped at action 1 because the repository does
not contain an authoritative contract for the decisions that control every
downstream fix:

- Checklist ownership is ambiguous: the seeded checklist names Production,
  Warehouse, Maintenance, Finance, HR, and IT at
  `api/database/migrations/0312_seed_separation_clearance_checklist_setting.php:12-25`,
  while the reserved workflow maps only `department_head`, `warehouse_staff`,
  `maintenance_tech`, `finance_officer`, and `hr_officer` at
  `api/database/seeders/WorkflowSeeder.php:141-149`.
- Maker/checker authority is ambiguous: `hr_officer` receives the complete
  `hr_separation` module at `api/database/seeders/RolePermissionSeeder.php:361-365`
  and `:459-475`, while Finance is not assigned the separation action set in
  the finance permissions at `:499-532`.
- Cancellation/restart/blocked-item transitions and the compensating employee
  status/account behavior are not specified; the current route surface only
  exposes sign, compute, and finalize at `api/app/Modules/HR/routes.php:266-280`.
- Deduction policy is contradictory: the process flow says final pay subtracts
  outstanding loans at `docs/PROCESS-FLOWS.md:1186-1192`, while the current
  finalization path rejects active loan balances.

All ordered action-plan items remain pending. A human decision is required on
the ownership matrix, maker/checker roles, lifecycle/recovery transitions, and
loan/advance/property deduction outcome before it is safe to change the state
machine, RBAC, or financial posting code.

## 2026-08-25 module-audit session

No production-code fixes were applied. The existing report and plan were
resumed as required for the `🔁 Needs Re-audit` status; source history showed no
post-report commit, and the same action-1 contract decisions remain unresolved.
All plan items remain pending for the ownership matrix, maker/checker roles,
lifecycle/recovery transitions, and loan/advance/property deduction outcome.
