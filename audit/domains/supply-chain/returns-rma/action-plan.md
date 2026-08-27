# Return Management (RMA) — Action Plan

## Current re-audit plan

The plan is ordered by money/stock risk and workflow dependency. It remains `📋 Plan Ready`:
the unresolved work is predominantly separate-session work, and the first item requires a
product/finance decision. No production source fix is approved by this session's gate.

1. **Decide and implement customer credit/resolution semantics** — RMA-002, RMA-004
   `[large] [separate-recommended]`
   - Decide whether invoice-less SO/delivery and finance-only returns issue a credit note.
   - Implement the chosen provenance rule consistently in validation, service, and UI.
   - Implement/ref-link replacement and refund outcomes, or remove those choices and expose
     an explicit pending-human state. Add financial regression coverage.

2. **Enforce source-document lifecycle at the service boundary** — RMA-014
   `[medium] [separate-recommended]`
   - Recheck allowed invoice/SO/delivery/PO/GRN/bill statuses after hash-ID resolution,
     under the existing row locks. Add direct-API tests for draft/cancelled sources.

3. **Resolve the supplier incomplete-draft contract** — RMA-009
   `[medium] [separate-recommended]`
   - Either make missing source and provisional price explicit and non-reserving, or make
     source-complete creation mandatory and remove the contradictory service promise.
   - Cover API and SPA behavior at draft and submit boundaries.

4. **Align received quantity validation with decimal storage** — RMA-015
   `[small] [separate-recommended]`
   - Require exactly the supported three decimal places before the scale-3 comparison and
     persistence. Add a `1.0009` boundary regression test proving no rounded over-return.

5. **Make source options pageable/searchable** — RMA-016
   `[medium] [same-session-ok]`
   - Replace each fixed latest-100 query with bounded pagination/search and add a UI flow
     that preserves remaining-quantity and source-line provenance.

6. **Add regression coverage for the remaining boundary contracts** — RMA-002, RMA-004,
   RMA-009, RMA-014, RMA-015, RMA-016 `[medium] [separate-recommended]`
   - Cover invoice-less/finance-only credit decisions, inert resolution handling, supplier
     drafts, cancelled/draft source rejection, precision boundaries, and page boundaries.

7. **Completion gate** `[small] [same-session-ok]`
   - Mark M046 `✅ Verified` only after the decisions are recorded and the resulting focused
     suite, feature/RBAC checks, PHP lint, and SPA module checks pass against
     `DB_DATABASE=ogami_test_m046_roll_c`. The unrelated assets `qrcode` typecheck errors
     remain outside M046.

## Historical plan retained below

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
