# Role-Responsive Dashboard Pack — Design Spec

**Date:** 2026-08-11
**Status:** Approved by user (design presented in two parts; user confirmed direction, "do what is best, NO LIMIT")
**Supersedes / builds on:** Series R (Task R4) permission-derived dashboard registry + dispatch (commits `ad2148ce`, `45d54dd0`, `109512ed`, `da3d8f56`, `b1fe60d1`)

---

## 1. Problem

The permission-derived registry and landing dispatch already exist and are correct —
the tiering **is** a byproduct of `dashboard_widgets.permission` + `DashboardDispatchService`
(live rarity, `DashboardDispatchService.php:81-95`). What is missing is **depth**:

1. Every one of the 51 widgets renders as a **scalar tile** — one number + helper line
   (`spa/src/components/dashboard/registry.tsx:87-122`). Pareto, breakdowns, trends, and
   gauges all collapse to a single figure (`DashboardWidgetDataService::breakdown()`
   flattens a GROUP BY into a helper string, `:290-294`). A QC inspector and a driver get
   structurally identical dashboards differing only in *which* numbers appear.
2. **No widget-picker UI** — `GET /dashboard/widgets` + `PUT /dashboard/layout` exist
   (`DashboardLayoutController.php:50-55, 73-80`) but nothing in the SPA calls them. Users
   cannot add a widget; only reset (`pages/dashboard/default.tsx:51-58`).
3. **`w`/`h` are dead** — the seeder writes `width: 12, height: 4` for every row
   (`DashboardRoleLayoutSeeder.php:132-133`) and the SPA ignores them
   (`pages/dashboard/default.tsx:97` hardcodes `PanelRow cols={3}`).
4. **Six domains have no analytics surface** — CRM, Assets, SupplyChain, ReturnManagement,
   Budgeting, Loans expose only scalar counts. No aggregate endpoints exist for them.
5. **Three roles** (`department_head`, `maintenance_tech`, `impex_officer`) qualify for
   11–17 widgets but have no bespoke dashboard page (deliberate — `DashboardRoleLayoutSeeder.php:65-70, 82-86`).

**Decision (user):** no new bespoke pages; fix depth in the registry; build analytics for
all six domains. NO LIMIT on scope.

## 2. Goals / Non-goals

**Goals**

- Widgets become typed: `scalar | breakdown | trend | table | gauge`.
- Tiering stays derived from permission data at runtime. **No `role ===` branch anywhere.**
- Every widget payload is scoped like the module's controllers (company-wide vs
  department-scoped), following the `hr.on_leave_today` precedent
  (`DashboardWidgetSeeder.php:56-63`).
- Six new analytics data sources (CRM, Assets, SupplyChain, ReturnManagement, Budgeting,
  Loans), all view-scoped.
- Widget picker UI + live `w`/`h` layout.
- Tests: 51 widget branches have 3 test methods today (`DashboardWidgetDataTest.php`);
  raise coverage substantially.

**Non-goals**

- No new bespoke dashboard pages (incl. for `department_head` / `maintenance_tech` /
  `impex_officer`).
- No new chart library (reuse `recharts` already in `spa/src/components/charts/`).
- No migration of the 8 bespoke pages / ~2,000 lines of per-role services into the
  registry this pass (separate, larger change — recorded in gap list).
- No B2B portal work (separate guards, no permissions — `api/config/auth.php:14-22, 29-37`).

## 3. Architecture

```
SPA (registry.tsx + LiveDashboardWidget)
   │  GET /dashboard/layout?rich=1
   ▼
DashboardLayoutController
   │
   ├─ DashboardLayoutService::getEffectiveLayout()   (unchanged: personal → role → empty, permission strip)
   │
   └─ WidgetAnalyticsService::render(string $key, User $user): ?WidgetPayload
        └─ per-key match, every branch scoped via WidgetScope::departmentId($user)
```

**`WidgetScope`** (new, `api/app/Modules/Dashboard/Support/WidgetScope.php`, final class) is
the one place scope is decided for widgets:

- `departmentId(User): ?int` — the user's linked `employees.department_id`, replacing the
  inline lookup at `DashboardWidgetDataService.php:68-69`.
- `isCompanyWide(User, string $permission): bool` — true when the user holds the
  company-wide gate for that domain, else the view is dept-filtered.

This is deliberately scoped to widgets. It does **not** attempt to unify the ad-hoc
controller scoping (`LoanController.php:79-84` compares `role->slug` literally;
`LeaveRequestController.php:70-77` uses a permission proxy) — that refactor is recorded in
§12 as out of scope, but `WidgetScope` gives it a home to grow into.

- `render_kind` column added to `dashboard_widgets` (migration `0442_*`).
- `WidgetAnalyticsService` (final class, `DB::table()` reads, **no writes → no
  `DB::transaction()` needed**; this is a read-only analytics path) implements the five
  render kinds.
- Existing scalar path (`DashboardWidgetDataService`) untouched; `?rich=1` routes rich
  data for the same keys, falling back to scalar for any widget without rich data.
- SPA `registry.tsx` switches on `render_kind`, delegates to existing chart primitives
  (`AreaTrend`, `BarComparison`, `DonutBreakdown`, `SparkLine`, `OeeGaugeChart`,
  `StatCard`, `DataTable`). Unknown `render_kind` → scalar fallback.
- `WidgetErrorBoundary` already wraps each tile (`pages/dashboard/default.tsx:101`).

## 4. Data model

Migration `0442_add_render_kind_to_dashboard_widgets`:

```php
Schema::table('dashboard_widgets', function (Blueprint $table) {
    $table->string('render_kind', 20)->default('scalar')->after('permission');
});
```

New `RenderKind` enum (backed, string): `Scalar`, `Breakdown`, `Trend`, `Table`, `Gauge`.

Seeder (`DashboardWidgetSeeder`) updated: every widget key declares its `render_kind` +
which analytics branch serves it.

## 5. Widget payload contract (discriminated union)

```php
// scalar     { value: string|null, kind: 'number|currency|percent|hours|date|decimal', helper: string|null }
// breakdown  { total: int|float, segments: array<{label: string, value: number, tone: string}> }
// trend      { points: array<{label: string, value: number}>, delta: float|null, kind: string, unit: string|null }
// table      { columns: array<string>, rows: array<array<string|number|null>>, total_count: int }
// gauge      { value: float, target: float|null, min: float, max: float, kind: string }
```

Unknown `render_kind` or failed branch → `null` data + `available: false` (same contract as
today's `summaries()`).

## 6. Widget enrichment plan (data sources)

All live `DB::table()` reads in `WidgetAnalyticsService`; **views scoped** per the module's
controllers (company-wide only under the same permissions that gate company-wide reads —
the `hr.on_leave_today` precedent).

| Widget | render_kind | Data source |
|---|---|---|
| `chain.stage_breakdown` | breakdown | SO / WO status → count per stage |
| `production.wo_breakdown` | breakdown | `work_orders` GROUP BY status (currently flattened at `DashboardWidgetDataService:290-294`) |
| `qc.pareto` | trend | `Quality` `/analytics/defect-pareto` (exists) |
| `qc.pass_rate` | trend | `inspections` by week |
| `machine.utilization` | gauge | `machines.status` |
| `oee.gauges` | gauge | `Production` `/oee/report` (exists) |
| `finance.cash_position` | trend | `journal_entries` posted, last 90d |
| `finance.revenue_mtd` | trend | `invoices` by week |
| `finance.ar_aging` | table | `Accounting` `/ar-aging` (exists) |
| `finance.ap_aging` | table | `Accounting` `/ap-aging` (exists) |
| `finance.upcoming_payables` | table | `bills` due window |
| `hr.headcount` | trend | `employees` by month |
| `hr.on_leave_today` / `hr.team_*` | breakdown | `leave_requests` today by dept |
| `hr.probation_alerts` | table | `employees` regularization window |
| `purchasing.supplier_perf` | trend | `supplier_performance_snapshots` (exists) |
| `inventory.low_stock` | table | `items` below reorder point |
| `inventory.pending_grns` / `pending_issues` | table | GRN / MIS |
| `maintenance.open_wos` | table | MWOs open |
| `maintenance.due_schedules` | table | preventive schedules |
| `budget.utilization` | gauge | budgets live FY |
| `forecast.*` | trend | `ForecastingDashboardService` (exists) |
| `rma.open_returns` | breakdown | by status |
| `crm.open_complaints` | breakdown | by status |
| `loans.outstanding` | table | scoped to dept (already) |
| `self.*` | scalar | unchanged |

## 7. New analytics data sources (six domains)

New `api/app/Modules/Dashboard/Services/WidgetAnalyticsService.php` (final class), one query
family per domain, **all views scoped**:

- **CRM**: sales orders per month (pipeline), complaints by status, complaint → NCR closure lag
- **Assets**: register summary, depreciation run history
- **SupplyChain**: deliveries overdue/ontime %, shipments by status
- **ReturnManagement**: returns by status, disposition mix, cycle time
- **Budgeting**: utilization by budget line, top over-budget
- **Loans**: outstanding by type, by department

Each gets a small unit test.

## 8. API pipeline

`GET /dashboard/layout?rich=1`:

```
{ data: [ { key, name, module, render_kind, x, y, w, h, source,
            data: { value | segments | points | rows } | null } ] }
```

- Same widget set as today (same permission stripping).
- `data` filled by `WidgetAnalyticsService`; `null` when unavailable.
- `DashboardWidgetDataService` keeps the scalar contract for `/dashboard/widget-data`
  (legacy escape hatch).
- **No writes anywhere in the new path** — no `DB::transaction()` required (read-only).
  The existing layout save/reset already wrap in transactions (`DashboardLayoutService.php:110, 149`).

## 9. SPA changes

- `registry.tsx`: `LiveDashboardWidget` switches on `render_kind`; delegates to
  `AreaTrend` / `DonutBreakdown` / `BarComparison` / `SparkLine` / `OeeGaugeChart` /
  `StatCard` / `DataTable` (all exist in `spa/src/components/charts/` and `ui/`).
- `default.tsx:97`: replace hardcoded `PanelRow cols={3}` with a width-aware grid
  (`w=12` full, `w=6` half, `w=4` third, `w=8` two-thirds).
- New `DashboardPicker.tsx`: add/remove/reorder via existing endpoints
  (`GET /dashboard/widgets`, `PUT /dashboard/layout`); user layouts already merge over
  role defaults (`DashboardLayoutService.php:30-43`). Reset button stays
  (permission-gated).
- `spa/src/api/dashboard-layout.ts`: `layout(rich=true)` + `widgets()` + `saveLayout()`.

## 10. Testing

- New: `api/tests/Feature/Dashboard/WidgetAnalyticsServiceTest.php` — one test per rich
  widget family + six domain families, asserting (a) correct shape, (b) **department
  scoping** (company-wide only under the right permission; otherwise dept-filtered).
- Extend `DashboardWidgetDataTest` if scalar contract changes (it should not).
- New: SPA `registry.test.tsx` — each `render_kind` renders the right primitive, unknown
  kind falls back to scalar.
- Keep the full suite green (currently 1242 tests; CLAUDE.md).
- `npm run audit:tokens` must pass (no hardcoded colours).

## 11. Conventions honored

- `final class` services, `declare(strict_types=1)`.
- HashIDs everywhere (`HasHashId` trait) — **not** ULIDs (project convention;
  CLAUDE.md).
- Design system: **Atelier** (warm paper `#fdfcfa`, espresso ink `#1f1b16`, clay accent
  `#b4542a`, opaque surfaces) — **not** grayscale SAP/Linear; all values from
  `spa/src/styles/tokens.css`.
- Every list page state: loading skeleton / error retry / empty / data / stale.
- No hardcoded colours (CI gate `npm run audit:tokens`).

## 12. Gaps recorded (out of scope this pass)

- 8 bespoke dashboard pages + ~2,000 lines of per-role services remain a second
  composition path (`RoleDashboardService.php` facade). Migration to the registry is a
  larger, separate change.
- B2B portal dashboards (`spa/src/pages/portal/*/dashboard.tsx`) sit outside RDBAC.
- `department_head` / `maintenance_tech` / `impex_officer` get their "full pack" via
  registry richness, not bespoke pages.
