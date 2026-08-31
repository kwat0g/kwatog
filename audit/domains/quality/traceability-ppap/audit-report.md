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

Findings continue below.
