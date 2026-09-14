# Audit: forecasting — 2026-09-06

## Summary

Small, tidy module (12 backend files, 3 SPA pages, 19 passing tests) with one
genuine demand-integrity defect at the forecast→MRP seam: the advisory MRP
projection sums **all** forecast rows for a month regardless of customer scope,
so a product that has both the all-customers total row and per-customer rows
gets its demand double-counted — inflated gross requirements and phantom
shortages with no visible warning. Second real problem: `compute()`/Recompute
silently overwrites manual overrides, inverting the documented "manual
overrides computed" semantics. There is no status/version lifecycle at all —
every saved row is immediately planning-visible, and past periods can be edited
retroactively (MAPE gaming, stale `variance`). SPA has a chart-ordering bug
(unpadded month keys) and mutations without error handling. Decimals are
served as floats, violating the string-decimal convention. Dashboard-side
forecast widgets are correctly permission-gated (no cross-module defect).
Test run: `--filter='Forecast'` → 19 passed / 0 failed.

## Findings

| ID | Category | Sev | Effort | Title | Location | Evidence/Repro |
|---|---|---|---|---|---|---|
| FC-01 | Broken process | Critical | S | MRP projection double-counts demand when total + per-customer rows coexist | api/app/Modules/Forecasting/Services/ForecastMrpService.php:42-50 | Query filters only on product flags + year/month + qty>0; no customer-scope filter. `recomputeBatch(perCustomer:true)` writes per-customer rows AND the NULL-total row (ForecastingService.php:166-169); SPA allows per-customer manual overrides plus total recompute for the same product-month. Both rows summed → gross requirement up to 2×; same product also listed twice in `products[]`. Contrast: StockOutProjectionService.php:84 correctly uses `whereNull('f.customer_id')` |
| FC-02 | Broken process | High | S | Recompute silently overwrites manual overrides | api/app/Modules/Forecasting/Services/ForecastingService.php:104-135 | `compute()` does `updateOrCreate` unconditionally — no check for existing `method = manual`. Model docblock (DemandForecast.php:23) promises manual "overrides any computed value"; the reverse happens. One Recompute click erases a PPC override with no warning, no UI indication, no test coverage |
| FC-03 | Gap | Medium | L | No status/version lifecycle for forecasts | api/app/Modules/Forecasting/Models/DemandForecast.php:44-64, migration 0157 | No draft/approved/obsolete status, no versioning. Every saved row is instantly demand-visible on the MRP seam; a stale forecast can only be retired by overwriting (0 is the only "retire" value). Edit-after-consumption semantics undefined |
| FC-04 | Risk | Medium | S | Past-period overrides enable retroactive MAPE gaming; leave stale `variance` | api/app/Modules/Forecasting/Controllers/DemandForecastController.php:192; Services/ForecastingService.php:212-231 | `storeManual` accepts forecast_year ≥ 2000, so elapsed periods are editable. `updateOrCreate` writes only method/qty/confidence — `actual_quantity`/`variance` remain from the old forecast (stored variance contradicts new qty). `accuracy()` recomputes APE from the edited qty, so editing a past forecast to match actuals erases the error from MAPE; only HasAuditLog deters it |
| FC-05 | Broken process | Medium | S | Demand chart orders Oct–Dec incorrectly (unpadded month key sort) | spa/src/pages/forecasting/demand.tsx:196-212 | Key = `` `${year}-${month}` `` sorted via `localeCompare` → lexicographically "2026-10" < "2026-9"; within a year bars render Jan, Oct, Nov, Dec, Feb, … |
| FC-06 | Bad practice | Low | S | Quantities served as floats, typed as numbers (string-decimal convention violated) | api/app/Modules/Forecasting/Resources/DemandForecastResource.php:23-26; spa/src/types/forecasting.ts:13-16 | `forecasted_quantity`, `actual_quantity`, `variance` cast `(float)`; SPA types `number`. Project law: decimals arrive as strings |
| FC-07 | Bad practice | Low | S | SPA mutations lack onError/toast.error; manual-override modal bypasses RHF+Zod and server-error mapping | spa/src/pages/forecasting/demand.tsx:126-191, 522-573 | `recomputeM`, `manualM`, `mrpInclusionM` define no `onError`; invalid qty (`parseFloat` of junk → NaN → 422) fails silently. Convention requires Zod schema + server error mapping + toast.error |
| FC-08 | Risk | Low | S | `index()` silently drops undecodable hash filters → returns full unfiltered list; SPA ignores pagination | api/app/Modules/Forecasting/Controllers/DemandForecastController.php:48-55; spa/src/api/forecasting.ts:19-27 | Garbage `product_id`/`customer_id` → filter skipped, all rows returned (within permission). `paginate(100)` response consumed as flat array — rows beyond page 1 never shown (needs ~8 yrs of monthly rows per scope, so latent) |
| FC-09 | Bad practice | Low | M | `method` is a string column + class constants, not an enum; inline `$request->validate()` instead of FormRequest | api/app/Modules/Forecasting/Models/DemandForecast.php:34-42; all 4 controllers | Project conventions: enums for all type fields; authorization/validation in FormRequest. Permissions themselves are correctly enforced via route middleware (`forecasting.view`/`manage`); seeder grants manage only to ppc_head/system_admin |
| FC-10 | Bad practice | Low | S | `recomputeBatch()` is dead code | api/app/Modules/Forecasting/Services/ForecastingService.php:146-182 | No callers in app/, routes/, console schedule, or tests (grep-verified). Only cron is `forecasting:reconcile-actuals` (routes/console.php:354) |
| FC-11 | Risk | Low | S | Accuracy metrics count every scope row; SPA chart plots duplicate month labels | api/app/Modules/Forecasting/Services/ForecastingService.php:286-329; spa/src/pages/forecasting/accuracy.tsx:63-71 | Total row + per-customer rows for the same product-month each count as separate "periods evaluated", inflating `periods_evaluated`; monthly chart maps one point per row onto categorical month labels (also mutates the react-query cached array via `.sort`) |
| FC-12 | Risk | Low | M | `reconcileActuals()` loads all candidates and updates row-by-row without a transaction | api/app/Modules/Forecasting/Services/ForecastingService.php:240-278 | Idempotent today, but unbounded `->get()` + N updates grows with table size |

### FC-01 — MRP projection double-counts demand (Critical)

`ForecastMrpService::project()` is the only seam where forecasts enter MRP
planning. It selects every forecast row for the target month whose product is
active and opted in (`include_forecast_in_mrp`), with **no customer-scope
filter**. The data model deliberately allows two overlapping row families for
one product-month: the NULL-customer "total" row and per-customer rows
(uniqueness key is product+customer-or-null+period). Both are easily created
in normal use: the Recompute button with "All customers (total)" writes the
total row, while manual overrides or recomputes with a customer selected write
per-customer rows; `recomputeBatch(perCustomer: true)` explicitly writes both
families (it even appends the total row on purpose, ForecastingService.php:167).
When both exist, gross material requirements are inflated — up to double — and
the same product appears twice in the report's `products` list. The sibling
consumer got it right: `StockOutProjectionService` reads only
`whereNull('f.customer_id')`. Because the projection drives planners toward
purchase requests for long-lead materials, the inflation converts directly into
over-purchasing advice with no visible anomaly. Fix (S): prefer the total row
when present, else sum per-customer rows; assert the invariant in a test.

### FC-02 — Recompute silently overwrites manual overrides (High)

The override workflow is a first-class SPA feature (per-row "Override" button,
modal, `forecasting.manage` gate), and the model documents manual as the value
that "overrides any computed value". But `compute()` — used by both the
Recompute endpoint and any batch path — does an unconditional
`updateOrCreate(method, forecasted_quantity, confidence_level)`. A PPC head who
overrides Sept for a product, then anyone who later clicks Recompute for that
product/customer/horizon, silently loses the override: the row flips back to
`moving_avg`/`weighted_avg` with the computed number. No warning in the API
response, no UI signal beyond the method chip changing, no test covering it.
Fix (S): skip rows whose `method` is `manual` unless an explicit
`overwrite_manual` flag is passed (and surface skipped periods in the
response).

## Cross-module flags

- **CRM unit** — `ForecastingService::historicalDemand()` hardcodes SO status
  strings `'cancelled', 'draft'` (ForecastingService.php:46) instead of
  `SalesOrderStatus` enum values. Values match today, but if CRM adds/renames
  a terminal status (e.g. `closed`, `voided`), those SOs silently count as
  demand history. Low.
- **MRP unit** — informational: the live MRP engine does **not** read
  `demand_forecasts` (grep-verified across MRP/Purchasing/CRM). Forecast→MRP is
  advisory-only via `ForecastMrpService` + the per-product
  `include_forecast_in_mrp` toggle; no PRs are auto-created from forecasts.
  This is by design (documented in the service docblock) and correctly labelled
  "Advisory only" in the SPA.
- **Permission-scoping / Dashboard unit** — checked, **no defect**:
  `ForecastingDashboardService` has no row scoping of its own, but its three
  widget keys declare proper permissions in `DashboardWidgetSeeder`
  (`forecast.headcount` → `hr.employees.view`, `forecast.revenue` →
  `accounting.dashboard.view`, `forecast.defect_rate` → `quality.view`,
  seeder lines 256-258) and are stripped at render by the layout service.
  Note: this dashboard service is unrelated to `DemandForecast` (it forecasts
  headcount/revenue/defect-rate trends).
- **Scope correction** — `spa/src/api/forecasting-dashboard.ts` does not exist;
  only `spa/src/api/forecasting.ts` plus `spa/src/types/forecasting-dashboard.ts`.

## What was NOT checked

- E2E/browser behavior of the three SPA pages (read-only review + unit/feature tests only).
- `BomService::explode()` internals (MRP-owned; consumed as a black box).
- `feature:forecasting` module-toggle middleware implementation.
- Performance of `projectAll()`/`reconcileActuals()` at large table sizes.
- GoldenPathDemoSeeder forecast data interactions.
- Whether the advisory projection's `usort`/`number_format` output is consumed
  by anything besides the SPA page (no other consumers found via grep).

## Code-reading re-audit — 2026-09-14 (56e0d431)

### Scope and status

This is a read-only source re-audit at commit `56e0d431e41d74d422ad81684ff50e0b2b49960e`. I read the required project documentation, the prior Forecasting audit, all files under `api/app/Modules/Forecasting/**`, all three Forecasting pages, the Forecasting API/types, the Forecasting feature tests, and directly relevant MRP, CRM, dashboard, route, settings, seeder, factory, migration, and scheduled-command dependencies.

The prior FC-01 and FC-02 defects are fixed at their original service/API seams. The remaining Forecasting surface still has material scope, lifecycle, precision, error-reporting, and UI integration defects. New findings are FC-13 through FC-18 below.

| ID | Status at this commit | Category | Sev | Effort | Location | Evidence/reproduction |
|---|---|---|---|---|---|---|
| FC-01 | Closed at original MRP seam; adjacent SPA consumer defect remains as FC-13 | Broken process | Critical | S | `api/app/Modules/Forecasting/Services/ForecastMrpService.php:41-68` | The NULL-customer total now wins over customer rows, and customer rows are summed only when no total exists. `ForecastMrpDoubleCountTest` covers both cases. |
| FC-02 | Closed | Broken process | High | S | `api/app/Modules/Forecasting/Services/ForecastingService.php:89-143`; `api/app/Modules/Forecasting/Controllers/DemandForecastController.php:130-181` | Existing manual rows return `null` and are reported in `skipped_manual` unless `overwrite_manual=true`; focused tests cover endpoint and batch behavior. |
| FC-03 | Unresolved | Gap | Medium | L | `api/app/Modules/Forecasting/Models/DemandForecast.php:44-64`; migration `0157_create_demand_forecasts_table.php:21-40` | No draft/approved/obsolete status, version, approval, or retire operation exists. Every row remains immediately visible to consumers, including a forecast after its planning period has passed. |
| FC-04 | Unresolved | Risk | Medium | S | `api/app/Modules/Forecasting/Controllers/DemandForecastController.php:199-207`; `api/app/Modules/Forecasting/Services/ForecastingService.php:218-243` | Manual override accepts elapsed years and updates only method, quantity, and confidence. Existing `actual_quantity` and `variance` survive the edit, while `accuracy()` recalculates MAPE from the new quantity (`ForecastingService.php:319-333`). A past override can therefore change reported accuracy and leave contradictory stored variance. |
| FC-05 | Unresolved | Broken process | Medium | S | `spa/src/pages/forecasting/demand.tsx:202-222` | Chart keys remain unpadded (`2026-9`, `2026-10`) and are sorted with `localeCompare`; October sorts before September. |
| FC-06 | Unresolved and broader than the prior report | Bad practice | Low | S | `api/app/Modules/Forecasting/Resources/DemandForecastResource.php:23-26`; `spa/src/types/forecasting.ts:13-16,43-57`; `api/app/Modules/Forecasting/Services/StockOutProjectionService.php:146-160` | Demand quantities and stock-out quantities are serialized as floats and typed as numbers, despite the project decimal-as-string rule. Binary conversion remains on the API boundary for forecast, actual, variance, available, demand, and suggested quantity. |
| FC-07 | Unresolved | Bad practice | Low | S | `spa/src/pages/forecasting/demand.tsx:127-160,180-200,531-579` | The three mutations still have no `onError`. A 422 from `parseFloat()` producing `NaN`, an invalid confidence, or a server validation failure has no field mapping and no local error toast; the Axios interceptor intentionally does not toast 422 responses. The modal remains local state rather than RHF + Zod. |
| FC-08 | Unresolved and broader than the prior report | Risk | Low | S | `api/app/Modules/Forecasting/Controllers/DemandForecastController.php:44-66,90-115,124-150`; `spa/src/api/forecasting.ts:19-27` | Invalid product/customer hashes are ignored in list, historical, and recompute paths. A malformed `product_id` on list is omitted and returns the permitted unfiltered list; a malformed optional `customer_id` becomes the all-customer scope. The SPA also discards pagination metadata and cannot request a page, so rows after the first page (default 100; the API's 500-row maximum is not pageable by this client) are silently unavailable. |
| FC-09 | Unresolved | Bad practice | Low | M | `api/app/Modules/Forecasting/Models/DemandForecast.php:34-42`; `api/app/Modules/Forecasting/Controllers/DemandForecastController.php:90-207`; `api/app/Modules/Forecasting/Controllers/ForecastMrpController.php:21-38` | `method` remains a string plus constants rather than an enum, and request validation remains inline instead of FormRequests with `authorize()`. Route middleware does enforce the Forecasting permissions, so this is a convention/control-structure defect rather than a current permission bypass. |
| FC-10 | Unresolved | Bad practice | Low | S | `api/app/Modules/Forecasting/Services/ForecastingService.php:156-194` | `recomputeBatch()` still has no production caller in app services, routes, or scheduled commands; only tests invoke it. Its checkpoint-oriented batch path is therefore dead runtime code and can drift from the endpoint path. |
| FC-11 | Unresolved | Risk | Low | S | `api/app/Modules/Forecasting/Services/ForecastingService.php:299-340`; `spa/src/pages/forecasting/accuracy.tsx:62-71` | Accuracy still counts total and customer scope rows as separate periods and emits duplicate month points. The SPA's `.sort()` mutates the React Query cached `monthly` array in place. |
| FC-12 | Unresolved | Risk | Low | M | `api/app/Modules/Forecasting/Services/ForecastingService.php:253-290` | Reconciliation still calls unbounded `get()` and performs one update per row without a transaction/chunking. A large forecast table or mid-run failure produces a partially reconciled batch; the monthly scheduler makes the next retry eventually correct but does not bound memory or provide a reconciliation run/error record. |

### New Findings

#### FC-13 — SPA consumers still combine total and per-customer forecast rows

- Category: Broken process
- Severity: High
- Effort: S
- Location: `api/app/Modules/Forecasting/Controllers/DemandForecastController.php:48-55`; `spa/src/pages/forecasting/demand.tsx:115-123,202-222`; `spa/src/components/dashboard/DemandForecastPanel.tsx:39-42,94-126`
- Status: New, unresolved. FC-01's MRP service fix does not propagate to the other forecast consumers.
- Evidence/reproduction: Create a product-month with a NULL-customer total of 100 and customer rows of 60 and 40. With the page's “All customers (total)” selection, `customer_id` is omitted, so the list endpoint returns all three scopes. The chart's duplicate month key is overwritten by whichever row is iterated last, while `DemandForecastPanel` sums all rows and displays 200 as the product total. The result is either an arbitrary chart value or a double-counted dashboard total, even though the MRP projection itself now correctly reports 100.

#### FC-14 — MRP projection masks every BOM failure as a missing BOM

- Category: Risk
- Severity: High
- Effort: S
- Location: `api/app/Modules/Forecasting/Services/ForecastMrpService.php:70-81`
- Status: New, unresolved.
- Evidence/reproduction: `BomService::explode()` explicitly throws `MissingBomException` for a missing active BOM and can also throw structure, integrity, database, or programming errors. The Forecasting bridge catches `\Throwable` indiscriminately, suppresses all of them, marks `has_bom=false`, omits that product's material requirements, and still returns a successful projection. A database failure or circular/invalid BOM therefore appears to the planner as an ordinary no-BOM product with no visible failure or retry path.

#### FC-15 — “Manual” is offered as a recompute method but silently runs weighted average

- Category: Broken process
- Severity: Medium
- Effort: S
- Location: `api/database/migrations/0395_seed_forecasting_method_catalog.php:11-22`; `spa/src/pages/forecasting/demand.tsx:139-147,322-328`; `api/app/Modules/Forecasting/Controllers/DemandForecastController.php:130-136`
- Status: New, unresolved.
- Evidence/reproduction: The options endpoint returns `moving_avg`, `weighted_avg`, and `manual`. The page renders all three choices, but selecting `manual` maps the recompute payload to `weighted_avg`; the backend does not accept `manual` for recompute. A planner selecting Manual and clicking Recompute receives computed weighted-average rows rather than a manual-entry workflow, with no warning. The separate Override button does not make the method selector honest.

#### FC-16 — Accuracy-by-product endpoint has an unbounded query-per-product pattern

- Category: Risk
- Severity: Medium
- Effort: M
- Location: `api/app/Modules/Forecasting/Controllers/ForecastAccuracyController.php:30-48`; `api/app/Modules/Forecasting/Services/ForecastingService.php:299-310`
- Status: New, unresolved.
- Evidence/reproduction: The endpoint loads every active product, then invokes `accuracy()` once per product. Each invocation issues a separate `demand_forecasts` query and hydrates all matching rows. With 200 active products, this is at least 201 queries plus repeated row materialization; the current tests cover only a small fixture and do not assert query count or bounded loading.

#### FC-17 — Forecast-driven MRP projection is an orphaned backend surface

- Category: Gap
- Severity: Medium
- Effort: M
- Location: `api/app/Modules/Forecasting/routes.php:55-61`; `api/app/Modules/Forecasting/Controllers/ForecastMrpController.php:21-31`; `spa/src/api/forecasting.ts:72-97`; `spa/src/pages/forecasting/demand.tsx:359-370`
- Status: New, unresolved.
- Evidence/reproduction: The API exposes `/forecasting/mrp-projection` and tests exercise `ForecastMrpService`, but `forecastingApi` has no `mrpProjection()` method and the Forecasting pages never request or render that route. The only employee-facing control is a checkbox promising “Include forecast in MRP projections.” A PPC user can opt a product in but cannot inspect the resulting product/material projection from the supported SPA; the route is reachable only through an unrepresented direct API call.

#### FC-18 — Product and customer selectors silently stop at the first 100 records

- Category: Gap
- Severity: Low
- Effort: S
- Location: `spa/src/pages/forecasting/demand.tsx:62-69`; `api/app/Modules/CRM/Services/ProductService.php:59-60`; `api/app/Modules/Accounting/Services/CustomerService.php:46-47`
- Status: New, unresolved.
- Evidence/reproduction: The page requests `per_page: 200`, but both owning services clamp pagination to 100 and the page ignores `meta`/additional pages. In an installation with product or customer 101 onward, those records cannot be selected in the Forecasting screen even though their HashID can be submitted directly.

### Clean areas and changed controls

- HashID handling is consistent on the Forecasting resource, nested product/customer/creator records, MRP products, and stock-out item links (`DemandForecastResource.php:17-39`; `ForecastMrpService.php:83-88`; `StockOutProjectionService.php:146-148`). No raw integer ID exposure was found in the scoped API responses.
- Forecasting routes use Sanctum, the feature gate, and permission middleware (`api/app/Modules/Forecasting/routes.php:16-61`). The three SPA routes are lazy-loaded and wrapped by module and permission guards (`spa/src/routes/advancedRoutes.tsx:7-51`); the application shell supplies the outer auth guard.
- The MRP projection now filters to active, opt-in products and has regression coverage for total-row precedence, per-customer fallback, inactive products, and duplicate product output (`ForecastMrpService.php:41-49`; `ForecastMrpDoubleCountTest.php`; `ForecastMrpToggleTest.php`).
- Forecast uniqueness is now enforced for NULL customer totals on PostgreSQL with a preflight duplicate failure rather than silent deletion (`0470_enforce_demand_forecast_nullable_customer_uniqueness.php:24-60`), and service writes use a PostgreSQL transaction advisory lock (`ForecastingService.php:109-143,407-422`).
- The forecast-to-MRP bridge is explicitly advisory and does not create purchase requests (`ForecastMrpService.php:21-23`). Stock-out projection also deliberately selects total forecast rows only (`StockOutProjectionService.php:77-89`).
- The scheduled actual reconciliation is registered with `withoutOverlapping` and `onOneServer` (`api/routes/console.php:352-358`), and the command does not swallow service exceptions (`api/app/Console/Commands/ReconcileForecastActuals.php:15-19`).

### Verification limits

- No tests, Docker commands, Artisan commands, browser runs, builds, or live database checks were run, as requested. This report is based on code reading and static cross-reference only.
- The current settings values, applied migration state, production row volumes, and live role/module-toggle behavior were not independently verified.
- Browser geometry, responsive behavior, and runtime query/cache behavior remain unverified. The SPA findings above are deterministic from the request parameters and render code, but not browser-executed here.
- `BomService` was inspected sufficiently to identify its explicit exception contract; a full MRP-unit audit remains outside this Forecasting report.

### Explicit no-code-change statement

No application code, migration, test, registry, or roadmap was changed. The only requested write is this appended section in `audit/forecasting.md`; all existing report content above it is preserved.
