# M054 — material-review-board action plan

Date: 2026-08-25  
Status: 🔁 Needs Re-audit  
Overall recommendation: partial implementation completed; re-audit after the deferred policy and cross-module items are resolved.

This existing plan was resumed in a dedicated session. Contained fixes were implemented and regression-tested; the highest-risk work that changes ownership of held stock, Quality disposition policy, permissions, or supplier/accounting handoff remains deferred.

## Ordered fixes

### 1. M054-F001 — Enforce location and warehouse invariants at the service boundary

- Classification/severity: Broken, P1
- Scope: medium; location policy, request/service validation, active-zone filtering, tests
- Recommendation: separate session
- Require active locations, a quarantine zone for hold destinations, a good non-quarantine/non-scrap zone for rework/use-as-is targets, and the same warehouse for every movement. Validate source location activity and zone as well; do not rely on the SPA tree.
- Acceptance: forged API payloads for inactive, wrong-zone, missing-zone, or cross-warehouse locations fail before movement; the SPA lists only valid active choices; same-warehouse behavior remains covered.

### 2. M054-F002 — Make held stock exclusive to the MRB release path

- Classification/severity: Broken, P1
- Scope: large; StockMovement/TransferOrder/StockAdjustment coordination, ownership checks, migration/backfill if needed, concurrency tests
- Recommendation: separate session
- Decide whether every movement from a quarantine location must reference the owning held MRB. Enforce that generic transfer/adjustment paths cannot consume or move held stock, or add an explicit authorized exception with an MRB reconciliation event. Reconcile already-held rows before enabling the guard.
- Acceptance: generic transfer/adjustment from a held quarantine location is denied; only MRB release can change the held quantity; a concurrent release versus exception has deterministic locking and a visible audit trail.

### 3. M054-F003 — Add rework verification and use-as-is concession evidence

- Classification/severity: Broken, P1
- Scope: large; Quality state/inspection handoff, concession approval, evidence model, release policy, tests
- Recommendation: separate session
- Define the required gate for each disposition. Rework should link to a rework action/order and a passed reinspection before good-stock release; use-as-is should require an authorized concession/sign-off and any customer evidence required by policy. Do not collapse both into one transfer branch.
- Acceptance: an unverified rework or unsigned use-as-is release cannot move stock to a good zone; the MRB resource shows the evidence, actor, and result; repeat/retry behavior is idempotent.

### 4. M054-F004 — Complete supplier-return provenance and handoff

- Classification/severity: Incomplete, P1
- Scope: large; vendor/PO/GRN/bill lineage, purchasing notification/dispatch, accounting reconciliation, portal/UI contract
- Recommendation: separate session
- Decide whether M054 owns the supplier-return document or hands off to Purchasing. Persist the supplier/source receipt identity and authoritative quantity; create or link the return/credit process; keep the MRB in an explicit `pending_supplier_return`/manual state until the external handoff is acknowledged, if that is the policy.
- Acceptance: a returned MRB identifies supplier, source receipt, quantity, movement, purchasing owner, and accounting outcome; a failed notification/handoff is retryable and does not present `returned` as complete.

### 5. M054-F005 — Validate and expose authoritative Quality linkage

- Classification/severity: Broken, P1
- Scope: medium/large; item/product/entity/status/quantity validation, lookup endpoints, inspection UI/resource, tests
- Recommendation: separate session
- Require linked NCR/inspection records to match the held material and permitted lifecycle state, or make the manual/no-link exception explicit with a reason. Add an item/inspection-scoped lookup and show inspection number, stage, status, and NCR state on the MRB detail page.
- Acceptance: unrelated, passed/closed/cancelled, or quantity-inconsistent links fail with a clear 4xx; a valid failed inspection/NCR is selectable and visible end-to-end; no first-100/all-record lookup is required for correctness.

### 6. M054-F006 — Add idempotency to hold commands

- Classification/severity: Incomplete, P1
- Scope: medium; command key/fingerprint persistence, API contract, SPA retry behavior, concurrency tests
- Recommendation: separate session
- Accept a bounded idempotency key, fingerprint the normalized item/quantity/source/destination/evidence payload and actor, and return the original MRB for an identical replay. Reject reuse with a different payload while preserving legitimate separate holds under different keys.
- Acceptance: timeout/retry creates one MRB and one hold movement; concurrent identical requests converge; changed-payload key reuse is rejected; the audit record names the original command.

### 7. M054-F009 — Define the disposition segregation-of-duties matrix

- Classification/severity: Incomplete, P1
- Scope: medium; permission/RBAC policy, approval records, thresholds, UI action gates, negative tests
- Recommendation: separate session
- Confirm whether warehouse and QC may both release every disposition. If not, split hold/release permissions and require an independent approver for scrap, return-to-supplier, high-value rework, and use-as-is concessions. Keep the policy server-side and actor-specific.
- Acceptance: each disposition has an explicit actor/approval matrix; unauthorized and self-approval attempts fail; the MRB timeline records decision actor, approver, evidence, and final movement.

### 8. M054-F007 — Make MRB search and filtering real

- Classification/severity: Broken, P2
- Scope: small; controller validation, service query, search tests
- Recommendation: same-session-ok
- Validate `search`, status, item, and page size at the controller; implement ticket/item/NCR/location matching in the service and preserve URL filter state.
- Acceptance: searching an MRB number returns only matching records, empty results are explicit, malformed page sizes return 422 rather than an internal error, and the API/UI contract is covered.

### 9. M054-F008 — Align quantity precision and lookup recovery

- Classification/severity: Polish, P2
- Scope: small; shared decimal rule, form validation, paged/searchable item/NCR lookups, loading/error states
- Recommendation: same-session-ok
- Set one three-decimal rule in the form and API. Replace bounded raw selects with searchable/scoped lookups, or provide a clear recovery path when item/NCR/warehouse queries fail or exceed the page limit.
- Acceptance: the form rejects a fourth decimal before submission, every valid active item and relevant quality record is reachable, and lookup failures tell the operator what to retry.

## Execution status for the resumed plan

Implemented and targeted-verified in this session:

- F001: service-boundary active/zone/warehouse validation for source, quarantine, and release locations; active same-warehouse SPA choices; regression coverage for inactive and cross-warehouse payloads.
- F005: item-scoped failed-inspection and open/in-progress NCR lookup; item/status/quantity/event consistency checks; inspection/NCR status detail in the resource and SPA.
- F006: bounded `Idempotency-Key`, actor-scoped payload fingerprint, durable migration fields, replay-safe hold command, and SPA retry key reuse.
- F007: validated search/status/page filters and MRB/item/NCR/location search, with URL filter state passed to the filter bar.
- F008: three-decimal form validation, searchable item/quality lookups, active-location filtering, and lookup retry states.

Deferred for a real in-session constraint, not because of the original risk label:

- F002: the ownership guard crosses shared StockMovement, transfer-order, adjustment, and Return Management paths. RMA quarantine movements are a valid existing exception, but there is no authoritative policy for the exception/reconciliation boundary; changing those dependencies would violate this module-scoped session.
- F003: the repository has no authoritative MRB rework evidence, reinspection gate, or use-as-is concession approval schema. A human decision is required before adding a release gate or inventing evidence fields.
- F004: supplier/PO/GRN/bill provenance and Purchasing/Accounting handoff ownership are unspecified. A terminal returned state cannot be safely replaced or connected without that cross-module contract.
- F009: segregation-of-duties thresholds and approval ownership are unspecified, and the required RBAC/seeder changes are outside this module’s scope.

The module is released as 🔁 Needs Re-audit. Re-audit the implemented findings after the deferred policy decisions are made; do not mark the module verified while F002/F003/F004/F009 remain open.

## Definition of done for the next implementation tranche

- Held stock has one authoritative owner and cannot bypass MRB release through generic inventory mutations.
- Every release disposition has the required Quality, concession, supplier, and approval evidence before stock/GL changes.
- Locations, source quantities, and quality links are validated server-side and reflected in the UI.
- Replayed holds are idempotent, MRB search works, and the operator can recover from lookup/API failures.
