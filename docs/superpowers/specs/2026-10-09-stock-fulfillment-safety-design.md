# Stock production and fulfillment safety

**Status:** Implemented and rolled out. Verification evidence and remaining test limits are recorded in the implementation plan.

**Scope:** Correct the eight findings from the stock-production review. The original [stock production and fulfillment design](2026-10-09-stock-production-and-order-fulfillment-design.md) remains the product specification.

## Approach

Repair existing planning, cancellation, allocation, and delivery services. Keep the current user workflow and permissions. Apply automated changes only to records whose ownership and untouched state can be proved. Use ordinary cancellation and stock-release services to retain history and accounting behavior.

Manual cleanup for every failure would add the clicks the user wants removed. A new production or shipping workflow would duplicate existing behavior. Targeted corrections in the shared services are the recommended approach.

## Required behavior

### Accurate materials and purchasing

Manufactured subassemblies use integer piece targets. Calculate their descendants and raw-material requirements from the rounded target, including waste. A requirement for 0.5 manufactured piece becomes one piece before explosion; a child requiring 3 kg per piece consequently requires 3 kg, not 1.5 kg.

Physical stock, incoming purchases, open purchasing requests, and QC-held receipts remain separate. Incoming supply reduces purchasing demand but never authorizes material issue or production Start. Net quantities in base units, exclude cancelled/declined/archived purchasing, and avoid counting a converted request and its purchase order twice. Use existing safety-stock and minimum-order-quantity rules.

Incoming purchasing assigned to another Sales Order or stock job remains committed to its owner. Existing stock-linked purchasing must stop appearing as general unplanned supply to MRP. Distribute genuinely uncommitted incoming supply once across competing material requirements using existing persisted work-order/material/request/order relationships and deterministic ordering. Recompute under the existing planning lock; do not rely on a per-request cache to protect separate runs.

For two jobs needing 3 kg each and only one uncommitted incoming 3 kg purchase, additional purchasing totals 3 kg. Replaying either plan must not create another shortage request or erase the other job's demand. Editing an untouched draft request must exclude that request's prior lines from the supply available to itself. Approved/converted purchasing and linked RFQs/POs are not silently edited or cancelled; changed requirements become a named procurement blocker.

### Safe cancellation

Cancelling a stock root cancels its untouched automatic descendants and untouched automatic draft purchasing requests in the same transaction. Preserve rows, source links, and audit history. Reuse normal cancellation to release material/equipment/schedule reservations.

If a descendant has an assignment, issued materials, output, or progressed status, or purchasing has progressed beyond an untouched draft, block automatic parent cancellation and identify the records requiring their existing review process. Preflight the entire dependency tree before any mutation. Failure must leave every dependent and reservation unchanged. Do not change ordinary order-production, repair, or purchasing cancellation rules globally.

Cancelling a Sales Order may automatically retire its own scheduled, unassigned, undispatched delivery when `automatic_supply_key` proves ownership. Keep manually created, assigned, loading, dispatched, invoiced, or received deliveries protected. Retire eligible deliveries before the existing downstream-state guard, within one rollback-capable transaction. Repeating cancellation must not release a hold twice.

### Current physical readiness

Allocation evidence includes current product/item mapping, reviewed QC, specification compatibility, resolved receipt posting, expiry, exact lot, and the current warehouse bin. A blocked/inactive bin, inactive warehouse, quarantine/scrap location, or missing physical lot cannot contribute ready quantity or authorize departure.

Held stock is not free stock: readiness checks must add back only the current allocation or shipment's own durable hold when validating its physical quantity. Other orders' holds stay protected. A normal allocation of 10 pieces remains ready when its own reservation uses all 10 pieces. Stock already dispatched remains part of delivery history; a later bin closure does not reverse its issue, receipt, invoice, or paid state.

Load every relationship consumed by validation before iterating allocations. Multiple source lots must work with Laravel lazy loading disabled; production must not depend on hidden per-allocation relationship queries.

### Recovery after QC revocation

On planning replay, reconcile invalid source allocations before calculating remaining delivery demand. Cancel an affected untouched automatic delivery through its existing cancellation service, release only its unconsumed order/shipment holds, then allocate approved replacement supply and draft one valid shipment. Preserve the failed inspection and original cancelled delivery as history. Unaffected sources may be released and reacquired transactionally when their containing automatic shipment is rebuilt; other orders' reservations are never released.

Assigned or progressed shipments keep their contents and require ImpEx/Quality review. They receive a named blocker and cannot depart with revoked evidence. Recovery does not silently substitute a lot in an assigned shipment. QC quarantine remains effective after releasing an order reservation. Retries and concurrent planning create at most one active replacement shipment and consume no stock before dispatch.

### Product readiness in the form

Add product readiness to the existing production form-options response. Preserve its current IDs and fields. Show eligible products normally; show disabled products with explicit reasons: missing/ambiguous finished-good mapping, non-piece unit, missing/invalid active BOM, or missing active inspection specification. Do not invent mappings, BOMs, or inspection limits.

The server rechecks on submission. Equipment, crew, materials, and deadline feasibility still belong to the existing combined planning review. Network failure preserves the draft and idempotency key. No extra confirmation buttons or separate proof-upload workflow.

## Evidence and acceptance

The review's six business-flow probes produced five failed assertions and one expected-behavior exception, with 20 assertions. The reproduction file is `/tmp/kwatog-finish-first-20261008/api/tests/Feature/CRM/StockFulfillmentRiskReviewTest.php`; XML is `/tmp/stock-production-20261009/stock-risk-review.xml`. Move these cases into durable tests, replacing reflection and simulated production settings with normal fixtures. Independently reproduce the multi-allocation lazy-loading failure.

Acceptance requires the six repro cases and all new ownership, cancellation, concurrency, and UI cases to pass, with lazy loading enabled. Verify approved stock, production shortage, driver proof, customer receipt, replacement accounting, and payment verification through the existing connected tests. Run a representative stock-only and mixed order lifecycle in an isolated environment and browser checks at 1440 px and 390 px. Never post artificial business fixtures to production.

## Constraints and rollout

No new dependency, broad permission, production workflow, purchasing approval, or fake QC result. Keep quantity precision and existing money helpers. Preserve source history and additive allocation schema. No schema change is planned; deriving committed supply from existing persisted relationships must be proved with separate-process tests before that choice is accepted.

Do not automatically clean up historical orphaned work, purchasing, or shipments during rollout. Report affected IDs read-only and apply corrections only through the approved operating flows. Preserve existing unrelated workspace changes and compare representative historical order/delivery/invoice/payment rows before and after rollout.
