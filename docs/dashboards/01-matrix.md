# OGAMI ERP — Phase 1: Role × Dashboard Coverage Matrix (`01-matrix.md`)

Branch `docs/dss-discovery-phase0`. **Phase 1 deliverable.** Compiled strictly from Phase 0
(`../00-discovery.md` + `phase0/A–F`) with spot-checks against the seeders, widget catalog, role-default
layouts and SPA route table. No feature code is written in this phase — this file is the spec the later
phases build from, and the session **stops here for review** (mission execution order §1).

**How this deliverable is organized** (the per-role spec was authored in parts 1–3 and is part of the
matrix, not a substitute for it):

| Part | File | Covers |
|---|---|---|
| Roles 1–6 | `phase0/M-p1.md` | `system_admin`, `hr_officer`, `finance_officer`, `vice_president`, `customer_service_officer`, `sales_officer` (also carries **§0 shared contracts**) |
| Roles 7–12 | `phase0/M-p2.md` | `production_manager`, `ppc_head`, `purchasing_officer`, `warehouse_staff`, `qc_inspector`, `maintenance_tech` |
| Roles 13–17 | `phase0/M-p3.md` | `maintenance_supervisor`, `impex_officer`, `department_head`, `employee`, `driver` |
| **This file** | `01-matrix.md` | shared contracts (canonical), **B** company-overview spec, **C** the four matrices, **D** coverage gaps + build order |

Trace key: (00§X) = `../00-discovery.md` §X · (A/B/C/D/E/F §Y) = the phase0 section of that letter ·
(M-p1 §Z) = part file §Z. Everything not traceable to Phase 0 or a re-verified seeder/route line is
marked **UNVERIFIED**; every metric that cannot be computed from existing tables is marked
**data not available: reason**.

**Coverage counts (this deliverable):** roles specified **17/17** · matrices **4/4** ·
company-overview spec **1/1** · role pages that exist today **8 bespoke + 9 default-grid** ·
proposed new pages **5** (`vendor`/`vp` collapsed into one executive page, plus logistics,
maintenance, department, driver landing) · UNVERIFIED items **19** ·
data-not-available items **44** (per-role counts rolled up from M-p1 14 / 41 + M-p2 4 / 12 + M-p3 1 / 6,
minus overlaps).

---

## §1. Role index (what each role gets, where it lands, what it can act on)

| # | Role | Landing today | Gating permission (holders) | Page status | Spec | Board act types |
|---|---|---|---|---|---|---|
| 1 | `system_admin` | `/dashboard/admin` | `dashboard.admin.view` (1) | exists (system health, no business KPIs) | M-p1 R1 | none by design (IT; top step of no workflow) |
| 2 | `hr_officer` | `/dashboard/hr` | `dashboard.hr.view` (2) | exists | M-p1 R2 | leave (HR arm), company loan step 2, separation clearance step 5 |
| 3 | `finance_officer` | `/dashboard/finance` | `dashboard.accounting.view` (2) | exists | M-p1 R3 | PR step 1, PO step 1, payroll checker (approve/finalize/bank/disburse), asset-disposal step 1, finance-only return, budget + transfer, loan write-off |
| 4 | `vice_president` | `/dashboard/plant-manager` | `dashboard.plant_manager.view` (3) | **new page required** → one executive page (see B) | M-p1 R4 | PR/PO step 2 (≥₱50k), bill payment (≥₱50k), cash-advance step 3, company-loan step 4, salary-adjustment step 2, asset-disposal step 2 |
| 5 | `customer_service_officer` | `/dashboard/default` | — | **new page required** → `/dashboard/customer-service` | M-p1 R5 | none (RMA/manage, price propose; approval by others) |
| 6 | `sales_officer` | `/dashboard/default` | — | **new page required** → `/dashboard/sales` | M-p1 R6 | none (SO lifecycle + price propose) |
| 7 | `production_manager` | `/dashboard/plant-manager` | `dashboard.plant_manager.view` (3) | exists + factory PWA | M-p2 R7 | return_request step 2, salary-adjustment step 1, legacy company-loan step 2 |
| 8 | `ppc_head` | `/dashboard/ppc` | `dashboard.ppc.view` (2) | exists | M-p2 R8 | none (MRP/schedule owner) |
| 9 | `purchasing_officer` | `/dashboard/purchasing` | `dashboard.purchasing.view` (2) | exists | M-p2 R9 | none (sourcing actions: RFQ award, supplier-listing review) |
| 10 | `warehouse_staff` | `/dashboard/warehouse` | `dashboard.warehouse.view` (2) | exists | M-p2 R10 | none (GRN/issues/counts/MRB; adjustment checker is another role) |
| 11 | `qc_inspector` | `/dashboard/quality` | `dashboard.quality.view` (2) | exists | M-p2 R11 | none (NCR/PPAP lifecycle) |
| 12 | `maintenance_tech` | `/dashboard/default` | — | **new page required** → `/dashboard/maintenance` (shared with 13) | M-p2 R12 | none (MWO lifecycle) |
| 13 | `maintenance_supervisor` | `/dashboard/default` | — | **new page required** → `/dashboard/maintenance` | M-p3 R13 | none (assign/verify lifecycle) |
| 14 | `impex_officer` | `/dashboard/default` | — | **new page required** → `/dashboard/logistics` | M-p3 R14 | none (shipment/delivery lifecycle) |
| 15 | `department_head` | `/dashboard/default` | — | **new page required** → `/dashboard/department` (or keep grid + cards) | M-p3 R15 | leave step 1, loan step 1, return_request step 1, OT (custom), PR creator |
| 16 | `employee` | `/dashboard/default` | — | exists (self-service + `GET /dashboards/employee`) | M-p3 R16 | none |
| 17 | `driver` | `/dashboard/default` | — | **landing change required** → `/driver` PWA | M-p3 R17 | none |

Landing is computed server-side by `DashboardDispatchService::resolve()` — permission-derived, rarest
permission first, counted live from `role_permissions` (A§0). **No spec below may add a role-name
branch**; each proposed page is reached by a new permission slug held by the intended roles.

---

## §2. Shared contracts (canonical; M-p1 §0 in full, consolidated here for later phases)

1. **Access.** `AuthGuard → ModuleGuard → PermissionGuard` in SPA; backend `CheckPermission` 403s
   independently. Row visibility is per-module policy code, never a permission string. Approval-board
   cards come from `ApprovalSourceScope::visibleIds()` (never `userCanView`). Aggregators reuse the
   owning module's row scope (CLAUDE.md).
2. **KPI grain.** Snapshots are monthly, computed 2nd @ 03:00, read one month behind by design; any
   sub-month trend comes from source tables. Targets/directions: oee 85/75↑ · dppm 500/750↓ · FPY
   98/95↑ · OTD 95/90↑ · supplier-quality 90/80↑ · attendance 96/93↑ · ar-aging-60d 5/8↓ ·
   budget-util 90/80↑ · ncr-closure 5/8↓ · inv-turnover 6/4↑ · wo-completion 95/90↑.
   Gate map (`KpiSnapshotService::MODULE_PERMISSIONS`): production→`production.dashboard.view` ·
   quality→`dashboard.quality.view` · supply_chain→`dashboard.ppc.view` ·
   purchasing→`dashboard.purchasing.view` · attendance→`dashboard.hr.view` ·
   accounting→`accounting.dashboard.view` · inventory→`dashboard.warehouse.view`.
3. **Finance dual slug (M1).** Page/catalog/dispatch answer to `dashboard.accounting.view`; data +
   `kpi.*`/`finance.*` widgets answer to `accounting.dashboard.view`. Every new finance surface picks
   deliberately.
4. **Board-blind rule.** Only the 8 live ledger kinds have board cards; `payroll` is a dead kind; the 14
   custom single-step types need per-type card sourcing. No generic approve endpoint exists — an inline
   button calls its module endpoint (remarks required on reject).
5. **Notification darkness.** 7+ types fire nothing on submit/decide; mid-chain handoffs are missing even
   on ledger types. Inboxes must poll; nothing assumes a push.
6. **Handoff-status dimension.** `manual_required` / `Generated` / `NotRequired` are first-class on every
   funnel (delivery→invoice, GRN→inspection, WO→outgoing inspection) — a broken link must not read as
   zero volume.
7. **Frontend reuse.** 30 dashboard reusables + 6 chart primitives; CSS 12-col grid (no drag/resize);
   tile "Open →" = server `link_path`; refresh 60s bespoke / 30s fast / forecasts manual; shell-owned
   skeleton/error/empty/stale + per-cell boundaries; no dashboard/chart export. New charts reuse
   existing primitives — no new chart library.
8. **Perf.** Widget-data + rich payloads are uncached (R15) — add a cache layer before adding tiles;
   index debt on returns/budgets/WO-operations/PR-PO dates/delivery actuals must be paid first;
   migrations respect the `0NNN_` vs `2026_*` ordering rule.
9. **Scope cuts.** Activity feeds only on SO/PO/WO/NCR; no customizable dashboards, no import center, no
   per-shot mold depreciation (CLAUDE.md).
10. **Drift guards.** `WidgetSeedIntegrityTest` (widget↔provider↔link↔KPI bijection, role defaults
    visible) and `ApprovalChainRolePermissionDriftTest` (step role ⇄ route permission ⇄ registry) must
    stay green on every touch.

---

## B. Company-overview dashboard (cross-functional)

**Who qualifies.** Company-wide reads only. Derived from grants (A§1): `system_admin` (wildcard),
`vice_president` (money chains + `dashboard.view_bottlenecks` + budgeting/accounting reads),
`finance_officer` (accounting + budgeting + PO/PR approve + `dashboard.view_bottlenecks`),
`production_manager` (broadest operations read: production, quality read, purchasing read, maintenance,
`view_bottlenecks`, chain recovery), `ppc_head` (MRP + forecasting + production plan + bottlenecks),
`hr_officer` (people only — excluded from a cross-functional page), `purchasing_officer` (chain-2 +
chain-recovery view — borderline; include read-only).

**Proposed key:** `company` → `dashboard.company.view` → `/dashboard/company`. Held by
`system_admin` + `vice_president` (and optionally `finance_officer`, `production_manager`; each added
holder changes landing/rarity — sequence deliberately, G-D-2).

> **Decision to make (recorded, not silently taken):** M-p1 R4 proposed `/dashboard/vp` and this section
> proposes `/dashboard/company`. They are the same page. **Recommendation: one page, one slug**
> (`dashboard.company.view`, path `/dashboard/company`), and the VP's landing moves from
> `/dashboard/plant-manager` to it. Do not ship both.

**Contents (8 blocks, all reusing existing primitives):**

| Block | Content | Sources |
|---|---|---|
| Health strip | 6 KPI tiles: OEE, OTD, FPY, DPPM, AR >60d, budget utilization (gates: the viewer must hold the module slug, else the tile is omitted — a VP sees none of them today, see G-P3-2) | `kpi.*` snapshots |
| Chain health | chain-stage donut incl. `manual_required` + chain-recovery backlog; bottleneck owner per stage | `chain.stage_breakdown`, `/chains`, `/chains/recovery` |
| Money | cash, AR/AP aging, AP due ≤3d, revenue MTD | finance widgets (gated) |
| Demand vs supply | demand forecast (3-mo) vs capacity vs actual output | `forecast.*`, `/production/schedule` |
| Quality | defect Pareto + NCR aging + 8D SLA burn | `qc.pareto`, `/quality/ncrs`, `/crm/complaints` |
| Approvals | VP-only filtered top-8 (already the worklist widget, filtered to ≥₱50k rows) | `approvals.pending` |
| Risks & opportunities | top-5 from the DSS recommendation feed (Phase 5) + open critical alerts | alerts engine |
| Weekly briefing | auto-generated plain-language summary (Phase 5 "executive" capability) | Phase 5 |

Explicitly **not** on this page: personal self-service tiles, module CRUD shortcuts beyond read/drill,
any per-role conditional rendering (stripping is permission-derived server-side).

---

## C. Matrices

### C1. Role × widget (permission per widget)

Derived from `RolePermissionSeeder` grant lists (A§1) + `DashboardWidgetSeeder` key→permission +
`KpiSnapshotService::MODULE_PERMISSIONS`. Holder names are the roles that **qualify** (admin is
implicit everywhere and shown as `adm`). Payload row scoping is always server-side and narrower than
visibility.

| Widget key | Permission | Qualifying roles |
|---|---|---|
| `production.kpi`, `machine.utilization`, `oee.gauges` | `production.dashboard.view` | adm, production_manager |
| `production.active_wo`, `production.wo_breakdown` | `production.work_orders.view` | adm, PM, PPC |
| `production.gantt_mini` | `production.schedule.view` | adm, PM, PPC |
| `machine.status` | `mrp.machines.view` | adm, PM, PPC, maintenance_tech, maintenance_supervisor |
| `mrp.shortages` | `mrp.plans.view` | adm, PM, PPC |
| `material.reservations` | `mrp.view` | adm, PM, PPC |
| `chain.stage_breakdown` | `dashboard.view_bottlenecks` | adm, VP, finance, PM, PPC, purchasing |
| `qc.pareto`, `qc.pass_rate` | `quality.view` | adm, PM, qc_inspector |
| `qc.pending_inspections` | `quality.inspections.view` | adm, PM, QC |
| `qc.open_ncrs` | `quality.ncr.view` | adm, PM, QC |
| `finance.cash_position` | `accounting.dashboard.view` | adm, finance |
| `finance.ar_aging`, `finance.unpaid_invoices` | `accounting.invoices.view` | adm, finance, VP |
| `finance.ap_aging`, `finance.upcoming_payables` | `accounting.bills.view` | adm, finance, VP, purchasing |
| `finance.revenue_mtd` | `accounting.dashboard.view` | adm, finance |
| `hr.headcount`, `hr.probation_alerts` | `hr.employees.view` | adm, HR, department_head (dept-scoped) |
| `hr.on_leave_today` | `hr.employees.view_sensitive` | adm, HR |
| `hr.team_dtr_today` | `attendance.view` | **all 17** (self-service slug — G-P2-1 leak) |
| `hr.team_on_leave_today` | `leave.view` | **all 17** (same leak) |
| `payroll.upcoming` | `payroll.periods.view` | adm, HR, finance |
| `approvals.pending` | none (self-scoped) | all 17 (empty for non-approvers) |
| `purchasing.open_prs`, `purchasing.open_pos` | `purchasing.view` | adm, VP, finance, PM, purchasing, impex, department_head |
| `purchasing.supplier_perf` | `purchasing.suppliers.performance.view` | adm, finance, purchasing |
| `supply.overdue_deliveries`, `supply.delivery_schedule` | `supply_chain.deliveries.view` | adm, finance, purchasing, warehouse, impex |
| `inventory.low_stock`, `inventory.pending_grns`, `inventory.pending_issues` | `inventory.view` | adm, PM, purchasing, QC, warehouse |
| `self.*` (4) | none (own employee) | all 17 with a linked employee (else null + helper) |
| `alerts` | `alerts.view` | adm, HR, finance, VP, CSO, sales, PM, PPC |
| `maintenance.open_wos`, `maintenance.due_schedules` | `maintenance.view` | adm, PM, PPC, tech, supervisor |
| `assets.under_maintenance` | `assets.view` | adm, VP, PM, PPC, tech, supervisor |
| `rma.open_returns`, `rma.pending_approval` | `return_management.view` | adm, finance, PM, purchasing, QC, warehouse, department_head, CSO |
| `crm.open_complaints` | `crm.view` | adm, VP, PM, PPC, QC, sales, CSO |
| `budget.utilization` | `budgeting.view` | adm, VP, finance |
| `loans.outstanding` | `loans.view` | adm, VP, finance, HR, PM, department_head (dept-scoped unless `loans.write_off`) |
| `forecast.headcount` | `hr.employees.view` | adm, HR, department_head |
| `forecast.revenue` | `accounting.dashboard.view` | adm, finance |
| `forecast.defect_rate` | `quality.view` | adm, PM, QC |
| `forecast.capacity` | `forecasting.view` | adm, finance, PM, PPC, purchasing, warehouse |
| `forecast.cash_flow` | `forecasting.view` | adm, finance, PM, PPC, purchasing, warehouse (G-P2-4) |
| `forecast.attendance` | `attendance.view` | **all 17** (G-P2-1) |
| `kpi.oee`, `kpi.wo_completion_rate` | `production.dashboard.view` | adm, PM |
| `kpi.dppm`, `kpi.first_pass_yield`, `kpi.ncr_closure_days` | `dashboard.quality.view` | adm, QC |
| `kpi.on_time_delivery` | `dashboard.ppc.view` | adm, PPC |
| `kpi.supplier_quality` | `dashboard.purchasing.view` | adm, purchasing |
| `kpi.attendance_rate` | `dashboard.hr.view` | adm, HR |
| `kpi.ar_aging_60d`, `kpi.budget_utilization` | `accounting.dashboard.view` | adm, finance |
| `kpi.inventory_turnover` | `dashboard.warehouse.view` | adm, warehouse |

**Strip vs tile.** The per-page KPI strips (Task 1) pass role-chosen code lists to
`GET /dashboard/kpi/trends`, which enforces the same module gate map — so strips and gates disagree for
PM (2/3), PPC (1/4), purchasing (1/2), warehouse (1/2). QC/finance/HR strips are clean. See G-P2-3.

### C2. Module × role dashboards (every module must appear at least once)

| Module / data area | Surfaced on | Via |
|---|---|---|
| HR (employees, training, recruitment, separation) | `/dashboard/hr`, `/dashboard/department`, `/dashboard/admin` | headcount/probation/on-leave panels, team cards, audit |
| Attendance | `/dashboard/hr`, `/dashboard/department`, `/dashboard/plant-manager` | DTR summary, team cards, OEE derate |
| Leave | `/dashboard/hr`, `/dashboard/department`, approvals board | leave calendar, forfeiture horizon, step-1 cards |
| Payroll | `/dashboard/hr`, `/dashboard/finance` | payroll summary + pipeline (6 stages), payslip emails (self-service) |
| Loans | `/dashboard/hr`, `/dashboard/department` | outstanding (dept-scoped), step-1 cards |
| Accounting + budgeting | `/dashboard/finance` | 18 panels (cash, AR/AP aging, JEs, budget-vs-actual, credit notes) |
| Inventory | `/dashboard/warehouse`, `/dashboard/purchasing`, `/dashboard/quality` | incoming/outgoing queues, low stock, MRB |
| Purchasing | `/dashboard/purchasing`, `/dashboard/finance` | PR queue, PO pipeline, supplier score, budget-ack queue |
| Supply chain | `/dashboard/warehouse` (deliveries), `/dashboard/logistics` (proposed) | outgoing queue, delivery/OTD panels |
| Production | `/dashboard/plant-manager`, `/dashboard/ppc`, `/factory` PWA | OEE, WO mix, Gantt, output |
| MRP | `/dashboard/ppc`, `/dashboard/plant-manager` | shortages, reservations, Gantt, capacity |
| CRM | `/dashboard/customer-service` (proposed), `/dashboard/quality`, `/dashboard/plant-manager` | complaints, defect Pareto, price-request queue |
| B2B portal | **not covered on any dashboard** — portal is a separate principal surface (supplier/customer self-service); dashboard visibility of portal activity is limited to supplier shipments feeding GRN + portal RMAs feeding returns | — |
| Forecasting | `/dashboard/ppc`, `/dashboard/hr`, `/dashboard/finance`, `/dashboard/quality`, `/dashboard/plant-manager`, `/dashboard/purchasing` | 7 forecast panels + demand/stock-out/accuracy pages |
| Return management | `/dashboard/quality`, `/dashboard/purchasing`, `/dashboard/warehouse`, `/dashboard/customer-service` | RMA widgets, inspection staging, receiving |
| Assets | `assets.under_maintenance` widget; VP/PM/PPC/tech/supervisor only | no dedicated asset dashboard (proposed: asset-disposal queue on the finance page) |
| Quality | `/dashboard/quality` | inspection queue, Pareto, NCR list, chain coverage, display policy |
| Maintenance | `/dashboard/maintenance` (proposed) | open MWOs, due schedules, downtime Pareto, PM windows |
| Dashboard (KPI/widget/layout/action center) | `/dashboard/admin` (rollout health, widgets, layouts) | `WidgetSeedIntegrityTest`, `dashboard:rollout-health` |
| Auth/Admin | `/dashboard/admin` | sessions, locked accounts, failed logins, queue health, audit |
| Budgeting (module 17) | `/dashboard/finance` | budget-vs-actual, enforcement modes; budget/transfer custom approvals |

**Not covered, with reason:** cost accounting / cash-flow forecasts beyond the moving-avg panel (scope
cut, CLAUDE.md) · bank reconciliation, closing wizards, fiscal-period locking (scope cut) · activity
feeds outside SO/PO/WO/NCR (scope cut) · B2B portal activity on internal dashboards (separate principal
model; A§0/§3) · per-shot mold depreciation (scope cut).

### C3. Approval type × role quick-approve

`✅` = may act at some step (or is the single checker) · `—` = not eligible · `◇` = creates/submits but
cannot approve (self-approval guard) · `✖` = board-invisible (no ledger rows → no card today).

| Type | Ledger? | Act roles |
|---|---|---|
| Leave | ✅ ledger | department_head (step 1, dept) · hr_officer (step 2) |
| Purchase request | ✅ ledger | finance_officer (step 1) · vice_president (step 2 ≥₱50k) · RFQ-sourced: no chain |
| Purchase order | ✅ ledger | finance_officer (step 1) · vice_president (step 2 ≥₱50k) · purchasing_officer ◇ (buyer creates) |
| Bill payment | ✅ ledger | vice_president (single, ≥₱50k; below threshold auto-posts) |
| Loan / cash advance | ✅ ledger | department_head (1) · hr_officer (2 company) / finance_officer (2 CA, 3 company) · vice_president (≥₱50k) |
| Salary adjustment | ✅ ledger | production_manager (1) · vice_president (2) |
| Asset disposal | ✅ ledger | finance_officer (1) · vice_president (2) |
| Return request | ✅ ledger | department_head (1) · production_manager (2) · finance_officer (finance-only variant) · QC-generated supplier returns auto-approve |
| Payroll period | ✖ dead kind | finance_officer (approve/finalize/disburse, maker-checker) — card must be sourced from the period, not the ledger |
| Overtime | ✖ custom | hr_officer, department_head (`attendance.ot.approve`) |
| Stock adjustment | ✖ custom | finance_officer (`inventory.adjust.approve`) |
| Item cost review | ✖ custom | finance_officer (`inventory.costs.approve`) |
| Stock-count variance | ✖ custom | warehouse_staff (`inventory.stock_count.manage` — the role that also counts) |
| GRN reject | ✖ refusal arm | warehouse_staff |
| Payroll adjustment | ✖ custom | finance_officer (`payroll.adjustments.approve`; hr_officer creates) |
| Budget / budget transfer | ✖ custom | finance_officer, vice_president (`budgeting.approve`) |
| Invoice prebill | ✖ custom | finance_officer (`accounting.invoices.prebill_approve`) |
| Supplier listing | ✖ custom | purchasing_officer (`purchasing.supplier_listings.review`) |
| Asset transfer | ✖ unrouted | — (undecidable via API by design) |
| Return case | ✖ custom | customer_service_officer / purchasing_officer (`return_management.manage`) |
| Loan write-off | ✖ custom | finance_officer (`loans.write_off.approve`) |
| PPAP / late production report | ✖ custom | qc_inspector / production_manager (module-local) |

Roles with **no** approve path anywhere: `sales_officer`, `maintenance_tech`, `maintenance_supervisor`,
`impex_officer`, `employee`, `driver`, and (by design) `system_admin` — their dashboards need
operational queues, not approval cards.

### C4. Metric → source → aggregation → refresh cadence

| Metric | Source tables / columns | Aggregation | Refresh |
|---|---|---|---|
| OEE (KPI) | `work_orders`, `wo_operations`, machine `available_hours_per_day` | availability × performance × quality over closed WOs, month grain | monthly snapshot (2nd 03:00); ignores shifts/holidays/downtime |
| WO completion (KPI) | `work_orders.planned_end/actual_end/status` | finished-in-month ÷ due-in-month | monthly; can exceed 100% (month mismatch) |
| DPPM / FPY (KPI) | `inspections`, `inspection_measurements` | defect ppm / pass ratio per month | monthly |
| NCR closure days (KPI) | `non_conformance_reports` opened/closed | mean(closed − opened) | monthly; ignores still-open NCRs |
| OTD (KPI) | `deliveries` `planned_*`/`actual_*` | on-time ÷ delivered | monthly; `actual_*` unindexed |
| Supplier quality (KPI) | `supplier_performance` snapshots | weighted score (0.25/0.35/0.10/0.15/0.15) | monthly, 1st 02:00 recompute |
| Attendance rate (KPI) | `attendances` (overwrite-per-day) + `attendance_punches` | present ÷ expected per month | monthly; pulses/partial days need source queries |
| AR >60d (KPI) | `invoices` open balances | current balance snapshot vs period end | monthly (not a true historical aging) |
| Budget utilization (KPI) | `budgets`, budget lines, GL actuals | spent-or-committed ÷ approved (FY figure) | monthly; month arg ignored |
| Inventory turnover (KPI) | `stock_movements`, `stock_levels` | annualised issues ÷ **current** stock value | monthly; no historical stock snapshot |
| Units produced today | `work_order_outputs` | SUM(good qty) today | 60s poll (page), 30s fast |
| Active / overdue WOs | `work_orders` | COUNT by status, planned_end < now | 60s |
| Chain stage counts | chain tracker service + `manual_required` states | COUNT per stage | 60s; verified per owning scope |
| Pending approvals (top-8) | `approval_records` + owning row scopes | SLA-overdue-first, LIMIT 8 | 30s + Reverb `ChainStepAdvanced` |
| PR queue age / est. total | `purchase_requests`, `purchase_request_items` | SUM est. total, days waiting | 60s; `date` unindexed |
| PO pipeline | `purchase_orders.status` | COUNT per status (9, non-zero) | 60s |
| Supplier score / tier | `supplier_performance` | latest period overall + tier (A≥90/B≥75/C≥60) | daily/weekly read, monthly recompute |
| Low stock | `stock_levels.quantity`, `items.reorder_point` | available ≤ reorder point | 60s; full-table subquery today (R7) |
| Pending GRNs / QC holds | `goods_receipt_notes.status` | COUNT `pending_qc`; rows without inspections | 60s; `grn_items` join unindexed |
| Issues / transfers today | `stock_movements` | COUNT/SUM by type today | 60s |
| Zone utilization | warehouse zones, `stock_levels` | occupied ÷ total bins per zone | 60s |
| Pending inspections | `inspections.status` | COUNT draft+in_progress, outgoing first | 60s |
| Pass rate today | `inspections` result | passed ÷ (passed+failed) today | 60s |
| Open NCRs | `non_conformance_reports` | COUNT non-closed by severity | 60s |
| Defect Pareto | `inspection_measurements`, `work_order_defects` | top-6 defect params this month | 60s |
| Cash position | GL cash-account postings | posted balance | 30s cache; `LOWER()`+OR predicate (R6) |
| AR/AP aging | `invoices`, `bills` open balances | 4 buckets on balance | 30s cache |
| Revenue MTD | posted JEs on revenue accounts | SUM MTD | 30s cache |
| Payroll pipeline | `payroll_periods` (7 statuses) | COUNT per stage, 90-day window | 30s |
| Headcount by dept | `employees` (active, current) | COUNT per dept | 60s; history needs `employment_history` |
| On leave today / team DTR | `leave_requests`, `attendances` | COUNT approved covering today; present/late/absent-derived | 60s; absent is derived (active − present − late − on-leave) |
| Open MWOs / due schedules | `maintenance_work_orders`, `maintenance_schedules` | COUNT open / due ≤ N days | 60s |
| Downtime Pareto | `work_order_downtime` | SUM minutes by reason/machine | 60s |
| Molds ≥ 80/95% | `molds.shot_count/max_shots` | ratio → alert severity | 15-min alert engine, 60s read |
| Capacity utilization (forecast) | open WOs vs machine hours × OEE derate | util% per month, breach at 90% (hardcoded) | manual refresh (forecast panel) |
| Cash-flow forecast | `invoices` − `bills` monthly totals | moving avg, 3-mo horizon | manual refresh |
| Attendance forecast | `attendances` rate + approved leave load | mean rate + leave-load %, risk at 8%/15% (hardcoded) | manual refresh |
| Demand forecast / MAPE | `demand_forecasts`, `sales_order_items` | moving/weighted/manual; MAPE vs reconciled actuals | monthly generate (1st 04:00) + reconcile (2nd 04:00) |
| Stock-out risk | `stock_levels`, forecast or 30-day consumption | days-to-stockout, risk bands vs lead time | 60s read |
| Alerts (all kinds) | per-kind sources | threshold rules, 24h dedup | 15-min alert engine |
| Approval SLA | `approval_records` timestamps + settings | reminders 24h / escalations 48h, 6-hourly | 6-hourly sweep |
| Badges | 26 observers, `StockLevel` excluded | per-user COUNTs, TTL 30s | 60s poll + Reverb |

---

## D. Coverage gaps and build order

### D1. Gaps carried out of Phase 0 (blockers, ranked — unchanged)

1. Board-blind decision types (payroll + 14 custom) — needs per-type card sourcing before any dashboard
   approval centre.
2. Notification darkness (7+ types; mid-chain handoffs).
3. Index debt on dashboard fact tables (returns, budgets/credit lines, WO operations/logs, PR/PO dates,
   delivery actuals).
4. Finance dual-slug authority (M1).
5. Top-step fragility (VP has no escalation superior; auto-resolve ships disabled).
6. Mid-chain audit gap (`ApprovalRecord` has no `HasAuditLog`).
7. Uncached widget-data/rich payloads (R15) — cache layer before adding tiles.
8. KPI monthly grain — sub-month from sources.
9. Handoff-status dimensions first-class.
10. Test throughput (one suite per DB; targeted filters).

### D2. Gaps discovered while writing this matrix (new)

- **G-P2-1 / G-P3-1 (leak).** `attendance.view` and `leave.view` are self-service slugs held by all 17
  roles but used as **global** widget gates for `hr.team_dtr_today`, `hr.team_on_leave_today` and
  `forecast.attendance`. Every role, including `driver` and `warehouse_staff`, can mount
  department-wide team tiles. Fix by re-gating on management slugs (`dashboard.hr.view` /
  `attendance.*`) **or** by documenting the broad visibility as intentional (payload stays
  dept-scoped and null-safe). Pin the outcome with a visibility-matrix test — this is the CLAUDE.md
  self-scoped-permission-as-global-gate class.
- **G-P2-2 (gate outage).** `ppc_head` holds `production.view`/`wo.*`/`schedule.view` but **not**
  `production.dashboard.view`, so `production.kpi`, `machine.utilization`, `oee.gauges`, `kpi.oee` and
  `kpi.wo_completion_rate` are stripped from the role that owns the schedule. Either add the slug or
  re-gate those widgets on `production.view`.
- **G-P2-3 (strip ≠ gate).** Strip code lists were chosen per page, gates per module: PPC 1/4 of its
  codes survive, PM 2/3, purchasing 1/2, warehouse 1/2. Failure is silent (empty-message branch). Fix by
  deriving strips from `MODULE_PERMISSIONS` or asserting the pairs in a test.
- **G-P2-4 (dataset gate drift).** `forecasting.view` is held by finance, PM, PPC, purchasing and
  warehouse, exposing `forecast.cash_flow` (finance dataset) and `forecast.capacity` (production
  dataset) to buyers and warehouse staff. Apply the documented "projection of a dataset = the dataset's
  gate" rule uniformly and settings-drive the capacity-90% / attendance-8%/15% constants.
- **G-P3-2 (gate map can't express two qualified owners).** OTD is gated `dashboard.ppc.view`, attendance
  rate `dashboard.hr.view`; `impex_officer` and `department_head` need one of each and hold neither.
  `MODULE_PERMISSIONS` maps module→**one** slug; extend it to a set (or add new KPI definitions) rather
  than branching on role name.
- **G-P3-3 / G-P3-4 (landing + defaults).** Three P3 roles have a better primary surface than the grid
  (`/driver`, `/self-service`, proposed logistics/maintenance). Each new surface needs its own permission
  slug, and each new slug changes rarity-sorted dispatch for existing holders. Also
  `DashboardRoleLayoutSeeder`'s comment says "five roles with no grant" while eight lack one.
- **G-D-1 (two competing executive-page proposals).** M-p1 R4 `/dashboard/vp` and this file's
  `/dashboard/company` are the same surface — ship one.
- **G-D-2 (dispatch side effects).** Adding `dashboard.company.view` to VP moves the VP landing off
  `/dashboard/plant-manager` (today's behaviour "reads what it signs"): intended, but it is an
  access-model change to be made explicitly and recorded.
- **G-D-3 (unindexed links behind every "new" card).** Every proposed queue card (unassigned MWOs, POD
  missing, doc expiry, dept OT load) aggregates columns in the B/F top-10 index-gap list. Ship the index
  migrations first, in the correct `0NNN_` vs `2026_*` order.

### D3. Build order recommendation (highest-traffic roles first, per mission)

**Phase 2 foundation (no role pages yet):** analytics service layer + cache (00§blocker-7), index
migrations (00§blocker-3), metric dictionary from §C4, then the company-overview shell (B) because it
forces every data contract once.

1. **`system_admin` + `finance_officer` + `vice_president`** — money + approvals are the daily traffic and
   the company page's first two consumers; VP gains its executive page here (G-D-1 resolved).
2. **`production_manager` + `ppc_head`** — Chain 1 backbone; also the roles with the most gate defects
   (G-P2-2/G-P2-3) to clean while the page is open.
3. **`purchasing_officer` + `warehouse_staff`** — Chain 2; needs the returns index work first.
4. **`qc_inspector`** — IATF differentiator, already the cleanest strip; add root-cause ranking.
5. **`hr_officer`** — Chain 3; depends on the attendance/leave gate decision (G-P2-1).
6. **`maintenance_tech` + `maintenance_supervisor`** (shared page), then **`impex_officer`**,
   **`department_head`**, **`sales_officer`**, **`customer_service_officer`**, **`employee`**
   (self-service landing), **`driver`** (PWA landing).

Sequencing rules: one page per PR; every page ships with its 5 states, its row-scope reuse and its
`WidgetSeedIntegrityTest`/`ApprovalChainRolePermissionDriftTest` runs; no new chart dependency; no
role-name branch anywhere.

### D4. Open questions for the reviewer (stop point)

1. G-P2-1: re-gate the three shared-slug widgets, or document the broad visibility? — **OPEN**
2. G-P2-2: grant `production.dashboard.view` to PPC, or re-gate the four production widgets? — **OPEN**
3. G-P3-2: extend `MODULE_PERMISSIONS` to slug sets, or add new KPI definitions for OTD/attendance
   audiences? — **DECIDED 2026-10-11: slug sets.** `MODULE_PERMISSIONS` is now
   `array<string, list<string>>` (any-one admits) and `dashboard_widgets.permission_any` carries the same
   set on the tile side, so the scorecard and the widget cannot disagree. A new
   `dashboard.company.view` slug (held by system_admin + vice_president) appears in every module's set,
   which is what lets one grant admit all seven modules instead of handing the executive four whole
   module dashboards. See `PROGRESS.md` "Phase 2 — A". Still open from this item: `impex_officer` and
   `department_head` (OTD / attendance rate) need a surface that renders those KPIs, not a grant.
4. G-D-1: confirm **one** executive page (`dashboard.company.view` → `/dashboard/company`) replacing the
   `/dashboard/vp` proposal, and confirm the VP landing change (plant-manager → company) — **DECIDED
   2026-10-11: yes to both.** `/dashboard/vp` is not shipped. The landing move was verified against every
   demo account: only the VP's path changed. See `PROGRESS.md` "Phase 2 — B".
5. Which of the five proposed new pages ship first inside the D3 order (client-facing
   customer-service/sales, or internal logistics/maintenance)? — **OPEN**
