# M033 — Sales Orders Audit Report

Date: 2026-08-25  
Domain: commercial  
Module: sales-orders  
Tier: 2  
Roles: `system_admin`, `customer-portal`  
Dependencies read for context only: `customer-product-pricing`, `auth-session`, `rbac`, MRP, delivery, invoice, and shared chain infrastructure.

## Audit context

The previous report was dated 2026-08-24 and the module was marked `🔁 Needs Re-audit`. The working tree contains substantial, uncommitted sales-order changes after that report, including lifecycle timestamps, route/service changes, SPA changes, and tests. Because those changes can invalidate the earlier findings, this is a fresh discovery → hardening → polish audit of the current source. No dependency module was modified.

## Executive summary

The current implementation has materially improved since the prior audit:

- The unsafe generic transition endpoint is gone; only named lifecycle endpoints remain (`api/app/Modules/CRM/routes.php:50-62`).
- Store, update, confirm, cancel, delete, and downstream lifecycle transitions use transactional service paths and row/reference locking (`api/app/Modules/CRM/Services/SalesOrderService.php:297-366,372-443,449-501,611-779`).
- Money arithmetic uses the repository’s BCMath/string helper instead of floats (`api/app/Modules/CRM/Services/SalesOrderService.php:297-443`, `api/app/Common/Support/Money.php:7-11`).
- Active customer/product validation, incoterm persistence, and delivery-date ordering are present in the write requests/service (`api/app/Modules/CRM/Requests/StoreSalesOrderRequest.php:31-44`, `api/app/Modules/CRM/Requests/UpdateSalesOrderRequest.php:31-44`, `api/app/Modules/CRM/Services/SalesOrderService.php:119-194`).
- Realtime chain invalidation, queued-MRP wording, and failed-QC rendering were addressed (`spa/src/hooks/useChainProgress.tsx:21-68`, `api/app/Modules/CRM/Listeners/NotifyOnSalesOrderConfirmed.php:28-38`, `api/app/Modules/CRM/Services/SalesOrderService.php:785-835`).

The remaining work is concentrated in one compile-breaking SPA edit, recovery/edit-form completeness, portal pagination, confirmation-time reference validation, missing direct route regression coverage, and two issues whose correct fix crosses the module boundary. The cross-module items are recorded and intentionally deferred because this session is constrained to M033.

## Discovery pass

### Surface and route inventory

The module exposes list, detail, chain, create, update, soft-delete, restore, confirm, and cancel routes. The restore route uses `withTrashed()` and the expected CRM permission; the generic transition route is no longer present (`api/app/Modules/CRM/routes.php:50-62`). SPA route access is separately permission-gated for list/create/detail/edit (`spa/src/routes/crmRoutes.tsx:71-78`).

The backend service contains the order lifecycle, pricing resolution, active-reference checks, delivery-date validation, cancellation guards, chain projection, and transition rejection logging (`api/app/Modules/CRM/Services/SalesOrderService.php:44-235,297-835`). The model contains soft deletion, lifecycle timestamps, editable/cancellable predicates, and customer/item/MRP/work-order/delivery/invoice relationships (`api/app/Modules/CRM/Models/SalesOrder.php:17-137`).

The internal SPA has list, create, edit, and detail surfaces. Create and detail expose incoterm and lifecycle/recovery feedback; edit persists no incoterm field in its form; list has table pagination but no archive/restore controls. The customer portal has a sales-order table, but its client adapter currently reduces the paginated API response to an array (`spa/src/api/b2b/customer.ts:82-86`, `spa/src/pages/portal/customer/orders/index.tsx:15-25,54-103`).

### Findings from discovery

#### F-001 — Broken — SPA create page does not compile

The current create page contains literal backslashes before template literals in the confirm failure/success paths (`spa/src/pages/crm/sales-orders/create.tsx:169,188-189`). The baseline `npm run typecheck` fails with `TS1127`, `TS1005`, and `TS1160` errors in this file, including an unterminated template literal. This is a direct regression from the interrupted source changes and blocks frontend verification/building.

#### F-002 — Incomplete — restore is routed correctly but not hardened through the service

The route correctly enables soft-deleted model binding (`api/app/Modules/CRM/routes.php:58-60`), but the controller calls `$salesOrder->restore()` directly without a transaction, row lock, service invariant, or structured business-rule handling (`api/app/Modules/CRM/Controllers/SalesOrderController.php:73-76`). There is no direct restore test in the current CRM feature coverage. The route binding fix is present; the write path still needs the module’s normal service boundary.

#### F-003 — Incomplete — edit form cannot change persisted incoterm

The edit page resets the loaded incoterm into form state (`spa/src/pages/crm/sales-orders/edit.tsx:116-133`) and the backend update path accepts and persists it (`api/app/Modules/CRM/Services/SalesOrderService.php:421-432`), but the rendered edit form contains no incoterm control (`spa/src/pages/crm/sales-orders/edit.tsx:217-234`). Create and detail do expose the field (`spa/src/pages/crm/sales-orders/create.tsx:42-54,281-286`, `spa/src/pages/crm/sales-orders/detail.tsx:231-242`).

#### F-004 — Incomplete — reference activity is checked at create/update but not at confirmation

Store and update validate active, non-deleted customer/product references (`api/app/Modules/CRM/Requests/StoreSalesOrderRequest.php:31-44`, `api/app/Modules/CRM/Requests/UpdateSalesOrderRequest.php:31-44`; `api/app/Modules/CRM/Services/SalesOrderService.php:119-150`). Confirmation locks the order and customer and runs credit checks (`api/app/Modules/CRM/Services/SalesOrderService.php:449-471`), but does not reassert that the customer and item products are still active immediately before changing a draft to confirmed. A draft can therefore remain editable while a referenced master record is deactivated, then be confirmed without the same invariant used by create/update.

#### F-005 — Broken — queued MRP can create downstream work after cancellation

Confirmation records a durable `SalesOrderConfirmed` outbox event after setting the order confirmed (`api/app/Modules/CRM/Services/SalesOrderService.php:476-489`). Cancellation locks and cancels currently visible downstream plans/work orders (`api/app/Modules/CRM/Services/SalesOrderService.php:611-672`). The MRP job can, however, capture confirmed orders as a collection (`api/app/Modules/MRP/Services/MrpEngineService.php:550-575`) and later process a captured order in its own transaction without re-locking/re-reading the sales order status (`api/app/Modules/MRP/Services/MrpEngineService.php:78-91,197-203`). If cancellation wins between those operations, cancellation may see no plan and the queued job may subsequently create an active plan/work orders for a cancelled order. Correcting this requires MRP/job changes outside M033, so it is recorded for coordinated follow-up rather than changed here.

#### F-006 — Incomplete — portal pagination metadata is discarded and no controls are exposed

The backend returns a paginator for customer orders (`api/app/Modules/B2B/Services/CustomerPortalService.php:98-114`; `api/app/Modules/B2B/Controllers/CustomerPortalController.php:63-74`). The SPA API adapter types the response as `{ data: PortalSoSummary[] }` and returns only `data.data`, discarding links/meta (`spa/src/api/b2b/customer.ts:82-86`). The page issues no page/status parameters and renders no pagination or filter controls (`spa/src/pages/portal/customer/orders/index.tsx:15-25,54-103`). Orders beyond the first API page are inaccessible from the portal UI.

#### F-007 — Polish — edit lookup failures are silent

Create disables failed/empty lookups and renders a retryable query error (`spa/src/pages/crm/sales-orders/create.tsx:195-207,229-230`). Edit starts customer/product queries but provides no equivalent loading/error/empty affordance or disabled state on the selects (`spa/src/pages/crm/sales-orders/edit.tsx:84-96,217-257`). A user can see an empty or unusable selector without being told whether the cause is loading, no active records, or a failed request.

#### F-008 — Incomplete — cancellation has two conflicting chain representations

The sales-order chain projection marks cancelled steps as `skipped` and suppresses later steps (`api/app/Modules/CRM/Services/SalesOrderService.php:785-835`). The shared canonical chain maps a cancelled sales order to `closed` (`api/app/Common/Support/ChainDefinitions.php:38-51`), and the broadcaster resolves/broadcasts the canonical definition (`api/app/Common/Services/ChainBroadcaster.php:80-110`). Consumers can therefore receive a cancellation as a closed/completed canonical chain while the sales-order endpoint reports skipped stages. The new lifecycle migration adds nullable timestamps but has no historical backfill (`api/database/migrations/2026_08_25_100000_add_sales_order_lifecycle_timestamps.php:13-20`), so older orders also lack authoritative transition dates. The consistent fix belongs in shared chain semantics and migration/reconciliation work outside this module.

#### F-009 — Missing — direct lifecycle route regression coverage is still absent

The current focused tests cover chain bridging, status transition behavior, chain stages, concurrency for confirm/cancel, pricing, and customer-portal service behavior, but do not provide a direct matrix for store/update/delete/restore, incoterm round-trip, update date rejection, archived detail behavior, confirmation after reference deactivation, cancellation downstream guards, or portal page two. The existing files are `api/tests/Feature/CRM/SalesOrderStatusTransitionsTest.php`, `api/tests/Feature/CRM/SalesOrderLifecycleConcurrencyTest.php`, `api/tests/Feature/CRM/SalesOrderChainBridgeTest.php`, `api/tests/Feature/CRM/SalesOrderChainStageTest.php`, and `api/tests/Feature/B2B/CustomerPortalServiceTest.php`; their current test declarations do not cover the missing route matrix. This leaves the most important invariants dependent on indirect coverage.

#### F-010 — Question / Incomplete — cancellation-reason policy is not explicit

The API request makes `reason` optional with a 500-character limit (`api/app/Modules/CRM/Requests/CancelSalesOrderRequest.php:16-20`), and the detail page provides an optional textarea (`spa/src/pages/crm/sales-orders/detail.tsx:398-415`). If audit/compliance requires a reason for cancellation, this is incomplete; if reasons are intentionally optional, the current behavior is correct. A product/business decision is required before changing the validation contract.

#### F-011 — Question / Incomplete — archive/restore recovery surface is ambiguous

The CRM API client exposes delete and restore methods (`spa/src/api/crm/salesOrders.ts:28-31`), and the backend restore route exists, but the internal list/detail UI has no archive/restore action or archived-order view. It is unclear whether archive/restore is intentionally API/admin-only or whether the system_admin surface is incomplete. This should be decided before adding UI behavior and permissions beyond the current route.

## Hardening pass

The following previous findings were rechecked and are resolved in the current source:

- Illegal arbitrary transitions: the generic transition endpoint is removed; named transition methods enforce `ALLOWED_TRANSITIONS` and record rejections (`api/app/Modules/CRM/Services/SalesOrderService.php:44-58,724-779`).
- Cancellation state restrictions: only draft/confirmed orders are cancellable, and active downstream delivery/invoice/work-order states are guarded (`api/app/Modules/CRM/Models/SalesOrder.php:108-137`, `api/app/Modules/CRM/Services/SalesOrderService.php:201-223,611-672`).
- Stale update/delete/confirm races: update, delete, confirm, and cancel take order locks inside transactions (`api/app/Modules/CRM/Services/SalesOrderService.php:372-443,449-501,611-695`).
- Active/archived references: request and service validation use active scopes, and resources are null-safe (`api/app/Modules/CRM/Requests/StoreSalesOrderRequest.php:31-44`, `api/app/Modules/CRM/Services/SalesOrderService.php:119-150`, `api/app/Modules/CRM/Resources/SalesOrderResource.php:45-48`, `api/app/Modules/CRM/Resources/SalesOrderItemResource.php:16-21`).
- Delivery date ordering: both write requests and service invariants require delivery dates not before order date (`api/app/Modules/CRM/Requests/StoreSalesOrderRequest.php:40-44`, `api/app/Modules/CRM/Requests/UpdateSalesOrderRequest.php:40-44`, `api/app/Modules/CRM/Services/SalesOrderService.php:184-194`).
- Exact money/quantity arithmetic: totals, remaining quantities, and tax use string/BCMath helpers (`api/app/Common/Support/Money.php:7-11`, `api/app/Modules/CRM/Services/SalesOrderService.php:297-443`, `api/app/Modules/CRM/Models/SalesOrderItem.php:47-53`).
- Realtime/query/notification/QC polish regressions: chain query keys are invalidated together, queued MRP is described as queued, and failed QC is normalized/rendered as rejected (`spa/src/hooks/useChainProgress.tsx:21-68`, `api/app/Modules/CRM/Listeners/NotifyOnSalesOrderConfirmed.php:28-38`, `api/app/Modules/CRM/Services/SalesOrderService.php:788-794`).

The new findings F-002, F-004, F-005, F-008, and F-009 are the remaining process/state and verification gaps. F-005 and F-008 require changes outside the module’s scope; F-009 is a separate regression-suite work item rather than an incidental one-line fix.

## Polish pass

The internal sales-order pages generally use the shared semantic tokens, `Panel`, `DataTable`, empty states, and query error patterns described by `docs/DESIGN-SYSTEM.md`. The material remaining polish/completeness issues are F-001 (compile blocker), F-003 (missing edit field), F-006 (portal navigation), F-007 (silent edit lookups), and the policy-dependent F-011 recovery surface. The list’s page-total calculation uses JavaScript `Number` over serialized monetary values (`spa/src/pages/crm/sales-orders/index.tsx:50-52`); this is display-only and lower risk than the backend totals, but should be revisited if exact aggregate display is required.

## Verification evidence

- Focused backend command: `docker compose exec -T api php artisan test tests/Feature/CRM/SalesOrderChainBridgeTest.php tests/Feature/CRM/SalesOrderStatusTransitionsTest.php tests/Feature/CRM/SalesOrderLifecycleConcurrencyTest.php tests/Feature/CRM/SalesOrderChainStageTest.php tests/Feature/CRM/CustomerProductPricingTest.php tests/Feature/B2B/CustomerPortalServiceTest.php` — **52 tests passed, 183 assertions**.
- Frontend baseline: `npm run typecheck` in `spa` — **failed** on F-001 in `spa/src/pages/crm/sales-orders/create.tsx` with `TS1127`, `TS1005`, and `TS1160` syntax errors.
- `git diff --check` also reports trailing whitespace in the same create-page change.

## Disposition

The module is not verified yet. The contained fixes are ready to implement in this session: F-001, F-002, F-003, F-004, F-006, and F-007. F-005 and F-008 require coordinated changes in MRP/shared chain infrastructure and are deferred without modifying those dependencies. F-009 is a larger direct regression-suite item; F-010 and F-011 require explicit product/operations decisions before changing behavior.

## Post-fix verification — 2026-08-25

The following audit findings were rechecked after implementation:

- F-001 is resolved: the create page no longer contains escaped template literals, and scoped ESLint passes.
- F-002 is resolved in M033: restore now uses a soft-delete-aware row lock and transaction in SalesOrderService before returning the resource.
- F-003 and F-007 are resolved in the edit surface: incoterm is rendered and lookup failures/loading/empty states are surfaced with retry affordances.
- F-004 is resolved in M033: confirmation locks the product/customer references and applies the same active-reference invariant used by create/update.
- F-006 is resolved in the portal surface: the client keeps paginator metadata and the page sends page/status parameters and renders shared pagination.
- F-009 is materially addressed by SalesOrderRouteCoverageTest.php, which now covers direct CRUD/restore/incoterm, date ordering, both confirmation reference races, cancellation downstream guard, generic-route absence, named-route permissions, and portal page two. Existing lifecycle/chain/concurrency suites remain green.

The remaining findings are intentionally not marked fixed:

- F-005 still requires MRP execution-time locking/re-read outside M033.
- F-008 still requires shared canonical-chain semantics and historical timestamp reconciliation outside M033.
- F-010 and F-011 still require product/operations decisions.

The focused existing backend suite passed 52 tests/183 assertions; the new M033 route-coverage suite passed 9 tests/32 assertions. Scoped ESLint and PHP syntax checks pass. The full SPA typecheck reports only unrelated diagnostics in HR/accounting/assets code as recorded in fix-log.md; no M033 or portal diagnostics remain.
