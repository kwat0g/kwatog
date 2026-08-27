# M005 — Approval workflows action plan

Audit session: 2026-08-27
Claimed module: `platform/approval-workflows`
Disposition: `📋 Plan Ready`

The focused normal paths are green, but the material findings are dominated by
cross-module authorization/state contracts and scheduler concurrency. No product-code fix
is safe to land in this session without first choosing those policies.

## Ordered actions

1. `[large][separate-recommended]` Resolve R-02. Define one authoritative reachable-step
   predicate for a workflow. Apply it to reminders, escalations, and auto-resolution so
   only the next pending step is processed. Add a real two-step Leave/PR regression proving
   future steps do not receive SLA stamps or decisions.

2. `[large][separate-recommended]` Resolve R-03. Design the domain-aware auto-resolution
   contract. Either route approve/reject through idempotent consumer lifecycle adapters with
   outbox/events, or keep automatic decisions disabled until every active consumer supports
   that contract. Prove source status, side effects, and audit attribution for Leave, Loan,
   Purchasing, SalaryAdjustment, and ReturnManagement.

3. `[medium][separate-recommended]` Resolve R-04. Lock and re-read the approval row inside
   the auto-decision transaction and require a conditional current-pending update. Add a
   two-connection race test against human approve/reject.

4. `[large][separate-recommended]` Resolve R-05. Choose the board's visibility model for
   `awaiting_others` and recent history: per-module row scope, company-wide masked metadata,
   or another explicit policy. Enforce it through per-type adapters, including Leave,
   Loans, Purchasing, Payroll, and delegated users. Add employee/department-head/finance/
   HR/admin privacy assertions.

5. `[medium][separate-recommended]` Resolve R-06. Make delegated authority agree with
   entity route middleware and row policies. Decide whether delegation grants temporary
   module action permission or whether the board must hide cards that cannot be actioned;
   provide a usable link/data contract. Add HTTP approve/reject tests for active, expired,
   revoked, role-changed, and self-submitting delegates.

6. `[medium][separate-recommended]` Resolve R-07. Decide between explicit assignment and
   role-audience notification. If role-based, resolve all active direct approvers and
   active delegates, deduplicate recipients, and test reminder/escalation recipient sets.

7. `[medium][separate-recommended]` Resolve R-01. Extend the authoritative approval type
   registry for ReturnManagement (and any other approved live consumer), then align source
   table loading, row policy, controller validation, SPA types/routes, and escalation links.
   Add a real return-request board and notification-link regression.

8. `[medium][separate-recommended]` Resolve R-08. Decide whether PayrollPeriod belongs on
   the shared approval board. Remove the phantom kind or implement an adapter for its
   custom maker-checker lifecycle; ensure options and history match the decision.

9. `[small][separate-recommended]` Resolve R-09. Update `docs/SCHEMA.md` and the approval
   pattern/audit docs with active workflow state, attempt/current identity, snapshot/version,
   SLA fields, and delegation authority after the implementation contracts settle.

10. `[medium][separate-recommended]` Resolve R-10. Add regression coverage for the chosen
    implementations: active-step scheduler behavior, source lifecycle, two-connection
    race, board row scope, delegated HTTP actions, recipient sets, registry parity, and
    Payroll's chosen adapter/removal. Keep tests on a unique database per audit session.

11. `[small][same-session-ok]` Resolve R-11. Pass an action/view mode into the SPA active
    card so “Awaiting others” says “Open record to view” while “My action required” keeps
    action wording. Add a focused rendering assertion. Deferred this session because the
    overall plan is not majority same-session-safe.

## Session decision

No source or test fix was implemented. The only safe same-session item is the isolated SPA
CTA polish, and it is subordinate to the unresolved board authorization and route contract.
The inherited Purchasing F-011 remains deferred to that module's business-decision path and
was not modified.

## Re-audit entry criteria

Re-audit M005 after the reachable-step, auto-resolution lifecycle/race, board visibility,
delegation route, recipient, and registry/payroll decisions have landed with focused
regressions. Close only when every active approval producer has an intentional board/link
contract, automated decisions update domain state atomically, and the board cannot expose a
row or offer an action that its caller cannot legitimately access.
