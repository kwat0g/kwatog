# OGAMI ERP — Dashboards/DSS Progress (single source of truth)

## Mission + branch

- Mission: dashboard/DSS discovery → design → build, starting with Phase 0 read-only
  discovery of roles/access, modules/data, approvals, existing dashboard/DSS, frontend
  foundation, and realtime/perf.
- Branch: `docs/dss-discovery-phase0`. Phase 0 writes docs only — no feature code.

## Resource constraints (binding on all later phases)

- Sequential agents only; never dispatch subagents (Phase 0 synthesis rule; later phases
  follow the mission's own parallelism rules, but DB access stays serialised).
- One test suite at a time per database. Two agents/suites must NOT share `ogami_test`
  (`RefreshDatabase` + `migrate:fresh` tears the schema mid-run; tell = hundreds of
  failures with zero assertion failures). Mint a private DB per worker:
  `CREATE DATABASE ogami_test_<name> OWNER ogami;` + `DB_DATABASE=ogami_test_<name>`.
- Targeted tests in tight loops (`--filter='Foo|Bar'`); full suite (~1900 tests / ~28 min,
  sharded via `make test`) only at end of feature. Perf/Redis/Reverb claims need staging
  smokes (`make chain-smoke`, `make worker-recovery-smoke`), not PHPUnit (test env forces
  sync/array/log).
- Index migrations must respect `0NNN_` vs `2026_*` ordering (dependency rule in CLAUDE.md).

## Phase 0 status — COMPLETE (6/6 sections + synthesis)

| # | Section | File | Status |
|---|---|---|---|
| A | Roles & access (17 roles, authority chain, 12 mismatches) | `docs/dashboards/phase0/A-roles-access.md` | done |
| B | Modules & data (20 areas, 3 chains, verdicts, 86 index gaps) | `docs/dashboards/phase0/B-modules-data.md` | done |
| C | Approvals (9 ledger + 14 custom, stuck risks, readiness) | `docs/dashboards/phase0/C-approvals.md` | done |
| D | Dashboard & DSS as-built (pages, KPIs, forecasts, 38 behaviors, 12 opportunities) | `docs/dashboards/phase0/D-dashboard-dss.md` | done |
| E | Frontend (deps, 30 widgets, tokens, gaps) | `docs/dashboards/phase0/E-frontend.md` | done |
| F | Realtime/perf (14 events, 32 cache keys, 15 risks, test constraints) | `docs/dashboards/phase0/F-realtime-perf.md` | done |
| S | Executive synthesis | `docs/dashboards/00-discovery.md` | done |
| S | Bug/gap log (68 items, all open) | `docs/dashboards/FINDINGS.md` | done |
| S | This file | `docs/dashboards/PROGRESS.md` | done |

Conflicts noted during synthesis (see `00-discovery.md` "Discrepancies" for detail):
1. "9 ledger types" = 8 live + 1 dead (`payroll` never writes rows).
2. Widget scalar count 54 (D) vs 55 (F) — recount `DashboardWidgetSeeder.php:48-105`
   before citing. No other cross-section conflicts.

## Phase 1 status — COMPLETE, AWAITING REVIEW (spec only; no feature code)

| Part | File | Covers |
|---|---|---|
| Roles 1–6 (+ §0 shared contracts) | `docs/dashboards/phase0/M-p1.md` | system_admin, hr_officer, finance_officer, vice_president, customer_service_officer, sales_officer |
| Roles 7–12 | `docs/dashboards/phase0/M-p2.md` | production_manager, ppc_head, purchasing_officer, warehouse_staff, qc_inspector, maintenance_tech |
| Roles 13–17 | `docs/dashboards/phase0/M-p3.md` | maintenance_supervisor, impex_officer, department_head, employee, driver |
| **Synthesis** | `docs/dashboards/01-matrix.md` | canonical shared contracts · company-overview spec · 4 matrices (role×widget, module×dashboard, approval-type×role, metric→source→cadence) · gaps · build order · open questions |

Counts: roles specified **17/17** · matrices **4/4** · new pages proposed **5**
(`/dashboard/company` (VP, replaces the earlier `/dashboard/vp` proposal), `/dashboard/logistics`,
`/dashboard/maintenance`, `/dashboard/department`, driver landing → existing `/driver` PWA) ·
UNVERIFIED 19 · data-not-available 44.

New gaps found while writing the matrix (all logged in `01-matrix.md` §D2):
G-P2-1/G-P3-1 shared self-service slugs used as global widget gates (team DTR/leave/attendance
forecast visible to every role) · G-P2-2 `ppc_head` lacks `production.dashboard.view` (4 widgets +
2 KPI tiles stripped) · G-P2-3 KPI strip codes ≠ KPI gates (PPC 1/4, PM 2/3, purchasing 1/2,
warehouse 1/2) · G-P2-4 `forecasting.view` leaks cash-flow/capacity tiles to buyers and warehouse ·
G-P3-2 the KPI gate map allows one slug per module, so impex/department_head cannot read OTD or
attendance rate · G-P3-3/G-P3-4 landing + role-default mismatches · G-D-1 two competing executive-page
proposals (resolve to one) · G-D-2 adding a dispatch slug moves a landing · G-D-3 every proposed queue
card needs index work first.

**Five decisions are parked for review** (`01-matrix.md` §D4): re-gate vs document the shared-slug
widgets · grant vs re-gate `production.dashboard.view` for PPC · extend `MODULE_PERMISSIONS` to slug
sets vs new KPI definitions · confirm the single executive page + VP landing change · which of the five
new pages ships first.

Suggested build order (after those decisions) — build order and blockers: foundation (cache + index
migrations + metric dictionary + company shell) → system_admin/finance/vice_president →
production_manager/ppc_head → purchasing_officer/warehouse_staff → qc_inspector → hr_officer →
maintenance pair → impex_officer → department_head → sales_officer → customer_service_officer →
employee → driver. Ranked blockers from Phase 0 unchanged: board-blind card sourcing → notification
gaps → index migrations → finance authority pick.

## Phase 2 status — FOUNDATION SHIPPED (analytics layer + indexes + dictionary)

Branch `docs/dss-discovery-phase0`. Feature code this time (the mission's Phase 2 is a build phase);
role pages and DSS tiles are still NOT built.

| Deliverable | Files | State |
|---|---|---|
| Analytics service layer | `api/app/Modules/Dashboard/Support/Analytics/{MetricDefinition,MetricRegistry,AnalyticsPeriod}.php` · `Services/Analytics/{AnalyticsCache,AnalyticsService}.php` · `Services/Analytics/Domains/{DomainMetric,ReturnsPipelineAnalytics,WorkOrderLeadTimeAnalytics}.php` | shipped |
| Thin controller + routes | `Controllers/MetricController.php` · `Requests/{ListMetricsRequest,ShowMetricRequest}.php` · `routes.php` (`GET /dashboard/metrics`, `GET /dashboard/metrics/{metric}`) | shipped |
| Index batch (Phase 0 B §4 cheapest wins) | 6 migrations `2026_10_11_0900xx_*` → **75 indexes across 20 tables** (production/WO-log joins, GRN+QC joins, all of returns, budgets/credit lines, PR/PO dates, delivery OTD timestamps, chain links) | shipped |
| Metric dictionary | `docs/dashboards/METRICS.md` — **generated** by `php artisan metrics:doc` from `MetricRegistry` | shipped |
| Settings | `dashboard.analytics.cache_ttl_seconds` = 60 (migration `2026_10_11_090500`) | shipped |
| SPA consumer | `spa/src/types/analytics.ts` (envelope contract) · `spa/src/api/metrics.ts` (catalog + one-metric read) · `spa/src/hooks/useMetric.ts` (refresh-bucket polling + stale flag) · `spa/src/lib/metricValue.ts` · `spa/src/components/dashboard/MetricPanel.tsx` | shipped |
| SPA mounts | `returns.pipeline` on `/return-management`, `production.wo_lead_time` on `/production/dashboard` — each page's own route guard is the metric's own gate, so the panel cannot 403 where the page opens | shipped |
| Tests | `tests/Feature/Dashboard/AnalyticsLayerTest.php` (16) · `tests/Feature/Dashboard/Phase0IndexMigrationTest.php` (4) · `spa/src/components/dashboard/MetricPanel.test.tsx` (5) | 20 passed / 136 assertions · 5 passed |

Contract the layer enforces: `{data, meta}` envelope (period + previous window, generated_at, cache_ttl,
scope, sources, indexes, caveats) · gate = the metric's ONE permission, permission-derived (no role-name
branch) · payload cached per `metric + scope + filters` with a version counter (`flush($code)` /
`flush()`), which works on the array store as well as Redis · aggregation in SQL `GROUP BY` /
`date_trunc`, never a PHP row loop · sources and declared indexes are asserted against the migrated
schema, so a renamed column or a skipped index fails the suite.

Open items (deliberately NOT in this increment): materialized views / summary tables, snapshot-history
tables (daily stock/AR/AP/WIP), per-metric invalidation observers, endpoint p95 query-budget assertions,
Reverb streaming for metric changes, the catalog (`GET /dashboard/metrics`) consumer — the API client
exposes `metricsApi.catalog()` but no surface reads it yet — and the remaining ~11 rows of the Phase 0
§4a index list (portal, MRP-run, payroll and admin tables). Only two metrics are mounted; the remaining
role pages are D3 build-order work.

SPA wiring notes (2026-10-11): `MetricPanel` takes a metric CODE and fetches, so body selection is
driven by `definition.render_kind` (breakdown → `WidgetBreakdown`; trend → `SparkLine`) and any other
top-level scalars are listed as a secondary stat row (nested number objects flattened one level, so
`handoff.awaiting_inspection` reaches the tile). Poll cadence comes from the metric's own `meta.refresh`
(live 60s — the rate the role dashboards already poll at; monthly never polls, since `kpi_snapshots` is
rewritten once on the 2nd). Read-only by construction: no inputs, no recompute. End-to-end verified
against the running app over the real HTTPS entrypoint (`production@ogami.test` → 200 with the payload
the TS types describe; `employee@ogami.test` → catalog count 0 and 403 on the specific metric, which is
the error state the panel renders).

Verification done: migrations ran on a scratch DB `ogami_test_an` (up → `migrate:rollback --step=6` →
up, all 6 reversible); `tests/Feature/Dashboard` 185 passed with 1 pre-existing failure
(`WidgetAnalyticsServiceTest::test_provider_failure_degrades_instead_of_throwing` — the test drops
`inspection_measurements`, which now has dependents from `2026_10_08_236000_add_finish_first_wo_qc`,
recorded as failing at base commit earlier in this mission); drift guards (`WidgetSeedIntegrityTest`,
`ApprovalChainRolePermissionDriftTest`, dispatch/layout tests) 46 passed. The app database `ogami` WAS
migrated on 2026-10-11 (all 6 applied; all 75 declared indexes confirmed present in `pg_indexes`,
transcribed from the migration files rather than read back from the migration output). Note: commit
`9b818111` shipped only 1 of the 6 index migrations — the other five sat untracked, so a clean checkout
created 13 of the 75 indexes the batch declares and `Phase0IndexMigrationTest` would have failed on 62.
Fixed in `77cfe246`.

SPA verification: `tsc --noEmit` clean, `eslint --max-warnings 0` clean on every touched file, token
audit clean, `src/components/dashboard` + `src/pages/dashboard` 54 passed, `vite build` exit 0 (8161
modules). `npm run build` as written fails on this host at `prepare-out-dir` — `spa/dist/assets` is
owned by `root` from an earlier container build, an environment permission issue unrelated to this
diff; the same bundle builds clean to a writable `--outDir`.

## Later phases — PENDING

Awaiting mission phasing past Phase 2 (role pages, forecasting wiring, approval centre, DSS tiles).

## Coverage checklist skeleton

**Phase 1 status of this checklist:** every role, module area and approval type below has a *spec* in
`01-matrix.md` (+ `M-p1/2/3`). The boxes stay unchecked because they track **built** coverage, which
starts in Phase 2.

### Spec coverage delivered in Phase 1

- Roles spec'd: **17/17** (M-p1 6 · M-p2 6 · M-p3 5)
- Matrices: **4/4** (role×widget · module×role-dashboards · approval-type×role · metric→source→cadence)
- Company-overview spec: **1/1**
- Approval types spec'd: **8 live ledger + 1 dead (payroll) + 14 custom = 23/23**
- Module areas with a matrix row: **20/20** (B2B marked "not covered on internal dashboards: reason")

### Roles (from A §1)

- [ ] system_admin
- [ ] hr_officer
- [ ] finance_officer
- [ ] vice_president
- [ ] customer_service_officer
- [ ] sales_officer
- [ ] production_manager
- [ ] ppc_head
- [ ] purchasing_officer
- [ ] warehouse_staff
- [ ] qc_inspector
- [ ] maintenance_tech
- [ ] maintenance_supervisor
- [ ] impex_officer
- [ ] department_head
- [ ] employee
- [ ] driver

### Modules / data areas (from B §1)

- [ ] HR
- [ ] Attendance
- [ ] Leave
- [ ] Payroll
- [ ] Loans
- [ ] Accounting (incl. budgeting)
- [ ] Inventory
- [ ] Purchasing
- [ ] SupplyChain
- [ ] Production
- [ ] MRP
- [ ] CRM
- [ ] B2B
- [ ] Forecasting
- [ ] ReturnManagement
- [ ] Assets
- [ ] Quality
- [ ] Maintenance
- [ ] Dashboard (KPI/widgets/layout/action-center)
- [ ] Auth/Admin (roles, overrides, audit, delegations)

### Approval chains (from C §1a ledger + §1b custom)

Ledger (step-ledger):
- [ ] leave
- [ ] pr
- [ ] po
- [ ] bill_payment
- [ ] loan
- [ ] salary_adjustment
- [ ] asset_disposal
- [ ] return_request
- [ ] payroll (dead kind — wire or drop)

Custom single-step:
- [ ] overtime
- [ ] stock_adjustment
- [ ] item_cost_review
- [ ] stock_count_variance
- [ ] grn_reject
- [ ] payroll_adjustment
- [ ] budget
- [ ] budget_transfer
- [ ] invoice_prebill
- [ ] supplier_listing
- [ ] asset_transfer (unrouted)
- [ ] return_case
- [ ] loan_write_off
- [ ] ppap / late_production_report

## How a fresh session resumes

1. Read this file (`docs/dashboards/PROGRESS.md`) for mission state.
2. Read the synthesis (`docs/dashboards/00-discovery.md`) for the full picture, then the Phase 1
   synthesis (`docs/dashboards/01-matrix.md`) for the specs and the parked decisions, and
   `docs/dashboards/METRICS.md` for the analytics dictionary (generated — regenerate with
   `php artisan metrics:doc`).
3. Drop into section files (`docs/dashboards/phase0/A–F`) only for the detail a task needs; drop into
   `phase0/M-p1.md` / `M-p2.md` / `M-p3.md` for a specific role's 10-item spec.
4. Check `docs/dashboards/FINDINGS.md` for the open bug/gap relevant to the task.
5. Respect the resource constraints above (serial DB access, private test DBs, targeted tests).
6. Before touching chains/permissions: run
   `tests/Feature/Approvals/ApprovalChainRolePermissionDriftTest.php` first (C §5.9).
