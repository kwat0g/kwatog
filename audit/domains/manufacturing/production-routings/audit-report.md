# M052 — Production Routings Audit

Date: 2026-08-25  
Domain: Manufacturing  
Roles: `system_admin`, `production_manager`, `ppc_head`  
Dependencies read: `customer-product-pricing`, `inventory-master`, `quality-inspection-specifications`  
Audit status: `📋 Plan Ready`  

## Scope and method

This audit covers the production-routing backend, API contract, frontend list/editor, routing permissions, persistence constraints, and the routing-to-BOM/work-order boundaries. Dependency modules were read for contracts and downstream behavior only; they were not audited or modified.

The three passes were Discovery, Hardening, and Polish. Findings are classified as `Broken`, `Missing`, `Incomplete`, or `Polish` and include the relevant evidence location.

## Discovery

### D-01 — Core routing surface exists and is wired

The module has a service, controller, request, resources, routing/product-routing models, migrations, API client/types, list page, editor, and five API routes. The routes are behind Sanctum, the production feature gate, and the expected view/manage permission checks (`api/app/Modules/Production/routes.php:17-23,76-81`). The route-list verification confirmed the same middleware chain.

Hash IDs are resolved in the request and serialized in the resources (`api/app/Modules/Production/Requests/StoreRoutingRequest.php:22-28`, `api/app/Modules/Production/Resources/ProductRoutingResource.php:14-28`, `RoutingOperationResource.php:14-36`). This is a positive control.

### D-02 — Dedicated routing test coverage is missing [Missing]

There is no dedicated routing feature/service test suite. Existing `api/tests/Feature/MRP/BomCostingTest.php` coverage exercises downstream costing but does not establish routing CRUD, version activation, operation ordering, resource compatibility, or authorization behavior. The repository search found no production-routing test file.

The focused BOM test command could not execute because the configured test database host `db` did not resolve (`SQLSTATE[08006] could not translate host name "db"`). This is an environment blocker, not a test pass or a product result.

## Hardening findings

### H-01 — More than one active routing version can exist [Broken]

`ProductionRoutingService::create()` always creates a new active version without deactivating prior versions (`api/app/Modules/Production/Services/ProductionRoutingService.php:54-91`). `duplicate()` deactivates only the selected source, not every other active version (`ProductionRoutingService.php:150-187`). The database only guarantees unique `(product_id, version)`, not one active row per product (`api/database/migrations/0255_create_product_routings_table.php:11-25`).

Both major consumers select the first active row without an explicit version policy: work-order generation (`api/app/Modules/Production/Services/WoOperationService.php:37-72`) and BOM costing (`api/app/Modules/MRP/Services/BomCostingService.php:148-170`). With multiple active rows, work instructions and costing can depend on query order rather than a business-defined routing.

### H-02 — Updating a routing destroys operation definitions referenced by history [Broken]

`update()` permanently force-deletes all existing operations before recreating them (`api/app/Modules/Production/Services/ProductionRoutingService.php:108-140`). Existing work-order operations retain a nullable foreign key with `nullOnDelete()` (`api/database/migrations/0257_create_wo_operations_table.php:13-35`), and generation copies routing-operation IDs into work-order history (`api/app/Modules/Production/Services/WoOperationService.php:52-70`). An edit can therefore null the provenance link for previously generated work, even though the copied display values remain.

For an IATF/traceability-sensitive production definition, referenced operations need immutable versioning or an explicit conflict rule. A normal edit must not silently sever historical provenance.

### H-03 — Resource validation permits deleted, inactive, incompatible, or unusable assignments [Incomplete]

The request validates only integer existence for the product, machine, and mold (`api/app/Modules/Production/Requests/StoreRoutingRequest.php:31-52`). It does not constrain `is_active`/`deleted_at`, product-to-mold ownership, machine/mold compatibility, or current resource status. Product, machine, and mold are soft-deletable models, while machine and mold have operational status enums (`api/app/Modules/CRM/Models/Product.php`, `api/app/Modules/MRP/Models/Machine.php`, `api/app/Modules/MRP/Models/Mold.php`; `api/app/Modules/MRP/Enums/MachineStatus.php`, `api/app/Modules/MRP/Enums/MoldStatus.php`).

The work-order assignment guard already rejects wrong-product molds, non-idle/running machines, unavailable molds, and incompatible pairs (`api/app/Modules/Production/Services/WorkOrderService.php:732-745`). Routing creation/update has no equivalent guard, and work-order generation copies the selected assignments. A routing can therefore be saved successfully and fail or produce unusable work instructions later. `StoreBomRequest` demonstrates the missing active/non-deleted `Rule::exists` pattern (`api/app/Modules/MRP/Requests/StoreBomRequest.php:33-48`).

### H-04 — Operation sequence uniqueness and numeric bounds are not enforced [Incomplete]

The request requires a positive sequence but does not require distinct sequence values (`api/app/Modules/Production/Requests/StoreRoutingRequest.php:34-46`), and the operation table has no `(routing_id, sequence)` uniqueness constraint (`api/database/migrations/0256_create_routing_operations_table.php:11-28`). Downstream previous-operation logic uses sequence comparisons (`api/app/Modules/Production/Services/WoOperationService.php:321-335`), so duplicates make ordering and completion gates ambiguous.

Cycle and rate inputs are accepted as unconstrained `numeric` values. The service uses BCMath and downstream costing uses string-based `Money` arithmetic, so there is no direct float violation observed; however, the request does not define maximum precision/scale consistent with the decimal columns. Canonical decimal validation is needed to prevent database rounding and inconsistent rate data.

### H-05 — Version allocation is race-prone and has no typed conflict path [Incomplete]

Create and duplicate compute `max(version) + 1` without locking the product or serializing concurrent requests (`api/app/Modules/Production/Services/ProductionRoutingService.php:58-64,152-166`). The unique index can turn a concurrent request into a raw database exception. The application maps `BusinessRuleException` to a client-safe 422, while database/query failures are not a substitute for a domain conflict response (`api/bootstrap/app.php:85-104`).

The transaction boundary is correct for the current writes (`ProductionRoutingService.php:56-91,110-140,152-187`), but it does not by itself enforce the active/version invariant under concurrency.

### H-06 — Routing changes recalculate BOM costing but do not visibly replan dependent MRP state [Missing]

After create/update/duplicate, the service recalculates the active BOM (`api/app/Modules/Production/Services/ProductionRoutingService.php:191-200`). The routing affects operation time and labor/machine/overhead rates, while pending production planning and capacity consumers can depend on those values. The service has no durable outbox/event or explicit MRP replan call. BOM changes use a separate automatic-replan path (`api/app/Modules/MRP/Services/BomService.php:164-174`), but routing changes do not show an equivalent cross-module side-effect contract.

The expected behavior—immediate replan, queued replan, or explicit stale marker—needs to be made transactional/idempotent and covered by tests.

### H-07 — Routing definition audit/lifecycle behavior is incomplete [Incomplete]

`ProductRouting` and `RoutingOperation` do not use the repository's audit-log trait even though routing definitions alter costing and future work instructions (`api/app/Modules/Production/Models/ProductRouting.php:1-41`, `RoutingOperation.php:1-58`; compare `api/app/Common/Traits/HasAuditLog.php:13-24`). The later shared migration adds `deleted_at` columns to these tables (`api/database/migrations/0444_add_soft_deletes_to_all_tables.php:11-63`), but the models do not use `SoftDeletes`, there is no delete/archive route, and active-state transitions are not exposed as a typed lifecycle operation.

The product decision should be explicit: immutable version history with deactivation, or an auditable archive lifecycle. The current combination exposes neither complete history nor a supported archive workflow.

### H-08 — Production-manager routing access conflicts with the documented role boundary [Broken]

The permission catalog contains `production.routings.view` and `production.routings.manage` (`api/database/seeders/RolePermissionSeeder.php:248-265`). The `production_manager` role receives the complete production module permission set through `module('production')` (`RolePermissionSeeder.php:530-554`, helper at `713-722`), which includes routing manage. The documented browser-test role boundary says production managers are view-only for the relevant master/planning areas, while `ppc_head` owns routings (`docs/AUTO-BROWSER-TESTS.md:114-120`).

This is a policy mismatch requiring an explicit role-matrix decision, then synchronized seeder, UI, and browser-test coverage. As written, a production manager can manage routing definitions despite the documented boundary.

## Frontend and Polish findings

### F-01 — The routing search control is dead [Broken]

The list page writes the search term as `search` through `FilterBar` (`spa/src/pages/production/routings/index.tsx:107-110`). `FilterBar`'s contract also emits `search` (`spa/src/components/ui/FilterBar.tsx:20-40,46-57,71-81`). The API type advertises a separate `product_search` parameter (`spa/src/api/production/routings.ts:5-9`), but `ProductionRoutingService::list()` only handles `product_id` and `is_active` and never reads either search field (`api/app/Modules/Production/Services/ProductionRoutingService.php:27-48`). Users see a search box that does not filter products.

### F-02 — View-only users can see manage actions, while the detail route rejects view-only access [Broken]

The list requires `production.routings.view`, but the `/:id` editor route requires `production.routings.manage` (`spa/src/routes/productionRoutes.tsx:17-55`). The backend show endpoint is correctly view-gated (`api/app/Modules/Production/routes.php:76-81`), so the frontend has no read-only detail path. The list always renders New routing, Duplicate, and the editable row action without a permission check (`spa/src/pages/production/routings/index.tsx:40-96`).

A view-only user can enter the list, see actions that will be denied, and cannot open the backend-supported detail view. This also conflicts with the role distinction documented for production manager and PPC roles.

### F-03 — Operation descriptions have no editor control [Missing]

The form schema/reset state includes `description`, and the payload sends it (`spa/src/pages/production/routings/editor.tsx:111-148`), but the operation table renders no input or textarea bound to `operations.${i}.description` (`editor.tsx:244-415`). New descriptions cannot be entered; the field is an invisible API-only value.

### F-04 — Master-data lookups are capped and have no visible loading/error/empty handling [Incomplete]

Products, machines, and molds are each fetched with `per_page: 100` and without search or pagination (`spa/src/pages/production/routings/editor.tsx:67-78`). The selects do not expose lookup failures or a “more results/search required” state. Larger master datasets will make valid products/resources unselectable or leave an apparently empty control.

### F-05 — The wide operation editor clips instead of providing a horizontal-scroll affordance [Polish]

The editor's twelve-column operation table is inside a wrapper using `overflow-hidden` (`spa/src/pages/production/routings/editor.tsx:247-415`). The shared `DataTable` uses `overflow-x-auto` for wide tables (`spa/src/components/ui/DataTable.tsx:424-425`), but the routing editor does not. Verify at a 375px viewport and provide horizontal scrolling or a responsive row layout while preserving keyboard/focus behavior required by the design system (`docs/DESIGN-SYSTEM.md:435-447,523-532`).

### F-06 — List filtering does not expose the backend's active-state filter [Incomplete]

The backend accepts `is_active` (`api/app/Modules/Production/Services/ProductionRoutingService.php:41-43`), but the list UI exposes only the nonfunctional search input and no active/inactive filter (`spa/src/pages/production/routings/index.tsx:100-112`). This makes inactive historical versions difficult to find and inspect.

## Positive controls verified

- All routing writes currently run inside `DB::transaction()` (`api/app/Modules/Production/Services/ProductionRoutingService.php:56-91,110-140,152-187`).
- Money-like routing rates are represented as decimal strings/BCMath in the service and downstream `Money`/BCMath costing path; no direct float arithmetic was found (`api/app/Modules/MRP/Services/BomCostingService.php:173-201`, `api/app/Common/Support/Money.php:7-15`).
- API route authorization, feature gating, Hash ID handling, and resource serialization are present as described above.
- Backend module files and migrations pass `php -l`; the SPA typecheck and targeted ESLint invocation pass.

## Audit decision

No implementation fixes were made in this session. The active-version invariant, historical provenance, role policy, and routing-to-MRP side effects are cross-module/high-risk changes; they require a separate implementation session with focused tests. The module is ready for an ordered remediation plan and should remain `📋 Plan Ready`.
