# M040 — Warehouse & Stock Control

Audit date: 2026-08-25  
Final disposition: Plan Ready  
Scope: one module only — warehouse hierarchy/map, stock levels and movements, adjustments, counts, transfer orders, picking, and warehouse scanning.

## Re-audit trigger and boundary

The previous report was written before the current working-tree changes. Scoped Inventory files changed afterward, including the ledger-backed map summary, lot-aware picking, count locking, warehouse permission alignment, and reason-code entry. Those changes materially affect the previous findings, so discovery, hardening, and polish were repeated against the current checkout. The changes pre-date this session; this session made no application-source changes during the re-audit.

Dependency modules were read only for contracts. No dependency module was audited or modified.

## Evidence checked

Backend and database:

- `api/app/Modules/Inventory/routes.php:68-130`
- `api/app/Modules/Inventory/Services/WarehouseService.php:18-102`
- `api/app/Modules/Inventory/Services/WarehouseMapService.php:21-120`
- `api/app/Modules/Inventory/Services/StockLocationSummaryService.php:20-116`
- `api/app/Modules/Inventory/Services/StockMovementService.php:53-423`
- `api/app/Modules/Inventory/Services/StockAdjustmentService.php:36-337`
- `api/app/Modules/Inventory/Services/StockCountService.php:28-258`
- `api/app/Modules/Inventory/Services/TransferOrderService.php:22-106`
- `api/app/Modules/Inventory/Services/PickingListService.php:23-205`
- `api/app/Modules/Inventory/Services/BarcodeScanResolverService.php:30-318`
- relevant controllers, requests, models, resources, enums, migrations, and `RolePermissionSeeder.php:203-219, 618-643`

Frontend and contracts:

- `spa/src/pages/warehouse/map.tsx:21-411`
- `spa/src/pages/warehouse/stock-count.tsx:28-427`
- `spa/src/pages/warehouse/transfers.tsx:29-270`
- `spa/src/pages/warehouse/picking.tsx:17-179`
- `spa/src/pages/warehouse/scanner/index.tsx:14-55`
- `spa/src/pages/inventory/stock-adjustments/create.tsx:24-176`
- `spa/src/api/inventory/warehouseWms.ts`, `spa/src/api/inventory/scanner.ts`, `spa/src/api/inventory/warehouse.ts`, `spa/src/api/inventory/stock.ts`
- `spa/src/types/warehouse.ts:1-165`
- `spa/src/routes/inventoryRoutes.tsx:75-88`
- `docs/DESIGN-SYSTEM.md`, `docs/PATTERNS.md`, `docs/PROCESS-FLOWS.md:709-768`, `docs/DEFENSE-TRACEABILITY.md:75-80`, `CLAUDE.md:447-455, 551-579`

Focused verification attempted:

    php artisan test --compact --filter='(WarehouseMapHashBindingTest|WarehouseScanTest|TransferOrderRaceRegressionTest|StockCountCancelRegressionTest|StockCountMovementFreezeTest|CycleCountWacTest|StockAdjustmentReasonTest|StockAdjustmentDoubleApproveRaceTest|StockLevelOptimisticLockTest|ZoneGuardTest)'

Result: exit code 2; 42 tests failed during setup with 0 assertions because PostgreSQL host `db` could not be resolved (`SQLSTATE[08006]`). PHPUnit also emitted existing doc-comment metadata deprecation warnings. The test result does not establish whether the product assertions pass.

## Current-worktree changes that address prior findings

- The normal map and bin-detail paths now derive occupancy from `stock_levels` rather than `warehouse_locations.current_*` (`WarehouseMapService.php:23-43, 51-59`; `WarehouseMapResource.php:82-94`). `WarehouseMapHashBindingTest.php:41-69` exercises the authoritative-data seam.
- Stock movement writes now use deterministic affected-level locking and bounded transaction retries (`StockMovementService.php:62-77, 255-271`), and count record/complete/cancel plus transfer execute/cancel re-read state under locks (`StockCountService.php:136-185, 188-256`; `TransferOrderService.php:57-106`).
- Warehouse route middleware and FormRequest authorization now use `inventory.warehouse.manage` consistently (`routes.php:72-85`; warehouse requests `authorize()` methods).
- Primary stock-adjustment entry now validates and submits `reason_code` (`StoreStockAdjustmentRequest.php:32-43`; `create.tsx:24-32, 124-126`), and adjustment approval re-reads WAC under lock (`StockAdjustmentService.php:283-293, 194-215`).

These changes reduce the prior risk but do not close the findings below.

## Findings

### M040-F02 — Scanner actions still generate unusable WMS deep links

Classification: Broken. Priority: high.

`BarcodeScanResolverService` still places raw numeric location and count-item IDs into action parameters (`api/app/Modules/Inventory/Services/BarcodeScanResolverService.php:52-72`). `WarehouseScanController` copies those values into URLs (`WarehouseScanController.php:48-72`), while production hash binding rejects raw numeric IDs. The map consumes only `view` and never selects `location_id` (`spa/src/pages/warehouse/map.tsx:46-70, 72-85`); the count page does not consume `count_item_id` (`spa/src/pages/warehouse/stock-count.tsx:60-85`). A scan can therefore 404 or land on an unrelated generic context.

### M040-F03 — Count scope is optional and can silently widen

Classification: Broken. Priority: high.

The count endpoint accepts nullable `warehouse_id` and `zone_id` for every scope (`api/app/Modules/Inventory/Controllers/StockCountController.php:68-82`). `createSession()` applies a predicate only when the matching ID is present; a warehouse or zone scope without its ID therefore selects every active location (`StockCountService.php:63-74`). It also does not prove that a supplied zone belongs to the supplied warehouse. The UI flattens all zones instead of filtering by the selected warehouse (`spa/src/pages/warehouse/stock-count.tsx:313-327`). The movement freeze query has the same broad `warehouse_id IS NULL` branch (`StockMovementService.php:347-363`), so an incorrectly scoped session can freeze more stock than the operator selected.

### M040-F04 — Concurrent count starts still have no shared location claim

Classification: Broken. Priority: high.

`startSession()` locks each session row and then checks an existing in-progress session, but it does not lock or uniquely claim the shared locations before changing the status (`StockCountService.php:108-129`). Two draft sessions containing the same location can both observe no overlap and become in progress. The database uniqueness constraint is only within one session (`api/database/migrations/0160_create_stock_count_sessions_table.php:33-50`), not across active sessions.

### M040-F05 — Completion still permits incomplete counts and stores a non-monetary variance value

Classification: Broken. Priority: high/financial.

`completeSession()` explicitly skips pending items and still marks the session completed (`api/app/Modules/Inventory/Services/StockCountService.php:188-238`). A session with zero generated items can also complete. Its `variance_value` is accumulated from absolute quantity variance (`StockCountService.php:200-211, 232-238`) even though the field is decimal money and the documented inventory/GL flow is value-based. The same method uses PHP floats for variance and tolerance comparisons (`StockCountService.php:202-218`). The UI offers completion while pending items remain and describes automatic adjustments without showing a blocked state (`spa/src/pages/warehouse/stock-count.tsx:213-221, 235-242, 393-401`).

### M040-F06 — Count approval is not segregated from counting or completion

Classification: Broken. Priority: high/RBAC.

Count, variance approval, and completion all use `inventory.stock_count.manage` (`api/app/Modules/Inventory/routes.php:112-120`). `approveVariance()` accepts a user but never checks a distinct approval permission or maker/checker relationship (`StockCountService.php:169-185`). The seeded warehouse role has the same manage permission used for all three operations (`api/database/seeders/RolePermissionSeeder.php:624-632`). The documented supervisor sign-off requirement (`docs/DEFENSE-TRACEABILITY.md:75-80`) is therefore not enforced by the service boundary.

### M040-F07 — Transfer-order creation still accepts orders that cannot execute

Classification: Broken.

The canonical transfer route uses an inline `Request` and validates existence/quantity only (`api/app/Modules/Inventory/Controllers/TransferOrderController.php:46-64`). `TransferOrderService::create()` inserts the pending row without checking same source/destination, active/blocked locations, or source availability (`TransferOrderService.php:41-55`). The movement boundary rejects identical locations only when execution is attempted (`StockMovementService.php:284-290`), leaving an unexecutable pending order. The stricter `StoreStockTransferRequest` is a separate unused request (`StoreStockTransferRequest.php:30-38`).

### M040-F08 — Negative reservation and release quantities remain accepted

Classification: Broken.

`StockMovementService::reserve()` and `release()` do not validate that `$quantity` is positive (`api/app/Modules/Inventory/Services/StockMovementService.php:380-423`). A negative reserve decreases `reserved_quantity`; a negative release increases it. Both mutations can therefore corrupt availability and reservation history without changing physical stock.

### M040-F09 — Stock valuation still crosses the decimal boundary into floats

Classification: Broken. Priority: financial.

`StockLevel` casts decimal quantity/WAC to PHP floats for both available quantity and total value (`api/app/Modules/Inventory/Models/StockLevel.php:46-56`), and the resource exposes those computed values (`StockLevelResource.php:26-31`). Map status calculations also convert quantity and capacity to floats (`WarehouseMapResource.php:56-63`). The repository convention forbids float money arithmetic (`CLAUDE.md:447-455`). High-precision quantities or costs can round API values incorrectly, and the adjustment threshold is also read through `SettingsService::requiredFloat()` (`StockAdjustmentService.php:311-318`).

### M040-F11 — Lot suggestions are not an authoritative lot balance or allocation

Classification: Broken. Priority: high/traceability.

The new summary service improves the old location projection, but `preferredLot()` reconstructs a net lot balance from movement history and ignores movements without a lot (`api/app/Modules/Inventory/Services/StockLocationSummaryService.php:50-115`). Picking chooses one preferred lot per location and can allocate more than that lot's net balance when mixed lots share a bin (`PickingListService.php:121-200`). Transfers similarly copy only the single preferred lot into the movement (`StockMovementService.php:108-114`). There is no persisted lot-balance or pick-allocation row that ties requested quantity to a lot.

### M040-F12 — Picking execution is missing

Classification: Missing. Priority: high.

The WMS route exposes only a read-only picking list (`api/app/Modules/Inventory/routes.php:129-130`; `PickingListService.php:23-41`). The page's “Record picks” action navigates to a material-issue detail page and does not reserve, pick, confirm, or transition any pick line (`spa/src/pages/warehouse/picking.tsx:156-169`). There is no M040 mutation or auditable picking state.

### M040-F13 — Soft-delete restore endpoints cannot bind deleted records

Classification: Broken.

Warehouse, zone, and location models use `SoftDeletes` (`api/app/Modules/Inventory/Models/Warehouse.php:15-18`; `WarehouseZone.php:16-18`; `WarehouseLocation.php:17-20`). Restore routes use ordinary implicit binding (`api/app/Modules/Inventory/routes.php:75, 80, 85`), and controllers call `restore()` only after binding (`WarehouseController.php:64-67, 88-91, 114-117`). Deleted rows are excluded before those methods run, so restore normally returns 404.

### M040-F14 — Hierarchy edits can move stocked locations without a ledger event

Classification: Broken.

Zone and location requests permit changing `warehouse_id`/`zone_id` (`api/app/Modules/Inventory/Requests/StoreWarehouseZoneRequest.php:27-34`; `StoreWarehouseLocationRequest.php:26-35`). `WarehouseService` updates those parents transactionally but checks neither stock/reservations nor active counts/transfers (`WarehouseService.php:67-93`). Delete checks only positive quantity (`WarehouseService.php:75-101`). Reparenting a stocked location can change count scope, map ownership, and reporting without a stock movement or transfer audit event.

### M040-F15 — Active/blocked/capacity controls are still incomplete

Classification: Incomplete.

Warehouse tree/map queries include inactive warehouses, zones, and locations (`api/app/Modules/Inventory/Services/WarehouseService.php:22-29`; `WarehouseMapService.php:23-30`). `WarehouseLocation` exposes only `zone_id`, code, rack, bin, and `is_active` as fillable; the map's `is_blocked`, `blocked_reason`, and `capacity_kg` fields have no M040 management request (`WarehouseLocation.php:26-31`; `StoreWarehouseLocationRequest.php:26-35`). Generic movement/transfer paths reject quarantine/scrap consumption but do not consistently reject inactive or blocked locations (`StockMovementService.php:308-329`). Capacity is compared directly with quantity even though the item's UOM may not be kilograms (`WarehouseMapResource.php:56-63`); this remains an open policy question.

### M040-F17 — WMS lists and frontend contracts remain incomplete

Classification: Incomplete.

Transfer orders and count sessions return unbounded collections (`api/app/Modules/Inventory/Services/TransferOrderService.php:22-27`; `StockCountService.php:28-33`). The TypeScript count union still includes `frozen`, which is absent from the backend enum, while the backend retains a legacy `completed` transfer status that the frontend type omits (`spa/src/types/warehouse.ts:41-44, 96-126`; `api/app/Modules/Inventory/Enums/StockCountSessionStatus.php:7-21`; `TransferOrderStatus.php:7-22`). Map bin detail, active count detail, transfer detail, and picking detail have no dedicated error/retry state (`spa/src/pages/warehouse/map.tsx:116-120`; `stock-count.tsx:71-75`; `transfers.tsx:56-60`; `picking.tsx:29-33`).

### M040-F18 — Cycle-count adjustments still omit structured reason coding

Classification: Incomplete.

The primary adjustment form now sends `reason_code`, but cycle-count reconciliation calls `recordAdjustment()` with `null` (`api/app/Modules/Inventory/Services/StockAdjustmentService.php:66-88, 251-278`). The resulting adjustment has free text only, despite `StockAdjustmentReason::CycleCountVariance` existing (`api/app/Modules/Inventory/Enums/StockAdjustmentReason.php:14-31`).

### M040-F19 — WMS frontend still violates the opaque-surface and responsive-density rules

Classification: Polish.

The map legend/status classes use translucent variants (`spa/src/pages/warehouse/map.tsx:21-35`), and map, count, transfer, and picking grids use unprefixed `col-span-*` values (`map.tsx:202-233, 307`; `stock-count.tsx:176-202`; `transfers.tsx:119-147`; `picking.tsx:53-76`). Picking and status panels also use `bg-surface/50`, `bg-elevated/30`, and similar opacity classes (`picking.tsx:104-128`). These conflict with the opaque-surface and responsive rules in `docs/DESIGN-SYSTEM.md` and make floor/tablet workflows harder to scan.

### M040-F20 — Picking read access is broader than its seeded permission

Classification: Broken. Priority: RBAC.

The backend picking-list route requires only `inventory.view` (`api/app/Modules/Inventory/routes.php:129-130`), and the SPA route does the same (`spa/src/routes/inventoryRoutes.tsx:83-84`). The page merely disables the button when `inventory.picking.view` is absent (`spa/src/pages/warehouse/picking.tsx:17-20, 156-169`). Roles with inventory read access but without the dedicated picking permission can still retrieve picking data.

### M040-F21 — Lifecycle transitions are ad hoc rather than a single state-machine contract

Classification: Incomplete.

Stock-count and transfer services write enum values directly (`api/app/Modules/Inventory/Services/StockCountService.php:52-60, 126-129, 232-238, 250-255`; `TransferOrderService.php:44-53, 79-83, 100-104`). Guards are duplicated per method and there is no `StateMachine`/`TRANSITIONS` contract for either workflow. `TransferOrderStatus` also retains a legacy `completed` case (`api/app/Modules/Inventory/Enums/TransferOrderStatus.php:7-22`) that is not a target of the current service. This permits status/API drift and makes future terminal-state changes unsafe.

## Open policy decisions

- Decide whether a count must include empty bins and unexpected items, and whether `variance_value` is quantity or monetary value. If monetary, choose the WAC source and decimal precision.
- Decide whether warehouse locations may be blocked/inactive for count, quarantine, scrap, transfer, or receiving, and whether capacity is measured in kilograms or item base UOM.
- Decide whether picking is a reservation, an intermediate pick event, or material-issue execution, and define lot allocation semantics.
- Decide whether legacy warehouse projection columns are removed or retained as a separately maintained read model; the current map is ledger-backed but lot reconstruction remains historical inference.

## Audit conclusion

M040 remains not ready for production sign-off. The previous map-projection and several concurrency/contract seams are improved in the current worktree, but scanner navigation, count scope/closure/segregation, reservation arithmetic, valuation precision, lot traceability, picking execution, restore behavior, and picking RBAC remain open. The first plan items require explicit warehouse/count/picking policy decisions; see `action-plan.md`.

---

# M040 — Warehouse & Stock Control — Re-audit 2026-09-01 (IN PROGRESS)

Claimed: `audit/scripts/claim-module.sh inventory warehouse-stock-control` → **RECLAIMED**
(stale lock 153h old from the 2026-08-25 session).

## Prior-session assessment (pre-probe)

The 2026-08-25 report **self-flags its own verification as unusable**: its focused run
produced "exit code 2; 42 tests failed during setup with 0 assertions because PostgreSQL
host `db` could not be resolved (`SQLSTATE[08006]`)". Per the pipeline's own guidance,
many failures with **zero assertions** means the database was gone, not that the code was
broken — so none of M040-F02 … M040-F21 has ever been runtime-verified. This session
re-derives each by probe.

`fix-log.md` states no application source was changed on 2026-08-25, and
`git log -- api/app/Modules/Inventory/` confirms the newest Inventory commit is
`276f2e3e` (the neighbouring goods-receiving session's fix), so the working tree at
claim time is unmodified relative to that report.

## Baseline (real, measured)

```
docker compose run --rm --no-deps -e DB_DATABASE=ogami_test_stock api \
  php artisan test tests/Feature/Inventory --no-coverage
→ Tests: 169 passed (557 assertions)   exit code 0   67.76s
```

Non-zero assertions is the proof the run was real. **The 2026-08-25 "42 failures" were
entirely a database-connectivity artifact; the module's existing suite is green at HEAD.**

## HTTP coverage vs service-only coverage (the decisive distinction)

Grepping every HTTP call in `api/tests/` for `/api/v1/inventory/*` gives only six in-scope
paths with *any* HTTP-level test: `POST /scan/resolve`, `GET /stock-levels`,
`GET /stock-movements`, `GET /stock-adjustments`, `PATCH /stock-adjustments/{id}/approve`,
`GET /warehouse-map/bins/{location}`.

**Uncovered at the HTTP layer (26 endpoints):** all 9 `stock-counts*`, all 5
`transfer-orders*`, `POST /stock-adjustments`, `GET /warehouse-map`, all 12
warehouse/zone/location CRUD + restore routes, `GET /picking-lists/mis/{mis}`,
`GET /scan/options`, `GET /stock-movements/options`, `GET /stock-adjustments/options`.

Probing those uncovered endpoints produced **three HTTP-reachable 500s** (N1, N2, N3
below) and the three dead restore routes. This is the same pattern the neighbouring
`goods-receiving` module found.

## `Rule::exists()->where(col, false)` sweep

`grep -rn "Rule::exists" app/Modules/Inventory/` → **no instance** of the broken shape in
this module. `StoreGrnRequest.php:57` already carries the fix comment from the
`goods-receiving` session. Repo-wide, the only `->where(col, <bool>)` instances pass
`true` (CRM price-agreement/sales-order requests, `Quality/AddNcrActionRequest.php:36`),
which `formatWheres()` renders as `"1"` — accepted by PostgreSQL. Nothing to report
outside this module.

## Prior findings: how many reproduce

| Prior finding | Verdict by probe |
|---|---|
| M040-F02 scanner deep links unusable | **Reproduces** (raw ids in actions; no page reads the params) |
| M040-F03 count scope silently widens | **Reproduces** over HTTP |
| M040-F04 no shared location claim across sessions | **Reproduces** with two real connections |
| M040-F05 completion permits incomplete counts; non-monetary variance | **Reproduces** (both halves) |
| M040-F06 count approval not segregated | **Reproduces** (self-approve = 200) |
| M040-F07 transfer order creates unexecutable orders | **Reproduces** |
| M040-F08 negative reserve/release accepted | **Reproduces** (reserved went negative, then above on-hand) |
| M040-F09 valuation crosses into floats | **DISPROVED as a correctness defect** — see below |
| M040-F11 lot suggestions not authoritative | Reproduces by reading; not re-derived (policy-gated) |
| M040-F12 picking execution missing | **Reproduces** (read-only route only) |
| M040-F13 restore endpoints cannot bind deleted records | **Reproduces** — all three 404 |
| M040-F14 hierarchy edits move stocked locations | Reproduces by reading; not runtime-probed |
| M040-F15 active/blocked/capacity incomplete | Reproduces by reading |
| M040-F17 WMS list/type contract gaps | **Reproduces** (`frozen` SPA-only, `completed` backend-only) |
| M040-F18 cycle-count adjustments omit reason code | **Reproduces** (`reconcileStockCountItem` passes `null`) |
| M040-F19 opacity/responsive polish | Reproduces by reading |
| M040-F20 picking read access broader than its permission | **Reproduces, and worse** — the permission is enforced nowhere |
| M040-F21 ad-hoc lifecycle transitions | Reproduces by reading |

**17 of 18 reproduce; 1 disproved.**

### M040-F09 — DISPROVED as a correctness defect (downgraded to Polish)

`StockLevel::getAvailableAttribute()` / `getTotalValueAttribute()`
(`api/app/Modules/Inventory/Models/StockLevel.php:47-56`) do use PHP floats, so the
convention violation is real. But the claimed consequence — "high-precision quantities or
costs can round API values incorrectly" — **does not occur at any representable value**.
Measured at the columns' maximum magnitude:

```
quantity 999999999.999  ×  weighted_avg_cost 9999.9999
  API total_value  = "9999999899990.00"
  bcmath exact     =  9999999899990.00     ← identical
qty 0.005 × wac 0.0005 → API "0.00", exact 0.00 (unrounded 0.00000250) ← identical
```

`numeric(15,3) × numeric(15,4)` needs at most ~17 significant digits and IEEE-754 double
carries ~15–17, which is why the product survives. Keep the finding as a convention/Polish
item; do **not** carry it as a financial defect.

### The headline invariant HOLDS

On-hand equals the independent SQL sum of movements, exactly, through a full lifecycle
(2 receipts, issue, transfer, delivery, adjustment-out, adjustment-in):

```
loc A: stock_levels.quantity = 82.000   SQL SUM(in) - SUM(out) = 82.000   wac 11.4634
loc B: stock_levels.quantity = 30.000   SQL SUM(in) - SUM(out) = 30.000   wac 10.6667
```

The dev database *does* show 12 of 13 (item, location) pairs divergent, but the shape
proves it is **seeded opening stock, not a runtime defect**: items 2–12 have *zero*
movement rows and levels in an exact arithmetic progression (374, 511, 648 … +137), and
the single pair created wholly through the application (item 13 / location 73) reconciles
at 82.000 = 82.000. Reported as an opening-balance data-integrity observation, not as a
stock-control defect.

## NEW findings

### N1 — `POST /stock-counts/items/{id}/count` returns 500 on an ordinary count · Broken · P0

`variance_percent` is `numeric(8,2)` (max 999999.99) but
`StockCountService::recordCount()` computes it unbounded
(`api/app/Modules/Inventory/Services/StockCountService.php:150-152`). Any count where
counted/system exceeds ~10,000 overflows the column:

```
system_quantity 0.500, counted_quantity 6000
→ SQLSTATE[22003]: Numeric value out of range: numeric field overflow   → HTTP 500
```

No malicious input is required — a bulk item whose system record has drifted to a
fraction is enough. The endpoint has **zero HTTP test coverage**, which is why a 500 on
the primary data-entry action of the whole stock-count workflow went unnoticed.

### N2 — Same endpoint returns 500 on scientific notation · Broken

`counted_quantity` is validated `required|numeric|min:0`
(`api/app/Modules/Inventory/Controllers/StockCountController.php:117`). `numeric` admits
`1e3`, which then reaches bcmath:

```
counted_quantity=1e3   → ValueError: bcsub(): Argument #1 ($num1) is not well-formed → 500
counted_quantity=1e17  → same
```

### N3 — `POST /transfer-orders` returns 500 on large input and silently truncates precision · Broken

`quantity` is validated `required|numeric|min:0.001`
(`api/app/Modules/Inventory/Controllers/TransferOrderController.php:62`):

```
1.999    → 201, stored 1.999          ok
1e3      → 201, stored 1000.000       accepted silently
10.00005 → 201, stored 10.000         4th/5th decimal silently discarded
1e17     → 500  SQLSTATE[22003] numeric field overflow
1e20     → 500  SQLSTATE[22003]
```

`StoreStockAdjustmentRequest.php:38-39` already uses the hardened
`decimal:0,3` / `decimal:0,4` form and correctly answers 422 for all of these — the two
`Request`-validated controllers above simply never adopted it.

### N4 — Stock movements are mutable and hard-deletable after they have moved stock · Broken · P0

The ledger has no observer and no database trigger. Measured:

```
movement created: 100 units in, stock_levels.quantity = 100.000
$movement->quantity = '9999'; $movement->save();   → succeeded (row now 9999.000)
DELETE FROM stock_movements WHERE id = ...          → succeeded
stock_levels.quantity after both                    → still 100.000
```

Editing or deleting a movement leaves `stock_levels` untouched, so on-hand and the ledger
diverge silently and permanently — and there is no void/reversal surface for a movement,
so an operator's only route is exactly this destructive one. This is the same exposure
`journal-ledger` closed with an observer **plus** a PostgreSQL `P0001` trigger, and the
same shape `goods-receiving` reported for hard-deletable accepted GRNs.

**Not a blanket fix:** `StockMovementService::stampLot()`
(`StockMovementService.php:449-462`) and `MovementGlPostingService::markManual()/
markGenerated()` legitimately update a movement after creation, so immutability must be
column-scoped (as `journal-ledger`'s trigger is), not row-scoped.

### N5 — The designated adjustment CHECKER cannot see what it is approving · Broken · RBAC

Seeded reality (`api/database/seeders/RolePermissionSeeder.php:554, 664`) matches
OGAMI-012: `inventory.adjust` → warehouse_staff (maker), `inventory.adjust.approve` →
finance_officer (checker). Separation is genuinely enforced — the maker's self-approve is
403. But finance_officer's **only** inventory permission is `inventory.adjust.approve`:

```
maker (warehouse_staff) create                  → 201 pending
maker self-approve                              → 403   separation enforced
checker GET /stock-adjustments?status=pending   → 403   cannot see the queue
checker GET /stock-adjustments/options          → 403
checker GET /stock-levels                       → 403   cannot judge the adjustment
checker GET /warehouse-map                      → 403
checker PATCH /stock-adjustments/{id}/approve   → 200   works only if handed the hash id
```

`GET /stock-adjustments` requires `inventory.view` (`routes.php:94`) and the SPA route is
guarded on `inventory.view` too, so the checker cannot load the page at all. The approval
step is reachable only out of band. This is the "approval chain impossible to complete"
class seen in three sibling modules.

The service also has **no `requested_by !== approved_by` check**
(`StockAdjustmentService::approve()`, `StockAdjustmentService.php:179-219`), so the
separation rests entirely on the permission split. `system_admin` holds both and can
self-approve — measured.

### N6 — OUTSIDE-MODULE BLOCKER: `migrate:fresh --seed` fails at HEAD

Not mine, not fixed, reported:

```
docker compose run --rm --no-deps -e DB_DATABASE=ogami_seedcheck api \
  php artisan migrate:fresh --seed --force
→ SQLSTATE[P0001]: Posted journal entry lines are immutable.
  CONTEXT: PL/pgSQL function prevent_posted_journal_line_mutation() line 19
  SQL: insert into "journal_entry_lines" (...) values (1, 1, 1, 50000, 0)
  at database/seeders/ComprehensiveDemoSeeder.php:634 (seedJournalEntries)
```

`ComprehensiveDemoSeeder` inserts lines into an already-`posted` journal entry, which the
`journal-ledger` module's new immutability trigger correctly refuses. Reproduced on a
throwaway database independently of any test of mine. Consequence for this audit: probes
could not use `$seed = true`; they seed only `RolePermissionSeeder`, which is enough for
role/permission truth. The throwaway database was dropped.

### N7 — Dead surfaces, both directions

- `GET /api/v1/inventory/warehouses` (`routes.php:71`) — wrapper exists
  (`spa/src/api/inventory/warehouse.ts:31` `listWarehouses`) with **no caller anywhere**.
- `spa/src/api/inventory/stock.ts:36-39` `stockTransfersApi.create` POSTs
  `/inventory/stock-transfers`, which is **commented out** at `routes.php:104`; its only
  caller `spa/src/pages/inventory/stock-transfers/create.tsx:56` is itself unrouted.
  Dead the whole way down.
- `/inventory/warehouse` (`spa/src/routes/inventoryRoutes.tsx:63`) is the **only** UI for
  all 12 warehouse/zone/location mutation routes and has **no sidebar entry**; its sole
  entry point is a button at `spa/src/pages/inventory/items/index.tsx:310`.
- Scanner deep links emit query params no page reads:
  `WarehouseScanController.php:70-72` produces `?location_id=`, `?count_item_id=`,
  `?material_issue_id=`; `map.tsx:50,54` reads only `view`, and neither
  `stock-count.tsx` nor `picking.tsx` calls `useSearchParams` at all. (This is the
  reachable half of M040-F02.)
- `inventory.picking.view` is seeded (`RolePermissionSeeder.php:224, 667`) but enforced by
  **no** backend route and no route guard — a dead permission (sharpens M040-F20).
- `docs/PROCESS-FLOWS.md:1578` points operators at `/inventory/warehouses`, which does not
  exist (the route is `/inventory/warehouse`); `:1579` points at
  `/inventory/item-categories`, removed 2026-08-08.
- Stock Count, Transfer Orders, Picking, Warehouse Map and the Scanner — the entire WMS
  surface — are **undocumented** in `docs/USER-MANUAL.md` (§9 is 8 lines covering only
  items/categories/warehouse/movements/issues).

### N8 — SPA mutation buttons are not permission-gated

`usePermission` is never imported in `spa/src/pages/inventory/stock-adjustments/index.tsx`
(Approve button, needs `inventory.adjust.approve`) or
`spa/src/pages/inventory/warehouse/index.tsx` (all create/edit/delete/restore, need
`inventory.warehouse.manage`). Buttons render for any `inventory.view` holder and fail
with 403 on submit. Backend enforcement is correct; this is UX only.

## Invariants executed — measured results

| Invariant | Probe | Result |
|---|---|---|
| on-hand == independent SQL sum of movements | 2 receipts + issue + transfer + delivery + adj-out + adj-in | **HOLDS.** A 82.000=82.000, B 30.000=30.000 |
| dev-DB on-hand vs ledger | SQL over real rows | 12/13 divergent — **seeder opening stock**, not runtime |
| stock cannot go negative via issue | `move()` MaterialIssue > available | `InsufficientStockException` |
| …via adjustment | adjust-out 10 on 0 available | `InsufficientStockException` |
| …via transfer | transfer 10 on 0 available | `InsufficientStockException` |
| …via delivery | Delivery from empty | `InsufficientStockException` |
| two issues racing the last unit | 2 concurrent processes, wall-clock barrier | **exactly one won** (other: insufficient stock) |
| two concurrent adjustments on one row | same | **exactly one won**; `stock_adjustments`=1 |
| issue racing a transfer | same | **exactly one won**; A=0.000 B=10.000, ledger consistent |
| guard is a row lock, not a pre-read | `lockForUpdate()` at `StockMovementService.php:225-249` + races above | **row lock, real** |
| transfer decrement+increment atomic | execute 30 of 100 | A=70.000 B=30.000, single transaction |
| source ≠ destination enforced | `POST /transfer-orders` same location | **NOT at create (201)**; only at execute (422) — M040-F07 |
| in-transit double-counted or invisible | pending order then inspect levels | Neither — pending does not reserve or decrement; stock stays at source |
| cancelled transfer restores both sides | cancel pending, then cancel after execute | pending cancel 204 (nothing to restore); post-execute cancel **422** |
| double execute | execute twice | **422** |
| adjustment recomputes WAC correctly | 100@10 then adj-in 100@90 | wac **50.0000** exact; adj-out leaves it 50.0000 |
| transfer preserves WAC | receipt @10.6172, transfer 40 | destination wac **10.6172** exact |
| negative adjustment at a different cost | adj-out 150 after blend | qty 50.000, wac unchanged — correct |
| unapproved adjustment has not moved stock | gate on, create above threshold | status `pending`, `stock_movements` count **unchanged** |
| maker cannot approve own adjustment | warehouse_staff self-approve | **403** by permission; **no `requested_by!=approver` check in the service** (N5) |
| threshold read from settings | set `inventory.adjustment_approval_threshold`=100 | **yes** — 50×10=500 > 100 → pending |
| count variance posts to GL | `MovementGlPostingTest` (green in baseline) + `postFor()` in the same transaction | **yes** when COA mapped |
| count variance refuses a closed period | closed `accounting_periods` row, then complete | **NO — by design.** `MovementGlPostingService.php:186-197` deliberately catches it and records a replayable `manual_required` handoff so the physical fact survives. Variance applied (100→90), session completed 200. Intended, not a defect. |
| variance not appliable twice | complete twice | second → **422** |
| `Rule::exists()->where(col,false)` | grep + review | **none in this module** |
| money/quantity family, `POST /transfer-orders` | 1.999 / 1e3 / 1e17 / 1e20 / 10.00005 | 201/201/**500**/**500**/201-truncated — **N3** |
| money/quantity family, `recordCount` | 1.999 / 1e3 / 1e17 / 1e20 | 200/**500**/**500**/**500** — **N2** |
| money/quantity family, `POST /stock-adjustments` | 1.999 / 1e3 / 1e17 / unit_cost 10.00005 | 201 / **422** / **422** / **422** — already hardened |
| array/map payload per-value rule | no map/array payload exists in M040's requests | n/a |
| soft-deleted item across 13 aggregates/lists/options | archive item, re-probe all | **no 500s, no 404s** — all 200 |
| soft-deleted location across the same 13 | archive location too | **no 500s**; `bin-detail` correctly 404s |
| soft-deleted movement | movements have no `deleted_at` | n/a — they hard-delete (N4) |
| movement immutable after moving stock | edit + hard delete | **BOTH SUCCEED**, `stock_levels` unchanged — **N4** |
| precision mismatch stock tables vs consumers | `information_schema` over 8 tables | **none** — every stock quantity is `numeric(15,3)`. But `variance_percent` is `numeric(8,2)` and overflows (**N1**) |
| permission gate per endpoint incl. list/options | bare role, 7 endpoints incl. 3 options | **all 403** |
| every registry role completes its part | 6 seeded roles × 7 operations | `system_admin` and `warehouse_staff` complete everything; `production_manager`/`purchasing_officer`/`qc_inspector` view-only (by design); **`finance_officer` 403 on stock-levels, map and the adjustment list it must approve — N5** |
| raw-id-free payloads and error bodies | 5 endpoints + a 422 body | **clean** — no `"id":<pk>`, `"item_id":`, `"location_id":`, `"zone_id":`, `"warehouse_id":` leak |
| restore binds `withTrashed()` | archive warehouse/zone/location, PATCH each restore | **all three 404** — M040-F13 |

## Could NOT verify

- **A real journal entry from a count variance in my own probe** — the COA the mapping
  needs is created by the seeder that is broken at HEAD (N6). Relied instead on
  `tests/Feature/Inventory/MovementGlPostingTest.php`, which is green in my baseline and
  proves adjustment-in/out posting against a seeded COA.
- **M040-F11 (lot balances) and M040-F12 (picking execution)** were not runtime-probed:
  both are blocked on the open policy decisions the prior action plan lists, and probing
  them would not change the answer.
- **M040-F14/F15** (reparenting a stocked location, blocked/capacity policy) confirmed by
  reading only; each needs a policy decision before a probe means anything.

