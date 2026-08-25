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
