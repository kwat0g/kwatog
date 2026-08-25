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
