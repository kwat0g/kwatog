# M021 — Payroll period processing action plan

Date: 2026-08-24  
Status: 📋 Plan Ready  
Overall recommendation: separate implementation work; no production-code fixes in this audit session

The focused Docker suite passed (264 tests, 771 assertions), but the highest-risk issues require financial policy, transaction/locking changes, migration review, permission design, and concurrency/publication tests. Work should be split into reviewable changes with explicit rollback and worker verification.

## Ordered implementation plan

### 1. M021-F01 — Fail closed when de minimis data is unavailable

- Classification/severity: Broken, P1
- Scope: medium; calculator, payroll error/state contract, approval/finalization gates, tests
- Recommendation: separate session
- Distinguish a valid zero taxable excess from an unavailable de minimis calculation. Persist a visible employee/period error or manual-review state and block approval/finalization until the dependency is recovered or an authorized override is recorded.
- Tests: missing table/settings, service exception, retry recovery, visible error row, approval block, finalization block, and legitimate zero-result cases.

### 2. M021-F02 — Make approved-adjustment application atomic

- Classification/severity: Broken, P1
- Scope: medium/large; calculator transaction/lock order, adjustment schema/application key, tests
- Recommendation: separate session
- Claim an approved adjustment under a stable lock order or enforce a unique payroll-period/adjustment application key. Re-read before deduction creation, make the losing concurrent worker safe, and preserve recomputation/reversal behavior.
- Tests: two-connection concurrent application, retry after deadlock/rollback, same-period recompute, different-period overlap, rejection/approval race, reversal, and audit attribution.

### 3. M021-F03 — Define and enforce disbursement evidence policy

- Classification/severity: Incomplete, P1
- Scope: large; proof request/controller, period state machine, bank-file contract, migration, finance policy, tests
- Recommendation: separate session with finance owners
- Decide whether a period may be manually evidenced, bank-file settled, or partially settled. Require valid proof metadata and file existence, reconcile proof totals to payable net, require generated bank artifacts where policy requires them, and represent partial settlement explicitly rather than treating any proof as full Disbursed.
- Tests: omitted/zero/mismatched amounts, partial proof sets, duplicate proofs, missing artifact, failed upload, GL pending, bank-file generated, full reconciliation, repeated close, and void/retention behavior.

### 4. M021-F04 — Repair proof archive/restore and evidence retention

- Classification/severity: Broken, P1
- Scope: medium; enum-aware state guard, trashed binding, private-file retention/archive, controller/service/UI/tests
- Recommendation: bundle with the disbursement evidence session only if one owner can keep the policy coherent
- Prevent archive after the policy-defined terminal state, intentionally resolve trashed proofs, and choose whether restoration retains the original private file or uses an immutable archive. Align detail-page copy, API behavior, and audit events.
- Tests: archive before/after disbursement, restore authorization, deleted-model route binding, missing physical file, restore success, and audit-history visibility.

### 5. M021-F05 — Make bank artifacts explicit and idempotent

- Classification/severity: Incomplete, P1
- Scope: medium/large; bank service, record schema, download route, artifact lifecycle, tests
- Recommendation: separate session
- Move generation behind an explicit mutation or idempotent command, persist one current artifact per period/format/version, and make download read-only. Define regeneration, revocation, retention, and concurrent-request semantics. Reduce period-lock duration around file I/O after correctness is preserved.
- Tests: repeated download, concurrent download, event replay, failed write cleanup, regeneration, revoked artifact, authorization, and builder/database total equality.

### 6. M021-F06 — Stage and validate statutory contribution table versions

- Classification/severity: Incomplete, P1
- Scope: large; import service, table schema/versioning, activation transaction, CRUD permissions, tests
- Recommendation: separate session with payroll/statutory owners
- Parse exact decimals, validate the complete effective-dated set for gaps, overlaps, duplicates, bounds, agency, and expected row coverage, then activate only after the full version passes. Preserve the prior active version on any failure, add database uniqueness, and record actor/version/audit metadata. Decide whether row-level CRUD remains compatible with versioned publication.
- Tests: malformed row, partial upload, duplicate bracket, overlap/gap, effective-date boundary, prior-version preservation, activate/deactivate/retry, rollback, and concurrent payroll read during activation.

### 7. M021-F07 — Separate adjustment permissions from maker-checker policy

- Classification/severity: Incomplete, P2
- Scope: small/medium; permission seeder, routes, service gates, UI gates, authorization tests
- Recommendation: separate authorization session
- Define view, create, approve, reject, and apply capabilities. Keep self-approval rejection but require a dedicated checker permission/role for approval and rejection. Roll out seed data and frontend gates together.
- Tests: HR maker, finance checker, same-user rejection, cross-user approval, read-only viewer, unauthorized route access, and direct API bypass attempts.

### 8. M021-F08 — Bound payroll and bank populations without weakening correctness

- Classification/severity: Incomplete, P2
- Scope: medium; query chunking/streaming, transaction boundaries, worker memory/lock metrics, tests
- Recommendation: separate performance/operability session
- Measure realistic employee counts, chunk available employees and payroll rows where safe, stream artifact output, and avoid holding the period lock during non-database I/O unless the artifact claim requires it. Preserve claim fencing and total reconciliation.
- Tests: large population, memory ceiling, lock wait, worker retry, partial chunk failure, and exact aggregate totals.

### 9. M021-F09 — Remove float boundaries from payroll presentation and artifacts

- Classification/severity: Incomplete, P2
- Scope: small/medium; summary DTOs, variance/preview, CSV/bank builders, shared money policy, tests
- Recommendation: bundle with a financial-output hardening session
- Keep decimal strings through serialization and artifact generation, centralize scale/rounding rules, and compare displayed/exported values with persisted totals.
- Tests: large totals, fractional-cent inputs, negative/zero values, format-specific rounding, preview-vs-download equality, and locale/injection-safe CSV output.

## Cross-module decisions to record

- Whether de minimis/statutory dependency failure blocks the whole period, only affected employees, or requires a controlled override.
- Whether proof totals represent net payroll, bank-file settlement, manual partial settlement, or another finance-approved amount.
- Whether archived proof files are retained indefinitely, moved to immutable storage, or explicitly non-restorable.
- Which roles may create, approve, reject, finalize, disburse, void, retry, import statutory tables, and download payroll artifacts.
- Whether government contribution schedules are row-managed or versioned as atomic sets, and how an active version is rolled back.

## Session decision

No production-code implementation is authorized for this audit session. The majority of findings are separate-recommended because they change financial state semantics, concurrency guarantees, publication artifacts, statutory configuration, or authorization policy. Small presentation and query improvements should not be applied alone while the P1 controls remain open.

## Definition of done for the next implementation session

- De minimis and statutory-table dependency failures are visible and cannot silently produce or publish incorrect payroll.
- Approved adjustments have an atomic one-application guarantee with two-connection coverage.
- Disbursement state reflects reconciled, policy-approved evidence, including partial settlement if supported.
- Proof archive/restore preserves or intentionally retires the underlying evidence artifact.
- Bank generation has an idempotent artifact record and read-only download path.
- Statutory table activation is all-or-nothing, exact-decimal, versioned, and reversible.
- Permission matrix, migrations/backfills, deployment order, worker restart, rollback, and restore evidence are documented before release.

---

# Action plan — re-audit 2026-08-30

Status: 🔁 Needs Re-audit
Applied this session: M021-F12, F13, F14 (all small + contained).
Handed off: M021-F10 (P0), F11 (P1), F15–F18, plus F06/F08 carried from 2026-08-24.

**Why the split.** Three items refuse impossible input or remove a duplicated
definition without altering a single amount — those are judged on containment and
were done. The two Broken items that remain both change what someone is paid or
require a schema plus a policy decision, so they are gated. Fixing the contained
three does not mask either.

## 1. M021-F10 — stop the payroll loan deduction over-drawing the ledger

- Classification/severity: **Broken, P0**
- Scope: **medium**
- Session recommendation: **separate-recommended**
- Justification: two independent changes are needed and both alter money.
  (a) `PayrollCalculatorService::applyLoanDeductions()` must clamp from the
  **ledger** (`totalDue − SUM(loan_payments.amount)`) instead of the
  denormalized `employee_loans.balance` (`:867-869`). (b) The
  `reconcileAggregates($loan, $period->payroll_date)` as-of cut (`:896-897`) must
  stop erasing later ledger rows from the summary. (b) cannot be done from this
  module alone: the `$asOf` argument exists to date `end_date` when a loan
  settles (`LoanService.php:355-383`), which is **loans' column and loans'
  semantics** — dropping the argument silently redefines when a loan closed, and
  `LoanService` is outside this module's boundary.
- Measured evidence: ledger reached **₱14,000 against a ₱12,000 total due**
  (₱2,000 over-deducted) with `balance` still claiming ₱9,000 outstanding; the
  loan was re-opened from `paid` to `active` by a back-dated cutoff. Full table
  in the audit report.
- Do NOT change any amount without a joint decision with the loans owner. The
  correct-case behaviour must be proved byte-identical first:
  `PayrollRecomputeIntegrityTest::test_recompute_does_not_double_deduct_a_loan`
  and `PayrollCalculatorServiceTest::test_active_loan_deducted_and_payment_recorded`
  are the existing pins.
- Tests required: back-dated cutoff after a later-dated manual payment; recompute
  of an earlier period after a later one; the invariant
  `balance + SUM(payments) == total_due` asserted after **every** payroll write;
  `SUM(payments) <= total_due` as a hard ceiling; a partial final instalment;
  a cash-advance (full-amortization) loan; and the untouched forward-order case
  proved unchanged.
- Related, in loans' own scope: the per-cutoff double-deduction guard
  `loan_payments_payroll_deduction_unique` is a row-pair unique on
  `(loan_id, payroll_id) WHERE payment_type = 'payroll_deduction'`
  (`2026_08_13_212000_add_loan_payroll_payment_idempotency.php:29-33`). Confirmed:
  the payroll path does default to `payroll_deduction`, so the index does cover
  it — but a second payroll row for the same cutoff has a different `payroll_id`,
  so only `payroll_cycle_claims` stops that, not this index.

## 2. M021-F11 — make the anomaly gate fail closed

- Classification/severity: **Broken, P1**
- Scope: **medium**
- Session recommendation: **separate-recommended**
- Justification: it *sounds* like a guard that only refuses, which would be
  contained — but it is not, and this was measured rather than assumed. Re-running
  `detect()` inside `finalize()` was implemented and **broke 9 pre-existing
  tests**, because it newly blocks flows that have never been evaluated at all:
  `ThirteenthMonthService::computeAndPay()` and `PayrollController::recompute()`
  do not run detection. Closing it properly needs a durable "detection completed
  / failed at run N" state on `payroll_periods` (migration) plus a policy
  decision on whether a 13th-month period must be anomaly-screened before it can
  be finalized. That is a schema and a policy change, not a guard.
- Measured evidence: with `payroll.anomaly.deduction_ratio = 'not-a-number'` the
  compute job completed clean, zero flags were written, and `approve()` +
  `finalize()` both succeeded.
- Migration naming: `payroll_periods` is altered by several `2026_*` timestamped
  migrations (`2026_08_25_*` among them), so a column added here **must be
  timestamp-named and dated after** whatever it depends on. Confirm with
  `grep -rln 'payroll_periods' api/database/migrations | sort | tail -1` before
  choosing a name. A `0NNN_` prefix would run before every `2026_*` file.
- Partially done: `PayrollAnomalyService::flag()` no longer swallows a
  non-unique-violation write failure (`:161-206`) — the detector's own failure
  path can no longer hide its failure. The *caller's* swallow
  (`ProcessPayrollJob.php:214-221`) and the gate itself are untouched.
- Tests required: invalid policy setting; a detector write failure; a 13th-month
  period; single-employee recompute; a legitimately clean period must still
  finalize; and an operator's resolved flags must survive re-detection.

## 3. M021-F06 — statutory-import completeness metadata (carried from 2026-08-24)

- Classification/severity: Incomplete, P1
- Scope: medium
- Session recommendation: **separate-recommended** — still needs the statutory
  owner to define expected bracket coverage and version ownership.
- New information: the import endpoint it hardens
  (`POST gov-tables/{agency}/import`) has **no SPA client at all** (M021-F16), so
  the hardening is currently unreachable. Sequence the UI with this item or the
  work stays invisible.

## 4. M021-F08 — bank-file population and lock duration (carried from 2026-08-24)

- Classification/severity: Incomplete, P2
- Scope: medium/large
- Session recommendation: **separate-recommended** — unchanged; needs a
  large-population load run before the transaction boundary moves.

## 5. M021-F16 — reconcile the dead surfaces in both directions

- Classification/severity: Incomplete (Missing, for SSS R-3), P2
- Scope: medium
- Session recommendation: **separate-recommended** — it is SPA feature work
  (a gov-table import screen, a delete/restore affordance, an SSS R-3 export
  card), not a repair. Decide per endpoint whether to build the client or remove
  the route; do not leave a statutory export unreachable.
- Priority within it: **SSS R-3** (a statutory remittance with no UI) and the
  **gov-table CSV import** (blocks item 3) first.

## 6. M021-F15 — give `recompute` its own authorization predicate

- Classification/severity: Incomplete, P2
- Scope: **small**
- Session recommendation: **separate-recommended** (narrowly)
- Justification: the change itself is a few lines, but it narrows who may mutate
  payroll. It needs the role/permission matrix decided and
  `tests/Feature/Security/PayrollAuthorizationTest.php` extended, and no seeded
  role reaches the hole today — so it is not worth landing beside a money fix
  where a mistake is expensive.

## 7. M021-F17 / F18 and the Polish list

- Classification/severity: Incomplete + Polish, P3
- Scope: **small** each
- Session recommendation: **same-session-ok**, as one tidy-up batch
- Contents: drop the `|| can('payroll.periods.create')` fallback on the
  13th-month button; exclude `ClearanceStatus::Cancelled` in
  `separationDate()` (**coordinate with `people/separation-final-pay` — that
  session owns the clearance lifecycle**); retype `delta.*` as decimal strings;
  delete `PayrollDeductionDetail.reference_id` from the SPA types; de-duplicate
  `PipelinePeriod`/`PayrollPipeline`; keep money out of the anomaly `details`
  JSON.
- Not batched with anything above: none of it touches an amount, but F18 crosses
  a module line and must be handed to its owner rather than edited here.

## Question for a human

`PayrollController::index()` applies `scopePublishable()` to **every** caller, so
a Computed period's rows are invisible on `/payrolls` and `/payrolls/{id}` returns
422 even for HR. Is that the intended publication boundary (the period detail page
reads its rows off the period resource, so nothing is broken in the UI), or should
holders of `payroll.payslip.view_all` see pre-finalization rows there? Not changed.
