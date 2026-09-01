# M058 — Traceability & PPAP audit report

**Audit date:** 2026-08-25  
**Domain/module:** quality / traceability-ppap  
**Tier:** 3  
**Claim:** M058 was claimed atomically after registry refresh as the only unlocked `📋 Plan Ready` Tier 3 candidate.  
**Audit mode:** fresh audit. The previous report was not reused because the source files cited by it have uncommitted changes in the shared worktree. No relevant committed git changes were present.

## Scope and conclusion

This audit covers the Quality traceability and PPAP surfaces, their API contracts, the related SPA/operator surfaces, and the directly connected lineage/lot/portal code read for context. Dependency modules were not modified.

The module has real foundations: authenticated and permissioned routes, hash-ID resources in most response paths, unique batch and shipment-lot identifiers, persisted stock-movement lot fields, a supplier-tenanted PPAP read endpoint, and a delivery service that now contains a strong output/QC/Sales Order validation helper. The critical problem is that the authoritative contracts are not joined at the points where traceability and approval are created. The normal work-order issue path still records a best-effort GRN snapshot rather than the lot actually issued; shipment-lot creation does not call the stronger delivery validation; PPAP approval can succeed with only the automatically-created PSW row; and the shipped SPA lacks PPAP, recall, and shipment-lot operator workflows.

**Production readiness:** 34/100 — risky; not ready for an auditable end-to-end release.

## Findings

### M058-F001 — Normal material issue lineage is not authoritative

**Classification:** Broken  
**Severity:** P1  
**Evidence:**

- `api/app/Modules/Production/Services/WorkOrderService.php:283-310` builds `material_lot_references` from the latest GRN item for each BOM item and labels the operation “best-effort latest GRN”.
- `api/app/Modules/Production/Services/WorkOrderService.php:355-364` calls the issue path and then captures that snapshot; the snapshot is not the result of the stock movement.
- `api/app/Modules/Production/Services/WorkOrderService.php:938-963` creates the normal work-order material issue without passing `lotNumber` into `StockMovementInput`.
- `api/app/Modules/Inventory/Services/MaterialIssueService.php:109-147` can persist a manually supplied lot, but accepts the optional value without proving that it belongs to the reservation/stock being issued.
- `api/app/Modules/Quality/Services/TraceabilityService.php:214-218,290-294` consumes the JSON snapshot as the material-to-work-order source.

**Impact:** A work order can trace to the latest received lot rather than the lot actually consumed, while the manual path can stamp a caller-selected lot. The flagship supplier-lot → GRN → issue → batch chain is therefore not evidence-grade.

### M058-F002 — Shipment-lot creation bypasses the delivery/QC contract

**Classification:** Broken  
**Severity:** P1  
**Evidence:**

- `api/app/Modules/Quality/Controllers/ShipmentLotController.php:36-46` validates only work-order ID strings, optional positive quantity, and an optional date.
- `api/app/Modules/SupplyChain/Services/ShipmentLotService.php:31-76` resolves work orders and checks only that each has a batch number before storing their IDs and a single total quantity. It does not lock the delivery, verify Sales Order line/product membership, verify passed output-bound outgoing inspection, allocate quantity per work order, reject duplicate IDs, or make creation idempotent.
- `api/app/Modules/SupplyChain/Services/DeliveryService.php:317-368` contains the stronger locked inspection/output/Sales Order/accepted-quantity validation, but `ShipmentLotService` does not invoke it.
- `api/database/migrations/0150_add_batch_lot_traceability.php:36-52` stores `work_order_ids` as unconstrained JSON and has no per-work-order allocation or database relationship to work orders.

**Impact:** A shipment lot can claim unrelated or over-allocated production, and traceability can report a relationship that was never validated against the delivery, product, QC result, or accepted quantity.

### M058-F003 — PPAP element completeness is not enforced by level

**Classification:** Broken  
**Severity:** P1  
**Evidence:**

- `api/app/Modules/Quality/Models/PpapElementType.php:7-28` defines 18 standard element cases plus PSW, while `api/app/Modules/Quality/Models/PpapLevel.php:7-30` describes level-specific submission expectations but supplies no required-element matrix.
- `api/app/Modules/Quality/Services/PpapService.php:59-86` creates only one PSW element for every submission, regardless of level.
- `api/app/Modules/Quality/Services/PpapService.php:103-115` permits submission when at least one element exists.
- `api/app/Modules/Quality/Services/PpapService.php:131-153` approves when all existing rows are accepted or not applicable; it does not require the level’s complete element set or evidence.
- `docs/PROCESS-FLOWS.md:1252-1256` says the PPAP workflow tracks 18 elements per submission.

**Impact:** A submission can be approved with the default PSW row alone. The enum/documentation count also needs an explicit product decision: whether PSW is included in the 18 tracked elements or is an additional element.

### M058-F004 — PPAP lifecycle and segregation of duties are mutable

**Classification:** Broken  
**Severity:** P1  
**Evidence:**

- `api/app/Modules/Quality/Models/PpapStatus.php:7-37` treats only rejected and expired as terminal; Approved is not terminal.
- `api/app/Modules/Quality/Services/PpapService.php:118-177` allows approval directly from Submitted or UnderReview, permits rejection whenever the status is not one of those two terminal statuses (including Approved), and updates elements without a parent-state guard, row lock, transaction, or reviewer/approver separation.
- `api/app/Modules/Quality/Controllers/PpapController.php:54-100` exposes those transitions under the same `quality.ppap.manage` permission and accepts element status/path/notes updates without an immutable-approved check.
- `api/app/Modules/Quality/Models/PpapElement.php:14-31` has no `HasAuditLog`, while the parent submission does.
- `api/app/Modules/Quality/routes.php:155-164` assigns create, update, submit, review, approve, reject, and element mutation to the same manage permission.
- `api/app/Modules/Quality/Support/InspectionStateMachine.php:12-41` shows the project’s explicit transition pattern elsewhere; no analogous PPAP transition contract is invoked by `PpapService`.

**Impact:** An approved record can be changed or rejected after approval, a submitter can potentially approve their own work, and child evidence changes are not independently auditable. The module does not use the explicit state-machine transition pattern used elsewhere in Quality.

### M058-F005 — Internal and supplier PPAP operator workflows are missing from the SPA

**Classification:** Missing  
**Severity:** P1  
**Evidence:**

- `spa/src/routes/qualityRoutes.tsx:19-75` registers traceability but no PPAP list/detail/create/review route.
- `spa/src/routes/portalRoutes.tsx:16-57` registers supplier dashboard, orders, invoices, deliveries, statements, and schedules, but no PPAP route.
- `api/app/Modules/B2B/routes.php:27-52` exposes only a supplier PPAP read/list endpoint; there is no supplier submission, evidence, or status-action workflow.
- `spa/src/api/quality/traceability.ts:3-93` contains only the traceability search client; no recall or shipment-lot client is present.
- `spa/src/pages/quality/traceability.tsx:24-105` implements only a search form and result tree.

**Impact:** QC users cannot operate the backend PPAP lifecycle from the internal product, suppliers cannot submit or maintain evidence in the portal, and traceability users cannot run the backend recall simulation or create a shipment-lot record through the shipped UI.

### M058-F006 — Evidence/document boundary is incomplete

**Classification:** Incomplete  
**Severity:** P1  
**Evidence:**

- `api/app/Modules/Quality/Controllers/PpapController.php:91-100` accepts a raw `document_path` string for element updates rather than a private upload/download contract.
- `api/app/Modules/Quality/Resources/PpapElementResource.php:12-22` returns `document_path` directly.
- `api/app/Modules/Quality/Resources/PpapSubmissionResource.php:12-48` exposes child element data when loaded without a protected evidence URL or access decision.
- `api/app/Modules/SupplyChain/Resources/ShipmentLotResource.php:14-55` also emits the raw CoC path.
- The submission and shipment-lot migrations use unconstrained path strings (`api/database/migrations/0240_create_ppap_tables.php:16-52`; `api/database/migrations/0150_add_batch_lot_traceability.php:36-52`).

**Impact:** Evidence storage, authorization, replacement/versioning, and download auditing are not represented as a complete boundary. A path stored by one caller can become a path returned to another caller without a documented private-file policy.

### M058-F007 — Recall simulation has unsafe/ambiguous result semantics

**Classification:** Broken  
**Severity:** P1  
**Evidence:**

- `api/app/Modules/Quality/Controllers/TraceabilityController.php:18-29` passes raw `term`/`lot` query values to the service with no request validation or bounds.
- `api/app/Modules/Quality/Services/TraceabilityService.php:35-111` performs first-match lookups, returns not-found when a known material lot has no downstream work order, merges JSON snapshots, has no result-size limit, and reports the entire shipment-lot quantity rather than an allocation tied to the affected batch/material.
- `api/app/Modules/Quality/Services/TraceabilityService.php:117-152` applies the same unbounded first-match pattern to search; `material_lot_number` is indexed but not unique in `api/database/migrations/0150_add_batch_lot_traceability.php:24-35`.

**Impact:** Recall results can be incomplete, ambiguous when identifiers are duplicated across records, or operationally too large. A known incoming lot can be reported as absent, and a displayed affected quantity can overstate the quantity tied to the recalled material.

### M058-F008 — PPAP source relationships, gate policy, and expiry execution are incomplete

**Classification:** Incomplete  
**Severity:** P2  
**Evidence:**

- `api/app/Modules/Quality/Requests/StorePpapRequest.php:35-50` silently converts invalid optional product/PO hash IDs to null; it does not verify vendor/item/product/PO relationships.
- `api/app/Modules/Quality/Controllers/PpapController.php:40-48` validates `product_id` as a raw integer and validates the update level only as a string, not through the same hash-ID/enum contract used by creation.
- `api/app/Modules/Quality/Services/PpapService.php:180-214` bulk-expires overdue submissions and treats an absent PPAP as acceptable in `vendorHasActivePpap`; the bulk update has no per-record audit trail.
- `api/database/seeders/SettingsSeeder.php:397` seeds `quality.ppap_gate_enabled` as false, and `api/app/Modules/Purchasing/Services/PurchaseOrderService.php:408-420` allows an unregistered vendor/item pair through when the gate is enabled because `vendorHasActivePpap` returns true when no PPAP exists.
- `api/routes/console.php:281-285` schedules calibration work but no PPAP-expiry command/schedule was found. The validity setting is seeded in `api/database/migrations/0295_seed_supplier_hr_crm_and_quality_sla_settings.php:27-34`.

**Impact:** PPAP records can lose intended source relationships, the gate does not enforce a clear fail-closed policy, and expiry depends on an explicit caller rather than a demonstrated scheduled job.

### M058-F009 — Traceability search form misses a documented visible-label polish requirement

**Classification:** Polish  
**Severity:** P3  
**Evidence:**

- `spa/src/pages/quality/traceability.tsx:50-62` uses a placeholder and `aria-label` but no visible label linked to the input.
- `docs/DESIGN-SYSTEM.md:405-408` specifies a label above inputs, and `docs/DESIGN-SYSTEM.md:523-531` requires linked form labels and consistent keyboard/focus/accessibility treatment.

**Impact:** The search is usable with assistive technology, but the visible form does not communicate the field name and expected identifier as consistently as the design system requires. This is secondary to the missing workflow and lineage findings.

## Discovery: what exists and what is absent

### Present

- Quality routes are behind Sanctum, the Quality feature flag, and per-route permissions (`api/app/Modules/Quality/routes.php:23-24,140-164`).
- PPAP permissions are seeded in the Quality permission bucket, and `qc_inspector` receives that bucket (`api/database/seeders/RolePermissionSeeder.php:316-340,655-670`).
- Work-order batch numbers and shipment-lot numbers have uniqueness constraints (`api/database/migrations/0150_add_batch_lot_traceability.php:24-52`).
- Stock movements and manual material issues now have lot fields (`api/app/Modules/Inventory/Services/StockMovementService.php:147-163`; `api/app/Modules/Inventory/Services/MaterialIssueService.php:109-147`).
- `PpapSubmission` has hash IDs, an audit-log trait, actor relationships, and status/date casts (`api/app/Modules/Quality/Models/PpapSubmission.php:22-58`).
- The supplier PPAP list is tenancy-scoped and its focused view test passes (`api/app/Modules/B2B/Services/SupplierPortalService.php:600-614`; `tests/Feature/B2B/SupplierPpapViewTest.php`).
- The delivery service contains a transaction/lock-based passed-inspection and output/Sales Order validation helper (`api/app/Modules/SupplyChain/Services/DeliveryService.php:317-368`), but the shipment-lot creation path does not use it.

### Absent or not demonstrated

- No authoritative material-issue-to-lot relation used by the normal work-order issue path.
- No shipment-lot allocation/relationship table or equivalent validated contract.
- No PPAP level-to-required-elements/evidence matrix.
- No immutable approved PPAP transition contract with maker-checker enforcement.
- No internal PPAP SPA surface, supplier PPAP submission/evidence surface, recall UI, or shipment-lot creation UI.
- No demonstrated scheduled PPAP expiry job.

## Verification and evidence limits

The following checks were run after the fresh source review:

- API route inventory succeeded: the Quality API exposes PPAP, traceability search/recall, and shipment-lot endpoints.
- `SupplierPpapViewTest`: 3 tests passed.
- `BatchLotSequenceTest`: 3 tests passed.
- `LotTraceabilityTest`: 4 tests failed before assertions because `GoodsReceiptNote::qcInspection` is missing in `api/app/Modules/Inventory/Services/GrnService.php:84,272`. This is a dependency/worktree issue outside M058 and was not changed.
- SPA typecheck is not green because of unrelated existing errors in asset QR-code and return-management files; no traceability-file error was reported.
- No focused tests were found for authoritative material lot stamping, shipment-lot contract binding, PPAP required-element completeness, approved-state immutability/segregation, protected evidence access, recall result semantics, or expiry scheduling.

These failures limit end-to-end verification; they do not reduce the severity of the code-level findings above.

## Audit decision

The fresh audit produced a new action plan. The majority of fixes are large and/or touch financial/quantity lineage, lifecycle transitions, permissions/evidence, or cross-module contracts. They are therefore `separate-recommended`. Under the session rules, this first-pass audit stops at `📋 Plan Ready`; no application source was modified in this session.

Open policy questions are recorded rather than guessed:

1. Is PSW one of the 18 required PPAP elements or an additional element?
2. Which PPAP levels require which evidence, and is an absent PPAP fail-closed when the gate is enabled?
3. What is the authoritative quantity/allocation rule when one work order or material lot contributes to multiple shipment lots?

---

# M058 — Traceability & PPAP re-audit — 2026-09-01

**Claim:** RECLAIMED (orphan lock from 2026-08-31T22:06Z, 153h stale).
**Mode:** re-audit of the 2026-08-25 `📋 Plan Ready` session (9 findings, only F009 implemented).

## Status: IN PROGRESS (skeleton committed before probing, per pipeline protocol)

- [ ] Environment verified, own test DB created
- [ ] Prior findings reproduced / disproved (F001–F009)
- [ ] HTTP-vs-service coverage split measured
- [ ] Trace chain built and attacked (link-by-link delete/archive)
- [ ] PPAP integrity invariants executed
- [ ] Immutability + pg_trigger census
- [ ] Scheduled commands executed
- [ ] State transition matrix walked
- [ ] Attachments / evidence probes
- [ ] Cross-tenant PPAP isolation
- [ ] Soft-delete divergence probes
- [ ] Permissions per endpoint incl. list/options
- [ ] Validation family (~7 values, money/qty)
- [ ] Dead surfaces both directions

## Baseline (real run, non-zero assertions)

`docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_ppap api php artisan test tests/Feature/Quality tests/Unit/BatchLotSequenceTest.php tests/Feature/B2B/SupplierPpapViewTest.php --no-coverage`
→ **135 tests / 388 assertions / exit 0**, duration 59s.

## HTTP-vs-service coverage split — measured

`grep -rn -E "quality/ppap|quality/traceability" api/tests/` returns **nothing**.
**0 of my 12 endpoints has ever been exercised over HTTP by any test.** Existing coverage
touches only adjacent surfaces: `tests/Feature/B2B/SupplierPpapViewTest.php` (the B2B
read endpoint, 4 tests), `tests/Feature/Inventory/LotTraceabilityTest.php` (service-level
lot movements), `tests/Unit/BatchLotSequenceTest.php` (number formats). Neither
`TraceabilityService`, `PpapService` nor `ShipmentLotService` has a single test of its own.

That gap is where every finding below came from.

## R1 — [Broken/P0] The forward trace has never worked: `whereJsonContains` compares an object against an array

`TraceabilityService` looks up the work orders that consumed a material lot with

```php
->whereJsonContains('material_lot_references', ['material_lot_number' => $lotNumber])
```

at **`api/app/Modules/Quality/Services/TraceabilityService.php:59`** (`simulateRecall`) and
**`:216`** (`traceFromMaterialLot`) — the module's only two forward hops.

`whereJsonContains` json-encodes an associative PHP array into a JSON **object**, and
`work_orders.material_lot_references` is a JSON **array of objects**. PostgreSQL
containment refuses that shape. Measured directly against PostgreSQL 16:

```
 obj_against_array | arr_against_array | scalar_against_array
-------------------+-------------------+----------------------
 f                 | t                 | t
```

(`'[{"material_lot_number":"L1","qty":1}]'::jsonb @> '{"material_lot_number":"L1"}'::jsonb` = **false**.)

Measured end to end on a fully intact chain (material lot → GRN → WO whose
`material_lot_references` explicitly names that lot → passed outgoing inspection →
shipment lot → delivered delivery → customer "Toyota-A"):

```
[T1-fwd] search(material lot)  found=true  type=material_lot  consuming_wos=0
[T1-fwd] does the material-lot trace reach a CUSTOMER?  NO — trace stops at the work order
[T1-fwd] recall(material lot)  found=false customers=0 deliveries=0 qty=0
[T1-bwd] search(shipment lot)  found=true  wos=1 materials=1 inspections=1 delivery=present customer=Toyota-A
```

**Impact.** The backward trace (customer shipment → lots) works. The forward trace
(this lot went to these customers) — the question an IATF auditor and a recall
coordinator actually ask — returns **`found: false`, zero customers, zero deliveries,
zero quantity** for a lot that was demonstrably consumed and shipped. `simulateRecall`
is not "incomplete": for a material lot it has never returned a single row in its life,
and because it answers `found: false` rather than erroring, a recall operator is told the
bad resin lot is unknown to the system. The same silent-zero shape as the 8D SLA ledger.

The correct form is `whereJsonContains($col, [['material_lot_number' => $lot]])`.

## R2 — [Broken/P1] A material lot that exists but has no downstream WO is reported as non-existent

`TraceabilityService.php:64` — `if ($woIds->isEmpty() && !$lot) return ['found' => false, ...]`.
Measured on a received-but-unconsumed lot (row present in `grn_items`):

```
[T5] search  found=true  type=material_lot
[T5] recall  found=false     <- the lot demonstrably EXISTS in grn_items
```

The recall endpoint cannot distinguish "this identifier is unknown" from "received, not
yet consumed" — the two answers demand opposite operator actions (quarantine the
warehouse stock vs. recall from customers). Reproduces prior F007 in part.

## R3 — [Broken/P1] `?term[]=` / `?lot[]=` array query parameter 500s both traceability endpoints

`TraceabilityController.php:20,27` casts the raw query value with `(string) $request->query(...)`.
There is no FormRequest and no validation on either route.

```
[T4] GET /api/v1/quality/traceability/search?term[a]=b            => 500
[T4] GET /api/v1/quality/traceability/recall-simulation?lot[a]=b  => 500
```

Benign inputs are handled (`''`, whitespace, 5000 chars, `' or 1=1 --`, `%`, `_` all → 200
`found:false`, so there is no injection), but any array-shaped parameter is an
unauthenticated-adjacent 500. Same class as the `goods-receiving` GRN 500: invisible
because no test posts to the route.

## R4 — [Broken/P1] Ambiguous identifiers resolve first-match and silently drop the rest

- `grn_items.material_lot_number` is indexed but **not unique**
  (`api/database/migrations/0150_add_batch_lot_traceability.php:33`). Two GRN lines sharing
  a lot number: `search` returned `supplier_lot_reference = 'SUP-D2'` only — one of two, no
  signal that a second exists.
- `batch_number` and `lot_number` occupy one identifier namespace with no prefix guard.
  With both set to `COLLIDE-1`, `TraceabilityService::search` (`:125-140`, ordered
  batch → lot → material lot) resolved `type=batch`; **the shipment-lot leg became
  unreachable** for that identifier.

## R5 — [Broken/P1] A partial trace is indistinguishable from a complete one

`work_orders` ids live in an unconstrained JSON column (`0150_...:43`) with no FK, so a
deleted work order leaves a dangling id that `whereIn('id', $woIds)` simply skips.

```
[T3] lot claims quantity 800 from 1 work order(s)  (was 2)
[T3] work_order_ids json still = [3,4]
[T3] payload keys = lot,backward,forward   <- no partial flag, no warning
[T3] found still = true — one batch vanished with no signal
```

The lot still asserts 800 units while accounting for one batch. Nothing in the payload
lets a caller detect the loss.

## R6 — [Broken/P0] Zero triggers; every trace identifier is rewritable and a confirmed lot is mutable

`pg_trigger` (non-internal) across `shipment_lots, work_orders, grn_items, deliveries,
inspections, ppap_submissions, ppap_elements` = **0**. The whole database has 2, both on
`audit_logs`. Measured:

```
[T8] raw SQL rewrote lot_number          -> HACKED-LOT
[T8] raw SQL rewrote batch_number        -> HACKED-BATCH
[T8] raw SQL rewrote material_lot_number -> HACKED-MAT
[T8] raw SQL emptied work_order_ids, qty=999999; trace backward wos = 0
[T8] Eloquent update on a lot whose delivery is CONFIRMED => true, qty 500 -> 1
```

The last line is the material one: **a shipment lot stays fully mutable after the customer
has confirmed the delivery.** `ShipmentLot` carries `HasAuditLog` (3 rows recorded), so the
change is logged — but nothing refuses it. Same shape found in `ncr-capa` (closed NCR) and
`inspections-certificates` (completed inspection).

## R7 — [Broken/P0] Shipment-lot creation has 500'd on every request for its entire life

`api/app/Modules/SupplyChain/Services/ShipmentLotService.php:40-42`:

```php
->map(fn (string $hashId) => WorkOrder::query()->findOrFail(
    (new WorkOrder())->decodeHashId($hashId)
))
```

**`decodeHashId()` does not exist.** `App\Common\Traits\HasHashId` exposes
`decodeHash()` and `tryDecodeHash()` (both static, `HasHashId.php:81,92`). No model in
the repository defines `decodeHashId` — the only occurrences are a private helper inside
`Inventory/Services/BarcodeScanResolverService.php:303` and a controller-local method in
`Accounting/Controllers/BudgetController.php:59`.

Measured over HTTP, 16 POSTs across 16 different payload shapes:

```
[T6] POST /api/v1/quality/traceability/deliveries/{d}/shipment-lot => 500
     "Call to undefined method App\Modules\Production\Models\WorkOrder::decodeHashId()"
     BadMethodCallException
```

**Every** payload that clears validation reaches the fault. `POST` with one valid
started work order: 500. Second post: 500. Unstarted WO: 500. Duplicate ids: 500.
Over-allocation: 500. Foreign product: 500.

**Impact.** `POST .../shipment-lot` is the **only** write path that binds production
batches to a customer delivery — the hop that makes "which customer got this batch"
answerable at all. It has never succeeded. This is why `shipment_lots` has **0 rows** in
the running dev database. The backward trace measured healthy in R1 only because the
probe inserted the lot row directly; through the product's own API that row cannot exist.

Same shape as `goods-receiving`'s `POST /api/v1/inventory/grn`: a route that 500s
unconditionally, invisible because no test posts to it.

**`ShipmentLotService` lives in `api/app/Modules/SupplyChain/`, which is LIVE under
another agent. Reported, not fixed.** The one-line correction is
`WorkOrder::tryDecodeHash($hashId)`, but it needs the surrounding contract work in
item 2 of the action plan, and it is not mine to land.

## R8 — [Broken/P0] 9 of 11 trace links SILENTLY ERASE; not one refuses on the trace's behalf

Built a real chain per link — material lot (`grn_items`) → GRN → work order (batch +
`material_lot_references`) → passed outgoing inspection → shipment lot → delivered
delivery → customer — then attacked each link and re-read the trace over HTTP. Each raw
statement ran inside its own SAVEPOINT so a refusal did not abort the outer transaction.

| link attacked | result | verdict |
|---|---|---|
| `customers` hard-delete (has a sales order) | `23503` FK violation from **`sales_orders_customer_id_foreign`** | REFUSES — but by an unrelated table, not by the trace |
| `customers` hard-delete (**no** sales order) | DELETED. lot survives, `customer_id` → NULL, trace `forward.customer` = **NULL** | **SILENTLY ERASES** |
| `deliveries` hard-delete | `23503` from `shipment_lots_delivery_id_foreign` (`restrictOnDelete`) | REFUSES |
| `deliveries` **archive** (`SoftDeletes`) | trace `found=true`, `delivery=NULL`, customer still present; recall: `found=true`, deliveries=**0**, customers=1, qty=500 | **SILENTLY ERASES** |
| `work_orders` hard-delete | DELETED. `found=true`, backward work_orders 1→0, JSON still lists `[4]`, recall still claims qty=500 | **SILENTLY ERASES** |
| `grn_items` hard-delete | DELETED. `search(material lot)` → `found=false`; the WO's JSON still names the vanished lot | **SILENTLY ERASES** |
| `goods_receipt_notes` hard-delete | DELETED (cascades its items). `search(material lot)` → `found=false` | **SILENTLY ERASES** |
| `inspections` hard-delete | DELETED. inspections in trace 1→0, `found=true` | **SILENTLY ERASES** (QC evidence) |
| `shipment_lots` hard-delete | DELETED. `search(lot)` → `found=false`; batch trace `forward.lots` = 0 | **SILENTLY ERASES** |
| `products` **archive** (soft) | `found=true` but `lot.product=NULL` **and** `wo.product=NULL` | **SILENTLY ERASES** (which part it was) |
| `items` **archive** (soft) | `found=true` but `item_code=NULL`, `item_id=NULL` | **SILENTLY ERASES** (material identity) |

**Two links refuse. Nine erase.** And neither refusal is the trace defending itself:
the customer refusal comes from `sales_orders`, the delivery refusal from the
`shipment_lots` FK. Remove the sales order and the customer erases.

The two `SoftDeletes` rows are the worst class because no DBA is required — they are the
product's own archive button. Archiving a **delivery** leaves a recall that reports
"500 units affected, 1 customer, **0 deliveries**": you learn who has the bad parts but
not which shipment to intercept. Archiving a **product** leaves a trace that resolves
but can no longer say what part it describes.

This is `material-review-board`'s R8 confirmed and generalised: its finding was one
`nullOnDelete` on `material_review_records.ncr_id`; the same pattern runs through
`shipment_lots.customer_id`, `shipment_lots.product_id` (`0150_...:40-41`) and every
unconstrained JSON id list.

## R9 — [Broken/P0] A level-3 PPAP is approvable with one element and zero evidence, by its own author, with no review

Measured over HTTP:

```
[P2] elements auto-created at level 3 = 1        (part_submission_warrant)
[P2] approve with 1 element, no evidence => 200
[P2] final status = approved
[P2] element document_path = NULL

[P3] submitted_by=3  reviewed_by=NULL  approved_by=3
[P3] SAME ACTOR ALL THREE: YES
[P3] approved straight from Submitted (no review): YES
```

Three independent defects compound:

1. **No level→element matrix.** `PpapService::create()` (`:79-83`) inserts exactly one
   PSW row regardless of level. `PpapLevel` (`Enums/PpapLevel.php:19-24`) *describes*
   level 3 as "PSW + full supporting data" but supplies no required set.
   `docs/PROCESS-FLOWS.md:1252-1256` promises 18 tracked elements.
2. **There is no route to add an element.** Measured route inventory — the complete PPAP
   surface is 9 internal routes plus 1 B2B read route, and the only element route is
   `PATCH /ppap/{ppap}/elements/{element}` on a row that must already exist.
   **0 create-element routes, 0 upload routes, 0 download routes, 0 delete-element routes.**
   So the other 18 element types are not merely unenforced, they are **unreachable through
   the API**. Approving a level-3 PPAP on a single evidence-free PSW row is not an edge
   case; it is the only outcome the product can produce.
3. **No segregation of duties.** `approve()` (`:135-154`) never compares `$by->id` with
   `submitted_by`, and accepts `Submitted` directly, so `reviewed_by` stays NULL.
   `qc_inspector` receives the whole `quality` permission bucket
   (`RolePermissionSeeder.php:682-698` → `$this->module('quality')`), which contains both
   `quality.ppap.view` and `quality.ppap.manage` (`:348-349`), and `routes.php:170-178`
   gates create, submit, review, approve, reject and element mutation on that **one**
   permission. One `qc_inspector` creates, submits and approves unaided.

Same self-approval shape `ncr-capa` measured (one inspector creating, dispositioning,
closing and self-verifying an NCR) and `material-review-board` measured (one actor
inspecting, raising, holding, dispositioning).

## R10 — [Broken/P1] An approved PPAP is fully mutable, rejectable, and hard-deletable; element writes are unaudited

```
[P4a] PUT approved parent          => 422  (guarded by PpapService::update():91) — OK
[P4b] PATCH element on APPROVED    => 200  document_path 'ppap/original-psw.pdf'
                                          -> 'ppap/SWAPPED-EVIDENCE.pdf', status -> rejected
      parent still reads           => approved
[P4c] PATCH reject on APPROVED     => 200  status -> rejected
[P4d] raw SQL rewrote ppap_number  -> HACKED
[P4e] raw DELETE approved row      => 1 row, still_exists=false, orphan_elements=0
[P4f] Eloquent delete()            => true, SoftDeletes=false
[P4g] pg_trigger on ppap tables    => 0
[P4h] audit_logs PpapElement=0     PpapSubmission=9
```

- **The evidence is swappable after approval.** `PpapService::updateElement()`
  (`:170-178`) has no parent-state guard at all. The approved PSW document can be replaced
  and its status flipped to `rejected` while the submission continues to read `approved`.
  This is the exact defect PPAP exists to prevent: the approved package no longer matches
  what was approved.
- **Approved is not terminal.** `PpapStatus::isTerminal()` (`Enums/PpapStatus.php:28-31`)
  returns true only for `Rejected` and `Expired`, and `reject()` (`:158`) guards on
  `isTerminal()` — so an approved submission can be rejected afterwards. Confirmed in the
  transition matrix (R13).
- **`PpapElement` has no `HasAuditLog`** (`Models/PpapElement.php:15-17`) while its parent
  does. Zero audit rows for every evidence change. The one child table whose history an
  IATF auditor would demand is the one with no history.
- `PpapSubmission` has **no `SoftDeletes`**, 0 triggers, and cascades its elements away.

For contrast, `audit_logs` in this same database **is** trigger-protected: my probe's
`DELETE FROM audit_logs` was refused with `SQLSTATE[P0001] Audit logs are immutable`
(`prevent_audit_log_modification`). The `journal-ledger` precedent exists here and works
— it simply was never applied to PPAP or to any trace table.

## R11 — [Broken/P1] Nothing prevents conflicting approved PPAPs for one part, and nothing supersedes

```
[P5] concurrently-approved submissions for one vendor+item = 2
[P5] approved levels for the SAME part = [3,3,1]
```

Three simultaneously-`approved`, non-expired submissions for one vendor+item, at two
different levels. `approve()` performs no check for an existing active approval;
`revision` (`0240_create_ppap_tables.php:34`, default 1) is never incremented by any code
path — `grep` finds no writer. `vendorHasActivePpap()` (`:198-214`) answers a boolean, so
the PO gate is satisfied by whichever row happens to be approved and cannot tell that two
contradict each other. There is no supersession concept.

## R12 — [Broken/P1] Nothing ages an expiring PPAP, and an expired one presents as current

```
[P6] approved_at=2026-09-01  expires_at=2029-09-01   (3y, from quality.ppap.approval_validity_years)
[P6] after forcing expires_at=2020-01-01:
       vendorHasActivePpap()                        = false   <- read-time scope is correct
       status column still says                     = approved
       GET /ppap?status=approved returns expired row = YES
       its status_label in the payload              = "Approved"
[Q2] expireOverdue() moved 1 row; NEW audit_logs rows = 0
```

- **`PpapService::expireOverdue()` (`:181-188`) has zero callers.** `grep -rn 'expireOverdue' app/ database/ routes/` returns only its own definition. `routes/console.php`
  schedules **47** commands; none touches PPAP or traceability, and there is no
  `app/Console/Commands` file mentioning either. This is `material-review-board`'s
  *missing*-command failure mode rather than the 8D ledger's *lying*-command mode: there
  is no command to lie.
- Because the status column never flips, every list, filter and report keyed on
  `status = 'approved'` presents an expired approval as current, labelled "Approved".
  `scopeActiveApproved` (`Models/PpapSubmission.php:54-58`) is expiry-aware, so the PO
  gate is right and the UI is wrong — the worst split, because the screen an auditor reads
  disagrees with the control that enforces.
- When finally called, the bulk `->update()` writes **0 audit rows** (an Eloquent builder
  mass update fires no model events, so `HasAuditLog` sees nothing). A status change
  nobody can attribute.

## R13 — [Incomplete/P2] Full transition matrix: 30 of 30 cells walked, 3 unsound

6 states × 5 actions, each on a fresh submission with all elements pre-accepted so the
matrix measures the **state** guard rather than the element guard. HTTP status; `->x` = the
row actually moved.

```
FROM          submit          review            approve         reject          update
draft         200->submitted   422              422             200->rejected   200
submitted     422              200->under_review 200->approved   200->rejected   200
under_review  422              200               200->approved   200->rejected   200
approved      422              422               422             200->rejected   422
rejected      422              422               422             422             422
approved/expired rows are otherwise sealed; expired: 422 across all five
```

27 cells sound. Three are not:

1. **`approved` + `reject` → 200 → rejected** (R10). An approved production-part
   approval can be reversed after the fact.
2. **`submitted` + `approve` → 200 → approved**, skipping review entirely, leaving
   `reviewed_by` NULL (R9).
3. **`draft` + `reject` → 200 → rejected.** A submission is rejectable before anyone has
   submitted it, producing a `rejection_reason` and a `reviewed_by` for a package no
   reviewer ever saw. Flagged as a **question** — plausibly an intentional
   "abandon a draft" affordance, but it is spelled as a review verdict.

Also noted: `under_review` + `review` → 200 overwrites `reviewed_by`/`reviewed_at`, so a
second actor can silently replace the recorded reviewer.

## R14 — [Broken/P1] The PPAP update route accepts raw integer PKs and rejects HashIDs; three inputs 500

`PpapController::update()` (`:42-46`) validates `'product_id' => ['sometimes','nullable','integer']`
and `'ppap_level' => ['sometimes','string']`, while `StorePpapRequest` (`:27,29`) uses
`'string'` + `Rule::in(PpapLevel::values())` and decodes through `Product::tryDecodeHash`.
The two halves of one resource disagree. Measured:

```
[Q1] PUT {"product_id":1}            => 200   stored product_id=1     <- raw PK ACCEPTED
[Q1] PUT {"product_id":"dGypLxpvAg"} => 422                           <- the real HashID REFUSED
[Q1] PUT {"product_id":987654}       => 500                           <- FK violation to the client
[Q1] PUT {"ppap_level":"banana"}     => 500 (txn aborted)
[Q1] PUT {"ppap_level":"7"}          => 500 (txn aborted)
[Q1] PUT {"ppap_level":"1e0"}        => 500 (txn aborted)
```

The update route is the inverse of the security rule in `CLAUDE.md`: it takes the integer
PK and refuses the HashID, so a caller can re-point an approved-adjacent PPAP at any
product by guessing a small integer. `ppap_level` is unvalidated on update, so any string
reaches `varchar(1)` / the `2026_08_13_220000` CHECK constraint and 500s.

**And the create route has the same 1e-notation defect as eight sibling modules, in a new
shape:**

```
[P9] valid                   => 201
[P9] garbage hash product_id => 201  product_id silently NULL
[P9] raw integer product_id  => 201  product_id silently NULL
[P9] garbage purchase_order  => 201  purchase_order_id silently NULL
[P9] array product_id        => 422
[P9] ppap_level '9'          => 422
[P9] ppap_level '1e0'        => 500   <- Rule::in uses loose comparison: '1e0' == '1'
[P9] ppap_level 1.0 (float)  => 201   stored as '1'  <- silently became Level 1
```

`Rule::in` compares loosely, so the numeric strings `'1e0'` and the float `1.0` both
satisfy `in:1,2,3,4,5`. `1.0` is silently coerced to Level 1; `'1e0'` survives validation
and overflows `ppap_level varchar(1)` → 500. Note this is *not* the `numeric|min:0` money
family — it is `Rule::in` against a numeric-valued enum, which has the same root cause.

Silently nulling three invalid optional foreign keys reproduces prior F008.

**The one genuine quantity FormRequest in this module is CLEAN.**
`ShipmentLotController::createForDelivery` (`:41`) uses `['nullable','integer','min:1']`,
the correct shape (like `material-review-board`'s `decimal:0,3`), and refused all six
hostile values with 422: `1.999`, `'1e3'`, `'1e17'`, `-1`, `0`, `{"a":1}` map. Only
`99999999999` reached the service — and there it hit R7's 500, so whether it would
overflow `unsignedInteger` is **unmeasured**.

## R15 — [Missing/P1] Dead surfaces in both directions

- **PPAP has no SPA client whatsoever.** `grep -rn -il 'ppap' spa/src/` returns
  **nothing** — no type, no api module, no page, no route, no nav entry — against 9
  internal routes and 1 supplier route. Confirms `supplier-portal`'s R010; this is the
  ninth-plus module with the pattern.
- **`GET /quality/traceability/recall-simulation` has no client.** `grep -rn 'recall' spa/src/`
  returns nothing. The recall simulation — an IATF capability, and the endpoint R1 proves
  has never worked — is unreachable from the product.
- **`spa/src/api/supply-chain/shipmentLots.ts` is an orphan.** Fully typed
  (`showForDelivery`, `createForDelivery`, `show`) and **nothing imports it**; its
  `createForDelivery` targets the route that R7 proves 500s unconditionally.
- **`docs/USER-MANUAL.md` has 0 mentions** of ppap / traceability / recall (same as
  `material-review-board`). `docs/PROCESS-FLOWS.md` has 4, including the 18-element
  promise at `:1252-1256` that R9 shows the API cannot fulfil.

## R16 — [Incomplete/P2] Evidence is an unvalidated free-text pointer, not a document boundary

`ppap_elements.document_path` is `varchar(500)` (`0240_...:47`) written from
`['sometimes','nullable','string','max:500']` (`PpapController.php:96`) and echoed back
verbatim by `PpapElementResource.php:20`. Measured:

```
[Q3] upload routes 0 | download/serve routes 0 | create-element 0 | delete-element 0
[Q3] PATCH document_path '../../../../etc/passwd' => 200
[Q3] read back from GET /ppap/{id} = '../../../../etc/passwd'
```

No file is ever written, so the usual attachment invariants (server-side MIME from real
bytes, random filename, storage outside the web root, permission-checked serve,
Content-Disposition, over-length filename) have **no surface to test** — I could not test
them because they do not exist. The traversal string is not currently exploitable for that
same reason; it becomes exploitable the moment anyone builds a download route that
concatenates this field. Reproduces prior F006.

`B2B` handled its half correctly: `SupplierPpapElementResource.php:108` replaces
`document_path` with `has_document`, and its docblock says why. Only the internal resource
leaks the path.

## R17 — [Incomplete/P2] Soft-deleted parents leave an approved PPAP with no attribution; a force-delete erases it

```
[P10] gate before archive               = true
[P10] vendor soft-deleted → gate        = true   | list still returns row | show vendor = null
[P10] item soft-deleted   → gate        = true   | show item   = null
[P10] vendor FORCE-deleted: submissions 1 -> 0   *** APPROVED PPAP SILENTLY ERASED ***
```

`ppap_submissions.vendor_id` and `.item_id` are `cascadeOnDelete()`
(`0240_...:19-20`). `Vendor` and `Item` both use `SoftDeletes`, so the cascade normally
lies dormant — but `forceDelete()` destroys the approved PPAP and its elements with no
refusal and no trace. An approved production-part approval is the single artefact an IATF
auditor asks for first.

Meanwhile the soft-delete path leaves the submission listed and `status: approved` while
`vendor` and `item` resolve to `null` — an active approval that no longer says who
supplies what.

## R18 — [Polish] Cross-tenant PPAP: no leak found

```
[Q4] B2B PPAP routes = GET|HEAD api/v1/b2b/supplier/ppap-submissions   (list only)
[Q4] internal /quality/ppap unreachable from a portal guard
[Q4] internal qc_inspector sees submissions across all vendors = 2  (by design)
```

`SupplierPortalService::ppapSubmissions()` (`:743-757`) scopes on an explicitly passed
`vendor_id` and never reads the auth guard. There is **no per-record show route**, so
there is no id-guessing surface for supplier A to reach supplier B's submission or its
documents. `supplier-portal` already verified the tenancy leg and
`SupplierPpapViewTest` (4 tests) covers it. Nothing to add.

## Guest access — my own earlier reading DISPROVED

An intermediate probe appeared to show a guest receiving 200 on `GET /ppap`,
`GET /ppap/{id}`, `PATCH .../elements/{element}` and both traceability routes. **That was
a probe artifact, not a defect**: `actingAs()` persists for the remainder of a test
method, so `$this->json()` in the same method was still the last authenticated user.
Re-measured in an isolated test:

```
[T7] guest GET /quality/traceability/search          => 401
[T7] guest GET /quality/traceability/recall-simulation => 401
[T7] guest GET /quality/ppap                          => 401
```

Recording it because a false auth-bypass claim would have been the most damaging thing in
this report.

## Permission gate — verified per endpoint including list

11 endpoints × 3 roles. `employee` → **403 on all 11**, including the list and both
traceability reads. `qc_inspector` and `system_admin` clear the gate on all 11 (subsequent
422s are business-rule refusals on already-transitioned fixtures, not permission
failures). No endpoint is gated on a permission no seeded role holds — the failure mode
found in four sibling modules is absent here. The problem is the opposite: **one
permission covers six mutating verbs** (R9.3).

Denied bodies carry no raw integer PK (`[P8] body contains the raw pk 42: no`); the 403
body is a generic message plus a framework stack trace (`APP_DEBUG` artifact of the test
env, not a finding).

## Sequence rows and restore routes

- `document_sequences` has **no `ppap` or `shipment_lot` row** in the dev database — but
  that is correct, not the gap CLAUDE.md's warning describes: `DocumentSequenceService::generate()`
  (`:71-85`) lock-or-**creates** the row on first use, and both types are present in the
  `documents.sequence_config` setting (`0360_seed_document_sequence_config.php:22-23`).
  `BatchLotSequenceTest` (3 tests) proves both formats. Numbering works; the rows are
  absent only because nothing has ever generated one — itself a symptom of R7.
- **No restore route exists in this module** (2 exist elsewhere in Quality), and
  `PpapSubmission` / `ShipmentLot` have no `SoftDeletes`, so the missing-`withTrashed()`
  defect found in six modules cannot occur here. Whether PPAP archival *should* be
  recoverable is an open human question (see below).

## Prior-session assessment

The 2026-08-25 session's log is **accurate about what it did**: it explicitly deferred
F001–F008 and implemented only F009. F009's fix is present and committed — the visible
label is live at `spa/src/pages/quality/traceability.tsx:53`.

Of its 9 findings, **8 reproduce** (F001 not re-tested from my side: it is Production/
Inventory-owned and both directories are live). F007 reproduces and is *understated* —
the prior report described "unsafe/ambiguous result semantics"; the measured truth (R1) is
that the forward leg has never returned a row. F003 also understated: not "completeness is
not enforced by level" but "the API cannot add an element at all" (R9.2).

Two prior claims are now **stale and should not be inherited**:
- "`LotTraceabilityTest`: 4 tests failed because `GoodsReceiptNote::qcInspection` is
  missing." Measured today: `LotTraceabilityTest` **passes**. The GRN service now uses
  `qc_inspection_id`.
- "`SupplierPpapViewTest`: 3 tests." It is **4** — `supplier-portal` added the
  document-path leak test.

## Invariants I could NOT verify, plainly

- **F001 (authoritative material-issue lineage).** `WorkOrderService` and
  `MaterialIssueService` are in Production/Inventory; Inventory is live under another
  agent. Read only.
- **`unsignedInteger` overflow on shipment-lot quantity** — masked by R7's 500.
- **Attachment invariants** (MIME from real bytes, random filename, outside web root,
  permission-checked serve, path traversal on serve, over-length filename, survives parent
  archive) — **no upload or download surface exists** for PPAP evidence. Nothing to probe.
- **Two-connection race on PPAP approval.** Not attempted: `RefreshDatabase` hides
  uncommitted rows from a second connection, so the result would be an artifact. Four
  prior sessions correctly abandoned theirs.
- **`GoldenPathDemoSeeder` silent skips** — measured by code reading, not yet by
  execution; see the open question below.

## Open questions for a human (not guessed)

1. **Is PSW one of the 18 AIAG elements or a 19th?** `PpapElementType` has **19 cases**
   and its own docblock says "18 standard + PSW", while AIAG's element 18 *is* the PSW.
   The enum also splits `MaterialTest` ("Material / Performance Test Results") from
   `PerformanceTest` ("Performance Test Results"), which AIAG combines. The count cannot
   be reconciled without a decision. (Carried over from the prior session, still open.)
2. **Which levels require which elements, and when is Not Applicable legitimate?**
   Required before R9 can be closed.
3. **Is an absent PPAP fail-open or fail-closed when `quality.ppap_gate_enabled` is on?**
   `vendorHasActivePpap()` returns `true` when no PPAP was ever registered — documented in
   its own docblock as deliberate ("you can't gate a part that was never put under PPAP
   control"), and `SettingsSeeder.php:397` seeds the gate off. That is a defensible policy,
   but it means enabling the gate does not gate an unregistered vendor/item pair. Confirm
   the intent.
4. **May a producer approve its own PPAP?** Answer is currently *yes* (R9). IATF says no.
   Splitting `quality.ppap.manage` into submit/review/approve changes the role matrix, so
   it is a policy call, not a fix.
5. **Should `approved` be terminal, and what is the revision/supersession rule?**
   (R10, R11.) `revision` exists in the schema and is never written.
6. **Is `draft` + `reject` intentional** as an "abandon draft" affordance? (R13.)
7. **Is PPAP archival recoverable or permanent?** No `SoftDeletes` today; a force-deleted
   vendor destroys approved submissions (R17).
8. **What is the allocation rule when one work order or material lot feeds several
   shipment lots?** Blocks any honest affected-quantity number in recall.
   (Carried over, still open.)

## R19 — [Broken/P1] A green full seed produces ZERO trace inputs, and the seeder reports success

The coordinator flagged `GoldenPathDemoSeeder` printing zero counts and exiting green.
**Measured** with `migrate:fresh --seed --force` (needs `php -d memory_limit=1G`) on a
throwaway database, exit **0**:

```
[Delivery Items] No deliveries or sales order items, skipping.
...
Database\Seeders\GoldenPathDemoSeeder ............ RUNNING
  Batch numbers already present.
  No batch WO; skipping hero trace.
  Created 0 shipment lots.
  Created 0 delivery proofs.
Golden-path demo seed complete.
EXIT=0
```

Row counts in that fully seeded database:

| table | rows |
|---|---|
| `work_orders` | **0** |
| `work_orders` with a `batch_number` | **0** |
| `deliveries` | **0** |
| `sales_orders` | **0** |
| `sales_order_items` | **0** |
| `shipment_lots` | **0** |
| `grn_items` with a `material_lot_number` | **0** |
| `ppap_submissions` | **0** |
| `inspections` | 3 |

**Every input to the trace is empty after a green seed.** Chain 1 (Order to Cash) has no
sales orders, no work orders and no deliveries at all, so there is nothing to trace and
nothing to demo.

The two adjacent log lines are the diagnostic tell, and they contradict each other:

1. `seedBatchNumbers()` (`database/seeders/GoldenPathDemoSeeder.php`) does
   `WorkOrder::whereNull('batch_number')->get()`; if empty it prints **"Batch numbers
   already present."** That message is true when every WO already has a batch and
   **false when there are no work orders at all** — the two cases are indistinguishable,
   and it is the second one here.
2. `hardenHeroTrace()` then does `WorkOrder::whereNotNull('batch_number')->first()`, gets
   null, and warns **"No batch WO; skipping hero trace."** — immediately contradicting the
   line above it.
3. `seedShipmentLots()` iterates `Delivery::orderBy('id')->get()`, which is empty, so
   "Created 0 shipment lots" means *zero deliveries existed*. Note it would also have
   produced a trace to nowhere if it had run: `$woIds = WorkOrder::pluck('id')` is empty,
   so `array_slice($woIds, 0, 2)` is `[]` and each lot would be written with
   `work_order_ids = []`.

Exactly the "nothing to do" vs. "everything was skipped" conflation the 8D SLA ledger
taught, in a seeder rather than a scheduled command: a zero count plus exit 0 is
indistinguishable from healthy.

**`database/seeders/` is not my module** — reported, not fixed. The upstream cause is that
no seeder creates work orders, sales orders or deliveries; `GoldenPathDemoSeeder` is only
where it becomes visible. This also explains why the running dev database has 0 rows in
every trace table, and it means **no trace or PPAP screen has ever been seen with data**.

Findings end here for the 2026-09-01 session.
