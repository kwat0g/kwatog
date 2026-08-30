# M023 — Separation and final pay action plan

Audit date: 2026-08-25  
Disposition: Plan Ready; no production-code fixes applied by this session

The current worktree already contains the canonical POST initiation wiring. The
remaining work must be implemented in the order below so lifecycle, authorization,
financial policy, and UI contracts do not diverge again.

## Ordered actions

### 1. Agree and encode the authoritative separation contract

- Findings: M023-F001, F002, F003, F004
- Scope: large
- Session recommendation: separate-recommended
- Change: decide the clearance state machine, department/item ownership, HR
  maker versus Finance checker boundary, compute/finalize permissions, and the
  loan/advance/property deduction policy. Document every writer and transition,
  then implement the guarded service boundary and active approval integration.
- Acceptance: every status/action has one owner and one allowed transition;
  cross-department signing and maker self-finalization are denied server-side;
  compute is impossible before completion; and the chosen deduction policy is
  executable without manual database edits.
- Verification: transition matrix, role/action matrix, unauthorized-scope,
  maker-checker, sequencing, exact-money, and two-connection concurrency tests.

### 2. Implement cancellation, restart, blocked-item resolution, and payroll recovery

- Findings: M023-F003, F006
- Scope: large
- Session recommendation: separate-recommended
- Change: add cancel/restart/recovery mutations, a supported blocked-item
  resolution path, employee/account compensation, completion invariants, and
  current-state checks for replayed outbox listeners. Filter cancelled/deleted
  records correctly wherever payroll resolves an authoritative separation date.
- Acceptance: pending/in-progress/completed/finalized/cancelled transitions are
  explicit and audited; a cancelled or restarted separation cannot cap payroll
  or leave the employee in an undefined status; and blocked items can converge
  to a valid completion or cancellation.
- Verification: state transition, cancellation/restart, blocked resolution,
  payroll-date, stale-event, replay, and concurrent-transition tests.

### 3. Close final-pay sequencing, deduction settlement, and immutable evidence

- Findings: M023-F004, F005
- Scope: large
- Session recommendation: separate-recommended
- Change: require completed clearance before compute, enforce the approved
  checker boundary, implement the selected settle/accept/recover policy, and
  persist versioned computation snapshots with source IDs/versions, actors,
  policy decisions, revisions, and journal/deduction references.
- Acceptance: compute/finalize API and UI enforce the same sequence; every amount
  revision is reconstructable; outstanding balances have a supported outcome;
  and duplicate finalization cannot create a second journal entry or deduction.
- Verification: source-change-between-compute/finalize, loan-policy,
  exact-cent, journal-balance/idempotency, audit-log, and recovery tests.

### 4. Harden checklist configuration and repair history data

- Findings: M023-F006, F007
- Scope: medium
- Session recommendation: separate-recommended
- Change: validate checklist schema and unique keys at settings write and
  initiation; define an allow-listed department/role mapping; assign employment
  history arrays directly; scan and repair existing JSON-string values through a
  controlled, observable migration/backfill.
- Acceptance: invalid or duplicate checklist configuration is rejected before it
  can create a clearance; initiation/finalization history serializes as objects
  for sensitive and non-sensitive viewers; and the repair can be measured,
  reviewed, and recovered.
- Verification: settings validation, duplicate-key, resource serialization,
  migration dry-run/count, rollback/recovery, and sensitive-view tests.

### 5. Complete canonical record, notification, and legacy-contract cleanup

- Findings: M023-F008, F009, F013
- Scope: medium
- Session recommendation: separate-recommended
- Change: persist initiation remarks on `clearances`, reconcile notification
  recipients with the approved role matrix, link notifications to the clearance
  detail, remove/deprecate the dead legacy permission/request, and update process
  documentation/API inventories.
- Acceptance: initiation remarks round-trip in the clearance resource; every
  intended owner receives an actionable notification; no supported or documented
  client targets the removed PATCH endpoint; and legacy permission assignment is
  intentional and tested.
- Verification: request/resource, notification recipient/link, route-contract,
  documentation, and permission-seed tests.

### 6. Finish list and resource API contracts

- Findings: M023-F010, F011
- Scope: small
- Session recommendation: same-session-ok
- Change: implement employee/clearance search, decode public IDs at the request
  boundary, validate positive page bounds, expose safe signer data, eager-load
  journal entries, and add bounded query-count coverage.
- Acceptance: each visible filter changes server results; invalid pagination is
  rejected or normalized; no internal signer ID is returned; and list query
  count stays bounded as rows grow.
- Verification: request/resource contract, hash-ID, pagination, search, and
  query-count tests.

### 7. Align the SPA with the complete lifecycle and role matrix

- Findings: M023-F012
- Scope: medium
- Session recommendation: separate-recommended
- Change: use the design-system status mapping, render cancelled/blocked states,
  suppress invalid actions, preserve safe server validation/policy messages, add
  item remarks, show recovery guidance, and route a successful initiation to the
  created clearance.
- Acceptance: every server status has a visual state and permitted-action matrix;
  cancelled/blocked records cannot show sign/compute/finalize actions; loan and
  permission errors are actionable; and the UI is complete for HR, department,
  warehouse/maintenance, Finance, IT, and system-admin roles.
- Verification: authenticated browser tests for initiation, each department's
  sign action, unauthorized cross-department signing, Finance finalization,
  cancellation/recovery, outstanding-loan handling, and narrow-width layouts.

## Recommended implementation sequence

1. Approve the lifecycle, ownership, maker-checker, and deduction policy.
2. Implement the clearance transition/recovery boundary and payroll compensation.
3. Implement final-pay sequencing/evidence and checklist/history integrity.
4. Reconcile notifications, permissions, documentation, and canonical remarks.
5. Finish API list/resource contracts and the complete role-aware SPA flow.
6. Run the focused backend suite on an isolated test database, the full SPA
   typecheck/lint/token/RBAC gates, authenticated browser tests, migration
   repair checks, and a final cross-module re-audit with payroll, loans,
   accounting, employee-master, and journal-ledger.

---

# M023 action plan — 2026-08-30 re-audit

Disposition: six contained fixes applied (see `fix-log.md`); everything that
changes what a leaver is paid is deferred to a human decision.

`separate-recommended` dominates for a specific, evidence-backed reason: of the
eight open items, five change a payment amount and two change who may approve a
payment. A *missing guard refusing impossible input* is judged on containment, and
those were fixed this session (pre-hire date, checklist integrity, list bounds).
A change to a leaver's final pay is not, however small its diff.

## Ordered actions

### 1. Decide and implement the loan-recovery workflow (R-002)

- Findings: M023-F004 / R-002
- Scope: medium (code) / large (policy)
- Session recommendation: **separate-recommended** — changes what a leaver is paid,
  and the loans module owns half the write path.
- Blocking question. Today an employee with any outstanding loan balance **cannot
  be separated at all**, and the 422 promises a confirmation step that does not
  exist. See the options below.

### 2. Decide the 13th-month and last-salary double-pay remedies

- Findings: M023-F014, M023-F015
- Scope: small (a `where` clause each) / large (policy)
- Session recommendation: **separate-recommended** — both reduce a leaver's payment.
- Change: (a) `proRatedThirteenthMonth()` must return zero (or accrued-minus-paid)
  when the accrual `is_paid`; (b) `lastSalaryProRated()` must treat every status
  that means "payroll will pay this" as already covered — `isLocked()` plus
  `Approved` — and a decision is needed on whether a `voided` last period is
  covered by final pay or by a replacement run.
- Acceptance: a separating employee's last salary and 13th month are each paid
  exactly once across payroll and final pay, provable by summing both sources.
- Verification: a matrix test over all seven `PayrollPeriodStatus` values and both
  `is_paid` states, asserting the combined total.
- Also settle the ₱1,420.45 spread between the payroll-row and DTR bases for the
  same separation — one basis must win.

### 3. Decide the leave-conversion policy and debit the balance

- Findings: M023-F016
- Scope: medium
- Session recommendation: **separate-recommended** — changes the payment, and the
  balance write lands in the Leave module's table.
- Change: align the separation rate with the year-end 1.0 clamp (or document why
  it differs), cap the converted days at the balance, and debit the balance inside
  the finalize transaction so the same days cannot be encashed again.
- Acceptance: converted days never exceed `remaining`; after finalization the
  balance is zero for every converted type; year-end cannot re-pay them.
- Verification: rates 0.5/1.0/2.0/9.99 against a fixed balance; a year-end run
  after a separation payout must pay nothing.

### 4. Give the clearance lifecycle a cancel/correct path

- Findings: M023-F003, M023-F017 (far-future half), M023-F018
- Scope: large
- Session recommendation: **separate-recommended**
- Change: a guarded clearance transition map with cancel/restart/blocked-item
  resolution and employee-status compensation; a bounded upper limit on
  `separation_date`; and a supported outcome for a zero-value final pay. Payroll's
  authoritative-date lookup must then exclude cancelled clearances
  (`PayrollCalculatorService::separationDate()` filters only `deleted_at`).
- Acceptance: no clearance can reach a state with no legal exit. Today three can:
  a duplicate-key checklist (fixed), an outstanding loan, and a zero-value payout.
- Verification: a full transition matrix, plus a test that every reachable state
  has at least one legal successor.

### 5. Checklist/history data repair and remaining evidence gaps

- Findings: M023-F007 (backfill), M023-F005
- Scope: medium
- Session recommendation: separate-recommended
- Change: measure and repair `employment_history` rows whose `to_value` was
  written double-encoded; validate the checklist setting at *write* time as well as
  at initiation; persist an immutable versioned final-pay snapshot with source IDs
  and the clamped-remainder figure named explicitly.
- Note: the clamped remainder is currently unrecorded — with `gross_plus` 0.00 and
  `gross_less` 99,999.00 the breakdown shows `net` 0.00 and nothing states that
  ₱99,999 went unrecovered.

### 6. Ownership matrix, maker-checker, notifications, legacy cleanup

- Findings: M023-F001, F002, F009, F013
- Scope: large
- Session recommendation: separate-recommended
- Unchanged from the original plan; still gated on the same ownership decision.
  Newly measured: a `department_head` can sign a `Finance` item (200), and one
  permission covers both compute and finalize.

### 7. Report to HR: last-administrator guard

- Finding: reachability confirmed, 1 → 0 active administrators
- Scope: small, **not ours** — `UserProvisioningService` is HR's file
- Session recommendation: separate, HR-owned
- The clearance listener is an unattended path to it, so the guard belongs in the
  service, not the listener.

### 8. SPA lifecycle polish

- Findings: M023-F012
- Scope: small-medium
- Session recommendation: separate-recommended (a live hook reformats `.tsx` files)
- `completed` should map to `success` per the design system; cancelled/blocked need
  states; server policy messages should survive to the operator.

## Loan-recovery options for R-002 — characterised, NOT implemented

The intended design is partly discoverable from the schema: `LoanPaymentType::FinalPay`
and `employee_loans.is_final_pay_deduction` both exist and are both dead. Whichever
option is chosen, `ClearanceLoanBlockTest` must be updated and the 422 text
corrected — it currently promises a step that does not exist.

**Option A — Deduct and settle automatically.** Final pay withholds the balance and
settles the loan; the recovery line already exists at `FinalPayService.php:246-248`.
- Consequence: the leaver's payout drops by the balance, possibly to zero. A loan
  larger than the payout leaves a residue with no collection mechanism (today the
  clamp silently absorbs it). Needs a written-off/receivable decision and a
  `LoanPayment` row of type `FinalPay` so the loan ledger reconciles.
- Cheapest to build; highest risk of quietly under-paying a leaver.

**Option B — Explicit operator confirmation (what the error text already promises).**
Add a `POST /clearances/{c}/loan-recovery` action recording an approved deduction,
consuming `is_final_pay_deduction`; finalize then requires every outstanding loan to
be either settled or flagged.
- Consequence: matches the existing message and schema, gives maker-checker a real
  artifact, and makes the deduction auditable. Costs one route, one migration-free
  flag write, and UI.
- Best fit to intent; moderate build.

**Option C — Require settlement outside final pay.** Keep the block, but add a
supported settle path plus a clearance "loan blocked" state so the separation is
parked rather than deadlocked.
- Consequence: never changes the payout, so no policy risk. But it leaves HR unable
  to close a separation until Finance acts, and it needs the state machine from
  item 4 to avoid the current dead end.

**Option D — Deduct up to a protected floor.** Recover only down to a statutory or
policy minimum take-home, carrying the remainder as a receivable.
- Consequence: most defensible for the employee and closest to Philippine practice,
  but needs a floor figure, a receivable account, and a collection process — the
  largest build.

Recommendation for the decision-maker: **B**, because the message, the enum and the
column were all written for it, and it changes no payout without an explicit,
recorded human approval. A does the same arithmetic but without the audit artifact.

## Recommended sequence

1. Answer the seven questions in `audit-report.md`.
2. Item 2 (double-pay remedies) — smallest diffs, largest correctness gain.
3. Item 1 (loan recovery) and item 3 (leave conversion).
4. Item 4 (lifecycle) — unblocks the remaining dead ends.
5. Items 5-8.
6. Re-run the focused HR suites on an isolated database, plus an authenticated
   Chromium journey for the SPA states.
