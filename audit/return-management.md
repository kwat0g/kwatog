# Audit: return-management — 2026-09-06

## Summary

The module is in notably good shape: decimal-safe bcmath throughout, lockForUpdate-serialized
source reservations with submit-time revalidation (double-return races closed), a formal
state machine, quarantine-before-restock with an IATF-correct Quality gate
(`ensureReturnInspectionsPassed` blocks dispose until every product inspection passes),
idempotent stock movements, maker-checker enforced by ApprovalService, and a strong test
suite (183 filtered tests green, run during this audit). No Critical money or security
defect was found. The significant findings are: (1) the approval chain works at the API but
is invisible — ReturnRequest is absent from `ApprovalTypeRegistry`, so pending RMAs never
appear on the Approval Queue/board (PS-01 class, different shape than PO/loans); (2) the
AB-06 seam — RM forwards `customer_id`/`invoice_id` (and `vendor_id`/`bill_id`) to
CreditNoteService with no header party cross-check, and its test fixture depends on that
gap; (3) several stuck-process edges (no exit from Approved; finance-only-with-item stock
stranded in quarantine; supplier dispose hard-fails when the bill can't take the credit);
(4) the invoice-line return limit is invoiced qty, not delivered qty; (5) returns with no
invoice header complete without ever creating a credit note.

## Findings

| ID | Category | Sev | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| RM-01 | Stuck process (PS-01 class) | High | M | Pending RMA approvals are invisible: no Approval Queue card, no chain on detail page | api/app/Common/Support/ApprovalTypeRegistry.php:20-62; api/app/Common/Services/ApprovalBoardService.php:160-163; api/app/Modules/ReturnManagement/Resources/ReturnRequestResource.php (no approval records) | `ApprovalTypeRegistry::TYPES` lists leave/pr/po/loan/payroll only; board loop does `if (! $meta) continue;`, so `return_request` approval_records are dropped from all four columns. Dept_head/production_manager hold `return_management.approve` (RolePermissionSeeder:626,768) and the API chain works end-to-end (SupplierReturnLifecycleTest:225-229), but approvers only find work by manually browsing the RMA list. Resource/SPA expose no pending-step info (spa detail.tsx gates Approve only on permission). |
| RM-02 | Risk (AB-06 seam) | Medium | M | RMA forwards header invoice/bill ids to CreditNoteService with no party cross-check; fixture depends on the gap | api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1361-1370 (customer), 1238-1253 (supplier); api/app/Modules/Accounting/Services/CreditNoteService.php:364-393; api/tests/Feature/ReturnManagement/CustomerReturnRestockOnDisposeTest.php:56-59,135,155,205,238 | See detail below. |
| RM-03 | Stuck process | Medium | M | No exit from Approved: unreturned goods strand the RMA and its source reservation forever | api/app/Modules/ReturnManagement/Support/ReturnRequestStateMachine.php:25; Services/ReturnRequestService.php:1815-1821 | TRANSITIONS: Approved → [Received] only; cancel() accepts draft/pending_approval only; reject() explicitly refuses post-approval. If goods never arrive, the RMA stays Approved and its active `return_request_source_allocations` row keeps blocking the source line's remaining quantity indefinitely. |
| RM-04 | Risk | Medium | M | Invoice-line return limit is invoiced quantity, not delivered; source document status unchecked | Services/ReturnRequestService.php:491 (`sourceLimit`), 256-277 (`resolveSource`) | `invoice_item` limit = `InvoiceItem::quantity`. An over-invoiced line (invoiced 10, delivered 8) permits returning 10 — context rule is "can't return more than delivered". `sales_order_item` (quantity_delivered) and `delivery_item` limits are correct. resolveSource also never checks the parent invoice/SO/delivery status; only the `sourceOptions` picker filters draft/cancelled (Controller:154,177,199), so direct API calls can source lines from draft/cancelled documents. |
| RM-05 | Gap | Medium | M | RMA without invoice header completes with no credit note at all (silent under-credit) | Services/ReturnRequestService.php:1016 (`if (... && $rma->invoice_id)`), 1313-1370 | dispose() creates the customer credit note only when `$rma->invoice_id` is set. A customer return sourced from an SO/delivery line (invoice_id nullable in StoreReturnRequestRequest) restocks goods and completes with no credit note and no warning. Legitimate when the customer was never invoiced, but indistinguishable from "invoice exists, clerk didn't link it" — and finance-only RMAs (whose entire purpose is a credit) also complete creditless if no invoice was linked. |
| RM-06 | Bad practice | Low | S | Draft-edit bypass gated on unrelated `admin.roles.manage` permission | Services/ReturnRequestService.php:123 | "Only the draft creator or a system administrator" is implemented as `hasPermission('admin.roles.manage')` — the role-administration slug. system_admin passes via wildcard, but the semantic gate is wrong; a role-management grant should not authorize editing other users' RMA drafts. |
| RM-07 | Risk | Medium | M | Supplier dispose is all-or-nothing on bill creditability; closed period or unposted/mismatched bill rolls back the whole disposition | Services/ReturnRequestService.php:1237-1266; CreditNoteService.php:179 (assertPostingAllowed), 280-291 (apply guards) | Supplier path finalizes the credit note and applies it inside the dispose transaction. If the bill is not posted/unpaid-partial, belongs to another vendor (see RM-02), or today's accounting period is closed, BusinessRuleException rolls back GRN reversal, stock ship-out and NCRs too. Customer path (draft CN, no GL) is unaffected — asymmetric fragility. No fallback to create an unapplied credit. |
| RM-08 | Stuck process | Low | S | Finance-only line with item_id quarantines stock at receive that no disposition can release | Services/ReturnRequestService.php:680 (no finance_only check), 1153-1155 (finance-only ⇒ no_return only), 1756-1773 (`shouldMove` excludes no_return) | Validation permits item_id on finance-only lines (StoreReturnRequestRequest only requires it for non-finance stockables). receive() then books quarantine AdjustmentIn; the only legal disposition (no_return) triggers no movement → units sit in quarantine permanently. |
| RM-09 | Risk | Low | S | Supplier dispose can resurrect a cancelled PO's receipt status | Services/ReturnRequestService.php:1280-1300 | `recalculatePurchaseOrderReceiptStatus` forceFills Approved/PartiallyReceived/Received from accepted sums without excluding terminal PO statuses; a PO cancelled after receipt would be revived. |
| RM-10 | Bad practice (valuation) | Low | L | Returned goods re-enter stock at zero cost, diluting WAC | Services/ReturnRequestService.php:688-697 (no unitCost); api/app/Modules/Inventory/Services/StockMovementService.php:94-102; CustomerReturnRestockCostTest.php:183 | Quarantine receipt inherits destination WAC; a fresh quarantine location costs 0.00, and the restock Transfer carries that 0 into the good-stock location, blending sellable stock value-neutral on quantity (value understated). Pinned by test; cost accounting is cut scope — flag for the record. |
| RM-11 | Gap | Low | S | No B2B customer-portal RMA submission path | api/app/Modules/B2B/Controllers/CustomerPortalController.php | Scope assumed "customer portal return submissions"; the portal exposes SOs, invoices, deliveries, complaints/8D — no return endpoint. Not a defect; recorded so consolidation doesn't assume it exists. |

### RM-02 detail — the AB-06 fixture dependency, characterized exactly

**What RM passes and assumes.** `createCreditNote()` (ReturnRequestService.php:1361-1370)
forwards `$rma->customer_id` and `$rma->invoice_id` verbatim to `CreditNoteService::create()`
and assumes the invoice belongs to the customer. `processSupplierDisposition()` (:1238-1253)
does the same with `vendor_id`/`bill_id`. **Where the cross-check is missing:** RM validates
invoice ownership only for *invoice_item-sourced lines* (resolveSource :258-263 checks
`source->invoice->customer_id === rma->customer_id` and ties the line's invoice to the RMA
header). For SO-sourced, delivery-sourced, and finance-only RMAs the header `invoice_id` is
never checked against `customer_id` at store/update or at credit creation; likewise `bill_id`
is never checked against `vendor_id` (bill-item provenance at :349-353 checks bill/item
identity only). **What the fixture needs:** `CustomerReturnRestockOnDisposeTest::customer()`
(:56-59) mints a NEW Customer row on every call, and tests at :135/:155/:205/:238 call it
twice — once for the RMA's `customer_id`, once inside `invoice()` — so the fixture's RMA
carries another customer's invoice, and the inspectedRma builder bypasses the service
(direct `ReturnRequest::create`), so no provenance check ever runs. Three dispose-time tests
(155/205/238) go red if CreditNoteService grows the `invoice.customer_id === cn.customer_id`
guard. **Current real-world impact:** money cannot cross — `apply()` re-checks the party
(CreditNoteService:243/277) — but `CreditNoteResource:41` serialises the foreign
invoice_number onto the note (cross-party metadata disclosure + false audit link), and on the
supplier side a vendor-mismatched `bill_id` survives until `apply()` inside dispose, then
rolls back the entire disposition (RM-07). **Fix direction for consolidation:** (1) RM adds
header-party validation at store/update (invoice→customer, bill→vendor) and asserts it again
before both credit-note calls; (2) fixture fixed to one customer; (3) AB-06 guard lands in
CreditNoteService. All three in one change; RM owns 1+2.

## Cross-module flags

- **Accounting / CreditNoteService (AB-06):** guard exists-and-reverted; landing requires the
  coordinated RM change above. Target unit: accounting.
- **Common / Approvals surface:** `ApprovalTypeRegistry` + `ApprovalBoardService` need a
  `return_request` entry (kind/label/table/link `'/return-management/'`/permissions
  `['return_management.view','return_management.approve']`). Per CLAUDE.md aggregator rules,
  the board should call a module row scope — RM has none (all view-holders see all RMAs, same
  as payroll), so state that explicitly at the call site when adding it. Target unit:
  common/approvals + RM.
- **Inventory:** zero-cost AdjustmentIn WAC inheritance (RM-10) is Inventory behavior pinned
  by an RM test; flag only.
- **Quality:** no defects found; the return-stage inspection gate and retry/recovery path are
  sound and well-tested.
- **B2B:** no RMA submission path exists (RM-11); scope assumption not met.

## What was NOT checked

- create.tsx (799 lines) beyond grep-level checks; list/detail/dispose reviewed more closely.
- Full fixture review of DispositionTest/SupplierReturn* tests for similar double-customer
  minting (only CustomerReturnRestockOnDisposeTest confirmed against the AB-06 note).
- ReturnRequestUpdateMail content, ChainBroadcaster/Reverb payloads, Dashboard
  ReturnWidgetAnalytics, migration-level DB constraints (checked service-level invariants
  instead), `approval_records` attempt/supersede edge cases beyond cancel().
- No concurrent-load proof of the reservation race (code-read only; the locking order is
  sound: source-row lock held across allocation insert inside one transaction).
- Replacement-PO downstream lifecycle after creation (Purchasing's concern).
