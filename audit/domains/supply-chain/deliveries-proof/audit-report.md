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

---

## Re-audit — 2026-09-01

Claim: RECLAIMED (stale lock, 153h old, from the 2026-08-25 session).

Environment baseline recorded at claim time:
- `docker compose ps`: `ogami-db` Up (healthy), `ogami-redis` Up. `ogami-api`/`ogami-nginx`/`ogami-spa` exited; tests run via `docker compose run --rm api`.
- `docker compose exec -T db psql -U ogami -d postgres -c "select 1;"` → 1 row.

Scope of this re-audit: verify the 10 prior findings by probe (not by log), resolve the
inherited red `tests/Feature/SupplyChain/CocAutoAttachOnConfirmTest` fixture question left
by the quality session's evidence-integrity guard, and run the invariant matrix for the
outgoing-QC → delivery → confirmation → invoice last mile.

### Prior-work assessment

The 2026-08-25 session's log is **accurate but incomplete, and one finding is stale.**
Its F001 work is genuinely in the tree (swept into `167de85e`), and its claimed test
figures are consistent with what re-execution produced. Verified by probe, not by log:

| prior finding | reproduces? | evidence |
|---|---|---|
| F001 manual create cannot satisfy the inspection contract | **NO — fixed** | `CreateDeliveryRequest.php:70` now requires `items.*.inspection_id`; `inspection-options` route + `DeliveryService::inspectionOptions()` exist; measured 422 with `items.0.inspection_id` when omitted |
| F002 assignment/confirmation actors cannot complete the workflow | **YES (partly closed)** | `assign()` + `PATCH /deliveries/{d}/assignment` now exist, but the RBAC half is untouched — see M044-F002 below, with a measured 403 matrix |
| F003 archive deletes the artifact and restore cannot bind | **YES** | measured: file gone from disk, proof row still live, restore → 404 |
| F004 confirmed proof is mutable and the last-proof invariant races | **YES (race half fixed this session)** | measured: CoC deleted + replaced after confirmation; receipt photo rewritten on a confirmed invoiced delivery |
| F005 narrow readers 403 on the proof contract their page requests | **YES** | measured: `warehouse_staff` → 403 on proofs/options, proofs index, proof view, receipt-photo |
| F006 landed cost has no reachable cost-entry path | **YES** | `grep -rn "calculate-landed-cost\|containers" spa/src/` → zero hits |
| F007 no idempotency contract on manual create | **YES** | no key accepted; `DeliveryController::store()` still forwards straight to `create()` |
| F008 delivery status has no database domain guard | **NO — was already wrong** | `deliveries_status_check` exists in PostgreSQL (migration `2026_08_13_110000_add_lifecycle_status_checks.php:41-42`), which the prior audit missed. Its **shipment-delete race** half stands. |
| F009 container + fleet management are API-only | **PARTLY** | fleet now has `vehiclesApi.create/update/destroy/restore`; containers still have 6 API routes and zero client |
| F010 download headers use arbitrary client filenames | **YES (fixed this session)** | measured `inline; filename="a".jpg"` |

**8 of 10 reproduce** (F004 and F009 partly). F001 is closed; F008's first half was never true.

### Baseline (measured, before any change)

```
docker compose run --rm -e DB_DATABASE=ogami_test_dlv api php artisan test tests/Feature/SupplyChain
→ 93 tests: 91 passed, 2 failed, 226 assertions, 138.23s
```
The 2 failures were exactly the inherited `CocAutoAttachOnConfirmTest` cases.
After this session: **109 passed, 0 failed, 304 assertions.**
`phpstan analyse app/Modules/SupplyChain` → no errors.

### The inherited red tests — conclusion: **case (a)**, the fixture was unrealistic

The quality session's reasoning holds and I verified it rather than accepting it.
`InspectionStatus::Passed` is written in exactly **one** place in the application,
`api/app/Modules/Quality/Services/InspectionService.php:567`, inside `complete()`,
which refuses the fixture's state twice before it can reach `passed`:
`InspectionService.php:552-554` (no measurement rows) and `:556-559` (any
`is_pass IS NULL`). Scaffold rows are inserted one per (sample unit x spec item)
at `:375-399`, so `count(distinct sample_index)` always reaches `sample_size`.
**No production path produces a `passed` inspection with zero measurements.**
The fixture now seeds resolved readings; both tests are **green**, the guard was
not weakened, and no Quality file was touched.

One caveat found while proving it, which IS a finding against Quality — see
M044-F016: the guard's `failing > 0` rule contradicts AQL acceptance numbers.

---

## Findings — 2026-09-01

### M044-F014 — A delivery ships and invoices with no Certificate of Conformance, and only a log line says so

Classification: **Broken** · Priority: **P0** · Session: **separate-recommended** (policy)

- `api/app/Modules/SupplyChain/Services/DeliveryService.php:885-892` wraps
  `attachCertificatesOfConformance()` in `try { } catch (\Throwable) { Log::warning(...) }`,
  commented "Best-effort; never blocks confirm."
- Measured (`ZzM044AuditProbeTest::test_probe_confirm_when_coc_generation_is_refused`):
  with the inspection's measurement evidence removed, `confirm()` returns
  `confirmed`, **zero** `proof_type='coc'` rows exist, and `deliveries.invoice_id`
  is **not null** — the customer is billed for an uncertified shipment.
- The asymmetry is the whole point. The invoice handoff sitting 30 lines below has
  **four** durable signals when it fails: a persisted `invoice_handoff_status`
  column (`:924-928`), an AR notification (`:950-959`), a replayable outbox event
  (`:974-981`), and a `delivery_confirmed_without_invoice` Finance bottleneck after
  four hours (`docs/PROCESS-FLOWS.md:360-363`). The CoC handoff has **none** of
  those — no column, no notification, no outbox row, no watchdog.
- `docs/PROCESS-FLOWS.md:353` lists "Certificate of Conformance auto-attached"
  as an **AUTO-TRIGGER** of confirm, with no hint it can silently not happen.
- CLAUDE.md: "Every shipment gets a Certificate of Conformance auto-generated from
  inspection data." For an IATF 16949 supplier shipping to Toyota/Nissan/Honda this
  is the certificate the customer's incoming inspection relies on.

This is the exact pattern CLAUDE.md's own "Event/listener wiring" note warns about:
the work may be caught-and-logged, but a failure nobody can see is
indistinguishable from success. **Not fixed here** — the fix decides whether a
shipment may leave uncertified, which the brief reserves for a human. The minimum
containment-shaped option (a `coc_handoff_status` column + notification + outbox,
mirroring the invoice handoff exactly) does not block confirm and would be
same-session-able; refusing confirmation outright would not.

### M044-F013 — Accounting raises an invoice against a scheduled, in-transit, or cancelled delivery

Classification: **Broken** · Priority: **P0** · Owner: **accounts-receivable — report, do not fix**

- `api/app/Modules/Accounting/Services/InvoiceService.php:687-692`
  (`resolveSourceChain()`) locks the delivery and checks it belongs to the selected
  sales order and customer, but **never reads `deliveries.status`**.
- Measured (`test_probe_ar_can_invoice_an_unconfirmed_or_cancelled_delivery`):
  `POST /api/v1/invoices` with a `delivery_id` returns **201** for a `scheduled`
  delivery, **201** for `in_transit`, and **201** for a **`cancelled`** one.
- SupplyChain's own gate is sound — `confirm()` refuses anything but `delivered`
  (`DeliveryService.php:818-820`) and `retryInvoiceHandoff()` refuses anything but
  `confirmed` (`:1005-1007`). The proof-of-delivery precondition is enforced on one
  side of the contract only, so the last mile can be billed by going around it.
- Nor is the invoiced quantity bounded by the delivered quantity on that path:
  the probe billed 10 units against a delivery for 10 that had not moved.

### M044-F011 — A cancelled sales order still accepted new deliveries — **FIXED**

Classification: **Broken** · Priority: **P1** · **Fixed this session**

- Before: `DeliveryService::create()` locked the sales order (`:238`) and validated
  quantities but never read `sales_orders.status`; cancelling an order does not
  release its ordered quantity, so the reservation ledger still reported capacity.
  Measured: `create()` returned a delivery against a `cancelled` order.
- The auto-draft listener (`Quality/Listeners/CreateDeliveryDraftOnQcPass.php:144`)
  builds its delivery directly but calls the same public
  `assertDeliveryQuantitiesAvailable()`, so that method is the single seam covering
  both paths — and it is in this module.
- After: `DeliveryService.php:631-655` refuses `SalesOrderStatus::Cancelled`.
- **Question for a human:** `draft` is still accepted. Shipping against an
  unconfirmed order is arguably also wrong, but refusing it changes which orders
  may ship, so it is deliberately left alone.

### M044-F004 — Confirmed proof of delivery is mutable; the CoC can be deleted and replaced

Classification: **Broken** · Priority: **P1** · Session: **separate-recommended** (policy) · race half **FIXED**

- `DeliveryProofController::store()` has no delivery-status guard. Measured
  (`test_probe_coc_can_be_deleted_and_replaced_after_confirmation`): after
  confirmation the auto-generated CoC is **deleted** (204) and an arbitrary
  operator-supplied PDF is **posted back as `proof_type='coc'`** (201). The
  certificate a customer may hold is not the certificate on file.
- `DeliveryService::uploadReceiptPhoto()` explicitly allows `Confirmed`
  (`:771-773`). Measured: `receipt_photo_path` is rewritten on a confirmed,
  invoiced delivery.
- **FIXED**: the last-proof count in `destroy()` was taken before the transaction
  and without a lock, so two concurrent deletes could each see one proof remaining
  and both commit a confirmed delivery with zero proofs. It now locks the delivery
  row (the same row `confirm()` serializes on) and the target proof inside the
  transaction.
- Still open: there is no append-only policy, no distinct correction permission,
  no reason/actor/replacement linkage, and **zero database triggers** on
  `deliveries`, `delivery_items`, or `delivery_proofs` (measured:
  `select tgname from pg_trigger where not tgisinternal and tgrelid in (...)`
  → 0 rows). The `journal-ledger` precedent is an observer **plus** a `P0001`
  trigger; this financial record has neither.

### M044-F003 — Archive permanently destroys the proof artifact and restore cannot bind

Classification: **Broken** · Priority: **P1** · Session: **separate-recommended** (retention policy)

Measured end to end (`test_probe_archive_deletes_the_files_and_restore_cannot_bind`):
1. upload a proof → file present on the local disk;
2. `DELETE /supply-chain/deliveries/{id}` → 204;
3. the proof **file is gone** (`DeliveryService.php:1279` deletes it after commit);
4. the `delivery_proofs` row is **still live** — `deleted_at` is null — now pointing
   at a missing artifact;
5. `PATCH /supply-chain/deliveries/{id}/restore` → **404**.

Five of the six restore routes lack `->withTrashed()` and therefore 404 for every
valid target: `routes.php:35` (shipments), `:55` (shipment documents), `:69`
(containers), `:119` (deliveries), `:133` (delivery proofs). Only `:83-85`
(vehicles) has it — and `FleetDriverHardeningTest` passes precisely because of
that. Adding the binding alone is **half a fix** and arguably worse: it would
resurrect metadata pointing at deleted files. The retention decision comes first.
The 404 body is at least clean — `"No query results for model [...Delivery]"`,
no raw primary key.

### M044-F002 — No seeded role can operate the last mile

Classification: **Missing** · Priority: **P0 (operational)** · Session: **separate-recommended** (RBAC policy)

`supply_chain.deliveries.create`, `supply_chain.deliveries.confirm` and
`supply_chain.fleet.manage` are **defined** at `RolePermissionSeeder.php:260-261`
and `:254` and granted to **no seeded role at all**; `system_admin`'s `'*'`
(`:470-474`) is the only holder. Measured matrix
(`test_probe_registry_role_permission_matrix`):

| endpoint | system_admin | purchasing_officer | warehouse_staff | impex_officer | driver |
|---|---|---|---|---|---|
| `GET /deliveries` | 200 | 200 | 200 | 200 | 403 |
| `GET /deliveries/{id}` | 200 | 200 | 200 | 200 | 403 |
| `GET /deliveries/inspection-options` | 200 | **403** | **403** | **403** | 403 |
| `GET /deliveries/driver-options` | 200 | **403** | **403** | **403** | 403 |
| `GET /deliveries/proofs/options` | 200 | 200 | **403** | 200 | 403 |
| `GET /deliveries/{id}/proofs` | 200 | 200 | **403** | 200 | 403 |
| `GET /deliveries/{id}/proofs/{p}/view` | 404\* | 404\* | **403** | 404\* | 403 |
| `GET /deliveries/{id}/receipt-photo` | 404\* | 404\* | **403** | 404\* | 403 |
| `POST /deliveries/{id}/confirm` | 200 | **403** | **403** | **403** | 403 |
| `PATCH /deliveries/{id}/status` | 422\*\* | **403** | **403** | **403** | 403 |
| `PATCH /deliveries/{id}/assignment` | 422\*\* | **403** | **403** | **403** | 403 |
| `POST /vehicles` | 422\*\* | **403** | **403** | **403** | 403 |

\* 404 = permission passed, the faked disk has no bytes. \*\* 422 = permission
passed, empty payload rejected.

So a real operator can **read** the delivery schedule and **do nothing with it**.
Nobody but a system administrator can create a delivery, assign a driver, advance
a status, upload proof, or confirm receipt — the whole of Chain 1's last mile. The
prior session's own test carries the admission in a comment:
`tests/Feature/SupplyChain/CreateDeliveryDriverGateTest.php:119-120`, "No seeded
role carries supply_chain.deliveries.create", worked around with a throwaway role
rather than fixed. This is the same class as the seeder's own recorded L-37
(`RolePermissionSeeder.php:446-449`) and M036 (`:578-585`) defects.

`DeliveryService::confirm()`'s docblock says "CRM officer confirms delivery"
(`:801`) and `DriverDeliveryService:20-22` says "that's the CRM officer's job",
but `finance_officer` holds no `supply_chain.*` permission at all and there is no
`crm_officer` role. The named actor does not exist.

**Related — customer confirmation is documented but not built.**
`docs/PROCESS-FLOWS.md:332` describes `POST .../confirm` as "**customer** confirms
receipt", but the route is internal Sanctum-only (`routes.php:115-116`) and the
customer portal exposes read-only delivery routes (`B2B/routes.php:103-105`).
Measured: `POST /api/v1/b2b/customer/deliveries/{id}/confirm` → **404**. Either
build portal confirmation or correct the process contract; the brief reserves
"who may confirm" for a human.

### M044-F012 — A delivery never decrements finished-goods stock

Classification: **Missing** · Priority: **P1** · Owner spans **warehouse-stock-control — report, do not fix**

- `StockMovementType::Delivery` exists (`Inventory/Enums/StockMovementType.php:12`),
  is listed as non-GL (`MovementGlPostingService.php:49`), is zone-consumability
  checked (`StockMovementService.php:313`) and is an ABC consumption bucket
  (`AbcClassificationService.php:44`) — and **nothing anywhere emits one**.
  `grep -rn "StockMovementType::Delivery" app/` returns only those two consumers.
- `grep -rn "StockMovement" app/Modules/SupplyChain/` → **zero hits**. The module
  never touches inventory.
- Measured (`test_probe_delivery_emits_no_finished_goods_stock_movement`): shipping
  and confirming a delivery leaves the `stock_movements` count unchanged (0 → 0),
  and `where movement_type = 'delivery'` is empty.
- Production **does** increment finished goods on output
  (`Production/Services/WorkOrderOutputService.php:494-505`, `ProductionReceipt`).
  So finished-goods on-hand rises with every batch produced and never falls when
  goods leave the plant. CLAUDE.md's Chain 1 is "Finished Goods → QC (outgoing
  AQL) → Delivery"; the decrement half of that arrow is absent.
- Because nothing is ever written, the questions "is the decrement inside
  `DB::transaction()`", "can it go negative", "is it applied twice", "is it
  restored exactly on void" are all vacuous here. They become live the moment the
  movement is implemented.

### M044-F015 — Nothing records what the customer actually accepted

Classification: **Missing** · Priority: **P2** · Session: **separate-recommended** (changes what is invoiced)

`delivery_items.quantity` is the quantity **scheduled** at creation. Confirmation
captures `receiver_name`, `receiver_position`, `received_at` and
`delivery_remarks` (`DeliveryService.php:858-874`) but **no quantity**, and there
is no delivery-item update endpoint. `createDraftInvoice()` then bills
`(string) $i->quantity` verbatim (`:1199`). A short delivery — truck arrives with
8 of 10 — is invoiced for 10 and `syncDeliveredQuantities()` writes 10 into the
SO ledger. The good news, measured, is that a *planned* partial ships and bills
correctly (see strengths); it is the *unplanned* shortfall that has nowhere to go.
Explicitly **not** fixed: this changes what quantity is invoiced.

### M044-F016 — The new CoC evidence guard contradicts the AQL acceptance number

Classification: **Broken** · Priority: **P1** · Owner: **quality / inspections-certificates — report, do not fix**

Found while verifying the inherited fixture, and it is the one respect in which
the M056 guard is too strict:

- `CoCService::assertEvidenceSupportsCertificate()` refuses a certificate when
  **any** measurement failed — `failing > 0` →
  `COC_EVIDENCE_CONTRADICTS_VERDICT` (`CoCService.php:238-243`).
- But `InspectionService::complete()` passes a lot when
  `! $criticalFail && $defects <= $accept` (`InspectionService.php:562-565`), and
  `accept_count` comes from the seeded AQL 0.65 Level II plan, where **Ac is
  non-zero for every lot over 280 units**: `Ac=1` at 281–500 and 501–1200, `Ac=2`
  to 3200, `Ac=3` to 10000, up to `Ac=14`
  (`database/migrations/0409_seed_quality_aql_sample_plan.php:23-31`).
- So a batch of 400 with one accepted non-critical defect is a **legitimate AQL
  pass** whose CoC is refused. Injection-molding lots at this plant are routinely
  over 280 units, so this is the normal case, not an edge one.
- Because of M044-F014 the refusal is then swallowed and the shipment goes out
  uncertified with only a log line. The two defects compound.
- The guard's other three rules (no rows, unresolved rows, short of `sample_size`)
  are sound and should stay. Only the `failing > 0` rule needs to become
  "failing > accept_count, or any critical failure".

### M044-F005 — Narrow delivery readers 403 on the proof controls their own page requests

Classification: **Incomplete** · Priority: **P2** · Session: **separate-recommended** (permission policy)

Reproduced and now measured, not inferred. `warehouse_staff` is deliberately given
only `supply_chain.deliveries.view` (`RolePermissionSeeder.php:676-679`) and the
delivery list/show accept it (`routes.php:96,102,104`), but every proof and
receipt route requires the broad `supply_chain.view` (`routes.php:114,124,126,130`).
Measured: 403 on proofs/options, proofs index, proof view and receipt-photo —
while `spa/src/pages/supply-chain/deliveries/detail.tsx:59-71` requests proof
options on mount. The role reaches the page and the page fails.

### M044-F007 — No idempotency contract on manual delivery creation

Classification: **Incomplete** · Priority: **P1** · Session: **separate-recommended**

Reproduced unchanged. `DeliveryController::store()` (`:82-85`) forwards straight
to `create()`, which generates a new delivery number on every call; no
`X-Idempotency-Key`, no payload fingerprint, no command table. The quantity and
inspection locks prevent over-delivery but cannot distinguish a legitimate partial
shipment from a duplicated command — both are valid reservations. The auto-draft
listener has an explicit lock/re-check dedup path
(`CreateDeliveryDraftOnQcPass.php:83-117`); the manual endpoint shares none of it.

### M044-F008 — Shipment deletion races a terminal status (status-domain half NOT reproduced)

Classification: **Incomplete** · Priority: **P2** · Session: **separate-recommended**

The prior audit's claim that delivery status has no database guard is **wrong**:
`deliveries_status_check` is present in PostgreSQL —
`CHECK (status = ANY (ARRAY['scheduled','loading','in_transit','delivered','confirmed','cancelled']))`
— from `2026_08_13_110000_add_lifecycle_status_checks.php:41-42`, alongside
`deliveries_invoice_handoff_status_lifecycle_check`. What stands is the other
half: `ShipmentService::destroy()` checks the caller's possibly-stale status
before opening its transaction (`:206-215`) and never locks the shipment, so a
stale delete can race a transition to `received`. Not executed — see limits.

### M044-F006 — Landed cost has no reachable cost-entry path

Classification: **Broken** · Priority: **P1** · Session: **separate-recommended** (money rules)

Reproduced. `ShipmentController::calculateLandedCost()` accepts only
`allocation_method` (`:164-175`); `ShipmentService::updateMeta()` allow-lists
carrier/vessel/container/B-L/dates/notes and cannot write freight, insurance,
duties or brokerage (`:145-154`); `LandedCostService::calculate()` reads those
money columns through binary floats (`:53-59`); `manual` allocation is an equal
split, not operator input (`:143-149`); each component is rounded per PO line with
no residual-cent reconciliation (`:78-101`). And it is unreachable:
`grep -rn "calculate-landed-cost" spa/src/` → zero hits.

### M044-F009 — Containers are an API-only surface

Classification: **Missing** · Priority: **P2** · Session: **separate-recommended**

Six container routes (`routes.php:59-70`) with **no** SPA client method and no
shipment-detail section; `ShipmentService::show()` does not eager-load containers
(`:69-75`), while `docs/PROCESS-FLOWS.md:603-626` instructs operators to add them.
The fleet half of the original finding is now partly closed —
`spa/src/api/supply-chain/index.ts:176-181` has `vehiclesApi.create/update/destroy/restore`.

### M044-F010 — Client filename forged the download header — **FIXED**

Classification: **Polish** · Priority: **P2** · **Fixed this session**

Measured before: a proof named `a".jpg` produced
`Content-Disposition: inline; filename="a".jpg"` — the quote closes the parameter
early. After: `inline; filename="a.jpg"; filename*=UTF-8''a.jpg` (RFC 6266),
matching the shape `B2B/Controllers/CustomerPortalController.php:187-199` already
uses. `ShipmentController::downloadDocument()` (`:134-147`) still has the raw
pattern and is left for the shipment tranche.

### M044-F017 — `status` is mass-assignable on a financial record

Classification: **Polish** · Priority: **P2** · Session: **separate-recommended**

`Delivery::$fillable` includes `'status'` (`Models/Delivery.php:27-34`) and
`DeliveryProof` has no `proof_type` domain check in the database
(`varchar(30)`, no constraint). CLAUDE.md's mass-assignment hardening convention
removed `status` from `$fillable` on Loan, LeaveRequest, PR, PO, PayrollPeriod and
NCR; `Delivery` was missed. Deferred rather than done: several tests across
modules mass-assign `Delivery` status, so removing it is a cross-module change,
not a contained one.

## Verified strengths — all measured this session

- **Every illegal status transition is refused.** 12 probed, 12 refused, with
  specific messages: loading without a vehicle; scheduled→in_transit;
  scheduled→delivered; confirm a scheduled delivery; confirm with no proof;
  reaching `confirmed` through the status route; cancel a delivered shipment;
  re-open a cancelled one; cancel a confirmed one; delete a confirmed one;
  reassign a confirmed one. Re-confirming is an idempotent no-op.
- **A partial delivery invoices only what landed.** SO line orders 10, delivery
  carries 4 → the invoice line is `4.00` and `sales_order_items.quantity_delivered`
  is `4.00`. The invoiced quantity comes from `delivery_items`, never the order.
- **Upload MIME is validated from the bytes.** Probed with a real
  `Illuminate\Http\UploadedFile` (not `UploadedFile::fake()`, whose
  `getMimeType()` reads the filename) carrying `<?php ... ?>` bytes under `.jpg`
  and `.pdf` names: **422 on both** the receipt and the multi-proof endpoint.
- **Stored filenames are random and traversal is neutralised.** A client name of
  `../../../../etc/passwd.jpg` stored as
  `deliveries/16/proofs/pM1PqlKF...jpg`; the display name is basenamed to
  `passwd.jpg` by Symfony before it is persisted.
- **Files live outside the web root** — the `local` disk (`storage/app`), served
  only through permission-gated controller actions. No `/storage/` URL exists.
- **Quantity validation is airtight.** `1.999`, `1e3`, `1e17`, `1e20`,
  `10.00005`, `-1`, `0` → **422 on all seven**, no 500, no silent rounding, no row
  written. `decimal:0,2` (`CreateDeliveryRequest.php:66`) plus
  `normaliseDeliveryQuantity()`'s `/^\d+(?:\.\d{1,2})?$/D` — the sibling-module
  `numeric|min:0` defect is not present here.
- **No archived-row leakage or 500s.** Archived delivery: absent from the list,
  404 on detail. Archived customer behind a live delivery: list 200, detail 200.
  Archived sales order: list 200, detail 200. `inspection-options` returns nothing
  for an archived inspection.
- **`driver` is confined to its own assignments.** Own delivery 200, another
  driver's 404 (`DriverDeliveryService.php:122-127`, 404 chosen over 403 to hide
  existence), with an **empty** message body — no delivery number, no primary key.
- **Customer portal cross-tenant isolation holds.** Customer A on B's delivery →
  403 with an empty message; on B's proof → 404 (`B2BTenancyScopeMiddleware`
  scopes the route binding itself). Neither response echoes the victim's DR or SO
  number. A's own list is empty.
- **No silent auto-confirm job exists.** `routes/console.php` schedules 43
  commands and **none** touches deliveries; `grep -rln Delivery app/Console/Commands/`
  finds only export/summary/demo commands. There is nothing to run, so the
  "zero counts but exit 0" hazard has no instance here. The one delivery-adjacent
  listener, `CreateDraftInvoiceOnDeliveryInvoiceRequested`, is a positive example:
  it records `skipped`/`manual_required`/`completed` outcomes explicitly and
  **re-throws** unexpected `Throwable` instead of swallowing it.
- **HashIDs are clean.** Every `id` in `DeliveryResource` and
  `DeliveryProofResource` is `hash_id`, including nested SO, customer, vehicle,
  driver, confirmer, invoice, shipment lot, items and inspection. No raw primary
  key appears in any refusal body probed.
- **Reservation and confirmation locking is genuinely careful** — the sales order
  is the serialization point for all reservations (`DeliveryService.php:238`),
  lines and inspections are locked individually (`:266`, `:422`), vehicles are
  serialized on their own row with an explicit note on why locking the delivery
  alone is insufficient (`:499-544`), and `confirm()` re-reads under
  `lockForUpdate()` before writing (`:826`).

## Evidence limits — stated plainly

- **The concurrent proof-delete race was not executed.** It is closed by
  inspection (the count is now inside the transaction behind the same row lock
  `confirm()` uses), but `RefreshDatabase` hides uncommitted rows from a second
  connection, so a naive two-connection probe would report "no lock" as an
  artifact, and a real two-PDO probe on this unique-insert path risks the deadlock
  a prior session correctly abandoned. Reported as reasoned, not measured.
- **The shipment-delete / received race (F008 second half) was not executed.**
- **No browser-driven authenticated journey** was run for delivery creation,
  assignment, archive/restore, landed cost, or container management.
- **No 200+ character filename probe** against `delivery_proofs.file_name`
  (`varchar(200)`) — a long client name may still reach PostgreSQL as `22001`.
  `DeliveryProofController::store()` validates the *file*, never the name length.
- **`APP_DEBUG` is true under test**, so refusal bodies carry a stack trace. Every
  raw-primary-key assertion here is made against the `message` field only; the
  trace inevitably contains 2-digit line numbers that a naive substring check on a
  small integer id would false-positive on. That is a test-harness artifact, not
  evidence about production bodies — production runs `APP_DEBUG=false`.
- **No deployed storage-retention or restore drill** was available.
