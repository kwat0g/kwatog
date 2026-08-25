# M039 — Inventory Master fix log

Audit date: 2026-08-24  
Implementation session: 2026-08-25  
Module status: `✅ Verified`  
Implementation status: **All planned findings addressed**

## Fixes

### M039-F01 — authoritative bin state

- Before: map, bin detail, and picking read the legacy `warehouse_locations.current_*` projection while normal movements changed only `stock_levels`.
- After: `StockLocationSummaryService::summarize()` / `preferredLot()` derive occupancy and FEFO/FIFO lot suggestions from the ledger (`api/app/Modules/Inventory/Services/StockLocationSummaryService.php:26-105`). `WarehouseMapService` and `WarehouseMapResource` use that summary (`api/app/Modules/Inventory/Services/WarehouseMapService.php:22-43,52-90`; `api/app/Modules/Inventory/Resources/WarehouseMapResource.php:34-92`), and picking uses the same lot source (`api/app/Modules/Inventory/Services/PickingListService.php:126-199`).
- Verification: stale legacy projection regression in `api/tests/Feature/Inventory/WarehouseMapHashBindingTest.php:41-68` passed.

### M039-F02 — transfer lock ordering and retry

- Before: dual-location movement locking acquired source then destination in caller order, allowing opposite transfers to deadlock.
- After: affected stock levels are deduplicated and locked by numeric location ID (`api/app/Modules/Inventory/Services/StockMovementService.php:62-77,255-269`); frozen-location locks are ordered too (`:333-340`); the movement transaction retries bounded concurrency failures (`:185`).
- Verification: transfer, movement, optimistic-lock, and freeze regressions passed in the full Inventory suite.

### M039-F03 — material-issue cancellation idempotency

- Before: cancellation trusted a stale slip and eager-loaded item relation outside the transaction.
- After: cancellation locks and re-reads the slip and all items before status-checking or reversing stock, then refreshes the caller model for correct API serialization (`api/app/Modules/Inventory/Services/MaterialIssueService.php:167-224`).
- Verification: stale cancellation regression proving one reversal (`api/tests/Feature/Inventory/MaterialIssueCancelTest.php:97-123`) and the route regression passed.

### M039-F04 — approval-time WAC

- Before: pending outbound adjustments approved using the WAC snapshot captured at creation.
- After: outbound approval passes no cost into the movement boundary, which derives WAC under the source lock; the approved adjustment cost and value are rewritten from the committed movement (`api/app/Modules/Inventory/Services/StockAdjustmentService.php:179-215,224-242,283-294`).
- Verification: WAC-change-before-approval regression (`api/tests/Feature/Inventory/StockAdjustmentReasonTest.php:102-140`) passed.

### M039-F05 — stock-count write guards

- Before: record and approve writes checked session state without a transaction/row lock.
- After: both operations lock session first, then item, re-check state, and commit the write and progress update atomically (`api/app/Modules/Inventory/Services/StockCountService.php:134-184`).
- Verification: cancelled-session write regression (`api/tests/Feature/Inventory/StockCountCancelRegressionTest.php:128-139`) and the existing count completion/reconciliation suite passed.

### M039-F06 — lot/UOM/QC contract

- Before: service-supported receiving/issue metadata was omitted from canonical validators, resources, SPA payloads, and forms.
- After: receipt UOM, lot, supplier lot, expiry, moisture, COA, issue UOM, and issue lot are migrated, validated, persisted, returned, typed, and editable (`api/database/migrations/2026_08_25_150000_harden_inventory_contracts.php:13-29`; `api/app/Modules/Inventory/Requests/StoreGrnRequest.php:45-52`; `api/app/Modules/Inventory/Requests/FinalizeGrnRequest.php:23-30`; `api/app/Modules/Inventory/Requests/StoreMaterialIssueRequest.php:42-43`; `api/app/Modules/Inventory/Resources/GrnItemResource.php:32-38`; `api/app/Modules/Inventory/Resources/MaterialIssueSlipItemResource.php:29-30`; `spa/src/pages/inventory/grn/create.tsx:234-311`; `spa/src/pages/inventory/material-issues/create.tsx:195-233`). Movement metadata is sent at creation in `api/app/Modules/Inventory/Services/GrnService.php:930-956,1039-1058`.
- Verification: lot traceability and UOM conversion tests passed; migration status is `Ran` in the isolated test database.

### M039-F07 — warehouse permission alignment

- Before: topology write routes required `inventory.items.manage` while their Form Requests required `inventory.warehouse.manage`.
- After: warehouse, zone, and location create/update/delete routes use the canonical warehouse permission (`api/app/Modules/Inventory/routes.php:72-84`), matching the Form Requests.
- Verification: 86 inventory routes enumerated successfully and the full Inventory suite passed.

### M039-F08 — negative-stock policy

- Before: `inventory.allow_negative` was seeded as an apparently configurable policy but was ignored by the central ledger.
- After: the setting is removed from `SettingsSeeder` (`api/database/seeders/SettingsSeeder.php:275-303`) and retired by migration (`api/database/migrations/2026_08_25_150000_harden_inventory_contracts.php:24-29,34-40`); the ledger remains the single no-negative-stock boundary.
- Verification: migration applied successfully and all stock movement regressions passed.

### M039-F09 — frontend/API contract alignment

- Before: SPA types and forms omitted `lock_version`, structured adjustment reason codes, and final lot/UOM/expiry/QC fields.
- After: response/request types and forms expose the fields (`spa/src/types/inventory.ts:137,195-201,240-281`; `spa/src/api/inventory/stock.ts:27-32`; `spa/src/pages/inventory/stock-adjustments/create.tsx:44-125`; `spa/src/pages/inventory/grn/create.tsx:31-40,166-177`; `spa/src/pages/inventory/material-issues/create.tsx:28-52,109-112`).
- Verification: the module changes are type-consistent; a workspace-wide `npm run typecheck` rerun is blocked by an unrelated syntax error in the modified-but-out-of-scope `spa/src/pages/purchasing/purchase-requests/detail.tsx:125`.

## Checks

- `find api/app/Modules/Inventory api/tests/Feature/Inventory -name '*.php' -print0 | xargs -0 -n1 php -l` — passed.
- `php -l api/database/migrations/2026_08_25_150000_harden_inventory_contracts.php` — passed.
- `DB_HOST=172.18.0.3 DB_DATABASE=ogami_m026_test php artisan route:list --path=inventory` — passed; 86 routes.
- `DB_HOST=172.18.0.3 DB_DATABASE=ogami_m026_test ./vendor/bin/phpunit tests/Feature/Inventory --testdox` — passed; **127 tests, 408 assertions**.
- `git diff --check` for the module-scoped files — passed.

## Deferred / external constraints

- No Inventory finding is deferred. The workspace-wide SPA typecheck needs the separate Purchasing syntax error corrected by the owner of that out-of-scope change; this session did not modify it.
