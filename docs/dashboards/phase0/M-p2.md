# Phase 1 Dashboard/DSS Spec — P2 Roles (6)

Branch `docs/dss-discovery-phase0`. Part-author file: covers `production_manager`, `ppc_head`,
`purchasing_officer`, `warehouse_staff`, `qc_inspector`, `maintenance_tech`. Companion files:
`M-p1.md` (roles 1–6), `M-p3.md` (roles 13–17), synthesis `../01-matrix.md`.

**§0 shared contracts are stated once in `M-p1.md` §0** and are binding here too (auth/guard stack,
KPI grain + targets + gate map, finance dual-slug M1, approval-board coverage, notification status,
handoff-status dimension, frontend reuse, perf constraints, scope cuts). Only P2-specific deltas are
repeated below. Trace key: (00§X) = `../00-discovery.md` §X; (A/B/C/D/E/F §Y) = phase0 section files.
Spot-checks in this file were re-verified directly against
`api/database/seeders/RolePermissionSeeder.php` (grant lists), `DashboardWidgetSeeder.php`
(widget key → permission → `link_path`), `DashboardRoleLayoutSeeder.php` (role defaults) and
`spa/src/routes/*.tsx` (paths) — anything not so verified is marked **UNVERIFIED**.

## §0-P2. Deltas that bite these six roles

1. **Self-service slugs are shared by every role** — `attendance.view`, `leave.view`, `leave.create`,
   `payroll.view` are merged into **all 17** roles (`RolePermissionSeeder.php:1011-1019`). The two
   `hr.team_*` widgets remain on those gates because their payloads are department-scoped and null-safe;
   `forecast.attendance` now requires the attendance-planning read. This resolves G-P2-1 without hiding
   safe team tiles or exposing the company-wide forecast.
2. **`production.dashboard.view` remains absent from `ppc_head`** by design. Production widgets and
   KPI tiles now use the production data-read set, so PPC reads the schedule data it owns without gaining
   the unrelated page permission (G-P2-2, resolved 2026-10-11).
3. **KPI strip codes vs KPI snapshot gates still differ for purchasing and warehouse.** The strip passes
   its per-page code list to `GET /dashboard/kpi/trends`, which enforces
   `KpiSnapshotService::MODULE_PERMISSIONS` identically to the scorecard (D§2). PPC and PM now read all
   three listed codes after data-gate fixes; purchasing and warehouse read 1/2 because each remaining
   code belongs to another module. Those two gaps stay explicit rather than widening role grants
   (G-P2-3; see `01-matrix.md` §D2).
4. **Factory/driver PWAs are single-holder surfaces** — `production.operator.access` = PM (+admin)
   (A§5.6), `supply_chain.driver.access` = driver (+admin) (A§5.7). No operator-rank role exists, so
   the shop-floor surface for `employee`/`department_head` is `/self-service/work-orders`
   (`spa/src/routes/selfServiceRoutes.tsx`).

---

## R7. `production_manager` — Production Manager · page EXISTS (`/dashboard/plant-manager`)

(A§1.7, 00§A; catalog `DashboardCatalog.php`; dispatch `dashboard.plant_manager.view`, 3 holders;
`?range=today|week|month|quarter` on the payload — D§1.2.)

### 1. Persona & decisions

Runs the plant: WO lifecycle (create/confirm/output/cancel), schedule confirm, mold/machine master
data, factory PWA, QC review stamp, returns final step, salary-adjustment step 1. **Daily:** WO
overdue + machine breakdowns, OEE against 85, today's output vs plan, in-process QC failures, scrap
>5% (D§13). **Weekly:** WO mix vs capacity, unplanned-WO backlog, defect Pareto → CAPA, mold shot
position (80/95%), maintenance backlog. **Monthly:** OEE/FPY/WO-completion against target, capacity
forecast for the next quarter, headcount-vs-load (no system help — D§5.4).

### 2. KPI cards (as-built page KPIs + strip; 8)

| Card | Value | Delta / sparkline | Target | Click-through (`link_path` / route) |
|---|---|---|---|---|
| Revenue (page) | gate `accounting.dashboard.view` | — | — | **stripped for PM** (D§1.2 D2-leak fix) |
| Production / units today | `production.kpi` scalar (units recorded today) | 14-day output trend (`CoreWidgetAnalytics::outputTrend`) | — | `/production/dashboard` |
| OEE today | page KPI + `kpi.oee` snapshot (gate `production.dashboard.view` ✓) | 12-mo `kpi.oee` trend | 85 / 75 ↑ | `/production/oee` |
| OTD | page KPI (strip code `on_time_delivery` is gate `dashboard.ppc.view` ✗ → tile only) | MoM | 95 / 90 ↑ | `/supply-chain/deliveries` (page opens only with `supply_chain.deliveries.view` — **not held**; tile value comes from the page payload, so keep the link inside the page context or drop it) |
| WO completion | `kpi.wo_completion_rate` (gate ✓) | 12-mo trend | 95 / 90 ↑ | `#kpi-wo_completion_rate` anchor on `/dashboard/plant-manager` |
| First-pass yield | `kpi.first_pass_yield` (gate `dashboard.quality.view` ✗ → tile stripped) | — | 98 / 95 ↑ | — |
| Active WOs | `production.active_wo` scalar | vs yesterday | — | `/production/work-orders` |
| Mold ≥ warning / Machine util | page KPIs (threshold labels from `alerts.mold.warning_ratio`) | — | 80 / 95% | `/mrp/molds`, `/production/dashboard` |

Blocked snapshot tiles (gates unheld → stripped server-side): dppm/FPY/NCR (`dashboard.quality.view`),
OTD (`dashboard.ppc.view`), supplier-quality (`dashboard.purchasing.view`), attendance
(`dashboard.hr.view`), AR/budget (`accounting.dashboard.view`), turnover (`dashboard.warehouse.view`).

### 3. Graph analytics (7; as-built + 2 asked for by Phase 5)

| Chart (primitive) | Purpose → decision |
|---|---|
| Chain stage breakdown (Donut, `chain.stage_breakdown` → `/chains`) | where the plant's orders are stuck, incl. `manual_required` (00§blocker-9) |
| WO status mix (Bar, `production.wo_breakdown`) | WIP vs overdue → re-plan or escalate (D§alert-7) |
| Machine utilization / availability grid (Gauges, `machine.utilization`) | idle vs saturated machines → rebalance load (D§1.2) |
| Defect Pareto (Bar, `qc.pareto` → `/quality/dashboard`) | top defect params → CAPA focus (D§17) |
| Production Gantt (8 machines × `gantt_horizon_days`) | schedule reality check (display-only — D§5.3) |
| Capacity vs load (Area/`forecast.capacity` → `/production/schedule`) | quarter-ahead breach at hardcoded 90% (D§3.4 gap) |
| **New:** machine-level downtime Pareto (`DowntimeParetoChart` exists) | which machine to fix first → maintenance window |

### 4. Forecast widgets

`forecast.capacity` ✅ (`forecasting.view` ✓ → `/production/schedule`) · `forecast.defect_rate` ✅
(gate `quality.view` ✓, dataset-gate rule) · `forecast.cash_flow` ✅ gate-wise (`forecasting.view`)
but semantically out of scope for a plant manager — recommend dataset gate (00§D4). Headcount /
attendance / revenue / supplier-risk: gates unheld or wrong dataset → **data not available**.
Caveat to surface in UI: capacity has no history series (trend ≈ flat arithmetic, D§3.4).

### 5. Approval inbox & quick actions (act-only; `ApprovalSourceScope::visibleIds()`)

| Type | Step & gate | Quick action → endpoint | Page |
|---|---|---|---|
| Return request (standard) | step 2 `production_manager` (`WorkflowSeeder.php:171-178`) | approve/reject w/ remarks (`return_management.approve`) | `/return-management/:id` |
| Salary adjustment | step 1 `production_manager` (`hr.salary_adjustments.act`) | act → engine checks step role | `/hr/employees` (queue tab) |
| Company loan (legacy rows) | legacy step 2 (`loans.approve`) | approve/reject | `/hr/loans/:id` |
All three are ledger-wired → board cards exist; self-approval guard blocks an adjustment the PM
submitted themselves (C§2.6 stuck risk 4).

### 6. Alerts / exceptions

`wo_overdue` · `machine_breakdown` (critical) · mold 80%/95% · `mrp_schedule_conflict` (co-recipient
with PPC — D§8) · `oee_below_threshold` (quality-rate only, D§9) · `qc_fail_rate_high` (scrap >5%/24h)
· `scheduler_stale` (D§13) · Action Center critical 48h SLA (D§18) · repeat breakdowns + assignment
watchdog (D§30). Realtime: 8 production/MRP events on `production.dashboard` (F§1).

### 7. DSS recommendations

1. WO release timing per machine (D§5.3) — the plant tile; needs a per-machine load series (absent).
2. Commit-date feasibility for orders in the current plan (D§5.9).
3. NCR root-cause ranking from defect Pareto + machine + material history (D§5.7).
4. OT-vs-hiring for the shift shortfall (D§5.4) — consumes the attendance forecast it cannot reach
   today (`attendance.view` notwithstanding, the panel is HR-owned).

### 8. Activity feed

Audit stream on `work_orders` / `work_order_outputs` / `work_order_defects` / inspections / NCRs
(parents carry `HasAuditLog`; `StockLevel` and `StockMovement` now audited — B-F11); in-process QC + output handoff events;
schedules confirm. Surfaces: `/production/work-orders/:id`, `/quality/inspections/:id`.

### 9. Shortcuts (all route-verified)

`/dashboard/plant-manager` · `/production/dashboard` · `/production/oee` · `/production/work-orders` ·
`/production/work-orders/create` · `/production/schedule` · `/production/routings` · `/factory` (PWA,
sole holder) · `/mrp/plans` · `/mrp/machines` · `/mrp/molds` · `/mrp/boms` · `/quality/ncrs` ·
`/quality/inspections` · `/crm/complaints` · `/purchasing/purchase-requests` (read) ·
`/maintenance/work-orders` (read + verify) · `/chains` · `/approvals` · `/action-center`.

### 10. Filters

`range` preset today/week/month/quarter (already a payload param) · machine · mold · product ·
WO status priority · shift · department · alert severity.

### As-built → Phase-1 delta

Exists: bespoke page, strip, capacity forecast, 3 board-visible act types. Add: per-machine load
series (unblocks D§5.3), `supply_chain.deliveries.view` decision for the OTD tile link (or a
`/production/oee`-style internal anchor), align strip codes with gates (G-P2-3), dataset-gate the
cash-flow tile.

---

## R8. `ppc_head` — PPC Head · page EXISTS (`/dashboard/ppc`)

(A§1.8, 00§A; `dashboard.ppc.view`, 2 holders.)

### 1. Persona & decisions

Owns MRP: runs, plans, BOMs, routings, machines, molds, forecasting, finished-good master data;
creates/confirms WOs and schedules production. **Daily:** MRP run health + shortages → auto-PR,
schedule conflicts, mold/machine availability, unplanned WOs. **Weekly:** forecast accuracy
(MAPE/bias), capacity vs load, WO status mix, BOM cost drift. **Monthly:** OTD/forecast method
selection (D§5.12), material-planning latency, plan-vs-actual.

### 2. KPI cards (7)

| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Active WOs | page KPI (`production.work_orders.view` ✓) | WoW | — | `/production/work-orders` |
| Material shortages | page KPI — Σ shortages in active plans, gate `mrp.plans.view` ✓ | vs last run | — | `/mrp/plans` |
| Capacity used | page KPI | MoM | 90 (breach const) | `/production/schedule` |
| Molds ≥ warning% | page KPI, label from `alerts.mold.warning_ratio` | — | 80 / 95% | `/mrp/molds` |
| OTD | `kpi.on_time_delivery` (gate `dashboard.ppc.view` ✓) | 12-mo | 95 / 90 ↑ | `#kpi-on_time_delivery` |
| OEE / WO completion | strip codes present but gates unheld → **stripped** (G-P2-2) | — | 85 / 95 | — |
| Supplier quality | gate `dashboard.purchasing.view` ✗ → stripped | — | 90 / 80 | — |

### 3. Graph analytics (8)

| Chart | Purpose → decision |
|---|---|
| Production Gantt 8 machines × horizon (`production.gantt_mini`) | schedule density → move a run |
| MRP shortages top-10 auto-PR lines (`mrp_shortages` panel) | buy now vs substitute (D§20) |
| WO status breakdown (`production.wo_breakdown`) | planned vs released vs late |
| Machine status/availability grid | capacity reality (D§1.2) |
| Material reservations (Σ reserved, `material.reservations`) | which demand is already covered |
| Capacity vs load forecast (`forecast.capacity`) | quarter breach → pre-emptive hire/Ot |
| **New:** demand-forecast vs actual (MAPE/bias, `/forecasting/accuracy`) | method selection per product (D§5.12) |
| **New:** BOM cost drift trend | costing accuracy before PO (needs a cost-history series — data not available today) |

### 4. Forecast widgets

`forecast.capacity` ✅ (`forecasting.view` ✓) · demand engine surfaces ✅ (`/forecasting/demand`,
`/forecasting/stock-out`, `/forecasting/accuracy` — full module held) · `forecast.cash_flow` ✅
gate-wise (`forecasting.view`) but semantically wrong dataset → dataset-gate it. Attendance/headcount/
revenue/defect: gates unheld → **data not available**.

### 5. Approval inbox & quick actions

Acts on: **none** — PPC holds no `*.approve` slug and is a step of no workflow (A§1.8). The board is
read-only for PPC; the real queue is the MRP run output (`mrp.shortages`, auto-PR lines) and the
schedule-conflict alert list. **Board-blind flag:** auto-generated PRs appear as ordinary PR cards for
Finance (chain is money-only), so PPC cannot see its own submissions' progress anywhere except
`/purchasing/purchase-requests` (read via `purchasing.view`? **not held** → use `/mrp/plans` and the
PR created-by list — mark UNVERIFIED as a PPC-visible path).

### 6. Alerts / exceptions

`mrp_schedule_conflict` (primary recipient, D§8) · `scheduler_stale` (D§13) · mold 80/95% ·
`wo_overdue` · stock critical/low (Alert engine 1/2 notifies warehouse/purchasing audiences — PPC
sees them only via `mrp.shortages`) · MRP stale-run reaper (D§20) · forecast actuals late-filled
(B-F7 → `forecast.accuracy` under-reports).

### 7. DSS recommendations

1. WO release earliest-feasible-date per machine (D§5.3) — highest-value PPC tile.
2. EOQ/coverage quantity on shortage lines (D§5.2).
3. Recommended supplier per awarded line (D§5.1, read-only for PPC).
4. Auto-select/blend forecast method per product with MAPE evidence (D§5.12).
5. PM-slot planning from machine usage (D§5.8).

### 8. Activity feed

MRP run summaries (bridge json, B-F6), plan/PR generation, WO create/confirm, schedule writes,
forecast generate/reconcile. Surfaces: `/mrp/plans/:id`, `/production/work-orders/:id`,
`/forecasting/demand`.

### 9. Shortcuts (route-verified)

`/dashboard/ppc` · `/mrp` · `/mrp/plans` · `/mrp/boms` · `/mrp/machines` · `/mrp/molds` ·
`/production/schedule` · `/production/work-orders` · `/production/work-orders/create` ·
`/production/routings` · `/forecasting/demand` · `/forecasting/stock-out` · `/forecasting/accuracy` ·
`/crm/products` · `/approvals` (read) · `/action-center` · `/chains` (bottleneck-only mode; sales-order
lookup remains permission-gated — M10).

### 10. Filters

Machine · mold · product · plan/run · WO status · horizon (`gantt_horizon_days`) · material · forecast
method.

### As-built → Phase-1 delta

Add production dashboard read (`production.dashboard.view`) or re-point the gauge/trend widgets to
`production.view` — today the planning role cannot see machine utilization, OEE gauges, or the
production KPI trend (G-P2-2). Add MAPE/bias tile.

---

## R9. `purchasing_officer` — Purchasing Officer · page EXISTS (`/dashboard/purchasing`)

(A§1.9, 00§A; `dashboard.purchasing.view`, 2 holders; company-wide PR operator.)

### 1. Persona & decisions

Runs sourcing: PR conversion, RFQ invite/compare/award, PO create/submit/send, supplier listings,
supplier performance, GRN/bill read, returns lifecycle. **Daily:** PR action queue (est. total, days
waiting), RFQ close-due, overdue deliveries, stock-critical items with no supplier. **Weekly:** PO
pipeline, supplier score movement + deterioration alerts, quote comparisons. **Monthly:** supplier
performance recompute (1st), price variance, spend by vendor.

### 2. KPI cards (7)

| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Pending PRs | page KPI (row-scope filtered) | WoW | — | `/purchasing/purchase-requests` |
| Open POs | page KPI (`purchasing.open_pos` breakdown) | WoW | — | `/purchasing/purchase-orders` |
| Overdue deliveries | page KPI vs `po.expected_delivery_date` | WoW | — | `/supply-chain/deliveries` |
| Suppliers due review | page KPI (score < tier C 60) | MoM | ≥ 60 | `/purchasing/suppliers/performance` |
| Supplier quality | `kpi.supplier_quality` (gate `dashboard.purchasing.view` ✓) | 12-mo | 90 / 80 ↑ | `#kpi-supplier_quality` |
| OTD | strip code gate `dashboard.ppc.view` ✗ → stripped (G-P2-3) | — | 95 / 90 | — |
| Low-stock items | `inventory.low_stock` (`inventory.view` ✓) | WoW | reorder point | `/inventory/stock-levels` |

### 3. Graph analytics (7)

| Chart | Purpose → decision |
|---|---|
| PR → PO conversion funnel (with `manual_required` first-class) | where sourcing stalls (00§blocker-9) |
| PO pipeline by status (9 statuses, non-zero only) | expedite vs chase |
| Top-5 suppliers (score + spend) | negotiation priority |
| Supplier score trend (weights 0.25/0.35/0.10/0.15/0.15, D§26) | preferred-supplier maintenance |
| Quote comparison delivered cost (`RfqCommercialCalculator`) | award decision (D§24) |
| Stock-out risk top-5 (`supplier_risk` composition, gate `forecasting.view` ✓) | expedite inbound |
| **New:** lead-time drift per supplier | re-set fallback lead times (D§14 candidates, B) |

### 4. Forecast widgets

`supplier_risk` (composed: `StockOutProjectionService` + demand MAPE/bias) ✅ · demand/stock-out pages
✅ (module held) · `forecast.capacity` + `forecast.cash_flow` gate-wise ✅ (`forecasting.view`) but
wrong dataset for a buyer → recommend dataset gating (01-matrix §D2 gap G-P2-4). Headcount/attendance/
revenue/defect: **data not available**.

### 5. Approval inbox & quick actions

Acts on: **none of the ledger chains** — deliberately no `purchasing.pr.approve` / `po.approve` /
`po.sod_override` (`RolePermissionSeeder.php:812-834`). The buyer *creates* POs (creator ≠ chain step),
so every PR/PO they touch appears on the **Finance** card. Their own queue is **sourcing actions**:
RFQ award (`RequestForQuoteAccessPolicy::canAward()`), supplier-listing review
(`purchasing.supplier_listings.review` — custom single-step, now has a permission/read-scoped board
card; decision remains on the module route, C§4/§5), GRN-reject refusal arms, return lifecycle. Mount an
"awaiting your award" list from RFQ states (close-due sweep runs every minute, D§38) rather than from
`approval_records`.

### 6. Alerts / exceptions

Supplier deterioration (D§26) · `no_supplier` (critical stock, no qualified supplier, D§3) ·
`stock_critical`/`stock_low` · `replenishment_blocked` (D§21) · AP due 3d (read-only via
`accounting.bills.view`) · `mrp_schedule_conflict` → delivery-risk notice (D§8) · RFQ close-due
minutely · quote comparison staleness (**quote history is not kept** — B module note).

### 7. DSS recommendations

1. Recommended supplier per line: score + price + lead + MOQ (D§5.1) — the buyer tile.
2. EOQ / days-of-cover quantity on PR lines (D§5.2).
3. Award recommendation on the comparison page (D§5.6).
4. Price-renewal suggestion from agreement expiry + price variance (D§5.6).

### 8. Activity feed

PR submit/convert, PO submit/send, RFQ invite/quote/award, supplier-listing submissions, GRN
acceptance chain, bill 3-way match (read). Surfaces: `/purchasing/rfqs/:id/compare`,
`/purchasing/purchase-orders/:id`, `/inventory/grn/:id`.

### 9. Shortcuts (route-verified)

`/dashboard/purchasing` · `/purchasing/purchase-requests` (+`/create`) · `/purchasing/rfqs` ·
`/purchasing/rfqs/:id/compare` · `/purchasing/purchase-orders` · `/purchasing/approved-suppliers` ·
`/purchasing/supplier-listings` · `/purchasing/chain` · `/purchasing/suppliers/performance` ·
`/supply-chain/shipments` · `/supply-chain/deliveries` · `/inventory/grn` · `/inventory/items` ·
`/inventory/stock-levels` · `/return-management` · `/accounting/vendors` (read) ·
`/accounting/bills` (read) · `/approvals` (read) · `/notifications`.

### 10. Filters

Vendor · PR/PO status · RFQ stage · must-arrive-by window · item category · date range · delivery
status · supplier tier.

### As-built → Phase-1 delta

Add award-queue tile + supplier recommendation (both need Phase 2 data, not new tables). Fix strip
gate mismatch (G-P2-3). Keep the buyer out of cash-flow tiles (G-P2-4).

---

## R10. `warehouse_staff` — Warehouse Staff · page EXISTS (`/dashboard/warehouse`)

(A§1.10, 00§A; `dashboard.warehouse.view`, 2 holders; no fleet, no shipments-create.)

### 1. Persona & decisions

Owns goods in/out: GRN finalize/accept/reject, material issues, transfers, counts, picking, MRB
holds, return receiving, clearance signing (materials-return item). **Daily:** incoming queue
(receivable POs), pending GRNs + QC holds, issues today, low stock, picking list. **Weekly:** zone
utilization, count variance, MRB aging. **Monthly:** turnover, slow-moving/obsolete, count accuracy.

### 2. KPI cards (7)

| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Pending GRNs | page KPI (`inventory.pending_grns`) | WoW | — | `/inventory/grn` |
| Issues today | page KPI (`inventory.pending_issues`) | WoW | — | `/inventory/material-issues` |
| Low-stock items | page KPI + `inventory.low_stock` | WoW | reorder point | `/inventory/stock-levels` |
| Pending transfers | page KPI | WoW | — | `/inventory/transfer-orders` |
| Inventory turnover | `kpi.inventory_turnover` (gate `dashboard.warehouse.view` ✓) | 12-mo | 6 / 4 ↑ | `#kpi-inventory_turnover` |
| Supplier quality | strip code gate `dashboard.purchasing.view` ✗ → stripped | — | 90 / 80 | — |
| Zone utilization | page panel (occupied/total/%) | — | warning/critical ratios | `/inventory/warehouse-map` |

### 3. Graph analytics (6)

| Chart | Purpose → decision |
|---|---|
| Incoming queue (receivable POs ≤ horizon) | dock scheduling (D§1.2) |
| Outgoing queue (scheduled/picking deliveries + customer) | load-truck priority |
| Low-stock alerts (10, with preferred supplier) | raise requisition vs wait (D§2) |
| Zone utilization bars | re-slot / add temporary storage |
| **New:** GRN aging by status (`pending_qc` first-class — F3) | chase QC so stock lands |
| **New:** movement ledger volume by type (`stock_movements`) | spot unusual issue/transfer patterns |

### 4. Forecast widgets

`forecast.capacity` + `forecast.cash_flow` gate-wise ✅ (`forecasting.view` ✓) but wrong dataset for
the floor → dataset-gate (01-matrix §D2 gap G-P2-4). Stock-out risk ✅ via the demand/stock-out pages
(module held). Turnover/headcount/attendance/revenue/defect: **data not available** (turnover exists
as a KPI tile but its calculator divides by *current* stock — D§2 gap).

### 5. Approval inbox & quick actions

Acts on: **none** — maker on stock adjustments (approver needs `inventory.adjust.approve`, not held),
GRN reject is a refusal arm (not an approval), return `receive` is a staging step. **Board-blind
flag:** stock adjustments, stock-count variances and GRN rejects write no ledger rows → nothing on
`/approvals` (C§1b/§5). Mount an operational queue instead: high-value stock adjustments awaiting a
checker (`/inventory/stock-adjustments`), counts with variance pending, GRNs `pending_qc` with no
inspection row (F3), MRB holds.

### 6. Alerts / exceptions

`stock_critical` / `stock_low` (`AlertEngineService:298-379`) · `replenishment_blocked` · QC-held
GRNs without inspection rows (F3; `inventory.qc_handoff_issues` and the retry sweep cover missing
handoffs) · MRB holds · missing-outgoing sweep (hourly) ·
clearance items awaiting signature (`hr.separation.view` + `hr.clearance.sign`) · return receive
handoff (no notification — C§3).

### 7. DSS recommendations

1. Days-of-cover / EOQ on low-stock lines (D§5.2) — replaces the reorder-point-only alert.
2. Overstock / slow-moving / obsolete detection (Phase 5 capability; inventory-level snapshots absent
   → turnover accuracy caveat, D§2).
3. ABC/XYZ classification for slotting.
4. Stock-out risk ranking with open-PO netting (the projection service does **not** net commitments —
   D§3.2 wrong list).

### 8. Activity feed

GRN finalize/accept, issue/transfer posting, count sessions, MRB hold/release, return receipts,
adjustment submissions. Surfaces: `/inventory/grn/:id`, `/inventory/material-issues/:id`,
`/inventory/mrb/:id`. The stock-movement ledger remains event history; stock-level and movement changes also emit audit rows (B-F11).

### 9. Shortcuts (route-verified)

`/dashboard/warehouse` · `/inventory/items` · `/inventory/stock-levels` · `/inventory/grn`
(+`/create`) · `/inventory/material-issues` (+`/create`) · `/inventory/stock-adjustments` ·
`/inventory/stock-count` · `/inventory/transfer-orders` · `/inventory/picking` · `/inventory/mrb` ·
`/inventory/scanner` · `/inventory/warehouse-map` · `/supply-chain/deliveries` · `/hr/separations` ·
`/approvals` (read) · `/action-center`.

### 10. Filters

Warehouse/zone · item/part number · movement type · GRN status (`pending_qc|accepted|
partial_accepted|rejected`) · supplier · date range · hold reason.

### As-built → Phase-1 delta

Dashboard exists. `inventory.qc_handoff_issues` now surfaces pending GRNs whose incoming-QC handoff
needs retry, with a filtered list link. Stock-count variance and adjustment-checker queue items remain
open; index the returns + WO-operation join columns before any of them aggregates (00§blocker-3).
Note M9: SPA warehouse-structure
pages are narrower than the API — a dashboard link into `/inventory/warehouse` works for this role
only because it holds `warehouse.manage`.

---

## R11. `qc_inspector` — QC Inspector · page EXISTS (`/dashboard/quality`)

(A§1.11, 00§A; `dashboard.quality.view`, 2 holders; IATF differentiator role.)

### 1. Persona & decisions

Owns specs, inspections (incoming/in-process/outgoing), measurements vs tolerances, NCR lifecycle,
calibration, PPAP, MRB quarantine, return inspection. **Daily:** incoming QC queue for GRNs,
outgoing QC before dispatch, in-process sampling, failed measurements → NCR. **Weekly:** defect
Pareto, NCR aging/effectiveness, calibration due, PPAP expiries. **Monthly:** DPPM/FPY/NCR closure vs
target, capability studies, CoC completeness on confirmed shipments.

### 2. KPI cards (7)

| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Pending inspections | page KPI (outgoing-first ordering) | WoW | — | `/quality/inspections` |
| Pass rate today | page KPI (`qc.pass_rate`) | day-over-day | — | `/quality/dashboard` |
| Open NCRs | page KPI (`qc.open_ncrs`) | WoW | — | `/quality/ncrs` |
| CoCs generated MTD | page KPI | MoM | — | `/quality/dashboard` |
| DPPM | `kpi.dppm` (gate ✓) | 12-mo | 500 / 750 ↓ | `#kpi-dppm` |
| First-pass yield | `kpi.first_pass_yield` (gate ✓) | 12-mo | 98 / 95 ↑ | `#kpi-first_pass_yield` |
| NCR closure days | `kpi.ncr_closure_days` (gate ✓) | 12-mo | 5 / 8 ↓ | `#kpi-ncr_closure_days` |

QC holds 3/3 strip codes — the only bespoke role whose strip is gate-clean besides finance/HR.

### 3. Graph analytics (7)

| Chart | Purpose → decision |
|---|---|
| Defect Pareto (`qc.pareto`, top-6 params) | which defect to attack (D§17) |
| Chain coverage (incoming/in-process/outgoing inspected-vs-total + %) | **coverage gap** → which gate is being skipped (D§1.2) |
| Inspection queue by type (donut) | staffing next hour |
| NCR list by severity + aging | escalation order (D§15) |
| **New:** capability/Cpk trend per spec (`/quality/capability` exists) | process drift early warning (D§5 quality) |
| **New:** repeat-issue detection (30-day recurrence window, D§30 class) | recurrence → CAPA, not another rework |
| Display-policy thresholds block (5 colours) | consistent meaning of good/warn/bad on the page |

### 4. Forecast widgets

`forecast.defect_rate` ✅ (gate `quality.view` ✓) · demand/stock-out ✅ (module held; supplier-defect
returns surface there) · capacity/cash-flow **stripped** (QC holds no `forecasting.view` — correct by
accident, since those datasets are not QC's). Attendance/headcount/revenue: **data not available**.

### 5. Approval inbox & quick actions

Acts on: **none of the ledger types** (NCR is lifecycle; PPAP is module-local; return `inspect` is a
staging step). **Board-blind flag:** the quality loop is entirely outside `approval_records` (C§1b/§5),
so the QC "inbox" must be built from inspection/NCR/calibration states: inspections `draft/in_progress`,
NCRs non-closed past tier, calibration due ≤30d, PPAP expiring, high-risk review queue
(`quality.inspections.review` ✓).

### 6. Alerts / exceptions

NCR SLA tiers (15-min sweep, D§15) · 8D SLA (complaints, read via `crm.complaints.view`, D§16) · CAPA
effectiveness 30d/14d (D§17) · calibration due 30d/`calibration:check-due` 06:50 · PPAP expiry sweep ·
`qc_fail_rate_high` (scrap >5%/24h, D§12) · document-review re-arm 7d (D§19) · missing-outgoing-QC
hourly sweep (F4) · repeat-breakdown sweep points at maintenance, not QC.

### 7. DSS recommendations

1. NCR root-cause ranking from Pareto + machine + material history (D§5.7) — the QC tile.
2. Recurrence/early-warning on drifting processes (Phase 5 "early-warning on drifting processes").
3. Corrective-action aging + effectiveness tracking with acceptance evidence.
4. CoC completeness gate on confirmed shipments (checklist, not a chart).

### 8. Activity feed

Inspection record + measurement eval (`recordMeasurements`), NCR open/close + replacement-WO creation
(`WorkOrder::coversSalesOrderLine` decides the owner — CLAUDE.md shared helpers), calibration records,
PPAP submissions. Surfaces: `/quality/inspections/:id`, `/quality/ncrs/:id`,
`/quality/work-orders/:id/final-inspection`.

### 9. Shortcuts (route-verified)

`/dashboard/quality` · `/quality/dashboard` · `/quality/inspections` (+`/new`) ·
`/quality/inspection-specs` · `/quality/ncrs` (+`/new`) · `/quality/ncrs/effectiveness` ·
`/quality/ncr-templates` · `/quality/calibration` · `/quality/traceability` · `/quality/capability` ·
`/inventory/mrb` · `/return-management` · `/crm/complaints` · `/approvals` (read) · `/notifications`.
PPAP pages: route list shows none (`spa/src/routes/qualityRoutes.tsx`) though the API/perms exist —
**UNVERIFIED**.

### 10. Filters

Product · inspection spec · inspection type (incoming/in-process/outgoing) · result · defect param ·
NCR severity/status · date range · machine/mold · calibration instrument.

### As-built → Phase-1 delta

Strongest of the six dashes: strip gates clean, defect Pareto + coverage panel already there. Add the
NCR root-cause ranking (Phase 2 aggregation) and the capability/recurrence charts. Park PPAP page path
verification.

---

## R12. `maintenance_tech` — Maintenance Technician · shared page shipped in Phase 2 E: `/dashboard/maintenance`

(A§1.12, 00§A; at spec time the role landed on `/dashboard/default`; Phase 2 E added
`dashboard.maintenance.view` and the shared maintenance page. The role-default layout already existed —
`DashboardRoleLayoutSeeder.php:31-129`.)

### 1. Persona & decisions

MWO executor: create, start, complete, submit-repair (no assign, no verify, **no
`maintenance.work.assigned`** — A§1.12/M6). **Daily:** assigned/queued breakdowns, machine status,
spare availability, downtime capture. **Weekly:** MTTR of own jobs, repeat breakdowns, schedule
adherence. **Monthly:** PM completion rate, mold/machine wear indicators, PM-vs-corrective mix.

### 2. KPI cards (6 — shipped on the shared maintenance page)

| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Open MWOs | `maintenance.open_wos` (`maintenance.view` ✓) | WoW | — | `/maintenance/work-orders` |
| Due schedules | `maintenance.due_schedules` (due ≤ N days) | WoW | PM compliance ≥ 90% (target **not seeded** — data not available) | `/maintenance/schedules` |
| Machine status | `machine.status` (`mrp.machines.view` ✓) | WoW | breakdown = 0 | `/mrp/machines` |
| Assets under maintenance | `assets.under_maintenance` (`assets.view` ✓) | WoW | — | `/assets` (route page gate = `assets.view`) |
| Pending my action | `approvals.pending` (self-scoped) | WoW | — | `/approvals` |
| Self tiles (4) | payslip / leave balance / DTR today / own pendings | — | — | `/self-service/*` |

KPI strip: **none** (admin/default mount `[]` — Task 1) and no `kpi.*` gate is held; a maintenance
page must therefore carry its own cards, not rely on the KPI plane.

### 3. Graph analytics (5 → new page)

| Chart | Purpose → decision |
|---|---|
| Downtime Pareto by machine/reason (`DowntimeParetoChart` reusable) | which machine to attack first |
| Breakdown count by machine (30-day window, D§30) | repeat offenders → root cause |
| MWO aging by status (open vs in-progress vs waiting parts) | chase parts vs labour |
| PM due/overdue timeline (`maintenance_schedules`) | slot PM before failure (D§5.8) |
| Mold/machine usage vs limits (shot counts, `mold_history`) | 80/95% position → plan replacement |

### 4. Forecast widgets

`forecast.capacity` gate `forecasting.view` ✗ → stripped; maintenance workload/downtime forecasters do
not exist. **data not available: no maintenance forecast series + no `forecasting.view` grant** —
Phase 3 candidate (maintenance workload + downtime).
Interim: 30-day rollup bars from `maintenance_work_orders` + downtime logs.

### 5. Approval inbox & quick actions

Acts on: **none** (no `*.approve` slug). Action Center is the real queue: MWO create/complete,
submit-repair, downtime capture, spare requests. **Board-blind flag:** none of their work is an
approval type; a "my tasks" tile must read Action Center + MWO assignment, and note the role **cannot
open `/self-service/maintenance-work-orders`** (guard needs `maintenance.work.assigned` — M6/A§1.12).
Proposed page must link it to `/maintenance/work-orders` instead.

### 6. Alerts / exceptions

`machine_breakdown` (critical, D§4) · mold 80/95% (D§5/6) · predictive thresholds (85 °C, 7.1 mm/s,
2–12 bar, 150 A, 70% oil; 3 consecutive → corrective MWO, D§29) · repeat-breakdown sweep (D§30) ·
assignment watchdog every 5 min (D§30) · Action Center critical 8h SLA (D§18) · MWO SLA default 72h.

### 7. DSS recommendations

1. PM slot planning from usage rate (D§5.8) — schedule during the next planned downtime on date D.
2. MTBF/MTTR panel → replace-vs-repair decision (needs `started_at` index, 00§blocker-3).
3. Spare-parts risk (part consumed by failing machine, no stock) — **data not available: no
   machine↔spare linkage surfaced**.

### 8. Activity feed

MWO create/start/complete/verify, downtime logs, condition readings, mold shot increments,
polymorphic `maintainable_type/_id` targets (CLAUDE.md namespace note). Surfaces:
`/maintenance/work-orders/:id`, `/maintenance/downtime`.

### 9. Shortcuts (route-verified)

`/dashboard/maintenance` (current; shared with maintenance_supervisor) · `/maintenance` ·
`/maintenance/work-orders` (+`/create`) · `/maintenance/schedules` · `/maintenance/downtime` ·
`/mrp/machines` · `/assets` · `/action-center` · `/approvals` (read) · `/notifications` ·
`/self-service` (self tiles).

### 10. Filters

Machine · mold · MWO status/priority · assignment (mine/unassigned) · date range · downtime reason ·
schedule window.

### As-built → Phase-1 delta (build order)

Phase 2 E shipped the shared `/dashboard/maintenance` page for both roles. Their defaults provide the
six cards above; the page remains permission-derived, and maintenance actions stay on existing routes.
`maintenance.work.assigned` remains an assignment-scoped self-service permission, not a page gate (M6).

---

## Cross-role notes for the P2 roles (feed into `01-matrix.md`)

1. **G-P2-1 (leak, resolved 2026-10-11):** the two `hr.team_*` widgets remain available on the
   self-service slugs because payloads are department-scoped and null-safe; this is documented broad
   access, not a cross-department leak. The organization-wide `forecast.attendance` widget now uses the
   attendance-planning read gate.
2. **G-P2-2 (gate outage, resolved 2026-10-11):** production widgets and KPI tiles use the production
   data-read set, so `ppc_head` can read the schedule data it owns without gaining the unrelated
   `production.dashboard.view` page permission.
3. **G-P2-3 (partly resolved):** PPC and PM now read all three codes on their strips. Purchasing and
   warehouse read 1/2 because each missing code belongs to another module; their lists document that
   tradeoff and `KpiVisibilityMatrixTest` pins the readable sets.
4. **G-P2-4 (dataset gate, resolved 2026-10-11):** forecasts now follow the read set of their source
   datasets, so buyers and warehouse staff no longer see finance or production projections. Capacity and
   attendance thresholds remain fixed defaults by decision; F-059 records the trigger for changing them.
5. **G-P2-5 (board-blind floor roles): partly addressed.** Warehouse stock adjustments/stock-count
   variances and buyer supplier-listing reviews now have permission/read-scoped approval cards; decision
   routes remain in their modules. GRN refusal and per-work-order late reports remain outside the board,
   so page-level operational probes still matter (01-matrix §C matrix "Approval-type × role").
6. Dashboard prerequisite indexes for returns, `wo_operations`, `production_logs`, budgets, PR/PO dates,
   and delivery timestamps were applied in the Phase 2 index batches. Other unindexed relationships remain
   listed in B §4; add those only when a dashboard query depends on them.
