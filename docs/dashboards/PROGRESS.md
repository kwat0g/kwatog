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
| S | Bug/gap log (68 findings; current statuses in `FINDINGS.md`) | `docs/dashboards/FINDINGS.md` | done |
| S | This file | `docs/dashboards/PROGRESS.md` | done |

Conflicts noted during synthesis (see `00-discovery.md` "Discrepancies" for detail):
1. "9 ledger types" = 8 live + 1 dead (`payroll` never writes rows).
2. Widget scalar count 54 (D) vs 55 (F) — recount `DashboardWidgetSeeder.php:48-105`
   before citing. No other cross-section conflicts.

**Findings statuses updated 2026-10-11 (Phase 2).** `FINDINGS.md` was written with all 68 items `open` and
stayed that way after work landed, so the log read as 68 live blockers when 8 were already fixed. Closed,
verified against the live `ogami` schema rather than against a migration's own output: F-030 (returns
fact-table indexes — `return_requests` + all six provenance FKs on `return_request_items`) and F-031…F-036
(the whole index-debt batch — `wo_operations`, `production_logs`, `grn_items`, `budgets`/
`budget_transfers`/`credit_note_lines`, PR/PO dates, delivery·WO·MWO timestamps — each column confirmed
present in `pg_indexes`, and each attributed to the one migration that creates it). **F-037 is partial**: `invoices.sales_order_id`/`delivery_id`,
`deliveries.invoice_id` and `official_receipts.invoice_id` are indexed;
`invoice_items.source_delivery_item_id` still is not. At that update, the remaining 60 stayed open —
nothing was marked closed on the strength of a commit message alone.

**Current finding status (2026-10-12):** 61 closed, 4 partial, 3 implemented pending runtime
verification, and 0 open. The remaining partials are F-002 (uncovered queues and notification delivery),
F-024 (polymorphic references lack database FKs), F-060 (remaining KPI definition gaps), and F-064
(inventory aggregation cost). F-010/F-014/F-015 have working paths and passing tests, but live notification
delivery remains unverified. F-001 payroll cards use a separate maker-checker source and are verified;
F-003 VP escalation, F-005 audit metadata, F-006 bill-payment notices, F-007 salary-adjustment notices,
F-008 asset-disposal notices, F-009 custom queue notices, F-011 leave fallback, F-012 loan fallback and F-039 finance page/API access are verified through feature tests;
F-013 is unreachable under seeded permissions (production_manager has view/act, not request); its
submit-route 403 is covered by `SalaryAdjustmentGateTest`. A deliberate per-user request grant can
still expose the self-stall. F-021 is confirmed and surfaced through Finance's delivery-invoice
handoff count and filtered list; booked revenue stays based on posted ledger entries. `AutoInvoiceOnDeliveryConfirmTest` checks the count, link, and filtered rows. The widget is seeded into the Finance role default; existing personal layouts remain unchanged and can add it from the picker. F-022 is covered by the 15-minute retry sweep and the Warehouse default's `inventory.qc_handoff_issues` count/filter; failure, recovery, count, and filtered-list tests pass. F-023 keeps the order funnel's QC stage pending when completed output lacks outgoing inspection; the hourly sweep repairs or alerts unresolved cases. Chain and sweep tests pass. F-024 remains partial: service writes use `SourceReferenceRegistry`, and `audit:source-references` found zero unresolved ledger links; polymorphic records still lack database FKs. F-025 adds an index to `mrp_plans.mrp_run_id`; migration applied and index test passes. F-026's monthly reconciler backfills closed periods, and forecast accuracy omits rows with null actuals; regression and dashboard tests pass. F-014/F-015 have permission-scoped delegation and notify-only SLA reminders/escalations across payroll
periods and ten custom queues. F-016/F-017 approval guides now match seeded workflows.
Targeted delegation, board-scope, custom-sweep, auto-resolve, notification-service/catalog and
finish-first compatibility tests pass; live notification delivery remains unverified.
F-027 is closed: rollout health resolves incoming inspections through `grn_item_id` and the GRN-line
foreign key, using legacy entity links only for inspections without typed source links; the conflicting-link
regression test passes.
Dashboard login checks then found `GET /dashboard/kpi/trends` returned 403 for purchasing and warehouse:
their strips request mixed KPI codes, while scorecard already omits codes outside each viewer's data grants.
Batch trends now applies that same filter. The API regression covers both roles. The 2026-10-12 Chromium
and Firefox role-dashboard audit found no dashboard API errors or permission toasts for the 16 available
demo login accounts; `maintenance_supervisor` has no matching demo login credentials in the running database,
and its permission-derived landing is covered by `DashboardDispatchTest`. Role records currently hold both
`notifications.view` and `business_policies.view`. The topbar now mounts its notification bell only when the
effective permission set contains `notifications.view`, with a regression for both grant states. F-028 is
closed: failed rework work-order creation rolls back the NCR
disposition; Quality's `Unlinked Rework NCRs` KPI and filtered list expose non-cancelled rework reports
missing both work-order links. Tests cover rollback, KPI count, and list rows; the live database audit found
24 historical unlinked rework NCRs and no closed outgoing-rework repair gaps. F-029 is closed:
`StockLevel` and `StockMovement` now emit `HasAuditLog` rows on Eloquent writes; `StockAuditTrailTest`
verifies actor attribution and before/after values through the inventory service. No history was backfilled;
the movement ledger remains the canonical source for physical stock events. F-037 is closed: the
missing `invoice_items.source_delivery_item_id` index was added by `2026_10_12_090000`, applied to the
running database, and verified by `Phase0IndexMigrationTest` and `pg_indexes`. F-038 is closed as a
date-grain rule: PO lines already have timestamps; no-timestamp lines use their parent business date or
output event date. `ForecastManualOverrideGuardTest` and `ProductionDashboardServiceTest` pass for the
sales-demand and production-defect joins. No item-level edit history is claimed.

F-063 is closed: widget summaries and rich payloads cache successful reads for 30 seconds under
viewer-specific keys, with failures retryable and no cross-user cache reuse. F-064 is partial: R1–R4 now
use SQL aggregation and batched OEE reads; inventory summary and low-stock calculations still process the
full relevant stock set to preserve location, hold, reservation, and availability rules. F-065 is closed:
cash position uses configured account codes, output trends filter an indexed `recorded_at` range, and AR
aging uses direct indexed due-date ranges. The recorded-at index migration is applied and asserted by
`Phase0IndexMigrationTest`.

## Phase 1 status — COMPLETE, AWAITING REVIEW (spec only; no feature code)

| Part | File | Covers |
|---|---|---|
| Roles 1–6 (+ §0 shared contracts) | `docs/dashboards/phase0/M-p1.md` | system_admin, hr_officer, finance_officer, vice_president, customer_service_officer, sales_officer |
| Roles 7–12 | `docs/dashboards/phase0/M-p2.md` | production_manager, ppc_head, purchasing_officer, warehouse_staff, qc_inspector, maintenance_tech |
| Roles 13–17 | `docs/dashboards/phase0/M-p3.md` | maintenance_supervisor, impex_officer, department_head, employee, driver |
| **Synthesis** | `docs/dashboards/01-matrix.md` | canonical shared contracts · company-overview spec · 4 matrices (role×widget, module×dashboard, approval-type×role, metric→source→cadence) · gaps · build order · open questions |

Counts: roles specified **17/17** · matrices **4/4** · six new pages shipped: `/dashboard/company` in
Phase 2 B and five role pages in Phase 2 E (`/dashboard/logistics`, `/dashboard/maintenance`,
`/dashboard/department`, `/dashboard/sales`, `/dashboard/customer-service`) · driver PWA dispatch remains
deferred; current driver home stays `/dashboard/default` ·
UNVERIFIED 19 · data-not-available 44.

The initial gaps are recorded with current resolution in `01-matrix.md` §D2: shared widget gates were
reviewed for row scope; production and forecast gates now follow dataset reads; PPC and PM KPI strips
match their readable codes; ImpEx now reads OTD through a qualified gate; five role pages shipped. The
department attendance KPI source, some cross-module strip codes, and future queue indexes remain explicit
gaps rather than broad permission grants.

The five Phase 1 decisions were resolved and recorded in `01-matrix.md` §D4: shared-slug widget scope,
PPC dataset gates, KPI permission sets, the company page/VP landing, and the five additional role pages.

Phase 1's planned build order and blockers at that point were: foundation (cache + index
migrations + metric dictionary) → system_admin/finance/vice_president →
production_manager/ppc_head → purchasing_officer/warehouse_staff → qc_inspector → hr_officer →
maintenance pair → impex_officer → department_head → sales_officer → customer_service_officer →
employee → driver. Phase 2 updates below record the shipped pages, widgets, and permission fixes; remaining
approval-board gaps and live notification-delivery verification are listed in the current status above.

## Phase 2 status — FOUNDATION SHIPPED (analytics layer + indexes + dictionary)

**Reading the sub-sections below:** each one's test counts are what was measured **at that increment's
commit**, not the running total — B says 192 backend because that is what `tests/Feature/Dashboard`
reported when B shipped, C says 196, E says 199. Read them as a timeline. The current state of the tree is
the HEAD commit's own verification block, not the highest number in this file.

Branch `docs/dss-discovery-phase0`. Feature code this time (the mission's Phase 2 is a build phase).
At this foundation increment, role pages and DSS tiles were still NOT built; later Phase 2 sections below
record their delivery.

| Deliverable | Files | State |
|---|---|---|
| Analytics service layer | `api/app/Modules/Dashboard/Support/Analytics/{MetricDefinition,MetricRegistry,AnalyticsPeriod}.php` · `Services/Analytics/{AnalyticsCache,AnalyticsService}.php` · `Services/Analytics/Domains/{DomainMetric,ReturnsPipelineAnalytics,WorkOrderLeadTimeAnalytics}.php` | shipped |
| Thin controller + routes | `Controllers/MetricController.php` · `Requests/{ListMetricsRequest,ShowMetricRequest}.php` · `routes.php` (`GET /dashboard/metrics`, `GET /dashboard/metrics/{metric}`) | shipped |
| Index batch (Phase 0 B §4 cheapest wins) | 6 migrations `2026_10_11_0900xx_*` → **75 indexes across 20 tables** (production/WO-log joins, GRN+QC joins, all of returns, budgets/credit lines, PR/PO dates, delivery OTD timestamps, chain links) | shipped |
| MRP run trace follow-up | `2026_10_11_170000_add_mrp_run_trace_index.php` → `mrp_plans.mrp_run_id` index | shipped; migration applied; `Phase0IndexMigrationTest` asserts it |
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
§4a index list (portal, MRP actor/user, payroll and admin tables). Only two metrics are mounted; the remaining
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

### Phase 2 — A: KPI gate map is now a slug set (shipped)

Resolves `01-matrix.md` §D4 #3 ("extend `MODULE_PERMISSIONS` to slug sets, or add new KPI
definitions") in favour of slug sets, and unblocks the company-overview shell.

| Deliverable | Files | State |
|---|---|---|
| Gate map is a set | `KpiSnapshotService::MODULE_PERMISSIONS` → `array<string, list<string>>` + `::moduleGates()`; `userCanSeeModule()` admits ANY of the set | shipped |
| Set-capable widget gate | `dashboard_widgets.permission_any` (jsonb, migration `0575_`, with an up/down backfill) · `DashboardLayoutService::canSeeWidget()` as the ONE rule, used by all three strip sites | shipped |
| Company-wide read | `dashboard.company.view` (migration `2026_10_11_100000` + `RolePermissionSeeder`), held by **system_admin + vice_president** — decided 2026-10-11, locked by test | shipped |
| Tests | `KpiVisibilityMatrixTest` (6: every module admitted to the company read, health strip fully readable, a no-gate role reads none, a module holder reads only its own module, every gate slug exists, holders are exactly the decided two) · `WidgetSeedIntegrityTest` +2 and patched (its leak check and its KPI gate check both had to learn `permission_any`, or they would have scored every `kpi.*` tile as ungated and stopped guarding them) | 192 passed / 967 assertions in `tests/Feature/Dashboard` |

**Why the widget registry needed a column.** `dashboard_widgets.permission` held ONE slug and the scorecard
held one per module; had only the scorecard become a set, a `kpi.*` tile and the page it links to would have
disagreed about the same KPI. `permission_any` (any-of, wins over `permission`) is the same rule on both sides.

**Membership rule, no role names:** the module's own dashboard slug, plus any page whose KPI strip actually
renders that module's codes (`/dashboard/plant-manager` draws OEE + WO completion rate + first-pass yield, so it
qualifies for `production` and `quality`), plus `dashboard.company.view`.

**Measured on the running app** (`/dashboard/kpi/scorecard`, live HTTPS): vice_president **0 → 11 codes** (the
executive health strip was 0 of 6 tiles, not 1 — the old map's values were module slugs and the VP held none of
them); driver 0; qc_inspector 3, quality only; ppc_head 1, supply_chain only; production_manager 2 → 5, gaining
the three quality codes their own landing page's strip already listed. `/dashboard/widgets` agrees: VP 0 → 11 KPI
tiles, driver 0, qc 3.

**Current status:** Phase 2 E shipped `/dashboard/logistics` and added its read slug to the supply-chain KPI
gate, so ImpEx reads OTD. Department heads still have no department-scoped attendance KPI source; no
company-wide grant was added. PPC and PM each read all three codes on their strips; purchasing and warehouse
remain at 1/2 because their missing code belongs to another module.

### Phase 2 — B: company-overview shell (shipped)

Resolves `01-matrix.md` §D4 #4 as recommended by §B: **one** executive page at `/dashboard/company`
(the `/dashboard/vp` proposal from M-p1 R4 is retired, not shipped alongside), and the VP landing moves
from `/dashboard/plant-manager` to it.

| Deliverable | Files | State |
|---|---|---|
| Dispatched page | `DashboardCatalog` entry `company` → `/dashboard/company` + `dashboard.company.view` | shipped |
| Route + guard | `spa/src/routes/dashboardRoutes.tsx` (`PermissionGuard permission="dashboard.company.view"`) — repeats the catalog permission as defence in depth | shipped |
| The page | `spa/src/pages/dashboard/company.tsx` — `DashboardShell` + `KpiStrip codes={COMPANY_KPIS}` + the registry renderer | shipped |
| Health strip | `COMPANY_KPIS` in `spa/src/lib/dashboardKpis.ts` (oee, on_time_delivery, first_pass_yield, dppm, ar_aging_60d, budget_utilization) | shipped |
| Role default | `DashboardRoleLayoutSeeder` gains `vice_president` — **this role had no default at all**, which is why its effective layout was empty | 8 blocks |
| Tests | `spa/src/pages/dashboard/company.test.tsx` (3) · `DashboardDispatchTest` provider updated | 59 SPA / 192 backend pass |

**It is a shell by design, not a new analytics layer.** Every §B block already exists as a gated widget;
re-deriving them would have produced a second, differently-gated copy of numbers the modules already
publish. So the page is thin and the value is the *dispatch + strip + default set*: the health strip is the
KPI scorecard (readable only because A made its gate a set), and the body is the widget registry stripped
against each widget's own gate.

**Measured on the running app:** `/dashboard/dispatch` for all twelve demo accounts — **only the VP's landing
moved** (`Company Overview /dashboard/company`); production_manager, system_admin, hr, ppc, finance,
purchasing, warehouse, qc, impex, depthead and employee are all unchanged. That is the G-D-2 check, and it is
the reason this was held for an explicit decision. The VP's effective layout resolves 8 blocks (3 rich, 5
scalar) with `layout_version` served; the strip reads all 6 codes.

That runtime measurement predates the recovery, quality and Phase 5 widgets. The migrations add five
role-owned entries; a database check confirmed all five are present. Existing user-owned layouts remain
unchanged and can add widgets from the picker; the effective rendered layout was not rechecked.

**Executive coverage after Phase 5.** The default VP layout now includes the requested quality and Phase 5
families. Quality cards use read-only grants; detailed recommendation rows and briefing sentences still
check each source permission before querying it.

| Family | Status |
|---|---|
| Quality (defect Pareto, NCR aging, 8D SLA burn) | **Built.** VP receives `quality.view` and `quality.ncr.view` only; no quality write or disposition grant. The 8D card also uses existing `crm.complaints.view`. |
| Demand vs supply | **Built in C below** — this was not a grant call after all, it was a gate written on a page slug. Both forecasts now read the datasets they project. |
| Chain-stage *donut*, AP due ≤3d, chain-recovery backlog card | **Chain health built in F below.** AP due ≤3d is served by the existing `finance.upcoming_payables` (its horizon is the `dashboard.widgets.payables_horizon_days` setting); the recovery backlog is a scalar on the shared recovery attention query. VP has view-only recovery access for the count and drill-through. |
| Risks & opportunities, Weekly briefing | **Built.** Current permission-filtered alert, recovery, quality, receivables and payables signals; weekly production/NCR/complaint totals compare trailing 7-day windows. This feed uses existing records and fixed rules, not predictive models. |

### Phase 2 — C: widget gates follow the dataset read (shipped)

Closes §D2 G-P2-1, G-P2-2 and G-P2-4, and the PPC half of G-P2-3. Resolves §D4 #1 and #2. One cause
behind all of them: **a widget's gate was chosen by which permission happened to be nearby** — a page
grant, or a self-service slug — rather than by who may read the data behind it.

| Deliverable | Files | State |
|---|---|---|
| Re-gate migration | `2026_10_11_110000_regate_dashboard_widgets_on_dataset_reads.php` — 7 keys, applies **and** reverses, sourced from `KpiSnapshotService::moduleGates()` so the tiles and the widgets cannot disagree | applied |
| Seeder | `DashboardWidgetSeeder` — the 7 rows now carry `permission_any` (the module's read set) with `permission` null | shipped |
| Executive page | `DashboardRoleLayoutSeeder` VP default gains `forecast.revenue` + `forecast.capacity` → §B's Demand-vs-supply family; recovery, quality and Phase 5 entries followed | 16 default entries currently |
| Strip honesty | `spa/src/lib/dashboardKpis.ts` — `PPC_KPIS` drops `first_pass_yield` (a quality reading ppc_head holds no grant for, so the tile could never draw); purchasing/warehouse kept at 1/2 with the reason commented at the list | shipped |
| Drift guards | `WidgetSeedIntegrityTest::test_widget_gates_follow_the_dataset_read` + `::test_regated_widgets_reach_the_intended_audience` (both directions: who GAINS and who LOSES) · `KpiVisibilityMatrixTest` +2 (the production data read admits the schedule owner; it does not widen them past their own modules) | 196 backend pass |

**Measured, not asserted — available widgets before → after** (migration rolled back, probed through
`DashboardLayoutService::listAvailableWidgets`, then re-applied; the same round trip is the migration's
own up/down proof):

| Role | Before | After | What moved |
|---|---|---|---|
| `vice_president` | 31 | **37** | +6: the three production tiles, revenue/capacity forecasts, attendance forecast |
| `ppc_head` | 27 | 28 | +3 production tiles and the capacity forecast; −2 (the finance cash-flow projection and the org-wide attendance forecast, which were never its read) |
| `purchasing_officer` | 24 | **21** | −3: both forecasts and the attendance projection — a buyer was rendering finance's cash forecast (G-P2-4) |
| `warehouse_staff` | 18 | **15** | −3: same three |
| `driver` | 8 | **7** | −1: the org-wide attendance/leave projection |

These counts predate the recovery tile; VP now qualifies for one additional widget. Runtime count was not
rechecked in this pass.

**The hole in the naive fix, caught before shipping:** gating on a single data-read slug is correct for the
module's own holder and **silent for the executive**, whose entire authority is `dashboard.company.view`.
`forecast.cash_flow` would have been visible to `finance_officer` and invisible to the VP — the company page
advertising a finance family it then drew empty. The gate is the module's read **set**, the same one the
`kpi.*` tiles use, which is why the VP is the biggest gainer above. `forecast.revenue` was the last
forecast still on a page grant and was fixed in the same migration.

**G-P2-1 is documented, not re-gated.** Measured: the two `hr.team_*` tiles are department-scoped and
null-safe by construction (`WidgetScope`), so no role reads another department's roster — re-gating them
would have traded a non-leak for a dashboard outage. `forecast.attendance` was the one genuine org-wide
leak and moved.

**Dev-DB side effects:** the migration applied, plus `DashboardWidgetSeeder` and
`DashboardRoleLayoutSeeder` re-run on dev. The demo VP account keeps its **8 saved personal blocks** —
personal rows win over the role default by design, so its page shows 8 while the default now carries 10.

### Phase 2 — D: role-dashboard polish, Tasks 1–8 (shipped)

Plan: `docs/superpowers/plans/2026-10-11-roles-dashboard-polish.md` (its own spec record — the grilling
session of 2026-10-11 is filed there, not in a separate spec doc). **This increment was implemented and
committed across 9 commits but never recorded here until now** — it is the reason `PROGRESS.md` could be
read as "only A/B/C exist". Commits: `a3c7d6b4`, `dcd56028`, `42b3e3e9`, `5c972807`, `0ca24d35`,
`8248c0b8`, `a33cb6bd`, `ca8c4ffd`, `122fcbb1`.

| Task | Deliverable | Files |
|---|---|---|
| T1 | Consistent compact KPI strip on all 9 role surfaces (status dot + 6-pt sparkline + MoM delta; empty message, never a crash, for a permission-stripped code) | `spa/src/components/dashboard/KpiStrip.tsx` (+`.test.tsx`) · `spa/src/lib/dashboardKpis.ts` (new — the per-role code lists) · mounted in `plant-manager`, `hr`, `ppc`, `finance`, `purchasing`, `warehouse`, `quality`, `admin`, `default` |
| T2 | Top-8 SLA-sorted approvals link-out on every dashboard, replacing the bare count | `api/app/Modules/Dashboard/Services/Analytics/ApprovalsWidgetAnalytics.php` · `spa/src/components/dashboard/registry.tsx` · `spa/src/api/dashboard-layout.ts` · `ApprovalBoardScopeTest` + `ApprovalsWidgetTest` |
| T3 | Standalone KPI scorecard page **deleted** (KPIs distributed per role); `/dashboard/scorecard` redirects to the dispatch target rather than 404s; admin gains a Compute panel | `spa/src/pages/dashboard/{scorecard,scorecard.test}.tsx` removed in `5c972807` · `spa/src/routes/dashboardRoutes.tsx` (+`.test.tsx`) · `spa/src/pages/dashboard/admin.tsx` · `DashboardWidgetSeeder` |
| T4 | Freshness + 5-state polish: `WIDGET_STALE_AFTER_MS` / `staleAfterMs`, stale badge in `DashboardShell`, and widget-level error isolation | `spa/src/components/dashboard/DashboardShell.tsx` (+`.test.tsx`) · `spa/src/api/dashboard-layout.ts` · `spa/src/api/dashboards.ts` · `spa/src/components/ui/WidgetErrorBoundary.tsx` · `spa/src/components/guards/ErrorBoundary.tsx` |
| T5 | Existing forecasts surfaced inline (read-only) on finance / hr / ppc / purchasing / quality / warehouse | `ForecastingDashboardService`, `PurchasingDashboardService` · `spa/src/components/dashboard/{ForecastPanel,DemandForecastPanel}.tsx` (+ tests) |
| T6 | **New** cash-flow forecast (finance) | `Dashboard/Services/CashFlowForecastService.php` · `Analytics/CashFlowWidgetAnalytics.php` · `spa/src/components/dashboard/CashFlowPanel.tsx` (+ test) · `CashFlowForecastTest` |
| T7 | **New** capacity forecast (ppc + plant-manager) | `Dashboard/Services/CapacityForecastService.php` · `Analytics/CapacityWidgetAnalytics.php` · `Support/ForecastTrend.php` · `spa/src/components/dashboard/CapacityPanel.tsx` (+ test) · `CapacityForecastTest` |
| T8 | **New** attendance forecast (hr) | `Dashboard/Services/AttendanceForecastService.php` · `Analytics/AttendanceWidgetAnalytics.php` · `spa/src/components/dashboard/AttendanceRiskPanel.tsx` (+ test) · `AttendanceForecastTest` |

Each forecast task landed the same four edits — service, analytics provider, seeder gate, SPA panel —
and registered the provider in **both** `WidgetAnalyticsService::providers()` and
`WidgetSeedIntegrityTest::handledKeys()`, which is the drift guard that keeps the rich↔provider bijection
honest. **T1/T3 were re-verified during Phase 2 C:** all 8 tasks are genuinely implemented (T1's strip is
mounted in 9 pages, T2 carries `LIMIT=8` + SLA sort, T3's redirect and admin panel exist with tests, T4's
stale badge has `DashboardShell.test.tsx`), so nothing here is a plan checkbox without code behind it.

Interaction with C, worth knowing: C changed the gate on `forecast.cash_flow`, `forecast.capacity` and
`forecast.attendance` — three tiles D created. D gated them on `forecasting.view` (the slug its own plan
named); C re-gated them on their datasets' read sets. D's provider/seeder wiring is unchanged by that, but
a reader of D alone would conclude the gates are `forecasting.view`.

**Not done in D, and why:** no inline approve anywhere (the approval board stays read-only; act lives on
entity controllers only), and the strip is mounted per role rather than derived from
`KpiSnapshotService::MODULE_PERMISSIONS` — that derivation is G-P2-3, addressed in C by hand for PPC and
recorded for purchasing/warehouse.

### Phase 2 — E: the five remaining role pages (shipped)

Resolves `01-matrix.md` §D4 #5 in favour of shipping all five together. Before this, **five of the
seventeen seeded roles had no dashboard of their own**: each held no `dashboard.*.view` slug, so
`DashboardDispatchService` fell through to `/dashboard/default` — a landing page, not a surface. Two of
them (`sales_officer`, `customer_service_officer`) had **no role-default rows either**, so the generic
grid they landed on resolved to empty.

| Key | Path | Permission | Role(s) |
|---|---|---|---|
| `logistics` | `/dashboard/logistics` | `dashboard.logistics.view` | impex_officer |
| `maintenance` | `/dashboard/maintenance` | `dashboard.maintenance.view` | maintenance_tech **+** maintenance_supervisor |
| `department` | `/dashboard/department` | `dashboard.department.view` | department_head |
| `sales` | `/dashboard/sales` | `dashboard.sales.view` | sales_officer |
| `customer-service` | `/dashboard/customer-service` | `dashboard.customer_service.view` | customer_service_officer |

| Deliverable | Files | State |
|---|---|---|
| Catalog entries | `DashboardCatalog` — 9 → **14** entries | shipped |
| Grants | `2026_10_11_120000_grant_role_dashboard_reads.php` (applies + reverses) · `RolePermissionSeeder` catalog rows + role grants | applied |
| Routes | `spa/src/routes/dashboardRoutes.tsx` — five lazy pages, each repeating its catalog permission as defence in depth | shipped |
| Shared shell | `spa/src/components/dashboard/RoleDashboardShell.tsx` — extracted from `company.tsx`, now shared by **all six** role pages; `company.tsx` refactored onto it rather than left as a second copy of the same strip/registry/stale logic | shipped |
| Pages | `spa/src/pages/dashboard/{logistics,maintenance,department,sales,customer-service}.tsx` | shipped |
| Role defaults | `DashboardRoleLayoutSeeder` — new defaults for `sales_officer` + `customer_service_officer` (they had none); `impex_officer` gains the OTD reading and its shipments card | shipped |
| New widgets | `logistics.shipments_in_transit` · `sales.open_orders` · `sales.price_requests` (scalar arms in `DashboardWidgetDataService`) | 75 widgets |
| Tests | `RoleDashboardShell.test.tsx` (5) · `DashboardDispatchTest` provider extended to all 17 roles + maintenance shared-page case · `KpiVisibilityMatrixTest` +2 | **199 backend / 64 SPA** |
| Drift guard | `scripts/check-dashboard-route-parity.mjs` + `npm run audit:dashboard-routes` — every `DashboardCatalog` entry must have a `<Route>` whose `PermissionGuard` names **the same** permission | **14/14** |

**A gap this increment exposed, and closed.** The catalog docblock has always called the route guard
"defence in depth" — but nothing checked the two agreed, and none of the existing guards could: the backend
suite cannot read the SPA tree (the api container mounts only `api/`) and the SPA suite cannot read the PHP
catalog. A disagreement costs a 403 on the page the server just dispatched the user to, silently. So the
check lives at the one seam where both halves are visible — a repo-level script beside the other cross-stack
audits (`audit:tokens`, `audit:rbac`) — and it matches a permission **per route block**, since a whole-file
search would happily accept a guard that sits on a different route. Proven by mutation rather than asserted:
swapping `logistics`'s guard to `dashboard.ppc.view` makes it exit **1** naming the entry, and restoring makes
it exit 0 with the routes file byte-identical.

**Role-login browser audit (2026-10-12).** `scripts/role-dashboard-audit.js` now checks all 17 role entries,
their landing paths, visible permission toasts, browser errors, and failed API responses in Chromium and
Firefox. Fifteen correctly matched live demo accounts passed with no dashboard API errors or permission
toasts. The running database's maintenance demo accounts drift from `DemoAccountSeeder`: `maintenance@ogami.test`
belongs to `maintenance_supervisor`, `maintenancehead@ogami.test` is absent, and `maintenance_tech` has no
demo login. `DashboardDispatchTest` covers both maintenance roles. The live role-permission table has the
expected dashboard landing grant and `notifications.view` for all 17 roles; no shared dashboard grant gap
reproduced. If the toast persists, its failing API request is needed to identify a different permission gate.

**The grants migration was round-tripped on dev, not just applied** (`up` → `migrate:rollback --path` →
`up`), because "applies and reverses" is a claim about `down()` and only the rollback exercises it. Holder
counts per slug: after `up` 2·2·2·3·2 (maintenance has two roles plus the wildcard); after `down` **1 each**
— the `system_admin` wildcard, which holds every permission independently of this migration — and after
re-applying, the same 2·2·2·3·2. So `down` removes exactly the grants this migration added and leaves
nothing behind.

**G-P3-2 closed for impex.** `dashboard.logistics.view` was added to the supply_chain KPI gate SET, so the
officer accountable for OTD can finally read it. That is the resolution the matrix specified — "a second
qualified gate", not a role-name branch — and it keeps the decision a `role_permissions` row.
`KpiVisibilityMatrixTest` now asserts both that impex reads OTD and that its readable modules are exactly
`['supply_chain']`, so the second gate cannot quietly become a whole-scorecard grant.

**The dispatch risk, checked rather than reasoned about.** Landing is rarity-ranked, and adding five
single-holder dashboards is an access-model change. Measured live on the running app, all 17 seeded
accounts: **0 mismatches.** The one that could have broken is `system_admin` — the wildcard qualifies for
all fourteen, and the five new ones each have exactly one non-admin holder, so they tie with `admin` on
rarity and the tie-break is the catalog KEY. `admin` sorting first is the only reason the administrator
stays on `/dashboard/admin`, and `DashboardDispatchTest` now asserts the candidate count (14) with that
reason written down, so a sixth single-holder page cannot silently move it.

**Built as shells, deliberately.** Every block on these pages is an existing gated widget or KPI reading;
re-deriving them would have produced a second, differently-gated copy of numbers the modules already
publish. Only three widgets are new, because only three cards had no widget over the same data:

| Card the specs asked for | State |
|---|---|
| Shipments in transit (M-p3 R14 §2) | **built** — `logistics.shipments_in_transit`, live value 2 |
| Open sales orders / price requests (M-p1 R6) | **built** — live values 14 and 0 |
| Maintenance's six cards (M-p2 R12 §2) | **already existed** — the role default is exactly that list, which is why the maintenance page needed no new widget |
| Docs expiring ≤ 30d (M-p3 R14 §2) | **not built, and not faked**: `shipment_documents` has no expiry column and no expiry sweep behind it, so the card would have rendered a permanent zero. Data-not-available. |
| Department attendance RATE (M-p3 R15 §2) | **not built**: needs `kpi.attendance_rate`, whose gate is the HR planning read this role does not hold. The matrix's alternative (a department rollup card) has no department-scoped KPI source, and the KPI is a company-wide figure — granting it would widen a company number to every department head. Left open rather than granted. |
| Maintenance forecast panels (M-p2 R12 §4) | **not built**: no maintenance forecast series exists and the role holds no `forecasting.view`. Phase 3 candidate, recorded in the specs already. |

The shared maintenance page is the one place two roles meet: `maintenance_tech` and
`maintenance_supervisor` hold the same page permission and see different cards, permission-derived. Its
gate is deliberately **not** `maintenance.work.assigned` — the technician does not hold that slug, so
using it would have hidden the page from half the roles it exists for (the M6 trap the specs name).

**Dev-DB side effects:** the migration applied, plus `RolePermissionSeeder`, `DashboardWidgetSeeder` and
`DashboardRoleLayoutSeeder` re-run on dev.

### Phase 2 — G: Sales dashboard analytics (shipped)

Sales now sees a 12-month order-date trend, open orders by lifecycle stage, and active price requests by
status. Existing order and pricing permissions remain the gates; customer-level records and amounts do not
enter these aggregates. `sales.order_intake` is added to role defaults and existing saved Sales layouts by
`2026_10_12_100000_add_sales_dashboard_analytics.php`.

| Deliverable | Files | State |
|---|---|---|
| Rich analytics | `CrmWidgetAnalytics` — zero-filled 12-month order count; open order and price-request status mixes | shipped |
| Catalog / defaults | `DashboardWidgetSeeder`, `DashboardRoleLayoutSeeder`, reversible catalog/layout migration | shipped |
| Page copy | `spa/src/pages/dashboard/sales.tsx` now names the actual views | shipped |
| Regression checks | `NewDomainAnalyticsTest`, `WidgetSeedIntegrityTest`, `DashboardWidgetDataTest` | passed |

The Sales default no longer includes `crm.open_complaints`: its route uses `crm.complaints.view`, which Sales
does not hold. The widget now uses that route grant, and a migration removes it from Sales role defaults while
permission filtering strips it from saved layouts. No complaint-view permission was added to Sales.

Conversion, promise-date risk, invoice handoff, and customer-confirmation trends remain out of this increment:
they require source joins or read grants the Sales role does not currently hold. The billing backlog remains
finance-owned, and no permission was widened to populate it.

### Phase 2 — F: §B chain health is a real breakdown (shipped)

`chain.stage_breakdown` was a **scalar** — a single count of active order-to-cash chains. §B asks for the
stage breakdown, and it is right that it does: "14 active" is a number with no decision in it, while "9
still in Order Entered, 2 In Production" is where the bottleneck is.

| Deliverable | Files | State |
|---|---|---|
| Rich provider | `CoreWidgetAnalytics` handles `chain.stage_breakdown` → `{total, segments[]}` — the same shape `qc.pareto` and `production.wo_breakdown` already produce, so the SPA's existing `WidgetBreakdown` renders it with no new component | shipped |
| Shared source | `ProductionDashboardService::chainStageBreakdown()` — `private` → **public**, so the widget and `/production/dashboard` read ONE stage map. A re-derived copy would disagree the first time a sales-order status moved | shipped |
| Render kind | `DashboardWidgetSeeder` — `chain.stage_breakdown` scalar → `breakdown` | 75 widgets |
| Drift guards | `WidgetSeedIntegrityTest` — rich↔provider bijection **and** "every handled key is seeded rich": the second is what fails if the seeder render kind is not moved with the provider | passed |

**Live, on the VP's layout:** `kind=breakdown total=13 segments=7` — Order Entered 9 (success), MRP Planned
2 (success), In Production 2 (info), QC Pending 0, Ready to Ship 0, Delivered Unpaid 0 (warning), At Risk
0 (danger). Tones come from the source's own colour map, and the whole set is counts — no customer, order
number or amount — so splitting the total into stages does not widen who sees what.

`manual_required` is not a sales-order stage at all — it is a PR/PO *conversion* state and a delivery→invoice
*handoff* state, so making it a segment of this donut would have labelled a stalled procurement as a
production stage. The chain-recovery backlog is a separate scalar on the recovery surface.

**Recovery backlog shipped.** `ChainListenerRecoveryService::attentionCount()` and its list endpoint share
the same attention filter. The `chain.recovery_backlog` widget links to `/chains/recovery`; VP receives
`dashboard.chain_recovery.view` only, not replay/resolve authority. The catalog migration adds the widget and
VP's role default without changing saved user layouts.

## Remaining approval-center work and verification

Phase 5 executive widgets, VP read-only quality access, VP escalation, finance page/API gate alignment,
approval-record audit events, and the documented submit/decision/handoff notifications are implemented.
The three migrations are applied. A VP service smoke returned NCR, 8D, recommendation, briefing and
recovery aggregates; VP permissions were verified as read-only for quality and chain recovery. A
read-only system-admin approval-board smoke exercised all ten custom filters and returned active OT,
cost-review and budget-transfer cards. PHP lint, SPA typecheck and `git diff --check` passed. The live
database has no `ApprovalRecord` audit rows, so there is no production sample; the HTTP feature test
verifies actor, IP, user-agent and request ID. Notification delivery remains unverified.

1. **Custom approval board sources are implemented (F-002, still partial).** `/approvals` now reads
   payroll periods separately and adds permission-gated cards for OT, stock adjustments, item cost
   reviews, stock-count variances, payroll adjustments, budgets, transfers, prebill invoices, supplier
   listings and loan write-offs, applying maker guards where the source records them. Ledger rows remain
   unchanged; no synthetic `approval_records`
   were added. GRN refusal, unrouted transfers, return-case state transitions, PPAP (no SPA review route)
   and late production reports (per-work-order list only) remain outside the global board.
2. **Custom delegation and sweep coverage are implemented (F-014/F-015).** Active delegates can act
   only when they already hold the source queue's read permission. The six-hour SLA sweep now sends
   deduplicated reminder/escalation notices for payroll periods and ten custom queues; escalations also
   notify active system administrators, never auto-decide. Targeted tests cover read-scope enforcement,
   budget role separation, and no state mutation; live delivery remains unverified.
3. **Sub-month KPI grain needs no snapshot change.** Snapshot grain stays monthly; weekly briefing and
   trends query source tables, as specified in `METRICS.md`.
4. **Reported validation/metadata failures are verified.** Finish-first output accepts the legacy
   `good_count` shape when no rejects or defects are present; the HTTP feature test also verifies hashed
   `crew_id` decoding. Notification catalog coverage passes for every emitted type, including the spare-parts
   handover type, and SPA notification metadata typecheck passed.

### F-040 — chain tracker role gates (closed 2026-10-12)

`/chains` and its sidebar item admit either `crm.sales_orders.view` or `dashboard.view_bottlenecks`.
Sales-order lookup/detail and bottleneck data each stay behind their own permission; the API's
row-level module filtering remains authoritative. PPC users can use bottleneck-only mode, and a
deep-linked sales-order ID does not trigger an unauthorized request. Sales and PPC component tests,
sidebar permission tests, SPA typecheck, and `ApprovalChainRolePermissionDriftTest` pass. No grants changed.

### F-041–F-042 — maintenance route gates (closed 2026-10-12)

The maintenance landing and internal work-order detail now require `maintenance.view`. Employees
with only `maintenance.work.assigned` retain scoped access on the self-service routes; backend
assignment checks remain in place. Route tests verify denied roles see the not-found state without
starting work-order reads. SPA tests, typecheck, and lint pass.

### F-043 — overtime create access (closed 2026-10-12)

The create route and list action now use `attendance.ot.create`, matching the write endpoint. The
form fetches the employee directory only for `hr.employees.view`; create-only users submit for their
linked employee. Focused SPA regression, typecheck, and lint pass.

### F-044 — self-service access alignment (closed 2026-10-12)

Audit confirmed self-service APIs resolve data from the authenticated employee and do not require
the shared attendance, leave, or payroll read slugs. Notification preferences does require
`notifications.preferences.manage`; its route is now guarded and its profile link hidden without
that grant. Route regression, typecheck, and lint pass.

### F-045 — approval-cover access (closed 2026-10-12)

The unguarded route matches the self-service API: any signed-in user may manage their own cover;
`admin.delegations.manage_any` is the only wider scope. Existing controller/service and page tests
cover the ownership rules. No extra route permission added.

### F-046 — leave and attendance read scopes (closed 2026-10-12)

Leave and attendance management pages remain manager-only. Generic read endpoints grant shared
self-service read permissions but enforce own/department row scopes. Leave visibility matrix and
attendance self-scope regressions pass; no wider page access is needed.

### F-047 — warehouse structure read access (closed 2026-10-12)

`/inventory/warehouse` now admits `inventory.view` readers, matching the read endpoints. The page
continues to hide mutations without `inventory.warehouse.manage`; Warehouse Map stays manager-only
because its endpoints require that grant. Route permission regressions pass.

### F-048/F-049 — admin and notification navigation gates (closed 2026-10-12)

Removed the SPA-only `is_superuser` bypass so admin access follows the API's `system_admin` role
check. The Notifications sidebar link now requires `notifications.view`, matching its route.
Sidebar regressions pass.

### F-050 — maintenance supervisor grant migrations (closed 2026-10-12)

Follow-up migrations supply `maintenance.wo.verify` and `mrp.machines.view` on migrated databases.
Regression removes and reapplies both grants twice without reseeding; personal dashboard layout stays intact.

### F-051 — dashboard defaults for every seeded role (closed 2026-10-12)

`DashboardRoleLayoutSeeder` now includes all 17 seeded roles, and the dispatch map sends only
`employee` and `driver` to `/dashboard/default`. Added a seed-integrity regression for full role
coverage; existing checks verify every default widget against role permissions.

### F-053 — factory PWA role boundary (closed 2026-10-12)

No production-operator role exists in the seeded catalog. Keep the full factory PWA restricted to
`production_manager`; employees retain assignment-scoped work-order access through self-service.
No broad permission grant added.

### F-054 — unknown Finance permission module (closed 2026-10-12)

Removed the stale `crm_commissions` module lookup from the Finance role seeder. It had no catalog
entry and yielded no permissions.

### F-055 — scorecard endpoint role (closed 2026-10-12)

The standalone scorecard page remains deleted. Its API endpoint intentionally feeds the shared
role KPI strips; E2E docs now name that strip surface.

### F-056 — KPI strip and widget windows (closed 2026-10-12)

Documented six monthly points for compact page strips and 12 months for widget trend context. Both
read the same monthly snapshots under the same permission gates.

### F-057 — forecast access gates (closed 2026-10-12)

Documented the split: shared forecast widgets follow source-data permissions; bespoke dashboard
panels use the permission declared by their service, including `forecasting.view` for cash/capacity.

### F-058 — forecast series meaning (closed 2026-10-12)

Clarified the inputs behind the capacity and attendance trend indicators. Capacity compares projected
months only; attendance uses recorded rates for its mean, direction, and confidence, while future
approved leave load drives its risk label.

### F-059 — forecast threshold defaults (closed 2026-10-12)

Accepted 90% capacity and 8%/15% attendance thresholds as fixed defaults. Code comments record
runtime configurability as the upgrade trigger if policy owners need threshold changes.

### F-060 — KPI calculator gaps (partial 2026-10-12)

WO completion now counts finished work from the same due-month cohort as the denominator. A regression
verifies July work finished in August remains a July miss and does not inflate August's rate. The focused
test passed (4 assertions). OEE, inventory turnover, budget utilization, and AR-aging gaps remain open.

### F-063–F-065 — dashboard query load (2026-10-12)

Successful widget-summary and rich-payload reads cache for 30 seconds per viewer and widget. Production
output totals, order-stage breakdowns, and dashboard OEE now aggregate in SQL or batch by machine. The
output trend uses the new `work_order_outputs.recorded_at` index; cash position and AR aging use indexed,
direct predicates. Inventory summary and low-stock aggregation remain full-set work because stock
availability depends on location, holds, reservations, and blocked/quarantine rules. R1–R4 and R6/R8/R9
are addressed; F-064 stays partial for R5/R7. Targeted production, widget, index, approval-board,
delegation, workflow-permission, executive-gate, and role-dispatch tests passed: 162 tests,
656 assertions across two serial runs. SPA typecheck passed; 27 focused SPA test files passed (108 tests),
and dashboard route/catalog parity passed for all 14 dashboards; RBAC audit found no referenced but
unseeded permissions. Full SPA lint still reports five `toISOString()` calendar-date violations in
unchanged return-management files.

### Dashboard role permissions (verified 2026-10-12)

All 17 roles hold their expected dashboard landing grant and `notifications.view` in the running database.
The role browser audit verifies each signed-in `/auth/user` role against the expected slug and found no
permission toast or failed dashboard API response for 15 correctly matched
demo accounts. Maintenance login data in the running database does not match the current demo seeder; see
the audit note above. `Topbar` now omits the notification bell when the effective permission is absent.

### F-052 — executive dashboard separation (closed 2026-10-12)

The VP now lands on `/dashboard/company`; its read-only page separates company aggregates from
plant operations, which remains available as an alternate. `DashboardDispatchTest` verifies dispatch.

`driver` and `employee` keep `/dashboard/default` by design — their primary surfaces are the driver PWA and
`/self-service`.

## Coverage checklist skeleton

**Phase 1 status of this checklist:** every role, module area and approval type below has a *spec* in
`01-matrix.md` (+ `M-p1/2/3`). Boxes track **built** coverage, which started in Phase 2. Ticking a role
means it lands on a purpose-built dashboard, not that every item in its `M-p*.md` spec is done; ticking a
module means it has a built surface, not that every reading `B-modules-data.md` asked for exists. The
per-role and per-module gaps are recorded in those specs and in `FINDINGS.md`.

**Approval-board coverage:** all eight live step-ledger kinds, payroll's maker-checker queue and ten
custom decision queues have sources gated by module read permission, applicable row scope, and maker
guards where the source records them. The non-queue/state-machine cases and live notification delivery
verification keep F-002 partial; custom delegation and notify-only SLA coverage are implemented.

### Spec coverage delivered in Phase 1

- Roles spec'd: **17/17** (M-p1 6 · M-p2 6 · M-p3 5)
- Matrices: **4/4** (role×widget · module×role-dashboards · approval-type×role · metric→source→cadence)
- Company-overview spec: **1/1**
- Approval types spec'd: **8 live ledger + 1 dead (payroll) + 14 custom = 23/23**
- Module areas with a matrix row: **20/20** (B2B marked "not covered on internal dashboards: reason")

### Roles (from A §1)

**Built coverage 17/17 as of 2026-10-11.** Fifteen roles land on a purpose-built dashboard; `employee`
and `driver` land on `/dashboard/default` **by design** (their primary surfaces are `/self-service` and
the `/driver` PWA) and are ticked on that basis, not because the generic grid was left as their
answer. The five that had no page until Phase 2 E are marked with the increment that gave them one.

- [x] system_admin (`/dashboard/admin`)
- [x] hr_officer (`/dashboard/hr`)
- [x] finance_officer (`/dashboard/finance`)
- [x] vice_president (`/dashboard/company` — Phase 2 B)
- [x] customer_service_officer (`/dashboard/customer-service` — Phase 2 E)
- [x] sales_officer (`/dashboard/sales` — Phase 2 E)
- [x] production_manager (`/dashboard/plant-manager`)
- [x] ppc_head (`/dashboard/ppc`)
- [x] purchasing_officer (`/dashboard/purchasing`)
- [x] warehouse_staff (`/dashboard/warehouse`)
- [x] qc_inspector (`/dashboard/quality`)
- [x] maintenance_tech (`/dashboard/maintenance` — Phase 2 E, shared page)
- [x] maintenance_supervisor (`/dashboard/maintenance` — Phase 2 E, shared page)
- [x] impex_officer (`/dashboard/logistics` — Phase 2 E)
- [x] department_head (`/dashboard/department` — Phase 2 E)
- [x] employee (`/dashboard/default` + `/self-service` — by design)
- [x] driver (`/dashboard/default` + `/driver` PWA — by design)

### Modules / data areas (from B §1)

**19/20 as of 2026-10-11.** A tick means the module has a **built** surface (a dashboard, page or widget
that renders it), named here so the tick is checkable rather than a claim; §C2 is the mapping this follows.
Ticking a module does **not** mean every reading its `B-modules-data.md` verdict asked for exists — the
sub-month KPI grain, historical snapshots and the per-module gaps stand as recorded.

- [x] HR — `/dashboard/hr`, `/dashboard/department`
- [x] Attendance — `/dashboard/hr`, `/dashboard/department` (team DTR), `/hr/attendance`
- [x] Leave — `/dashboard/hr`, `/dashboard/department` (team leave), `/hr/leaves`
- [x] Payroll — `/dashboard/hr` (pipeline), `/dashboard/finance`, `/payroll/periods`
- [x] Loans — `loans.outstanding` widget (VP / finance / HR / PM / department_head) · `/hr/loans`
- [x] Accounting (incl. budgeting) — `/dashboard/finance` + `budget.utilization` · `/budgeting/budget-vs-actual`
- [x] Inventory — `/dashboard/warehouse` · `/inventory/stock-levels`
- [x] Purchasing — `/dashboard/purchasing`, `/dashboard/finance`
- [x] SupplyChain — `/dashboard/warehouse` (deliveries), `/dashboard/logistics` (shipments, OTD)
- [x] Production — `/dashboard/plant-manager`, `/dashboard/ppc`, `/production/dashboard`, `/factory` PWA
- [x] MRP — `/dashboard/ppc`, `/dashboard/plant-manager`, `/mrp/plans`
- [x] CRM — `/dashboard/customer-service`, `/dashboard/sales`, `/dashboard/quality`, `/dashboard/plant-manager`
- [ ] **B2B — deliberately not covered on internal dashboards.** The portal is a separate principal model (supplier/customer self-service); internal visibility is limited to supplier shipments feeding GRN and portal RMAs feeding returns (§C2 "not covered, with reason"). Left unchecked because that is the decision, not because the work is pending.
- [x] Forecasting — 7 forecast panels across `/dashboard/{ppc,hr,finance,quality,plant-manager,purchasing}`
- [x] ReturnManagement — `/dashboard/quality`, `/dashboard/purchasing`, `/dashboard/warehouse`, `/dashboard/customer-service`
- [x] Assets — `assets.under_maintenance` widget only. **Partial by design**: §C2's proposed asset-disposal queue on the finance page is NOT built.
- [x] Quality — `/dashboard/quality`, `/quality/dashboard`
- [x] Maintenance — `/dashboard/maintenance` (shared page, Phase 2 E)
- [x] Dashboard (KPI/widgets/layout/action-center) — `/dashboard/admin`, `/dashboard/default`, `/action-center`
- [x] Auth/Admin (roles, overrides, audit, delegations) — `/dashboard/admin`, `/admin/operations-health`

### Approval chains (from C §1a ledger + §1b custom)

Ledger (step-ledger):
- [x] leave
- [x] pr
- [x] po
- [x] bill_payment
- [x] loan
- [x] salary_adjustment
- [x] asset_disposal
- [x] return_request
- [x] payroll (separate maker-checker card source; still no ledger rows or sweep)

Custom single-step:
- [x] overtime — custom approval-board source
- [x] stock_adjustment — custom approval-board source
- [x] item_cost_review — custom approval-board source
- [x] stock_count_variance — custom approval-board source
- [ ] grn_reject
- [x] payroll_adjustment — custom approval-board source
- [x] budget — custom approval-board source
- [x] budget_transfer — custom approval-board source
- [x] invoice_prebill — custom approval-board source
- [x] supplier_listing — custom approval-board source
- [ ] asset_transfer (unrouted)
- [ ] return_case
- [x] loan_write_off — custom approval-board source
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
