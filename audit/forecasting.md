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
