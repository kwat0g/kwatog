# Multi-GRN Supplier Invoicing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let one supplier invoice cover several accepted receipt portions on one PO, while keeping receipt-level AP matching and preventing payment before invoice submission.

**Architecture:** Keep one AP bill per accepted GRN portion. Add a supplier-invoice header with many bill links; submit the header atomically from the supplier portal. Keep three-way matching, GRNI clearing, and payment accounting on each bill. Expose grouped invoice information to Supplier and Finance.

**Tech Stack:** Laravel/PHP, PostgreSQL, React/TypeScript, PHPUnit, Vitest.

**Spec:** [Multi-GRN Supplier Invoicing Design](../specs/2026-10-08-multi-grn-supplier-invoicing-design.md)

## Global Constraints

- Planning only now. Do not change application code or live `PO-202610-0001` data until implementation is requested.
- Never merge receipt-level AP bills or treat dispatch quantity as accepted quantity.
- Money comparisons use `App\Common\Support\Money`, not floating point. Supplier gross total includes VAT before withholding tax.
- Portal IDs use existing hash IDs; vendor scope must be checked again inside the locked transaction.
- Preserve existing paid and posted accounting records. Backfill only adds links; it never creates or reverses journal entries.
- Run PHP tests only in a dedicated `ogami_test_*` database. Do not use the live database for `RefreshDatabase`, migration rollback, or E2E tests.
- The repository has a large unrelated working-tree diff. Change only files named in this plan and inspect the final diff before deployment.

---

## Contract

Supplier submits one invoice against specific **bill portions**, not against a single GRN ID. Receipt labels stay visible in the selector:

```json
{
  "bill_number": "INV-100",
  "date": "2026-10-09",
  "gross_total": "11200.00",
  "bill_ids": ["<hash for 12,000-piece draft>", "<hash for 8,000-piece draft>"]
}
```

One linked bill remains the legacy invoice path. A matching retry with the same vendor, PO, number, date, total, and ordered set of bill IDs returns the existing invoice; different contents return 422. `goods_receipt_note_id` remains accepted only during the compatibility window and resolves to exactly one eligible draft. A group invoice has one document and one submission event.

## Task 1: Add invoice header and backfill existing references

**Files:** Create `api/database/migrations/2026_10_09_000100_create_supplier_invoices.php`, `api/app/Modules/Accounting/Models/SupplierInvoice.php`; modify `api/app/Modules/Accounting/Models/Bill.php`, `api/app/Modules/B2B/Models/PortalShippingDocument.php`; test `api/tests/Feature/Accounting/SupplierInvoiceBackfillTest.php`.

**Interface:** `supplier_invoices` stores `vendor_id`, nullable `purchase_order_id` for legacy rows, `number`, `date`, `gross_total`, `submitted_at`, nullable `portal_user_id`, nullable `voided_at`, `voided_by`, `void_reason`. `bills.supplier_invoice_id` and `portal_shipping_documents.supplier_invoice_id` are nullable FKs. `SupplierInvoice::bills()` and `Bill::supplierInvoice()` are relations. Unique `(vendor_id, number)` only where `voided_at IS NULL`; keep old bill fields/index through rollout.

- [ ] Add migration test with two live legacy bills and one cancelled historical bill. Assert each keeps its bill ID, status, amount, payments, and journal ID; live rows gain headers; cancelled rows remain readable even when numbers repeat.
- [ ] Run `docker compose exec -T -e DB_DATABASE=ogami_test_supplier_invoice api php artisan test tests/Feature/Accounting/SupplierInvoiceBackfillTest.php`; expect missing table/relationship failure.
- [ ] Create additive schema and backfill in bill-ID order for historical live rows. Leave cancelled rows on their existing legacy fields so duplicate cancelled numbers cannot collide with live headers. Backfill document `supplier_invoice_id` from its `bill_id`. Preserve `portal_shipping_documents.bill_id` until all readers use the header. Use the existing bill's `total_amount` for backfilled gross total, and its supplier number/date/submitted-at for header values.

```sql
CREATE UNIQUE INDEX supplier_invoices_vendor_live_number_unique
ON supplier_invoices (vendor_id, number)
WHERE voided_at IS NULL;
```

- [ ] Run the focused test plus migration up/down on the isolated database. `down()` drops only new links/table/index; old bill fields and payment history remain intact. Commit this independent additive migration.

## Task 2: Keep invoice-linked draft quantities immutable

**Files:** Modify `api/app/Modules/Accounting/Services/BillService.php`, `api/app/Modules/B2B/Policies/SupplierPoCapabilities.php`; test `api/tests/Feature/Accounting/PartialAcceptedGrnBillingTest.php`, `api/tests/Feature/B2B/SupplierPoCapabilitiesTest.php`.

**Interface:** `createDraftForGrn()` reuses only a Draft bill with no supplier invoice link or legacy supplier invoice number. Eligible portions are unclaimed Draft bills for billable GRNs, not GRNs with no prior invoice.

- [ ] Add a test: accept 12,000 pcs on one GRN, stage and invoice its draft, then accept another 8,000 pcs on that same GRN. Expect the first bill's items/total/invoice link unchanged and a new unclaimed 8,000-piece draft. Add a test that an unclaimed draft can still refresh after QC changes its accepted quantity.
- [ ] Add a capability test: a GRN with an invoiced first portion and an unclaimed second portion remains invoiceable; a fully claimed GRN does not.
- [ ] Run the two focused test files; expect the new assertions to fail.
- [ ] Change the draft calculation to subtract quantities on every live bill **except the editable unclaimed draft**, including claimed Draft bills. Reject a QC reduction that makes a claimed quantity exceed current accepted quantity; send it to manual correction rather than silently shrinking the invoice. Change capability queries to seek eligible unclaimed Draft bills; retain a safe fallback for an accepted GRN whose auto-create listener has not yet staged a bill.

```php
$editable = Bill::query()
    ->where('goods_receipt_note_id', $grn->id)
    ->where('status', BillStatus::Draft)
    ->whereNull('supplier_invoice_id')
    ->whereNull('supplier_invoice_number')
    ->lockForUpdate()->first();
// The accepted delta excludes every other live bill, including claimed drafts.
```

- [ ] Run the focused tests and `ThreeWayMatchPerReceiptTest.php`; commit the receipt-portion fix before changing supplier submission.

## Task 3: Submit one invoice against selected portions

**Files:** Modify `api/app/Modules/B2B/Requests/Supplier/SubmitInvoiceRequest.php`, `api/app/Modules/B2B/Services/SupplierInvoiceService.php`, `api/app/Modules/B2B/Controllers/SupplierInvoiceController.php`, `api/app/Modules/B2B/Events/SupplierInvoiceSubmitted.php`, `api/app/Modules/B2B/Listeners/EmailSupplierOnInvoiceSubmitted.php`; test `api/tests/Feature/B2B/SupplierInvoiceSubmissionTest.php`.

**Interface:** Existing POST path remains. Accept `bill_ids[]` and `gross_total` for new clients, plus existing `bill_number`, `date`, optional `file` and `remarks`. Response contains invoice hash ID, number, gross total, and linked bill/GRN summaries. The old one-GRN POST is translated server-side into one eligible bill and derives `gross_total` until clients migrate. A pre-cutover posted/uninvoiced bill with no payment may be claimed once; new submissions otherwise use Draft bills.

- [ ] Add tests: two accepted GRNs on one PO create one invoice header/two linked drafts/one document/one event; one draft works; a subset remains available for a later invoice; same-GRN later portion works. Pre-cutover posted/uninvoiced/unpaid bill may be claimed; paid historical bill cannot. Reject mixed vendor, mixed PO, duplicate bill IDs, unaccepted GRN, already-linked bill, zero selection, duplicate live number, and `gross_total` differing from selected bill totals. Test identical retry and concurrent claim.
- [ ] Run `SupplierInvoiceSubmissionTest.php`; expect new cases to fail.
- [ ] Under one transaction lock PO, vendor, and selected bills in ascending ID order. Require every bill to be Draft, stock/PO/GRN linked, accepted, same vendor/PO, and unclaimed; permit a posted/unpaid/unclaimed bill only when it predates cutover and has no payments. Check gross amount with `Money::cmp`, then create header and set `supplier_invoice_id` on each bill. Store file once with `portal_shipping_documents.supplier_invoice_id`; delete provisional storage on rollback. Make retry identity include normalized selected bill IDs, date, and amount. Return 422 for a changed retry.

```php
if (Money::cmp($declaredGross, $bills->reduce(
    fn (string $sum, Bill $bill) => Money::add($sum, (string) $bill->total_amount),
    Money::zero(),
)) !== 0) {
    throw new BusinessRuleException('Invoice total does not match selected accepted receipts.');
}
```

- [ ] Emit one `SupplierInvoiceSubmitted` event carrying the header after commit; send one existing-style Finance/supplier notification for the header, with no duplicate on retry. Run focused tests, document authorization tests, and route tests. Commit.

## Task 4: Require an invoice before posting or paying a PO stock bill

**Files:** Modify `api/app/Modules/Accounting/Services/BillService.php`, `api/app/Modules/Accounting/Models/SupplierInvoice.php`, `api/app/Modules/Accounting/Controllers/BillController.php`, `api/app/Modules/Accounting/Resources/BillResource.php`; test `api/tests/Feature/Accounting/BillServiceTest.php`, `api/tests/Feature/Accounting/AutoCreateBillOnGrnAcceptedTest.php`, `api/tests/Feature/Accounting/BillPaymentReservationRaceTest.php`.

**Interface:** A PO/GRN stock bill created after the cutover needs a live `supplier_invoice_id` whose `gross_total` equals its linked bill totals before `postDraft()`. `recordPayment()` and the final `approvePayment()` step must also refuse an unlinked or voided invoice for an unpaid bill, including an older posted-but-uninvoiced bill. Non-stock bills keep their existing workflow.

- [ ] Add tests: auto draft exists without invoice; Finance cannot post it; linked, matched draft can post; posted but uninvoiced historical bill cannot start/complete a new payment; non-stock bill behavior unchanged; paid historical bill is not rewritten. Add tests that a group total changed by bill mutation cannot be posted and that cancelling a posted/unpaid linked bill reverses its journal but flags the invoice for correction and blocks remaining group payments.
- [ ] Run focused tests; expect missing-invoice cases to fail.
- [ ] Add `SupplierInvoice::matchesLinkedBills(): bool` using `Money::cmp` against the sum of live linked bill totals. Add one shared bill guard and call it at `postDraft()`, `recordPayment()`, and immediately before final payment posting in `approvePayment()`. Scope the guard to PO/GRN stock bills. Return a specific error: `Supplier invoice required before this bill can be posted or paid.` Use the header's live state and recomputed group total; keep the existing three-way-match check.

```php
if ($bill->provenance_type === 'stock' && $bill->purchase_order_id && $bill->goods_receipt_note_id) {
    $invoice = $bill->supplierInvoice;
    if (! $invoice || $invoice->voided_at || ! $invoice->matchesLinkedBills()) {
        throw new BusinessRuleException('Supplier invoice required before this bill can be posted or paid.');
    }
}
```

- [ ] Add a Finance-only void action for an invoice when all linked bills are still Draft with zero payments: require a reason, mark header voided, unlink drafts, retain audit and file, and block direct cancellation of linked Draft bills. A posted invoice cannot be voided through this action. For an unpaid posted linked bill, keep the existing reasoned `cancel()` reversal, retain its link, and derive `requires_correction` when any linked bill is Cancelled; prevent new group payments. Test both paths.
- [ ] Run focused AP, three-way match, supplier submission, and payment tests. Commit gate and void action only after Task 3 is working.

## Task 5: Show one invoice in Supplier and linked bills in Finance

**Files:** Create `api/app/Modules/B2B/Resources/SupplierInvoiceResource.php`; modify `api/app/Modules/B2B/Controllers/SupplierPortalController.php`, `api/app/Modules/B2B/Services/SupplierPortalService.php`, `api/app/Modules/B2B/Resources/SupplierPurchaseOrderResource.php`, `api/app/Modules/Accounting/Services/BillService.php`, `api/app/Modules/Accounting/Resources/BillResource.php`, `api/app/Modules/B2B/routes.php`, `spa/src/types/b2b.ts`, `spa/src/api/b2b/supplier.ts`, `spa/src/pages/portal/supplier/dashboard.tsx`, `spa/src/pages/portal/supplier/statement-of-account.tsx`, `spa/src/pages/portal/supplier/purchase-orders/detail.tsx`, `spa/src/pages/portal/supplier/invoices/index.tsx`, `spa/src/pages/portal/supplier/invoices/detail.tsx`, `spa/src/pages/accounting/bills/detail.tsx`; test `api/tests/Feature/B2B/SupplierPortalServiceTest.php`, `spa/src/pages/portal/supplier/purchase-orders/detail.test.tsx`.

**Interface:** Portal PO detail returns `invoiceable_bill_portions: [{id, grn_id, grn_number, quantity, gross_total}]` and grouped invoices. New supplier-invoice list/detail endpoints use invoice headers, aggregate `paid_amount`/`balance` from linked bills, show included GRNs, and show `requires_correction` if a linked bill was cancelled. AP bill detail retains its singular GRN plus linked supplier invoice number/date/file/group total. Keep old bill-based `/invoices/{bill}` and PDF responses during compatibility; new grouped view must label its generated PDF as an AP summary, not the supplier's original invoice. Keep legacy response fields until client migration is verified.

- [ ] Add API tests for supplier vendor isolation, one invoice row from two bills, aggregate balance after paying one bill, authorized document downloads, and old bill URL compatibility. Add UI tests for one eligible row (read-only), two eligible rows (selectable), subset selection, gross total, mismatch response, and one grouped invoice in list/detail/dashboard/statement.
- [ ] Run focused API/Vitest tests; expect new cases to fail.
- [ ] Reuse the existing `Submit invoice` modal. Show receipt/accepted quantity/amount per eligible portion. Auto-select its sole row; allow explicit selection when several exist. Display total of selected rows and collect supplier gross total. Remove the disabled GRN dropdown only after the new bill-portion payload is wired. Show one supplier invoice with per-GRN breakdown in list/detail/dashboard and group the statement's open bills by invoice. Make AP bill link to its group. Retain old bookmarked bill-detail URLs through a legacy fallback; show their document as an AP bill PDF, not as the supplier's uploaded invoice.

```ts
const selectedGross = portions
  .filter((portion) => selectedIds.includes(portion.id))
  .reduce((total, portion) => total + Math.round(Number(portion.gross_total) * 100), 0);
// Render cents as currency; backend Money comparison remains authoritative.
```

- [ ] Run focused API/Vitest tests, `npm run typecheck`, and targeted ESLint. If typecheck fails in unrelated files, record exact pre-existing errors rather than editing them. Commit the portal/AP projection change.

## Task 6: Prove role-based E2E and deploy safely

**Files:** Create `api/tests/Feature/E2E/MultiGrnSupplierInvoiceTest.php`; update `docs/testing/supplier-po-e2e-runbook.md` if present, otherwise create it.

- [ ] In isolated test data, use supplier, Warehouse, Quality, and Finance principals. Create PO 20,000 pcs; receive and accept 12,000 then 8,000 on separate GRNs. Assert two drafts and zero posted payables before supplier action. Submit one invoice for both, verify two AP matches, post both, pay one, confirm grouped partial balance; pay the other, confirm Paid. Repeat with one receipt invoiced first and the second later. Assert wrong-role and wrong-vendor mutations fail.
- [ ] Run `docker compose exec -T -e DB_DATABASE=ogami_test_supplier_invoice api php artisan test tests/Feature/E2E/MultiGrnSupplierInvoiceTest.php`; expect pass. Run all focused tests from Tasks 1–5. Use an isolated database for a migration up/down rehearsal with existing bill and payment rows.
- [ ] Document exact role/menu handoff from supplier submission to AP payment and the partial-receipt branch. State clearly that one supplier invoice can contain several internal AP bills and that payments still occur per bill. Include an audit query/report of pre-cutover paid-but-uninvoiced PO bills for Finance to reconcile manually, with no ledger mutation. Do not change live `PO-202610-0001` during automated proof.
- [ ] Inspect only this feature's diff, confirm no unrelated working-tree files included, then deploy API migration before API code and SPA. Verify one- and two-GRN invoice screens with a test supplier/PO, posting guard, invoice file access, and one notification. Never rehearse rollback against the live database.

## Release order and known boundary

1. Additive data model/backfill, then accepted-portion fix.
2. Group submission API, then posting/payment gates, then Supplier/Finance screens.
3. Role-based E2E proof, isolated migration rehearsal, deployment.

This release does **not** add a single bank-transfer transaction allocated across several bills. Existing payments remain per bill; supplier-invoice balance is the sum. If one remittance is required later, design a separate approval and allocation workflow before representing one transfer in the ledger.
