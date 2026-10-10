# Stock production and Sales Order fulfillment

**Status:** Approved and implemented on 2026-10-09. See [rollout evidence and operating notes](../../stock-production-rollout-2026-10-09.md).

**Decision confirmed by the user:** New Sales Orders use approved stock first and produce the shortage. Authorized PPC users can choose fresh production instead.

## Outcome

Production can replenish finished goods without a customer order. PPC receives automatic machine, mold, schedule, and worker suggestions, reviews them together, and confirms once. The ordinary warehouse, production, process-quality, final-QC, and costing lifecycle still applies.

New Sales Orders reserve eligible finished goods automatically and manufacture only their uncovered quantity. Stock fulfillment remains traceable to the actual work order, output batch, warehouse lot, and reviewed inspection that produced it. Reusing stock satisfies production and quality coverage without creating fictitious production or resetting historical QC results.

## Evidence from the current repository

| Area | Existing capability | Gap to close |
|---|---|---|
| Work-order creation | `StoreWorkOrderRequest` accepts a nullable Sales Order; `WorkOrderService::createDraft()` builds direct BOM materials | Work-order list hides creation; creation form lacks the combined automatic planning flow |
| Resource planning | `CapacityPlanningService::run()`, assignment proposal and atomic apply, shift/leave/overlap checks | Expose the same proposal for stock production with an internal completion deadline |
| Finished-goods receipt | Output-bound production receipts and QC stock holds | Surface approved, held, reserved, and incoming production separately |
| MRP | Nets material and manufactured-subassembly supply, preserves progressed commitments | Top-level finished goods are not deducted from Sales Order production demand |
| Delivery | Reviewed output inspection, physical lot/bin reservation, departure revalidation | Both `DeliveryService` and `DeliveryStockService` require the source WO to belong to the destination Sales Order |
| Chain | Derived production, QC, delivery, billing, payment stages | Production/QC derivation primarily selects WOs belonging to the order |
| Product identity | `Product::inventoryItem()` maps part number to inventory item code | Missing, archived, ambiguous, or unsuitable mappings must block stock promises |

No automatic stock replenishment scheduling or order-specific stock allocation ledger currently closes these gaps end to end.

## Approach and alternatives

**Recommended:** Extend ordinary work orders and existing planning/reservation services; add explicit order-to-output allocations. This supports stock, fresh production, and mixed fulfillment with one quantity model.

**Rejected:** Invent internal Sales Orders to create stock. This pollutes customer demand, delivery, billing, and chain history.

**Deferred:** Demand forecasting and autonomous min/max replenishment. Useful later, but it does not replace the allocation and quality rules needed now. Manual stock production with automatic planning is the first release.

## Stock-production flow

1. PPC Head or Production Manager opens **Work orders**, then **Produce for stock**. Existing permission `production.wo.create` governs creation; assignment and confirmation keep their current permissions.
2. Enter product, positive whole-piece quantity, **Stock needed by**, optional preferred production day, and worker range from 1 to 4. Quantity means new pieces to produce, not a desired stock balance. Show current approved free stock and already planned stock production beside it.
3. **Plan production** saves an idempotent Planned stock WO and generates the existing capacity/crew proposal. No proposal generation occurs on typing, focus, or a polling timer. Material and product readiness errors appear inline before promising capacity.
4. Review one summary: product/quantity, BOM and material shortages, machine/mold, estimated start/finish, worker names/recorder, and conflicts. **Confirm production plan** atomically assigns and confirms when guards pass. A blocker keeps the WO Planned with one recovery action and the responsible team named.
5. Warehouse prepares/issues actual materials. Production checks actual readiness and starts the job. Assigned workers record output. Existing trial/process QC rules and independent outgoing inspection remain mandatory.
6. QC-approved output becomes eligible finished-goods stock. Pending/failed/held portions remain unavailable. No customer delivery or invoice is created merely because an internal WO finishes.

The stock deadline uses Asia/Manila and the existing 12-hour display. It is an internal stock-ready deadline, not a fabricated customer delivery date. No customer production-to-delivery buffer is applied to this internal deadline; ordinary customer orders retain their existing buffer.

### Materials and manufactured subassemblies

Use `BomService::productionPlan()` for stock-production roots as well as ordinary orders. Direct requirements stay on the parent. Free approved manufactured subassemblies can cover parent demand; remaining subassemblies get real child WOs and their own production/QC evidence.

An internal root does not receive a fake `MrpPlan` linked to a Sales Order. A small `StockProductionPlanningService` owns reconciliation for that root, reuses existing BOM requirements and WO creation, and links generated material-shortage PRs by a nullable root WO reference. Only untouched automatic draft PRs and unstarted child WOs can be reconciled. Purchasing approval, budget checks, receipt/QC, and warehouse issue remain unchanged. A PO is expected material supply, never finished goods available for immediate shipment.

## Sales Order fulfillment

For each order line, show two choices to authorized PPC users:

| Choice | Behavior |
|---|---|
| **Use approved stock first** — default for new lines | Reserve compatible approved stock; produce only the remaining shortage |
| **Produce new** | Keep existing stock untouched; generate fresh production for uncovered demand |

Before order confirmation, availability is an estimate with an “As of” timestamp. Confirming the order triggers authoritative allocation and planning. CRM sees the result; PPC owns changing production strategy. Customer staff do not select warehouse lots, equipment, or internal manufacturing policy.

Examples for an order of 500 pieces:

- 500 eligible free pieces: reserve 500, create no new WO, prepare delivery.
- 300 eligible free pieces: reserve 300, produce 200. By default prepare one shipment when all 500 are approved; ImpEx can arrange a partial shipment through the existing partial-delivery workflow.
- 0 eligible pieces: produce 500 through the existing route.
- 500 physical pieces but 200 held: only 300 can be reserved. Display held quantity separately.
- **Produce new**: produce 500, even if free stock exists, unless valid production already committed to this line covers part of the order.

Policy changes use an order revision and an audit reason. They can release unshipped allocations and reconcile only untouched planned WOs/draft automatic requests. Once materials are issued, a crew/schedule is confirmed, production starts, or a shipment dispatches, show the commitment and require the existing operational change/cancellation flow. Never silently discard progressed work or dispatch history.

## Eligibility, reservations, and exact quantities

Stock is eligible only if product/item mapping, output provenance, process release, independent outgoing approval, warehouse state, remaining approved quantity, and expiry are valid. Customer specification/revision, PPAP/CoC or shelf-life restrictions must be satisfied when the repository already models them. Unknown compatibility blocks the affected lot with a Quality review reason; do not assume that identical part numbers prove contractual compatibility.

Select earliest expiry first where expiry exists, then receipt time and stable IDs. Do not mix unrelated specifications to reach a promised quantity. The allocation limit is the lesser of usable physical lot stock and its remaining approved release capacity, after existing issues, returns/dispositions, shipment holds, material holds, and order holds.

Respect existing safety stock when selecting general stock for new orders. Only quantity above the configured safety buffer is automatically allocatable. No new override permission or implicit buffer consumption is added.

Create a durable order allocation for each selected output/inspection/lot/bin. Reserving increases the existing reserved counter; it does not reduce on-hand quantity or post COGS. When a shipment takes ownership, transfer the hold rather than adding another hold. Dispatch consumes the shipment hold through the existing stock movement and costing path. Pre-dispatch cancellation releases only remaining quantities. Dispatched goods require the real return/recovery process.

Use decimal strings with BCMath at inventory precision and integer piece quantities for production. Reject negatives, fractions for piece-based manufacturing, exponent notation, NaN/Infinity, duplicate line IDs, wrong products, foreign ownership, client-supplied approved totals, and over-allocation. Never trust UI calculated availability.

The line coverage calculation counts disjoint quantities: confirmed delivery, valid unshipped stock allocations, order-bound production commitments/output, and remaining shortage. An output represented by a shipment is counted once. Repaired pieces and replacement deliveries retain their existing identities; free replacement supply never increases the original billable order quantity.

## Honest chain and status

Keep existing chain keys for callers. Change displayed labels/descriptions from the server's allocation/coverage facts:

| Fulfillment | Supply/production stage | Quality stage |
|---|---|---|
| Stock-only | **Ready from stock**, done, with source production links | **QC approved stock**, done, with actual inspection links |
| Fresh production | **Production**, pending/active/done based on real work | Existing inspection/review states |
| Mixed | **Stock + production**, quantities shown; done only when all required supply is ready | Done only when all allocated/produced shipment quantities are currently approved |

Use **Supply ready** as the customer-facing stage where internal labels would confuse. Stock allocation alone does not mark Delivered, Invoiced, Paid, or Closed. Delivery confirms actual customer receipt; billing/payment keep their existing guards. Do not stamp the current date as historical production/QC completion or rewrite the source WO to belong to the new order. Record allocation time separately.

Derive **Ready for delivery** for fully covered/approved orders consistently on CRM detail/list, customer detail/list, dashboards, the business-chain tracer, notifications, and delivery preparation. Historical Failed inspections remain historical; current coverage can still be approved after a valid disposition or replacement.

## Delivery, returns, and costing

Extend provenance validation with one explicit alternative: a valid allocation authorizes stock from an internal stock-production output to this order line. The existing same-order WO path remains valid. Merely relaxing the Sales Order comparison is forbidden.

Draft delivery from order supply coverage, not only from a WO QC event. For mixed fulfillment append to the same unassigned, undispatched draft by order/source identity; never identify automation ownership by a notes-text pattern. Assigned drafts require explicit ImpEx review before contents change. Previously dispatched shipments remain immutable.

Revalidate holds, latest QC, specification, expiry, and actual bins at allocation, shipment preparation, and departure. A revoked approval or missing lot blocks dispatch, marks affected allocations unavailable, and notifies PPC/Quality. Replan replacements once, without releasing other orders' holds. No silent lot substitution.

Shipment lines preserve source IDs internally. Customer receipt and problem-report forms group the same product for reading, while retaining separate source lines for exact quantities, serial/lot attribution, returns, and replacement accounting. CoC evidence comes from actual approved sources, including multiple source outputs when required.

Production capitalization happens on the original production receipt. Allocation never creates another receipt or production cost. Sales pricing comes from the destination Sales Order, not the old WO or source order. Dispatch/confirmation use existing transit/COGS accounting. Returns re-enter held stock and need their existing inspection/disposition; a previously approved original lot is not sufficient evidence for returned pieces.

## UX polish and action limits

- Work-order list adds **Produce for stock**, a source filter, and **For stock / For order / Subassembly / Repair** badges derived from explicit purpose and relationships.
- Stock planning is a short page flow with one proposal review modal. Machine, mold, schedule, and workers are reviewed together; no separate mandatory “Auto assign,” “Assign crew,” and “Confirm WO” click sequence.
- Stock overview shows approved free, reserved, QC-held, and incoming production. “Incoming” is never labeled “Available.” It links to actual lots, jobs, and inspection blockers.
- Order lines show ordered, stock reserved, production committed, QC pending, shipped/received, and shortage. Product totals are convenient; lot details are expandable.
- Controls appear by state and permission. **Review fulfillment** is meaningful only for confirmed orders with editable commitments. Invalid forms show red field errors, a short summary, and a disabled primary action without restricting ordinary typing.
- Retain drafts after network failures. Preserve idempotency keys on retries, prevent duplicate submissions, and refresh stale revisions with a clear review prompt. Keyboard focus, readable dates, 390 px layouts, and the existing Atelier tokens/components are required.
- ImpEx plans shipment/driver/vehicle once; driver remains the proof source; customer confirms receipt. This work must not restore removed manual proof or collection steps.

## Risks and recovery requirements

| Gap or risk | Required behavior |
|---|---|
| Two orders claim the same lot | Row locks, consistent lock order, server quantities, and concurrent allocation tests |
| A lot is also needed as a manufactured component | Shared reserved counter and lot-hold checks cover both parent production and customer fulfillment |
| Preview becomes stale | Recalculate on confirm; report changed reserved/production totals; never honor forged quantities |
| MRP reruns duplicate production or purchasing | Persist allocations and reconcile only untouched automatic commitments; stable request/source keys |
| Stock arrives while fresh production is underway | Preserve progressed commitments; apply new stock only to uncovered demand |
| Internal stock jobs crowd out promised customer work | Use existing priority/deadline ordering; PPC can reorder unstarted work, never silently preempt active jobs |
| Equipment/crew expires between proposal and execution | Check compatibility, maintenance/mold life, overlap, shift, leave, and actual attendance again at Start |
| Old inventory has no usable production/QC evidence | Exclude and report “Quality evidence required”; no blanket migration marking it approved |
| Archived or renamed product/item mapping | Block allocation and creation; report affected mapping to master-data owner |
| Deadline impossible/material unavailable | Planned job with named blockers; no automatic overtime, fabricated availability, or purchase approval |
| QC revoked after allocation | Block shipment, isolate affected holds, notify owners, and replan once |
| Cancellation or reduced order quantity | Release unshipped holds transactionally; preserve dispatched/issued costs and use existing change workflows |
| Customer receives an original and a free replacement | Separate fulfillment/allocation identities; billable demand and replacement coverage stay separate |
| Automation actor or queue fails | Existing outbox/recovery reporting and idempotent replay; no silent success banner |
| Downgrade after allocations exist | Preserve additive records; refuse rollback that discards allocation or request provenance |

## Delivery boundary and non-goals

Ship the integrated core: explicit stock production with automatic planning, top-level stock-first allocation, fresh-production choice, mixed delivery, honest chain, and regressions. Automatic forecasting/reorder execution, cross-company inventory, purchased finished goods without production provenance, consignment, automatic overtime, global schedule optimization, and reuse of another live customer's committed output are excluded.

The stock overview can show existing safety-stock settings and suggest a replenishment action. Scheduled min/max replenishment remains a separate future phase after allocation correctness is proven.

No live order, stock, production, invoice, or journal changes are part of planning. Implementation must use an isolated test database and preserve existing workspace work.
