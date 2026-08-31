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

## Status: probing in progress — findings appended below as measured.
