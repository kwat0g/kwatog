# M039 — Inventory Master audit report

Audit date: 2026-08-24  
Claim: `inventory / inventory-master`  
Registry tier: 3  
Status: `📋 Plan Ready`  
Session recommendation: `separate-recommended`

## Verdict

Production-readiness score: **55/100 — not ready for an unqualified release**.

The module has a substantial, exercised inventory ledger. `StockMovementService` is the central mutation boundary, uses transactions and row locks, enforces weighted-average cost and count freezes, and the existing Inventory feature suite passes. The release risk is at the seams around that ledger: the warehouse map reads a projection that normal movements never maintain, several stale-state write races are not closed, and service capabilities are not reachable through the canonical HTTP/UI contracts.

The findings are broad enough that a safe same-session fix would require changing ledger semantics, projections, concurrency behavior, and API/UI contracts. No production code was changed during this audit.

## Evidence checked

- Registry, dependency chain, atomic claim/release state, module routes, feature middleware, permission middleware, Form Requests, controllers, resources, services, models, migrations, scheduled commands, GL/outbox listeners, and SPA API/types/pages.
- Core paths: receipt/QC, issue/cancel, transfer order, stock movement, adjustment approval, stock count, reservations, MRB/quarantine, WAC, UOM, lot traceability, replenishment, safety stock, warehouse map, and picking.
- `find api/app/Modules/Inventory api/tests/Feature/Inventory -name '*.php' -print0 | xargs -0 -n1 php -l` — passed.
- `php artisan route:list --path=inventory` — passed; 86 inventory routes enumerated.
- `npm run typecheck` in `spa` — passed.
- `DB_HOST=<healthy Postgres container IP> ./vendor/bin/phpunit tests/Feature/Inventory` — passed: **123 tests, 396 assertions**.
- The same suite without the container-IP override failed before assertions because the host process cannot resolve Docker service name `db`; this is a local runner wiring issue, not a module assertion failure.

## Strengths

- Stock movement, WAC, optimistic lock versioning, reservation, quarantine/scrap guards, and count-freeze checks are centralized in `api/app/Modules/Inventory/Services/StockMovementService.php`.
- GRN QC gates, partial acceptance, GL handoff, outbox/reorder listeners, and safety-stock scheduling have explicit service/test coverage.
- Transfer-order execution, adjustment approval, MRB release, and count completion/cancellation already contain several lock-then-guard protections.
- Routes are behind `auth:sanctum` and `feature:inventory`; API identifiers use the project’s hash-ID resource conventions.

## Findings

### M039-F01 — Broken: warehouse map and picking read stale bin state

Priority: **P1**  
Scope: **large**  
Recommendation: **separate-recommended**

`StockMovementService::move()` updates `stock_levels` only (`api/app/Modules/Inventory/Services/StockMovementService.php:104-132`). The warehouse map resource and bin detail instead expose `warehouse_locations.current_item_id`, `current_quantity`, and `current_lot_number` (`api/app/Modules/Inventory/Resources/WarehouseMapResource.php:36-79`, `api/app/Modules/Inventory/Services/WarehouseMapService.php:56-94`). Those legacy projection fields are introduced by `api/database/migrations/0159_enhance_warehouse_locations.php:13-20`, but no normal movement writer maintains them. The SPA renders those values as the bin’s current item/quantity (`spa/src/pages/warehouse/map.tsx:269-281,341-355`). `PickingListService` also takes its displayed lot number from `current_lot_number` (`api/app/Modules/Inventory/Services/PickingListService.php:162-175`).

After a receipt, issue, or transfer, operators can see an empty/old bin while the authoritative stock ledger contains different stock. This is a production correctness issue, not only presentation drift.

Action: choose one authoritative bin projection, preferably derive occupancy and lot suggestions from `stock_levels` plus movement/lot data, or maintain the projection atomically in the movement transaction. Add receipt → issue → transfer API/E2E assertions for map, bin detail, and picking.

### M039-F02 — Incomplete: opposite-direction transfers can deadlock

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

The movement service says affected rows are locked in ID order, but it locks the caller’s source first and destination second (`api/app/Modules/Inventory/Services/StockMovementService.php:65-73`). The count-freeze location query also receives the input order (`api/app/Modules/Inventory/Services/StockMovementService.php:296-307`). Two concurrent transfers A→B and B→A can therefore acquire the same pair in opposite order and hit a PostgreSQL deadlock/transaction abort. The load test is read-only (`api/tests/Load/concurrent-inventory.js`) and no opposite-direction write test exists.

Action: sort all affected location/stock-level keys before locking, define a bounded retry policy for serialization/deadlock errors, and add a two-worker transfer test that proves one cleanly retries or returns a business result without partial ledger rows.

### M039-F03 — Broken: stale material-issue cancellation can reverse stock twice

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

`MaterialIssueService::cancel()` checks status before the transaction, then uses the caller’s potentially stale slip and relation without re-reading/locking the slip inside the transaction (`api/app/Modules/Inventory/Services/MaterialIssueService.php:172-213`). Two stale `Issued` models can both post adjustment-in reversals before either status update prevents the other. The existing `MaterialIssueCancelTest` covers sequential cancellation only (`api/tests/Feature/Inventory/MaterialIssueCancelTest.php:87-94`).

Action: lock and re-read the slip inside the transaction, re-load items under the same transaction, guard the authoritative status, and add a stale-model/concurrent cancel regression test. Preserve a single auditable reversal per slip.

### M039-F04 — Incomplete: pending out-adjustments can use stale WAC at approval

Priority: **P1**  
Scope: **medium**  
Recommendation: **separate-recommended**

Adjustment creation snapshots the current WAC and value (`api/app/Modules/Inventory/Services/StockAdjustmentService.php:123-128`), while approval passes the stored unit cost into the movement (`api/app/Modules/Inventory/Services/StockAdjustmentService.php:194-205`). The normal movement invariant derives issue cost from the locked source level (`api/app/Modules/Inventory/Services/StockMovementService.php:42-44`). If stock receipt/WAC changes while an above-threshold out-adjustment is pending, approval can post a value based on the old WAC and the original threshold snapshot.

Action: decide whether approval means “current WAC” or an explicitly reserved cost. For current WAC, derive it under the movement lock and recalculate/audit the approved value; add a test that changes WAC between create and approve.

### M039-F05 — Incomplete: stock-count writes are not transactionally guarded

Priority: **P2**  
Scope: **medium**  
Recommendation: **separate-recommended**

`recordCount()` and `approveVariance()` read session/item status and update rows outside a transaction/row lock (`api/app/Modules/Inventory/Services/StockCountService.php:148-190`). `completeSession()` locks the session and items (`:193-203`), so a stale count request can pass its earlier in-progress check and write after completion has committed. Existing coverage protects cancel/complete and reconciliation, but not a concurrent record/approve versus complete interleaving (`api/tests/Feature/Inventory/StockCountCancelRegressionTest.php:27-32`).

Action: lock the session and item inside a transaction, re-check status at write time, and reject writes once completion/cancellation wins. Add a concurrency regression test.

### M039-F06 — Missing: service-supported lot/UOM/QC capabilities are unreachable over HTTP/UI

Priority: **P2**  
Scope: **medium**  
Recommendation: **separate-recommended**

The services support receipt UOM, lot/supplier lot, expiry, moisture, and COA fields (`api/app/Modules/Inventory/Services/GrnService.php:148-217`; `api/app/Modules/Inventory/Services/MaterialIssueService.php:79-131`), but the canonical validators omit them (`api/app/Modules/Inventory/Requests/StoreGrnRequest.php:33-46`, `api/app/Modules/Inventory/Requests/StoreMaterialIssueRequest.php:31-44`) and the receive-with-QC controller validation omits the receipt attributes (`api/app/Modules/Inventory/Controllers/GoodsReceiptNoteController.php:151-171`). SPA payloads/forms likewise omit those fields (`spa/src/api/inventory/grn.ts:5-25`, `spa/src/api/inventory/material-issues.ts:10-23`, `spa/src/pages/inventory/grn/create.tsx`, `spa/src/pages/inventory/material-issues/create.tsx`). Direct service tests therefore do not prove the user-facing contract.

Action: either expose and validate the supported attributes end-to-end, including policy/permissions and UI tests, or remove/deprecate the unreachable service contract explicitly. Preserve lot/UOM traceability as a single canonical path.

### M039-F07 — Incomplete: warehouse write permissions disagree between route and Form Request

Priority: **P2**  
Scope: **small**  
Recommendation: **separate-recommended**

Normal warehouse/zone/location write routes use `permission:inventory.items.manage` (`api/app/Modules/Inventory/routes.php:72-84`), while their Form Requests authorize `inventory.warehouse.manage` (`api/app/Modules/Inventory/Requests/StoreWarehouseRequest.php:12-15`, `StoreWarehouseZoneRequest.php:17-20`, `StoreWarehouseLocationRequest.php:16-19`). Seeded `warehouse_staff` happens to carry both permissions, masking the mismatch; least-privilege/custom roles can receive a route denial or Form Request 403 unexpectedly.

Action: select `inventory.warehouse.manage` as the canonical gate for warehouse topology writes, align route and request tests, and verify custom-role behavior.

### M039-F08 — Incomplete: `inventory.allow_negative` is seeded but has no effect

Priority: **P2**  
Scope: **medium**  
Recommendation: **separate-recommended**

The settings seed describes `inventory.allow_negative` as permitting issues below zero (`api/database/seeders/SettingsSeeder.php:275-282`), but no application code reads it. `StockMovementService` always rejects insufficient available stock (`api/app/Modules/Inventory/Services/StockMovementService.php:104-112,238-243`). An operator can therefore change a setting whose stated policy is not honored.

Action: make the policy explicit: remove/retire the setting if negative stock is unsupported, or implement it only at the central movement boundary with role/audit controls, GL implications, and tests.

### M039-F09 — Polish: frontend/API contracts lag the backend resource surface

Priority: **P3**  
Scope: **small**  
Recommendation: **same-session-ok after the semantic fixes**

`StockLevelResource` exposes `lock_version`, but `spa/src/types/inventory.ts:129-138` omits it. The adjustment API/page omit the backend’s structured `reason_code` even though the response type exposes it (`spa/src/api/inventory/stock.ts:26-34`, `spa/src/pages/inventory/stock-adjustments/create.tsx`). GRN and material-issue types also omit the lot/expiry/reservation fields represented by backend services/resources (`spa/src/types/inventory.ts:184-193,248-256`).

Action: regenerate or manually align request/response types after F06’s contract decision, then add a type-level/API fixture check so fields cannot silently disappear.

## Production-audit assessment

### Blockers / high-value risks

1. Warehouse operators cannot rely on the map/picking projection after stock changes (M039-F01).
2. Concurrent opposite transfers can deadlock (M039-F02).
3. Stale material-issue cancellation can duplicate reversal stock (M039-F03).
4. Pending adjustment approval can use a stale valuation basis (M039-F04).

### Evidence still missing

- Production-like browser/API flow proving map, bin detail, picking, and stock ledger agree after receipt, issue, transfer, and lot changes.
- Real concurrent database-worker tests for opposite transfers, material-issue cancellation, stock-count writes, and retry behavior.
- Migration/rollback rehearsal and deployment smoke test against the production-like Postgres configuration.
- A live-data reconciliation report comparing `warehouse_locations.current_*` with authoritative `stock_levels`/movement data.

### Next action

Resolve M039-F01 through M039-F04 in a dedicated inventory-hardening session, then rerun the full Inventory suite plus the new concurrency and browser/API flows before treating this module as release-ready.
