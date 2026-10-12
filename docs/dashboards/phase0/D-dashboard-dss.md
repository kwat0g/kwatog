# SECTION D — Existing Dashboard & DSS (Phase 0 discovery)

Branch `docs/dss-discovery-phase0` @ `122fcbb1`. Dashboard overhaul commits `dcd56028..122fcbb1`
(top-8 approvals, scorecard removal, freshness polish, existing + 3 new forecasts).
System described **as it is now**. Every claim cites a primary source.

---

## 1. Dashboards: pages, endpoints, widget catalog, dispatch, layout

### 1.1 The 8 bespoke + default + admin + self-service pages

`DashboardCatalog::all()` (`api/app/Modules/Dashboard/Support/DashboardCatalog.php:35-46`) is the
single registry — 8 purpose-built dashboards, keyed by **permission, never role name**:

| key | SPA path | gating permission | backend service |
|---|---|---|---|
| `admin` | `/dashboard/admin` | `dashboard.admin.view` | `AdminDashboardService::admin` (`Services/AdminDashboardService.php:36`) |
| `plant-manager` | `/dashboard/plant-manager` | `dashboard.plant_manager.view` | `PlantManagerDashboardService::plantManager` (`Services/PlantManagerDashboardService.php:41`, `?range=today\|week\|month\|quarter`) |
| `hr` | `/dashboard/hr` | `dashboard.hr.view` | `HrDashboardService::hr` (`Services/HrDashboardService.php:36`) |
| `ppc` | `/dashboard/ppc` | `dashboard.ppc.view` | `PpcDashboardService::ppc` (`Services/PpcDashboardService.php:39`) |
| `finance` | `/dashboard/finance` | `dashboard.accounting.view` | `FinanceDashboardService::summary` (`Services/FinanceDashboardService.php:57`; canonical path `/dashboard/finance`, `/dashboard/accounting` redirects — `DashboardCatalog.php:40-42`) |
| `purchasing` | `/dashboard/purchasing` | `dashboard.purchasing.view` | `PurchasingDashboardService::purchasing` (`Services/PurchasingDashboardService.php:36`) |
| `warehouse` | `/dashboard/warehouse` | `dashboard.warehouse.view` | `WarehouseDashboardService::warehouse` (`Services/WarehouseDashboardService.php:35`) |
| `quality` | `/dashboard/quality` | `dashboard.quality.view` | `QualityDashboardService::quality` (`Services/QualityDashboardService.php:37`) |

Plus:
- **Default / generic widget home** (`DashboardCatalog::DEFAULT_PATH = '/dashboard/default'`,
  `DashboardCatalog.php:28`; SPA `spa/src/pages/dashboard/default.tsx`) — fallback for users who
  qualify for no bespoke dashboard; also the primary surface for `department_head`,
  `maintenance_tech`, `impex_officer`, `driver`, `employee` (see §1.4).
- **Employee self-service** (`GET /dashboards/employee`, no permission gate beyond auth —
  `routes.php:38`; `HrDashboardService::employee`, `HrDashboardService.php:130-158`): 3 KPIs
  (month attendance, leave remaining, pending requests) + latest payslip + next holiday. Returns a
  notice when the account has no linked employee (`HrDashboardService.php:132-134`).
- **Admin page shows no business KPIs** — system health only (sessions, account security, auth
  events, queue health, audit trail, alerts; `AdminDashboardService.php:18-24`).

API endpoints (`api/app/Modules/Dashboard/routes.php`):
- `GET /api/v1/dashboards/{plant-manager,hr,ppc,accounting,employee,purchasing,warehouse,quality,admin}`
  (`routes.php:17-50`; `DashboardController.php:33-76`). Note: `accounting` action delegates to
  `PpcDashboardService::accounting` (small-page grouping, `PpcDashboardService.php:20-26`), while the
  richer finance surface is `GET /dashboards/finance` → Accounting's `FinanceDashboardController::summary`.
- `GET /api/v1/dashboards/badges` (self-gating per key, `routes.php:58-61`; `BadgeService`).
- `GET /api/v1/dashboards/action-center` + `PATCH /dashboards/action-center/tasks`
  (gated `dashboard.action_center.view`, `routes.php:69-74`; approvals deliberately excluded since 2026-09,
  `ActionCenterService.php:25-33`).
- `GET /api/v1/dashboards/rollout-health` (`dashboard.admin.view`, `routes.php:75-76`).
- Layout plane: `GET /dashboard/widgets|layout|widget-data|dispatch`, `PUT /dashboard/layout`,
  `POST /dashboard/layout/reset` (`routes.php:84-104`; `DashboardLayoutController.php:24-105`).
- KPI plane (still live after scorecard-page removal): `GET /dashboard/kpi/scorecard|trends|trend/{code}`,
  `POST /dashboard/kpi/compute` (admin-only, `routes.php:112-120`; `KpiController.php:16-53`).

### 1.2 What each bespoke page shows (KPIs + panels + gate per item)

All bespoke pages return `{kpis: [...], panels: {...}}`; every KPI/panel declares its own permission
via `PanelGate::kpis/panels` (`Support/PanelGate.php:39-74`). Refused items are **omitted, not zeroed**
(`PanelGate.php:21-24`). All bespoke payloads cached 30 s per user (`CACHE_TTL = 30` in each service);
finance uses a shared key suffixed by a gate-answer signature (`FinanceDashboardService.php:57-68`,
`PanelGate::signature`, `PanelGate.php:92-101`).

- **Plant Manager** (`PlantManagerDashboardService.php:50-82`): KPIs Revenue (gate
  `accounting.dashboard.view`), Production, OEE-today, OTD (page grant). Panels: chain stages,
  alerts, machine util, defect pareto, **financial snapshot gated `accounting.dashboard.view`**
  (cash/AR/AP/revenue-MTD/draft-JE count — the D2 leak fix; production_manager holds no accounting
  grant), `capacity_forecast` (gate `forecasting.view`), range echo.
- **PPC** (`PpcDashboardService.php:42-80`): KPIs Active WOs, Material Shortages (auto-generated
  pending PRs, gate `mrp.plans.view`), Capacity Used, Molds ≥ warning% (threshold label rendered from
  `alerts.mold.warning_ratio`). Panels: chain stages, alerts, machine util/availability grid,
  mrp_last_run (relative time), unplanned WOs, production Gantt (8 machines × `gantt_horizon_days`),
  mrp_shortages (top 10 auto-PR lines), `capacity_forecast`, WO status breakdown.
- **Accounting (small)** (`PpcDashboardService.php:82-99`): 4 KPIs (cash, AR, AP, draft JEs) + recent
  JEs + top-5 overdue AR customers. The **rich finance page** (`FinanceDashboardService.php:76-107`):
  18 panels incl. cash, revenue MTD, AR/AP outstanding + aging, AP due-this-week (8 rows, horizon
  `dashboard.widgets.ap_due_horizon_days`), recent/unposted JEs, current accounting period,
  pending credit notes, payroll pipeline (6 lifecycle stages, 90-day window
  `dashboard.finance.payroll_pipeline_history_days`), budget-vs-actual top-5 by overspend,
  `revenue_forecast`, `cash_flow_forecast`.
- **HR** (`HrDashboardService.php:47-88`): KPIs headcount, on-leave-today, pending leave, open
  clearances. Panels: headcount by dept, recent hires (5), pending leaves (8), today's attendance
  summary (present/late/absent-derived/on-leave — absent is **derived**: active − present − late −
  on-leave, `HrDashboardService.php:177-201`), probation alerts, leave calendar (horizon setting),
  month calendar (holidays + ≤10 birthdays), pending-my-action, `headcount_forecast` (gate
  `hr.employees.view`, NOT `forecasting.view` — deliberate), `attendance_forecast` (gate
  `attendance.view`), payroll summary (latest period + headcount + net total + pending adjustments,
  gate `payroll.periods.view` — REC-05 fix, `HrDashboardService.php:37-46`).
- **Purchasing** (`PurchasingDashboardService.php:39-69`): KPIs pending PRs, open POs, overdue
  deliveries (vs `po.expected_delivery_date`), suppliers-due-review (score < `tier_c_min` = 60).
  Panels: PR action queue (10, with est. total + days-waiting), PO pipeline (9 statuses, non-zero
  only), top-5 suppliers, upcoming deliveries (8, receivable statuses within horizon),
  `supplier_risk` composition (gate `forecasting.view`).
- **Warehouse** (`WarehouseDashboardService.php:38-68`): KPIs pending GRNs, issues today, low-stock
  items, pending transfers. Panels: incoming queue (receivable POs ≤ horizon, 8), outgoing queue
  (scheduled/picking deliveries + customer names, gate `supply_chain.deliveries.view`), low-stock
  alerts (10, with preferred-supplier subselect), zone utilization (occupied/total/% per zone +
  warning/critical ratio settings).
- **Quality** (`QualityDashboardService.php:40-68`): KPIs pending inspections, pass-rate-today,
  open NCRs, CoCs-generated-MTD. Panels: inspection queue (10, outgoing-first ordering),
  defect pareto, NCR list (5, severity-ordered), chain coverage (incoming/in-process/outgoing
  inspected-vs-total + %), `defect_rate_forecast` (gate `quality.view`, NOT forecasting),
  plus a `display_policy` block exposing 5 colour thresholds to the SPA.
- **Admin** (`AdminDashboardService.php:36-54`): KPIs active sessions, locked accounts, failed logins
  (window `security.auth_history_window_hours`), failed jobs. Panels: active sessions (15), account
  security (incl. ≥3-attempt at-risk count), auth events (breakdown + hourly success trend + 20
  failures), queue health (pending/failed + 10 recent; `healthy` = 0 failed AND pending <
  `dashboard.admin.pending_jobs_warning_threshold` = 100), recent audit (15), open alerts by severity.

### 1.3 Widget catalog: 76 keys (65 catalog + 11 KPI)

Source: `DashboardWidgetSeeder::catalog()` (`api/database/seeders/DashboardWidgetSeeder.php:175-286`)
+ `kpiCatalog()` (`:128-143`). Each row declares **permission / render_kind / link_path / module**
independently (`DashboardWidgetSeeder.php:19-23`); every key has an "Open →" link
(`LINK_BY_KEY`, `:48-105`; KPI keys link to owning dashboard + `#kpi-<code>` anchor, `:298-332`).
`WidgetSeedIntegrityTest` asserts registry↔provider↔link bijection (per CLAUDE.md).

| key pattern | permission (representative) | render_kind | data source (`DashboardWidgetDataService::summary`, `DashboardWidgetDataService.php:97-211`) | rich payload (`WidgetAnalyticsService` providers) |
|---|---|---|---|---|
| `production.kpi` | `production.dashboard.view` | trend | units recorded today | 14-day good-output trend (`CoreWidgetAnalytics::outputTrend`, `Analytics/CoreWidgetAnalytics.php:94-116`) |
| `production.active_wo`, `.wo_breakdown`, `.gantt_mini` | `production.work_orders.view` / `.schedule.view` | scalar/breakdown/scalar | open-WO count / status breakdown / scheduled ≤ N days | WO status mix w/ tones (`CoreWidgetAnalytics.php:149-175`); gantt scalar-only |
| `machine.utilization`, `oee.gauges` | `production.dashboard.view` | gauge | % machines running | 7-day availability gauge, target 85 (`CoreWidgetAnalytics.php:123-140`) |
| `machine.status` | `mrp.machines.view` | scalar | running count + breakdown helper | — (scalar only) |
| `chain.stage_breakdown` | `dashboard.view_bottlenecks` | scalar | active O2C chains | — |
| `qc.pareto` | `quality.view` | breakdown | defects this month | top-6 defect params via `DefectParetoService` (`CoreWidgetAnalytics.php:73-87`) |
| `qc.pending_inspections` / `qc.open_ncrs` | `quality.inspections.view` / `quality.ncr.view` | scalar | draft+in-progress / non-closed | — |
| `qc.pass_rate` | `quality.view` | scalar | passed/(passed+failed) | — |
| `mrp.shortages` | `mrp.plans.view` | scalar | Σ shortages in active plans | — |
| `material.reservations` | `mrp.view` | scalar | Σ reserved qty | — |
| `finance.cash_position` | `accounting.dashboard.view` | scalar | posted cash-account balance | — |
| `finance.ar_aging` | `accounting.invoices.view` | breakdown | open AR balance | 4-bucket aging on **balance** (`CoreWidgetAnalytics.php:185-212`) |
| `finance.ap_aging` / `.revenue_mtd` / `.unpaid_invoices` / `.upcoming_payables` | bills/invoices/dashboard | scalar | open AP / MTD invoiced / count w/ balance / due ≤ N days | — |
| `hr.headcount` | `hr.employees.view` | breakdown | active count (dept-scoped unless `view_sensitive`) | per-dept segments, same scope (`CoreWidgetAnalytics.php:221-249`) |
| `hr.on_leave_today` | `hr.employees.view_sensitive` | scalar | approved covering today | — |
| `hr.team_on_leave_today` / `hr.team_dtr_today` | `leave.view` / `attendance.view` | scalar | dept-scoped; null+helper if unlinked | — |
| `hr.probation_alerts` | `hr.employees.view` | scalar | regularizations due ≤ N days | — |
| `payroll.upcoming` | `payroll.periods.view` | date-kind | next non-voided period date+status | — |
| `approvals.pending` | **null** (self-scoped) | table | count awaiting caller's role | **top-8 worklist** Document/Type/Waiting, SLA-overdue-first, delegation-aware (`Analytics/ApprovalsWidgetAnalytics.php:28-99`, LIMIT 8 `:31`) |
| `purchasing.open_prs` / `.open_pos` | `purchasing.view` | scalar/breakdown | row-scope-filtered counts | PO status mix w/ tones (`CoreWidgetAnalytics.php:256-287`) |
| `purchasing.supplier_perf` | `purchasing.suppliers.performance.view` | scalar | latest-period avg overall score | — |
| `supply.overdue_deliveries` / `.delivery_schedule` | `supply_chain.deliveries.view` | table/table | past-date open / scheduled ≤ N days | `SupplyChainWidgetAnalytics` |
| `inventory.low_stock` / `.pending_grns` / `.pending_issues` | `inventory.view` | scalar | available ≤ reorder / non-terminal GRNs / draft slips | — |
| `self.*` (4) | null (own employee) | scalar/decimal/hours | latest finalized payslip / leave sum / today's hours / own pendings | — |
| `alerts` | `alerts.view` | scalar | open alerts count | — |
| `forecast.headcount` / `.revenue` / `.defect_rate` | same-as-dataset (`hr.employees.view` / `accounting.dashboard.view` / `quality.view`) | scalar | `ForecastingDashboardService` kpi pass-through; **null ("no data") when empty, never 0** (`DashboardWidgetDataService.php:226-248`) | trend via `CashFlow/Capacity/AttendanceWidgetAnalytics` for the 3 new ones |
| `forecast.cash_flow` / `.capacity` / `.attendance` | `forecasting.view` / `forecasting.view` / `attendance.view` | trend | same pass-through | full chart payloads |
| `maintenance.open_wos` / `.due_schedules` | `maintenance.view` | scalar | open statuses / due ≤ N days | — |
| `assets.under_maintenance` | `assets.view` | breakdown | count out of service | `AssetWidgetAnalytics` |
| `rma.open_returns` / `.pending_approval` | `return_management.view` | breakdown/table | in-progress / awaiting decision | `ReturnWidgetAnalytics` |
| `crm.open_complaints` | `crm.complaints.view` | breakdown | complaint status distribution | `CrmWidgetAnalytics` |
| `sales.order_intake` / `sales.open_orders` / `sales.price_requests` | sales-order / price-agreement read | trend / breakdown / breakdown | orders by order date in trailing 12 months / non-terminal order book / active price requests | monthly order-intake trend, order stages, and request statuses (`CrmWidgetAnalytics`) |
| `budget.utilization` | `budgeting.view` | gauge | spent-or-committed % of approved (null when no budget) | `BudgetWidgetAnalytics` |
| `loans.outstanding` | `loans.view` | table | dept-scoped unless `loans.write_off` | `LoanWidgetAnalytics` |
| `kpi.*` (11: oee, wo_completion_rate, dppm, first_pass_yield, ncr_closure_days, on_time_delivery, supplier_quality, attendance_rate, ar_aging_60d, budget_utilization, inventory_turnover) | `KpiSnapshotService::MODULE_PERMISSIONS[module]` | trend | latest snapshot value + period/target/status helper; null before first compute (`DashboardWidgetDataService.php:309-346`) | 12-month points + delta + target-at-time + status (`Analytics/KpiWidgetAnalytics.php:44-92`) |

Other providers: `Asset/Loan/Return/SupplyChain/Budget/CrmWidgetAnalytics` (table/breakdown/gauge
payloads per key). Scalar failures degrade per-widget to `available:false` + "Live data source
unavailable" (`DashboardWidgetDataService.php:59-70`); rich failures to scalar + `data_unavailable`
flag (`DashboardLayoutService.php:70-82`, `WidgetAnalyticsService.php:69-80`).

### 1.4 Dispatch rules + layout persistence

- **Dispatch** (`Services/DashboardDispatchService.php:30-80`): candidates = catalog entries whose
  permission the user holds (incl. admin wildcard + overrides); rarest-permission-first wins, rarity
  counted **live** from `role_permissions` (`:89-104`), ties on key. No match → `/dashboard/default`.
  `GET /dashboard/dispatch` returns `{target, candidates}`; candidates back the switcher
  (`DashboardLayoutController.php:38-48`).
- **Layout resolution** (`DashboardLayoutService.php:36-58`): personal rows → role-default rows →
  empty; forbidden widgets stripped at render on both plain and rich paths (`:277-315`). First login
  clones role defaults under row lock (`:102-145`). Save = optimistic concurrency via sha256 version
  (`saveUserLayout`/`resetUserLayout`, `:150-219`); saves silently drop forbidden/duplicate keys
  (`:177-181`). Personal rows that all become forbidden fall back to role default, not blank (`:44-55`).
- **Role defaults** (`DashboardRoleLayoutSeeder.php:31-129`): 14 roles seeded; holders of a bespoke
  dashboard lead their defaults with the matching widgets + **exactly one KPI tile each**
  (production_manager `kpi.oee`, ppc_head `kpi.on_time_delivery`, finance `kpi.ar_aging_60d`, hr
  `kpi.attendance_rate`, purchasing `kpi.supplier_quality`, qc `kpi.dppm`, warehouse
  `kpi.inventory_turnover`; `DashboardRoleLayoutSeeder.php:22-28`). New-on-branch: production_manager
  + ppc_head gained `forecast.capacity`; finance gained `forecast.cash_flow`; hr gained
  `forecast.attendance`. `department_head` is approvals-first + team + self widgets (no bespoke page).
- **Freshness polish (branch, `0ca24d35`)**: scalar `updated_at` per widget, `WIDGET_STALE_AFTER_MS =
  120_000` shell stale badge, five-state rendering (loading/error/empty/data/stale) on shell, grid,
  KpiStrip and forecast/stock-out panels (`spa/src/api/dashboard-layout.ts`,
  `spa/src/components/dashboard/DashboardShell.tsx`, `spa/src/pages/dashboard/default.tsx:58-61`).
- **KPI strip (branch, `42b3e3e9`)**: every bespoke page header renders `<KpiStrip codes={...}
  compact />` with per-page code lists in `spa/src/lib/dashboardKpis.ts` (e.g. plant-manager:
  oee/wo_completion_rate/first_pass_yield; admin/default: none). Standalone scorecard page deleted;
  KPI tiles link to owning dashboard + `#kpi-<code>` anchor.
- **Top-8 approvals (branch, `dcd56028`)**: `approvals.pending` renders the caller's top-8 queue
  (Document/Type/Waiting + SLA-overdue sort + link-out) on all dashboards carrying the widget.
  Payroll periods are sourced separately from `approval_records`, gated by view/approve grants and
  the maker-checker rule; ten custom single-step queues also have permission/read-scoped cards, with
  decisions remaining on their owning module endpoints. GRN refusal, unrouted asset transfer, return,
  PPAP and per-work-order late-report queues remain outside the global board (C§4/§5).

---

## 2. KPI system

- **Definitions (11)**: `KpiDefinitionSeeder.php:15-26` — oee (target 85, warn 75, higher),
  dppm (500/750, lower), first_pass_yield (98/95, higher), on_time_delivery (95/90, higher),
  supplier_quality (90/80, higher), attendance_rate (96/93, higher), ar_aging_60d (5/8, lower),
  budget_utilization (90/80, higher), ncr_closure_days (5/8 days, lower), inventory_turnover
  (6/4 ratio, higher), wo_completion_rate (95/90, higher). Status = OnTarget/Warning/OffTarget vs
  target+warning with direction (`KpiSnapshotService.php:287-314`); trend = Up/Down/Flat on ±1% band
  vs previous month (`:272-285`).
- **Compute schedule**: `kpi:compute-monthly` → 2nd @ 03:00 for previous month
  (`api/routes/console.php`, "Task 14" comment) → `KpiSnapshotService::computeAll`
  (`KpiSnapshotService.php:59-91`): per-definition isolation, `updateOrCreate` keyed
  definition+period (idempotent), **null (no observation) persists nothing** — scorecard shows
  definition without snapshot (`:93-106`). Failures return per-code errors; manual
  `POST /dashboard/kpi/compute` returns 422 when any failed (`KpiController.php:40-53`).
- **Staleness**: by design **one month behind** — tiles read `kpi_snapshots`, never recompute
  (`DashboardWidgetDataService.php:295-308`); CLAUDE.md cron note confirms early-month "missing" KPI
  is expected. AR-aging lookback window is itself a setting
  (`dashboard.kpi_snapshot_lookback_days` = 60, migration `0323`).
- **Per-role distribution**: read boundary `MODULE_PERMISSIONS`
  (`KpiSnapshotService.php:41-49`: production→`production.dashboard.view`,
  quality→`dashboard.quality.view`, supply_chain→`dashboard.ppc.view`,
  purchasing→`dashboard.purchasing.view`, attendance→`dashboard.hr.view`,
  accounting→`accounting.dashboard.view`, inventory→`dashboard.warehouse.view`; unmapped = ungated).
  Enforced identically on scorecard/trend/trends endpoints (`:138-260`) and reused as the `kpi.*`
  widget permission (`DashboardWidgetSeeder.php:156-170`). Branch change: each bespoke page strip
  shows only its roles' KPIs (`spa/src/lib/dashboardKpis.ts`); generic-dashboard roles pick any
  qualifying `kpi.*` widget.
- **Calculator notes / known gaps** (all in `KpiSnapshotService.php`):
  - OEE (`:318-374`): availability×performance×quality over completed/closed WOs; scheduled minutes
    from machine `available_hours_per_day` × weekdays — ignores shifts/holidays/downtime calendars.
  - WO completion (`:612-642`): denominator and numerator use the same due-in-month cohort; a late
    completion remains a miss in its due month and cannot inflate a later month's rate.
  - Inventory turnover (`:558-585`): annualised monthly-issues / **current** (not period-average) stock
    value — no historical stock snapshot exists.
  - Budget utilization (`:527-541`): fiscal-**year** figure, month arg ignored.
  - AR-aging (`:491-518`): snapshot of **current** open balances evaluated against period end, not a
    true historical aging.

---

## 3. Forecasting inventory

### 3.1 Demand forecasting engine (`ForecastingService`, `api/app/Modules/Forecasting/Services/ForecastingService.php`)

- **Methods**: `moving_avg` (simple mean), `weighted_avg` (linear 1..N recency weights), `manual`
  override (`ForecastingService.php:17-27, :444-477`). History = confirmed-demand quantities from
  `sales_order_items` ⨝ `sales_orders` (excludes cancelled+draft), zero-filled complete series
  (`:36-82`). Cron: `forecasting:generate` monthly 1st @ 04:00 over active products
  (`api/routes/console.php`); manual rows protected unless `overwriteManual` (`:150-198`).
- **Reconcile/accuracy**: `forecasting:reconcile-actuals` monthly 2nd @ 04:00 backfills
  `actual_quantity` + `variance` for elapsed periods, idempotent (`:257-298`). `accuracy(year)` →
  **MAPE + bias** over reconciled rows with actual > 0, plus per-month ape table (`:306-442`);
  per-customer rows collapsed preferring the null-customer total (FC-01 double-count guard,
  `:358-395`). Surfaced on `ForecastAccuracyPanel` and inside `supplierRisk` (`ForecastingDashboardService.php:328`).
- **Confidence**: `ForecastConfidence::fromSeries` = 100 − 100·σ/μ clamped 0–100; null when n<2 or
  mean ≤ 0 (`Support/ForecastConfidence.php:10-28`). Pure dispersion — ignores sample size, trend,
  and regime change.
- **Right**: zero-filled series, advisory-lock serialisation per key (`:485-500`), manual-override
  protection, idempotent reconcile, double-count guard. **Wrong**: no seasonality/trend/promo/
  customer-churn handling; MAPE undefined for zero-actual months (dropped, biasing toward stocked
  periods); lookback fixed at 6 default (`forecasting.default_lookback_months`).

### 3.2 Stock-out projection (`StockOutProjectionService`, `Services/StockOutProjectionService.php`)

- `days_until_stockout = (available − safety_stock) / daily_demand`, demand = next-month demand-forecast
  daily rate when an item code matches a product part-number, else 30-day consumption average
  (`:92-117`). Zero demand → null (no risk). Suggested qty = max(MOQ, lead-time × demand × 1.20 buffer);
  zero-lead items use 0.50 × reorder point (`:121-132`). Risk bands: critical ≤ lead time, high ≤
  lead+7d, medium ≤ 30d (`:134-145`; settings `inventory.stockout.*`, migrations `0297`/`0315`).
  Worst-first sort; horizon filter default 60 d (`:147-182`).
- **Right**: forecast-then-history fallback with recorded `demand_source`, MOQ respected, BC-math.
  **Wrong**: forecast↔item join is a **code-equality hack** across domains (finished-good forecasts
  applied to raw materials sharing a code — `:78-81` admits it); no open-PO/commitment netting
  (unlike `AutoReplenishmentService`); no lead-time variability; horizon culls far risks silently.

### 3.3 Forecast→MRP projection (`ForecastMrpService`, `Services/ForecastMrpService.php`)

Explodes target-month demand forecasts through active BOMs (only products with
`include_forecast_in_mrp`), nets vs on-hand−reserved + safety, biggest-shortage-first
(`:40-111`, `:119-173`). **Advisory only — creates no PRs** (`:22-24`). FC-01 total-row guard,
decimal-safe accumulation. Wrong: single-month snapshot, no capacity check, products without BOMs
silently flagged `has_bom=false`.

### 3.4 Dashboard forecast panels (all moving-average + seasonal-adjust + linear-regression trend)

Shared math: `ForecastTrend::direction` — least-squares slope vs `slope_ratio` (0.03) × mean or
`minimum_slope` (0.5) floor (`Support/ForecastTrend.php:10-25`; settings migration `0351`);
history 12 mo / horizon 3 mo (`forecasting.default_history_months/horizon_months`, migration `0332`).

| panel | engine | inputs | horizon/output |
|---|---|---|---|
| Headcount | `ForecastingDashboardService::headcountForecast` (`:47-124`) | active employees, hired ≤ month-end | 12 history + 3 forecast; seasonal adjust; KPI = avg forecast |
| Revenue | `::revenueForecast` (`:136-208`) | posted JEs on revenue-type accounts | same shape; KPI = Σ forecast |
| Defect rate | `::defectRateForecast` (`:223-307`) | completed inspections' defect/sample sums; **empty months omitted, not 0%** (`:259-261`) | same shape; no seasonal term |
| Supplier risk (purchasing) | `::supplierRisk` (`:321-356`) | stock-out rows + demand MAPE/bias | counts (at-risk/critical/high) + top-5 items + mape/bias/periods |
| Cash flow (Task 6, branch) | `CashFlowForecastService::forecast` (`Services/CashFlowForecastService.php:20-51`) | monthly invoice totals (non-draft/cancelled) minus bill totals | inflow/outflow/net per month; KPI = Σ net; empty when no AR+AP |
| Capacity (Task 7, branch) | `CapacityForecastService::forecast` (`Services/CapacityForecastService.php:23-46`) | open WOs spread evenly over planned spans vs machine-hours × days × **OEE derate** (latest OEE snapshot; unknown = 1.0, `:63-71`) | util% per month; **breach flag at hardcoded 90%** (`BREACH_UTIL_PCT`, `:18`); no historical series |
| Attendance (Task 8, branch) | `AttendanceForecastService::forecast` (`Services/AttendanceForecastService.php:23-53`) | monthly present-rate from attendances + approved-leave load vs headcount×days | expected rate + leave-load% + **risk low/med/high at hardcoded 8%/15%** (`:17-18, :55-58`) |

**What they get right**: uniform shape the SPA charts directly; empty-history → em-dash + null (never
fake zero); read-only; Schema-guarded for fresh installs; trend math unified in one helper.
**What they get wrong / leave out**: flat moving-average extended unchanged into every future month
(no growth, no seasonality except headcount/revenue, no hiring-plan/termination pipeline, no leave
seasonality, no shift-calendar or holiday effects); capacity spreads WO hours evenly and aggregates
plant-wide (no per-machine bottleneck, no setup/changeover, hardcoded 90%); attendance projects a
single historical mean forward (leave-load only affects the risk label, not the rate); revenue reads
GL postings, not order pipeline; cash flow ignores payment behaviour/terms (due dates, dunning
tiers); confidence is dispersion-only.

---

## 4. DSS-behavior catalog (whole project)

Format: behavior → exact rule/threshold + config location → what it gets wrong/omits.

**Alert engine** (`AlertEngineService`, `api/app/Common/Services/AlertEngineService.php`; runs
`alerts:run` every 15 min; dedup 24 h `alerts.dedup_window_hours`):
1. `stock_critical` (critical): usable < safety_stock (safety > 0). 2. `stock_low` (warning): usable
   < reorder_point. 3. `no_supplier` (warning): low-stock item with no qualified approved supplier
   (`checkInventory`, `:298-379`). *Omits: open-PO coverage (replenishment service nets it, alerts don't).*
4. `machine_breakdown` (critical): machine status = breakdown. 5/6. `mold_shot_limit` (warning ≥
   **0.80**) / `mold_shot_critical` (critical ≥ **0.95**) — `alerts.mold.*_ratio`, must be ordered
   (`:382-436`). 7. `wo_overdue` (warning): open WO with planned_end < now. 8. `mrp_schedule_conflict`
   (warning): invalidated capacity reservation / execution overrun, notifies ppc_head +
   production_manager (+ maintenance_tech if machine/mold, + sales_officer on delivery risk).
   9. `oee_below_threshold` (warning): machine quality < **0.75** over **3 d**, min **100** outputs
   (`alerts.oee.*`, `:496-541`). *Quality-rate only — not true OEE.*
10. `ar_overdue_30` (warning ≥ **30 d**) / `ar_overdue_60` (critical ≥ **60 d**) —
    `alerts.ar.*_overdue_days` (`:545-601`). 11. `ap_due_soon` (info): due today..+**3 d**,
    `alerts.ap.due_soon_days`; overdue bills excluded. 12. `qc_fail_rate_high` (warning): product
    scrap > **0.05** over **24 h**, min **100** outputs (`alerts.quality.*`, `:618-663`).
13. `scheduler_stale`: ledger-driven stall/partial-failure signal; explicitly NOT a dead-man switch
    (raiser is itself scheduled; outside coverage = Docker healthcheck `scheduler:health`, `:665-745`).

**Escalations / SLAs**:
14. Approval reminders + escalations: `approvals.reminder_hours` = **24**, `approvals.escalation_hours`
    = **48** (SettingsSeeder `:151-152`); `approvals:run-escalations` every 6 h;
    `ApprovalEscalationService::runWithOutcome` reports failed/unstaffed distinctly.
15. NCR SLA: `ncr:escalate` every 15 min; tiers notify configured roles, counts unstaffed roles
    (`RunNcrEscalations.php:23-40`).
16. 8D SLA: `complaints:check-8d-slas` every 15 min (D3/D4/finalize).
17. CAPA effectiveness: `ncr:check-effectiveness` daily 02:05; check interval **30 d**, escalation
    **14 d** overdue (`quality.effectiveness.*`).
18. Action Center item SLAs: default **24 h**, maintenance critical **8 h** / default **72 h**,
    production critical **48 h** / default **72 h** (migration `0297`).
19. Document review re-arm **7 d** (`quality.document_review.rearm_days`); PPAP expiry sweep daily
    (`quality:expire-ppaps`); calibration due window **30 d** (`quality.calibration.due_window_days`,
    `calibration:check-due` daily 06:50); training expiry tiers **30/14/7/expired**
    (`training:check-expiries` daily 06:30, idempotent per day).

**Inventory / procurement automation**:
20. MRP auto-PR: daily `mrp:run-daily` (06:00) nets shortages → **one consolidated draft PR**
    (`is_auto_generated`, `MrpEngineService.php:414-508`); stale-run reaper hourly (2 h heartbeat).
    Safety buffer **2 d** (`mrp.safety_buffer_days`); fallback lead time **14 d**.
21. Auto-replenishment: `AutoReplenishmentService::checkAndReplenish` — position (usable + in-transit
    + QC-held) + commitments (open PRs + unapproved auto-POs) vs reorder point; below → draft PR
    (`Services/AutoReplenishmentService.php:30-110`); **critical items with one preferred supplier skip
    PR → auto-PO to VP**; reconciled nightly (`inventory:reconcile-replenishment` 02:30); blocked
    states raise `replenishment_blocked` alerts.
22. Safety-stock recompute: nightly 02:15, **SS = Z·σ·√lead_time** (Z default 1.65 ≈ 95%,
    `inventory.safety_stock.service_level_z`; `SafetyStockRecomputeService.php:17-18, :90-148`).
    Skips zero-lead-time items and thin-demand histories.
23. PO auto-consolidation: fully-approved PRs → one draft PO per vendor; lines need preferred supplier
    + price or PR falls to `manual_required` (`ConsolidatePurchaseOrders`,
    `api/app/Modules/Purchasing/Listeners/ConsolidatePurchaseOrders.php`).
24. RFQ money: `RfqCommercialCalculator` — single calculator for quote totals, comparison delivered
    cost, PO carry-over (per CLAUDE.md).
25. Material reservations: WO/issue flow reserves `reserved_quantity`; available = qty − reserved
    (`MaterialIssueService.php:101, :282-336`); dashboards surface Σ reserved.

**Scoring / grading**:
26. Supplier performance: monthly `purchasing:recompute-supplier-performance` (1st 02:00);
    overall = weighted (on-time **0.25**, quality **0.35**, NCR **0.10**, price **0.15**, lead-time
    **0.15**); missing metric = **50** neutral; penalty factors NCR 2 / price 2 / lead-time 5; tiers
    A ≥ 90 / B ≥ 75 / C ≥ 60 / D below (migrations `0295`/`0339`/`0355`; service
    `SupplierPerformanceService.php:33-159, :528-546`). Deterioration fires alerts
    (`AlertOnSupplierDeterioration`). Targets: on-time 95, quality 98, price-var 5, lead-var 2.
27. Payroll anomaly flags (block finalize): net-change **0.30**, overtime **80 h**, deduction ratio
    **0.50** (`payroll.anomaly.*`).
28. Budget enforcement: warn **0.80** / critical **0.95** / exhausted **1.00** / overdrawn **1.20**;
    mode warn|block|off (`budgeting.enforcement_mode`; `BudgetEnforcementService.php:27-78`).
29. Predictive maintenance: thresholds temp ≤ **85 °C**, vibration ≤ **7.1 mm/s**, pressure 2–12 bar,
    current ≤ **150 A**, oil ≥ **70%**; **3 consecutive** breaches → corrective MWO
    (`maintenance.predictive.*`; `PredictiveMaintenanceService.php:100-229`).
30. Repeat breakdowns: daily sweep flags machines with repeated breakdowns (message cites 30-day
    window; `CheckMaintenanceRepeatBreakdowns.php:35`); assignment watchdog every 5 min.

**Cash / revenue hygiene**:
31. AR dunning: daily 07:00, tiers at **7/15/30** days (`accounting.ar_dunning.tier_days_csv`,
    migration `0294`), idempotent per-tier claims (`ArDunningService.php:29-119`).
32. Pricing review: daily 07:00 + per-minute dispatch-only recovery (`pricing:review-agreements`).

**HR / workforce**:
33. Auto payroll periods (14th/last-day + 23:30 reconcile), stale-run reaper hourly, payslip-email
    reconcile every 15 min (console.php A3 block).
34. Onboarding reminders: daily 09:00, open > **3 d** (`hr:onboarding-reminders`); recruitment
    bottleneck scan hourly; missing-timeout notice 07:00; leave year-end forfeiture Dec-31 23:00 +
    Jan 1–7 recovery window.
35. Probation: period months setting (`hr.probation.period_months`) + dashboard horizon 30 d.

**Quality loop closures**:
36. NCR→replacement WO: stock-batch failures create replacement/rework WO on NCR close (lead **7 d**,
    priority **5**; `quality.ncr.replacement_*`); SO-line failures owned by MRP re-plan instead
    (`WorkOrder::coversSalesOrderLine`, per CLAUDE.md). Incoming-QC handoff retried every 15 min
    (`grn:retry-pending-incoming-qc`); missing-outgoing-QC sweep hourly (`qc:sweep-missing-outgoing`).
37. Chain bottleneck scan hourly (24 h dedup; `chain:check-bottlenecks` + `ChainBottleneckWidget`).
38. Close-due-RFQs every minute; upcoming-WO notify every minute; production summaries 18:00 daily/Friday.

**Cross-cutting**: badge severity (danger > **20**, warning > 0 + per-badge overrides migration `0462`;
    TTL 30 s); notification digest 07:05; critical-alert email retry every 15 min; budget-vs-actual GL
    sync monthly; period auto-relock after 48 h open.

---

## 5. DSS opportunities (decisions with no system help — spreadsheet guesswork)

1. **Which supplier to award / reorder from** — no recommended vendor: score exists but no
   "suggested supplier" combining score + price + lead time + MOQ on a PR line; buyer eyeballs
   `purchasingTopSuppliers` vs quote sheets.
2. **How much to order** — suggested_qty exists only inside stock-out projection; PR lines carry no
   computed EOQ/coverage quantity; MRP writes shortage-only quantities.
3. **When to release a WO to the floor** — capacity forecast is plant-aggregate %; no per-machine
   earliest-feasible-date or sequencing suggestion; Gantt is display-only (no drag-to-reschedule,
   no what-if).
4. **Overtime / hiring decision** — attendance forecast shows a risk label but no "expected shortfall
   in hours → authorize N OT hours / open req" recommendation; probation + headcount panels don't
   project staffing gaps vs production load.
5. **Cash-commit decisions (pay run, big PO, capex)** — cash-flow forecast is a flat average with no
   scenario ("if we pay supplier X early / delay caisson Y"); no commit-vs-forecast guard at approval time.
6. **Price agreement renewal** — pricing review flags risk but offers no recommended price (no
   cost-plus / index / history-based suggestion); RFQ award has comparison cost but no "award
   recommendation" (split-award explicitly out of scope).
7. **Which NCR corrective action** — recurrence window (30 d) identifies repeats but nothing ranks
   likely root causes from defect pareto + machine + material history.
8. **Preventive-maintenance timing** — mold alerts fire at fixed 80/95% ratios; no usage-rate-based
   "schedule during next planned downtime on date D" suggestion; calibration due is a date, not a
   slot plan.
9. **Delivery promising / prioritisation** — no commit-date feasibility check at SO confirmation
   (capacity × material × QC lead time); overdue list exists but no "which order to expedite first
   for maximum OTD/penalty avoidance" ranking.
10. **Budget reallocation** — budget-vs-actual shows overspend top-5 but no "transfer ₱X from
    under-utilised line L" proposal; transfers are manual.
11. **Collection prioritisation** — dunning tiers + aging exist but no collector worklist ranked by
    recoverability (balance × days × customer history).
12. **Forecast method selection** — moving vs weighted average is an operator parameter; accuracy/MAPE
    exists but nothing auto-selects or blends the better method per product, and no promo/seasonality
    input exists at all.

---

## 6. Discrepancies vs docs · gaps / blockers

1. **Scheduled-command count**: CLAUDE.md cron note says "43 scheduled commands as of 2026-08-25";
   `api/routes/console.php` now registers **63** `Schedule::command/call` entries (counted). The
   in-code comment in `AlertEngineService.php:677-681` still says "42 scheduled entries". Both stale.
2. **Resolved 2026-10-12 — scorecard terminology.** The standalone page is deleted, but
   `GET /dashboard/kpi/scorecard` remains the shared data source for role `KpiStrip` components.
   E2E chart notes now name the strip explicitly.
3. **Resolved 2026-10-12 — KPI strip and widget windows.** The compact page strip requests six
   monthly points from `/dashboard/kpi/trends` for a quick scan; pickable `kpi.*` widgets retain
   12 months for deeper trend context. Both use the same KPI permission gates and snapshot series.
4. **Resolved 2026-10-12 — forecast permissions are surface-specific.** Shared forecast widgets
   follow the source-data gates: headcount uses `hr.employees.view`; revenue/cash flow use accounting
   gates; defect uses `quality.view`; capacity uses production gates; attendance uses attendance
   gates. Bespoke dashboard panels keep their service-declared gate, including `forecasting.view`
   for finance cash flow and plant/PPC capacity. The widget and panel paths may expose different
   forecast surfaces; each gate stays explicit.
5. **Resolved 2026-10-12 — capacity/attendance series semantics.** Capacity has no historical
   utilization source; its direction compares future projected loads, and confidence derives from
   projected load values. Attendance calculates recent recorded rates, uses their mean as the future
   expected rate, derives trend/confidence from those rates, and uses approved leave load for risk;
   no historical points are plotted. These are projections, not historical trend charts.
6. **Accepted policy defaults**: capacity breach is 90% (`CapacityForecastService.php:18`), attendance
   risk is 8%/15% (`AttendanceForecastService.php:17-18`). They stay constants until policy owners need
   runtime changes; the code comments record that upgrade trigger.
7. **Blockers for DSS work**: no per-machine load series (capacity), no payment-behaviour data
   (cash-flow realism), no historical stock snapshots (turnover accuracy), no demand-driver fields
   (promo/season/customer pipeline) on forecasts, no scenario/what-if persistence layer anywhere.
