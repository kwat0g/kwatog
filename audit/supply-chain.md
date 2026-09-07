# Audit: supply-chain — 2026-09-06

## Summary

The money path is in good shape: confirm() serializes on the delivery row lock and is
idempotent (no double-confirm/double-invoice from this side), quantity reservation is
fail-closed on both the SO line and the output-bound inspection (locked, summed over
reserving statuses, over-delivery refused), the outgoing-QC gate is honored exactly as
the Quality audit described, and the driver surface is self-scoped (own deliveries only,
no confirm). The real breaks are at the seams: CR-01 confirmed and characterized on our
side (remaining deliveries of an already-invoiced SO can never be confirmed — the SO
promotion call throws uncaught inside confirm's transaction), the SPA delivery-create
form filters SOs to `confirmed` only so QC-passed (in_production) orders never appear,
several restore routes/files are dead, and landed-cost input is unreachable over HTTP.
Test run: `--filter='SupplyChain'` → 181 passed / 0 failed (158 s).

## Findings

| ID | Category | Sev | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| SC-01 | Broken process | High | M | CR-01 our side: confirm() of remaining delivery rolls back when SO already `invoiced` | api/app/Modules/SupplyChain/Services/DeliveryService.php:916-924 | `markDelivered/markPartiallyDelivered` → `transitionOrFail` → `BusinessRuleException` ("Transition from invoiced to … not allowed"), uncaught inside confirm's `DB::transaction` → status flip, qty sync, invoice handoff all roll back; delivery stuck at `delivered` forever |
| SC-02 | Broken process | High | S | Delivery create form only offers `confirmed` SOs; deliverable orders are absent | spa/src/pages/supply-chain/deliveries/create.tsx:51-55 | `salesOrdersApi.list({ status: 'confirmed' })`; WO start moves SO to `in_production` (WorkOrderService.php:371), so any SO with passed outgoing QC is filtered out — no UI path to schedule the first (or split) delivery; backend accepts these SOs |
| SC-03 | Broken process | Medium | S | Delivery + proof restore routes lack `->withTrashed()` → 404 for every archived row | api/app/Modules/SupplyChain/routes.php:135-136,149-150 | Sibling restore routes (shipments/docs/containers/vehicles) have it; these two don't. SPA proof "Archive" dialog says "can be restored later" (deliveries/detail.tsx:771) — it cannot |
| SC-04 | Broken process | Medium | M | Delete paths physically destroy files while restore routes stay advertised | api/app/Modules/SupplyChain/Services/ShipmentService.php:204-227; api/app/Modules/SupplyChain/Services/DeliveryService.php:1288-1299 | `deleteDocument()`/`delete()` delete disk files after commit; `restoreDocument`/`restore` (with `withTrashed`) then bind a row whose file is gone → download 404s. Same for delivery proof files when a delivery is deleted then restored |
| SC-05 | Stuck process | Medium | S | Landed-cost inputs unreachable over HTTP; feature computes zero for every shipment | api/app/Modules/SupplyChain/Services/ShipmentService.php:156-166; Requests/CreateShipmentRequest.php | `freight_cost/insurance_cost/duties_amount/brokerage_fee/other_charges` are fillable but in no create/updateMeta allow-list; `calculate()` always sees 0.00. Known + probe-locked (ImportShipmentCustomsAuditTest `test_probe_no_http_path_can_enter_a_landed_cost`) |
| SC-06 | Risk | Medium | S | Shipment creatable against any PO status incl. cancelled/closed/received | api/app/Modules/SupplyChain/Services/ShipmentService.php:90-118 | No PO-status gate in `create()`; probe-measured all 6 PO statuses accepted (ImportShipmentCustomsAuditTest:1044) |
| SC-07 | Risk | Medium | S | CoC proof deletable after confirmation | api/app/Modules/SupplyChain/Controllers/DeliveryProofController.php:171-177 | Guard only blocks deleting the LAST proof; a confirmed delivery with photo + auto-attached CoC can lose the CoC. Probe-locked (ZzM044 `test_probe_coc_can_be_deleted_and_replaced_after_confirmation`). IATF record integrity — flag Quality |
| SC-08 | Bad practice | Medium | S | Delivery item quantity emitted as float | api/app/Modules/SupplyChain/Resources/DeliveryResource.php:115 | `'quantity' => (float) $i->quantity` on a decimal column; SPA type mirrors `quantity: number` (types/supplyChain.ts). Violates decimal-as-string convention |
| SC-09 | Broken process | Medium | S | "Invoice needs Finance action" banner can never render | spa/src/pages/supply-chain/deliveries/detail.tsx:514-541 | Banner requires `data.invoice_handoff.status === 'manual_required' && !data.invoice` but is nested inside `{data.invoice && data.invoice.status === 'draft' && (…)}` — always false; the failed-handoff alert exists only as a notification |
| SC-10 | Gap | Low | M | Restore endpoints do no re-validation | api/app/Modules/SupplyChain/Controllers/DeliveryController.php:164-168 | `$delivery->restore()` re-reserves SO-line + inspection capacity without re-checking SO remaining qty, SO cancellation, inspection capacity or vehicle/driver availability; can over-reserve a line and wedge `syncDeliveredQuantities()` for sibling deliveries. Latent until SC-03 fixed |
| SC-11 | Gap | Low | M | Driver PWA: no offline queue; photo retry duplicates proofs | spa/src/pages/driver/DriverDeliveryDetail.tsx:62-64; DriverPhotoCapture.tsx:45-56 | Label says "queued" but nothing is queued; a retried receipt POST creates a second `DeliveryProof` row (status PATCHes are idempotent server-side, so transitions are safe) |
| SC-12 | Gap | Low | S | Proof upload allowed in any delivery status | api/app/Modules/SupplyChain/Controllers/DeliveryProofController.php:51-86 | `store()` never reads delivery status — proofs can be attached to scheduled/cancelled deliveries (harmless for confirm, but files orphan if the delivery is later deleted) |

### SC-01 detail (High — our side of CR-01)

Exact characterization requested: `confirm()` (DeliveryService.php:916-924) computes
delivery coverage and, for `full`/`partial`, calls `SalesOrderService::markDelivered()`/
`markPartiallyDelivered()`. Those go through `transitionOrFail()`; with the SO already at
`invoiced` (terminal — `ALLOWED_TRANSITIONS['invoiced'] = []`, set there by
`InvoiceService::finalize()` → `markInvoiced()` on the FIRST finalized invoice), the
transition returns a rejection and `transitionOrFail` throws `BusinessRuleException`. The
call sits inside confirm's single `DB::transaction` and is NOT in the invoice-handoff
try/catch, so the whole confirmation rolls back: status flip, confirmed_at/by, receiver
data, qty sync, CoC attach, invoice creation. The delivery stays `delivered`; every retry
fails identically; cancelling the invoice does not demote the SO. Repro: deliver 4/10 →
confirm → finalize draft invoice (SO → invoiced) → deliver 6 → confirm → 422, forever.
Fix needs CRM's transition policy (or demoting delivery promotion to best-effort with a
rejection ledger) — see Cross-module flags. No test covers deliver-after-invoice.

### SC-02 detail (High)

The create form's SO dropdown queries `status: 'confirmed'` only. Since
`WorkOrderService` promotes the SO to `in_production` when the WO starts, an order only
becomes deliverable after it has left `confirmed` — the dropdown is effectively empty for
every order that actually has passed outgoing QC, and partially-delivered orders (split
deliveries) are equally invisible. Backend (`assertDeliveryQuantitiesAvailable`) correctly
accepts any non-cancelled SO, so this is purely a frontend filter. Effort S: include
`in_production` and `partially_delivered` in the list query.

## Cross-module flags

1. **CRM + Accounting (SC-01 / CR-01):** the stuck sequence spans three units. Either the
   SO transition policy must allow `invoiced → partially_delivered/delivered` (or treat
   late promotions as no-ops), or confirm() must degrade the promotion to best-effort.
   Matches audit/crm.md CR-01; our-side characterization above.
2. **Accounting — double-invoice exposure:** `invoices.delivery_id` has no unique
   constraint (0048_create_invoices_table.php:19) and `InvoiceService` never reads
   delivery status (probe-measured M044-F013: AR invoices scheduled/in-transit/cancelled
   deliveries via `delivery_id`). Combined with the auto-draft, one delivery can carry two
   invoices. Critical-class on their side.
3. **Inventory — no FG decrement:** confirming a delivery emits NO stock movement
   (probe-measured; `StockMovementType::Delivery` is never written by any code path).
   Finished-goods stock never leaves the books on shipment.
4. **Quality:** CoC attach at confirm is best-effort (failure → `Log::warning` only,
   never blocks — DeliveryService.php:902-911) and SC-07 lets the attached CoC be deleted
   afterwards; CoC proof integrity is Quality's IATF call.
5. **Purchasing (SC-06):** ShipmentService::create accepts terminal POs; needs
   Purchasing's definition of which PO states may receive shipments.

## What was NOT checked

- PDF blade templates (packing list / commercial invoice content correctness).
- Common services internals (ChainBroadcaster, OutboxService, NotificationService,
  DocumentSequenceService) — only their call sites here.
- Customer/supplier portal surfaces beyond the referenced probe results.
- DB indexes / query performance.
- Full SPA walkthrough of shipments/create.tsx and DriverDeliveryList internals
  (5-state handling spot-checked and present on all pages inspected).
- Full test suite — only `--filter='SupplyChain'` (181 passed, 0 failed).
