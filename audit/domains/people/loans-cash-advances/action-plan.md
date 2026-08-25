# M020 — Loans & Cash Advances action plan

Decision: **Plan Ready**. No production-code fixes in this audit session; the majority of work is financial-integrity, RBAC, state-machine, or cross-module and is therefore separate-recommended.

## Ordered implementation actions

1. **Canonicalize rate and money precision** — `[large] [separate-recommended] [F-001, F-006]`
   - Preserve annual rates such as 10.5% without two-decimal truncation; centralize fractional-rate and UI-percent conversion.
   - Validate/normalize all principals to two-decimal money strings before cap checks, schedule generation, and persistence.
   - Replace float-based salary-limit arithmetic with `Money`/BCMath string operations.
   - Acceptance: 10.5% persists and reconciles exactly; three-decimal input is rejected; cap-boundary tests pass.

2. **Enforce row-level RBAC for every loan decision** — `[large] [separate-recommended] [F-002]`
   - Create one visibility/decision policy for list, show, limits, approve, reject, and bulk approve.
   - Keep system-admin/finance global scope and department-head own-department scope; re-check inside locked mutations.
   - Acceptance: cross-department department-head reads and decisions return 403/empty scope; own-department and finance cases remain valid.

3. **Introduce an explicit loan state machine and write-off workflow** — `[large] [separate-recommended] [F-003]`
   - Define allowed transitions and distinguish cancellation, write-off, settlement, rejection, and paid states.
   - Require actor, reason, approval/SoD, audit event, and journal/reference handling for write-off.
   - Align the detail-page action with `loans.write_off`.
   - Acceptance: no positive-balance loan can silently leave payroll/final-pay collection; transition and permission tests cover every edge.

4. **Integrate final-pay recovery with the loan ledger** — `[large] [separate-recommended] [F-004]`
   - Add an idempotent final-pay confirmation/settlement path that creates `LoanPaymentType::FinalPay`, reconciles the loan, and records recoverable/unrecovered balance.
   - Coordinate clearance locking and balanced journal posting in one transaction with retry behavior.
   - Acceptance: final-pay deduction closes/reduces the loan exactly once and separation can finalize only after the ledger/journal agree.

5. **Make reconciliation and payroll reversal ledger-derived** — `[large] [separate-recommended] [F-007, F-008]`
   - Specify as-of dates and filter payment rows accordingly.
   - Derive remaining periods/amount from the schedule and ledger, not payment-row count.
   - Replace direct reversal arithmetic/deletion with auditable reversal entries and canonical reconciliation.
   - Add payment FK/reference integrity, amount/type constraints, immutable audit coverage, and a permissioned manual-payment API/UI.

6. **Repair the self-service preview contract** — `[medium] [separate-recommended] [F-005]`
   - Include `loan_type` in request/query state and choose one response shape for admin and self-service.
   - Add backend contract coverage and frontend render coverage for all supported rate types.

7. **Decide and complete government-loan scope** — `[medium] [separate-recommended] [F-010]`
   - If SSS/Pag-IBIG remain in scope, seed approval workflows, expose/reference government identifiers, and correct labels/rates.
   - If not, remove or explicitly de-scope their settings/types from user-facing behavior.

8. **Separate approval-step notifications from final decisions** — `[medium] [separate-recommended] [F-009]`
   - Emit a step-approved event for intermediate approvals and final decision notifications only on Active/Rejected transitions.
   - Add one-step and multi-step notification tests.

9. **Harden feature and preview boundaries** — `[medium] [separate-recommended] [F-013]`
   - Align HR self-service routes with the Loans feature gate.
   - Add a narrow throttle/cache to amortization preview and test feature-disabled behavior.

10. **Correct low-risk frontend semantics** — `[small] [same-session-ok] [F-011, F-012]`
    - Explicitly map rejected/cancelled statuses to danger/neutral variants.
    - Base progress on total due/balance and use the canonical rate formatter.
    - Add bounded/searchable employee selection and paginated self-service history where the API supports it.

11. **Reconcile documentation with the shipped policy** — `[small] [same-session-ok] [F-014]`
    - Update process flow, user manual, and browser-test role/policy examples after the government-loan and write-off decisions are settled.

## Open decisions

- Are SSS and Pag-IBIG loans part of the supported M020 product scope, or should they be removed from the active policy surface?
- Should an active cancellation mean a true write-off, a pre-disbursement cancellation only, or a settled/reversed loan?
- For final pay that cannot recover the full balance, should the residual remain collectible, be written off through an approval path, or block separation?
