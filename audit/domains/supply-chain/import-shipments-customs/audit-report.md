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

### Environment and baseline (real, quoted)

```
docker compose ps                → ogami-db + ogami-redis up (healthy); api/queue/reverb/spa/nginx NOT running
docker compose exec db psql      → select 1 → 1 row
CREATE DATABASE ogami_test_impex OWNER ogami
docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_impex api \
  php artisan test tests/Feature/SupplyChain --no-coverage
  → Tests: 109 passed (304 assertions)   Duration: 106.91s   EXIT=0
```

Non-zero assertions, so the run was real. `SupplyChain/CocAutoAttachOnConfirmTest` is
green, as `deliveries-proof` reported. Scheduled commands: **0 of 48** in
`api/routes/console.php` reference shipment / customs / impex / landed cost, so the
"command exit code lies about doing nothing" invariant is N/A here — recorded, not skipped.

### HTTP-level coverage vs service-only (the goods-receiving lesson)

21 import endpoints exist. Before this session **6 had HTTP-level coverage; 15 did not.**

| endpoint | HTTP-covered at HEAD | uncovered result measured this session |
|---|---|---|
| `GET /shipments/options` | no | 200 |
| `GET /shipments` | yes (`DeliveryReadPermissionTest`, permission only) | — |
| `GET /shipments/{s}` | yes | — |
| `POST /shipments` | yes | — |
| `PATCH /shipments/{s}/status` | **no** — `ShipmentStatusRegressionRaceTest` calls the service, never HTTP | 200 for a shipment with zero documents |
| `PATCH /shipments/{s}` | **no** | 200 on a `received` shipment; accepts ETA < ETD |
| `DELETE /shipments/{s}` | **no** | 204 on `cleared`; 422 on `received` |
| `PATCH /shipments/{s}/restore` | **no** | **404 for its only valid target** |
| `POST /shipments/{s}/calculate-landed-cost` | **no** | **500 for any shipment with ≥2 PO lines** |
| `GET /shipments/{s}/packing-list` | yes | — |
| `GET /shipments/{s}/commercial-invoice` | yes | — |
| `POST /shipments/{s}/documents` | yes | — |
| `GET /shipment-documents/{d}/download` | yes | header injection (see F103) |
| `DELETE /shipment-documents/{d}` | **no** | 204 after receipt |
| `PATCH /shipment-documents/{d}/restore` | **no** | **404** |
| `GET /shipments/{s}/containers` | **no** | 200 |
| `POST /shipments/{s}/containers` | **no** | **500 on `1e17`/`1e20`**; `1.999`→`2.00` |
| `GET /containers/{c}` | **no** | 200 |
| `PUT /containers/{c}` | **no** | 200 on a received shipment's container |
| `DELETE /containers/{c}` | **no** | 204 |
| `PATCH /containers/{c}/restore` | **no** | **404** |

Three of the four new P0/P1 defects below live on routes with no HTTP test.

### Prior-work assessment

The 2026-08-25 session committed **no source changes** and its log says so plainly; its
recorded verification (26 tests / 53 assertions) is consistent with what is on disk. So this
is the "log accurate, nothing fixed" outcome, not a fabricated or self-flagged one.

Re-measured, **8 of 8 prior findings still reproduce**, none is disproved:

| prior | verdict | probe |
|---|---|---|
| F001 shipments for terminal/invalid POs | REPRODUCES, worse than stated | all **8** PO states accepted (`draft…cancelled`) |
| F002 no customs evidence / receiving gate | REPRODUCES | empty shipment walks `ordered→received` in five 200s |
| F003 Incoterm discarded | REPRODUCES | submitted `DDP` → response `null`, column `null` |
| F004 landed cost unusable + never reaches inventory | REPRODUCES, and understated | see F100/F101/F102 |
| F005 containers API/PDF-only | REPRODUCES | zero container methods in `spa/src/api/supply-chain/index.ts` |
| F006 archive destroys files; restore cannot bind | REPRODUCES | file deleted + row live; restore 404 ×3 |
| F007 supplier portal does not converge | REPRODUCES | `supplier_shipments` read only by `B2B` resources |
| F008 no terminal-state mutation policy | REPRODUCES | `received` shipment fully editable, ETA < ETD accepted |

## New findings (2026-09-01)

### M043-F100 — `by_weight` landed-cost allocation has never worked (Broken, P0)

`LandedCostService::getItemWeights()` declares
`: Illuminate\Database\Eloquent\Collection` at
`api/app/Modules/SupplyChain/Services/LandedCostService.php:176` but maps PO lines to
floats at `:178-186`. `Eloquent\Collection::map()` downgrades to
`Illuminate\Support\Collection` as soon as the mapped values are not Models, so the
declared return type is violated on **every** invocation — not only for degenerate input.

Measured with two ordinary non-zero lines:

```
TypeError: App\Modules\SupplyChain\Services\LandedCostService::getItemWeights():
Return value must be of type Illuminate\Database\Eloquent\Collection,
Illuminate\Support\Collection returned      (LandedCostService.php:186, from :155)
```

A `TypeError` is not a `BusinessRuleException`, so it escapes the whole
`DB::transaction()` closure as an unhandled 500. Nothing is persisted.

Second, independent defect in the same method: it reads
`$item->item->net_weight ?? $item->item->weight`, and **neither is a column** —
`information_schema` reports zero `%weight%` columns on `items` (asserted in the probe).
So even with the type error repaired, `by_weight` would silently produce an equal split
while claiming to apportion by weight. One of the four advertised bases is doubly dead.

This is the 8D-SLA-ledger shape: a code path that has never once executed successfully,
indistinguishable from a working one because no test and no client ever calls it.

### M043-F101 — the only landed-cost endpoint 500s on every multi-line shipment (Broken, P0)

`LandedCostService::calculate()` returns
`$shipment->fresh()->load('landedCosts.purchaseOrderItem')` at
`api/app/Modules/SupplyChain/Services/LandedCostService.php:70,106` — it does **not**
load `landedCosts.shipment`. `ShipmentLandedCostResource:16` then reads
`$this->shipment?->hash_id`, and `AppServiceProvider:237` sets
`Model::preventLazyLoading(! $this->app->isProduction())`.

Measured over HTTP on `POST /supply-chain/shipments/{id}/calculate-landed-cost`:

```
{"lines=1":200,"lines=2":500,"lines=3":500,"lines=2,freight=100":500}
exception = Illuminate\Database\LazyLoadingViolationException:
  Attempted to lazy load [shipment] on model
  [App\Modules\SupplyChain\Models\ShipmentLandedCost] but lazy loading is disabled.
```

Deterministic and reproducible. A real resin import is multi-line, so in every
non-production environment the endpoint is a hard 500; in production the guard is off
and the same line becomes a silent N+1 (one query per allocation row). The single-line
case escaping is reproducible but its mechanism is **not established** — recorded honestly
rather than guessed.

`impex_officer` — the seeded role that exists for exactly this module — completes 14 of the
15 steps `docs/PROCESS-FLOWS.md:627-632` documents and fails on step 5:

```
{"options":200,"create":201,"upload_bill_of_lading":201,"upload_commercial_invoice":201,
 "upload_packing_list":201,"add_container":201,"list_containers":200,
 "status_shipped":200,"status_in_transit":200,"status_customs":200,"status_cleared":200,
 "status_received":200,"landed_cost":500,"packing_list_pdf":200,"commercial_invoice_pdf":200}
```

No test in the repository posts to this route. Same class as `goods-receiving`'s
`POST /inventory/grn`.

### M043-F102 — landed-cost apportionment does not reconcile to the charged total (Broken, P0)

`LandedCostService` reads the five charge columns as **floats** at `:53-57`, sums them as
floats at `:59`, and rounds each component on each line independently at `:81-85` with no
residual reconciliation. `ShipmentLandedCost` totals therefore do not equal
`shipments.landed_cost_total`.

Measured against real Postgres rows with BCMath:

| case | header total | Σ line `total_allocated` | delta |
|---|---|---|---|
| 7 equal lines, freight 100.00 | `100.00` | `100.03` | **+0.03 over-allocated** |
| 3 equal lines, freight 100.00 | `100.00` | `99.99` | **−0.01 parked on no line** |
| 3 lines × five components of 100.00 | `500.00` | `499.95` | **−0.05** (each component `99.99`) |

The over-allocating direction is the more serious one: the ledger claims more duty and
freight were apportioned to inventory than the broker actually charged. `goods-receiving`
verified weighted-average cost exact to `10.6172` against independent BCMath — if landed
cost is ever wired into GRN unit cost, this defect corrupts a verified-correct downstream
calculation.

Related, same finding family:

- The **basis is not consistent**: `manual` is implemented as an equal split at `:145-150`
  while its own comment says "user enters amounts directly". Measured with lopsided line
  values `9000.00` / `1000.00` and freight `100.00`: allocations `["50.00","50.00"]`.
  There is no per-line input anywhere to enter a manual amount with.
- Zero-basis is **safe**: `computeRatios()` guards `$total <= 0` at `:160-165`, so
  `by_value` and `by_quantity` with all-zero lines fall back to an equal split and
  allocate `90.00` of `90.00` with no `DivisionByZeroError`. (`by_weight` throws first —
  F100.)
- `shipment_landed_costs` has **no `deleted_at`**, so the `->delete()` at `:76,194` is a
  hard delete and the `shipment_landed_cost_unique` constraint cannot be tripped by a
  re-run. Recalculation is idempotent.
- `ShipmentLandedCost` is the only model in the module **without `HasAuditLog`**
  (`api/app/Modules/SupplyChain/Models/ShipmentLandedCost.php:21` — `HasFactory, HasHashId`
  only), while `Shipment`, `Container` and `ShipmentDocument` all have it. Money
  allocations are written and hard-deleted with no audit row.

### M043-F103 — document download forges its own Content-Disposition (Broken, P1)

`ShipmentController::downloadDocument()` interpolates the client's stored
`original_filename` straight into the header at
`api/app/Modules/SupplyChain/Controllers/ShipmentController.php:145-147`:

```php
'Content-Disposition' => $isImage
    ? sprintf('inline; filename="%s"', $filename)
    : sprintf('attachment; filename="%s"', $filename),
```

Measured with a real upload named `bl".pdf`:

```
attachment; filename="bl".pdf"
```

The double quote closes the `filename` parameter early and the remainder is injected into
the header value. This is the **identical defect** `DeliveryProofController` was repaired
for at commit `38663a81` hours earlier in this same module directory, which left an
RFC 6266 helper to copy (`DeliveryProofController::contentDisposition()`, including a
comment about the character-class escaping that 500s if written the obvious way).

### M043-F104 — an over-length client filename reaches Postgres as a 500 (Broken, P1)

`shipment_documents.original_filename` is `varchar(255)`. The upload validator at
`api/app/Modules/SupplyChain/Controllers/ShipmentController.php:107-111` has no rule on
the client filename, and `ShipmentService::uploadDocument()` writes
`$file->getClientOriginalName()` verbatim at `:180`. Measured: a 304-character name →
**500** (SQLSTATE 22001). The file is stored before the insert, so the `catch` at `:187`
does remove the blob and no row is written — the data stays consistent, but the caller
gets an unhandled server error instead of a validation message.

### M043-F105 — container weight/volume is the `numeric|min:0` money-validation family (Broken, P1)

`ContainerController::store()/update()` validate `gross_weight_kg`, `net_weight_kg` and
`volume_cbm` with the bare `['nullable','numeric','min:0']` shape found in eight sibling
modules (`api/app/Modules/SupplyChain/Controllers/ContainerController.php:34-36,55-57`),
against `numeric(10,2)` and `numeric(8,3)` columns.

Measured **one value per test** (a 22003 overflow aborts the surrounding `RefreshDatabase`
transaction, so a shared-test loop reports cascade 500s that are a harness artifact — the
first version of this probe wrongly recorded `-1 => 500` and `0 => 500` for exactly that
reason and was rewritten):

| field | value | result |
|---|---|---|
| `gross_weight_kg` | `-1` | 422 ✔ |
| `gross_weight_kg` | `0` | 201, stored `0.00` ✔ |
| `volume_cbm` | `-1` | 422 ✔ |
| `gross_weight_kg` | `1.999` | 201, stored **`2.00`** — silent precision loss |
| `gross_weight_kg` | `10.00005` | 201, stored **`10.00`** |
| `volume_cbm` | `1.9999` | 201, stored **`2.000`** |
| `gross_weight_kg` | `1e3` | 201, stored **`1000.00`** — scientific notation accepted |
| `gross_weight_kg` | `1e17` | **500** (22003) |
| `gross_weight_kg` | `1e20` | **500** (22003) |
| `volume_cbm` | `1e17` | **500** |
| `volume_cbm` | `1e20` | **500** |

No `Money.php` `ValueError` here because these are quantities, not money — but the
overflow 500 and the silent rounding are the same shape.

### M043-F106 — `updateMeta` drops the create request's date invariant (Incomplete, P1)

`CreateShipmentRequest` enforces ETA ≥ ETD with a closure rule at
`api/app/Modules/SupplyChain/Requests/CreateShipmentRequest.php:36-42`.
`ShipmentController::updateMeta()` validates the same two fields as bare `['nullable','date']`
at `:98-99` and carries no cross-field rule. Measured on a **received** shipment:
`etd=2026-12-31, eta=2026-01-01` accepted with 200.

### M043-F107 — dead surfaces, both directions (Missing, P2)

- `GET /shipments/options` returns an `allocation_methods` array
  (`ShipmentController:56-59`) that **no client consumes** — `spa/src/api/supply-chain/index.ts`
  types the key but nothing reads it, because there is no landed-cost UI.
- All **6** container routes have no SPA client method and no page: `grep -rn "containers"
  spa/src/api spa/src/pages/supply-chain` returns nothing. `docs/PROCESS-FLOWS.md:629`
  nevertheless instructs the operator to "Add containers with details".
- `DELETE /shipments/{id}` and `PATCH /shipments/{id}/restore` have no SPA client either
  (`shipmentsApi` exposes only `destroyDocument`/`restoreDocument`).
- `docs/PROCESS-FLOWS.md:615` documents `POST .../calculate-landed-cost` — measured 500
  (F101) — and `:631` documents "Calculate landed cost (freight, duties, insurance, etc.)"
  with no field anywhere to enter freight, duties or insurance (F102 family).
- `CLAUDE.md`'s number-format table has no Shipment row, although the sequence is
  configured and works: measured `SHP-202609-0001` with a `document_sequences` row created
  on first use. (Reported, not fixed — outside this module's files.)

## Invariants verified as SOUND (no defect)

- Full 7×7 transition matrix walked: **49 cells, 10 accepted, 39 refused**, and the accepted
  set is exactly the linear chain plus cancel-from-any-non-terminal.
- Clear customs twice → 422. Receive from `ordered`/`shipped`/`in_transit`/`customs` → 422 ×4.
  Cancel a received shipment → 422. Archive a received shipment → 422.
- Permission gate: **21/21** endpoints 403 a permissionless user. Auth gate: **21/21** 401
  unauthenticated. Internal shipment middleware is
  `api|auth:sanctum|feature:supply_chain|permission:…` on every route, with no
  `supplier_portal` guard anywhere near it.
- MIME is validated from real bytes: real PHP source named `exploit.pdf` → **422**; a real
  PDF → 201. (Probed with `new Illuminate\Http\UploadedFile(..., test: true)` over real
  bytes, not `UploadedFile::fake()`, whose `getMimeType()` reads the name.)
- Stored filename is random and traversal-safe: a client name of
  `../../../../etc/passwd.pdf` stored as `shipments/73/iAoaW32Hy3j6NIYgIieQfKgKtIUlGD4XAUeDOrkc.pdf`;
  matches `^shipments/\d+/[A-Za-z0-9]{20,}\.pdf$`; local disk root
  `/var/www/storage/app/private` — outside the web root.
- Soft-deleted shipment → **404 on all seven surfaces** (show, packing list, commercial
  invoice, calculate-landed-cost, status, containers, create-against-trashed-PO) and absent
  from the default list. A soft-deleted **vendor** and **item** behind a live shipment do
  **not** 500 either PDF (both 200) — no repeat of the AR `DivisionByZeroError` class.
- Error bodies carry no raw primary key and no internal column name; the transition refusal
  names `shipment_number`, not the id.
- FX: `shipments` and `purchase_orders` have **zero** `currency`/`fx`/`exchange`/`rate`
  columns. There is no conversion and therefore no float rate to corrupt. Peso-only per
  CLAUDE.md — recorded as a documented absence, not filed as missing.
- `Rule::exists()` in this module: one instance, `CreateDeliveryRequest:43`, closure form
  with a `true`-ish string `'available'` — not the `false` landmine, and not an import route.
- Landed-cost recalculation is idempotent (hard-delete + unique constraint).

## Invariants that are DEFECTIVE (summary, detail above)

- Apportionment does not sum to the total (F102). Basis inconsistent (`manual`) (F102).
  `by_weight` unreachable and its basis column absent (F100).
- The computed figure reaches **nothing**: zero references to `LandedCost`/`landed_cost` in
  `Modules/Inventory`, `Modules/Accounting`, `Modules/Purchasing` (asserted structurally in
  the probe). `GrnService:214` takes `$row['unit_cost'] ?? $poi->unit_price`.
- Clearance is **not** gated on documents (F/prior-F002): five 200s with 0 documents and
  0 containers, and a `customs_clearance_date` stamped behind no evidence.
- A document **is** swappable after clearance: a second `bill_of_lading` uploaded after
  `received` → 201, and the B/L clearance relied on deleted → 204.
- The received→GRN handoff is not swallowed into a log — **it does not exist**. `Event::fake`
  + `Queue::fake` + `Notification::fake` across the whole walk to `received`: nothing pushed,
  nothing sent, and `shipments` has **no** `%handoff%` column while `deliveries` has
  `["invoice_handoff_status","invoice_handoff_message","invoice_handoff_at"]`. That is the
  asymmetry `deliveries-proof` taught us to look for, in its starkest form.
- Immutability after receipt: Eloquent rewrote `shipment_number` to `SHP-HACKED`; raw SQL
  rewrote it to `HACKED`, moved `customs_clearance_date` to `1999-01-01` and walked status
  back to `cleared`; `DB::table('shipments')->delete()` removed the row; **`pg_trigger`
  count on `shipments`/`shipment_documents`/`containers`/`shipment_landed_costs` = 0**.
  The only protection is the service guard on `DELETE /shipments/{id}`, and a **`cleared`**
  customs record can still be archived (204).
- A document does **not** survive its shipment being archived: shipment trashed=yes,
  document row trashed=**no**, file deleted=**yes** — a live metadata row pointing at a
  destroyed file, the inverse of the delivery-proof defect and unrecoverable because
  restore cannot bind.
- Restore binding: `{"shipment":404,"document":404,"container":404}` — 3 of the 3 import
  restore routes are unreachable for their only valid target
  (`api/app/Modules/SupplyChain/routes.php:35,55,69`; only `/vehicles/{vehicle}/restore:83-85`
  declares `withTrashed()`).
- PO-state gate: `["draft","pending_approval","approved","sent","partially_received",
  "received","closed","cancelled"]` — **all 8** accepted, against `GrnService:103-121`'s
  three.

## Fixes applied and verification (2026-09-01)

Seven contained items fixed; eight gated. Full before/after table in `fix-log.md`.
Commits: `98817e8e` skeleton · `cc140299` findings · `a9f5de6b` action plan ·
`ec145663` the seven fixes · `6349f0d4` rename + Pint · `b3d60e96` fix log.

```
tests/Feature/SupplyChain   baseline 109 passed / 304 assertions / exit 0
                            after    160 passed / 477 assertions / exit 0
phpstan analyse app/Modules/SupplyChain --memory-limit=1G   → No errors
pint --test (4 changed files fail at HEAD)  → NEW (mine only): []
```

A mid-session host OOM (exit 137) put Postgres into crash recovery and produced a
**160-failed / ZERO-assertion** run in 7.6s (`SQLSTATE[08006] … the database system is
starting up`). That run is discarded, not reported: zero assertions means the database
was gone. Recovery took 50s and the suite then passed.

### Invariant table — every row executed, with its measured result

| invariant | probe | result |
|---|---|---|
| apportionment sums exactly to the total | 7 lines / freight 100.00, BCMath sum | **FAIL — 100.03 vs 100.00 (+0.03)**; 3 lines **99.99 (−0.01)**; five components **499.95 vs 500.00** |
| apportionment basis stated and consistent | `manual` with lopsided lines 9000/1000 | **FAIL — equal split `["50.00","50.00"]`**, contradicting its own docblock |
| `by_weight` basis usable | `information_schema` on `items` | **FAIL — zero `%weight%` columns**; method also `TypeError`d on every call → **FIXED to refuse (422)** |
| zero-weight / zero-value line divide-by-zero | `by_value`/`by_quantity`, all-zero lines | **PASS** — guarded, equal split, 90.00 of 90.00, no `DivisionByZeroError` |
| cost reaching GRN equals cost computed | recursive scan of Inventory/Accounting/Purchasing | **FAIL — zero references**; `GrnService:214` uses `$row['unit_cost'] ?? $poi->unit_price` |
| FX handling or documented absence | column scan on `shipments` + `purchase_orders` | **PASS (absent by design)** — no currency/fx/rate column; peso-only, nothing to corrupt |
| clearance blocked with mandatory documents missing | empty shipment, 5 transitions over HTTP | **FAIL — 200 ×5**, 0 documents, 0 containers, clearance date stamped |
| document swappable after clearance | 2nd B/L + delete original after `received` | **FAIL — 201 and 204** |
| handoff failure not swallowed into a log | `Event`/`Queue`/`Notification::fake` + column scan | **FAIL, worse — no handoff exists.** Nothing pushed/sent; `shipments` has no `%handoff%` column vs `deliveries`' three |
| scheduled commands distinguish "nothing to do" from "everything threw" | `schedule:list` + grep | **N/A — 0 of 48** commands touch shipment/customs/impex/landed cost |
| full transition matrix | 7×7 via the service | **PASS — 49 cells, 10 legal, 39 refused**, exactly the linear chain + cancel-from-non-terminal |
| clear customs twice | HTTP ×2 from `customs` | **PASS** — 200 then **422** |
| receive an uncleared shipment | HTTP from 4 non-`cleared` states | **PASS — 422 ×4** |
| cancel a received one | HTTP | **PASS — 422** |
| edit cost/quantity after clearance | `PATCH` meta + `PUT` container on `received` | **FAIL — 200 and 200** (gated, tranche B5) |
| record immutable after receipt | Eloquent / raw SQL / delete / `pg_trigger` | **FAIL on all four** — `SHP-HACKED` via Eloquent, `HACKED` + `1999-01-01` + status walked back to `cleared` via SQL, row hard-deleted, **`pg_trigger` = 0** |
| attachment MIME validated with real bytes | real PHP bytes as `.pdf`; real PDF as `.png`; real PDF | **PASS — 422**, 201, 201 (probed with `new UploadedFile(..., test: true)`, not `::fake()`) |
| random stored filename | client name `../../../../etc/passwd.pdf` | **PASS** — `shipments/73/iAoaW32Hy3j6NIYgIieQfKgKtIUlGD4XAUeDOrkc.pdf` |
| outside web root | `config('filesystems.disks.local.root')` | **PASS** — `/var/www/storage/app/private` |
| permission-checked serve | 21-endpoint sweep | **PASS** — download requires `supply_chain.view` |
| path traversal refused | as above | **PASS** — no `..`, no `passwd` in the stored path |
| over-length filename | 304 chars into varchar(255) | **FAIL — 500** (22001) → **FIXED to 422** |
| document survives shipment archive | archive then inspect row + file | **FAIL — orphaned**: shipment trashed, document row **live**, file **destroyed** |
| soft-deleted PO/vendor/item/shipment across aggregates and exports | 7 surfaces + list + both PDFs | **PASS** — archived shipment **404 on all 7** and absent from the list; archived **vendor** and **item** behind a live shipment do **not** 500 either PDF (200/200) |
| money FormRequest vs the seven poison values | container weight/volume, **one value per test** | **FAIL — 5 defective of 11** (`1.999`→`2.00`, `10.00005`→`10.00`, `1e3`→`1000.00`, `1e17`/`1e20`→500) → **all FIXED to 422** |
| `Rule::exists()` non-closure instances | grep the module | **PASS — none.** One instance total (`CreateDeliveryRequest:43`), closure form, non-`false` value |
| permission gate per endpoint incl. list/options | 21 endpoints, permissionless user | **PASS — 21/21 → 403** |
| auth gate per endpoint | same 21, no session | **PASS — 21/21 → 401** (first attempt read 403 for all 21 because `actingAs()` persists within a test method — a harness artifact, split into its own test) |
| `impex_officer` completes an import end to end | 15 documented steps | **FAIL — 14/15**, `landed_cost` 500 → **FIXED, now 15/15** |
| internal endpoints leak no other supplier's shipment | middleware inspection + unauthenticated read | **PASS** — every shipment route is `auth:sanctum|feature:supply_chain|permission:…`, no `supplier_portal` guard; unauthenticated 401 |
| raw-id-free error bodies | 4 refusal bodies | **PASS** — no raw pk, no internal column name; refusals name `shipment_number` |
| restore binds `withTrashed()` | archive then restore ×3 | **FAIL — 404/404/404** → **FIXED to 200/200/200** |
| sequence row exists in `document_sequences` | create over HTTP | **PASS** — `SHP-202609-0001`, config `{prefix:SHP, reset:monthly, pad:4}`, row created on first use |

### Could NOT verify — stated plainly

- **No browser-driven journey.** `ogami-api`, `-queue`, `-reverb`, `-spa`, `-nginx` were
  stopped for the whole session (as in the prior one). Everything above is PHPUnit-level
  HTTP through Laravel's kernel, not a real nginx/Sanctum-cookie round trip.
- **Why a single-line shipment escaped the `calculate-landed-cost` 500** while 2 and 3 lines
  hit it. Deterministic and reproduced four times, but the mechanism was not established;
  recorded rather than guessed. Moot after the fix (all line counts now 200).
- **No concurrency probe on landed-cost recalculation.** `RefreshDatabase` hides
  uncommitted rows from a second connection, so a two-connection race probe would report a
  bogus "no lock". Not attempted rather than reported wrongly. The table's
  `shipment_landed_cost_unique` constraint plus the hard delete make a duplicate impossible,
  but a lost-update between two concurrent recalculations is untested.
- **`GrnService` was not called.** Inventory is LIVE under another agent; the
  landed-cost-never-reaches-GRN finding is a structural scan plus a code citation, not an
  executed GRN.
- **No production feature-toggle values, customs SOP, or landed-cost accounting policy**
  were available, so tranche B is a plan, not a validated design.

### Note for the registry

`CLAUDE.md`'s number-format table has no Shipment row although the sequence exists and
works (`SHP-YYYYMM-NNNN`, measured). Reported only — outside this module's files.
