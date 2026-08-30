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

## 2026-08-30 re-audit session (M023, reclaimed orphan lock)

Prior state confirmed by probe, not by reading this log: the four earlier
sessions left **no committed production fixes**. `167de85e` swept exactly one
M023 file (`SeparationService.php`, +17/-6 — the canonical POST initiation
wiring); every other module file was untouched since `411e42ff`/`79a5184c`.
All 13 prior findings still reproduced. The earlier audits never obtained a
working test database ("34 failures and 0 assertions"), so none of the 13 had
ever been measured against real PostgreSQL rows. This session measured all of
them plus five new defects on an isolated database (`ogami_test_sep`).

Six contained fixes were applied. Everything that would change what a leaver is
paid was deferred to a human decision — see `action-plan.md`.

Baseline: `SeparationContractRegressionTest` = **10 failed / 2 passed** against
unmodified `HEAD` source (the 2 passing are deliberate pass-either-way locks:
"separation on the hire date is allowed" and "a future-dated separation is still
allowed", which guard against an over-aggressive date rule). After the fixes:
**12 passed / 30 assertions**, stable over two consecutive runs.

### FIX-1 — a separation dated before the hire date is refused (P0, money)

`api/app/Modules/HR/Services/SeparationService.php:85-104`

- Before: `initiate()` checked only "already separated". `POST .../separation`
  with `separation_date=2020-06-15` on an employee hired `2024-01-01` returned
  **201 ACCEPTED**. `PayrollCalculatorService::employedDayFraction()` then
  measured **`0.0000`** for a normal 2026-05-16..31 cutoff, because it takes the
  EARLIEST `clearances.separation_date` and the employment window collapses.
  Every later cutoff pays zero basic pay, and with no cancel/correct transition
  the mistake cannot be walked back through the API.
- After: `BusinessRuleException` — "Separation date … precedes the hire date …".
  A separation ON the hire date, and a future-dated separation (notice periods
  are normal), both still succeed.

### FIX-2 — duplicate / blank checklist entries are refused (P0, blocks separation)

`api/app/Modules/HR/Services/SeparationService.php:227-286`

- Before: both readers validated only key *presence*. Measured with a duplicated
  `item_key`: after signing `dup` twice the checklist read
  `["dup:cleared","dup:pending","blank_dept:pending"]` and the status stayed
  `in_progress`. `signItem()` matches the FIRST row and treats an already-cleared
  match as a replayed no-op, so the later duplicate is **permanently unsignable**;
  completion requires every row cleared, so the clearance can never be completed,
  never finalized, and the employee can never be separated or paid — with no
  cancel path to escape. Blank department/label were accepted too.
- After: one `validateChecklist()` shared by `configuredChecklist()` and the
  public `defaultChecklist()` helper; rejects duplicate `item_key` and any blank
  or non-string `department`/`item_key`/`label`.

### FIX-3 — initiation remarks round-trip (F008)

`api/app/Modules/HR/Services/SeparationService.php:135`

- Before: measured `clearance.remarks = NULL` and absent from the 201 response,
  although the request validates `remarks` and the model/resource expose it. The
  value was used only as an employment-history remark.
- After: persisted on the canonical clearance.

### FIX-4 — employment history honours its array cast (F007)

`api/app/Modules/HR/Services/SeparationService.php:146-153` and `:376-382`

- Before: both writes passed `json_encode(...)` into a column cast to `array`.
  Measured read-back: `to_value` = **`string`** while `from_value` = `array`.
  `EmploymentHistoryResource` masks assuming array shape.
- After: arrays assigned directly; `to_value` reads back as an array.
- NOT fixed: rows already written double-encoded. A backfill is a separate
  migration — see action-plan item 5.

### FIX-5 — list contract: search, page bounds, HashID filter (F010)

`api/app/Modules/HR/Services/SeparationService.php:47-90`

- Before: the SPA's Search box sent `search` (`index.tsx:89`) to an endpoint that
  ignored it — measured 2 rows returned for a term matching 1. `per_page` had no
  lower bound: `0`, `-5` and `abc` all returned 200, and `paginate(0)` returns
  every row. `employee_id` was passed raw into a WHERE against a bigint column,
  so the SPA's own HashID filter threw
  **`SQLSTATE[22P02]: invalid input syntax for type bigint: "dGypLxpvAg"`**.
- After: `search` matches `clearance_no`, employee number, first/last and full
  name; `per_page` clamped to 1..100; `employee_id` decoded via `HashIdFilter`
  and an unresolvable value returns an empty set instead of throwing.

### FIX-6 — no raw signer PK in the payload (F011)

`api/app/Modules/HR/Resources/ClearanceResource.php:45,71-107`

- Before: `clearance_items` was emitted verbatim, including the signer's raw
  integer user PK. Measured: `"signed_by":30` present in the response while every
  other identifier in the same payload is hashed.
- After: `signed_by` is the signer's HashID plus a `signed_by_name`, resolved in
  one batched query. Safe for the SPA, which renders only `signed_at`.

### Verification

- `php -l`: clean on all three files.
- `phpstan analyse` (changed files, `--memory-limit=1G`): **[OK] No errors**.
- `pint --test`: both changed source files fail with fixer lists **byte-identical
  to their `HEAD` versions** (`SeparationService.php` 11 fixers,
  `ClearanceResource.php` 2 fixers), i.e. fully pre-existing — zero new
  violations. The new test file passes Pint.
- Pre-existing module suites, unchanged and still green: `ClearanceLoanBlockTest`,
  `FinalPayTest`, `FinalPayCentPrecisionTest`, `FinalPayComputeAttributionTest`,
  `FinalPayMoneyFindingsRegressionTest`, `SeparationLifecycleConcurrencyTest` —
  **34 passed / 100 assertions**.
- `ClearanceLoanBlockTest` was deliberately NOT modified: this session did not
  change the loan-block behaviour it asserts. R-002 remains open by design.

### Deferred

Every money-policy, RBAC, lifecycle and GL item is deferred; all require a human
decision about what a leaver is paid or who may approve it. Five newly measured
P0/P1 defects (13th-month double pay, last-salary double pay, leave conversion
uncapped and never debited, the loan deadlock, and the unfinalizable zero-value
final pay) are documented with measurements in `audit-report.md` and options in
`action-plan.md`. None was implemented.
