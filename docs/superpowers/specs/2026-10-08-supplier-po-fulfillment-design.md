# Supplier Purchase Order Fulfillment Design

## Goal and current state

Make the supplier's path from an acknowledged purchase order to a warehouse receipt understandable and traceable, while preserving the existing PR, PO approval, QC, and AP controls. `PO-202610-0001` is acknowledged, has a required and confirmed delivery date of October 13, 2026, and has an auto-created draft `GRN-202610-0001`. No shipment has been reported and no goods have been received.

Current screens expose three independent actions: a month-and-quantity delivery schedule, a shipment with optional header fields and no quantities, and documents attached to the PO. Purchasing can read supplier activity, but schedule review lives under portal-access administration. The supplier PO KPI counts the draft GRN as a posted receipt. These are separate problems; each release below must be usable by itself.

## Vocabulary and sequence

| Record | Meaning | Owner |
| --- | --- | --- |
| Required delivery date | OGAMI's requested date on the PO | Purchasing |
| Confirmed delivery date | Date accepted through PO acceptance or approved counterproposal | Supplier and Purchasing |
| Delivery schedule | Supplier's advance plan for a date and PO-line quantity | Supplier; Purchasing reviews |
| Shipment | Goods the supplier has actually dispatched, including PO-line quantities and ETA | Supplier |
| Shipping document | Packing list, bill of lading, commercial invoice, or CoA supporting dispatch and QC | Supplier |
| GRN | Warehouse's record of goods physically received; draft means expected only | Warehouse |
| QC acceptance | Inspection outcome that makes accepted quantity available and billable | Quality |

Recommended sequence for a planned delivery: supplier submits a schedule; Purchasing acknowledges or rejects it; supplier dispatches and records a shipment; supplier uploads relevant shipping documents; Warehouse records actual receipt against the shipment; Quality completes incoming QC; supplier submits an invoice for an accepted receipt; Finance matches and pays. The schedule remains optional for an immediate one-off dispatch. No screen may describe a schedule as proof of dispatch, an ETA as a revised agreed date, or a draft GRN as received goods.

## Release 1: truthful state and clear actions

The supplier PO page reports **Received GRNs** as the count of non-draft GRNs and separately reports expected draft GRNs. `GRN-202610-0001` therefore shows zero received and one expected. The GRN table retains each record and status. Replace “Upload doc” with “Upload shipping document” and explain accepted file types and that OGAMI confirms actual receipt through a GRN. On the PO page, describe **Schedule delivery** as planning and **Add shipment** as dispatch. This release changes display and copy only.

## Release 2: a dated supplier plan and an operational reviewer

A supplier schedule gains an optional stored `planned_delivery_date` for backward-compatible reads. The updated supplier UI requires a specific date, prefilled from the PO confirmed date when available. The API accepts historic month-only clients during rollout, labels their submissions “month-only,” and never implies a specific day. For a date supplied, require `YYYY-MM-DD`, no past date, and `month === date.slice(0, 7)`. A plan after the PO confirmed date (or required date when confirmation is absent) is rejected with an instruction to propose a PO date change; acknowledging a plan does not modify PO dates. Keep the existing per-line quantity cap and schedule cancellation behavior. Customer monthly schedules keep their current contract.

Use the application's `Asia/Manila` business date for the “no past day” rule so a date near midnight does not differ between supplier UI and API.

Reviewing supplier schedules belongs to Purchasing. Add `purchasing.delivery_schedules.review` to the purchasing-officer role. Expose supplier schedule review on the internal PO's supplier-activity panel, scoped by the existing `PurchaseOrderAccessPolicy`; allow system administrators through their existing wildcard. Keep the portal-access screen for customer schedules and existing administrators. Internal routes must distinguish supplier PO schedules from customer schedules so the new permission cannot review customer submissions or another department's PO.

## Release 3: dispatch quantities and receiving reconciliation

New supplier shipments carry at least one `{purchase_order_item_id, quantity_shipped}` line, a shipped date, and an ETA. `carrier` and `tracking_number` remain optional because supplier-owned vehicles may have neither. The API verifies vendor ownership, each line's PO membership, distinct line IDs, positive quantities in PO purchase units, and ETA on or after the shipped date. It serializes writes on the PO row. Existing shipment rows remain readable as **quantity not recorded** and can still have header corrections; they are not silently assigned quantities.

Add nullable `supplier_shipment_id` to GRNs. When Warehouse finalizes an auto-created draft GRN, it selects the supplier shipment being received, or explicitly records **unannounced delivery**. The selected shipment must belong to the same PO. Display shipped, physically received, and accepted quantities per PO line without using shipment quantities as a substitute for GRN quantities. Do not overwrite `goods_receipt_notes.shipment_id`, which references a different Supply Chain shipment model. Historical GRNs remain unlinked. This first release flags over-shipment and receipt mismatches for review; it does not invent automatic adjustment or block a legitimate return and reshipment.

An ETA after the confirmed PO date remains recordable as a real forecast. Show **At risk: ETA after confirmed date** in supplier and internal views and notify the PO's Purchasing audience once per changed late ETA. The agreed PO date remains unchanged. Correcting the ETA removes the current warning without deleting the original shipment update history.

## Release 4: document context and evidence boundary

Shipping documents remain supplier-uploaded files. A new optional `supplier_shipment_id` associates packing lists, bills of lading, and CoAs with a specific dispatch; old PO-only documents remain visible and are marked “PO-wide.” The API checks that the document's shipment belongs to the same PO and vendor. Purchasing sees documents on the PO; incoming QC sees documents for its linked shipment plus legacy PO-wide documents. A bill of lading shows carrier handoff, not OGAMI receipt. A signed proof of delivery is a separate warehouse/GRN evidence requirement and is not silently added to the supplier shipping-document list. If that evidence becomes required, design its retention and verification under the GRN workflow separately.

## Role-based acceptance walk

The complete PR-to-supplier runbook begins with these already-completed checkpoints for the current live example: Department Head Roberto Santos creates `PR-202610-0001` with an October 13 needed-by date and Direct PO sourcing; Finance Officer Ana Reyes approves it; Purchasing Officer Elena Cruz converts it to `PO-202610-0001` for Asia Pacific Polymers; Finance approves the PO; the PO is sent to the active supplier portal account; and the supplier accepts it as written. For a fresh test run, repeat and verify each checkpoint with its role account before beginning fulfillment. A system administrator manages portal access only; it does not stand in for Purchasing, Warehouse, QC, or Finance operations.

Use the existing accepted test PO without mutating it during implementation planning. Later, execute this flow with role accounts and record actual IDs/statuses after each step:

1. **Supplier portal** (`asiapacific@ogami.test`): submit October 13 schedule for 20,000 `PKG-001` bags. Expect `submitted`; PO dates unchanged.
2. **Purchasing officer** (new schedule-review permission): acknowledge the supplier plan from the PO. Expect `acknowledged` schedule. System admin remains fallback during rollout.
3. **Supplier portal**: dispatch a shipment of 20,000 bags, with actual shipped date, carrier/tracking if available, and ETA no later than October 13. Expect one shipment row and line quantity, no GRN quantity change.
4. **Supplier portal**: attach a packing list; attach a CoA only if this material's QC specification requires it. Expect files readable by Purchasing and incoming QC. No file proves warehouse receipt.
5. **Warehouse staff**: open `GRN-202610-0001`, link the shipment, enter actual quantity and bin, finalize. Expect `pending_qc` and a received date. If actual quantity differs from 20,000, show discrepancy rather than silently changing shipment history.
6. **Quality inspector and independent reviewer**: complete required incoming inspection. Expect accepted or partially accepted GRN, with only accepted quantity available/billable.
7. **Supplier portal, then Finance/AP**: submit invoice against an invoiceable GRN; complete existing 3-way match, approval, and payment. Expect a paid bill linked to the same PO and accepted receipt.

The automated test must use an isolated test database and role principals, not the shared live PO. It must assert state transitions and forbidden cross-role actions, not only page visibility. A short human runbook records the role switch and expected screen after each step.

## Compatibility and stop conditions

- Additive nullable columns and tables; do not rewrite historic schedules, shipments, GRNs, or documents.
- Historic month-only schedules and unquantified shipments remain visible with explicit legacy labels.
- Supplier actions remain vendor-scoped; internal views remain PO-row-scoped.
- All new file access stays behind the existing supplier or internal document authorization paths.
- Release 1 can ship alone. Release 2 is complete when a dated supplier plan can be reviewed by Purchasing without changing the PO date. Release 3 is complete when one dispatched lot can be reconciled against a GRN without falsifying received quantity. Release 4 is complete when shipment-specific files are visible to the right buyer and QC users.
