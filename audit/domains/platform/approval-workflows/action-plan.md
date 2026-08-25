# M005 — Approval workflows action plan

Audit session: 2026-08-24  
Disposition: `📋 Plan Ready`

The focused approval paths pass, but the open findings are majority `separate-recommended`. No production-code fixes were applied in this session.

## Ordered plan

1. `[large][separate-recommended]` Define the approval-attempt contract. Add a current-attempt/workflow-version identity to approval records or implement a single authoritative current-attempt query. Update `isFullyApproved`, `isRejected`, `chain`, board history, and printable signatures. Add rejected→resubmitted→approved and concurrent-submit regressions before changing downstream status transitions.

2. `[medium][separate-recommended]` Make the board and dashboard worklist delegation-aware. Reuse the same active delegation/role-change rules as `ApprovalService`, and add tests proving a delegate sees, acts on, and loses access to the delegated queue when the window expires or the delegator changes role.

3. `[large][separate-recommended]` Stamp workflow identity/version at submission and use it for escalation policy lookup. Add an ambiguity test with two workflow definitions sharing `step_order`/`role_slug`; verify the selected auto-resolve action and SLA belong to the submitted workflow.

4. `[medium][separate-recommended]` Decide the board’s visibility policy for financial amounts, requester identity, remarks, actor identity, and links. Implement per-module/department scope or intentional masking as required, then cover employee, department-head, finance, HR, and system-admin responses.

5. `[medium][separate-recommended]` Bound board and scheduler work. Add explicit pending/history limits or pagination, batch source rows per type, remove actioned-card N+1 queries, and convert reminder/escalation/auto-resolve scans to chunked or claimed work with retry/resume semantics. Add a large-volume query-count and memory regression.

6. `[small][separate-recommended]` Replace the escalation link map with the authoritative supported-type registry. Cover `EmployeeLoan`, `PayrollPeriod`, leave, PR, PO, and return links; keep unknown types on a deliberate safe fallback rather than concatenating a hash to an audit-log path.

7. `[medium][separate-recommended]` Reconcile seeded workflow definitions with actual module ownership. Either wire the reserved definitions into their modules and board type map, or add an explicit reserved/inactive lifecycle and exclude them from runtime policy lookup. Resolve the payroll definition mismatch with the payroll maker-checker flow.

8. `[small][separate-recommended]` Make automatic decisions auditable. Require a valid active automation principal or introduce a first-class system actor, and test missing/inactive/multiple actor-role configurations.

9. `[small][same-session-ok]` Improve SPA options failure handling, keep displayed counts consistent with returned card limits, add a history pagination affordance, and use terminal-action wording for actioned cards. This is safe in isolation but was not applied because the overall plan is not majority same-session-safe.

## Re-audit entry criteria

Re-audit M005 after the attempt/version decision, delegation-aware board behavior, workflow-identity policy lookup, visibility policy, and bounded query design are implemented with focused regression coverage. The module can close only when automatic decisions have deterministic workflow attribution and actor attribution, and the board’s cross-module data contract is explicit.
