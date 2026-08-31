# M043 — import-shipments-customs audit report

Audit date: 2026-08-25  
Status: 📋 Plan Ready  
Claim: supply-chain / import-shipments-customs  
Scope: inbound shipment lifecycle, PO/customs handoff, shipment and container records, import documents, generated packing list/commercial invoice PDFs, landed-cost allocation, supplier-portal boundary, permissions, private-file handling, SPA/API parity, and the related GRN/AP contracts.

## Production readiness

Production audit: 46/100, risky. The basic shipment create/status/document/PDF path is authenticated and currently testable, but the customs record can advance without evidence, the selected Incoterm is discarded, landed costs cannot be entered or applied to inventory, containers are not maintainable from the shipment UI, and archive/restore can make document files unrecoverable.

Blockers:

- Define the authoritative shipment/PO/customs lifecycle and reject creation or transition against closed, cancelled, received, or otherwise invalid POs.
- Add an explicit customs-document gate and a durable received-to-GRN handoff. The current path only stamps dates and relies on a PO-only relationship.
- Make landed-cost inputs, allocation, cent reconciliation, inventory valuation, and AP evidence one auditable contract.
- Preserve and reconcile supplier-submitted shipment updates/documents with the internal shipment record, or explicitly document a manual handoff.
- Repair soft-delete restore binding and define document-file retention so archive/restore cannot leave active metadata pointing at deleted files.

## Decision

No application source fixes were applied in this session. The dominant findings change customs evidence policy, shipment/PO/GRN ownership, landed-cost accounting, supplier-portal convergence, and archival retention. They are not safe as a small same-session patch. The module is released as 📋 Plan Ready with the ordered remediation work in `action-plan.md`.

## Findings

### M043-F001 — Shipments can be created for terminal or invalid PO states

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- `CreateShipmentRequest` validates only that `purchase_order_id` exists and does not constrain the PO status at `api/app/Modules/SupplyChain/Requests/CreateShipmentRequest.php:26-34`.
- `ShipmentService::create()` calls `PurchaseOrder::query()->findOrFail()` and creates the shipment without checking the PO state at `api/app/Modules/SupplyChain/Services/ShipmentService.php:90-106`.
- The PO lifecycle includes `approved`, `sent`, `partially_received`, `received`, `closed`, and `cancelled` at `api/app/Modules/Purchasing/Enums/PurchaseOrderStatus.php:7-16`. The normal create form lists only `sent` POs at `spa/src/pages/supply-chain/shipments/create.tsx:37-40`, but the API accepts a direct request for any existing non-trashed PO.
- GRN creation explicitly rejects non-open PO states at `api/app/Modules/Inventory/Services/GrnService.php:103-121`, so shipment creation and the receiving boundary do not share one authoritative PO gate.

Impact: an operator or integration can create an `ordered` shipment for a cancelled, closed, already received, or otherwise unsuitable PO. The record then looks like a valid inbound leg even though the downstream purchasing/inventory lifecycle cannot receive it. The UI filter is not a server-side business rule.

### M043-F002 — Customs and receipt transitions have no evidence or receiving gate

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- `ShipmentService::updateStatus()` checks only the enum transition and auto-stamps ATD, customs-clearance date, and ATA; it does not inspect documents, containers, landed costs, or a GRN at `api/app/Modules/SupplyChain/Services/ShipmentService.php:109-137`.
- The module defines nine import document types, including B/L, commercial invoice, packing list, import entry, certificate of origin, MSDS, and BOC release at `api/app/Modules/SupplyChain/Enums/ShipmentDocumentType.php:7-18`, but no type is required before `customs → cleared` or `cleared → received`.
- The documented process says to upload import documents, add containers, advance status, and then proceed to GRN at `docs/PROCESS-FLOWS.md:605-627`. The focused regression test drives an empty shipment through the entire chain at `api/tests/Feature/SupplyChain/ShipmentStatusRegressionRaceTest.php:51-69`.
- The shipment migration explicitly says the expected GRN is handled by Inventory and linked only by `purchase_order_id`, with no shipment FK or handoff state at `api/database/migrations/0093_create_shipments_table.php:14-16,22-37`. GRN creation likewise accepts a PO rather than a shipment at `api/app/Modules/Inventory/Services/GrnService.php:103-126`.

Impact: `received` is currently an operator-entered status, not a controlled customs/warehouse handoff. A shipment can be marked cleared or received without BOC/customs evidence, and the warehouse cannot identify which shipment/container a PO-based GRN represents when a PO has multiple shipments.

### M043-F003 — The shipment Incoterm contract silently loses the submitted value

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- The create request validates `incoterm` at `api/app/Modules/SupplyChain/Requests/CreateShipmentRequest.php:26-42`, and the SPA schema/form submits it at `spa/src/pages/supply-chain/shipments/create.tsx:20-30,68-80`.
- `ShipmentService::create()` never copies `incoterm` into the `Shipment::create()` payload at `api/app/Modules/SupplyChain/Services/ShipmentService.php:94-106`.
- The backend model and resource expose an Incoterm column/value at `api/app/Modules/SupplyChain/Models/Shipment.php:25-55` and `api/app/Modules/SupplyChain/Resources/ShipmentResource.php:20-29`, but `updateMeta` does not validate or persist it at `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:91-102` and `api/app/Modules/SupplyChain/Services/ShipmentService.php:141-154`.
- Generated PDFs render the PO Incoterm, not the shipment value, at `api/resources/views/pdf/packing-list.blade.php:85-89` and `api/resources/views/pdf/commercial-invoice.blade.php:84-87`.

Impact: the user can select a shipment-specific term and receive a successful response while the database remains null. Customs paperwork can therefore show the PO's old term or a blank value, which is a material trade-document mismatch.

### M043-F004 — Landed-cost calculation has no usable input path and does not reach inventory valuation

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- The calculate endpoint accepts only `allocation_method` at `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:164-175`. The metadata update accepts carrier/vessel/container/B/L/dates/notes only at `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:91-102` and `api/app/Modules/SupplyChain/Services/ShipmentService.php:141-154`; no shipment endpoint or SPA method edits freight, insurance, duties, brokerage, or other charges.
- The SPA client ends at document upload/archive/restore and has no calculate or landed-cost mutation at `spa/src/api/supply-chain/index.ts:39-68`. The shipment detail has no cost-entry/allocation panel at `spa/src/pages/supply-chain/shipments/detail.tsx:226-274,404-453`, while its TypeScript `Shipment` type omits the backend landed-cost and Incoterm fields at `spa/src/types/supplyChain.ts:24-43`.
- `LandedCostService` reads the cost columns as floats at `api/app/Modules/SupplyChain/Services/LandedCostService.php:53-59`, rounds each line independently without residual-cent reconciliation at `:78-106`, and implements `manual` as an equal split while saying the user enters amounts directly at `:143-150`.
- The normal shipment detail load does not include `landedCosts` at `api/app/Modules/SupplyChain/Services/ShipmentService.php:69-75`; even the calculate response loads `purchaseOrderItem` but not the `shipment` relation, while `ShipmentLandedCostResource` reads `shipment_id` from that relation at `api/app/Modules/SupplyChain/Services/LandedCostService.php:100-106` and `api/app/Modules/SupplyChain/Resources/ShipmentLandedCostResource.php:15-23`, relying on implicit lazy loading and risking N+1 or failure if lazy loading is disabled.
- Inventory receiving still defaults GRN unit cost to the PO line price and posts that GRN cost to stock movements at `api/app/Modules/Inventory/Services/GrnService.php:194-206,924-935`. There is no call from the landed-cost service into GRN, stock valuation, or AP bill allocation.

Impact: the advertised landed-cost feature cannot be completed by an authorized operator. If costs are populated out of band, the result can silently lose cents, `manual` does not honor manual input, refreshes do not show allocations reliably, and accepted inventory continues using PO/GRN cost rather than the calculated landed cost. This is a financial-control gap, not a presentation-only omission.

### M043-F005 — Multi-container tracking is API/PDF-only on the internal workflow

Classification: Missing  
Priority: P1  
Session: separate-recommended

Evidence:

- Container CRUD routes and validation exist at `api/app/Modules/SupplyChain/routes.php:58-70` and `api/app/Modules/SupplyChain/Controllers/ContainerController.php:20-75`.
- `ShipmentService::show()` loads PO, creator, and documents only; it does not load `containers` or expose them through the normal shipment detail response at `api/app/Modules/SupplyChain/Services/ShipmentService.php:69-75`. `ShipmentResource` has no `containers` field at `api/app/Modules/SupplyChain/Resources/ShipmentResource.php:15-55`.
- The SPA client has no container methods at `spa/src/api/supply-chain/index.ts:39-68`; the detail page renders only the legacy single `container_number` field at `spa/src/pages/supply-chain/shipments/detail.tsx:239-242`, and the TypeScript shipment contract has no container collection at `spa/src/types/supplyChain.ts:24-43`.
- The only maintained path that loads the container relation is PDF generation at `api/app/Modules/SupplyChain/Services/ImpexDocumentService.php:27-43,56-74`. The documented operator flow nevertheless requires adding containers at `docs/PROCESS-FLOWS.md:621-627`.

Impact: an ImpEx Officer cannot add, edit, archive, restore, or review the multi-container record from the supplied shipment journey. The PDF can display container data only if another client or direct API call populated it; the normal detail record remains blind to the operational source of truth.

### M043-F006 — Shipment/document archive destroys files and restore routes cannot bind trashed rows

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- Shipment, document, and container restore routes are declared without `withTrashed()` at `api/app/Modules/SupplyChain/routes.php:33-36,53-70`.
- The shared hash-binding contract states that soft-deleted rows bind only through `resolveSoftDeletableRouteBinding()` when a route opts into `withTrashed()` at `api/app/Common/Traits/HasHashId.php:45-68`. The controllers call `restore()` but cannot receive an archived model through ordinary binding at `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:152-162` and `api/app/Modules/SupplyChain/Controllers/ContainerController.php:64-73`.
- `ShipmentService::delete()` allows every status except `received`, collects active document paths, soft-deletes the shipment, then deletes the physical files after commit at `api/app/Modules/SupplyChain/Services/ShipmentService.php:206-215`. `ShipmentDocument` itself uses `SoftDeletes` at `api/app/Modules/SupplyChain/Models/ShipmentDocument.php:15-29`, so the metadata is not removed with the parent soft delete.
- The document detail query excludes trashed documents at `api/app/Modules/SupplyChain/Services/ShipmentService.php:69-75`, and the SPA presents a restore action for the active rows it can see at `spa/src/pages/supply-chain/shipments/detail.tsx:330-351`, not an archived-document recovery path.

Impact: archiving a shipment can leave document rows active but their files deleted; restoring the shipment, once binding is repaired, would expose broken downloads. Shipment/container/document restore promises also currently return a binding failure for the intended archived record. The delete guard permits historical customs records to be archived after customs activity and before receipt.

### M043-F007 — Supplier-portal shipment evidence does not converge on the internal shipment record

Classification: Missing  
Priority: P1  
Session: separate-recommended

Evidence:

- Supplier routes expose shipment updates and shipping-document upload/list/download under the PO-scoped portal surface at `api/app/Modules/B2B/routes.php:27-45`.
- `SupplierPortalService::updateShipment()` appends shipped date, carrier, tracking, ETA, and notes to the PO remarks; it does not create or update a `Shipment` at `api/app/Modules/B2B/Services/SupplierPortalService.php:158-203`.
- Supplier uploads are stored in a separate `portal_shipping_documents` table and model, explicitly distinct from `shipment_documents`, at `api/app/Modules/B2B/Services/SupplierPortalService.php:205-265`, `api/app/Modules/B2B/Models/PortalShippingDocument.php:14-44`, and `api/database/migrations/0163_create_portal_shipping_documents_table.php:9-14,18-44`.
- The internal shipment detail loads only `ShipmentDocument` rows at `api/app/Modules/SupplyChain/Services/ShipmentService.php:69-75` and renders only those rows at `spa/src/pages/supply-chain/shipments/detail.tsx:276-401`.

Impact: supplier-provided B/L, commercial invoice, packing list, and shipping updates are not visible in the ImpEx Officer's shipment record without a manual reconciliation step. The two surfaces can disagree about carrier/ETA and document completeness while both appear authoritative. The boundary may be intentional, but it is not an executable handoff for the declared `supplier-portal` dependency.

### M043-F008 — Document and metadata mutation policy is incomplete after terminal states

Classification: Incomplete  
Priority: P2  
Session: same-session-ok only after lifecycle policy

Evidence:

- The SPA hides upload controls for `received` and `cancelled` shipments at `spa/src/pages/supply-chain/shipments/detail.tsx:360-400`, but the backend upload action has no equivalent status guard at `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:105-119` or `api/app/Modules/SupplyChain/Services/ShipmentService.php:162-190`.
- Upload is described as “or replace” but always creates a new `ShipmentDocument` row; the migration adds only a non-unique `(shipment_id, document_type)` index at `api/app/Modules/SupplyChain/Services/ShipmentService.php:157-186` and `api/database/migrations/0094_create_shipment_documents_table.php:20-34`.
- `updateMeta` validates dates independently and does not carry over the create request's ETA-after-ETD rule: compare `api/app/Modules/SupplyChain/Requests/CreateShipmentRequest.php:35-40` with `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:91-102`.

Impact: a privileged API caller can mutate a terminal customs record after the UI has declared it closed, duplicate a document type without a defined version/current policy, or create an ETA earlier than ETD. This should be resolved as part of the lifecycle and evidence-retention contract before a small validation patch is attempted.

## Verified strengths

- Supply-chain shipment, container, document, and generated-PDF routes are behind Sanctum, the `supply_chain` feature gate, and permission middleware at `api/app/Modules/SupplyChain/routes.php:18-70`.
- Shipment document uploads use the private local disk, failed inserts remove orphaned files, downloads return 404 for missing files, and authenticated access behavior is covered by `api/tests/Feature/SupplyChain/DocumentAccessTest.php:111-220,268-293`.
- Shipment status transitions are forward-only and now re-read/lock the authoritative row, preventing stale status regression; the focused concurrency test passed at `api/app/Modules/SupplyChain/Services/ShipmentService.php:109-138` and `api/tests/Feature/SupplyChain/ShipmentStatusRegressionRaceTest.php:51-80`.
- Hash ID route binding and resources are used for shipment, document, and container identifiers; raw IDs are not the emitted resource IDs at `api/app/Common/Traits/HasHashId.php:20-43,70-95` and the module resources.
- Packing list and commercial invoice generation load PO/vendor/item/container data and return authenticated PDFs; the focused ImpEx test suite passed.
- Migrations through the shipment/container/landed-cost changes are applied in the current database, including migrations 0093, 0094, 0227, 0228, and 0230.
- Seeded role boundaries distinguish the broad internal supply-chain read/manage permissions from supplier-portal authentication; `purchasing_officer` and `impex_officer` receive shipment permissions at `api/database/seeders/RolePermissionSeeder.php:586-594,662-668`.

## Verification

Passed:

- `docker compose run --rm api php artisan test tests/Feature/SupplyChain/ShipmentCreateTest.php tests/Feature/SupplyChain/ShipmentStatusRegressionRaceTest.php tests/Feature/SupplyChain/ImpexDocumentTest.php tests/Feature/SupplyChain/DocumentAccessTest.php` — 26 tests, 53 assertions.
- `docker compose run --rm spa npm run typecheck` — passed.
- `docker compose run --rm api php artisan migrate:status` — all listed migrations reported `Ran`.
- `docker compose run --rm api php artisan route:list --path=supply-chain --except-vendor` — 43 Supply Chain routes enumerated.

Evidence limits:

- No browser-driven authenticated journey was run for shipment creation, container CRUD, landed-cost entry, customs evidence, supplier-portal reconciliation, archive/restore, or the GRN handoff.
- No focused test covers PO-state rejection, required customs document gates, Incoterm persistence, container CRUD/UI parity, landed-cost input/rounding/inventory application, supplier-portal convergence, or shipment/document restore.
- `docker compose run --rm spa npm run audit:api-routes` did not produce an audit result because the existing script aborted with `TypeError [ERR_STREAM_NULL_VALUES]` at `spa/scripts/audit-api-routes.mjs:96` while serializing Laravel route data.
- `docker compose ps -a` showed only `ogami-db` and `ogami-redis` running; `ogami-api`, `ogami-queue`, `ogami-reverb`, `ogami-spa`, and `ogami-nginx` were stopped. Live HTTP, worker, WebSocket, scheduler, and deployment behavior was therefore not validated.
- No production feature-toggle values, supplier-portal roster, customs operating procedure, landed-cost accounting policy, or live AP/GRN records were available for a production drill.

## Next action

Start with the separate implementation tranche in `action-plan.md`: agree the authoritative PO → shipment → customs evidence → GRN/AP contract, then implement and test the lifecycle gate, supplier evidence convergence, and landed-cost accounting path before adding UI polish.

---

# M043 — import-shipments-customs RE-AUDIT (2026-09-01)

Session: re-audit of the 2026-08-25 `📋 Plan Ready` session (RECLAIMED, 153h orphan lock).
Status: IN PROGRESS — skeleton committed before probing.

Owned this session: `api/app/Modules/SupplyChain/`.
Read-only (LIVE under other agents): `api/app/Modules/Inventory/`, `api/app/Modules/Quality/`.

## Plan

1. Prior-work assessment — verify each of M043-F001..F008 by probe, not by reading the log.
2. Numeric baseline (tests + assertions + exit code) on a private database.
3. HTTP-layer coverage inventory: which shipment/document/container/landed-cost endpoints
   have HTTP-level tests vs service-only, then hit the uncovered ones.
4. Landed-cost apportionment invariants (exact-sum, basis, divide-by-zero, figure reaching GRN).
5. Currency / FX handling (or documented absence).
6. Document-completeness gate before clearance / receipt; swallowed-handoff asymmetry.
7. Scheduled commands touching shipments — exit codes and counters.
8. Full status transition matrix; immutability after clearance/receipt (incl. `pg_trigger`).
9. Attachments: real-bytes MIME, random filename, outside web root, traversal, over-length name,
   orphan-on-archive.
10. Soft-deleted PO/vendor/item/shipment across aggregates, lists, exports.
11. Money FormRequest probes (`1.999`/`1e3`/`1e17`/`1e20`/`10.00005`/`-1`/`0`).
12. `impex_officer` end-to-end import; permission gate per endpoint; raw-id-free error bodies.
13. Dead surfaces both directions vs `docs/USER-MANUAL.md`, `docs/PROCESS-FLOWS.md`.

## Findings

(populated below as measured)

