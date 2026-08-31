# M038 — Supplier Performance Fix Log

## 2026-08-25

This session resumed the existing `📋 Plan Ready` plan. The module's source files
had not changed since the audit report; unrelated dirty-worktree changes in PO
restore routing and quality-spec revision loading did not invalidate the report.

### Fixed

- **F-005 — ranking order:** `api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:220-236`
  now joins vendor names, orders non-null scores first, breaks score ties by
  vendor name, and uses vendor ID as a deterministic final tie-breaker. The
  ranking test covers null scores and equal-score vendor-name ordering at
  `api/tests/Feature/Purchasing/SupplierRankingTest.php:141-159`.
- **F-006 — ranking contract validation and shape:**
  `api/app/Modules/Purchasing/Requests/SupplierRankingRequest.php:11-38` now
  validates period, tier, and limit inputs; the controller consumes only
  validated values and returns row periods plus `meta.count` at
  `api/app/Modules/Purchasing/Controllers/SupplierPerformanceController.php:106-142`.
  Invalid-input and response assertions are at
  `api/tests/Feature/Purchasing/SupplierRankingTest.php:54-83,100-105,161-176`.
- **F-007 — period bounds:** the service validates every compute, batch, and
  ranking entry point at
  `api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:44-46,174-176,214-256`;
  the command rejects non-integers and out-of-range periods at
  `api/app/Console/Commands/RecomputeSupplierPerformance.php:29-61`; and
  `api/database/migrations/2026_08_25_180000_guard_supplier_performance_periods.php:17-65`
  adds database enforcement for the repository's 2000–2100 period convention
  and months 1–12. Service coverage is at
  `api/tests/Feature/Purchasing/SupplierTierTest.php:87-93`.
- **F-008 — deterioration alert link:**
  `api/app/Modules/Purchasing/Listeners/AlertOnSupplierDeterioration.php:59-74`
  now emits the registered `/purchasing/suppliers/{hash}/performance` path;
  the payload assertion is at
  `api/tests/Feature/Purchasing/SupplierDeteriorationTest.php:93-103`.
- **F-012 — ranking frontend:** added the typed API client at
  `spa/src/api/purchasing/supplier-performance.ts:25-33`, response types at
  `spa/src/types/supplierPerformance.ts:51-76`, the permission-gated route at
  `spa/src/routes/purchasingRoutes.tsx:99-116`, and the filterable ranking page
  at `spa/src/pages/purchasing/suppliers/ranking.tsx:39-238`. The existing
  scorecard links to the ranking page at
  `spa/src/pages/purchasing/suppliers/performance.tsx:56-83`.
- **F-013 — trend polish:** the scorecard now uses the configured trend window
  and exposes a semantic period/score/tier table alongside the visual bars at
  `spa/src/pages/purchasing/suppliers/performance.tsx:240-311`; the policy type
  includes `trend_months` at `spa/src/types/supplierPerformance.ts:34-44`.
- **F-014 — regression coverage:** ranking, alert-link, period-boundary, and
  repeated-compute assertions were added at
  `api/tests/Feature/Purchasing/SupplierRankingTest.php:54-176`,
  `api/tests/Feature/Purchasing/SupplierDeteriorationTest.php:93-103`, and
  `api/tests/Feature/Purchasing/SupplierTierTest.php:87-110`.
- **F-015 — first-computation race hardening:** the snapshot write now uses a
  database upsert under the existing transaction at
  `api/app/Modules/Purchasing/Services/SupplierPerformanceService.php:72-118`,
  and the repeated-period invariant is asserted at
  `api/tests/Feature/Purchasing/SupplierTierTest.php:95-110`.

### Verification

- `php -l` passed for all changed PHP implementation, migration, and test files.
- `php artisan route:list --path=purchasing/vendors` confirmed the ranking,
  detail, and recompute API routes.
- Invalid CLI checks passed: `--month=13` and `--month=1.5` both exit with
  status 2 before database access.
- Targeted ESLint and Prettier checks passed for all changed SPA files.
- Full SPA typecheck remains blocked by two pre-existing errors in
  `spa/src/pages/assets/detail.tsx` (`qrcode` is unavailable and `dataUrl` is
  implicitly `any`); it reported no supplier-performance errors.
- The focused Laravel run selected 24 module tests but every case stopped in
  `RefreshDatabase` because PostgreSQL host `db` could not be resolved
  (`SQLSTATE[08006]`). Database-backed fixes are therefore not runtime-verified
  in this environment.

### Deferred — status `🔁 Needs Re-audit`

- **F-001** remains pending until purchasing approves the price-variance
  denominator, receipt allocation, currency, and rounding policy.
- **F-002/F-004** remain pending until quality/purchasing owners define supplier
  attribution for work-order/delivery inspections, mixed coverage, and partial
  acceptance; these require cross-module fixtures and code.
- **F-003** remains pending until PO-level versus receipt-level delivery and
  lead-time semantics are chosen.
- **F-009** remains pending until a stable vendor-period deterioration identity
  and a cross-module notification idempotency boundary are approved.
- **F-010** remains pending until the RBAC decision confirms whether recompute
  is purchasing-officer self-service or admin-only.
- **F-011** remains pending until allowed domains for configurable score policy
  values are approved.
- **F-014/F-015** still need the database-backed focused suite and a real
  concurrent first-computation regression once PostgreSQL is available.

## 2026-09-01 — Re-audit

Lock **RECLAIMED** (orphan lock 118h old, `2026-08-25T12:09:11Z`).

### Environment — verified before any probe

`docker compose ps` showed `ogami-db` (healthy) and `ogami-redis` both **Up**;
`psql -c "select 1"` returned a row. No container was started or restarted.
This is the condition four earlier pipeline sessions lacked, which is why their
verification claims were phantom.

### Real numeric baseline

**29 passed / 0 failed / 69 assertions**, 35.47s, on a dedicated database
`ogami_test_supperf` (never the shared `ogami_test`):

```
docker compose run --rm -e DB_DATABASE=ogami_test_supperf api php artisan test \
  tests/Feature/Purchasing/SupplierRankingTest.php \
  tests/Feature/Purchasing/SupplierTierTest.php \
  tests/Feature/Purchasing/SupplierQualityMetricsTest.php \
  tests/Feature/Purchasing/SupplierDeteriorationTest.php --no-coverage
```

Harness trap recorded for the next session: passing **repo-relative** paths
(`api/tests/...`) makes `artisan test` print `Test file not found` and **exit 0**
— a green run with zero tests. Container-relative paths are required.

### Prior session's work — assessed

The 2026-08-25 fix-log was **accurate and honest**. Its code is committed (swept
into `167de85e`); `git status` over all module paths is clean; every file it
claims exists. It explicitly recorded that its DB-backed verification never ran
(`SQLSTATE[08006]`, host `db` unresolvable) rather than claiming success — the
opposite of the `separation-final-pay` failure mode. **This session is the first
execution of that work, and all of it passes.**

**8 of 15 prior findings now CLOSED** (F-005, F-006, F-007, F-008, F-012, F-013,
plus F-014 partially and F-015's code path). **7 still reproduce** — F-001,
F-002, F-003, F-004, F-009, F-010, F-011 — all knowingly deferred as commercial
or cross-module decisions. No prior fix was falsely claimed.

The F-007 database CHECK constraint was confirmed **present and correct** in a
fresh migration run; it is absent from the long-lived dev `ogami` database only
because that migration has never been applied there (dev drift, NEW-07).

### Fixed — commit `6f357553`

Three items, all measured failing before and passing after. **No scoring
formula, weighting, or definition of "on time" was touched.**

- **NEW-03 — archived POs and PO lines no longer score their supplier.**
  `SupplierPerformanceService.php` — `whereNull('deleted_at')` added to the
  `po_count` query and to `priceVariancePct()` (both `po` and `poi`), and the
  guard moved *into* the JOIN clause for the two `leftJoin`s in
  `onTimeDeliveryRate()` and `leadTimeVarianceDays()` so an archived PO reads as
  "no promised date" and its receipt leaves the ratio, rather than being scored
  late against an order that no longer exists.
  Measured: one soft-deleted, wholly unreceived PO moved `price_variance_pct`
  **0.00 → 50.00** and `po_count` **1 → 2**. After the fix both are unchanged.
- **NEW-04 — archived vendors no longer keep a ranking slot.** `ranking()` gains
  `whereNull('vendors.deleted_at')`, reconciling the raw `leftJoin` with the
  `with('vendor:id,name')` that was already applying the scope.
  Measured: the archived vendor ranked **first** on 95.00 with
  `{"id":null,"name":null}`; ranking returned 2 rows, now 1.
- **NEW-06 — lead-time variance can no longer 500.** New
  `MAX_LEAD_TIME_VARIANCE_DAYS = 999.99` clamp with a `Log::warning`.
  Measured: `SQLSTATE[22003] numeric field overflow` on a raw 2206 days, which
  aborted the entire snapshot; now stores `999.99`. **Score-neutral** — the
  composite floors this component at 0 past 20 days, so clamped and unclamped
  both score `17.50`, and the test asserts that number so the claim cannot rot.

### Deliberately NOT fixed

NEW-01 (the 0-vs-neutral defect), NEW-05 (PO status filter), and the carried
F-001/F-002/F-003/F-004/F-009/F-010/F-011. Each either changes what a score
means or crosses into quality/inventory ownership. NEW-01 in particular moves
every tier boundary, and the brief is explicit that a scoring formula is not this
session's decision.

### Verification

```
BEFORE (baseline):  29 passed / 0 failed / 69 assertions
AFTER  (+ new test): 40 passed / 0 failed / 96 assertions
```

- New regressions proven red against unmodified source: HEAD's service restored
  from `git show HEAD:…`, suite re-run → **5 failed / 6 passed**. The 5 are
  exactly the three NEW-03 cases, NEW-04 and NEW-06; the 6 that stayed green are
  the labelled **pass-either-way locks** (on-time boundary ×3, zero-history
  vendor, tie determinism ×2). The fixed file was then re-applied and proven
  byte-identical with `sha256sum -c` → `OK`.
  *Process note, for honesty:* the temporary revert overwrote the fixed file
  without a backup, so the six edits were re-applied from context; the
  `sha256sum -c` check against the hash taken before the revert is what proves
  the restore is exact.
- `php -l` clean on both files.
- `phpstan analyse` on both changed files → **`[OK] No errors`**.
- `pint --test`: the test file passes. The service fails with 10 fixers, **all
  inherited** — proven by extracting `git show HEAD:…` to a temp path, running
  Pint on it, and diffing the rule lists programmatically: `NEW (mine only): []`.
  The change in fact *removes* one (`class_attributes_separation`).
- No SPA file was modified, so no `npm` checks were required and the
  Prettier `PostToolUse` hook never fired.

### Out-of-module defect found — reported, NOT touched (NEW-08)

The archived-vendor leak fixed here in `ranking()` **also exists downstream in
the Dashboard module**: `PurchasingDashboardService.php:142-143` joins `vendors`
with no `deleted_at` guard, and `DashboardWidgetDataService.php:445` /
`KpiSnapshotService.php:451` both `avg('overall_score')` without excluding
archived vendors, so the "average supplier score" KPI includes retired
suppliers. Outside this module's surface; not modified.

### New findings recorded (see audit-report.md for evidence)

- **NEW-01 (Broken, high)** — `compositeScore()` substitutes **0** for a missing
  `on_time_delivery_rate` or `quality_pass_rate` while honouring the seeded
  `neutral_missing_metric` = 50 for NCR/price/lead-time. The two metrics scored
  as zero carry **60% of the composite**. Both arms are reachable: a vendor whose
  incoming inspection is merely still `draft` loses 35%; a vendor whose POs carry
  no promised date loses 25%. On the repo's own fixture this stamps **tier D**
  where consistent-neutral gives **C** and renormalisation gives **B**.
  *Not fixed — QUESTION-1; changing it moves every tier boundary.*
- **NEW-02 (Incomplete)** — the existing terminal-empty quality test asserts only
  `quality_pass_rate` is null and never reaches `overall_score`, which is why
  NEW-01 survived a green suite. Its comment is also wrong twice.
- **NEW-03 (Broken)** — soft-deleted `purchase_orders` / `purchase_order_items`
  still feed `priceVariancePct()`, `po_count`, and the on-time and lead-time
  joins, because all four reach them via `DB::table()`.
  `goods_receipt_notes` and `inspections` have **no `deleted_at`** — that arm is N/A.
- **NEW-04 (Broken)** — `ranking()`'s raw `leftJoin('vendors')` does not filter
  `deleted_at` while its `with('vendor:id,name')` does, so an archived vendor
  ranks with `vendor.id = null, vendor.name = null` and consumes a `limit` slot.
- **NEW-05 (Incomplete, QUESTION-2)** — no `purchase_orders.status` filter
  anywhere; a **cancelled** PO reads as a 100% price variance and zeroes 15% of
  the composite.
- **NEW-06 (Incomplete)** — `lead_time_variance_days` is `numeric(5,2)`
  (±999.99) with no clamp; a mis-keyed expected date overflows and 500s `compute()`.
- **NEW-07** — not a code defect: dev DB is missing the F-007 migration.

### Controls confirmed PASSING (measured)

`/vendors/ranking` is declared before `/vendors/{vendor}/performance`
(`routes.php:86` vs `:89`) and is **not param-bound** — five ranking tests
resolve it. All five ratios are divide-by-zero guarded; no AR-style
`DivisionByZeroError` exists here. A zero-history vendor yields NULL metrics,
NULL score and NULL tier — "no history" **is** distinguishable from "scored zero"
in the all-empty case, and breaks down only in the mixed case (NEW-01).
On-time uses the PO's promised date with `lte()`, so **exactly-on-date and early
both count on time**, and a receipt with no promised date is excluded from both
sides rather than counted late. The recompute command returns `FAILURE` whenever
any vendor threw, so "everything failed" is distinguishable from "nothing to do"
— this module does **not** have the 8D-SLA false-green defect. All three registry
roles can reach what they need (`purchasing_officer` holds view+recompute via
`module('purchasing')`; `finance_officer` holds view only at
`RolePermissionSeeder:549`; `system_admin` wildcard). No NCR aggregate exists, so
the `->reorder()` / `42803` trap cannot fire. No money arithmetic anywhere in
this service. SPA `Number(overall_score)` is safe because `decimal:2` yields the
truthy string `"0.00"`.

### Not verified — stated plainly

Runtime confirmation of NEW-01's 53.75/tier-D arithmetic; runtime probes for
NEW-03, NEW-04 and NEW-06; ranking tie determinism under both insertion orders
(deterministic by construction, probe not run); two-connection race for F-015;
live per-role HTTP 403 probes (there is **no export endpoint** on this surface);
NCR rate against real NCR rows; `docs/USER-MANUAL.md` cross-check. This module
has **no quality-PPM metric** — its quality inputs are inspection pass rate and
NCR rate.
