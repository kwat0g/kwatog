# M044 — deliveries-proof audit report

Audit date: 2026-08-25  
Status at original audit: 📋 Plan Ready  
Current session outcome: 🔁 Needs Re-audit  
Claim: supply-chain / deliveries-proof  
Scope: inbound shipment tracking, import documents, containers, landed cost, outbound delivery lifecycle, proof of delivery, driver handoff, customer portal delivery visibility, permissions, and the related SPA surfaces.

## Decision

No application source fixes were applied in the original audit pass. This resumed session implemented and verified M044-F001. M044-F002 and later findings remain pending; F002 requires a human decision about assignment and confirmation ownership before implementation can continue. The module is released as 🔁 Needs Re-audit with progress recorded in `fix-log.md`.

## Resumed-session progress — 2026-08-25

- M044-F001 is resolved: manual delivery lines now require an inspection, and the SPA loads passed outgoing inspections scoped to the selected sales order with remaining accepted capacity.
- M044-F002 is deferred pending a decision on the authorized assignment actor, driver handoff, and customer-versus-internal confirmation boundary. The remaining ordered items are not started in this session.

## Findings

### M044-F001 — Manual delivery creation cannot satisfy the required inspection contract

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- `api/app/Modules/SupplyChain/Requests/CreateDeliveryRequest.php:54-60` makes `items.*.inspection_id` nullable.
- `api/app/Modules/SupplyChain/Services/DeliveryService.php:215-234` rejects every delivery item without an explicit passed, output-bound outgoing inspection.
- `spa/src/pages/supply-chain/deliveries/create.tsx:25-39` includes `inspection_id` in the type but the rendered item form at `:230-267` has no inspection selector or inspection data source.
- The mutation at `spa/src/pages/supply-chain/deliveries/create.tsx:98-110` therefore sends `inspection_id: undefined` for every manually entered line.
- The process contract says a delivery may be created manually at `docs/PROCESS-FLOWS.md:321-328`.

Impact: the internal “New delivery” page cannot create a delivery. Only the outgoing-QC auto-draft path supplies the inspection link. The UI also allows arbitrary decimal input (`:261-264`) while the API accepts at most two decimal places (`CreateDeliveryRequest.php:56-60`), creating an avoidable second validation failure.

### M044-F002 — Assignment and confirmation actors cannot complete the normal outbound workflow

Classification: Missing  
Priority: P1  
Session: separate-recommended

Evidence:

- `CreateDeliveryRequest.php:38-50` accepts a `driver_id`, and `DeliveryService.php:171-180` persists it, but the SPA create mutation at `deliveries/create.tsx:98-110` omits it and the form has no driver field.
- The QC auto-draft listener creates only the sales-order, status, date, notes, and creator at `api/app/Modules/Quality/Listeners/CreateDeliveryDraftOnQcPass.php:159-168`; it assigns neither a vehicle nor a driver.
- The driver list is strictly `where('driver_id', $driver->id)` at `api/app/Modules/SupplyChain/Services/DriverDeliveryService.php:34-53`.
- The internal routes expose status/receipt/confirm but no delivery assignment/update endpoint at `api/app/Modules/SupplyChain/routes.php:100-113`.
- The seeded operational roles have only delivery read or shipment read grants: `RolePermissionSeeder.php:586-601,604-630,662-668`. The driver role has only `supply_chain.driver.access` at `:699-705`; `system_admin` is the only seeded wildcard role at `:449-453`.
- Confirmation is an internal Sanctum route requiring `supply_chain.deliveries.confirm` at `routes.php:108-109`; the customer portal exposes only GET delivery and proof routes at `api/app/Modules/B2B/routes.php:75-97`, despite the process document describing customer confirmation at `docs/PROCESS-FLOWS.md:327-332,341-355`.

Impact: auto-drafted deliveries have no reachable assignment path and are invisible to the driver PWA. Warehouse/purchasing/ImpEx users can read the schedule but the seeded roles cannot perform the internal status, proof, or confirmation actions. The product has not chosen or implemented whether confirmation is performed by an internal checker or by the customer portal.

### M044-F003 — Archive/restore is presented as reversible but deletes the artifact and cannot bind the archived row

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- `api/database/migrations/0444_add_soft_deletes_to_all_tables.php:40-45` adds `deleted_at` to shipments, shipment documents, containers, vehicles, deliveries, and delivery proofs.
- Restore routes do not opt into trashed model binding at `api/app/Modules/SupplyChain/routes.php:35-56,69-84,112-127`; the controllers receive ordinary `SoftDeletes` models and call `restore()` directly (`ShipmentController.php:158-187`, `DeliveryController.php:126-129`, `DeliveryProofController.php:143-151`).
- Shipment document deletion deletes the local file after commit at `api/app/Modules/SupplyChain/Services/ShipmentService.php:193-203`; proof deletion does the same at `DeliveryProofController.php:134-138`; delivery deletion removes proof and receipt paths at `DeliveryService.php:1093-1104`.
- The SPA promises that archived shipment documents and proofs “can be restored later” at `spa/src/pages/supply-chain/shipments/detail.tsx:526-549` and `spa/src/pages/supply-chain/deliveries/detail.tsx:661-681`.

Impact: after archive, normal route binding excludes the record, so the restore endpoint cannot find it. Even if binding were corrected, the physical private file has already been deleted, so restore would resurrect metadata pointing at a missing artifact. The same mismatch affects delivery restoration and all attached proof files.

### M044-F004 — Confirmed proof of delivery is mutable and its last-proof invariant races

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- `DeliveryProofController::store()` has no delivery-status guard and accepts new proofs at `api/app/Modules/SupplyChain/Controllers/DeliveryProofController.php:50-85`.
- `DeliveryService::uploadReceiptPhoto()` explicitly allows both `delivered` and `confirmed` at `api/app/Modules/SupplyChain/Services/DeliveryService.php:592-613`.
- Confirmed delivery proof deletion is blocked only when the pre-transaction count says the target is the last proof at `DeliveryProofController.php:124-138`. The delivery row is not locked, and the count is outside the transaction.
- Confirmation locks the delivery and checks `proofs()->count()` at `DeliveryService.php:646-672`, but proof deletion does not lock that same delivery row.

Impact: after confirmation, a user can add proofs, add a replacement receipt, and delete any proof while another remains. Two concurrent deletes against two proofs can each observe one remaining proof and commit a confirmed delivery with zero proofs. The code comments call proof the legally defensible confirmation record, but there is no append-only or authorized correction policy.

### M044-F005 — Narrow delivery readers can open the delivery page but cannot use its proof/receipt read contract

Classification: Incomplete  
Priority: P2  
Session: separate-recommended

Evidence:

- Delivery list/show accepts either broad or narrow read permission at `api/app/Modules/SupplyChain/routes.php:87-99`.
- Receipt-photo and every internal proof read route require only the broad `supply_chain.view` at `routes.php:106-123`; they do not accept `supply_chain.deliveries.view`.
- `warehouse_staff` is intentionally granted only the narrow delivery read at `RolePermissionSeeder.php:604-630`.
- The delivery detail page immediately requests proof options at `spa/src/pages/supply-chain/deliveries/detail.tsx:59-71` and renders proof/receipt URLs at `:325-346,453-460`.
- The focused read-permission suite covers the delivery list and shipment denial, but not proof options, proof view, or receipt-photo access (`api/tests/Feature/SupplyChain/DeliveryReadPermissionTest.php`).

Impact: a narrow reader can reach `/supply-chain/deliveries/:id`, then receives 403 responses for the proof options and private file links that the page advertises. Either the narrow permission must include the intended read-only proof contract, or the SPA must hide those controls and the API must use an explicit separate proof policy.

### M044-F006 — Landed-cost calculation has no reachable cost-entry path, a fake manual mode, and cent drift

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- `ShipmentController::calculateLandedCost()` accepts only `allocation_method` at `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:164-175`.
- `ShipmentService::updateMeta()` allow-lists only carrier/vessel/container/B/L/dates/notes at `api/app/Modules/SupplyChain/Services/ShipmentService.php:145-154`; it cannot write freight, insurance, duties, brokerage, or other charges.
- `LandedCostService::calculate()` reads those money fields through binary floats at `api/app/Modules/SupplyChain/Services/LandedCostService.php:53-59`.
- `manual` allocation is implemented as an equal split, not operator-supplied line allocations, at `LandedCostService.php:143-149`.
- Each component is independently rounded per PO line at `LandedCostService.php:78-101`, with no residual-cent reconciliation before `landed_cost_total` is set.
- `spa/src/api/supply-chain/index.ts:39-68` has no calculate or landed-cost update method, and `spa/src/pages/supply-chain/shipments/detail.tsx:404-453` has no landed-cost entry or allocation panel.

Impact: operators cannot enter the costs or run the calculation through the shipped UI/API contract. If data is populated out-of-band, “Manual” silently equal-splits it, and a three-line 100.00 allocation can store 99.99 in line totals while the shipment total remains 100.00. This is a financial-control issue, not a presentation-only gap.

### M044-F007 — Manual delivery creation has no durable idempotency contract

Classification: Incomplete  
Priority: P1  
Session: separate-recommended

Evidence:

- `DeliveryController::store()` simply forwards the validated payload to `DeliveryService::create()` at `api/app/Modules/SupplyChain/Controllers/DeliveryController.php:53-55`.
- `DeliveryService::create()` generates a new delivery number and inserts a new delivery on every call at `api/app/Modules/SupplyChain/Services/DeliveryService.php:151-180`; no idempotency key or request fingerprint is accepted.
- The only delivery identity constraint in `0096_create_deliveries_table.php:21-41` is the generated `delivery_number`; `0097_create_delivery_items_table.php:20-31` has no command/source uniqueness.
- The SPA mutation has no idempotency header or client command key at `spa/src/pages/supply-chain/deliveries/create.tsx:98-119`.
- The QC listener has an explicit lock/re-check deduplication path at `CreateDeliveryDraftOnQcPass.php:83-117`, but the manual endpoint does not share that command identity.

Impact: a timeout or client retry can create two scheduled deliveries for the same intended dispatch whenever sufficient quantity remains. The quantity locks prevent over-delivery, but they do not distinguish a legitimate partial shipment from a duplicate command; both rows can later produce physical dispatch and invoice effects.

### M044-F008 — Delivery status has no database domain guard, and shipment deletion races terminal status

Classification: Hardening  
Priority: P1  
Session: separate-recommended

Evidence:

- `DeliveryStatus` and its forward transitions exist only in PHP at `api/app/Modules/SupplyChain/Enums/DeliveryStatus.php:8-31`; the base delivery migration declares a free-form string at `0096_create_deliveries_table.php:27-41`.
- The lifecycle-check migration adds a `shipments.status` guard at `api/database/migrations/2026_08_13_220000_add_remaining_lifecycle_status_checks.php:69`, but its delivery entry covers only `invoice_handoff_status` at `:82-84`; vehicle status is guarded at `:88`.
- Delivery service transitions re-read and lock the delivery (`DeliveryService.php:296-311`), but direct model/listener/raw writes have no database status-domain backstop.
- Shipment transition locks the row at `api/app/Modules/SupplyChain/Services/ShipmentService.php:109-137`, while shipment deletion checks the caller's possibly stale status before opening its transaction at `:206-215` and never locks the shipment first.

Impact: an out-of-bound write can introduce an invalid delivery status that later breaks enum casts or lifecycle reporting. A stale shipment delete can race a transition to `received` and archive a shipment that the service says must not be deleted. Existing status-race coverage proves the forward transition guard but not this delete path.

### M044-F009 — Container and fleet management are API-only on the internal SPA

Classification: Missing  
Priority: P2  
Session: separate-recommended

Evidence:

- Container CRUD routes exist at `api/app/Modules/SupplyChain/routes.php:58-70`, but `ShipmentService::show()` eager-loads purchase order, creator, and documents only at `api/app/Modules/SupplyChain/Services/ShipmentService.php:69-75`; it does not load containers.
- The shipment detail surface has no container section in its main panels (`spa/src/pages/supply-chain/shipments/detail.tsx:226-453`), despite the process flow instructing operators to add containers at `docs/PROCESS-FLOWS.md:603-626`.
- Vehicle create/update/delete/restore endpoints exist at `routes.php:72-84` and `VehicleController.php:40-77`, but `spa/src/pages/supply-chain/fleet.tsx:19-61` is read-only and `spa/src/api/supply-chain/index.ts:127-134` exposes only list/options methods.

Impact: operators cannot maintain the container record attached to a shipment or maintain the fleet from the supplied internal UI. The API surface and process documentation promise capabilities the reachable SPA does not provide; fleet-driver work should coordinate with this boundary rather than duplicate it.

### M044-F010 — Private document download headers use arbitrary client filenames directly

Classification: Polish  
Priority: P2  
Session: same-session-ok after lifecycle work

Evidence:

- Delivery proof download stores the client original name at `api/app/Modules/SupplyChain/Controllers/DeliveryProofController.php:67-75` and interpolates it directly into `Content-Disposition` at `:100-113`.
- Shipment document download does the same with `original_filename` at `api/app/Modules/SupplyChain/Controllers/ShipmentController.php:134-147`; the customer portal repeats the pattern at `api/app/Modules/B2B/Controllers/CustomerPortalController.php:166-177`.

Impact: unusual quotes, control characters, or non-ASCII names can produce malformed response headers or a failed download. Use a standards-compliant disposition builder with a safe fallback filename and cover the internal and portal streams.

## Verified strengths

- Delivery quantity reservations lock the sales order and relevant lines; outgoing-QC provenance and accepted-quantity capacity are checked, and the focused reconciliation/concurrency suite passed.
- Delivery status changes re-read and lock the delivery, serialize shared vehicle activation, and prevent direct status-based confirmation at `DeliveryService.php:296-412`.
- Confirmation is idempotent under a delivery lock and persists invoice-handoff recovery/outbox records at `DeliveryService.php:646-812`; focused invoice handoff tests passed.
- The QC auto-draft path has an explicit lock/re-check deduplication guard at `CreateDeliveryDraftOnQcPass.php:83-117`.
- Private upload/download paths use the local disk and authentication; focused document-access tests passed. Customer portal delivery reads are customer-scoped in `CustomerPortalService.php:167-196` and the portal proof test passed.

## Verification

Passed:

- `docker compose run --rm api php artisan test tests/Feature/SupplyChain/DeliveryConfirmTest.php tests/Feature/SupplyChain/DeliveryLifecycleConcurrencyTest.php tests/Feature/SupplyChain/DeliveryQuantityReconciliationTest.php tests/Feature/SupplyChain/DeliveryReadPermissionTest.php tests/Feature/SupplyChain/DeliveryUploadTest.php tests/Feature/SupplyChain/DocumentAccessTest.php tests/Feature/SupplyChain/DriverDeliveryTest.php tests/Feature/SupplyChain/AutoInvoiceOnDeliveryConfirmTest.php tests/Feature/SupplyChain/CocAutoAttachOnConfirmTest.php tests/Feature/SupplyChain/ShipmentCreateTest.php tests/Feature/SupplyChain/ShipmentStatusRegressionRaceTest.php tests/Feature/SupplyChain/ImpexDocumentTest.php` — 77 tests, 176 assertions.
- `docker compose run --rm spa npm run typecheck` — passed.

Evidence limits:

- No browser-driven authenticated journey was run for manual delivery creation, assignment, archive/restore, narrow-role proof access, fleet/container management, or landed-cost entry.
- No concurrent proof-delete, manual delivery replay, shipment-delete/received race, or cent-level landed-cost allocation test exists in the focused suite.
- No deployed storage-retention, customer confirmation, or restore drill was available.
