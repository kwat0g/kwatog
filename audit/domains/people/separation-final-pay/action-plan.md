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
