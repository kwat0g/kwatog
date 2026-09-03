# M021 — Payroll period processing audit report

Audit date: 2026-08-24  
Status: 📋 Plan Ready  
Release recommendation: separate implementation work required

Production audit: 38/100, blocked, because payroll calculation, disbursement evidence, bank artifacts, and statutory-table publication still have financial-integrity gaps even though compute claims, GL handoff, and lifecycle locking are comparatively strong. The focused Docker suite passed 264 tests and 771 assertions, but the browser harness and the highest-risk concurrency/publication scenarios were not verified.

## Executive assessment

M021 has a strong operational skeleton. Period creation validates date and employee-scope overlap; compute uses a durable outbox, per-period overlap protection, claim tokens, and per-employee transactions; failures become visible payroll rows that block approval; approval/finalization/disbursement/void paths lock and reload authoritative rows; GL posting is idempotent, balanced, period-guarded, and audited; bank builders reconcile decimal totals; payslip email has durable state/recovery; statutory exports and self-service payslips have dedicated server-side boundaries.

The release boundary is weaker than that skeleton. A dependency failure in de minimis calculation is treated as zero tax-impacting excess and can continue through payroll. Approved adjustments are not atomically claimed before application. Disbursement can be closed with an arbitrary proof without reconciling the bank artifact or net-pay total. Proof archival is not lifecycle-safe and its advertised restore path cannot restore the deleted private file. Bank-file download is a generating side effect that creates a new random artifact on each request. Government contribution imports can deactivate a prior schedule after a partial or invalid upload. These are P1 financial or publication risks, so the module is plan-ready rather than release-ready.

## Blockers and high-value findings

### M021-F01 — P1, Broken: de minimis lookup failure silently becomes zero taxable excess

Evidence: PayrollCalculatorService.php:253-260 calls the de minimis service while building contributions. PayrollCalculatorService.php:777-794 returns 0.00 when the class is absent and catches any Throwable from the service, logging a warning and continuing with zero excess.

Impact: a missing table, settings failure, query error, or service regression can silently understate taxable de minimis excess and therefore under-withhold BIR tax while the payroll remains computable, approvable, and finalizable. The error is not represented in the payroll error rows that approval checks.

Action: make statutory/de minimis dependency failure fail closed for the affected employee or period, or persist a visible manual-review state that blocks approval/finalization. Distinguish a legitimate zero result from an unavailable calculation. Add missing-table, settings, service-exception, retry, and approval-block tests.

### M021-F02 — P1, Broken: an approved adjustment can be applied twice under concurrent computation

Evidence: PayrollCalculatorService.php:963-995 queries Approved adjustments with whereNull(applied_at) but does not lock the adjustment rows before creating deduction details and then setting applied_at. The sequential coverage in PayrollCalculatorServiceTest.php:576-646 proves recomputation does not double-apply after the first commit, but does not cover two workers reading the same unapplied row before either worker marks it.

Impact: concurrent computations or overlapping period work can create duplicate adjustment deductions, duplicate application links, and incorrect net pay. A sequential recomputation guard does not protect the read/insert/update interleaving.

Action: claim approved adjustments atomically under a stable lock order, or add a database-level application key that makes one payroll/adjustment application unique and safely handles the losing transaction. Re-read the authoritative state before applying and add a two-connection concurrency test with rollback/retry behavior.

### M021-F03 — P1, Incomplete: any disbursement proof can close a full period

Evidence: DisbursementProofController.php:45-90 accepts an optional disbursed_amount and date, uploads a private file, and creates a proof without requiring a matching bank artifact, total, or lifecycle status. PayrollPeriodService.php:995-1025 allows Finalized to become Disbursed when GL is Posted or NotRequired and at least one non-deleted proof exists; it does not require bank_file_status Generated, proof amount coverage, or proof total equal to period net pay. Migration 0151 permits partially_disbursed and nullable proof amounts, but the close service only implements the full Disbursed transition.

Impact: a period can be marked fully disbursed with a zero/omitted/mismatched proof or with no generated bank file. The status then becomes a financial assertion that the evidence does not support, and the partial-disbursement model is not represented in the close rules.

Action: define whether disbursement is bank-file based, manual-proof based, or supports partial settlement. Require proof metadata and amount validation, reconcile the sum of valid proofs against the payable net total, require bank-file generation when policy requires it, and enforce allowed proof upload statuses. Add mismatch, partial, duplicate, zero, missing-file, and replay tests.

### M021-F04 — P1, Broken: proof archive and restore do not preserve the evidence artifact

Evidence: PayrollPeriod.php:55 casts status to PayrollPeriodStatus. DisbursementProofController.php:143-166 compares that enum-cast value strictly with the string disbursed, so the post-disbursement archive guard never matches. The same method soft-deletes the proof and registers after-commit physical file deletion. DisbursementProofController.php:168-172 restores only the model. Routes at api/app/Modules/Payroll/routes.php:89-98 use standard implicit binding for restore, which excludes SoftDeletes rows by default, and the restore action has no file restoration path. PayrollPeriodService.php:104-109 explicitly uses withTrashed for the period detail, while the SPA at spa/src/pages/payroll/periods/detail.tsx:1304-1305 tells operators the proof can be restored later.

Impact: operators can archive evidence after disbursement despite the stated policy, the restore route generally cannot resolve an archived proof, and even a manually resolved restore would point to a file already deleted from private storage. Audit evidence can therefore be lost or appear restorable when it is not.

Action: use enum-aware lifecycle checks, make archived/disbursed evidence policy explicit, bind trashed proofs intentionally, and choose a recoverable retention strategy (for example, retain and hide the file, or restore from an immutable archive). Add archive-after-disbursement, deleted-record restore, missing-file, authorization, and physical-file retention tests.

### M021-F05 — P1, Incomplete: bank-file download is a non-idempotent generating side effect

Evidence: BankFileService.php:92-196 generates a file, creates a BankFileRecord with a random filename, and marks the period generated. BankFileService.php:231-247 stream() calls generate() before every read. Migration 0037_create_bank_file_records_table.php:13-30 has no unique active-artifact/current-record/revocation key. Event replay is guarded by GenerateBankFileOnPayrollFinalized.php, but that guard does not protect manual or repeated GET downloads.

Impact: repeated downloads, browser retries, or concurrent operators can create multiple private files and audit records for one disbursement, with no explicit current artifact or revocation semantics. The transaction also holds the period row lock while loading payrolls and writing the file, increasing contention for larger periods.

Action: make generation a command/explicit mutation with an idempotency key and a durable current-artifact record. Make download read-only against that record, authorize the artifact and period state, and define regeneration/revocation behavior. Add repeated GET, concurrent download, failed-write cleanup, and replay tests.

### M021-F06 — P1, Incomplete: statutory contribution imports can publish an incomplete schedule

Evidence: GovernmentContributionTableImportService.php:66-118 converts numeric inputs through float, catches row errors, increments skipped/errors, and continues within the import transaction. At :120-129, deactivatePrior can deactivate all prior active rows before the import has established that the uploaded set is complete, non-overlapping, and valid. Migration 0031_create_government_contribution_tables_table.php:26-40 provides indexes but no uniqueness constraint for duplicate effective brackets. GovernmentContributionTableService.php:92-127 exposes row-level update/deactivate/activate operations without a full schedule-set validation. ImportGovContributionTableTest.php:84-101 explicitly accepts a malformed row being skipped while valid rows import.

Impact: a bad or partial CSV can leave the company with an incomplete active bracket schedule after the previous schedule is deactivated. Payroll may fail part-way through, use an unintended bracket set, or require emergency manual correction. Float parsing also makes exact statutory thresholds less reliable.

Action: stage the entire upload, validate agency/effective-date completeness, exact decimal values, bracket ordering, gaps/overlaps, duplicates, and expected row count, then activate the new version only after all rows pass. Preserve the previous active version on any failure, add a unique database key, record actor/version/audit metadata, and repair the SoftDeletes restore route for table rows if restore remains supported.

### M021-F07 — P2, Incomplete: adjustment read/create/approve/reject permissions are collapsed

Evidence: api/app/Modules/Payroll/routes.php:108-114 uses payroll.adjustments.create for list, show, create, approve, and reject. RolePermissionSeeder.php:470-486 grants that permission to HR, while :494-504 grants the payroll module set to Finance. PayrollAdjustmentService.php:74-114 checks the creator identity and state but does not introduce a separate approval permission or role-specific checker boundary.

Impact: maker-checker prevents self-approval but does not establish a distinct authorization capability. A permitted HR maker can approve another HR adjustment, and read access cannot be separated from mutation/approval where the operating model requires it.

Action: define separate adjustment view, create, approve, reject, and possibly apply permissions. Preserve self-approval rejection, add role/action matrix tests, and update UI gates and seed data together.

### M021-F08 — P2, Incomplete: whole-population reads and bank-file I/O can amplify lock and memory pressure

Evidence: PayrollPeriodService.php:631-640 loads all available employees with get(). ProcessPayrollJob.php:104-116 also obtains the full employee collection for a computation. BankFileService.php:102-124 loads all payrolls, and :163-193 writes the artifact while the period transaction/lock remains active. These paths have no bounded chunk/stream contract in the inspected code.

Impact: large employee populations can cause worker memory growth, slow retries, and long periods during which lifecycle actions are blocked by the period lock. Bank generation and payroll computation can turn a large normal run into an operational incident.

Action: measure expected population sizes, introduce bounded chunking/streaming where safe, minimize lock duration around external/file I/O, and add load/recovery tests before changing transaction boundaries.

### M021-F09 — P2, Incomplete: decimal money crosses float boundaries in summaries and artifacts

Evidence: PayrollPeriodService.php:89-96 and :139-178 format summary and variance totals through float; BankFileService.php:289-291 uses float for preview totals and :336/:369 use float formatting in CSV builders. Core calculator and GL code otherwise uses decimal string/bc math.

Impact: display and generated-file values can diverge at large amounts or fractional-cent boundaries even when persisted totals are correct. This weakens the consistency of operator review and artifact reconciliation and makes future format changes risky.

Action: keep money as decimal strings through response DTOs and artifact builders, centralize scale/rounding policy, and add high-value and fractional-cent regression fixtures comparing persisted, preview, CSV, and bank totals.

## Strengths observed

- Compute claims use a token, durable outbox handoff, per-period overlap protection, stale-claim recovery, and a job that is intentionally not ShouldBeUnique so the claim fence remains authoritative.
- Per-employee transactions persist diagnostic failures and approval blocks error rows rather than silently treating failed employees as successful.
- Payroll-cycle claims use a unique database guard, protecting the employee/period cycle from sequential duplicate payroll rows.
- Approval, finalization, disbursement, retry, force-unlock, and void paths generally lock and reload authoritative state; maker-checker and audit events are present.
- GL posting builds a balanced journal from decimal strings, refuses closed posting periods, is idempotent on an existing journal, and records the actor/audit trail.
- Bank generation refuses missing bank accounts, uses private storage, and compares builder totals with database totals using decimal arithmetic.
- Payslip email tracks queued/sent/failed state and has reconciliation/recovery behavior. Statutory exports have a dedicated permission and finalized/disbursed status gate. Payroll and self-service controllers enforce server-side employee/department scope.
- The SPA follows the Atelier system with status-gated actions, explicit confirmation dialogs, progress fallback, loading/error/empty states, accessible labels/focus, and dense data-table patterns.

## Evidence checked

- Current branch/release surface: main at b269eafd, origin aligned. Recent changes include backup/health/export hardening. Pre-existing dirty changes were preserved and no M021 production files were modified.
- Payroll routes, requests, controllers, services, jobs, listeners, models, enums, migrations, permission seeders, console schedules, frontend pages/components/API/types, focused tests, load fixture, design-system guidance, deployment, and restore documentation were inspected.
- Clean Compose test run: 264 passed, 771 assertions, 03:36.390. The host-side run was invalidated before assertions by DB_HOST=db DNS resolution outside Compose and was not treated as a product failure.
- SPA typecheck completed without diagnostics.

## Evidence missing / follow-up validation

- Browser/e2e verification was not completed because Vite could not write its cache under root-owned spa/node_modules/.vite-temp; no ownership change or cleanup was performed.
- No two-connection test covers approved-adjustment application, de minimis dependency failure, proof amount reconciliation, or statutory import activation safety.
- No load run covers large payroll populations, bank-file generation, scheduler throughput, or worker recovery under contention.
- No deployed queue/SMTP/realtime/scheduler/storage verification or restore-drill evidence was available.

## Release decision

No production-code fixes were applied. The majority of findings require migrations, transaction/locking changes, financial policy decisions, permission changes, or new concurrency/publication tests. Applying only the smaller float, UI, or query improvements would leave the primary financial-integrity risks unresolved. M021 is released as 📋 Plan Ready with the ordered action plan below.

---

# Re-audit — 2026-08-30

Audit date: 2026-08-30
Status: 🔁 Needs Re-audit
Release recommendation: three contained fixes applied in-session; the loan
over-deduction defect and the anomaly-gate fail-open are handed off.

## What the reclaimed session left behind

The lock was an orphan stamped `2026-08-25T11:51:43Z` by
`codex-coordinator-blocker-quarantine`, 108h old. It was **completed work, not
abandonment**, matching the pattern seen in `user-administration`, `auth-session`,
`journal-ledger` and `loans-cash-advances`:

- `fix-log.md` was fully written (the "2026-08-25 implementation session" section).
- Every claimed fix is **committed** — swept into `167de85e`. Verified by probe,
  not by reading the log: `DisbursementEvidenceService.php` exists and enforces
  exact evidence reconciliation (`:99-133`), `2026_08_25_140000_add_artifact_key_to_bank_file_records.php`
  and `2026_08_25_141000_add_unique_government_schedule_key.php` are on disk,
  `PayrollCalculatorService::deMinimisTaxableExcess()` now throws instead of
  returning `0.00` (`:788-807`), `applyApprovedAdjustments()` holds
  `orderBy('id')->lockForUpdate()` (`:980-989`), and the separate
  `payroll.adjustments.{view,approve,reject}` permissions are enforced in
  `routes.php:112-118`.
- The working tree held **no uncommitted payroll change** at claim time.

Of the nine findings the 2026-08-24 report raised, **none of F01–F05, F07 or the
decimal half of F09 still reproduce**. F06 (statutory-import completeness
metadata) and F08 (bank-file population/lock) remain open exactly as the prior
fix-log states. Two NEW Broken findings and several Incomplete/Polish items are
recorded below.

## Baseline before any change this session

`tests/Feature/Payroll` on a private database (`ogami_test_payroll`):
**268 passed, 1 failed, 790 assertions, 269.83s.**

The one failure is a pre-existing cross-module blocker, unchanged from the prior
session's note:

```
FAILED PayrollMoneyFindingsRegressionTest > p02 01 payroll je has actor and audit row
Payroll JE must record who created it.
Failed asserting that null is not null.
  at tests/Feature/Payroll/PayrollMoneyFindingsRegressionTest.php:208
```

This is shared Accounting decision #12 and is **not this module's to decide** —
see "Cross-module consequences" below. Not fixed, not worked around.

## Broken

### M021-F10 — P0, Broken: a payroll run can over-deduct a loan past what is owed

`PayrollCalculatorService::applyLoanDeductions()` clamps the deduction on the
**denormalized** `employee_loans.balance` column
(`api/app/Modules/Payroll/Services/PayrollCalculatorService.php:867-869`) and then
rebuilds that same column with
`reconcileAggregates($loan, $period->payroll_date->toDateString())`
(`:896-897`) — an as-of cut that **drops every ledger row dated after the payroll
date**. A back-dated or out-of-order cutoff therefore erases later payments from
the summary and re-opens a settled loan, and the next run clamps against the
fabricated balance.

The asymmetry is the tell: the manual path, `LoanService::recordPayment()`
(`api/app/Modules/Loans/Services/LoanService.php:325-332`), derives its cap from
the **ledger** (`totalDueFor - SUM(payments)`) and therefore cannot overdraw. Only
the payroll path trusts the denormalized column.

Measured against real PostgreSQL rows (zero-interest company loan, principal
₱12,000, 12 cutoffs × ₱1,000; `now()` pinned to 2026-08-30):

| step | ledger `SUM(loan_payments.amount)` | `employee_loans.balance` | `status` |
|---|---|---|---|
| manual ₱11,000 recorded 2026-08-30 | 11,000.00 | 1,000.00 | active |
| April 1–15 cutoff computed (`payroll_date` 2026-04-15) | **12,000.00** (settled) | **11,000.00** | **active** |
| two further cutoffs computed | **14,000.00** | 9,000.00 | active |

`14,000.00` against a `12,000.00` total due: **₱2,000 taken that was not owed**,
and the loan would keep paying itself down from a fabricated balance until the
as-of cut caught up. This independently reproduces the ₱250 overdraw the
`loans-cash-advances` session reported (R-005b) and identifies the mechanism.

### M021-F11 — P1, Broken: the anomaly gate that blocks finalize fails OPEN

Anomaly flags are the finalize gate (`PayrollPeriodService::finalize()` counts
unresolved flags at `:1193-1200`). Detection runs in `ProcessPayrollJob`'s
`finally` block inside `catch (Throwable) → Log::warning`
(`api/app/Modules/Payroll/Jobs/ProcessPayrollJob.php:214-221`), and
`PayrollAnomalyService::policy()` throws `BusinessRuleException` on any
non-numeric `payroll.anomaly.*` setting (`:225-231` pre-fix). So a detector that
threw produced **zero flags**, and a broken gate is indistinguishable from a
clean period — the exact defect pattern CLAUDE.md documents for the 8D SLA
escalation ledger, this time on a money gate.

Measured: with `payroll.anomaly.deduction_ratio` set to `'not-a-number'`, the
compute job completed without error, `payroll_anomaly_flags` for the period was
**empty**, and `approve()` then `finalize()` both **succeeded** — the period
reached Finalized with the gate silently disabled. The same period computed with
an intact policy raised flags and would have been held.

Related, and part of the same finding: neither `ThirteenthMonthService::computeAndPay()`
nor the single-employee `PayrollController::recompute()` path runs detection at
all, so a 13th-month period is finalized having **never** been evaluated.

**Not fixed in-session.** A re-run of `detect()` inside `finalize()` was
implemented and measured, then reverted: it broke **9 pre-existing tests** with
`BusinessRuleException`, because it newly blocks the flows that have never been
evaluated (13th-month and direct-compute). Closing this needs a durable
"detection completed / failed" state on the period plus a decision on the
13th-month path — schema plus policy. An `AUDIT NOTE` comment now marks the gate
at `PayrollPeriodService.php:1176-1192` so the next reader does not have to
re-derive this.

### M021-F12 — P2, Broken (fixed): an unusable sort direction returned HTTP 500

Both payroll list endpoints whitelisted the sort **column** and passed the
caller's `direction` straight to `orderBy()`, which throws
`InvalidArgumentException` on anything but `asc`/`desc`.
`GET /api/v1/payroll-periods?sort=period_start&direction=sideways` returned
**500** (measured). Same shape as the `docs/PATTERNS.md:262-268` bug.
Sites: `PayrollPeriodService::list()` and `PayrollController::index()`.

### M021-F13 — P2, Broken (fixed): the BIR 2316 Alphalist did money in floats

`BirAlphalistService::generate()` passed every figure through
`round((float) …)` and computed `taxable_income` as
`max(0.0, (float) gross - (float) deductions)` — binary floating-point
arithmetic producing a **filed tax return** figure that has to reconcile against
the payroll rows. `toCsv()` then re-converted with `number_format(float)`.
This is the one money surface the prior session's F09 decimal pass missed.

## Incomplete

- **M021-F14 — a second copy of the pay-type reconciliation (fixed).**
  `PayrollCalculatorService::monthlyBasis()` re-derived `semi_monthly_rate × 2`
  itself rather than calling `Employee::monthlyEquivalentSalary()`, which
  CLAUDE.md names as the ONE place the two pay types are reconciled. The copies
  agreed (measured: `basic_pay === monthlyEquivalentSalary() ÷ 2` for both pay
  types, before and after), which is precisely why a divergence would have gone
  unnoticed. A third reader remains and is legitimate:
  `historyMonthly()` (`:706-724`) must read a `employee_salary_history` row, which
  the model accessor cannot serve.

- **M021-F15 — `recompute` is gated by a READ-scope check.**
  `PayrollController::recompute()` (`:86-93`) authorizes with
  `authorizePayroll()`, the same predicate used by `show()` and `payslip()`: it
  returns early for the caller's own payroll row and for any row in a
  `department_head`'s department. The route permission
  (`payroll.periods.compute`) is the real control, but a mutation should not
  share a read predicate. No seeded role reaches it, so it is latent.

- **M021-F16 — five backend endpoints have no client, and one page has no route.**
  No SPA caller exists for `POST gov-tables/{agency}/import` (`routes.php:41`),
  `DELETE`/`PATCH restore` on `admin/gov-tables/{govTable}` (`:33`, `:35`),
  `GET de-minimis/{deMinimisBenefit}` (`:125`), or
  `GET payroll/statutory/sss-r3/{period}` (`:152`). Two more have an api-layer
  method but no page caller: `GET payroll-adjustments/{adjustment}`
  (`spa/src/api/payroll/adjustments.ts:15-16`) and `listProofs`
  (`spa/src/api/payroll/periods.ts:99-102`). `spa/src/pages/payroll/pipeline.tsx`
  is fully dead — nothing imports it and its only endpoint is the deliberately
  commented-out `/payroll-periods/pipeline` (`routes.php:53-61`), which is
  intentional scope-cut, unlike the rest.
  Two of these matter beyond tidiness: **SSS R-3 is a statutory remittance with
  no way to reach it**, and the **government-table CSV import that the prior
  session hardened (F06) has no UI at all** — that hardening is currently
  unreachable.

- **M021-F17 — an over-permissive 13th-month button.**
  `spa/src/pages/payroll/periods/index.tsx:128` computes
  `can('payroll.thirteenth_month.run') || can('payroll.periods.create')`, while
  the backend requires the first alone (`routes.php:67`). A create-only custom
  role sees a live button and gets a generic
  `toast.error('Failed to create 13th-month period.')` (`:221`) rather than a
  permission message. Backend enforces; UX only. Unreachable with seeded roles.

- **M021-F18 — a cancelled separation would still cut pay (latent).**
  `PayrollCalculatorService::separationDate()` (`:606-619`) reads
  `clearances.separation_date` filtering only on `deleted_at`, so it does not
  exclude `ClearanceStatus::Cancelled`. Not currently reachable: no service or
  route sets that status. Owned by `people/separation-final-pay`; reported, not
  touched.

## Polish

- `PayrollAnomalyService::detectForPayroll()` (`:94-155`) casts money to `float`
  for the ratio comparisons and **persists those floats** into the flag
  `details` JSON. Diagnostics only, never an amount, but the stored figures are
  the ones an operator reads while deciding to resolve a flag.
- `PayrollPeriodService::variance()` (`:165`) and `pipeline()` (`:182`) use
  `round((float) …)` for **percentages**, not money — correct as-is; noted so a
  future grep does not re-flag them.
- `DeMinimisBenefitResource:27` reads a money **limit** through
  `SettingsService::requiredFloat()`. Fixing it needs a decimal accessor in
  `App\Common`, outside this module.
- `spa/src/types/payroll.ts:340-345` types `delta.gross/net/deductions` as
  `number`; the backend returns decimal strings (`PayrollPeriodService.php:171-173`).
  Nothing mis-renders today (only comparison and formatting), but the type lies
  about the wire format.
- `spa/src/types/payroll.ts:188` declares `PayrollDeductionDetail.reference_id`
  as a required `number | null`, yet `PayrollDeductionDetailResource` never
  serializes it — a non-optional field that is always `undefined` at runtime,
  typed as a raw integer. Nothing leaks; the declaration should go.
- `spa/src/types/payroll.ts` declares `PipelinePeriod` and `PayrollPipeline`
  twice, byte-identical (`:305-325` and `:355-375`).
- `PayrollController::index()` applies `scopePublishable()` unconditionally, so
  `/payrolls?period_id=<computed period>` is empty and `/payrolls/{id}` 422s for
  a Computed row **even for HR**. Intentional publication boundary as far as can
  be told (the period detail page reads its rows off the period resource
  instead), but it makes a plausible review URL look broken. Flagged as a
  question, not a defect.

## Cross-module consequences (report only — do not fix here)

**Shared Accounting decision #12.** `JournalEntryService::create()` discards the
maker on any source-linked entry:
`'created_by' => empty($data['reference_type']) ? $user?->id : null`
(`api/app/Modules/Accounting/Services/JournalEntryService.php:139`). Payroll always
sets `reference_type => 'payroll_period'`
(`PayrollGlPostingService.php:317-323`), so **every payroll GL entry carries
`created_by = null`**. Two payroll-side consequences:

1. `assertNotSelfPosting()` returns early when `created_by` is null
   (`JournalEntryService.php:409-412`), so the JE-level self-posting guard is
   **inert on every payroll posting**. Payroll's own maker-checker is not
   affected: `approve()` still refuses `computed_by === approver`
   (`PayrollPeriodService.php:972-976`).
2. Attribution is not lost, only absent from the ledger row — it survives in
   `payroll_periods.finalized_by` and in the `payroll.je.post` audit row whose
   `user_id` is that same actor (`PayrollGlPostingService.php:327-343`).

The consequence is a **red test inside this module** asserting the opposite of
the shipped shared decision: `PayrollMoneyFindingsRegressionTest:208`. Left red
deliberately. Someone owning both modules must decide whether the decision or
the test is wrong.

## Invariants executed

See the table in `fix-log.md` for the probe used and the measured result for each
of the 26 assigned invariants.

---

### RESOLVED 2026-09-04 — cross-module decision #12 upheld

The project owner upheld shared Accounting decision #12: source-linked entries
carry no maker, so `created_by` stays null on the payroll JE. The P02-01 test
was aligned to assert the attribution that actually exists — `posted_by` equals
the finalizing user, `payroll_periods.finalized_by` preserves the actor on the
source row, and the `payroll.je.post` audit row records it (asserted with
`user_id`). Reversing the decision would trip the self-post guard on every
invoice/bill/asset posting, which create and post as the same user.
