# Goods Receiving Action Plan

Session: 2026-08-24  
Status: `Plan Ready`  
Recommendation: `separate-recommended`; the majority of findings affect QC state, stock, PO projections, financial valuation, or cross-module contracts. No fixes were applied in this audit session.

## Ordered fixes

1. **Close the terminal-QC invariant (GRN-01, GRN-06).** Require a persisted incoming inspection for every QC-eligible line before terminal single-screen acceptance; handle fractional quantities explicitly; reject an invalid/missing QC anchor instead of treating it as clear; and add regression tests for failed staging and fractional receipts. `Scope: large` · `Session: separate-recommended`.

2. **Enforce PO-line item identity (GRN-02).** Compare every submitted item ID with the locked PO line's item ID, use the canonical line item for downstream writes, and cover both FormRequest and single-screen paths with a negative test. `Scope: medium` · `Session: separate-recommended`.

3. **Separate physical receipt from QC acceptance in PO projections (GRN-04).** Introduce explicit received-pending-QC/accepted/rejected quantities or statuses, then update Purchasing, Quality, AP, MRP, supplier metrics, and closeout consumers to use the correct projection. `Scope: large` · `Session: separate-recommended`.

4. **Enforce usable destination locations (GRN-05).** Centralize a receiving-location invariant that rejects soft-deleted, inactive, blocked, and incompatible bins; apply it to create, draft finalization, and service-level calls; add API tests. `Scope: medium` · `Session: separate-recommended`.

5. **Define unit-cost authority and variance handling (GRN-03).** Decide whether receiving may override PO cost; if yes, add role/approval/variance rules and audit fields, otherwise remove the override and use the authoritative cost. Verify WAC and GL postings with decimal-safe tests. `Scope: medium` · `Session: separate-recommended`.

6. **Make traceability metadata a validated, Quality-aware contract (GRN-07, GRN-08).** Pass only validated fields, prohibit caller-set `coa_verified`, define upload validation/storage ownership, then add lot/expiry/moisture/COA fields to API resources, TypeScript types, create/detail UI, and end-to-end tests. `Scope: large` · `Session: separate-recommended`.

7. **Repair cross-module navigation and observability (GRN-09, GRN-10, GRN-11).** Correct the notification route, expose/link the incoming inspection, and expose the journal-entry relationship/status with permission-appropriate links. `Scope: medium` · `Session: separate-recommended`.

8. **Clarify partial draft semantics (policy question).** Add an explicit “unsubmitted lines remain expected” confirmation and line-level status, or enforce all-line completion if that is the intended policy. `Scope: small` · `Session: same-session-ok`.

## Verification gate

Before marking this module `Verified`, make the test database reachable and rerun the focused GRN suite. Add targeted tests for the first, second, fourth, and sixth items, then verify the full GRN → incoming QC → acceptance → stock → GL → AP chain and the notification recovery link.

---

## Re-audit 2026-09-01 — ordered plan

The 2026-08-24 plan is superseded for items 1, 2, 4, 6 and 7 (implemented
2026-08-25 and now verified by probe). Items 3 (GRN-04), 5 (GRN-03) and 8 (draft
policy) remain open and are re-listed below as carried-forward.

Recommendation: **fix in-session**. Four of the ten new findings are
`same-session-ok` at `small` scope, and one of them is a P0 that makes the
module's primary create endpoint return 500 on every request. The remaining six
are genuinely gated and are handed off, which is stated and justified rather than
attempted.

### Fix now (this session)

1. **GRN-R1 — P0: repair `StoreGrnRequest`'s location predicate.**
   `Rule::exists(...)->where('is_blocked', false)` serialises to `is_blocked=""`
   and PostgreSQL rejects it (`22P02`), so `POST /api/v1/inventory/grn` 500s on
   every request. Use a closure form (`->where(fn ($q) => $q->where('is_blocked', false))`)
   so the value is never string-serialised. Add the missing HTTP-level regression
   test for the create endpoint — its absence is why this survived six days.
   `Scope: small` · `Session: same-session-ok`.

2. **GRN-R4 — scope the `exists` rules to live rows.** Add
   `->whereNull('deleted_at')` to the `purchase_orders` and `items` predicates,
   matching the `warehouse_locations` one beside them. Turns two 500s into 422s.
   `Scope: small` · `Session: same-session-ok`.

3. **GRN-R2 — make the QC-coverage gate fail closed for an archived item.**
   `qcEligibleLineIds()` skips a line whose `item` relation is null, which a
   soft-deleted item makes true — measured as an outright acceptance (10.000 into
   stock, zero inspections) with a passing control. Resolve the item
   `withTrashed()` for the eligibility decision. Judged contained: it refuses
   input the gate already intends to refuse, and changes no valuation figure, no
   bill-matching quantity and nobody's authority to accept goods.
   `Scope: small` · `Session: same-session-ok`.

4. **GRN-R3 — align the three loose money/quantity rules on `StoreGrnRequest`.**
   `FinalizeGrnRequest.quantity_received`, the inline
   `receiveWithQc` rules, and `AcceptGrnRequest.item_accepted_map.*` (which has
   no per-value rule at all) all use bare `numeric` or nothing, giving 500s on
   `1e3`/`1e17`/`1e20` and silent rounding of `1.9999`→`2.000` and a
   `10.00005`→`10.0001` **unit cost** that feeds WAC and the GL. Replace with the
   `decimal:0,3` / `decimal:0,4` + bounded-`max` shape already proven on
   `StoreGrnRequest`. This only narrows accepted input.
   `Scope: small` · `Session: same-session-ok`.

### Hand off (gated — do NOT attempt in a receiving session)

5. **GRN-R5 — make an accepted GRN immutable.** Observer + PostgreSQL trigger on
   `goods_receipt_notes` / `grn_items` refusing quantity, cost, status-rewind and
   delete once `status` is terminal; and change `bills.goods_receipt_note_id`
   from `ON DELETE SET NULL` to `RESTRICT`. Follow the `journal-ledger`
   precedent (observer **plus** `P0001`). Gated: it defines what "final" means
   for a financial record, needs a migration, and the FK change reaches into
   Accounting. `Scope: medium` · `Session: separate-recommended`.

6. **GRN-R6 — give the partial-accept remainder a destination.** Either a
   `reject_remainder` transition from `partial_accepted` that reverses the PO
   line and closes the GRN, or an MRB/return-to-supplier hand-off for goods that
   never entered `stock_levels`. Crosses Inventory, Purchasing, MRB and
   ReturnManagement — MRB is a separate unaudited module. `Scope: large` ·
   `Session: separate-recommended`.

7. **GRN-R7 — widen the PO-line quantity scale (Purchasing).** Migrate
   `purchase_order_items.quantity` and `.quantity_received` to `numeric(15,3)`
   and the cast to `decimal:3`, matching `quantity_accepted` on the same table
   and `grn_items` on the other side. This is what actually closes the measured
   over-receipt breach (10.004 received against a 10.00 order at zero
   tolerance). Purchasing owns the column. `Scope: medium` ·
   `Session: separate-recommended`, **different module**.

8. **GRN-R10 — decide the fate of `/receive-goods`.** No SPA caller, and no
   seeded non-admin role can submit a terminal verdict through it. Retire the
   route + `grnApi.receiveGoods`, or grant one role both permissions and accept
   the self-certification that `7dd5e50d` deliberately closed. Needs a product
   decision, not a fix. `Scope: small` · `Session: separate-recommended`.

9. **GRN-R8 — archived-parent presentation.** Decide `withTrashed()` display vs
   `null` for the vendor/item behind an accepted receipt, then apply it
   consistently across `GoodsReceiptNoteResource` and `GrnItemResource`.
   `Scope: small` · `Session: separate-recommended` (presentation policy).

10. **GRN-R9 — `DocumentSequenceService` insert race.** Shared service in
    `Common`, already measured racing 4 of 8 callers elsewhere. Belongs to
    whoever owns `Common`; `grn` inherits it. **Not this module's to fix.**

### Carried forward from 2026-08-24 (still open)

11. **GRN-04** — separate physical receipt from QC acceptance in PO projections.
    `Scope: large` · `Session: separate-recommended`.
12. **GRN-03** — unit-cost override authority and variance policy. GRN-R3 stops
    the value being *malformed*; it does not decide who may override a PO price.
    `Scope: medium` · `Session: separate-recommended`.
13. **GRN-08 (remainder)** — COA upload/storage and Quality's verification
    transition. `coa_document_path` is still a bare string with no upload
    endpoint, no MIME validation and no serving controller.
    `Scope: large` · `Session: separate-recommended`.
14. **Draft-finalization policy** — omitted draft lines still mean "not received
    yet" implicitly. `Scope: small` · `Session: same-session-ok`, but it is a
    product confirmation, not a defect.

### Verification gate

Before `✅ Verified`: the focused GRN suite green (baseline 52), plus new HTTP
coverage for `POST /api/v1/inventory/grn` (absent today — the P0's root cause),
the archived-item QC gate, and the money-validation family on all three
surfaces.
