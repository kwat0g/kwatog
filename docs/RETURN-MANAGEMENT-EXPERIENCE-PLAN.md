# Return and shortage reporting experience

Status: implemented locally, 2026-09-25. Customer and supplier cases, portal intake, evidence, shared quantity controls, incremental return receipts, and approved no-charge replacement handling are implemented. See [the implementation review](RETURN-MANAGEMENT-REVIEW.md) for verification and remaining limits, and the [headless acceptance report](RETURN-MANAGEMENT-HEADLESS-TEST.md) for completed supplier and customer return journeys, issued credits and corrected audit findings.

## Recommended experience

Provide one **Report a problem** action on customer deliveries and Ogami's incoming receipts. Also provide a searchable entry from the Returns page for problems discovered later. The user identifies the goods, records what happened, and submits once. The system chooses the appropriate return, discrepancy, Quality, and settlement paths.

Use the existing Atelier interface and components. This is an operational flow: clear quantities, little typing, visible ownership, and an explicit next step matter most.

## Customer journey

1. Open a delivery and choose **Report a problem**, beside **Confirm receipt**. Both actions remain understandable; reporting does not imply accepting the documented quantity.
2. See the delivery's actual part numbers, descriptions, quantities, and units already filled in. Select affected lines; unaffected lines stay collapsed. Support several affected lines in one report.
3. Choose **Missing quantity**, **Damaged or defective**, or both for each affected line. For shortages, enter the quantity actually received and calculate the difference. For defects, enter the affected quantity among the goods received. Show optional lot/serial fields when relevant.
4. Add a short explanation and optional photos/documents. Ask for a preferred outcome: **Send the missing/replacement goods**, **Credit**, or **Help me decide**. A preference is not authorization to issue money or stock.
5. Review a compact summary and submit. Return one reference and **Submitted — awaiting Ogami review**, with the responsible team and next action. Preserve entered data on validation or network failure and make retries idempotent.

Example: a delivery documents 100 pieces; 90 arrived and 2 of those are damaged. Display **10 missing · 2 damaged · 88 received without a reported defect**. Never count the 2 damaged pieces as another shortage. Support an entire missing line or shipment with received quantity zero.

Reporting remains available after confirmation or invoicing. Those cases require a different settlement path, not a dead end. The original documents remain auditable. Any time-window exception is reviewed under a configured business policy rather than silently discarded.

## Ogami receiving journey

Use the same quantity language on the receiving screen: **Expected for this shipment**, **Actually received**, and **Damaged or suspect**. Distinguish the total PO balance from the supplier's promised quantity for this shipment; an expected partial delivery is not automatically a supplier shortage claim.

- Record only goods physically received on the GRN. Missing goods never enter inventory or a physical-return receipt.
- Allow a shortage report from a PO/shipment before a GRN exists, including a wholly missing delivery.
- Put suspect goods through incoming Quality handling. A warehouse report does not itself establish a final QC rejection.
- Record receipt and the linked issue together where a receipt exists. Reuse the existing full/remainder rejection paths when Quality confirms rejection.
- Assign Purchasing as the supplier case owner. Show supplier acknowledgment, agreed action, expected replacement/redelivery date, and evidence of completion on the same case.
- Expose the relevant case and reply actions to the supplier portal, with email notification and internal follow-up if portal access is unavailable.

## One report, distinct operational effects

| Reported problem | Physical handling | Resolution |
|---|---|---|
| Customer received fewer goods | No physical return for the missing quantity | Investigate; agree redelivery or the appropriate billing correction/credit |
| Customer received defective goods | Authorize return when required; actual receipt enters quarantine; Quality controls disposition | Replacement, rework, credit, or an approved no-return settlement |
| Supplier shipment is short | GRN records actual receipt only | Keep the shortage owed on the original PO, or explicitly settle/short-close it |
| Raw material fails incoming QC | Existing reject/reject-remainder handling and linked supplier return | Supplier redelivery/replacement; credit only when a payable actually exists |
| Previously accepted material proves defective | Trace PO/GRN/lot and current physical custody | Controlled supplier return and the appropriate payable/stock corrections |

Do not create a new replacement PO merely because a receipt is short. The original PO already represents the outstanding supply. When a separate replacement PO is necessary, transfer that obligation explicitly so both orders cannot promise the same goods. Convert base quantities into the replacement order's unit before calculating quantity, price, or remaining obligations.

A shortage report also does not prove where the missing goods went. Investigation must distinguish goods never dispatched, transit loss, and goods later found. Inventory corrections follow that evidence; a claim must not automatically put missing stock back on a shelf.

## Ownership and customer visibility

Use a small case record in Return Management to group the report, affected lines, correspondence, and links to existing operational documents. Keep its external reporter separate from its assigned internal owner. Existing RMA records remain the authority for physical returns; existing accounting and inventory records remain authoritative for settlement and movements.

The shared intake module validates provenance, deduplicates submissions, records the case and line snapshots, and assigns the owner. Portal and employee forms use the same interface. It delegates operational work to the existing modules; it does not maintain a second stock, credit, or approval ledger.

- Customer Service owns customer cases; Purchasing owns supplier cases.
- Warehouse owns counts and custody; Quality owns defect decisions; Finance owns financial approval and posting.
- Authorized staff can triage and correct submitted information through an audited revision, including reports created under the system account. They do not need role-administration permission.
- Customers can provide requested evidence, reply, and withdraw an unresolved request. After stock or money effects, withdrawal becomes a reviewed resolution rather than deletion.
- Internal work appears in the owning team's queue. Notifications supplement the queue; a missed notification must not hide the case.

Show customers **Submitted → Under review → Action agreed → In progress → Resolved**. Show **Information needed** with a precise request when applicable. A disputed/rejected claim includes the reason and a reply/review path; the customer is not forced to agree to rejection merely to proceed.

Each case detail shows requested and verified quantities, the next action and who owns it, agreed dates, actual return receipts, linked replacement delivery progress, and credit amount/status. Distinguish **Credit being reviewed**, **Credit issued**, and **Refund paid**. “Resolved” requires all agreed physical and financial actions to finish, or an explicit audited closure decision.

## Implementation sequence

### 1. Repair the existing return controls

- Fix the reproduced kg/bag replacement quantity and open-PO balance comparisons in `ReturnRequestService`.
- Replace creator-only triage restrictions with explicit case ownership and permissions, preserving external attribution and maker/checker controls.
- Align customer reason lengths and field-level validation; support multiple lines and real product labels.
- Add explicit cancellation before physical receipt, partial return installments, and clear remaining quantities. Preserve posted effects and use controlled compensating actions where necessary.

### 2. Complete customer reporting end to end

- Add the case intake and the delivery-context form; link defects to existing RMA and Quality workflows.
- Extend `DeliveryDiscrepancyService` beyond report/reject: verified quantities, agreement, resolution, revision history, and closure are required.
- Wire B2B routes, controller, request validation, response, types, and customer pages together. The current working-tree discrepancy implementation is incomplete and cannot be treated as a finished backend.
- Reconcile the existing discrepancy record with the case's authoritative claim quantities; do not reserve or settle the same report in two independent ledgers.
- Replace the one-discrepancy-per-delivery limitation with controlled subsequent claims/revisions and shared quantity limits.
- Before invoicing, hold the affected delivery while disputed quantities are reviewed. Other deliveries can continue. After posting, route approved corrections through Accounting instead of changing historical invoice quantities.
- Add protected attachments and the customer-visible case timeline. Optional evidence should not prevent initial reporting; a reviewer can request specific evidence before approving a remedy.

### 3. Complete supplier shortage and acknowledgment handling

- Add the same intake pattern to GRN receiving and PO/shipment detail, including zero-receipt reports.
- Reuse PO outstanding quantities, GRN rejection, supplier-return, and replacement behavior; preserve purchase/base-unit conversions throughout.
- Add supplier acknowledgment and proposed resolution, Purchasing acceptance, expected dates, and completion evidence.

### 4. Verify the full experience

Use the public HTTP paths and actual roles for business tests, and Chromium for desktop/mobile interaction checks. Run database tests on a dedicated database. Validate at least:

1. Customer reports 100 documented / 90 received / 2 damaged as one case, with no duplicate counting or stock movement on intake.
2. Customer reports several affected lines, an entirely missing shipment, or a later defect after confirmation/invoicing.
3. Return manager can triage a portal-created case; unauthorized users and other customers/suppliers cannot read or change it.
4. Two submissions or retries cannot over-claim, reserve twice, create duplicate credits, or duplicate replacement orders.
5. Returned goods arrive in two installments; quantities and credits follow actual receipt and the agreed policy.
6. Five kilograms at five kilograms per bag produces one replacement bag at the correct price, including the open-PO case.
7. An expected partial supplier delivery creates an outstanding balance; a promised-but-missing quantity creates a shortage case. Neither invents stock or duplicates supply.
8. Failed QC cannot restock sellable inventory; missing goods never go through physical-return receipt.
9. Rejected claims can be challenged; approved cases can request information, recover from failed downstream work, and close only when obligations reconcile.
10. A customer can complete intake on a phone, attach evidence, recover from errors, revisit the report, and understand the next action and final settlement.

## Policy choices to make explicit during implementation

Use existing approval and financial controls. Do not invent a return window, refund entitlement, free replacement, transport-cost obligation, evidence requirement, or response deadline. Represent the customer's preference immediately; approve the actual remedy through the responsible team. Configure the business's agreed policies and display their relevant consequences at the decision point.

The first release should deliver a complete customer case from submission through resolution, followed by the supplier counterpart. Basic supplier returns continue through the existing path while supplier shortage handling is completed.
