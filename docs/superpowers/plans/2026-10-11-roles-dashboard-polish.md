# Roles Dashboard Polish + Analytics + Forecasting + Approvals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **STATUS: SHIPPED 2026-10-11 — all 8 tasks.** The checkboxes below stay unticked on purpose: they
> record authoring-time steps ("run the test, watch it fail"), not a live state, and ticking them would
> claim a re-observation of steps nobody re-ran. The shipped record — files, the nine commits, and what
> was re-verified afterwards — is `docs/dashboards/PROGRESS.md` → "Phase 2 — D: role-dashboard polish".
> Note that `forecast.cash_flow` / `forecast.capacity` / `forecast.attendance`, created by Tasks 6–8, were
> re-gated in Phase 2 C: their gates are now the datasets' read sets, not `forecasting.view` as written here.

**Goal:** Polish all role dashboards with a consistent KPI strip, top-8 approvals link-out, inlined forecasts, and remove the standalone KPI scorecard page.

**Architecture:** Reuse the existing widget registry (`DashboardWidgetSeeder` + `WidgetAnalyticsService` + `DashboardLayoutService`) and read-only `ApprovalBoardService`. No new auth model, no inline approve. New forecasts are new `ForecastingDashboardService` methods + thin analytics providers, same `render_kind` pattern.

**Tech Stack:** Laravel 11 API (`api/app/Modules/Dashboard`, `Forecasting`, `Common/Services/ApprovalBoardService`), React 18 SPA (`spa/src/pages/dashboard/*`, `spa/src/components/dashboard/*`), TanStack Query, Zod.

**Spec:** Grilling session 2026-10-11 (all-roles-at-once, consistent strip, strict-by-module, compact 6-pt, read-only forecasts, top-8 SLA approvals, 3-mo horizon, poll+stale, phased). No separate spec doc — this plan is the spec record.

## Global Constraints

- Every model keeps `HasHashId`; every API Resource returns `hash_id`, never raw integer `id`.
- Every financial write wrapped in `DB::transaction()`; authorization in `FormRequest::authorize()` or route middleware, never controller body.
- Dashboards are permission-derived — never add a role-name branch. `DashboardDispatchService::resolve()` + `PanelGate` + `WidgetScope` stay the access decision; role layouts are UX seeds only.
- Every list/panel handles 5 states: loading (skeleton), error (retry), empty (message), data, stale (placeholder/badge).
- Every mutation: `toast.success`/`toast.error` + `queryClient.invalidateQueries`.
- Every page lazy-loaded with `React.lazy()`; every route wrapped in `AuthGuard` + `ModuleGuard` + `PermissionGuard`.
- Numbers use `font-mono tabular-nums`; statuses use `<Chip>` with semantic mapping; no hardcoded colours (tokens.css only, `npm run audit:tokens` clean).
- Never Bearer tokens / localStorage auth — Sanctum HTTP-only cookies only.
- `WidgetSeedIntegrityTest` must stay green: rich↔provider bijection, every widget has `link_path`, KPI widgets match `kpi_definitions`, KPI gates match `KpiSnapshotService::MODULE_PERMISSIONS`, no role default references an invisible widget.
- Aggregator surfaces reuse the owning module's row scope — never re-express (`ApprovalSourceScope`, `PurchaseOrderAccessPolicy::visibleTo`, etc.).
- Approval board stays read-only; act lives on entity controllers only.

## Review Focus

- Stale KPI month: `kpi:compute-monthly` runs 2nd @ 03:00, so early-month strip reads last month — expect stale badge, not "missing bug".
- Delegated approver outside row scope sees `Restricted` card, not the document — table must render badge, not link.
- `forecasting.view`-less user must not see forecast panels at all (stripped server-side via `PanelGate`, not hidden with CSS).
- Deleted `/dashboard/scorecard` URL hit from bookmark must redirect to dispatch target, not 404.
- Polling must not hammer API: approvals 30s, KPI strip on mount + scorecard month change only, forecasts manual refresh.

---

### Task 1: Consistent KPI strip everywhere (strict-by-module, compact 6-pt)

**Files:**
- Modify: `spa/src/components/dashboard/KpiStrip.tsx`
- Modify: `spa/src/pages/dashboard/{plant-manager,hr,ppc,finance,purchasing,warehouse,quality,admin,default}.tsx`
- Modify: `spa/src/components/dashboard/DashboardShell.tsx`
- Test: `spa/src/pages/dashboard/scorecard.test.tsx` (update), new `spa/src/components/dashboard/KpiStrip.test.tsx`

**Interfaces:**
- Consumes: `GET /dashboard/kpi/scorecard?year&month` (`spa/src/api/kpi.ts`), `KpiSnapshotService::MODULE_PERMISSIONS`
- Produces: `<KpiStrip codes={string[]} compact />` used by all 9 surfaces; per-role code lists exported from `spa/src/lib/dashboardKpis.ts` (new, tiny)

- [ ] **Step 1: Write failing test** — `KpiStrip` renders status dot + 6-pt sparkline + MoM delta for `codes=['oee']`, and renders empty message (not crash) when scorecard returns `{}` for a permission-stripped code.
- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- KpiStrip --run` in `spa/`
Expected: FAIL (component/prop does not exist)

- [ ] **Step 3: Implement `lib/dashboardKpis.ts` + compact `KpiStrip`** — exact per-role lists: production_manager `[oee,wo_completion_rate,first_pass_yield]`, ppc `[oee,wo_completion_rate,on_time_delivery,first_pass_yield]`, finance `[ar_aging_60d,budget_utilization]`, hr `[attendance_rate]`, purchasing `[supplier_quality,on_time_delivery]`, qc `[dppm,first_pass_yield,ncr_closure_days]`, warehouse `[inventory_turnover,supplier_quality]`, admin `[]` (compute panel instead, Task 3), default `[]` (self widgets only). Compact = prev-month scorecard + `GET /dashboard/kpi/trends?codes&months=6` sparkline only.
- [ ] **Step 4: Mount strip in all 9 pages inside `DashboardShell` header slot** — no bespoke KPI markup left; delete duplicated strip code from `ppc.tsx`, `finance.tsx`, etc.
- [ ] **Step 5: Run tests to verify they pass**

Run: `npm run test -- KpiStrip --run` + `npm run audit:tokens`
Expected: PASS, no hardcoded colours

- [ ] **Step 6: Commit**

```bash
git add spa/src/components/dashboard/KpiStrip.tsx spa/src/lib/dashboardKpis.ts spa/src/pages/dashboard/
git commit -m "feat: consistent compact KPI strip on all role dashboards"
```

### Task 2: Top-8 approvals link-out table on every dashboard

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/Analytics/ApprovalsWidgetAnalytics.php` (top-8 urgent ordering, already exists — tighten to SLA sort)
- Modify: `spa/src/components/dashboard/registry.tsx` (`LiveDashboardWidget` table kind for `approvals.pending`)
- Modify: `spa/src/pages/dashboard/{default,hr,plant-manager,finance}.tsx` (ensure widget/link present; bespoke pages get `Open approvals → /approvals` row)
- Test: `api/tests/Feature/Approvals/ApprovalBoardScopeTest.php` (extend: widget respects `ApprovalSourceScope`), `spa/src/components/dashboard/registry.test.tsx` (new)

**Interfaces:**
- Consumes: `ApprovalBoardService::board(user)` via `DashboardWidgetDataService::pendingApprovalsForRole()`; `ApprovalTypeRegistry::linkFor()` for `card.link`
- Produces: `approvals.pending` table payload `{document,type,waiting_h,link,delegation_badge}` sorted by `overdue_hours` desc, limit 8

- [ ] **Step 1: Write failing API test** — delegated user outside row scope gets `Restricted` card without `link` in widget payload; SLA-overdue item sorts first.
- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_verify api php artisan test --filter='ApprovalBoardScopeTest'`
Expected: FAIL (ordering/badge missing)

- [ ] **Step 3: Implement ordering + badge in `ApprovalsWidgetAnalytics`** — sort by SLA overdue desc, take 8, pass through `Restricted` without link. No approve/reject actions here (read-only boundary).
- [ ] **Step 4: Frontend table** — columns `Document|Type|Waiting`, kind `SegmentedControl` filter (client-side on the 8), SLA countdown text, delegation badge, row click `navigate(card.link)`. Bespoke pages without the widget get one-line `Link to=/approvals`.
- [ ] **Step 5: Run tests**

Run: API filter above + `npm run test -- registry --run` in `spa/`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Dashboard/Services/Analytics/ApprovalsWidgetAnalytics.php spa/src/components/dashboard/registry.tsx
git commit -m "feat: top-8 SLA approvals link-out on all dashboards"
```

### Task 3: Remove scorecard page, distribute KPIs, admin compute panel + redirect

**Files:**
- Delete: `spa/src/pages/dashboard/scorecard.tsx` (+ route in `spa/src/routes/dashboardRoutes.tsx`)
- Modify: `spa/src/lib/dashboardLinks.ts` (`kpiLink` → in-dashboard anchor, not `/dashboard/scorecard`)
- Modify: `spa/src/pages/dashboard/admin.tsx` (add Compute KPIs panel, gate `dashboard.admin.view`)
- Modify: `api/database/seeders/DashboardWidgetSeeder.php` (`LINK_BY_KEY` for `kpi.*`), `DashboardRoleLayoutSeeder.php` (no layout references scorecard)
- Test: `api/tests/Feature/Dashboard/WidgetSeedIntegrityTest.php` (must stay green), SPA redirect test

**Interfaces:**
- Consumes: `POST /dashboard/kpi/compute` (admin only), existing `kpi.*` widget keys
- Produces: `/dashboard/scorecard` → 301/SPA `Navigate` to `/dashboard/dispatch` target; `kpiLink(code)` → `#kpi-{code}` anchor

- [ ] **Step 1: Write failing tests** — `GET /dashboard/scorecard` route gone; SPA test: navigating to `/dashboard/scorecard` lands on dispatch target; `WidgetSeedIntegrityTest` passes with new links.
- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_verify api php artisan test --filter='WidgetSeedIntegrityTest'` + `npm run test -- dashboardRoutes --run`
Expected: FAIL (old links/route)

- [ ] **Step 3: Delete page + route, add redirect** — keep a `Navigate` shim at `/dashboard/scorecard` → `/dashboard` (dispatch) for bookmarks; remove nav entry. Move `Compute KPIs` button + month label into `admin.tsx` panel only.
- [ ] **Step 4: Update `LINK_BY_KEY` + `kpiLink`** — every `kpi.*` widget `link_path` → owning dashboard path + `#kpi-{code}`; no dangling `/dashboard/scorecard` string anywhere (`grep -r scorecard spa/src api/app` must be empty except redirect shim + this plan).
- [ ] **Step 5: Run full dashboard test slice**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_verify api php artisan test --filter='Dashboard|Kpi|Widget'`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add spa/src/pages/dashboard/ spa/src/routes/dashboardRoutes.tsx spa/src/lib/dashboardLinks.ts api/database/seeders/DashboardWidgetSeeder.php
git commit -m "feat: remove KPI scorecard page, distribute KPIs per role"
```

### Task 4: Freshness + 5-state polish on all dashboards

**Files:**
- Modify: `spa/src/components/dashboard/{DashboardShell,DashboardGrid,registry}.tsx`, `spa/src/components/ui/WidgetErrorBoundary.tsx`
- Modify: `spa/src/api/dashboard-layout.ts` (stale timestamps), `spa/src/api/dashboards.ts` (poll intervals)
- Test: `spa/src/components/dashboard/DashboardShell.test.tsx` (new: stale badge, error retry, empty)

**Interfaces:**
- Consumes: `layout({rich})` + `widget-data` responses with `updated_at`; `TimeClockQuickAction` stays in header
- Produces: `<DashboardShell pollMs staleAfterMs>` behaviour reused by all pages

- [ ] **Step 1: Write failing test** — stale `updated_at` (>poll window) shows badge; widget error shows retry button that re-fires query; empty payload shows message, not blank grid.
- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- DashboardShell --run` in `spa/`
Expected: FAIL

- [ ] **Step 3: Implement** — approvals/KPI poll 30–60s (`RoleDashboard` 60s precedent, approvals board 30s), forecasts manual refresh button only; `WidgetErrorBoundary` + skeleton + empty + `Open → (link_path)` on every rich widget.
- [ ] **Step 4: Run tests + token audit**

Run: `npm run test -- DashboardShell --run` + `npm run audit:tokens`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add spa/src/components/dashboard/ spa/src/api/dashboard-layout.ts
git commit -m "feat: dashboard freshness and five-state polish"
```

### Task 5: Surface existing forecasts inline (read-only)

**Files:**
- Modify: `spa/src/components/dashboard/{ForecastPanel,DemandForecastPanel,StockOutPanel,ForecastAccuracyPanel}.tsx`
- Modify: `spa/src/pages/dashboard/{plant-manager,ppc,purchasing,warehouse,hr,finance,quality}.tsx` (mount missing panels)
- Modify: `api/app/Modules/Dashboard/Services/ForecastingDashboardService.php` (supplier-risk helper reusing `StockOutProjectionService` + accuracy MAPE — no new math)
- Test: `api/tests/Feature/Forecasting/ForecastDashboardTest.php` (new, thin), SPA panel tests exist — extend

**Interfaces:**
- Consumes: `panels.headcount_forecast|revenue_forecast|defect_forecast`, `GET forecasting/mrp-projection`, `GET forecasting/stock-out`, `GET forecasting/accuracy/summary`
- Produces: read-only panels `{12-mo history bars + 3-mo faded forecast + confidence + MAPE/bias + Open in Forecasting →}` gated on `can('forecasting.view')`

- [ ] **Step 1: Write failing test** — user without `forecasting.view` gets panels stripped (backend `PanelGate`); with permission, panel shows confidence + `Open →` link, no override inputs.
- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_verify api php artisan test --filter='ForecastDashboardTest'`
Expected: FAIL

- [ ] **Step 3: Implement supplier-risk panel as composition** — `StockOutProjectionService::index(horizon_days)` + accuracy MAPE, wrapped in existing `StockOutPanel` props. No new forecast engine. Mount: ppc/warehouse get Demand+StockOut+Accuracy; purchasing gets supplier-risk+StockOut; hr/finance/quality keep headcount/revenue/defect `ForecastPanel`.
- [ ] **Step 4: Run tests**

Run: API filter above + `npm run test -- Forecast --run` in `spa/`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add api/app/Modules/Dashboard/Services/ForecastingDashboardService.php spa/src/components/dashboard/Forecast* spa/src/pages/dashboard/
git commit -m "feat: surface existing forecasts on role dashboards"
```

### Task 6: New cash-flow forecast (finance)

**Files:**
- Create: `api/app/Modules/Dashboard/Services/CashFlowForecastService.php`
- Modify: `api/app/Modules/Dashboard/Services/ForecastingDashboardService.php` (`cashFlowForecast()` delegates — ponytail: one implementation, panel + widget share it)
- Modify: `api/app/Modules/Dashboard/Services/Analytics/BudgetWidgetAnalytics.php` or new `CashFlowWidgetAnalytics.php` (register in `WidgetAnalyticsService::providers()` + `WidgetSeedIntegrityTest::handledKeys()`)
- Modify: `spa/src/components/dashboard/FinanceSection.tsx` or new `CashFlowPanel.tsx`, `spa/src/pages/dashboard/finance.tsx`
- Test: `api/tests/Feature/Dashboard/CashFlowForecastTest.php`

**Interfaces:**
- Consumes: `invoices` (AR aging), `bills` (AP aging), `finance.revenue_mtd` run-rate; horizon 3-mo, method moving-avg (same as `revenueForecast`)
- Produces: `cashFlowForecast(): {months[3]: {inflow,outflow,net}, confidence}` + `forecast.cash_flow` widget (`render_kind=trend`, `permission=forecasting.view`, `link_path=/forecasting/demand`)

- [ ] **Step 1: Write failing test** — known AR/AP fixture → expected 3-mo net series + confidence 0–100; empty tables → `emptyResponse()`, not divide-by-zero.
- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_verify api php artisan test --filter='CashFlowForecastTest'`
Expected: FAIL (class missing)

- [ ] **Step 3: Implement service (≤80 lines, mirror `revenueForecast`)** — `DB::transaction` not needed (read-only); moving-avg + linear-regression trend via existing `computeTrend()`.
- [ ] **Step 4: Provider + seeder rows + SPA panel (read-only bars + confidence + link)** — register provider in BOTH places or seed test fails by design.
- [ ] **Step 5: Run tests**

Run: API filter + `WidgetSeedIntegrityTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add api/app/Modules/Dashboard/Services/CashFlowForecastService.php spa/src/components/dashboard/CashFlowPanel.tsx
git commit -m "feat: cash-flow forecast on finance dashboard"
```

### Task 7: New capacity forecast (ppc + plant-manager)

**Files:**
- Create: `api/app/Modules/Dashboard/Services/CapacityForecastService.php`
- Modify: `ForecastingDashboardService.php` (`capacityForecast()`), new `CapacityWidgetAnalytics.php`, `spa/src/pages/dashboard/{ppc,plant-manager}.tsx`, new `CapacityPanel.tsx`
- Test: `api/tests/Feature/Dashboard/CapacityForecastTest.php`

**Interfaces:**
- Consumes: open `work_orders` load, `Machine` capacity, OEE trend (reuse `KpiSnapshotService::getTrend('oee')`); horizon 3-mo
- Produces: `capacityForecast(): {months[3]: {load_hours,capacity_hours,util_pct}, breach: bool}` — `ponytail: threshold const, per-machine split later if needed`

- [ ] **Step 1: Write failing test** — overloaded fixture → `breach=true` on month 2; no machines → `emptyResponse()`.
- [ ] **Step 2: Run test**

Run: `docker compose exec -T -e DB_DATABASE=ogami_test_verify api php artisan test --filter='CapacityForecastTest'`
Expected: FAIL

- [ ] **Step 3: Implement (plant-level aggregate only, no per-machine split)** — load from open WOs ÷ capacity × OEE derate.
- [ ] **Step 4: Provider + panel + mount on ppc + plant-manager**
- [ ] **Step 5: Run tests (incl. seed integrity), commit** `feat: capacity forecast on ppc dashboard`

### Task 8: New attendance forecast (hr)

**Files:**
- Create: `api/app/Modules/Dashboard/Services/AttendanceForecastService.php`
- Modify: `ForecastingDashboardService.php` (`attendanceForecast()`), new `AttendanceWidgetAnalytics.php`, `spa/src/pages/dashboard/hr.tsx`, new `AttendanceRiskPanel.tsx`
- Test: `api/tests/Feature/Dashboard/AttendanceForecastTest.php`

**Interfaces:**
- Consumes: `attendances` rate trend + approved `leave_requests` pipeline; horizon 3-mo
- Produces: `attendanceForecast(): {months[3]: {expected_rate,leave_load}, risk: low|medium|high}`

- [ ] **Step 1: Write failing test** — rising leave-load fixture → `risk=high` month 3; no attendance rows → `emptyResponse()`.
- [ ] **Step 2–5: Same cycle as Task 6** — implement ≤80 lines mirroring `headcountForecast()`, provider + seeder + read-only panel, run `CapacityForecastTest`-style filter + seed test, commit `feat: attendance forecast on hr dashboard`

---

## Self-Review

1. **Spec coverage:** All-roles scope → Tasks 1/2/4 touch all 9 pages. Consistent strip → Task 1. Strict-by-module compact → Task 1 code lists. Scorecard removal + admin compute + redirect → Task 3. Top-8 SLA link-out → Task 2. Existing forecasts → Task 5. New cash/capacity/attendance 3-mo read-only → Tasks 6/7/8. Poll+stale → Task 4. Phased → task order is the phase order (1–4 = Phase 1, 5 = Phase 2, 6–8 = Phase 3).
2. **Step scan:** Each step names one artefact + checkable command; no TBDs; bodies only where algorithm needed (Tasks 6–8 name the reused method).
3. **Type consistency:** `cashFlowForecast/capacityForecast/attendanceForecast` all return `{months[3], confidence|breach|risk}`; widget keys `forecast.cash_flow|capacity|attendance` (`render_kind=trend`); panels `CashFlowPanel|CapacityPanel|AttendanceRiskPanel`.
4. **Review Focus:** Each of the 5 lines maps to a task test (stale→T4, Restricted→T2, forecasting.view strip→T5, scorecard redirect→T3, poll cost→T4).
5. **Proportion:** Plan is interface + file map, not code transcript; service bodies intentionally left to implementer (≤80-line mirrors).
