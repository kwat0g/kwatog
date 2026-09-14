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

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Re-audit basis

This is a read-only source re-audit at commit `56e0d431e41d74d422ad81684ff50e0b2b49960e`. The requested Supply Chain backend, supply-chain and driver SPA pages, API clients, types, migrations, and directly relevant Supply Chain/Quality tests were read against the prior findings. No tests, Docker, Artisan, browser, database, or live-provider checks were run. The pre-existing worktree changes were left untouched.

### Prior finding status

| ID | Current status | Current evidence |
|---|---|---|
| SC-01 | **Resolved in current source** | `SalesOrderService::ALLOWED_TRANSITIONS` now permits `invoiced → partially_delivered/delivered` (`api/app/Modules/CRM/Services/SalesOrderService.php:59-66,79-85`), so the `DeliveryService::confirm()` promotion at `api/app/Modules/SupplyChain/Services/DeliveryService.php:977-987` no longer necessarily throws on a later delivery. The prior stuck sequence is no longer reproduced by code reading. No new focused test execution was performed here. |
| SC-02 | **Resolved in current source** | The create form now requests `confirmed`, `in_production`, and `partially_delivered` sales orders (`spa/src/pages/supply-chain/deliveries/create.tsx:51-59`). The prior confirmed-only UI filter is gone. |
| SC-03 | **Unresolved, partially narrowed** | Shipment, document, container, and vehicle restore routes have `->withTrashed()` (`api/app/Modules/SupplyChain/routes.php:35-37,56-58,84-86,99-101`), but delivery and proof restore routes still do not (`api/app/Modules/SupplyChain/routes.php:137-152`). Archived delivery/proof rows therefore remain unbindable. |
| SC-04 | **Unresolved** | Shipment deletion removes document files after commit while leaving document metadata live (`api/app/Modules/SupplyChain/Services/ShipmentService.php:204-226`); delivery deletion removes proof files and receipt files after commit (`api/app/Modules/SupplyChain/Services/DeliveryService.php:1352-1363`). Restore is advertised by both SPA dialogs (`spa/src/pages/supply-chain/shipments/detail.tsx:526-550`, `spa/src/pages/supply-chain/deliveries/detail.tsx:765-785`) but cannot recover the physical artifact. |
| SC-05 | **Unresolved** | The landed-cost columns remain absent from both HTTP write allow-lists: `ShipmentService::updateMeta()` only accepts tracking metadata (`api/app/Modules/SupplyChain/Services/ShipmentService.php:156-165`), and `CreateShipmentRequest` validates no landed-cost fields (`api/app/Modules/SupplyChain/Requests/CreateShipmentRequest.php:26-43`). The SPA shipment form also has no cost inputs (`spa/src/pages/supply-chain/shipments/create.tsx:162-189`). |
| SC-06 | **Unresolved** | `ShipmentService::create()` loads any PO by ID and creates an `ordered` shipment without checking its status (`api/app/Modules/SupplyChain/Services/ShipmentService.php:90-117`). The existing probe records every `PurchaseOrderStatus` as accepted (`api/tests/Feature/SupplyChain/ImportShipmentCustomsAuditTest.php:1034-1065`). |
| SC-07 | **Unresolved** | Confirmed-delivery proof deletion still blocks only deletion of the last proof (`api/app/Modules/SupplyChain/Controllers/DeliveryProofController.php:171-181`). A generated CoC can still be archived if another proof remains, and `proof_type=coc` accepts an arbitrary uploaded PDF (`api/tests/Feature/SupplyChain/ZzM044AuditProbeTest.php:83-113`). |
| SC-08 | **Unresolved** | Delivery quantity is still cast to a JavaScript number in the API resource (`api/app/Modules/SupplyChain/Resources/DeliveryResource.php:119-130`), mirrored as `quantity: number` in the API/types (`spa/src/api/supply-chain/index.ts:75-79`, `spa/src/types/supplyChain.ts:93-99`). This remains a decimal-as-string violation. |
| SC-09 | **Unresolved** | The Finance-action banner remains nested inside `data.invoice && data.invoice.status === 'draft'`, while its own condition requires `!data.invoice` (`spa/src/pages/supply-chain/deliveries/detail.tsx:512-541`). A manual-required handoff cannot render in the page. |
| SC-10 | **Unresolved** | Delivery restore still directly calls `restore()` without a transaction or revalidation (`api/app/Modules/SupplyChain/Controllers/DeliveryController.php:171-180`), and its route cannot bind an archived row because SC-03 remains open. It can reintroduce reservations without rechecking SO quantity, cancellation, inspection capacity, or dispatch resources. |
| SC-11 | **Unresolved** | The driver detail page still describes a normal mutation as “queued” but only calls the HTTP mutation (`spa/src/pages/driver/DriverDeliveryDetail.tsx:62-64,49-60`); there is no durable offline queue. Receipt retry posts another upload with no idempotency key (`spa/src/pages/driver/DriverPhotoCapture.tsx:45-56`, `spa/src/api/driver.ts:29-35`). |
| SC-12 | **Unresolved** | `DeliveryProofController::store()` does not inspect delivery status (`api/app/Modules/SupplyChain/Controllers/DeliveryProofController.php:50-85`), so direct API calls can attach proofs to scheduled or cancelled deliveries. The SPA narrows its upload control, but that is not the authorization/state boundary. |

### New or materially expanded findings

| ID | Category | Sev | Effort | Finding | Exact location | Evidence / reproduction |
|---|---|---:|:---:|---|---|---|
| SC-13 | Gap / Broken process | High | M | Import shipments can clear customs and reach `received` with no mandatory evidence, and documents remain mutable after receipt | `api/app/Modules/SupplyChain/Services/ShipmentService.php:120-148`; `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:121-149,212-221` | The status service only checks the enum transition and stamps dates; it does not require a B/L, commercial invoice, packing list, import entry, BOC release, or container. The upload/delete handlers have no received/cancelled terminal guard. The probe measures an empty shipment reaching `received` (`api/tests/Feature/SupplyChain/ImportShipmentCustomsAuditTest.php:430-463`) and a B/L being uploaded/deleted after receipt (`:465-500`). |
| SC-14 | Stuck process / Gap | High | M | A received shipment has no durable GRN handoff, retry state, or operator next action | `api/app/Modules/SupplyChain/Services/ShipmentService.php:120-148` | Transitioning to `received` only stamps `ata` and returns a shipment resource. No event, outbox row, job, notification, handoff column, or recovery command connects it to Inventory GRN. The probe confirms no queue, notification, or shipment handoff state (`api/tests/Feature/SupplyChain/ImportShipmentCustomsAuditTest.php:503-534`). This leaves the P2P chain at a terminal-looking logistics state with no evidence that receiving was initiated or completed. |
| SC-15 | Risk / Bad practice | Medium | S | Landed-cost allocations do not reconcile to the charged amount and use binary floating-point money arithmetic | `api/app/Modules/SupplyChain/Services/LandedCostService.php:72-120,162-186` | Costs and line bases are converted to `float`, each component is independently rounded per line, and residual cents are not assigned. The existing probe measures 7 lines on ₱100.00 producing ₱100.03, 3 lines producing ₱99.99, and five component totals producing ₱499.95 against a ₱500.00 header (`api/tests/Feature/SupplyChain/ImportShipmentCustomsAuditTest.php:59-146`). |
| SC-16 | Broken process | High | L | Landed cost is stored but never reaches inventory valuation, GRN cost, or accounting | `api/app/Modules/SupplyChain/Services/LandedCostService.php:53-126`; `api/database/migrations/0230_add_landed_cost_columns_to_shipments.php:30-43` | The service persists shipment/header and per-PO-line allocations, but the downstream Inventory, Purchasing, and Accounting module source contains no landed-cost consumer. The probe records no downstream references (`api/tests/Feature/SupplyChain/ImportShipmentCustomsAuditTest.php:367-392`). Even after SC-05 is made writable and SC-15 is corrected, weighted-average stock cost remains unaffected. |
| SC-17 | Risk / Broken process | Medium | M | The same active driver can be assigned to simultaneous deliveries | `api/app/Modules/SupplyChain/Services/DeliveryService.php:406-445` | `assertDispatchAssignmentAvailable()` locks and validates the driver is active and has the driver role, but performs no existing-delivery or date-overlap check. The vehicle branch checks active `loading`/`in_transit` assignments (`:420-432`); the driver branch does not. Two deliveries with different vehicles can therefore both enter loading/in-transit under one driver. |
| SC-18 | Gap / Broken process | High | M | Shipment-lot creation does not prove that the selected batches belong to the delivery or that the lot quantity is deliverable | `api/app/Modules/SupplyChain/Services/ShipmentLotService.php:31-74`; `api/app/Modules/Quality/Controllers/ShipmentLotController.php:36-46` | The service checks only that submitted WOs exist and have a `batch_number`; it does not compare their sales order, product, output, passed outgoing inspection, delivery lines, or delivered quantity to the target delivery. A caller with `quality.inspections.manage` can submit unrelated started WOs and any positive integer quantity. The database only enforces one lot per delivery (`api/database/migrations/0150_add_batch_lot_traceability.php:36-52`), not provenance or quantity. |
| SC-19 | Bad practice | Medium | S | Fleet capacity violates the decimal-as-string contract, and vehicle status is not enum-cast | `api/app/Modules/SupplyChain/Resources/VehicleResource.php:15-24`; `api/app/Modules/SupplyChain/Models/Vehicle.php:19-26`; `spa/src/api/supply-chain/index.ts:160-166`; `spa/src/types/supplyChain.ts:114-123` | `capacity_kg` is a `decimal(10,2)` column but is emitted as `(float)` and represented as `number`; the Fleet page also converts input with `Number()` (`spa/src/pages/supply-chain/fleet.tsx:78-88`). `Vehicle` defines `VehicleStatus` but does not cast `status` to it, leaving a status/type lifecycle as an unconstrained string at the model boundary. |
| SC-20 | Gap | Medium | M | The shipment Incoterm selected by the operator is not the term rendered in generated customs documents | `api/app/Modules/SupplyChain/Services/ImpexDocumentService.php:54-72`; `api/resources/views/pdf/commercial-invoice.blade.php:78-104`; `api/resources/views/pdf/packing-list.blade.php:74-100` | Shipment create now persists `shipment.incoterm` (`api/app/Modules/SupplyChain/Services/ShipmentService.php:102-112`), but both PDFs render `$po->incoterm`. A shipment submitted as DDP against a PO stored as FOB produces a document showing FOB; a null PO term shows blank despite a shipment term. The code comments and probe identify this as an unresolved override-versus-inherit trade-document decision (`api/tests/Feature/SupplyChain/ImportShipmentCustomsAuditTest.php:1068-1099`). |

### Clean areas / controls retained

- The prior late-delivery blocker is resolved in the current CRM transition policy, and the delivery create form now includes the post-production deliverable SO states.
- Delivery confirmation still re-reads and locks the delivery, treats a second confirmation as a no-op, requires proof, synchronizes delivered quantities, and uses the output-bound passed-inspection reservation path (`api/app/Modules/SupplyChain/Services/DeliveryService.php:905-965,475-531`).
- Sales-order reservation and inspection capacity are serialized and fail closed; cancelled sales orders are rejected in the shared gate (`api/app/Modules/SupplyChain/Services/DeliveryService.php:696-752`).
- Driver reads and writes are self-scoped by `driver_id`, and the driver resource omits invoice, unit-price, inspection, shipment-lot, and proof-view internals (`api/app/Modules/SupplyChain/Services/DriverDeliveryService.php:35-80,122-126`; `api/app/Modules/SupplyChain/Resources/DriverDeliveryResource.php:12-61`).
- Supply-chain routes retain authentication, feature, and permission middleware; upload paths use the local disk, server-side MIME validation, random stored names, and permission-gated streaming (`api/app/Modules/SupplyChain/routes.php:18-58,103-163`; `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:121-180`).
- HashID resources, status enums for Shipment/Delivery/Container, delivery vehicle locking, receipt rollback cleanup, and RFC 6266 filename sanitization remain present. These are source observations only, not fresh runtime verification.

### Verification limits

- Tests were not run, per request. Existing test files were read as evidence; several deliberately encode measured defects as “PASS-EITHER-WAY” probes and must not be treated as proof of a healthy module.
- Docker, Artisan, database inspection, queue/scheduler execution, browser rendering, storage-provider behavior, concurrent two-connection behavior, and external customer/supplier-provider behavior were not verified.
- PDF findings are based on the service/view source path and the existing probe’s documented behavior, not generated-PDF inspection.
- Common infrastructure, portals, Quality internals, Accounting/Inventory internals, and CRM internals were read only where needed to validate Supply Chain boundaries; cross-module ownership remains with the relevant audit unit.

### No-code-change statement

No application code, migration, test, registry, or roadmap file was modified for this re-audit. This append to `audit/supply-chain.md` is the only requested write.
