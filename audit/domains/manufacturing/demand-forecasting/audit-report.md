# M048 — Demand Forecasting Audit Report

**Audit date:** 2026-08-24  
**Status:** 📋 Plan Ready  
**Scope:** `manufacturing/demand-forecasting` only

## Executive summary

Demand Forecasting has a coherent backend, API routes, settings, forecast history, manual overrides, accuracy reporting, stock-out projection, MRP projection, and SPA pages. Authentication, module gating, view/manage permissions, hashed resource IDs, forecast-period uniqueness, and transactional per-period forecast writes are present.

The module is not ready to be treated as a reliable planning source. The principal risk is ambiguous forecast scope: total forecasts and customer-specific forecasts can be returned together, displayed as though they were a single total, overwritten by the UI, counted together in dashboard metrics, and both consumed by MRP. This can inflate material requirements and cause an operator to override the wrong row. MRP also converts every BOM exception into “no BOM”, hiding real defects. Forecast freshness is another operational gap: a batch recompute implementation exists, but no caller or schedule was found; only actual reconciliation is scheduled.

No production code was changed in this session. The findings require domain decisions and/or cross-module changes, so the module is released as **Plan Ready**.

## What was checked

- Backend routes, controllers, services, model, migrations, scheduled command, settings, permissions, and existing Forecasting tests.
- SPA routes, API client/types, demand, accuracy, and stock-out pages, dashboard panels, sidebar discoverability, and shared table primitives.
- Dependency behavior was read where needed for evidence (`BomService`, sales-order status, shared API error handling); dependency modules were not audited or modified.

## Findings

### M048-001 — Broken — forecast scope is ambiguous and unsafe

`DemandForecastController::index` only adds a customer predicate when a customer hash decodes successfully; an omitted customer therefore returns both total rows and customer-specific rows (`api/app/Modules/Forecasting/Controllers/DemandForecastController.php:44-66`). The demand page sends an omitted customer for its default “All customers (total)” selection (`spa/src/pages/forecasting/demand.tsx:114-121`, `296-305`), does not show customer scope in the table (`spa/src/pages/forecasting/demand.tsx:440-519`), and maps rows by period so a later row can replace another row in the chart (`spa/src/pages/forecasting/demand.tsx:193-213`). Manual override submits the selected filter scope rather than the row’s scope (`spa/src/pages/forecasting/demand.tsx:171-191`).

The same ambiguity reaches dashboard totals: `DemandForecastPanel` requests the unscoped list and sums/counts all returned rows (`spa/src/components/dashboard/DemandForecastPanel.tsx:39-44`, `111-141`). Operators can see mixed scopes as one series and update the wrong forecast row; dashboard variance and count metrics can be overstated.

**Recommendation:** define an explicit total-vs-customer scope contract. Make total scope default to `customer_id IS NULL`, expose customer scope in every row/chart/edit surface, and make overrides target the immutable row scope. Add endpoint and UI tests for both scopes.

### M048-002 — Broken — MRP projection can double-count demand

`ForecastMrpService::project` filters active, included, positive forecasts for the target period but does not restrict `customer_id` to `NULL` (`api/app/Modules/Forecasting/Services/ForecastMrpService.php:40-50`). Customer-specific and total forecast rows can therefore both contribute to gross material requirements. The stock-out projection explicitly uses `whereNull('f.customer_id')` for its next-month forecast (`api/app/Modules/Forecasting/Services/StockOutProjectionService.php:77-89`), which is evidence that total-scope consumption is the intended planning behavior but is not applied consistently.

This is a cross-module planning correctness risk: the same demand may be represented once at total level and again by customer, inflating component requirements.

**Recommendation:** decide and enforce the canonical MRP scope, preferably total-only unless customer forecasts are guaranteed mutually exclusive. Add a regression test that creates both rows and asserts one contribution.

### M048-003 — Broken — MRP hides real BOM and infrastructure failures

The MRP service catches `\Throwable` around BOM explosion and treats every exception as `has_bom=false` (`api/app/Modules/Forecasting/Services/ForecastMrpService.php:55-67`). `BomService::explode` has a distinct `MissingBomException` for the expected no-BOM case, while other BOM structure/runtime failures are possible (`api/app/Modules/Manufacturing/Services/BomService.php:249-265`). Database or programming failures can therefore be reported as ordinary missing BOMs, producing incomplete requirements without an actionable error.

**Recommendation:** catch only the expected missing-BOM exception, preserve structured per-product errors for known BOM problems, and let infrastructure/programming failures surface to monitoring. Add tests for missing BOM, malformed BOM, and service failure separately.

### M048-004 — Missing/Incomplete — forecast generation is not operationally fresh

`ForecastingService::recomputeBatch` contains batch logic but no caller was found (`api/app/Modules/Forecasting/Services/ForecastingService.php:146-181`). The only Forecasting scheduler found runs actual reconciliation monthly (`api/app/Console/Commands/ReconcileForecastActuals.php:10-19`, `api/routes/console.php:308-314`). The exposed recompute endpoint processes one product/customer/horizon at a time (`api/app/Modules/Forecasting/Controllers/DemandForecastController.php:124-171`).

Forecasts can become stale unless an operator or external process repeatedly invokes the endpoint. There is no documented ownership, cadence, retry behavior, or freshness indicator in the module.

**Recommendation:** define the forecast cadence and source-of-truth policy, then wire a queued/scheduled batch job with locking, retries, observability, and a last-success/freshness indicator. This should be a separate operational change.

### M048-005 — Incomplete — forecast mutations fail silently in the SPA

Recompute, MRP-toggle, and manual-override mutations define success handlers but no error handlers (`spa/src/pages/forecasting/demand.tsx:126-151`, `171-191`). The shared client deliberately does not toast 422 responses and suppresses mutation 5xx toasts for page-owned handling (`spa/src/api/client.ts:81-103`, `223-255`). Several read queries also only render errors for the main forecast/history requests; product, customer, settings, options, and accuracy failures are not surfaced (`spa/src/pages/forecasting/demand.tsx:227-251`).

Validation, permission, conflict, and server errors can leave the operator believing a recompute or override succeeded. The page also invalidates only the list after recompute, not all affected forecast/history/accuracy/dashboard queries (`spa/src/pages/forecasting/demand.tsx:138-151`).

**Recommendation:** add visible mutation error states, preserve server validation messages, disable/reconcile stale controls during pending work, and invalidate every affected query key. Add UI tests for 422, 403, and 5xx responses.

### M048-006 — Incomplete — manual override lacks explicit business audit context

Manual forecast writes validate and persist quantity, confidence, method, and `created_by`, but the model has no override reason or explicit updating actor field (`api/app/Modules/Forecasting/Controllers/DemandForecastController.php:187-219`, `api/app/Modules/Forecasting/Models/DemandForecast.php:44-64`). The schema stores forecast values and creator but no domain reason/history field (`api/database/migrations/0157_create_demand_forecasts_table.php:21-40`). Existing audit logging may record technical changes, but the workflow does not require the business explanation an approver would need.

Also, overriding a row that already has actuals does not visibly reset or preserve a clearly defined reconciliation state (`api/app/Modules/Forecasting/Services/ForecastingService.php:187-231`). This makes historical variance interpretation dependent on implicit update behavior.

**Recommendation:** define override semantics for unreconciled and reconciled periods; require reason and actor attribution, preserve before/after values, and expose the history to authorized users. Treat this as a separate business/audit change.

### M048-007 — Incomplete — accuracy metrics can be optimistic or scope-mixed

Accuracy only considers rows with non-null actuals and `actual_quantity > 0` (`api/app/Modules/Forecasting/Services/ForecastingService.php:286-328`). Zero-actual periods are excluded rather than reported as a defined zero-demand case, and the query has no customer-scope restriction. If total and customer rows are present, both scopes can enter the same accuracy result. The by-product endpoint also loads every active product and calls the accuracy query per product (`api/app/Modules/Forecasting/Controllers/ForecastAccuracyController.php:30-50`), creating an unbounded N+1 pattern.

**Recommendation:** define zero-demand accuracy treatment and scope selection, show sample/omitted-period counts, and replace the per-product loop with a bounded aggregate or paginated query. Add tests for zero actuals and duplicate scopes.

### M048-008 — Incomplete — pagination is discarded and large lists are unbounded in practice

The API paginates demand forecasts with a maximum of 500 rows (`api/app/Modules/Forecasting/Controllers/DemandForecastController.php:44-66`), but the SPA client returns only `r.data.data` and discards pagination metadata (`spa/src/api/forecasting.ts:16-27`). The demand table has no pagination or “load more” behavior (`spa/src/pages/forecasting/demand.tsx:440-519`). The dashboard panel likewise requests the list without a page contract and summarizes the returned subset (`spa/src/components/dashboard/DemandForecastPanel.tsx:39-44`, `111-141`).

Operators may see only the first page while believing the table or dashboard is complete; the accuracy-by-product endpoint is separately unbounded (`api/app/Modules/Forecasting/Controllers/ForecastAccuracyController.php:30-50`).

**Recommendation:** make pagination explicit end-to-end, show total/loaded counts, and use bounded server-side aggregation for dashboard cards.

### M048-009 — Missing — MRP projection has no frontend workflow

The backend exposes MRP projection and inclusion routes (`api/app/Modules/Forecasting/routes.php:55-61`), but the SPA API client has no projection method and the advanced Forecasting routes only mount demand, stock-out, and accuracy pages (`spa/src/api/forecasting.ts:16-92`, `spa/src/routes/advancedRoutes.tsx:23-33`). The inclusion toggle is present on the demand page, but there is no UI for reviewing projected gross/net material requirements or the errors generated by projection.

**Recommendation:** either provide a discoverable, permission-appropriate MRP projection screen with loading/error/empty states or explicitly document the endpoint as an integration-only contract. Do not expose a partial planning control without the result review workflow.

### M048-010 — Incomplete — stock-out “Create PR” is not a prefilled handoff

Every stock-out row links to the generic purchase-request creation route without item, quantity, date, or reason parameters (`spa/src/pages/forecasting/stock-out.tsx:164-167`). The target creation page initializes its own empty form and does not read URL state (`spa/src/pages/purchasing/purchase-requests/create.tsx:51-77`).

The action therefore loses the context that motivated it and forces the user to re-enter the item and quantity, increasing error and abandonment risk.

**Recommendation:** pass a validated item/demand context and prefill the form, or replace the link with an explicit “open purchase request” workflow that preserves the source projection.

### M048-011 — Incomplete — stock-out results include healthy no-demand items

The service skips only items whose calculated days of stock exceed the horizon; items with `days_of_stock` null are retained (`api/app/Modules/Forecasting/Services/StockOutProjectionService.php:141-144`). The page labels the result table “Items at risk” but renders all returned rows (`spa/src/pages/forecasting/stock-out.tsx:105-175`). Dashboard filtering hides `risk=ok`, so the page and dashboard disagree about the result contract (`spa/src/components/dashboard/StockOutPanel.tsx:76-99`).

**Recommendation:** define whether the endpoint returns all monitored items or only risk items, name/filter the page accordingly, and add no-demand coverage tests.

### M048-012 — Polish — Forecasting is not discoverable in the main sidebar

The Forecasting routes are mounted under the advanced route set, but no `forecasting` navigation entry appears in `Sidebar.tsx` (the sidebar sections cover workflow pages such as MRP and purchasing; `spa/src/components/layout/Sidebar.tsx:108-117`, `242-275`). Users can reach forecasting through selected dashboard widgets or a direct URL, but there is no consistent module entry point.

**Recommendation:** add a permission-filtered Forecasting navigation group or a clear link from the relevant role workflows.

### M048-013 — Polish — accuracy sorting is not keyboard/accessibility complete

Accuracy table headers use click handlers and pointer styling but are plain `<th>` elements without keyboard interaction or `aria-sort` (`spa/src/pages/forecasting/accuracy.tsx:243-256`; `spa/src/components/ui/table-cells.tsx:60-71`). The demand page also uses a custom chart whose displayed labels are title-only rather than an accessible data table/description (`spa/src/pages/forecasting/demand.tsx:383-438`).

**Recommendation:** use keyboard-operable sort buttons, expose sort state, and provide an accessible data representation for chart values.

### M048-014 — Hardening — forecast invariants rely mainly on request validation

The forecast schema stores method as a free string and quantities/confidence as decimals, with no visible database checks for supported method, period bounds, or confidence range (`api/database/migrations/0157_create_demand_forecasts_table.php:21-40`; `api/app/Modules/Forecasting/Models/DemandForecast.php:44-64`). API requests validate the main write paths, but direct jobs/imports or future callers can bypass those assumptions.

**Recommendation:** add database/domain-level invariants compatible with the supported database, or centralize strict value objects/enums used by every write path. Add migration and service-level tests.

### M048-015 — Policy question — viewer roles and customer-level data scope need confirmation

Several roles receive `forecasting.view`—including finance, production, PPC, purchasing, and warehouse (`api/database/seeders/RolePermissionSeeder.php:494-629`). The list controller applies no organization/customer ownership or row-level scope beyond an optional filter (`api/app/Modules/Forecasting/Controllers/DemandForecastController.php:44-66`).

This may be intentional for a plant-wide planning module, but customer-specific demand can be commercially sensitive. Confirm whether all those roles may read every customer-specific forecast, and if not, define the row-level policy before expanding the UI.

## Existing safeguards

- All Forecasting routes require Sanctum authentication and the Forecasting feature flag; view and write routes are separated by permission (`api/app/Modules/Forecasting/routes.php:16-61`).
- Per-period compute and manual writes use transactions and advisory locks (`api/app/Modules/Forecasting/Services/ForecastingService.php:85-135`, `187-231`, `388-409`).
- Forecast uniqueness for nullable customer scope has a dedicated Postgres remediation migration and tests (`api/database/migrations/0470_enforce_demand_forecast_nullable_customer_uniqueness.php:24-60`, `api/tests/Feature/Forecasting/ForecastUniquenessTest.php:19-74`).
- Existing tests cover basic accuracy, permissions, toggle behavior, uniqueness, historical defaults, and hash IDs, but not the scope/MRP/error/UI flows above (`api/tests/Feature/Forecasting/ForecastAccuracyTest.php:39-159`, `ForecastMrpToggleTest.php:36-92`, `HistoricalDemandOptionalParamTest.php:39-65`, `StockOutProjectionHashIdTest.php:16-26`).

## Verification notes

- `git diff --check` was clean for the audited paths.
- `php artisan test --filter='Forecast' --colors=never` could not reach assertions: 19 tests failed during database setup because host `db` could not be resolved (`SQLSTATE[08006] [7] could not translate host name "db" to address`).
- The targeted SPA Vitest command could not start because Vite could not write `spa/node_modules/.vite-temp` (`EACCES`). This is an environment/permissions blocker, not evidence of a product assertion failure.
