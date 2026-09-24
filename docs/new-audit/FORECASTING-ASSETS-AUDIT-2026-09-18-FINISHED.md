# Forecasting + Assets Audit

Date: 2026-09-18
 
## Re-audit 2026-09-19

Historical verdict: **open**.

## Final Status 2026-09-23

Current verdict: **FINISHED** for the engineering scope of this report.

Forecasting remediation is complete: invalid HashIDs fail closed, total/customer
accuracy scopes are collapsed without double-counting, by-product accuracy is batched,
reconciliation is transactionally rerunnable, computed-method options match the API,
forecast generation is scheduled, decimal quantity handling is preserved through the
projection/reporting paths, and only an explicit missing-BOM condition is treated as
an absent BOM. Forecast-driven MRP remains intentionally advisory and does not create
purchase requests; executable forecast demand is a future product-policy decision.
The legacy `/forecasting/accuracy` URL remains a compatibility alias while the SPA
uses `/forecasting/accuracy/summary`, so there is one canonical accuracy contract.

Assets remediation is complete: machine/mold/vehicle association has an authorized
HashID API and one-to-one database enforcement, maintenance work orders maintain the
asset status truthfully, the hidden transfer surface no longer ships routes or
permissions, the QR contract is JSON-only and documented, deployed disposal workflows
are migrated to finance → vice president, depreciation output uses HashIDs, scheduled
depreciation failures are recorded, and the scheduler mutex is aligned with the
request command.

Focused verification on the current worktree: Forecasting and Assets tests passed
(98 tests, 492 assertions). Full-suite and deployed scheduler/queue proof remain
release-level verification outside this artifact.

- Forecasting remains advisory-only for MRP by explicit product-policy disposition; generation is now scheduled, reconciliation is transactionally rerunnable, accuracy scopes are authoritative, and invalid hashes fail closed.
- Assets now expose operational machine/mold/vehicle association, maintenance status transitions, deployment migration for disposal workflows, HashID depreciation responses, and durable failure outcomes.
- Full current classification: `RE-AUDIT-REGISTER-2026-09-19.md`.
Claims are **[confirmed]** (file:line/grep) or **[assumption/unverified]**.

---

## Part A — Forecasting (`api/app/Modules/Forecasting/`)

### Walkthrough
- `ForecastingService::compute()` upserts one (product, customer, year, month); methods
  `moving_avg`, `weighted_avg` (linear recency), `manual`. No exponential smoothing/seasonality.
- `reconcileActuals()` backfills actuals/variance, run monthly (`forecasting:reconcile-actuals`).
- `accuracy()` computes MAPE/bias. MRP integration is **advisory only**: `ForecastMrpService`
  explodes forecasts for products with `include_forecast_in_mrp` and produces a report; it creates
  no PRs and the MRP engine references no forecasts.

### Findings

The following entries preserve the original audit evidence. Their current closure or
policy disposition is recorded in **Final Status 2026-09-23** above.

1. **MRP never consumes forecasts.** The live engine is confirmed-SO only; `ForecastMrpService` is a
   standalone report. `include_forecast_in_mrp` defaults false and only a demo seeder enables it.
2. **`/forecasting/accuracy` and `/forecasting/accuracy/summary` are the same endpoint** (both call
   `accuracy()`).
3. **`byProduct()` calls `accuracy()` per active product** (N queries, no aggregation).
4. **Stock-out projection bridges to inventory by fuzzy `items.code = products.part_number`** and
   hardcodes `'forecast'/'historical'/'none'` strings instead of the `DemandSource` enum; the
   `consumed_30d` alias is stale.
5. **Confidence formula duplicated** in `ForecastingDashboardService` (comment admits it);
   `forecasting.trend_*` settings are read only by the dashboard, not the core service.
6. Dashboard `forecast.*` widgets are independent trend projections that never read `demand_forecasts`.
7. Uniqueness invariant relies on PG-only advisory lock + NULLS-NOT-DISTINCT unique; on sqlite it is
   unenforced (test skipped).
8. No events/listeners; nothing stubbed. Coverage: 29 tests across 7 files but none for
   `reconcileActuals` CLI/`confidenceFromSeries`/weighted-avg math.

---

## Part B — Assets (`api/app/Modules/Assets/`)

### Walkthrough
- `AssetService` create/update/delete/restore; straight-line and 200% declining-balance depreciation.
- `DepreciationService::runForMonth()` posts one consolidated JE per period with an idempotency
  marker, supplemental-JE repair for late acquisitions, prior-period completeness gate,
  backfill/catch-up paths.
- Two-phase disposal: `requestDisposal()` → approval chain (`finance_officer → vice_president`) →
  `approveDisposal()`/`dispose()` posts DR cash/accum/loss, CR cost/gain.
- Monthly automation: scheduled request → outbox → queued listener → job.

### Findings
1. **Cross-module asset links are dead.** `vehicles.asset_id` and `machines.asset_id` exist but are
   not fillable/exposed and have no relation; only demo seeders set them. There is no application
   path to associate an Asset with its machine/mold/vehicle. `Mold.asset_id` is cast but has no
   relation. **[confirmed]**
2. **`AssetStatus::UnderMaintenance` is unreachable** (no service/request sets it) yet is read by
   dashboard widgets and shown in the SPA (always zero).
3. **The asset-transfer stack is hidden.** Routes are commented out (`routes.php:41-55`) but the
   controller/service/model/resource/tests remain, permissions are still seeded and granted to
   finance, and the SPA client still calls the dead URLs.
4. **QR service docblock lies.** `AssetQrCodeService` claims SVG/PNG generation; it returns only a
   JSON payload. No image generation exists.
5. **Stale system_admin references** in disposal comments/routes/role seeder, contradicting the
   exec-tier policy (the chain is actually finance→VP).
6. **Scheduled mutex name mismatch:** `assets:request-monthly-depreciation` is named
   `assets:run-monthly-depreciation` in the lock.
7. Missing configured accounts hard-fail the transaction (`firstOrFail()`); `update()` freezes
   `useful_life_years`/`salvage_value` once depreciation exists. Strong test coverage (55 tests).

## Assumptions
- A1. No test run; source/grep. A2. The transfer hide is documented as intentional; whether it
  should be deleted vs re-enabled is a product call. A3. Account code settings presence per
  environment not verified.
