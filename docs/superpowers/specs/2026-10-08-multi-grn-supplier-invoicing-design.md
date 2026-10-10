# Multi-GRN Supplier Invoicing Design

## Decision

One supplier invoice may cover one or several accepted receipt portions from **one purchase order and one vendor**. Each receipt portion keeps its own internal AP bill, three-way match, and GRNI clearing. A new supplier-invoice header groups those bills. We do not merge AP drafts or wait for the entire PO to arrive.

For example, a 20,000-piece PO received as 12,000 and 8,000 pieces creates two GRNs and two AP drafts. The supplier can submit `INV-100` once against both drafts. Finance sees one supplier invoice for 20,000 pieces, with two receipt-level AP matches. A supplier may also invoice the 12,000-piece receipt first and the 8,000-piece receipt later under a different invoice number.

## Current behavior and reason for change

- `AutoCreateBillOnGrnAccepted` stages an AP draft for each accepted GRN; stock and GRNI are recorded independently of any supplier invoice.
- `SupplierInvoiceService` currently attaches one invoice number to one bill/GRN. A unique index on live bills prevents attaching the same supplier invoice number to another bill.
- `SupplierPoCapabilities` treats a GRN with any invoiced bill as fully invoiced. If more quantity is accepted on that GRN later, the new billable portion may be hidden from the supplier.
- `BillService::createDraftForGrn` may rewrite an existing draft. It must never rewrite a draft already claimed by a submitted supplier invoice.
- `BillService::postDraft` and `recordPayment` currently do not require a supplier invoice. A Finance user could post or pay the auto draft before the supplier submits one.
- Bill matching and GRNI clearing depend on each bill's singular `goods_receipt_note_id`. Combining bills would change accounting controls unnecessarily.

## Required behavior

1. QC-accepted quantities stage AP drafts immediately, including partial receipts. Drafts do not create a payable until Finance posts them.
2. The supplier submits an invoice number, date, and **gross invoice total before withholding tax**. The existing optional invoice-file upload remains optional. Supplier selects one or more eligible billable portions from the PO. One eligible portion is selected automatically and shown read-only; several are shown as selectable receipt rows with accepted quantity and gross amount. Supplier may select a subset. This replaces the current disabled single-GRN field only when selection is needed.
3. A portion is eligible when its bill is a live, unclaimed draft for an accepted GRN belonging to the supplier's vendor and the chosen PO. A pre-cutover posted-but-uninvoiced bill with no payments may also be claimed for reconciliation; new bills cannot reach that state. Paid historical bills are excluded and reported to Finance as exceptions. The backend checks this under locks; the portal controls are guidance, not authorization.
4. Sum of selected AP draft `total_amount` must equal the supplier's declared gross total to the cent. A mismatch returns a clear error showing both totals. Finance resolves a genuine price/tax discrepancy through the existing PO, receipt, and bill review process; this release does not invent a variance override.
5. Submission creates one supplier-invoice header and links all selected drafts atomically. Vendor plus supplier invoice number is unique among non-voided invoices, matching the existing live-bill rule. An identical retry returns the existing header without duplicate documents, links, emails, or bills. Reusing a live number for different contents is rejected.
6. A newly submitted invoice is immutable through the supplier portal. Later QC acceptance on the same GRN stages a **new** draft for only the newly accepted quantity. It needs its own later supplier invoice, or it may join another invoice with other unclaimed portions.
7. For new PO/GRN stock bills, Finance cannot post or request/approve payment until the bill is linked to a supplier invoice and its group total still reconciles. Existing paid bills remain historical records and appear in a one-time Finance exception report. Existing posted-but-uninvoiced bills cannot receive new payments until they are linked and reconciled; they are never silently re-posted or duplicated.
8. Cancellation or mutation of a claimed bill cannot silently alter the group. Finance may void a new invoice with a reason only when every linked bill is still Draft and has no payments; the bills become unclaimed again. A posted, unpaid linked bill may be cancelled through the existing reversal flow with a reason, but its invoice stays linked and becomes `requires_correction`: no further payment or reuse of that invoice number until Finance reconciles a replacement supplier invoice. A paid bill cannot be unlinked. Preserve audit records.
9. Supplier sees one invoice record with its included GRNs, total, aggregate paid amount, and aggregate balance. Finance sees the same supplier reference on each linked AP bill and the receipt-level matching result. The invoice document is stored once on the header, with existing vendor/internal authorization. One submission generates one notification.
10. Existing single-GRN supplier invoices retain their numbers, dates, attachments, bill associations, payment history, and links through a compatibility backfill. Keep legacy bill fields readable during rollout. Do not change live `PO-202610-0001` data as part of testing or migration verification.

## Payment boundary

This design groups **invoices**, not payment transactions. The existing AP workflow records and approves payments per bill. Finance may pay two linked bills separately; the supplier invoice's balance is their sum. A single bank transfer allocated across several AP bills needs a separate payment-allocation design and must not be represented as two transfers with a shared reference. Do not claim one-click consolidated payment in this release.

## Rollout and proof

- Add nullable group links and backfill first; preserve legacy columns and old API fields until portal/AP clients read the group. Keep the old bill unique index for legacy values. New grouped bills need not copy the same supplier number into the legacy column.
- Switch supplier submission and projections after backfill. Enforce posting/payment gates only after the invoice path and Finance visibility are live. Check existing unpaid/posted records before enabling the gate.
- Run migrations and tests only against an isolated `ogami_test_*` database. Test up/down migration with existing single-GRN, cancelled, posted, and paid rows. Verify vendor scoping, duplicate/concurrent submissions, partial acceptance continuation, one- and two-GRN submissions, amount mismatch, payment gates, document access, and one notification.
