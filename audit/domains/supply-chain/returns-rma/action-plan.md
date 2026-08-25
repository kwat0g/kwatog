# Return Management (RMA) — Action Plan

The plan is ordered by workflow dependency and risk. This first-pass audit contains multiple financial, stock, Quality, lifecycle, and cross-module items, so the module remains `📋 Plan Ready`; no production source fixes are being rushed into this audit session.

1. **Fix customer source-kind resolution and add source-path regression coverage.**
   - Findings: RMA-001.
   - Scope: medium.
   - Session recommendation: separate-recommended.
   - Preserve associative source kinds, test invoice/SO/delivery branches, and assert authoritative unit price, source allocation, product matching, and rejection of multiple source IDs. Include a concurrent/remaining-quantity case.

2. **Restore the product/source/item contract for normal customer returns.**
   - Findings: RMA-003.
   - Scope: medium.
   - Session recommendation: separate-recommended.
   - Make the SPA carry the selected source product and derive/validate the intended inventory identity; make the API reject mismatched or ambiguous product/item/source combinations. Add an end-to-end test proving a source-backed product return stages Quality and cannot mark the handoff `not_required` merely because the browser omitted `product_id`.

3. **Decide and implement the customer resolution/credit policy.**
   - Findings: RMA-002 and RMA-004.
   - Scope: large.
   - Session recommendation: separate-recommended.
   - Choose whether SO/delivery returns can create credits without a root invoice. Then implement the selected policy consistently in validation, source resolution, credit-note provenance, and UI. Either implement/ref-link customer replacement work orders and refund settlement, or remove/defer those resolution choices and expose a clear pending-human outcome. Add financial tests for each supported resolution.

4. **Harden source reservations and lifecycle persistence.**
   - Findings: RMA-005 and RMA-006.
   - Scope: medium.
   - Session recommendation: separate-recommended.
   - Recheck an active allocation against the locked source limit on submit/receipt, add database checks for allocation quantity/unit price/source kind, and add the allowed `return_requests.status` constraint. Test stale source reductions, cancellation/rejection release, completed consumption, and invalid direct writes.

5. **Make disposition completeness a service invariant and remove float quantity decisions.**
   - Findings: RMA-008 and RMA-011.
   - Scope: medium.
   - Session recommendation: separate-recommended.
   - Require the service-level disposition map to cover every RMA line exactly once before any side effect. Replace float/int quantity conversions with decimal-safe calculations and add fractional receipt/PO/Quality tests.

6. **Apply the `return_management` feature flag consistently.**
   - Findings: RMA-007.
   - Scope: small.
   - Session recommendation: same-session-ok.
   - Add `feature:return_management` to the API route group and a `ModuleGuard module="return_management"` around the SPA RMA routes. Add API 403 and browser-disabled-state coverage; keep permission checks nested inside the feature boundary.

7. **Align the supplier draft contract across API and SPA.**
   - Findings: RMA-009.
   - Scope: medium.
   - Session recommendation: separate-recommended.
   - Either support a truly incomplete supplier draft with nullable/clearly provisional price and no source reservation, or require source-complete creation and remove the incomplete-draft service promise. Ensure source selection populates the authoritative display fields and submit remains the strict boundary.

8. **Expose remaining source quantity and allocation traceability.**
   - Findings: RMA-010.
   - Scope: medium.
   - Session recommendation: same-session-ok.
   - Add remaining quantity to source options, make the UI label the actual reservable amount, paginate or filter source documents safely, and expose per-line allocation history/active reservation in the RMA detail response.

9. **Restore a green SPA verification gate.**
   - Findings: RMA-012.
   - Scope: small.
   - Session recommendation: same-session-ok.
   - Remove the duplicate `minLength` prop, run the module page lint/typecheck, and keep the unrelated assets QR-code errors tracked separately.

10. **Unify manual-handoff retry controls by permission.**
    - Findings: RMA-013.
    - Scope: small.
    - Session recommendation: same-session-ok.
    - Use the inspect permission for both retry placements, prevent duplicate mutation affordances, and verify the QC-inspector and manager views.

11. **Refresh the module regression suite after the contract changes.**
    - Findings: verification gap supporting RMA-001 through RMA-011.
    - Scope: medium.
    - Session recommendation: separate-recommended.
    - Update fixtures for the finance-only disposition matrix and immutable Quality-spec revisions, then add missing tests for ordinary source-backed customer creation, feature-disabled routes, SO/delivery credits, replacement/refund outcomes, stale allocations, service-level partial disposition, and fractional quantities. Run the suite against an isolated test database before verification.

## Completion gate

Mark M046 `✅ Verified` only after all items above are either implemented with targeted regression proof or explicitly resolved by a recorded product decision. Re-run the focused Return Management suite, the SPA typecheck/lint, and the feature/RBAC checks; do not count the current dirty shared-database run as verification.
