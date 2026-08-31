# M054 — material-review-board audit report

Audit date: 2026-08-25  
Status: 🔁 Needs Re-audit  
Claim: quality / material-review-board  
Scope: MRB hold/quarantine creation, stock movement isolation, quality/NCR linkage, disposition release, supplier return handoff, permissions, lifecycle state, and the related Inventory SPA/API surfaces.

## Production readiness

Production audit: 58/100, risky, because held inventory can bypass the MRB release path and the `rework`/`use_as_is` release path can return material to good stock without a second Quality or concession-evidence gate.

Blockers:

- Enforce same-warehouse, active, zone-correct location validation for every hold/release movement.
- Make quarantine stock exclusive to the MRB release workflow rather than generic transfers or adjustments.
- Define and enforce the Quality gate for rework/use-as-is, including concession evidence where required.
- Complete supplier-return provenance and purchasing/accounting handoff before marking an MRB returned.

## Decision

This resumed plan received contained fixes for F001, F005, F006, F007, and F008. F002, F003, F004, and F009 remain open because they require cross-module policy or human decisions that cannot be safely inferred inside this module. The module is released as 🔁 Needs Re-audit; see the execution record in `action-plan.md` and `fix-log.md`.

The finding evidence below is the baseline audit evidence. It is intentionally not presented as a fresh full audit after the targeted fixes; the next session should re-check the changed findings and the deferred decisions.

## Findings

### M054-F001 — Explicit location IDs can violate quarantine, warehouse, and active-location invariants

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- The request validates only that source/quarantine/target location IDs exist, with no active, zone, warehouse, or source-stock relationship checks at `api/app/Modules/Inventory/Requests/StoreMrbRequest.php:40-46` and `api/app/Modules/Inventory/Requests/ReleaseMrbRequest.php:32-39`.
- `hold()` uses the safe same-warehouse active-zone resolver only when the quarantine location is omitted. An explicit ID is accepted as-is at `api/app/Modules/Inventory/Services/QuarantineService.php:96-104`; the resolver's stricter rules are at `:291-309`.
- Rework/use-as-is release rejects only quarantine and scrap target zones; it does not require an active target or the same warehouse as the held quarantine location at `QuarantineService.php:203-218`.
- The warehouse tree returns all locations at `api/app/Modules/Inventory/Services/WarehouseService.php:22-29`. The hold form filters explicit quarantine choices by zone type but not `is_active` at `spa/src/pages/inventory/mrb/index.tsx:239-253`, and the release form filters only quarantine—not inactive locations or warehouse identity—at `spa/src/pages/inventory/mrb/detail.tsx:226-239`.

Impact: a caller can hold stock into an ordinary, inactive, or cross-warehouse location, and can release held stock into an inactive or different warehouse. The MRB row then claims quarantine/release semantics that the physical stock ledger does not honor.

### M054-F002 — Held stock can bypass MRB release through generic transfers and adjustments

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- The stock movement guard blocks quarantine/scrap sources only for `MaterialIssue` and `Delivery`; `Transfer` and adjustment paths are deliberately allowed through `api/app/Modules/Inventory/Services/StockMovementService.php:238-270,272-293`.
- Transfer orders execute a generic transfer without checking for a held MRB at `api/app/Modules/Inventory/Services/TransferOrderService.php:57-77`; their create/execute routes are available under `inventory.adjust` at `api/app/Modules/Inventory/routes.php:122-127`.
- Stock adjustments also pass `AdjustmentOut`/`AdjustmentIn` movements without an MRB ownership check at `api/app/Modules/Inventory/Services/StockAdjustmentService.php:231-243`.
- The warehouse role has `inventory.adjust` as well as `inventory.mrb.manage` at `api/database/seeders/RolePermissionSeeder.php:610-621`.

Impact: held stock can be transferred back to good inventory, adjusted out, or otherwise changed without a release movement. The MRB remains `held` with a stale quantity and later release can fail, double-account, or act on a different physical balance. Issue/picking guards are not sufficient ownership controls for the full inventory mutation surface.

### M054-F003 — Rework and use-as-is release has no reinspection or concession-evidence gate

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- The Quality disposition contract describes `rework` as repair to specification and `use_as_is` as a concession requiring customer sign-off at `api/app/Modules/Quality/Enums/NcrDisposition.php:8-13`.
- M054 handles both dispositions identically: it only requires a target location, rejects quarantine/scrap zones, transfers the held quantity to good stock, and marks the MRB released at `api/app/Modules/Inventory/Services/QuarantineService.php:197-221`.
- The release request accepts only disposition, target location, and optional free-text notes at `api/app/Modules/Inventory/Requests/ReleaseMrbRequest.php:32-39`; there is no reinspection ID/result, concession approval, sign-off actor, or supporting evidence field.
- The NCR service's separate rework/replacement behavior is reached from NCR closure at `api/app/Modules/Quality/Services/NcrService.php:247-331`, but M054 release does not require or invoke that Quality lifecycle.

Impact: nonconforming material can re-enter issuable good stock on a warehouse/QC release command without proof that rework passed or that a use-as-is concession was authorized. The status `released` overstates the evidence captured by the module.

### M054-F004 — Return-to-supplier marks inventory returned without supplier or procurement provenance

Classification: Incomplete  
Priority: P1  
Session: separate-recommended

Evidence:

- The MRB schema records item, locations, optional NCR/inspection, quantity, movements, users, and notes, but no vendor, PO, GRN, bill, lot, or supplier-return document fields at `api/database/migrations/0262_create_material_review_records_table.php:20-56`.
- The `return_to_supplier` branch posts only a generic `ReturnToVendor` stock movement and changes the MRB status to `returned` at `api/app/Modules/Inventory/Services/QuarantineService.php:240-267`.
- The movement's accounting mapping is a generic inventory/GRNI posting at `api/app/Modules/Inventory/Services/MovementGlPostingService.php:245-272`; M054 does not create or link a supplier dispatch, GRN reversal, bill credit, or purchasing notification.
- The documented failed-incoming-QC flow says supplier return requires a new PO/restart at `docs/PROCESS-FLOWS.md:1409-1417`, while the M054 release request has no supplier/source fields.

Impact: the inventory ledger can say material was returned while Purchasing and Accounts Payable cannot identify the supplier receipt, quantity, or financial document being reversed. If the intended policy is that M054 is only a physical write-off handoff, that ownership boundary must be explicit; as shipped, the terminal `returned` state implies more completion than the module proves.

### M054-F005 — NCR and inspection links are existence-only, incomplete in the UI, and not item/status-bound

Classification: Broken  
Priority: P1  
Session: separate-recommended

Evidence:

- `StoreMrbRequest` checks only `exists` for `ncr_id` and `inspection_id` at `api/app/Modules/Inventory/Requests/StoreMrbRequest.php:40-46`.
- `hold()` copies those IDs without checking item/product identity, inspection entity/stage/status, NCR status, or affected quantity at `api/app/Modules/Inventory/Services/QuarantineService.php:124-135`.
- The linked models carry the fields needed for those checks—NCR product/inspection/status at `api/app/Modules/Quality/Models/NonConformanceReport.php:36-72` and inspection item/entity/status at `api/app/Modules/Quality/Models/Inspection.php:34-55,62-80`—but M054 does not use them.
- The SPA loads the first 100 NCRs without an item/status filter and sends no inspection ID at `spa/src/pages/inventory/mrb/index.tsx:255-269`; the resource exposes only an inspection hash, not its stage/status, at `api/app/Modules/Inventory/Resources/MaterialReviewRecordResource.php:34-41`.

Impact: an MRB can be attached to an unrelated, passed, cancelled, or closed quality record, and the operator cannot see the inspection result from the MRB page. The central quality-to-quarantine trace is therefore not authoritative or usable for release decisions.

### M054-F006 — Hold commands have no durable idempotency contract

Classification: Incomplete  
Priority: P1  
Session: separate-recommended

Evidence:

- The store endpoint accepts a normal request and immediately calls `hold()` with no idempotency key or command identity at `api/app/Modules/Inventory/Controllers/MrbController.php:49-59`.
- Each call generates a new MRB number, inserts a new record, and posts a new physical transfer at `api/app/Modules/Inventory/Services/QuarantineService.php:120-153`; the migration guarantees only uniqueness of the generated number at `0262_create_material_review_records_table.php:20-23`.
- The focused race coverage protects duplicate release, not a timed-out/replayed hold (`api/tests/Feature/Inventory/MrbDoubleReleaseRaceTest.php:102-137`).

Impact: a client timeout after commit followed by a retry can quarantine the same intended event twice whenever source quantity remains. The second MRB is indistinguishable from a legitimate separate hold and can create excess quarantine, downstream release, and GL effects.

### M054-F007 — The MRB search field is a no-op

Classification: Broken  
Priority: P2  
Session: same-session-ok

Evidence:

- The SPA renders a search control and stores `search` in the URL filters at `spa/src/pages/inventory/mrb/index.tsx:144-150`.
- The controller forwards only `status`, `item_id`, and `per_page` at `api/app/Modules/Inventory/Controllers/MrbController.php:37-41`, so `search` is discarded before the service sees it.
- `QuarantineService::list()` implements only status and item filters at `api/app/Modules/Inventory/Services/QuarantineService.php:45-65`.

Impact: operators searching by MRB ticket number receive the unfiltered result set. In a growing quarantine queue this obscures the record needed for a release or incident response while the UI falsely indicates that the search was applied.

### M054-F008 — Client and API quantity precision disagree, and lookup lists are bounded without recovery

Classification: Polish  
Priority: P2  
Session: same-session-ok

Evidence:

- The API accepts at most three decimal places at `api/app/Modules/Inventory/Requests/StoreMrbRequest.php:40-43`, while the hold form accepts four at `spa/src/pages/inventory/mrb/index.tsx:38-47`.
- The hold modal fetches only 500 active items and 100 NCRs with no search, pagination control, or query-error state at `spa/src/pages/inventory/mrb/index.tsx:228-258`.
- The material quantity is a three-decimal database value at `api/database/migrations/0262_create_material_review_records_table.php:29-30`, so a client-side value accepted as `1.2345` will fail only after submission rather than being rejected consistently in the form.

Impact: operators can select an incomplete lookup list or enter a value the form says is valid but the API rejects. The failure path is especially poor for a hold operation because the operator may already be responding to a physical quality exception.

### M054-F009 — High-impact release dispositions have no dual-control policy boundary

Classification: Incomplete  
Priority: P1  
Session: separate-recommended

Evidence:

- Both hold and release use the same `inventory.mrb.manage` permission at `api/app/Modules/Inventory/routes.php:156-157` and `api/app/Modules/Inventory/Requests/StoreMrbRequest.php:21-24,ReleaseMrbRequest.php:20-23`.
- Seeded warehouse staff and QC inspectors both receive that permission at `api/database/seeders/RolePermissionSeeder.php:619-645`; there is no separate disposition-approval permission or approval record in the M054 schema.
- A single authorized operator can therefore create the hold and directly scrap, return to supplier, rework, or use-as-is release through `QuarantineService::release()` at `:165-221`.

Impact: the system does not prove whether a high-value write-off, supplier return, or use-as-is concession received an independent Quality/management decision. This may be intentional for the plant, but the shipped permission model and audit record do not state the policy. Confirm the segregation-of-duties threshold before treating M054 as production-ready.

## Verified strengths

- Inventory and MRB routes are behind Sanctum, the inventory feature flag, and server-side view/manage permissions at `api/app/Modules/Inventory/routes.php:24,152-157`.
- Hold and release writes use database transactions, decimal-string quantity arithmetic, authoritative WAC movements, and allow-listed `material_review_record` source references.
- Release re-reads and locks the MRB row before checking `held`, preventing duplicate release movement; the focused stale-release race tests passed.
- Source availability/reservations are checked before a hold, and quarantine/scrap stock is excluded from material issue and picking paths.
- The MRB status domain is database-guarded (`held`, `released`, `scrapped`, `returned`) at `api/database/migrations/2026_08_13_221000_add_enum_lifecycle_status_guards.php:53,72-99`.
- Hash IDs are decoded at the request boundary and raw IDs are not exposed by the resource; role responsibility and dashboard badge tests passed.

## Verification

Passed:

- `docker compose run --rm api php artisan test tests/Feature/Inventory/QuarantineMrbTest.php tests/Feature/Inventory/MrbDoubleReleaseRaceTest.php tests/Feature/Auth/RoleResponsibilityAlignmentTest.php tests/Feature/Dashboard/BadgeControllerTest.php tests/Feature/Dashboard/BadgeRealtimeTest.php` — 40 tests, 332 assertions.
- `docker compose run --rm spa npm run typecheck` — passed.

Evidence limits:

- No authenticated browser journey was run for raising a hold with invalid/inactive/cross-warehouse locations, linking an inspection/NCR, releasing use-as-is/rework, returning to supplier, or using the search field.
- No focused test covers duplicate/replayed hold requests, generic transfer/adjustment from held stock, invalid quality-link identity/status/quantity, release target activity/warehouse, supplier accounting/procurement reconciliation, or dual-control expectations.
- No deployed warehouse configuration, supplier workflow, or live GL/purchasing handoff was available for a production drill.

## Resumed-plan verification

- `docker compose run --rm api php artisan test tests/Feature/Inventory/QuarantineMrbTest.php tests/Feature/Inventory/MrbDoubleReleaseRaceTest.php` — 16 tests, 49 assertions passed, including the new location, idempotency, and quality-link cases.
- Targeted ESLint for both MRB SPA pages — passed. The latest repository-wide `npm run typecheck` is blocked by pre-existing errors in `src/pages/assets/detail.tsx` (`qrcode`) and `src/pages/return-management/detail.tsx`; no M054 file appears in that error set. The repository-wide lint command also reports three unrelated pre-existing hook dependency errors outside M054.

---

# M054 — material-review-board RE-AUDIT (2026-09-01) — IN PROGRESS

Claim: RECLAIMED (orphan lock, 153h old, from 2026-08-25 session).
Session focus: the **disposition -> stock-movement seam**, handed to this session
independently by two other audits (`ncr-capa` finding N-004 and `goods-receiving`
finding GRN-R6).

## STRUCTURAL BLOCKER RECORDED BEFORE ANY PROBE

**M054 is registered under domain `quality`, but 100% of its code lives in
`api/app/Modules/Inventory/`** — `Models/MaterialReviewRecord.php`,
`Controllers/MrbController.php`, `Services/QuarantineService.php`,
`Requests/{StoreMrb,ReleaseMrb,MrbIndex,MrbQualityOptions}Request.php`,
`Resources/MaterialReviewRecordResource.php`, routes in `Inventory/routes.php`,
SPA at `spa/src/pages/inventory/mrb/`.

`api/app/Modules/Inventory/` is LIVE under another agent this session
(`warehouse-stock-control`), and this session's constraints are read-only there.
Therefore this session is **characterisation + report**, not fix, for the MRB
surface itself. Findings below are reports, not fixes, unless explicitly marked.


## Real numeric baseline (this session)

```
docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_mrb api \
  php artisan test tests/Feature/Inventory/QuarantineMrbTest.php \
                   tests/Feature/Inventory/MrbDoubleReleaseRaceTest.php --no-coverage
→ 16 passed, 49 assertions, exit 0
```
Non-zero assertions, so the run was real. This **exactly matches** the number the
prior session claimed in its "Resumed-plan verification" block. The prior log is
**accurate**, not fabricated and not self-flagged-unverified.

Environment re-verified at claim time: `docker compose ps` showed only `db`
(healthy, up 57m) and `redis` running — **the `api` container is NOT up on this
branch**, so every command in this report used
`docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_mrb api …`.
`select 1;` against `db` returned a row.

## Prior-finding reproduction

| prior | verdict this session | evidence |
|---|---|---|
| F001 explicit location IDs violate zone/warehouse/active invariants | **FIXED — does not reproduce** | `QuarantineService::assertLocation()` `:412-449` now enforces active location, active warehouse, typed zone, required zone, `rejectSpecialZones`, same-warehouse. Applied to source `:195`, quarantine `:200`, release target `:337-342`, and the scrap resolver `:359`. 4 existing tests cover it. |
| F002 held stock bypasses MRB release via generic transfer/adjustment | **REPRODUCES — and is worse than described** | see M054-R1 |
| F003 rework/use_as_is release has no reinspection or concession gate | **REPRODUCES** | `release()` `:331-356` treats Rework and UseAsIs identically: target location + zone check, then transfer to good stock. `ReleaseMrbRequest::rules()` `:34-39` has only `disposition`, `target_location_id`, `notes`. |
| F004 return_to_supplier has no supplier/procurement provenance | **REPRODUCES** | `:375-388` posts a bare `ReturnToVendor` with `referenceType: 'material_review_record'`; `0262_create_material_review_records_table.php:20-56` + `2026_08_25_190000_add_mrb_hold_idempotency.php` have no vendor/PO/GRN/bill column. |
| F005 NCR/inspection links existence-only, not item/status-bound | **FIXED — does not reproduce** | `assertQualityLinks()` `:472-526` now checks inspection status=Failed, item identity, batch ≥ qty, NCR status ∈ {Open,InProgress}, affected_qty ≥ qty, NCR↔inspection identity. `qualityOptions()` `:111-164` filters candidates by item + failed status. Measured over HTTP: `GET /mrb/quality-options?item_id=<hash>` → 200 returning only the matching failed inspection. |
| F006 hold has no durable idempotency contract | **FIXED — does not reproduce** | `Idempotency-Key` header → `:176-223` fingerprint compare under `lockForUpdate`, backed by UNIQUE `(held_by, idempotency_key)` in `2026_08_25_190000_add_mrb_hold_idempotency.php:16`. |
| F007 MRB search field is a no-op | **FIXED — does not reproduce** | `list()` `:71-86` searches mrb_number, item code/name, ncr_number, source + quarantine location/zone codes. `MrbIndexRequest:21` validates `search`. Measured: `GET /mrb?search=MRB-` → 200. |
| F008 client/API quantity precision disagree | **FIXED on the API side — does not reproduce as a defect** | `StoreMrbRequest:41` is `['required','decimal:0,3','min:0.001']`. Probed 20 values over HTTP (below) — zero 500s, zero silent corruption. |
| F009 high-impact dispositions have no dual-control boundary | **REPRODUCES** | see M054-R3 |

**5 of 9 prior findings are genuinely fixed and verified by probe; 4 reproduce.**
The four that reproduce are exactly the four the prior session deferred as
requiring cross-module policy, which was the correct call.

---

## HTTP-level coverage versus service-only

The whole repo contains **two** HTTP calls to any MRB endpoint, both
`postJson('/api/v1/inventory/mrb', …)` in
`api/tests/Feature/Inventory/QuarantineMrbTest.php:418,424`. Everything else in
`QuarantineMrbTest` and all of `MrbDoubleReleaseRaceTest` calls
`QuarantineService` directly, skipping the FormRequest and the route middleware.

| endpoint | HTTP coverage before this session | probed here | result |
|---|---|---|---|
| `GET /api/v1/inventory/mrb/options` | **none** | yes | 200; 403 without `inventory.mrb.view` |
| `GET /api/v1/inventory/mrb` | **none** | yes, 9 filter combinations | 200 / 422 correctly; no 500 |
| `GET /api/v1/inventory/mrb/quality-options` | **none** | yes, 5 combinations | 200 / 422; one misleading message (M054-R7) |
| `GET /api/v1/inventory/mrb/{mrb}` | **none** | yes | 200; 403 unauthorised; 404 on raw int |
| `POST /api/v1/inventory/mrb` | 2 calls | yes, 20 quantity values | all correct |
| `POST /api/v1/inventory/mrb/{mrb}/release` | **none** | yes | 200 / 422 / 403 correctly |

**No `goods-receiving`-class defect was found on the uncovered endpoints — every
one of the five responds correctly.** The FormRequests are all reachable and
behave. This is the good outcome, and it is now measured rather than assumed.

---

# FINDINGS — 2026-09-01

## STRUCTURAL: M054 is a `quality` registry entry whose code is 100% Inventory

Classification: **Incomplete** (registry/ownership, not runtime)
Priority: P2 · Session: separate-recommended

`api/app/Modules/Inventory/` holds every MRB artefact:
`Models/MaterialReviewRecord.php`, `Controllers/MrbController.php`,
`Services/QuarantineService.php`, four `Requests/Mrb*|*Mrb*.php`,
`Resources/MaterialReviewRecordResource.php`, `Enums/MrbStatus.php`, routes at
`Inventory/routes.php:153-158`, SPA at `spa/src/pages/inventory/mrb/`,
API client `spa/src/api/inventory/mrb.ts`, sidebar
`spa/src/components/layout/Sidebar.tsx:337-342`.

The dependency direction is **Inventory → Quality**: `QuarantineService.php:20-24`
imports `InspectionStatus`, `NcrDisposition`, `NcrStatus`, `Inspection`,
`NonConformanceReport`. Quality imports nothing from Inventory —
`grep StockMovementInput\|StockMovementService api/app/Modules/Quality` returns
**nothing**. This matters for the bridge options below.

Consequence for this session: `Inventory/` is LIVE under another agent, so
**every finding below is a report, not a fix.** No MRB source file was modified.

---

## M054-R1 — Held quarantine stock escapes on four of six movement types, and strands the MRB permanently

Classification: **Broken** · Priority: **P0** · Session: separate-recommended
(reproduces and extends prior F002)

`StockMovementService::assertConsumableSource()`
`api/app/Modules/Inventory/Services/StockMovementService.php:307-329` returns
early unless the movement type is `MaterialIssue` or `Delivery`:

```php
if (! in_array($type, [StockMovementType::MaterialIssue, StockMovementType::Delivery], true)) {
    return;
}
```

and the comment at `:300-304` states the intent: *"Only deliberate write-offs
(adjustment_out, scrap, return_to_vendor) and quarantine mechanics (transfer) may
touch it."* `SourceReferenceRegistry::assertValid()`
`api/app/Modules/Accounting/Services/SourceReferenceRegistry.php:75-77` permits
`referenceType: null, referenceId: null`, so a caller needs no document at all.

**Measured** (this session, `ogami_test_mrb`) — one MRB holding 20.000 at a
quarantine location, then each movement type attempted out of that location with
a null reference and no MRB decision:

| movement type | outcome | MRB afterwards | quarantine left |
|---|---|---|---|
| `Transfer` (→ good location) | **ESCAPED** | still `held`, claims 20.000 | 0.000 |
| `AdjustmentOut` | **ESCAPED** | still `held`, claims 20.000 | 0.000 |
| `Scrap` | **ESCAPED** | still `held`, claims 20.000 | 0.000 |
| `ReturnToVendor` | **ESCAPED** | still `held`, claims 20.000 | 0.000 |
| `MaterialIssue` | blocked (`BusinessRuleException`) | `held` | 20.000 |
| `Delivery` | blocked (`BusinessRuleException`) | `held` | 20.000 |

The escape is not the whole defect. **After any escape the MRB can never reach a
terminal state.** `release()` re-reads the row, sees `held`, and then
`StockMovementService::move()` throws:

```
InsufficientStockException: Insufficient available stock at location 2 for item 1: needed 20.000, available 0.000
```

measured on all four escape paths. So the record is stuck at `held` forever: an
IATF §8.7 "nonconforming material on hold" that can never be dispositioned or
closed out, while the physical material has already gone back to good stock
(`Transfer`), off the books (`AdjustmentOut`), to scrap, or to a vendor — in every
case with **no disposition recorded, no MRB release movement, and no link to the
NCR**. The `mrb_holds` dashboard badge
(`api/app/Modules/Dashboard/Services/BadgeService.php:433-435`) counts it forever.

This is a stronger claim than prior F002, which said "later release can fail".
Measured: it **always** fails, permanently.

**Owned by Inventory (live under another agent) — reported, not fixed.**

---

## M054-R2 — Two independent disposition decisions on the same lot, and nothing reconciles them

Classification: **Broken** · Priority: **P0** · Session: separate-recommended
(this is the seam `ncr-capa` N-004 and `goods-receiving` GRN-R6 both pointed at)

There are two `disposition` columns over the same `NcrDisposition` enum, written
by two services, with two disjoint sets of consequences and **no constraint
between them**:

| | `non_conformance_reports.disposition` | `material_review_records.disposition` |
|---|---|---|
| written by | `NcrService::setDisposition()` `api/app/Modules/Quality/Services/NcrService.php:255-275` | `QuarantineService::release()` `api/app/Modules/Inventory/Services/QuarantineService.php:391-402` |
| `scrap` does | replacement Work Order, **only if** outgoing stage + product_id (`NcrService.php:319-336`) | `StockMovementType::Scrap` out of quarantine (`:358-373`) |
| `rework` does | rework Work Order, **only if** outgoing stage (`NcrService.php:338-357`) | Transfer quarantine → good location (`:331-356`) |
| `use_as_is` does | **nothing** | Transfer quarantine → good location (`:331-356`) |
| `return_to_supplier` does | notify Purchasing (`NcrService.php:360-362`, `:430-453`) | `ReturnToVendor` out of quarantine (`:375-388`) |
| touches stock? | **never** | always |
| touches WO / notification? | always | **never** |

`NcrService` contains **zero** references to `MaterialReviewRecord`,
`QuarantineService`, `StockMovement*` or any warehouse zone. Confirmed by grep
across `api/app/Modules/Quality/`.

**Measured — I1: an NCR disposition has no material consequence.** Setting
`ncr.disposition = 'scrap'` with no MRB: `stock_movements` **0 → 0**. This
independently reproduces `ncr-capa` N-004 from the MRB side.

**Measured — I2: the two decisions may openly contradict each other.** An NCR
whose `disposition` is `return_to_supplier`, with a linked MRB released
`use_as_is`, both succeed. Result: `ncr=return_to_supplier mrb=use_as_is
status=released`, and `40.000` of the nonconforming lot landed in a
`finished_goods` location. The quality record says "send it back to the
supplier"; the stock ledger says "it is good finished stock". Both are
authoritative in their own module and neither knows about the other.

**Measured — I3: quantity dispositioned is not reconciled against quantity
quarantined.** `assertQualityLinks()` `:506-508` checks each hold individually
against `affected_quantity`, never the running total. Against one NCR with
`affected_quantity = 40`: **3 MRB rows, `sum(quantity) = 120.000`** — three times
the quantity the NCR says is affected — all accepted.

**Measured — I4/I5: the same nonconformance can be scrapped AND returned to the
supplier at the same time.** Two MRBs on one NCR (`affected_quantity = 40`), one
released `scrap` → `scrapped`, the other `return_to_supplier` → `returned`. Both
terminal, both physical movements posted, 80 units disposed against a 40-unit
nonconformance. Re-releasing an already-terminal MRB *is* correctly refused
(`MRB … is not held (status: scrapped)`) — the guard is per-record, and there is
no per-NCR guard at all.

---

## M054-R3 — There is no board: one actor inspects, raises the NCR, holds, and dispositions

Classification: **Broken** · Priority: **P1** · Session: separate-recommended
(reproduces prior F009; this is the MRB form of the self-absolution hole
`ncr-capa` measured on NCRs)

A Material Review Board is by definition a multi-party body. The implementation
has a single permission for both sides of the decision:

- `Inventory/routes.php:157` hold → `permission:inventory.mrb.manage`
- `Inventory/routes.php:158` release → `permission:inventory.mrb.manage`
- `StoreMrbRequest:23` and `ReleaseMrbRequest:22` both check the same slug.
- `RolePermissionSeeder.php` grants `inventory.mrb.view` + `inventory.mrb.manage`
  to **`warehouse_staff` (:669-670)** and **`qc_inspector` (:694-695)**.
- The schema has no approver, no second signature, no board-membership row.

**Measured — I9/I10.** One `qc_inspector` user performed the entire chain:
`inspector_id=7  ncr.created_by=7  held_by=7  released_by=7`. The same person who
detected the nonconformance released it `use_as_is` — a concession that
`NcrDisposition.php:12` documents as *"records but ships anyway, with customer
sign-off"* — into good stock, with no second party and no recorded customer
sign-off anywhere in the schema.

All three registry roles complete their part; the problem is that **any one of
them completes all of it.**

---

## M054-R4 — Rework and use-as-is return material to good stock with no reinspection or concession evidence

Classification: **Broken** · Priority: **P1** · Session: separate-recommended
(reproduces prior F003)

`release()` `:331-356` handles `Rework` and `UseAsIs` in one shared `case` arm:
require a target location, assert it is active/same-warehouse/non-special, post
the transfer, set `Released`. `ReleaseMrbRequest:34-39` accepts only
`disposition`, `target_location_id`, `notes`.

So `rework` is recorded as complete with **no evidence the rework was performed**
and **no reinspection at all** — no `inspection_id` field on release, and
`NcrService`'s own rework work-order path (`NcrService.php:338-357`) is never
invoked by MRB and only fires for outgoing-stage inspections anyway. `use_as_is`
is recorded with **no concession approval and no customer sign-off**, despite the
enum documenting sign-off as its defining requirement.

Measured over HTTP: `POST /mrb/{id}/release {"disposition":"use_as_is",
"target_location_id":"<hash>"}` → **200**, and the material is in a
`finished_goods` location. The only thing the endpoint refuses is a *missing*
target location (`422 A target good location is required for rework/use-as-is
release.`).

---

## M054-R5 — A terminal MRB record is fully mutable, hard-deletable, and untriggered

Classification: **Broken** · Priority: **P1** · Session: separate-recommended

Measured against a `scrapped` MRB (`ogami_test_mrb`, real Postgres):

| attack | result |
|---|---|
| Eloquent `fill(['quantity' => '999.000', …])->save()` | **ALLOWED** — `quantity` became `999.000` on a scrapped record |
| property-set `status = MrbStatus::Held; save()` | **ALLOWED** — terminal record reopened to `held` |
| raw SQL `UPDATE … SET mrb_number = 'HACKED'` | **ALLOWED** — document number rewritten |
| `MaterialReviewRecord::find($id)->delete()` | **ALLOWED** — row gone (no `SoftDeletes` on the model) |
| non-internal triggers on `material_review_records` | **NONE** |
| CHECK constraints | exactly one: `material_review_records_status_lifecycle_check` |

The single CHECK constraint (from
`2026_08_13_221000_add_enum_lifecycle_status_guards.php:53,72-99`) only restricts
`status` to the four enum values — it does **not** prevent a terminal → `held`
downgrade, which is why that downgrade succeeded.

Mitigations that do exist: none of this is HTTP-reachable (there is no update or
destroy route — `Inventory/routes.php:153-158` exposes only
options/index/quality-options/show/store/release), and `MaterialReviewRecord`
uses `HasAuditLog` (`Models/MaterialReviewRecord.php:27`), so the *Eloquent*
mutations leave an audit row. The **raw-SQL rewrite and the hard delete leave
nothing**, and there is no `P0001` trigger of the kind `journal-ledger`
established as the precedent.

Same shape `ncr-capa` found on `non_conformance_reports`. **If a trigger is
proposed, note the hazard `ncr-capa` documented:** `release()` writes the MRB row
**twice** in `hold()` (`:266` then `:281` for `hold_movement_id`) and once in
`release()`, so a trigger keyed naively on `OLD.status` being terminal is fine
here — but a trigger keyed on "any UPDATE after terminal" would also have to
permit nothing, since MRB has no legitimate post-terminal writer. That makes MRB
an *easier* trigger target than NCR/CAPA, not a harder one.

---

## M054-R6 — Nothing ages, escalates, or reports on a quarantine hold; there are zero MRB scheduled commands

Classification: **Missing** · Priority: **P1** · Session: separate-recommended

Measured: enumerating the full Artisan command list inside the container and
filtering on `/mrb|quarantine/i` returns **NONE**.
`grep -n 'mrb\|quarantine' api/routes/console.php` → no matches.

So there is no aging report, no SLA, no escalation, and no alert for material
sitting in quarantine. An MRB raised today can remain `held` indefinitely — and
per M054-R1 a stranded one *must* remain `held` forever — with the only signal
being an ever-growing `mrb_holds` sidebar badge count
(`BadgeService.php:430-435`). IATF §8.7 expects nonconforming material to be
dispositioned in a controlled, timely way; nothing here measures timeliness.

Note the good news on the flip side of the "dead scheduled command" class: there
is no MRB command printing zero counts and exiting SUCCESS, because there is no
MRB command at all. That invariant is vacuously satisfied and is reported as
**N/A — no command exists**, not as "passed".

---

## M054-R7 — `return_to_supplier` records a vendor return with no vendor

Classification: **Incomplete** · Priority: **P1** · Session: separate-recommended
(reproduces prior F004)

`release()` `:375-388` posts `StockMovementType::ReturnToVendor` with
`referenceType: 'material_review_record'` and sets `MrbStatus::Returned`. The
table has no `vendor_id`, `purchase_order_id`, `goods_receipt_note_id`, `bill_id`
or supplier-return document column —
`0262_create_material_review_records_table.php:20-56` plus
`2026_08_25_190000_add_mrb_hold_idempotency.php:14-15` are the complete schema.

So the inventory ledger asserts material went back to a supplier while naming no
supplier, no receipt, and no financial document to reverse. Purchasing and AP
have nothing to act on. Meanwhile `NcrService::notifyPurchasing()`
(`NcrService.php:430-453`) sends a *notification* naming the NCR — the two halves
of "return to supplier" are in different modules and never meet.

---

## M054-R8 — Deleting an NCR silently erases the MRB's quality trace

Classification: **Broken** · Priority: **P2** · Session: separate-recommended

`0262_create_material_review_records_table.php:24-27` declares both quality FKs
`->nullOnDelete()`:

```php
$table->foreignId('ncr_id')->nullable()->constrained('non_conformance_reports')->nullOnDelete();
$table->foreignId('inspection_id')->nullable()->constrained('inspections')->nullOnDelete();
```

Measured: `NonConformanceReport` and `Inspection` **do not use `SoftDeletes`**
(probed via `class_uses_recursive`), so a delete is a hard delete. After
`DELETE FROM non_conformance_reports WHERE id = …`, the MRB row survives with
`ncr_id = NULL` and `GET /mrb/{id}` still returns **200** showing `"ncr": null` —
a quarantine/disposition record with its cause silently removed. For an IATF
§8.7 traceability record the correct FK is `restrictOnDelete`, matching
`item_id`, `source_location_id` and `held_by` on the same table (`:29,32-35,46`).

---

## M054-R9 — Polish

1. **A soft-deleted item makes a held lot unidentifiable.** `Item` uses
   `SoftDeletes` (`Models/Item.php:20`) but `item_id` is `restrictOnDelete`, so
   the FK survives while the `belongsTo` resolves to null. Measured:
   `GET /mrb` and `GET /mrb/{id}` both return **200** with `"item": null`.
   *This refutes a hypothesis I formed while reading* — I expected a 500 from
   `MaterialReviewRecordResource:30-35` (`$this->item->hash_id`, the only
   relation payload in that resource with **no** null guard, unlike `ncr` `:37`,
   `inspection` `:53` and `locationPayload()` `:84`). `JsonResource::whenLoaded()`
   returns `null` when a loaded relation is null, so it never reaches the
   closure. Not a 500 — but the operator sees a quarantined lot with no item code
   or name, and the `mrb_holds` badge still counts it.
2. **Misleading validation message on a bad hash id.** `GET
   /mrb/quality-options?item_id=garbage` → `422 "The item id field is required."`
   `ResolvesHashIds` nulls an unresolvable hash before `rules()` runs, so an
   *invalid* id is reported as a *missing* one. Same for `StoreMrbRequest`.
3. **`HashIdFilter::decode` accepts raw integers in every environment**
   (correction #4, confirmed): `GET /mrb/quality-options?item_id=12` → 200, and
   `GET /mrb?item_id=999999` → 200. Not a leak, but the API accepts two id forms.
4. **MRB is absent from `docs/USER-MANUAL.md`.** `grep -i 'material
   review\|MRB\|quarantine' docs/USER-MANUAL.md` → **zero matches**, for a
   live, permission-gated, sidebar-linked, IATF-relevant screen.

---

## Verified strengths (measured this session, not read)

- **State machine is sound. 20/20 transition-matrix cells walked** (4 states ×
  5 dispositions including one invalid). `held` + each of the four valid
  dispositions → the correct terminal state; `held` + invalid → refused; **all 15
  terminal-state cells refused** with `BusinessRuleException`. No back-door of
  the `resume()` kind — `release()` is the single mutation path and it re-reads
  under `lockForUpdate` (`:313-316`).
- **Quantity validation is fully hardened — the eight-module validation family
  does not apply here.** `StoreMrbRequest:41` = `['required','decimal:0,3','min:0.001']`.
  20 values probed over HTTP: `1.999`→201 stored exactly `1.999` (no rounding);
  `1.9999`, `10.00005`, `1e3`, `1E3`, `1e17`, `1e20`, `0.0001`, `abc`, `1,5`,
  `0x10`, `NaN`, `Infinity` → **422**; `0`, `-5` → 422 `min`; `''` → 422
  required; `'  7  '`→201 `7.000`; `'+3'`→201 `3.000`. **Zero 500s, zero silent
  corruption, no `ValueError`, no `22003`.** `999999999999999` (which would
  overflow `decimal(15,3)`) is masked by the stock-availability check at `:236-244`
  returning 422 first — theoretically reachable only with ≥1e12 on hand.
  No array/map payload exists on either MRB request.
- **`Rule::exists()` landmine absent.** No MRB FormRequest uses `Rule::exists`
  in any form (all four checked programmatically); they use plain
  `'exists:table,id'` strings.
- **No `orderBy($variable)`.** `QuarantineService::list()` `:88` hardcodes
  `orderByDesc('held_at')->orderByDesc('id')`. The `docs/PATTERNS.md:262-268`
  unvalidated-`direction` bug is **not** inherited.
- **No `42803` risk.** MRB has no aggregate query anywhere — `list()` paginates,
  `qualityOptions()` uses `whereHas`. Nothing selects over
  `NonConformanceReport::actions()`, so the default-`orderBy` GROUP BY trap does
  not apply. The only MRB aggregate in the codebase is `BadgeService.php:433-435`,
  a plain `count()`.
- **Empty-period divide-by-zero: N/A.** There is no MRB rate, average or
  percentage anywhere. A `count()` of 0 is honest, not fabricated.
- **`document_sequences` row exists.** Measured:
  `{document_type: mrb, prefix: MRB, year: 2026, month: 9, last_number: 1}`
  (seeded by `0360_seed_document_sequence_config.php:16`), producing
  `MRB-202609-0001` — correct monthly-reset format. Unlike `work_order`
  (correction #5), MRB's row is present.
- **No raw integer PKs leak.** `GET /mrb/{id}` payload: `id` `'1kPNxQbXyR'`,
  `hold_movement_id` `'40awqV4pKG'`, `release_movement_id` `null`. Programmatic
  check for `"id":<pk>` where pk=34 → **not present**. `GET /mrb/999999` → 404.
  (The 404 body carries `exception`/`file` keys because `APP_DEBUG=true` under
  `phpunit.xml`; that is an env artifact, not an MRB defect.)
- **Permission gate holds on all six endpoints including list and options.**
  `maintenance_tech` (no `inventory.mrb.*`) → **403** on `/mrb/options`, `/mrb`,
  `/mrb/quality-options`, `/mrb/{id}` and `POST /mrb/{id}/release`. `warehouse_staff`
  and `qc_inspector` both complete hold **and** release (which is itself
  M054-R3). All three registry roles can complete their part.
- **Quarantined stock is visible, not invisible.** Measured after a 30-unit hold
  from a 100-unit source: a real `stock_levels` row at the quarantine location
  with `quantity=30.000 reserved=0.000`; total on hand across all locations still
  `100.000`; non-quarantine/non-scrap on hand `70.000`. Nothing vanished and
  nothing is double-counted.
- **Quarantined stock is excluded from issue, delivery, reservation, picking, MRP
  and work orders.** Measured for issue and delivery (both blocked, above);
  guards read at `MaterialIssueService:96-97`, `PickingListService:132-133`,
  `StockMovementService::reserve()` `:386-397`, `MrpEngineService:867-868`,
  `WorkOrderService:955-956,1063-1064`. (The *write* side is the hole — M054-R1.)
- **Restore route / soft-delete invariants: N/A, correctly.** `MaterialReviewRecord`
  has no `SoftDeletes` and no restore or destroy route, so the five-module
  `withTrashed()` defect cannot exist here. There is also **no MRB export**, so
  the archived-row-in-export invariant has no surface.
- **No dead surfaces in either direction.** All six routes have an SPA client
  method (`spa/src/api/inventory/mrb.ts:25-40+`); both pages are routed
  (`spa/src/routes/inventoryRoutes.tsx:109-112`) and reachable from the sidebar
  (`Sidebar.tsx:337-342`). The only documentation gap is `USER-MANUAL.md`
  (M054-R9.4).

---

# THE CENTRAL QUESTION — the disposition → movement seam

## 1. What does MRB actually do today?

MRB is a **manually-raised, location-based warehouse segregation tool for stock
that is already on the books.** Precisely:

- `hold()` requires an existing `stock_levels` row at a **good** source location
  with `quantity - reserved_quantity >= qty`
  (`QuarantineService.php:233-244`, plus `rejectSpecialZones: true` at `:195`).
  It posts a `Transfer` good-location → Quarantine-zone location and opens a
  `held` MRB row.
- `release()` posts a second movement whose type depends on a disposition string
  the operator supplies **at release time**, and sets the terminal status.
- Its only entry points are `POST /api/v1/inventory/mrb` and
  `POST /api/v1/inventory/mrb/{mrb}/release`, both from the
  `/inventory/mrb` screen. **Nothing anywhere in the codebase creates an MRB
  automatically** — `MrbController.php:65` is the only production caller of
  `hold()`; the only other callers are the two MRB test files.
- `ncr_id` and `inspection_id` are **nullable and optional**. An MRB can be
  raised, dispositioned and closed with no quality record attached at all.

So MRB is the **only** mechanism in the system that gives a nonconforming-material
decision a physical consequence, and it is entirely operator-driven.

## 2. Is it the intended `NCR disposition → MRB decision → stock movement` bridge?

**No. It is not wired as a bridge, and the data model cannot express one.**

The intended chain would need the NCR's decision to *drive* the MRB's movement.
What exists instead is two independent decisions over the same enum (M054-R2):
`NcrService::setDisposition()` produces work orders and notifications and never
touches stock; `QuarantineService::release()` produces stock movements and never
touches work orders or notifications. Neither reads the other's `disposition`
column. Measured: they can hold openly contradictory values simultaneously
(`ncr=return_to_supplier` / `mrb=use_as_is`, material into finished goods).

Three concrete reasons the bridge is absent rather than merely unwired:

1. **Direction of dependency.** Inventory imports Quality
   (`QuarantineService.php:20-24`); Quality imports nothing from Inventory. A
   Quality-initiated bridge (`NcrService` → `QuarantineService`) would be a **new**
   dependency direction and would make `NcrService::close()` fail on a warehouse
   condition.
2. **No quantity ledger.** `assertQualityLinks()` `:506-508` compares each hold
   against `affected_quantity` in isolation. There is no per-NCR running total, so
   nothing can know how much of a nonconformance has been dispositioned.
   Measured: 3 MRBs summing 120.000 against `affected_quantity = 40`.
3. **The NCR carries a `product_id`, the MRB carries an `item_id`.**
   `NonConformanceReport` is keyed on `App\Modules\CRM\Models\Product` (finished
   goods) while `MaterialReviewRecord.item_id` is
   `App\Modules\Inventory\Models\Item` (raw materials). They are joined only
   indirectly, via `inspections.item_id` (`assertQualityLinks()` `:487,520`).
   A bridge has to decide which side owns the identity of the held lot.

## 3. Is `goods-receiving`'s claim correct?

> *"MRB can't help, because it transfers stock that never existed."*

**CORRECT, for the receipt remainder — with one important narrowing.**

Verified against the GRN path (read-only; `Inventory` is live under another agent):

- `GrnService::moveAcceptedQuantity()`
  `api/app/Modules/Inventory/Services/GrnService.php:1225-1245` is the single
  stock-posting funnel for receiving, and it posts a delta derived from
  `quantity_accepted` only. `partialAccept()` `:606-686` sets
  `quantity_accepted = $accepted` at `:645` and posts only that delta at `:650`.
  For received 100 / accepted 60, **60 is booked; the other 40 is booked nowhere** —
  not to a quarantine location, not to a hold location, not to scrap.
- `resolveReceivingLocation()` `:1252-1290` **forbids** receiving into a
  Quarantine or Scrap zone outright (`:1283`), so the GRN path structurally cannot
  place rejected material where MRB operates.
- There is **no `quantity_rejected` column on `grn_items`**. The create migration
  `0064_create_grn_items_table.php:19-20` has only `quantity_received` and
  `quantity_accepted`, and no later migration adds a rejected column. The
  "rejected 40" is an arithmetic residue no table records.
- `MaterialReviewRecord` has **no GRN foreign key** at all
  (`0262_…:24-27` — only `ncr_id`, `inspection_id`).

Therefore for `received = 100, accepted = 60`: the 60 in a good location **can**
legitimately be held under MRB; the other 40 has no `stock_levels` row anywhere,
so `hold()` fails at `QuarantineService.php:237`
(`"No stock for item {$itemId} at source location {$sourceId}."`). For a fully
`rejected` GRN nothing is booked, so MRB is inapplicable to the entire receipt.

**The narrowing:** the claim is right about the remainder and wrong as a blanket
statement — MRB works correctly on accepted stock, which is its actual design
(hold material that is on the books but suspect, e.g. an in-process or dock-audit
failure discovered after put-away). The gap is that **Chain 2 has no destination
for received-but-not-accepted quantity**, and MRB is not it.

Two adjacent Chain-2 defects surfaced while verifying this. **Both belong to
`goods-receiving` / `purchasing`, not to M054** — reported here only so they are
not lost:

- `partialAccept()` does not call `reversePoReceipt()`, so the PO line keeps
  `quantity_received = 100, quantity_accepted = 60`. Remaining capacity is
  computed from *received* (`GrnService.php:197`), so a replacement 40 can never
  be received against that line, and `refreshPoStatus()` `:1364-1366` requires
  `quantity_accepted >= quantity` — the PO is stuck at `partially_received`
  permanently.
- `partialAccept()` calls `assertQcGate()` `:616`, which throws unless every
  incoming inspection is `passed` or `cancelled` (`:737-750`). So
  `GrnStatus::PartialAccepted` is **unreachable on a genuine quality failure** —
  the operator must accept all 100, reject all 100, or cancel the inspection.

> Correction to `CLAUDE.md` and to my own briefing: **`GrnStatus::Draft` does
> exist** (`api/app/Modules/Inventory/Enums/GrnStatus.php:9`) and is live
> (`GrnService.php:311,318,329,361`). The note claiming "no `draft`" is stale.

## 4. Options for reconciling MRB with the NCR disposition — with consequences

Presented as options because **which mechanism owns the movement is an
IATF-auditable design decision, not a code cleanup.** I am not choosing one.

### Option A — MRB is the sole executor; the NCR disposition becomes advisory
Make `release()` require an `ncr_id` and refuse a disposition that differs from
`non_conformance_reports.disposition`. Add a per-NCR quantity ledger so
`sum(mrb.quantity) <= ncr.affected_quantity`.

- *Direction*: Inventory → Quality only. **No new dependency** — the imports
  already exist. Cheapest to build.
- *Consequence*: every disposition now requires a warehouse action to be
  complete, so an NCR cannot close until the material is physically dealt with.
  That is arguably the correct IATF posture, but it **couples NCR closure to
  warehouse throughput** and will block closures today that currently succeed.
- *Consequence*: makes the NCR's own `scrap`/`rework` work-order side effects
  (`NcrService.php:319-357`) and MRB's movements two halves of one decision that
  must now be kept consistent across a module boundary in both directions.
- *Consequence*: does **not** address the GRN remainder (§3) — nothing to hold.

### Option B — Quality orchestrates; `NcrService::close()` drives the movement
`NcrService::close()` calls `QuarantineService` for the material effect.

- *Direction*: **new Quality → Inventory dependency.** Quality currently has zero
  stock code; this would make the Quality module unbootable without Inventory
  unless lazily resolved the way `NcrService::workOrderService()` already does
  (`:404-411`).
- *Consequence*: `close()` starts failing on warehouse conditions (no active
  quarantine location, stock moved, insufficient available). `NcrService` already
  has the precedent for refusing to close when a required downstream effect fails
  (`createRequiredWorkOrder()` `:413-437` throws and leaves the NCR open) — so the
  pattern exists, but the failure surface grows a lot.
- *Consequence*: puts the decision where IATF expects it (Quality owns
  disposition of nonconforming product) and gives one place to audit.
- *Consequence*: still requires the quantity ledger from Option A.

### Option C — A third owner: MRB becomes the board of record for both
Move the disposition decision *out* of `NcrService::setDisposition()` and make
the MRB record the single place a disposition is decided, with the NCR reading it.

- *Consequence*: the largest change, and it inverts the current model where NCR
  is the quality record of record. Would need MRB to also trigger the replacement
  / rework work orders that `NcrService::close()` currently creates.
- *Consequence*: cleanly solves M054-R2, R3 and R4 at once, because a *board*
  record can carry board membership, a second signature, reinspection evidence and
  concession sign-off — none of which the current schema has room for.
- *Consequence*: MRB would then need a non-stock entry point to cover the GRN
  remainder (§3), i.e. a disposition record that is not backed by a stock movement.

### Option D — Leave the two mechanisms independent, and say so
Document that `non_conformance_reports.disposition` is a *quality determination*
and `material_review_records.disposition` is a *physical execution*, and add a
reconciliation report rather than a constraint.

- *Consequence*: the cheapest, and honest about what is shipped. But it leaves
  M054-R2's measured contradiction (`return_to_supplier` vs `use_as_is`, material
  into finished goods) legal, which is very hard to defend to an IATF auditor
  because §8.7 requires nonconforming product to be controlled *to prevent
  unintended use* — and a use-as-is release into finished goods against an NCR
  that says return-to-supplier is exactly unintended use.

### Orthogonal to all four, and independently necessary
**M054-R1 must be fixed regardless of which option is chosen.** As long as
`Transfer` / `AdjustmentOut` / `Scrap` / `ReturnToVendor` can leave a quarantine
location with no MRB decision, no bridge design holds — the material escapes
underneath whichever mechanism owns the decision, and the MRB is stranded at
`held` forever. This is a containment-shaped fix (extend
`assertConsumableSource()` to require an MRB release context for any movement out
of a Quarantine zone) but it lives in `StockMovementService`, which is
**Inventory-owned and live under another agent.**

## 5. Questions that need a human

1. **Which of Options A–D is the intended design?** This determines whether
   `NcrService::close()` may fail on a warehouse condition, and whether an NCR can
   be closed with material still in quarantine.
2. **Is MRB meant to be a *board* at all?** The name and IATF §8.7 imply a
   multi-party body; the implementation is one permission held by two roles, and
   measured: one `qc_inspector` inspected, raised the NCR, held and released
   `use_as_is` into good stock (M054-R3). If single-actor is the intended plant
   reality for a 200-person shop, that should be a recorded decision rather than
   an accident of the permission model.
3. **Where does received-but-not-accepted GRN quantity go?** (§3.) This is a
   Chain-2 gap that neither MRB nor GRN nor RMA currently owns, and it needs an
   owner before it can be built. It is **not** M054's to answer alone.
4. **Should `use_as_is` require recorded customer sign-off before material
   re-enters good stock?** The enum says it does (`NcrDisposition.php:12`); the
   code requires nothing (M054-R4).
