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

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Scope and verdict

This is a read-only re-audit of the Return Management backend, employee SPA pages/API/types,
the customer-portal return dependency, the approval-board dependency, current Return Management
tests, relevant migrations, and the directly used Accounting/Inventory services. The prior audit's
RM-01 approval-visibility defect and RM-05 silent no-invoice credit defect are fixed in the current
commit. RM-11 is also closed as a scope assumption: a customer-portal RMA path now exists.

The main unresolved controls are still source-document authority, post-approval recovery,
supplier-credit failure recovery, finance-only stock classification, and cancellation/status
invariants. A new SPA cache invalidation gap can leave the RMA list stale after successful workflow
actions. No Critical finding was identified by code reading.

### Prior finding status

| ID | Status at `56e0d431` | Current evidence |
|---|---|---|
| RM-01 | **Fixed, with a residual UX gap recorded as RM-13** | `ApprovalTypeRegistry` now registers `ReturnRequest` (`api/app/Common/Support/ApprovalTypeRegistry.php:84-97`); the board resolves it (`api/app/Common/Services/ApprovalBoardService.php:140-153,196-223`); the resource and detail page expose/render the chain (`api/app/Modules/ReturnManagement/Resources/ReturnRequestResource.php:22-31,159-171`; `spa/src/pages/return-management/detail.tsx:695-698`). `ReturnRequestApprovalChainTest.php:49-95` and `ApprovalBoardScopeTest.php:310-338` pin the surfaces. |
| RM-02 | **Unresolved** | Customer and supplier credit-note calls still receive independently stored header party/source IDs without a header cross-check (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:2023-2032,1884-1912`; Accounting's deferred guard remains `api/app/Modules/Accounting/Services/CreditNoteService.php:364-393`). |
| RM-03 | **Unresolved** | `Approved` still transitions only to `Received` (`api/app/Modules/ReturnManagement/Support/ReturnRequestStateMachine.php:25`); cancel accepts only draft/pending rows (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:2478-2487`). |
| RM-04 | **Unresolved** | Invoice source limits still use invoiced quantity, and source resolution still does not enforce parent document status (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:842-862,1074-1080`; picker-only status filters are in `api/app/Modules/ReturnManagement/Controllers/ReturnRequestController.php:152-155`). |
| RM-05 | **Fixed** | Customer dispose now calls `createCreditNote()` without requiring `invoice_id` (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1614-1627`), and the no-invoice SO-source regression is covered by `api/tests/Feature/ReturnManagement/CustomerReturnNoInvoiceCreditTest.php:49-100`. |
| RM-06 | **Unresolved** | Draft ownership is still bypassed by the unrelated `admin.roles.manage` permission (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:700-706`). |
| RM-07 | **Unresolved** | Supplier dispose still finalizes and applies the credit inside the same transaction (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1884-1912`); a closed/unposted/mismatched target bill or closed posting period rolls back receipt/PO changes, credit creation, and stock movement with no unapplied/manual fallback. |
| RM-08 | **Unresolved** | Finance-only item lines remain accepted by the request contract (`api/app/Modules/ReturnManagement/Requests/StoreReturnRequestRequest.php:140-154`); receive quarantines them (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1266-1290`), while the only legal finance-only disposition is `no_return`, which performs no release (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1750-1756,2424-2438`). |
| RM-09 | **Unresolved** | PO receipt-status recalculation still assigns `Approved`/`Sent`/`PartiallyReceived`/`Received` without excluding terminal PO status (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1927-1949`). |
| RM-10 | **Unresolved, policy-dependent** | Customer restock/rework movement supplies no explicit cost (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:2329-2341`); Inventory therefore uses destination WAC or zero for a fresh location (`api/app/Modules/Inventory/Services/StockMovementService.php:87-105`). The existing test deliberately pins zero cost for an empty destination (`api/tests/Feature/ReturnManagement/CustomerReturnRestockCostTest.php:183-208`). |
| RM-11 | **Closed** | Customer-portal routes now expose source options, list, detail, and create (`api/app/Modules/B2B/routes.php:157-164`; `api/app/Modules/B2B/Controllers/CustomerPortalController.php:409-462`), with tenant checks and server-side source/item/price resolution (`api/app/Modules/B2B/Services/CustomerPortalService.php:387-429`; `api/tests/Feature/B2B/CustomerReturnPortalTest.php:110-331`). |

### Findings

#### RM-12 — RMA list remains stale after successful mutations

- **Category:** Gap
- **Severity:** Medium
- **Effort:** S
- **Location:** `spa/src/pages/return-management/list.tsx:36-39`; `spa/src/pages/return-management/create.tsx:424-428`; `spa/src/pages/return-management/detail.tsx:117-220`; `spa/src/pages/return-management/dispose.tsx:77-80`
- **Evidence/reproduction:** The list query key is `['return-requests', filters]`, but create success invalidates `['return-management']`, which does not match it. Detail workflow mutations invalidate only `['return-request', id]`, and dispose does the same. Create an RMA, approve/receive/dispose/complete it, then return to an already-visited list page: the cached row/status/count remains until an unrelated refetch or staleness interval. This violates the required mutation pattern of invalidating the affected list as well as the detail record and can make an RMA appear pending after it has advanced.
- **Disposition:** New frontend consistency defect. The server remains authoritative and direct navigation refetches the detail, so this is not an authorization or data-integrity bypass.

#### RM-13 — Approval action buttons are not limited to the current step

- **Category:** Bad practice
- **Severity:** Low
- **Effort:** S
- **Location:** `spa/src/pages/return-management/detail.tsx:244-248,371-392`; `api/app/Modules/ReturnManagement/Resources/ReturnRequestResource.php:26-31`
- **Evidence/reproduction:** For every `pending_approval` RMA, the detail page offers both Approve and Reject to every user with `return_management.approve`; it does not consume the resource's `pending_approval_step` or the pending record's `role_slug`. A `production_manager` opening a step-1 RMA therefore sees an enabled Approve button, submits it, and receives the backend's expected-step rejection until the `department_head` acts. The backend permission and ApprovalService checks still prevent unauthorized mutation, but the UI presents an illegal action instead of the next legal action or a reason it is unavailable.
- **Disposition:** Residual UX issue after RM-01's board/detail visibility fix; not a backend security defect.

#### RM-02 — Header party/source provenance is still independently trusted

- **Category:** Risk (AB-06 seam)
- **Severity:** Medium
- **Effort:** M
- **Location:** `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:2023-2032,1884-1912`; `api/app/Modules/ReturnManagement/Requests/StoreReturnRequestRequest.php:64-102`
- **Evidence/reproduction:** Create a customer RMA for customer A with a valid sales-order/delivery line belonging to A but set `invoice_id` to an invoice belonging to customer B. The source-line checks validate the selected line's party, but no check binds the RMA header invoice to `customer_id`; `createCreditNote()` then forwards both independently. The resulting customer credit note can carry customer A and invoice B, exposing B's invoice number as a false audit link even though later credit application rejects a cross-party application. The supplier path has the analogous `vendor_id`/`bill_id` gap; a mismatched bill survives until apply and can trigger RM-07's full rollback. The existing fixture still demonstrates the dependency by creating separate customers at `CustomerReturnRestockOnDisposeTest.php:135,155,205,238` while `customer()` mints a new row at lines 56-59.

#### RM-03 — Approved returns have no expiry, cancellation, or compensating exit

- **Category:** Stuck process
- **Severity:** Medium
- **Effort:** M
- **Location:** `api/app/Modules/ReturnManagement/Support/ReturnRequestStateMachine.php:20-32`; `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:2478-2487,2514-2519`
- **Evidence/reproduction:** Submit and fully approve an RMA, then do not receive the goods. The row remains `approved`; the only legal next state is `received`, and the cancel endpoint refuses it. Its active source allocation is still counted because allocation queries exclude only rejected/cancelled returns (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:969-983,994-1010`). The source line's returnable headroom is therefore blocked indefinitely with no expiry, operator cancellation, or release-with-reason path.

#### RM-04 — Invoice returnability is still based on invoiced quantity and picker filtering is not enforcement

- **Category:** Risk
- **Severity:** Medium
- **Effort:** M
- **Location:** `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:842-862,1074-1080`; `api/app/Modules/ReturnManagement/Controllers/ReturnRequestController.php:152-155`; `api/app/Modules/B2B/Services/CustomerPortalService.php:140-143,348-376`
- **Evidence/reproduction:** For an invoice line with quantity 10 but only 8 delivered, the source picker advertises and `sourceLimit('invoice_item')` reserves against 10, permitting a return of 10. The source-options controller filters draft/cancelled parent documents, but `resolveSource()` does not recheck invoice/SO/delivery status, so a direct internal API request can submit a draft/cancelled source. The customer portal has the same direct-source status omission in `resolvePortalReturnLine()`; its picker is not an authorization boundary. The context rule remains that a return cannot exceed physically delivered quantity and must reference an eligible source document.

#### RM-06 — Draft ownership uses an unrelated role-administration permission

- **Category:** Bad practice
- **Severity:** Low
- **Effort:** S
- **Location:** `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:700-706`
- **Evidence/reproduction:** A user who is not the draft creator can edit a draft if granted `admin.roles.manage`; that permission describes role administration, not RMA stewardship. A custom role grant therefore authorizes edits to another user's RMA without holding a Return Management management permission, while a legitimate system-admin semantic check is represented only indirectly by the wildcard permission behavior. The route/FormRequest gate still requires `return_management.manage`, but the service's second authorization decision is incorrectly keyed.

#### RM-07 — Supplier credit failure rolls back the entire physical return

- **Category:** Stuck process
- **Severity:** Medium
- **Effort:** M
- **Location:** `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1778-1791,1884-1912`; `api/app/Modules/Accounting/Services/CreditNoteService.php:164-199,273-291`
- **Evidence/reproduction:** Dispose a supplier RMA against a bill that is closed, not posted to GL, vendor-mismatched, or in a closed posting period. The service updates/locks receipt sources, finalizes the credit, applies it, and only then moves stock, all inside the dispose transaction. `CreditNoteService::finalize()`/`apply()` throws, so the GRN/PO quantity reversal, stock shipment, disposition status, replacement PO, and credit note all roll back. There is no durable “credit created but unapplied/manual required” outcome for Finance to resolve, so a real supplier return remains operationally blocked by a downstream accounting prerequisite.

#### RM-08 — Finance-only lines can still quarantine goods that no legal disposition releases

- **Category:** Stuck process
- **Severity:** Low
- **Effort:** S
- **Location:** `api/app/Modules/ReturnManagement/Requests/StoreReturnRequestRequest.php:140-154`; `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1266-1290,1750-1756,2424-2438`
- **Evidence/reproduction:** Submit a customer `finance_only=true` RMA with both `product_id` and `item_id` and a positive quantity. The request validator permits the item, `receive()` books an `AdjustmentIn` into quarantine for any customer item line, and the finance-only disposition matrix permits only `no_return`; `shouldMove()` then excludes that disposition. The credit can complete while the quarantined units remain held forever. The normal SPA does not expose the item selector in finance-only mode, but the API contract is reachable directly and the service does not reject the contradictory classification.

#### RM-09 — Supplier disposal can revive a cancelled PO

- **Category:** Risk
- **Severity:** Low
- **Effort:** S
- **Location:** `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1927-1949`
- **Evidence/reproduction:** Receive a PO, cancel it later, then dispose a supplier return against its lines. `recalculatePurchaseOrderReceiptStatus()` derives a non-terminal receipt status and force-fills it without checking whether the PO is cancelled or otherwise terminal. The cancellation is silently replaced by `sent`, `approved`, `partially_received`, or `received`, corrupting the PO lifecycle and chain history.

#### RM-10 — Customer restock cost remains value-neutral/zero-cost rather than provenance-based

- **Category:** Risk
- **Severity:** Low
- **Effort:** L
- **Location:** `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:2329-2341`; `api/app/Modules/Inventory/Services/StockMovementService.php:87-105`; `api/tests/Feature/ReturnManagement/CustomerReturnRestockCostTest.php:183-208`
- **Evidence/reproduction:** Dispose a customer return into a fresh good-stock location. The RMA transfer supplies no unit cost; Inventory resolves a pure receipt to the destination WAC and uses `0.00` when that location is empty. The returned quantity therefore enters the ledger with zero value and can dilute inventory valuation. The current test explicitly expects that behavior, so this is a confirmed policy/valuation gap rather than an unproven crash. Cost accounting is cut scope, but the finding remains relevant to weighted-average inventory integrity.

### Clean areas and changed controls

- The approval-board registration and RMA detail approval timeline now close the original RM-01 invisibility path. The registry explicitly documents that Return Management has no row scope, matching its list endpoint (`api/app/Common/Support/ApprovalTypeRegistry.php:84-97`; `api/app/Common/Support/ApprovalSourceScope.php:36-39,49-56`).
- Customer no-invoice credit creation is now explicit and tested. SO/delivery-backed customer returns can create a draft credit note with a null invoice reference rather than silently completing creditless (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1963-2032`; `api/tests/Feature/ReturnManagement/CustomerReturnNoInvoiceCreditTest.php:49-100`).
- Hash-ID decoding is present at the request boundary for party, source-line, item, warehouse-location, and RMA line inputs; resources serialize related IDs as hashes (`api/app/Modules/ReturnManagement/Requests/StoreReturnRequestRequest.php:35-61`; `api/app/Modules/ReturnManagement/Requests/ReceiveReturnRequest.php:42-88`; `api/app/Modules/ReturnManagement/Resources/ReturnRequestItemResource.php:15-60`).
- Source reservations are transactionally created/revalidated under source-line locks, and the current database backstop constrains status, source kind, and non-negative allocation values (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1013-1072`; `api/database/migrations/2026_08_26_040000_harden_return_request_status_and_allocations.php:39-119`).
- Dispose and complete now enforce disposition completeness and prevent completion before disposal (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1711-1765,2213-2251`), while movement stamps make dispose-time restock/ship-out idempotent (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:2051-2100,2277-2372`).
- Return-stage Quality handoff has an explicit manual-required/retry path, active inspection gating, and decimal-safe batch sizing (`api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:1311-1502,2548-2655`; `api/app/Modules/ReturnManagement/Listeners/CreateReturnInspectionOnRequested.php:68-107`).
- The customer portal is now a real RMA entry point with customer-scoped source queries, server-resolved finished-good item and source price, draft-only creation, and explicit foreign-customer rejection (`api/app/Modules/B2B/Services/CustomerPortalService.php:387-429`; `api/tests/Feature/B2B/CustomerReturnPortalTest.php:171-263`).
- Internal RMA routes are behind both Sanctum and the Return Management feature gate, with separate action permissions (`api/app/Modules/ReturnManagement/routes.php:18-40`).

### Verification limits

- No tests, Docker commands, or Artisan commands were run, per request. Test files were read for regression intent and coverage claims only; this report does not independently verify that the suite passes on `56e0d431`.
- No live HTTP, queue worker, mail provider, Reverb/WebSocket, scheduler, PostgreSQL concurrency, or browser behavior was exercised.
- Concurrent source reservation safety was inspected statically but not proven with a two-connection run.
- The audit did not re-audit the complete Accounting Credit Note policy, Inventory valuation policy, Purchasing replacement-PO lifecycle, or the shared approval-board implementation beyond the RMA integration points.
- The customer portal SPA was outside the requested SPA page scope; its backend dependency was read only to recheck RM-11 and shared provenance behavior.

### No code change

No application code, migrations, tests, permission/approval registry, seed data, or roadmap content was modified. The only file changed by this re-audit is `audit/return-management.md`, to append this section.
