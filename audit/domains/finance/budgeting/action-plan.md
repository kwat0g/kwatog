# M030 — budgeting action plan

Audit date: 2026-08-24  
Disposition: Plan Ready

No production source fixes were applied. The central budget-consumption
source, lifecycle authority, and actuals/report consistency are not safe to
patch as an isolated same-session bundle.

## Ordered work

1. **Establish the canonical consumption source — F-001**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Decide whether posted GL movement and approved commitments are derived on
     read or maintained as transactional aggregates. Map account, department,
     budget, PO, bill, JE, reversal, and cancellation ownership. Backfill
     existing headers and prove the result under concurrent posting/approval.

2. **Implement the budget state machine and SoD policy — F-002**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Define draft → submitted → active/approved → closed, reject every other
     transition, reload and lock the budget inside each transition, and decide
     whether the submitter may approve. Align enum labels, scopes, route
     permissions, audit evidence, and fiscal-year status/date rules.

3. **Make actuals and variance one authoritative rebuild — F-003, F-004**
   - Scope: [medium/large]
   - Session recommendation: [separate-recommended]
   - Update or derive variance with actual_total, replace the per-line query
     loop with a bounded aggregate strategy, and define snapshot/locking
     behavior while journals post. Add a durable run ledger with completion,
     failure, retry, and last-success evidence.

4. **Validate sync targets before durable dispatch — F-005**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Share one fiscal-year decode/validation contract between the API and
     Artisan command. Return actionable 422/command failures instead of
     staging a doomed event or silently selecting the active year.

5. **Close budget-line and fiscal-year invariants — F-006, F-007**
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Reuse M025's account eligibility decision, reject duplicates and invalid
     account types, enforce precision/constraints, and define a date-window
     current fiscal-year query. Add migration and interleaving tests before
     changing live data.

6. **Align the SPA with server-side report lifecycle — F-008, F-009**
   - Scope: [medium]
   - Session recommendation: [same-session-ok]
   - Add fiscal-year and department filters, real pagination/report endpoints,
     complete draft editing or remove the partial update contract, and show
     sync status rather than treating queue acceptance as completion.

7. **Normalize the public contract — F-010**
   - Scope: [medium]
   - Session recommendation: [same-session-ok]
   - Hash foreign keys consistently, choose a documented decimal money wire
     format, and update TypeScript types plus API contract tests.

8. **Build the M030 acceptance suite**
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Cover JE and PO/bill consumption, header/line reconciliation, all valid
     and invalid budget transitions, self-approval, stale transition races,
     actuals variance, job timeout/retry status, invalid sync targets,
     duplicate/ineligible accounts, non-calendar fiscal years, pagination,
     draft editing, and public identifier/money contracts.

## Re-audit acceptance gates

- A posted journal movement or approved commitment changes the same
  authoritative consumption amount used by enforcement, overview, dashboard,
  and budget-vs-actual reports.
- No invalid or stale budget transition can alter a financial-state row, and
  maker-checker policy is enforced server-side and tested under concurrency.
- Actuals rebuild updates or derives line variance atomically, scales to the
  expected line count, and exposes durable completion/failure state.
- Invalid fiscal-year targets fail before dispatch; omitted targets resolve
  only to a date-window-valid active year.
- Duplicate, inactive, non-leaf, and over-precision line inputs are rejected
  consistently, with database invariants preventing bypass.
- SPA reports identify the fiscal year, honor filters/pagination, show sync
  state, and provide a complete draft correction path.
- Public budget identifiers and monetary values follow one documented API
  contract.

## Session decision

Do not apply fixes in this audit session. F-001/F-002 are control failures,
F-003/F-004/F-005 affect financial data and asynchronous recovery, and F-006
depends on the M025 account-state policy. The majority of findings are
large/medium cross-module or financial-state changes; keep M030 at Plan Ready
and schedule an implementation pass followed by a fresh audit.
