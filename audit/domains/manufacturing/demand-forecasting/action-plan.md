# M048 — Demand Forecasting Action Plan

**Status:** 📋 Plan Ready  
**Audit report:** [audit-report.md](./audit-report.md)

The first implementation session should resolve the scope contract before changing presentation or metrics. Most high-value items are cross-module, data-semantic, operational, or audit-sensitive and are therefore recommended for separate sessions.

## Ordered actions

1. **Define and enforce forecast scope (M048-001, M048-002, M048-007).**
   - **Scope:** large
   - **Session recommendation:** separate-recommended
   - Decide whether total rows and customer rows are mutually exclusive, how “all customers” behaves, and which scope feeds MRP and accuracy. Update API filters, resource/UI labels, chart aggregation, manual override targeting, dashboard aggregation, MRP projection, and accuracy queries together. Add total/customer/zero-actual regression coverage.

2. **Make MRP failures diagnosable (M048-003).**
   - **Scope:** medium
   - **Session recommendation:** separate-recommended
   - Catch only the expected missing-BOM exception; preserve structured product-level errors for known BOM issues and surface unexpected failures. Add service and endpoint tests.

3. **Establish forecast freshness operations (M048-004).**
   - **Scope:** large
   - **Session recommendation:** separate-recommended
   - Define cadence and ownership, then schedule/queue batch recomputation with lock/retry/observability behavior and a freshness indicator. Include a runbook and failure alerting.

4. **Make forecast mutations and dependent reads observable in the SPA (M048-005).**
   - **Scope:** medium
   - **Session recommendation:** separate-recommended
   - Add mutation error/validation/conflict states, complete query invalidation, and explicit error states for all required settings/options/accuracy queries. Cover 422, 403, 5xx, and stale-data behavior.

5. **Define manual override audit semantics (M048-006).**
   - **Scope:** large
   - **Session recommendation:** separate-recommended
   - Require reason and actor, preserve before/after history, and specify how an override interacts with actual reconciliation and variance. Obtain finance/PPC approval for the policy and migration shape.

6. **Make list size and pagination honest (M048-008).**
   - **Scope:** medium
   - **Session recommendation:** separate-recommended
   - Preserve paginator metadata in the client, add table pagination or server-side filtering, and replace dashboard “all rows” summaries with bounded aggregates.

7. **Close or explicitly document the MRP projection workflow (M048-009).**
   - **Scope:** medium
   - **Session recommendation:** separate-recommended
   - Add a permission-aware review page/client for projection results, or document the endpoint as integration-only and remove any implication that the toggle alone completes MRP planning.

8. **Preserve context when creating purchase requests (M048-010).**
   - **Scope:** medium
   - **Session recommendation:** separate-recommended
   - Pass validated source item/quantity/date context to the purchase-request flow, prefill it, and retain a link back to the stock-out projection.

9. **Clarify stock-out result semantics (M048-011).**
   - **Scope:** small
   - **Session recommendation:** same-session-ok
   - Either filter the page to risk rows or rename it to all monitored items; explicitly label no-demand rows and align page/dashboard behavior. Add coverage for null days of stock.

10. **Improve Forecasting navigation (M048-012).**
    - **Scope:** small
    - **Session recommendation:** same-session-ok
    - Add a permission-filtered sidebar entry or role-appropriate workflow links after the route/page structure is confirmed.

11. **Complete table/chart accessibility (M048-013).**
    - **Scope:** small
    - **Session recommendation:** same-session-ok
    - Make sorting keyboard-operable with `aria-sort` and provide accessible chart data/labels.

12. **Enforce forecast invariants at the domain/storage boundary (M048-014).**
    - **Scope:** medium
    - **Session recommendation:** separate-recommended
    - Align database checks or strict domain types with the method catalog, period bounds, and confidence/quantity rules; cover direct service/job writes.

13. **Confirm row-level visibility policy (M048-015).**
    - **Scope:** large
    - **Session recommendation:** separate-recommended
    - Confirm whether viewer roles may read every customer-specific forecast. If not, define and test organization/customer ownership or role-based row filtering before adding broader navigation.

## Suggested delivery gates

- Do not enable MRP consumption changes until total/customer scope and duplicate-contribution tests pass.
- Do not call manual override complete until reason, actor, reconciliation behavior, and audit history are agreed.
- Do not call the SPA complete until failed writes are visible and paginated data is visibly bounded.
- Re-run the backend Forecasting suite with a resolvable database and the targeted SPA suite after implementation; the current session was environment-blocked for both.
