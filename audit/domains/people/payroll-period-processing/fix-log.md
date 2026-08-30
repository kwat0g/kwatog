# M021 — Payroll period processing fix log

Audit date: 2026-08-24  
Module status: 🔁 Needs Re-audit  
Implementation status: fixes applied; see the 2026-08-25 implementation section

## Audit actions

- Refreshed the registry, confirmed M021 was the next unlocked eligible module, and claimed it atomically with the audit script.
- Read the Payroll routes, requests, controllers, services, jobs, listeners, models, enums, migrations, permission seeders, scheduled commands, frontend pages/components/API/types, focused tests, load fixture, design-system guidance, deployment notes, and restore drill.
- Ran the clean Docker-backed payroll and authorization suite: 264 tests passed with 771 assertions.
- Ran SPA typecheck successfully. The payroll Playwright run was attempted but Vite could not write its cache because pre-existing spa/node_modules and spa/node_modules/.vite-temp are root-owned; no ownership change or cleanup was performed.
- Preserved all unrelated pre-existing worktree changes. During the audit portion, no M021 production source files were changed before the plan was handed off.

## Code changes

During the audit portion, only the M021 audit artifacts were authored:

- inventory.md
- audit-report.md
- action-plan.md
- fix-log.md

## Why fixes were initially deferred

The initial audit session deferred implementation because the findings are dominated by financial-state integrity, concurrent adjustment application, disbursement evidence policy, private artifact lifecycle, statutory-table activation, authorization separation, and population-scale transaction behavior. The resumed implementation session below handled the in-scope fixes and records the remaining policy/performance decisions.

## Release note

The resumed implementation session is ready to release M021 as 🔁 Needs Re-audit. The release step must remove the claim lock, regenerate the registry, and identify the next eligible module.

## 2026-08-25 implementation session

The existing Plan Ready work was resumed after an atomic claim. The audit report and action plan were not rewritten. Changes below are limited to the payroll-period-processing module, its payroll-specific migrations/permission catalog, and the payroll SPA surfaces.

### M021-F01 — de minimis dependency failure

- Before: api/app/Modules/Payroll/Services/PayrollCalculatorService.php:778-796 treated a missing/failed de minimis lookup as 0.00.
- After: the calculator injects DeMinimisService at :62-70, propagates a BusinessRuleException at :778-795, and lets ProcessPayrollJob::handle() persist the visible error row at api/app/Modules/Payroll/Jobs/ProcessPayrollJob.php:112-170; existing approval guards reject errored rows.
- Verification: Payroll feature suite exercised the calculator and de minimis paths. A dedicated dependency-failure injection test remains recommended.

### M021-F02 — approved adjustment application

- Before: api/app/Modules/Payroll/Services/PayrollCalculatorService.php:971-976 read all approved, unapplied adjustments without a row lock.
- After: :971-980 applies deterministic id ordering plus lockForUpdate() inside the per-employee transaction, so concurrent workers cannot both claim the same row.
- Verification: the broader Payroll suite passed all adjustment/recompute coverage; two-connection race coverage remains pending.

### M021-F03 — disbursement evidence reconciliation

- Before: api/app/Modules/Payroll/Services/PayrollPeriodService.php:1010-1017 closed a finalized period when any proof row existed.
- After: api/app/Modules/Payroll/Services/DisbursementEvidenceService.php:25-132 computes payable net with decimal strings, rejects payroll errors/zero or excessive amounts, requires every active proof file, and requires exact total equality before markDisbursed(); upload status records partially_disbursed while evidence is incomplete. api/app/Modules/Payroll/Controllers/DisbursementProofController.php:53-117 validates and reconciles required positive amounts transactionally.
- Verification: PayrollPeriodEventsTest.php now covers exact evidence and mismatch rejection; the focused event suite passed 9/9. Finance policy on whether generated bank artifacts are mandatory remains a decision for re-audit.

### M021-F04 — proof archive/restore

- Before: api/app/Modules/Payroll/Controllers/DisbursementProofController.php:164-211 used an enum-unsafe terminal check, deleted the private file on archive, and could not bind soft-deleted proofs for restore.
- After: :164-212 locks the period, blocks archive/restore after Disbursed or Voided, retains the private object, checks it exists before restore, reconciles restored amounts, and the route opts into withTrashed() at api/app/Modules/Payroll/routes.php:101-105. The SPA only offers upload on finalized periods at spa/src/pages/payroll/periods/detail.tsx:400-402.
- Verification: proof lifecycle paths are covered by the Payroll suite; physical-file retention and restore authorization remain policy-sensitive and should receive dedicated controller tests.

### M021-F05 — bank artifact publication

- Before: api/app/Modules/Payroll/Services/BankFileService.php generated a random path and a new audit row on every download.
- After: api/app/Modules/Payroll/Services/BankFileService.php:97-240,276-323 uses a period/format artifact key, deterministic private path, temp-write/publish cleanup, idempotent current-record lookup, and read-only streaming. api/database/migrations/2026_08_25_140000_add_artifact_key_to_bank_file_records.php:12-44 backfills the newest historical artifact and adds uniqueness; api/app/Modules/Payroll/Controllers/PayrollPeriodController.php:288-329 and api/app/Modules/Payroll/routes.php:73-77 expose explicit POST generation before GET download. The SPA calls that mutation at spa/src/api/payroll/periods.ts:82-90 and spa/src/pages/payroll/periods/detail.tsx:196-208,613-626.
- Verification: bank integrity and replay coverage passed 17/17 plus auto-generation coverage in the focused suite. Generation still holds the period lock during builder/storage work; that performance/operability part is deferred to F08.

### M021-F06 — statutory table import integrity

- Before: api/app/Modules/Payroll/Services/GovernmentContributionTableImportService.php cast CSV values through floats, skipped invalid rows, and deactivated prior schedules after a partial transaction.
- After: :25-176 stages the complete file, :178-305 parses exact decimal/date strings and rejects reversed, duplicate, or overlapping brackets before one transaction upserts and deactivates; cache invalidation occurs after the transaction. api/app/Modules/Payroll/Services/GovernmentContributionTableService.php:98-171 applies the same active-row overlap guard to CRUD activation/update. api/database/migrations/2026_08_25_141000_add_unique_government_schedule_key.php:11-24 adds database uniqueness.
- Verification: import coverage passed 4/4, including no partial writes on a bad row. Gap/expected-coverage validation and actor/version publication metadata are deferred because the statutory schedule shape and version ownership need an explicit business decision.

### M021-F07 — adjustment permissions

- Before: api/app/Modules/Payroll/routes.php:112-117 and the SPA gated read, create, approve, and reject on .create.
- After: api/database/seeders/RolePermissionSeeder.php:133-136,489-517 defines separate view/create/approve/reject capabilities, routes use the matching middleware, RejectPayrollAdjustmentRequest checks .reject, and the list/sidebar/frontend action gates use .view, .approve, and .reject (spa/src/routes/payrollRoutes.tsx:37-44, spa/src/pages/payroll/adjustments/index.tsx:158-185, spa/src/components/layout/Sidebar.tsx:613-617).
- Verification: payroll authorization coverage passed 5/5 in the isolated focused run.

### M021-F08 — population and lock pressure

- Before: api/app/Modules/Payroll/Jobs/ProcessPayrollJob.php materialized every eligible employee before processing.
- After: api/app/Modules/Payroll/Services/PayrollPeriodService.php:633-657 exposes a query and ProcessPayrollJob.php:103-126 counts then consumes it with lazyById(100), preserving claim fencing and final reconciliation.
- Deferred: BankFileService.php:114-218 still builds the payroll population and performs storage I/O under the period lock. A realistic large-population/memory/lock test and a safe artifact-claim boundary are required before changing that transaction.

### M021-F09 — decimal output boundaries

- Before: payroll summaries, variance money deltas, bank previews/builders, and de minimis aggregates crossed through (float)/number_format.
- After: api/app/Modules/Payroll/Services/PayrollPeriodService.php:91-166,1070-1078, BankFileService.php:73-76,176-191,343-365,418-527, and DeMinimisService.php:105-132,170-235 keep money as decimal strings and use Money/BCMath; bank reconciliation compares the builder total to persisted payroll totals.
- Verification: all bank formats and preview/integrity coverage passed; the broad Payroll suite passed 272/273 tests.

### Verification notes

- PHP syntax checks passed for all changed PHP source, migration, seeder, and test files.
- Isolated Docker database focused runs passed: bank integrity 17 tests/34 assertions; statutory import plus authorization 9/28; bank auto-generation plus disbursement events 12/29.
- The broader isolated Payroll plus payroll-authorization run completed 273 tests with one unrelated existing failure in PayrollMoneyFindingsRegressionTest::test_p02_01_payroll_je_has_actor_and_audit_row (the Accounting journal entry still has null created_by/posted_by; that service was not changed in this M021 pass).
- SPA typecheck remains blocked by the pre-existing JSX parse error in spa/src/pages/inventory/grn/create.tsx:237; no unrelated UI file was changed to mask it.

### Session disposition

M021-F01 through F07 and the decimal portion of F09 were implemented and rechecked. M021-F06 still needs statutory-owner decisions, M021-F08 still needs bank population/lock work, and F03/F04 need policy-specific publication/retention coverage. Release as Needs Re-audit with those pending items called out in the module status.

---

# 2026-08-30 re-audit session

Claim: **RECLAIMED** (orphan lock, 108h, `codex-coordinator-blocker-quarantine`).
Prior session's work was complete and **committed** in `167de85e`; nothing was
uncommitted and `fix-log.md` was already written. Verified by probe — see the
"What the reclaimed session left behind" section of `audit-report.md`.

Baseline before any change: `tests/Feature/Payroll` = **268 passed, 1 failed,
790 assertions** on a private database `ogami_test_payroll`. The single failure
(`PayrollMoneyFindingsRegressionTest:208`, payroll JE `created_by` is null) is a
pre-existing cross-module blocker owned by Accounting and was left untouched.

After this session: **282 passed, 1 failed, 827 assertions** — the same single
pre-existing failure, plus 14 new passing tests.

## Fixes applied

### M021-F12 — an unusable sort direction returned HTTP 500

- Before: `api/app/Modules/Payroll/Services/PayrollPeriodService.php:63`
  `$dir = $filters['direction'] ?? 'desc';` and
  `api/app/Modules/Payroll/Controllers/PayrollController.php:68`
  `$dir = $request->query('direction', 'desc');` — both passed straight to
  `orderBy()`, which throws `InvalidArgumentException` on anything but asc/desc.
- After: `PayrollPeriodService.php:66` and `PayrollController.php:71` normalise to
  a two-value whitelist:
  `strtolower((string) …) === 'asc' ? 'asc' : 'desc'`.
- Verification, before: `GET /api/v1/payroll-periods?sort=period_start&direction=sideways`
  → **500**; `?direction=asc%3B%20drop%20table%20payrolls` → **500**;
  `?direction=DESC--` → **500**. Same three on `/api/v1/payrolls`.
- Verification, after: all **200**, and `direction=asc` / `direction=DESC` still
  order correctly (case-insensitive).

### M021-F13 — the BIR 2316 Alphalist did money arithmetic in floats

- Before: `api/app/Modules/Payroll/Services/BirAlphalistService.php:81-84`
  `round((float) $r->total_gross, 2)` … and
  `'taxable_income' => round(max(0.0, (float) $r->total_gross - (float) $r->total_deductions), 2)`;
  `:112-115` then re-converted with `number_format($row['…'], 2, '.', '')`.
- After: `:85-102` keeps every figure a decimal string —
  `Money::round2()` on each DB sum and `Money::sub()` for `taxable_income`,
  clamped at zero with `Money::lt`; `:130-135` emits those strings verbatim.
  Both `@return`/`@param` docblocks retyped `float` → `string`.
- Verification, before: the new test's `assertIsString($row['total_gross'])` and
  the negative-taxable-income case both **failed** against HEAD.
- Verification, after: the reported figures equal the persisted payroll row
  exactly (`Money::round2($payroll->gross_pay) === $row['total_gross']`),
  `taxable_income === Money::sub(gross, deductions)` to the cent, every figure
  matches `/^-?\d+\.\d{2}$/`, and the CSV carries the same strings with no second
  conversion. The seven pre-existing `BirAlphalistTest` cases still pass.

### M021-F14 — a second copy of the pay-type reconciliation

- Before: `api/app/Modules/Payroll/Services/PayrollCalculatorService.php:726-740`
  `monthlyBasis()` re-derived the rule itself —
  `Money::mul((string) $employee->semi_monthly_rate, '2')` for semi-monthly, the
  `basic_monthly_salary` column otherwise.
- After: `:736-745` delegates to `Employee::monthlyEquivalentSalary()`
  (`api/app/Modules/HR/Models/Employee.php:93-101`), the method CLAUDE.md names as
  the ONE place the two pay types are reconciled, and keeps only the
  payroll-specific "no authoritative figure" messages, which the accessor
  deliberately leaves to its caller by returning null.
- Verification: byte-identical output, proved both ways. `basic_pay ===
  bcdiv(monthlyEquivalentSalary(), '2', 2)` for a `monthly` (₱21,000) and a
  `semi_monthly` (₱9,460/cutoff → ₱18,920 monthly) employee, measured **before**
  the change and again after. Both error messages still name the right pay type.
  All 34 `PayrollCalculatorServiceTest` cases, `PartialEmploymentProrationTest`
  and `MidCycleSalaryProrationTest` pass unchanged.

### M021-F11 (partial) — the detector's failure path no longer hides its own failure

- Before: `api/app/Modules/Payroll/Services/PayrollAnomalyService.php:160-182`
  wrapped the flag write in `catch (\Throwable) → Log::warning` and returned 0, so
  a missing column, a bad FK or a dead connection produced no flag and no error.
- After: `:161-206` catches `QueryException` only, re-throws anything that is not
  a unique-constraint violation, and logs the one benign case (a concurrent
  detector already raised the same `(payroll_id, flag_type)`) at `info`.
  `Illuminate\Database\QueryException` imported at `:12`.
- Scope: this closes the **detector's** swallow only. The caller's swallow
  (`ProcessPayrollJob.php:214-221`) and the finalize gate itself are deliberately
  untouched — see the deferral below.
- Verification: `PayrollAnomalyPolicyTest`, `PartialEmploymentProrationTest` and
  the full `tests/Feature/Payroll` run pass; no new failure.

## Deferred, with the reason

### M021-F10 — loan over-deduction (P0)

Not fixed. It needs (a) a ledger-derived clamp in `applyLoanDeductions()` and
(b) removal of the `reconcileAggregates($loan, $asOf)` cut — and (b) redefines
when a loan's `end_date` is set, which is `LoanService`'s semantics and outside
this module. Both change an amount. Handed off as action-plan item 1 with the
measured table.

### M021-F11 — anomaly gate fail-closed (P1)

The obvious fix (re-run `detect()` inside `finalize()`) was **implemented and
measured, then reverted**. It broke **9 pre-existing tests** across
`BankFileAutoGenerationTest`, `PayrollPeriodLifecycleTest`, `ThirteenthMonthTest`
and `PayrollMoneyFindingsRegressionTest`, all with `BusinessRuleException`, because
it newly blocks the flows that never had detection at all —
`ThirteenthMonthService::computeAndPay()` and `PayrollController::recompute()`.
Closing it properly needs a durable detection-state column plus a decision on the
13th-month path. Reverted cleanly, verified with `sha256sum -c`.

An `AUDIT NOTE` comment now marks the gate at
`PayrollPeriodService.php:1176-1192` with the measurement and the reason, so the
next reader does not re-derive it.

## Tests added

`api/tests/Feature/Payroll/PayrollListSortAndExportPrecisionTest.php` — 14 tests,
37 assertions, all passing.

Confirmed **red against unmodified source** (`git show HEAD:` extracts swapped in,
run, then restored and verified with `sha256sum -c`): **8 of 14 failed**, namely
6 of the 8 sort-direction data sets and both alphalist precision tests. The
remaining 6 are **pass-either-way regression locks**, labelled as such:
`flat basic is the monthly equivalent halved for both pay types`, the two missing-rate
message tests, `an explicit ascending direction is still honoured`, and the
`empty` direction data set for both endpoints (an empty string happens to be
tolerated on the periods path at HEAD).

## Scratch removed

`api/tests/Feature/Payroll/ZzPayrollInvariantProbeTest.php` — the measurement
harness for all 26 invariants below. Deleted; its findings are recorded here and
in `audit-report.md`.

## Static checks

- `php -l` — clean on all five changed source files and the new test.
- `phpstan analyse … --memory-limit=1G` — **[OK] No errors** (6 files).
- `pint --test` — the five changed source files fail, and **every violation is
  inherited from HEAD**: the fixer list for each file is byte-identical to the
  list Pint reports for its `git show HEAD:` extract (compared programmatically;
  `ALL INHERITED`). The new test file's two violations were genuinely new and
  were fixed with `pint` on that file alone; it now passes.
- No SPA file was modified, so no typecheck/eslint/token audit was required.

## Invariants executed — measured results

| invariant | probe | result |
|---|---|---|
| flat basic (`monthly`) | compute with **zero** attendance rows; assert `basic_pay == basic_monthly_salary ÷ 2` | **PASS** ₱21,000 → ₱10,500.00, `days_worked` 0.0 |
| flat basic (`semi_monthly`) | same, `semi_monthly_rate` ₱9,460 | **PASS** ₱9,460.00 |
| `monthlyEquivalentSalary()` used everywhere | assert `basic_pay == bcdiv(monthlyEquivalentSalary(),'2',2)` both types, before + after delegating `monthlyBasis()` | **PASS** — copies agreed; now one definition (F14 fixed) |
| cycle-claim uniqueness, two connections | `pcntl_fork`; parent holds an uncommitted claim, child inserts the same `(employee_id, cycle_key)` on its own PDO | **PASS** child **blocked**, then refused `23505`; 1 row survives |
| void releases claims | `PayrollPeriodScopeTest::test_voiding_a_period_releases_its_cycle_claims` | **PASS** claims → 0; replacement run pays the employee |
| derived half cannot contradict dates | create Aug 16–31 with `is_first_half: true`, and Aug 1–15 with `false` | **PASS** stored flag corrected; keys `2026-08-H2` / `2026-08-H1` |
| straddling window refused | Aug 10–20, and Aug 20 – Sep 10 | **PASS** both `BusinessRuleException` |
| `payroll_date` lower bound | 2026-08-01…15 with `payroll_date` 2026-07-01 | **PASS** refused, "cannot fall before the cutoff" |
| `payroll_date` upper bound | same cutoff with 2031-01-31; then grace set to 5 days and 2026-08-25 | **PASS** both refused; 2026-08-31 (routine delay) accepted |
| mid-period hire proration | hired 2026-10-13 into a 10-01…15 cutoff | **PASS** ₱9,460 × 3/15 = **₱1,892.00** |
| mid-period leaver proration | `clearances.separation_date` 2026-10-03 | **PASS** **₱1,892.00**; earliest of two clearances wins; separation on the last day pays in full; before the cutoff pays ₱0.00 |
| gov basis follows actual compensation (hire) | compare `sss_ee`/`philhealth_ee`/`pagibig_ee` against a full cutoff | **PASS** all strictly lower; deduction ratio < 0.5 |
| gov basis follows actual compensation (leaver) | same for the separated employee | **PASS** all strictly lower; net > 0; **no** `high_deduction` or `zero_pay` flag |
| gov deductions 1st-period-only | first-half vs second-half cutoff | **PASS** second half `sss_ee`/`philhealth_ee`/`pagibig_ee` all `0.00`; a mislabelled second-half window still withholds nothing |
| disjoint-scope check against real employee sets | overlapping scopes producing intersecting employees, plus company-wide-vs-scoped both ways | **PASS** refused, naming the shared employees; disjoint scopes still permitted; a voided period does not block a replacement |
| 13th-month not paid at Draft | `computeAndPay()` then inspect `is_paid` | **PASS** lands **Computed**, accruals linked, `is_paid` false until `finalize()` |
| 13th-month re-run does not wipe rows | run twice | **PASS** no double pay, rows rebuilt |
| void reopens accruals | void a finalized 13th-month period | **PASS** `is_paid` false, `paid_date` null |
| finalized period immutable | recompute · compute-claim · approve · finalize-again · force-unlock, then HTTP `PUT` and `DELETE` | **PASS** **5/5** `BusinessRuleException`; **405** on both HTTP verbs (no edit or delete route exists); a posted period will not re-post |
| anomaly flag blocks finalize | intact policy vs `payroll.anomaly.deduction_ratio = 'not-a-number'` | **FAIL — M021-F11.** Broken policy → 0 flags → `approve()` **and** `finalize()` both succeeded. Gate fails OPEN |
| payroll rows sum exactly to GL journal | 3 employees, uneven salaries; compare the cash-account credit to `SUM(net_pay)` | **PASS** exact to the cent; `total_debit == total_credit` |
| loan deduction cannot overdraw (R-005b) | manual ₱11,000 on a ₱12,000 loan, then a back-dated cutoff | **FAIL — M021-F10.** ledger **₱14,000 vs ₱12,000 due**; `balance` ₱11,000 while the ledger was settled; loan re-opened `paid` → `active` |
| void does not corrupt the loan schedule (R-005) | recompute April after May has run | **FAIL — M021-F10.** ledger ₱2,000 but `balance` ₱11,000 (truth ₱10,000); `balance + SUM(payments) == total_due` violated by ₱1,000 |
| row-level scope for `employee` | `employee`-role user reads own vs another's payroll and lists `/payrolls` | **PASS** own **200**, other **403**, list returns exactly the caller's own row |
| raw-id-free error bodies | the 403 body above, plus every payroll Resource | **PASS** no raw pk or `employee_id` in the body; all nine Resources emit `hash_id`; `tryDecodeHash` is hashids-only (no raw-int acceptance) |
| money discipline (`float`/`round`/SQL arithmetic) | grep + read of every service, controller, resource, model, job, and the SPA payroll surfaces | **FAIL then FIXED** — `BirAlphalistService` (F13, fixed). Remaining `(float)`/`round()` are percentages, hour comparisons, setting validation, or anomaly diagnostics — itemised in the Polish list. **SPA has no money-float defect**: every peso figure goes straight to `formatPeso()`; the `Number(x)` uses are exact-zero predicates |

Could **not** test, and why:

- **Two payroll workers racing the SAME employee through the real job.** The
  cycle-claim uniqueness was proved at the constraint with two real PDO
  connections, which is where the guard actually lives, but a genuine two-worker
  `ProcessPayrollJob` race needs a live queue and was not run.
- **The `AUDIT NOTE` deferral for M021-F11** is documented, not tested — a test
  for it would have to assert the broken behaviour.
- **Browser/e2e.** No SPA file was changed, so no Playwright run was attempted;
  the prior session's Vite cache-ownership blocker was not re-tested.
- **Large-population load** (M021-F08) — unchanged from 2026-08-24.
