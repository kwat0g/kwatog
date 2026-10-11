# OGAMI ERP — Dashboards/DSS Discovery Synthesis (Phase 0)

Executive synthesis for a reviewer who will not read the six section files.
Compiled strictly from the sections — no new codebase research.
Code-citing section wins ties; conflicts are noted explicitly, never silently picked.

- Branch: `docs/dss-discovery-phase0`
- Method: read-only primary-source discovery (seeders, migrations, models, policies,
  routes, services, SPA source, live-DB `information_schema`/`pg_indexes`).
  No feature code touched.
- Sections (all in `docs/dashboards/phase0/`):
  A-roles-access · B-modules-data · C-approvals · D-dashboard-dss · E-frontend · F-realtime-perf

---

## Overview

Six sections investigated the full substrate a dashboard/DSS phase will build on:
who can see what (17 roles, dispatch, 12 backend↔frontend gating mismatches),
what data exists (20 module areas, 261 tables, three end-to-end chains traced to exact
FK/event/call links, 80-table forecastability verdicts, 86 missing-index gaps),
how approvals move (9 registry kinds + 14 custom single-step types, stuck paths,
notification/audit gaps, inline-approve readiness), what dashboards/DSS already exist
(8 bespoke pages, 65-key widget catalog, 11-KPI monthly system, 7 forecast panels,
38 DSS behaviors, 12 unserved opportunities), the frontend foundation (27 prod deps,
30 dashboard reusables, token/guard/query contracts), and realtime/perf reality
(14 broadcast events, 32 cache keys, 15 query risks, test-infra constraints).

Headline: the foundation is real (permission-derived dashboards, ledger-backed chains,
idempotent schedulers) but a dashboard approval center is **board-blind for payroll +
all custom approvals** (no ledger rows), **notification-dark for 7+ decision types**,
and **index-poor on returns/budgets/WO-operations** — those three set the Phase 1 order.

Source: `docs/dashboards/phase0/` (all six files; per-section paths at each § end)

---

## A. Roles & access

### 17-role list (one line each)

| # | Role slug | One line |
|---|---|---|
| 1 | `system_admin` | Wildcard superuser (IT-only; top step of no workflow, cannot award RFQs) |
| 2 | `hr_officer` | HR/attendance/leave/loans operator; payroll maker (create/compute, never approver) |
| 3 | `finance_officer` | Accounting/budgeting owner; payroll checker; PR/PO/bill/asset step approver |
| 4 | `vice_president` | Executive terminal approver on all money chains; no dashboard of its own |
| 5 | `customer_service_officer` | CRM complaints/inquiries/RMA lifecycle; lands on `/dashboard/default` |
| 6 | `sales_officer` | SO full lifecycle + price propose; default landing; sees `/chains`, not its bottleneck data |
| 7 | `production_manager` | Production/WO lifecycle + plant-manager dashboard; sole factory-PWA holder |
| 8 | `ppc_head` | MRP/forecasting + WO create/confirm + routings; PPC dashboard; bottleneck API but no `/chains` page |
| 9 | `purchasing_officer` | Sourcing runner (RFQ award yes, PR/PO approve no); purchasing dashboard |
| 10 | `warehouse_staff` | GRN/issues/stock-count/MRB/transfer; warehouse dashboard; no fleet/shipments-create |
| 11 | `qc_inspector` | Quality specs/inspections/NCR/calibration/PPAP + return inspect; quality dashboard |
| 12 | `maintenance_tech` | MWO executor (create/complete, no verify/assign); no dashboard; ironically lacks `work.assigned` |
| 13 | `maintenance_supervisor` | MWO assign/verify + schedule manage; no dashboard; migration grants lag seeder grants |
| 14 | `impex_officer` | Shipments/deliveries/fleet; no dashboard; cannot open driver PWA |
| 15 | `department_head` | Dept-scoped approver/creator (leave/loan/PR/RMA step 1); default landing |
| 16 | `employee` | Self-service baseline only; 15-min session timeout |
| 17 | `driver` | Driver PWA (`driver.access`) + self-service; nothing else |

8 of 17 land on `/dashboard/default` (5, 6, 12, 13, 14, 15, 16, 17). 3 roles have no
role-default layout rows (`vice_president`, `customer_service_officer`, `sales_officer`).

### Authority chain (5 lines)

1. One user = one role (`users.role_id` FK); effective permissions = role grants +
   per-user grant/revoke overrides (expiry-honored, read fresh, never cached at slug level).
2. `system_admin` bypasses every permission check (backend `hasPermission`/`CheckPermission`,
   SPA `usePermission`/sidebar); business carve-outs are coded by slug, not permission.
3. Backend denies with 403 (`CheckPermission`); row visibility is per-module policy code;
   frontend is a 3-layer UX guard (`AuthGuard` → `ModuleGuard` → `PermissionGuard`, 404-lookalike denials).
4. Landing is server-side (`GET /dashboard/dispatch`, rarest-permission-wins counted live
   from `role_permissions`); 8 bespoke dashboards keyed by permission, `/dashboard/default` fallback.
5. Approval delegations add step-roles windowed (exact-role or blanket); portal principals
   are separate guards rejected from internal endpoints.

### The 12 mismatches (grouped by severity, with file refs)

**Break seeded-role flows:**
- M10 — `/chains` page (`crm.sales_orders.view`) vs bottleneck API (`dashboard.view_bottlenecks`)
  diverge both ways: `sales_officer` opens page/403s data, `ppc_head` can call API but is 404'd off page.
  (`spa/src/routes/dashboardRoutes.tsx:102-105`, `api/routes/api.php:118-121`)
- M5 — `/maintenance` home has no guard; `driver`/`sales_officer` open an empty shell whose calls all fail.
  (`spa/src/routes/maintenanceRoutes.tsx:22`)
- M6 — WO detail `anyOf[view, work.assigned]` is broader than backend `CheckMaintenanceWorkAccess:read`;
  every `employee` can open arbitrary order URLs the API 404s.
  (`spa/src/routes/maintenanceRoutes.tsx:30-31`,
  `api/app/Modules/Maintenance/Middleware/CheckMaintenanceWorkAccess.php:20-36`)

**Bite custom roles / overrides only (no seeded role splits them):**
- M1 — finance page guards `dashboard.accounting.view` but its data call needs
  `accounting.dashboard.view` (and the page doc contradicts its own guard).
  (`spa/src/routes/dashboardRoutes.tsx:64-65`, `spa/src/api/accounting/dashboard.ts:5-8`,
  `api/app/Modules/Dashboard/routes.php:69-70`, `spa/src/pages/dashboard/finance.tsx:54-60`)
- M4 — OT create page checks `attendance.edit`, store endpoint checks `attendance.ot.create`.
  (`spa/src/routes/hrRoutes.tsx:124-128`, `api/app/Modules/Attendance/routes.php:55`)
- M7 — self-service pages are guard-free while the API is not (revoked users keep pages, eat 403s).
  (`spa/src/routes/selfServiceRoutes.tsx:29-75`)
- M8 — `/approvals/delegations` unguarded; backend is service-scoped.
  (`spa/src/routes/dashboardRoutes.tsx:92-95`, `api/app/Modules/Admin/routes.php:231-244`)
- M12 — sidebar shows `/notifications` to everyone; route needs `notifications.view` (diverges only on revoke).
  (`spa/src/components/layout/Sidebar.tsx:190-196`, `spa/src/routes/dashboardRoutes.tsx:119`)

**Narrower-SPA-than-API by design (404 for APIs the user could call):**
- M2 — leave management list needs approver perms in SPA, `leave.view` in API.
  (`spa/src/routes/hrRoutes.tsx:136-153`, `api/app/Modules/Leave/routes.php:36`)
- M3 — attendance register needs manager perms in SPA, `attendance.view` in API.
  (`spa/src/routes/hrRoutes.tsx:101-104`, `api/app/Modules/Attendance/routes.php:39`)
- M9 — warehouse structure pages need `warehouse.manage`, APIs need `inventory.view`.
  (`spa/src/routes/inventoryRoutes.tsx:125-132`, `api/app/Modules/Inventory/routes.php:90`)

**Inert but diverged:**
- M11 — `is_superuser` bypass exists only in SPA; the API never sends or checks it.
  (`spa/src/hooks/usePermission.ts:6`, `api/app/Modules/Auth/Resources/UserResource.php:13-45`)

Doc-vs-code extras: seeder defines 17 roles vs CLAUDE.md's 14 (missing
`customer_service_officer`, `sales_officer`, `maintenance_supervisor`); stale
`crm_commissions` grant line grants nothing; `maintenance_supervisor` migration set is
thinner than the seeder set (migrated-not-reseeded DBs lack `wo.verify` + `mrp.machines.view`).

Source: `docs/dashboards/phase0/A-roles-access.md`

---

## B. Modules & data

### Module table (condensed: models → dashboard-critical fact tables)

| Module | Models | Dashboard-critical tables / notes |
|---|---|---|
| HR (19) | employees, departments, history, salary history, recruitment, training | `employees` is OVERWRITE-current; history via `employment_history` + `employee_salary_history` |
| Attendance (6) | attendances, punches, shifts, assignments, OT, holidays | `attendances` OVERWRITE-per-day; intra-day history = `attendance_punches` |
| Leave (5) | requests, balances, types, year-end | balances mutated in place (OVERWRITE-per-year) |
| Payroll (11) | periods, rows, claims, deductions, adjustments, anomalies, 13th, bank, proofs, de-minimis, gov tables | claims UQ = no-double-pay guard; periods STATUS-MACHINE (7 statuses) |
| Loans (2) | loans, payments | balances mutated; history = `loan_payments` LEDGER |
| Accounting+budget (23) | COA, JE, vendors/customers, bills, invoices, collections, OR, credit notes, budgets, fiscal years | GL ref polymorphic (convention); budgets table has ONLY pkey |
| Inventory (22) | items, warehouse tree, stock levels/movements, GRN, issues, MRB, adjustments, counts, transfers | movements LEDGER; levels OVERWRITE, no audit rows |
| Purchasing (18) | PR/PO, RFQ/quotes/awards, suppliers, listings, snapshots, dispatches | money computed once (`RfqCommercialCalculator`); quote history not kept |
| SupplyChain (19) | shipments, containers, docs, lots, vehicles, deliveries+items, attempts, reservations | delivery→invoice lossy by design; actual_* timestamps unindexed |
| Production (14) | WOs, operations, outputs, materials, defects, crews, downtime, schedules, routings, logs | `wo_operations` has NO non-PK index; `sales_order_item_id` unenforced |
| MRP (7) | runs, plans, BOMs, machines, molds, mold history | run→order trace via bridge json; products live in CRM |
| CRM (12) | SOs, lines, products, pricing, complaints, 8D, responses, allocations | SOs STATUS-MACHINE w/ 8 indexed stage timestamps; lines have NO timestamps |
| B2B (8) | portal users, supplier shipments, docs, schedules | writes via `SystemUserResolver::impersonate` (audit-FK safety) |
| Forecasting (1) | demand_forecasts | actuals late-filled; only NOT-NULL rows comparable |
| Returns (10) | requests/items, receipts, cases/lines/events | least-indexed fact tables in the system |
| Assets (4) | assets, depreciations, runs, transfers | transfers unrouted (scope cut) |
| Quality (18) | inspections, measurements, specs, NCRs, calibration, PPAP | result-versioned LEDGER; NCR STATUS-MACHINE |
| Maintenance (7) | MWOs (polymorphic), schedules, windows, logs, readings, spares | MTTR needs `started_at` index |
| Dashboard (6) | KPI defs/snapshots, widgets, layouts, action-center | snapshots monthly grain ONLY |
| Auth/Admin (+) | users, roles, overrides, login history | single-role users; overrides additive |

Live schema: 261 tables. SCHEMA.md ("~88 tables / 15 modules / migrations–0169") is stale
in ≥16 places; CLAUDE.md counts (43 cron entries, max migration 0481, budget "revisions"
table, 1900-test suite) are stale/unverified — code wins.

### Three chains (5–8 lines each)

**CHAIN 1 — Order to Cash.** SO confirm emits `SalesOrderConfirmed`, stamps `mrp_plan_id`;
MRP engine reuses/creates plan, writes shortage PRs + draft WOs (incl. subassembly), cancels
stale planned WOs; capacity service writes pending `production_schedules` (gist no-overlap);
WO outputs recorded idempotently (movement + handoff); in-process QC gates the output path;
outgoing QC is one-live-per-output with a dispatch release gate; delivery requires an explicit
passed output-bound inspection; confirm attaches CoC + advances SO + best-effort draft invoice
(`Generated`/`ManualRequired`/`NotRequired`); invoice finalize marks SO invoiced→paid→closed;
collection posts JE + 1:1 official receipt.

**CHAIN 2 — Procure to Pay.** MRP shortage → auto PR (`mrp_plan_id`, `is_auto_generated`);
money-only PR chain (RFQ-sourced PRs skip it — need-only approve); approved PRs auto-convert
to one draft PO per vendor; RFQ→invitations→quotes (one/vendor)→awards (one winner/line)→PO
with line trace cols; dispatch + portal shipments feed GRN; shipment (ImpEx, landed costs)
→ GRN (≤1 per shipment) → incoming-QC trigger per line + accept gate → accept moves stock
(WAC recompute) + posts GL atomically; bill carries 3-way-match snapshot (stock provenance
needs supplier invoice); payment posts (below ₱50k auto-posts); void reverses JE + chains
replacement; BIR-2307 service attached.

**CHAIN 3 — Hire to Retire.** Hire writes `employees` → `provisionForEmployee` creates the
user (`users.employee_id` UQ FK); shift assignment (employee+effective UQ) → biometric CSV
→ `attendance_punches` → DTR compute (manual rows protected); leave HR-approval stamps
`attendance_snapshot` + mutates balances; OT auto-detect (partial UQ); payroll compute reads
attendance/OT/gov-by-`payroll_date`/loans/adjustments/13th with `EmployedDayFraction` proration
shared with HR final pay; `payroll_cycle_claims` UQ blocks double-pay; finalize emits GL-post
request (void reverses JE), bank file + proofs, payslip emails; separation → `clearances`
(breakdown json + JE) → final pay consumes loans; active-clearance staff excluded from scope.

**Broken / lossy links (12, all verified):** F1 WO→SO-line unenforced (zero FK rows —
dashboards LEFT JOIN) · F2 delivery→invoice valid with NULL invoice (`manual_required`) —
count handoff_status, not invoices · F3 pending_qc GRNs with no inspection rows (sweeper
exists because the gap is real) · F4 completed WOs without outgoing inspection (same) ·
F5/F8 GL + stock-movement references polymorphic by convention · F6 MRP run→order trace
only via summary-json + plans bridge (run_id unindexed) · F7 forecast actuals late-filled ·
F9 inspections dual addressing (prefer typed FKs) · F10 NCR-close WO creation can fail
logged-only (reconcile `disposition=rework` + NULL WO ids) · F11 stock tables have no audit
rows (ledger only) · F12 returns fact tables nearly unindexed.

### Forecastability verdicts (80 rows)

**TRENDABLE-LEDGER 32 · TRENDABLE-STATUS-MACHINE 22 · TRENDABLE-SNAPSHOT 2 ·
MIXED 15 · OVERWRITE-current/per-day/per-year 9.**
Rule of thumb: anything needing a line-item date, a status history, or sub-month grain
must join the parent/ledger — never the header alone. Line-item tables mostly lack dates
(`sales_order_items`, `purchase_order_items`, `invoice_items`, `bill_items`,
`work_order_defects`, `payroll_deduction_details`); status history = `audit_logs`
(136 models; stock tables excluded); KPI grain is monthly (2nd @ 03:00 compute).

### Index gaps (86 across ~45 tables + ~300 intentional audit-FK omissions)

Top-10 cheapest wins: `wo_operations.work_order_id` (table has zero non-PK indexes) ·
`production_logs.wo_operation_id` + `operator_id` (only-pkey table) ·
`grn_items.goods_receipt_note_id` (line→header join) · `return_requests` status/type/date +
all business FKs (returns dashboard has no primary filter index) · `budgets`
fiscal_year/department/status (table has ONLY pkey) · `credit_note_lines.*` (only pkey) ·
`purchase_requests.date` + `purchase_orders.date` (aging/cycle-time) ·
`deliveries.delivered/confirmed/received_at` (OTD) · `work_orders.product_id`/`mold_id`/
`actual_start/end` (mix/load/lead-time) · `invoices.sales_order_id`/`delivery_id`
(SO→invoice→delivery joins).

Source: `docs/dashboards/phase0/B-modules-data.md`

---

## C. Approvals

### 9 registry kinds (step-ledger, board-visible, SLA-swept) — condensed

| Type | Chain | Notes |
|---|---|---|
| Leave | `department_head` → `hr_officer` (separate arms) | No draft; dept-arm scope-checked; sole-head's own leave always stalls |
| Purchase request | `finance_officer` → `vice_president` ≥ ₱50k | RFQ-sourced skips chain; budget-ack gate; money-only so self-guard can't strand |
| Purchase order | `finance_officer` → `vice_president` ≥ ₱50k | Buyer never a step; +vendor-SoD/PPAP/budget gates at approve |
| Bill payment | single `vice_president` ≥ ₱50k, else auto-posts | Changed under CLAUDE.md text (was Finance→VP) |
| Loan / cash advance | CA 3-step (dept→finance→VP); company 4-step (+`hr_officer`, VP ≥ ₱50k) | Step 2 now HR (was PM); row-scoped decide |
| Salary adjustment | `production_manager` → `vice_president` | One pending/employee; PM self-request stalls |
| Asset disposal | `finance_officer` → `vice_president` | No disposal status; JE posts on final approve inside tx |
| Return request | `department_head` → `production_manager`; finance-only single | Step 1 unscoped (any head); QC supplier returns auto-approve |
| Payroll period | **No chain — custom maker-checker, unwired workflow** | Approver ≠ creator ≠ computer; **board `payroll` kind is dead (never any rows)** |

Shared engine guards: self-approval refuse, distinct-decision-maker per attempt,
role-or-delegate check, ₱50k threshold skip, lock-then-guard on every act path.
Sweep: reminders 24h + escalations 48h (6-hourly, notify-only); auto-resolve ships disabled.

### 14 custom single-step types (no ledger, no board card, no sweep) — condensed

Overtime · stock adjustment · item cost review · stock-count variance · GRN reject
(refusal arm, not an approval) · payroll adjustment · budget (+different-role rule) ·
budget transfer · invoice prebill (+portal payment submissions) · supplier listing ·
asset transfer (**service exists, unrouted by design — undecidable via API**) ·
return case · loan write-off · PPAP / late production report.

### 6 stuck risks

1. Leave: department with no active head (step 1 is head-only; escalation notifies
   `production_manager`, who cannot act); sole head's own leave stalls unconditionally.
2. Bill payment: VP seat empty + above-threshold = permanent stall; escalation resolves
   to nobody (`resolveSuperior` null at VP).
3. Loans: borrower's department with no head stalls at step 1 (both chains).
4. Salary adjustment: PM self-request self-blocks step 1 (single-holder by default).
5. Payroll period: one person creates+computes with no second `approve` holder and no
   override grant = silent stall (no notification fires at all).
6. Custom single-step family (systemic): no timeout, no delegation, no sweep — a sole
   eligible checker on leave = silent stall. Self-guards hold everywhere, so the failure
   mode is stall, never self-approval.

### Notification gaps

Missing entirely: bill-payment submit/decide, salary-adjustment request/decide,
asset-disposal request/decide, stock/payroll-adjustment, budget + transfers, invoice
prebill, cost review. Missing mid-chain handoffs: loan step transitions, PR/PO
Finance→VP, return step handoffs, payroll waiting-approval. Present: leave submit/arms/
decide, loan submit + final decide, PR chain broadcast, PO submit + full-approve,
OT submit/decide, portal RMA + QC-defect notices, SLA reminder/escalation (ledger rows
only). Audit: parents carry `HasAuditLog`, plus explicit payroll-approve row — but
**`ApprovalRecord` itself has no audit trait**: intermediate step decisions leave no
`audit_logs` row (no IP/user-agent); the ledger is the only trail.

### Inline-readiness verdict

All 8 wired ledger types **Ready** (approver/state/SoD/idempotency/locking all exist
server-side; UI must route leave's two arms and surface preconditions: budget-ack, vendor
SoD, PPAP, truck-return guard). Payroll is **Ready but board-blind** (no ledger rows → no
card to hang the button on). Custom types are **Action-ready, discovery-blind** (need
per-type card sourcing + precondition surfacing). No generic `POST /approvals/{id}/approve`
exists — a dashboard button must call N module endpoints with N payloads (remarks required
on every reject).

Drift guard to run on any chain touch:
`tests/Feature/Approvals/ApprovalChainRolePermissionDriftTest.php`.

Source: `docs/dashboards/phase0/C-approvals.md`

---

## D. Dashboard & DSS as-built

### Pages / widgets / forecasts (counts)

- **Pages:** 8 bespoke (`admin`, `plant-manager`, `hr`, `ppc`, `finance`, `purchasing`,
  `warehouse`, `quality`) + `/dashboard/default` fallback + employee self-service
  (`GET /dashboards/employee`, auth-only) + admin system-health page (sessions, auth,
  queue, audit — no business KPIs). Every bespoke payload returns `{kpis, panels}`
  with per-item `PanelGate` permission filtering (refused = omitted, not zeroed), 30s
  per-user cache. Branch additions: KPI strip on every bespoke header, top-8 approvals
  worklist widget, freshness polish (per-widget `updated_at`, 2-min stale badge, 5 states).
- **Widgets:** 65-key catalog (54 scalar + 11 `kpi.*`), each row declaring permission /
  render_kind (scalar/breakdown/trend/table/gauge) / `link_path` / module; scalar miss →
  `available:false`, rich miss → scalar + `data_unavailable`. `WidgetSeedIntegrityTest`
  pins registry↔provider↔link bijection.
- **Forecasts:** demand engine (moving/weighted/manual + monthly generate + reconcile +
  MAPE/bias accuracy + dispersion confidence) · stock-out projection (forecast-then-history,
  MOQ, risk bands) · forecast→MRP advisory projection · 7 dashboard panels (headcount,
  revenue, defect rate, supplier risk, cash flow, capacity, attendance — all
  moving-average + seasonal-adjust + linear-regression trend, 12-mo history / 3-mo horizon).

### KPI system (5 lines)

1. 11 definitions with target + warning + direction (oee 85/75, dppm 500/750, FPY 98/95,
   OTD 95/90, supplier quality 90/80, attendance 96/93, ar-aging-60d 5/8, budget 90/80,
   ncr-closure-days 5/8, turnover 6/4, wo-completion 95/90).
2. `kpi:compute-monthly` (2nd @ 03:00, per-definition isolation, idempotent, null-safe).
3. Tiles read snapshots — **one month behind by design**; daily/weekly trends must query sources.
4. Read boundary `KpiSnapshotService::MODULE_PERMISSIONS`, enforced identically on all
   endpoints and reused as `kpi.*` widget permission; no role-name branches.
5. Known calculator gaps: OEE ignores shifts/holidays/downtime calendars; WO completion can
   exceed 100% (numerator/denominator month mismatch); turnover divides by *current* stock
   (no historical snapshot); budget is a fiscal-year figure (month ignored); AR-aging is a
   current-balance snapshot, not historical aging.

### 38 DSS behaviors (grouped)

- **Alert engine 1–13** (15-min run, 24h dedup): stock critical/low, no-supplier, machine
  breakdown, mold 80%/95%, WO overdue, MRP schedule conflict, OEE-quality 0.75/3d/100-out,
  AR 30d/60d, AP-due-soon 3d, scrap-rate 5%/24h/100-out, scheduler-stall signal (not a dead-man).
- **Escalations/SLAs 14–19:** approval 24h/48h (6-hourly); NCR 15-min tiers; 8D 15-min;
  CAPA 30d check/14d escalation; Action Center 24h default (maint critical 8h, prod critical 48h);
  document-review 7d, PPAP-expiry + calibration-30d + training-30/14/7d sweeps.
- **Inventory/procurement 20–25:** daily MRP auto-PR (2d buffer, 14d fallback lead);
  auto-replenishment (nets in-transit/QC-held + open commitments; critical single-supplier → auto-PO);
  nightly safety-stock (Z·σ·√LT, Z=1.65); PR→PO auto-consolidation; single RFQ money calculator;
  material reservations.
- **Scoring 26–30:** supplier monthly score (0.25/0.35/0.10/0.15/0.15, neutral-50, tiers A≥90/B≥75/C≥60/D);
  payroll anomaly flags (net-change 0.30, OT 80h, deduction 0.50 — block finalize);
  budget warn/block/off (0.80/0.95/1.00/1.20); predictive-maintenance thresholds (85 °C, 7.1 mm/s,
  2–12 bar, 150 A, 70% oil; 3 consecutive breaches → MWO); repeat-breakdown sweep + assignment watchdog.
- **Cash 31–32:** AR dunning tiers 7/15/30d (idempotent); pricing-review daily + dispatch recovery.
- **HR 33–35:** payroll auto-create + reapers + payslip-email reconcile; onboarding/recruitment/
  timeout/leave-forfeiture jobs; probation horizon.
- **Quality 36–38:** NCR→replacement-WO on close (stock batches; SO-line failures owned by MRP re-plan),
  incoming-QC retry 15-min, missing-outgoing sweep hourly; chain-bottleneck hourly; RFQ-close + WO-notify
  minutely, production summaries daily/Friday.
- **Cross-cutting:** badge severity (>20 danger), digest 07:05, critical-email retry, budget-GL sync,
  period auto-relock 48h.

### 12 opportunities (no system help today)

1. Recommended supplier (score+price+lead+MOQ) · 2. Order quantity (EOQ/coverage on PR lines) ·
3. WO release timing (per-machine earliest-feasible-date; Gantt is display-only) ·
4. OT/hiring recommendation (hours shortfall → authorize N OT / open req) ·
5. Cash-commit scenarios at approval time · 6. Price-renewal suggestion + award recommendation ·
7. NCR root-cause ranking · 8. Usage-rate-based PM slot planning ·
9. SO commit-date feasibility + expedite ranking · 10. Budget-transfer proposals ·
11. Collector worklist by recoverability · 12. Auto-select/blend forecast method per product.

Doc gaps: scorecard endpoint + `getScorecard` still live/tested while SPA page deleted
(`docs/e2e-audit.md` still cites it); KPI strip-vs-tile windows undocumented; forecast-gate
rule (dataset gate vs `forecasting.view`) lives only in code comments; capacity/attendance
panels have no history (trend ≈ flat arithmetic); capacity-90% + attendance-8%/15% are
hardcoded consts, not settings.

Source: `docs/dashboards/phase0/D-dashboard-dss.md`

---

## E. Frontend

- **Deps (27 prod):** recharts 2.15.4 · react-query 5.100.8 · react-table 8.21.3 ·
  react-hook-form 7.75 + resolvers 3.10 + zod 3.25.76 · react-router-dom 7.18.2 (pinned) ·
  react 18.3.1 · axios 1.20 · zustand 5.0.12 · react-hot-toast 2.6 · tabler icons 3.46 ·
  date-fns 4.1 · leaflet + react-leaflet · laravel-echo 1.19 + pusher-js 8.5 · qrcode ·
  three/gsap/lenis (landing-only, lazy) · 3 fontsource families · clsx.
  Absent by audit: d3/echarts/chart.js, ag-grid, i18n, jspdf/html2canvas/file-saver,
  react-grid-layout/dnd-kit, Radix/MUI. Bundle discipline is structural (`manualChunks`
  per vendor incl. landing-only and realtime splits).
- **30 widgets (6 chart primitives + 24 dashboard-level):** AreaTrend, BarComparison,
  DonutBreakdown, SparkLine, OeeGaugeChart, DowntimeParetoChart; DashboardShell(+KpiGrid),
  DashboardGrid(+Item), LiveDashboardWidget, WidgetBreakdown, WidgetTable,
  ApprovalsPendingTable, KpiStrip, DashboardPicker, RoleDashboard (legacy), ChainBottleneckWidget,
  Forecast/Demand/Accuracy/Capacity/CashFlow/AttendanceRisk/StockOut panels, FinanceSection,
  SeverityBadge/LeadStat/StatBand/DashSection, PeriodControl(+Note), StatCard+Panel.
  Server-contract types imported, not re-declared. Grid = CSS 12-col (no drag/resize — cut
  scope); drill-down = tile "Open →" (server `link_path`) + KPI anchors + legacy link maps;
  refresh = 60s poll bespoke / 30s fast / forecasts manual-only / badges 60s+push;
  states = shell-owned skeleton/error/empty/stale + per-cell error boundaries; export =
  server CSV/XLSX + doc PDFs exist, **dashboard/chart export absent**.
- **Top-5 gaps:** (1) per-widget export — shared component, no new dep (server endpoint +
  SVG-serialize PNG; PDF stays server-side); (2) categorical colorblind-safe chart palette —
  tokens only (`--chart-1..8` ×3 palettes + contrast gate); no second chart lib;
  (3) dashboard date-range standard — promote PeriodControl+PeriodNote into shell header;
  per-shift/payroll-cutoff presets need backend `rangeBounds()` first; (4) i18n — new dep
  only when a second locale is committed (react-i18next, lazy namespaces); (5) tile-level
  realtime — wiring over existing Echo/Reverb, no new dep. Explicit non-gaps: grid lib,
  second chart lib, ag-grid, component kit, file-saver. Tokens vs DESIGN-SYSTEM: two
  `--text-subtle` hexes + dashboard-pattern header ("range + export") are doc bugs, file wins.

Source: `docs/dashboards/phase0/E-frontend.md`

---

## F. Realtime / perf

- **14 broadcast events (all private channels):** BadgesChanged (empty payload, open-auth) ·
  UserNotificationCreated (`user.{id}`, bell 30s fallback) · PermissionOverrideChanged ·
  ChainStepAdvanced (only `ShouldBroadcastNow`) · 8 production/MRP events on
  `production.dashboard` (+ per-WO/machine channels) consumed by the production dashboard ·
  MaintenanceWorkOrderCreated · PayrollProgressEvent (per-period channel, 3s poll fallback +
  cached snapshot). Declared-but-unlistened: `inventory.stock` (authorised, zero subscribers).
- **32 cache key patterns:** procurement-chain 5 (60/120s, TTL-only, unscoped counts) ·
  `dashboard:production` + `inv:dashboard:summary` (30s) · `mrp:plant-run` lock (1200s) +
  `payroll:progress:*` (3600s) · gov-table active/version/effective sets · auth role/user
  perm sets · 10 role-dashboard per-user 30s keys + finance signature-key + statement keys
  (tag-flushed on every JE write) · badges per-user+version (generic observer on 26 models;
  `StockLevel` excluded) · holidays + settings (3600s) + `system:user_id` (forever).
  Queued = ~92 `ShouldQueue` listeners/jobs via Redis (outbox `record` + afterCommit dispatch +
  minute-level `outbox:dispatch` recovery); sync = ChainStepAdvanced, widget/KPI reads.
- **15 query risks (summarized):** PHP-side aggregation over full loads (R1 outputs-today,
  R2 active-SO hydration, R3 OEE 3×N queries, R4 full-window OEE get, R5 two full inventory
  tables, R7 whole-stock low-stock subquery) · index-defeating predicates (R6 cash-position
  `LOWER()`+OR over the biggest ledger, R8 `DATE()` group-by, R9 4× invoice scans with date
  arithmetic) · KPI-compute-only risks (R10 unindexed `actual_end`, R11 full-table SUMs,
  R12 >100% WO-completion contract gap) · fan-out (R13 ~30 badge COUNTs/user/TTL) ·
  uncached hot paths (R14 rich tiles double-query; **R15 widget-data + rich payloads have no
  cache at all** — every 30–60s poll hits transactional tables live). B-index gaps amplify
  all of the above; failures degrade tile-by-tile to "no data" (load hides as emptiness).
- **Test constraints:** one shared `ogami_test` DB — parallel agents must mint their own
  (`ogami_test_*` allowed, `CREATE DATABASE … OWNER ogami`); ~1900 tests / ~28 min, sharded
  runner (`make test`), `--filter` loops, full suite only at end; test env forces
  sync/array/log (no Redis/Reverb assertions in PHPUnit — perf needs staging smokes);
  128M PHP OOM → 512M bump; seed varchars ≤20 chars; `role_id` attach (never `assignRole()`);
  `Storage::fake('local')`; index migrations must respect `0NNN_` vs `2026_*` ordering.

Source: `docs/dashboards/phase0/F-realtime-perf.md`

---

## Consolidated blockers for dashboard work (ranked)

1. **Board-blind decision types** (C §2.9/§4/§5, FINDINGS F-cust): payroll + all 14 custom
   types write no ledger rows — an approval center needs per-type card sourcing first.
2. **Notification darkness** (C §3): 7+ types fire nothing on submit/decide; "my action"
   counts will surprise approvers never told. Mid-chain handoffs missing even on ledger types.
3. **Index debt on dashboard fact tables** (B §4, F §2): returns (no status/type/date/counterparty
   index), budgets + credit lines (pkey-only), WO-operations/logs (join cols unindexed),
   PR/PO dates, delivery actuals. Migrate before aggregating (respect `0NNN_`/`2026_*` ordering).
4. **Finance dual-slug authority** (A M1): page gate vs data gate vs widget permission are three
   answers — every new finance surface must pick deliberately.
5. **Top-step fragility** (C §2 head): VP has no escalation superior; auto-resolve ships disabled
   (enabling with `default_action=reject` silently kills, `approve` silently spends).
6. **Mid-chain audit gap** (C §3): `ApprovalRecord` without `HasAuditLog` — per-step IP-stamped
   trails need a ledger→audit bridge if compliance requires it.
7. **Uncached hot paths** (F R15 + R1–R14): widget-data + rich analytics recompute live per poll;
   add a cache layer before adding DSS tiles or load scales with users × keys.
8. **KPI monthly grain** (B G3, D §2): sub-month trends query source tables; do not stretch snapshots.
9. **Handoff-status dimensions** (B G5/F2–F4): `manual_required` backlogs must be first-class on every
   funnel or broken links read as zero volume.
10. **Test throughput** (F §4): one suite at a time per DB; targeted `--filter` loops; staging smokes
    for anything Redis/Reverb-shaped.

---

## Discrepancies where briefing assumptions contradict code findings

(Assumptions = the discovery mission's expected counts, verified against sections.)

- **Verified as stated:** 12 backend↔frontend mismatches (A) · 9 ledger + 14 custom approval
  types (C) · 8 bespoke dashboards (A/D) · 38 DSS behaviors (D §4, numbered 1–38) ·
  12 DSS opportunities (D §5) · 30 frontend widgets (E §2a) · 14 events / 32 cache keys /
  15 query risks (F) · dead payroll board kind, VP escalation gap, bill-payment drift,
  asset-transfer unrouted, ApprovalRecord audit gap, custom-approval notification gaps,
  scorecard leftovers, B-F1…F12 links, A-M1…M12 bugs — all present as briefed.
- **Nuance 1 — "9 ledger types": only 8 ever write rows.** `payroll` is a registry kind with a
  seeded-but-unwired workflow; zero `approval_records` rows can ever exist for it (C §1a/§5.1,
  A §5.9). Count as 8 live + 1 dead, not 9 live.
- **Nuance 2 — widget scalar count 54 vs 55.** D §1.3 reports 65 keys (54 + 11 KPI) from the
  seeder catalog; F §3 reports 55 scalar keys in `LINK_BY_KEY`. Both count the same seeder file
  at different line windows — recount `DashboardWidgetSeeder.php:48-105` before citing either
  number. Code-citing section wins no tie here (both cite code); flagged, not resolved.
- **No other cross-section conflicts found.** A/D dispatch rules agree; B/C/F chain, sweep,
  and schedule claims agree (63 scheduled entries; 43/42-figure comments stale everywhere).

---

## Links

- `docs/dashboards/phase0/A-roles-access.md` — roles, authority chain, 12 mismatches
- `docs/dashboards/phase0/B-modules-data.md` — modules, chains, forecastability, indexes
- `docs/dashboards/phase0/C-approvals.md` — approval types, stuck risks, notifications, readiness
- `docs/dashboards/phase0/D-dashboard-dss.md` — dashboards, KPIs, forecasts, DSS behaviors
- `docs/dashboards/phase0/E-frontend.md` — deps, widgets, tokens, gaps
- `docs/dashboards/phase0/F-realtime-perf.md` — events, cache, query risks, test constraints
- `docs/dashboards/FINDINGS.md` — bug/gap log (IDs, severity, evidence, status)
- `docs/dashboards/PROGRESS.md` — single source of truth / resume pointer
