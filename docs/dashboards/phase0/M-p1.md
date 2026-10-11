# Phase 1 Dashboard/DSS Spec — P1 Roles (6)

Branch `docs/dss-discovery-phase0`. Part-author file: covers `system_admin`, `hr_officer`, `finance_officer`, `vice_president`, `customer_service_officer`, `sales_officer`. Companion files cover the rest. Conventions shared by all roles are stated once in §0; per-role sections list only deltas + full item tables.
Trace key: (00§X) = `00-discovery.md` §X; (A/B/C/D/E/F §Y) = phase0 section files. Every bullet cites at least one.
Spot-check rule applied: permission slugs from `RolePermissionSeeder.php:501-988` (A§1), KPI targets from `KpiDefinitionSeeder.php:15-25`, KPI gates from `KpiSnapshotService.php:41-48`, widget `link_path` from `DashboardWidgetSeeder.php:49-104`, SPA paths verified against `spa/src/routes/*.tsx`. Anything not so verifiable is marked **UNVERIFIED**. Anything not computable from existing tables is marked **data not available: reason**.

## §0. Shared contracts (all 6 roles)

- **Auth/guard stack:** `AuthGuard → ModuleGuard → PermissionGuard`; backend `CheckPermission` 403s independently; row scopes are per-module policy code, never permission strings (A§0, 00§A). No Phase 1 surface adds a role-name branch: candidacy = permission, rarity counted live from `role_permissions` (A§0, 00§A).
- **KPI grain:** snapshots are monthly, computed 2nd @ 03:00, read one month behind by design; daily/weekly sparklines/deltas query source tables, never stretched snapshots (D§2, B-G3, 00§D). Targets/directions: oee 85/75↑ · dppm 500/750↓ · FPY 98/95↑ · OTD 95/90↑ · supplier-quality 90/80↑ · attendance 96/93↑ · ar-aging-60d 5/8↓ · budget-util 90/80↑ · ncr-closure-days 5/8↓ · inv-turnover 6/4↑ · wo-completion 95/90↑ (`KpiDefinitionSeeder.php:15-25`, D§2). KPI-gate map (`KpiSnapshotService.php:41-48`): production→`production.dashboard.view` · quality→`dashboard.quality.view` · supply_chain→`dashboard.ppc.view` · purchasing→`dashboard.purchasing.view` · attendance→`dashboard.hr.view` · accounting→`accounting.dashboard.view` · inventory→`dashboard.warehouse.view`.
- **Finance dual-slug (M1):** page/catalog/dispatch answer to `dashboard.accounting.view`, data + `kpi.*`/`finance.*` widgets answer to `accounting.dashboard.view`; every finance surface picks deliberately (A§M1, 00§blocker-4).
- **Board-blind rule:** approval-center cards exist only for the 8 live ledger kinds; `payroll` kind is dead (never any rows) and all 14 custom single-step types have no ledger/board card/sweep — they need per-type card sourcing (C§2.9/§4/§5, 00§blocker-1). No generic approve endpoint: each inline button calls its module endpoint; remarks required on every reject (C§5).
- **Notification darkness:** 7+ types fire nothing on submit/decide; mid-chain handoffs missing even on ledger types (C§3, 00§blocker-2). Inboxes poll; nothing assumes a push arrived.
- **Handoff-status dimension:** `manual_required`/`Generated`/`NotRequired` backlogs are first-class on every funnel; broken links read as zero volume otherwise — delivery→invoice NULL-invoice valid (F2), pending-QC GRNs w/o inspection rows (F3), completed WOs w/o outgoing inspection (F4) (B§chains/F1–F12, 00§blocker-9).
- **Frontend:** 30 reusables (`AreaTrend BarComparison DonutBreakdown SparkLine OeeGaugeChain DowntimePareto` + shell/grid/live/filter/panels); CSS 12-col grid, no drag/resize; tile "Open →" = server `link_path`; refresh 60s bespoke / 30s fast / forecasts manual-only; shell-owned skeleton/error/empty/stale + per-cell boundaries; no dashboard/chart export (E§2, 00§E). New charts reuse these primitives; no new chart lib (E§gaps).
- **Perf:** widget-data + rich payloads have no cache (every 30–60s poll hits transactional tables); add cache layer before DSS tiles; index debt first on returns/budgets/WO-operations/PR-PO dates/delivery actuals (F§R15/B§4, 00§blockers-3/7). Respect `0NNN_` vs `2026_*` migration ordering.
- **Scope cuts honored:** activity feeds only on SO/PO/WO/NCR; no customizable dashboards, no import center, no per-shot mold depreciation (CLAUDE.md; A§5.9).

---

## R1. `system_admin` — System Administrator · page EXISTS (`/dashboard/admin`)

(A§1.1, 00§A; catalog `DashboardCatalog.php:33-47`; dispatch `dashboard.admin.view`, 1 holder.)

### 1. Persona & decisions
IT superuser, wildcard `*` (A§1.1). Top step of NO workflow; cannot award RFQs (slug carve-out, `RequestForQuoteAccessPolicy.php:50-53`); payroll maker-checker still applies absent override grant (A§1.1). **Daily:** auth/session anomalies, queue/outbox health, approval SLA sweep output, badge-fanout load. **Weekly:** permission-override expiries, delegation coverage, index/cache hotspots (F§R13–R15), forecast-accuracy (MAPE/bias) review. **Monthly:** KPI-snapshot compute success (per-definition isolation), role-layout drift (`WidgetSeedIntegrityTest`), stale-doc audit (SCHEMA/CLAUDE counts already stale — B§, 00§B).

### 2. KPI cards (8; all gates pass via wildcard, A§1.1)
| Card | Value | Delta / sparkline | Target | Click-through (verified `link_path` / route) |
|---|---|---|---|---|
| Attendance rate | snapshot + live `attendances`补 | MoM; daily source trend (D§2) | 96/93↑ | `/hr/attendance` |
| AR >60d | `computeArAging60d` | MoM | 5/8↓ | `/accounting/ar-aging` |
| Budget utilization | `computeBudgetUtilization` (FY figure, month ignored — D§2 calc gap) | MoM | 90/80↑ | `/budgeting/budget-vs-actual` |
| OTD | `computeOnTimeDelivery` | MoM | 95/90↑ | `/supply-chain/deliveries` |
| DPPM | `computeDppm` | MoM | 500/750↓ | `/dashboard/quality` (UNVERIFIED as KPI anchor; widget link is `/quality/dashboard`) |
| WO completion | `computeWoCompletionRate` (can exceed 100% — D§2 gap) | MoM | 95/90↑ | `/production/work-orders` |
| NCR closure days | `computeNcrClosureDays` | MoM | 5/8↓ | `/quality/ncrs` |
| OEE | `computeOee` (ignores shifts/holidays/downtime calendars — D§2 gap) | MoM | 85/75↑ | `/production/oee` |
Sparklines: monthly snapshots; sub-month = source queries (D§2).

### 3. Graph analytics (6)
| Chart (primitive) | Purpose → decision |
|---|---|
| Chain stage breakdown (Donut, `chain.stage_breakdown` → `/chains`) | volume per chain stage incl. `manual_required` (00§blocker-9) → where to unblock (D§4.38 bottleneck hourly) |
| WO status mix (Bar, `production.wo_breakdown` → `/production/work-orders`) | WIP vs overdue → re-plan or escalate (D§alert-5) |
| QC defect Pareto (Bar, `qc.pareto` → `/quality/dashboard`) | top defect codes → CAPA focus (D§17/38) |
| AR aging buckets (Bar, `finance.ar_aging` → `/accounting/invoices`) | 30d/60d tiers (D§alert-8, §31 dunning) → collector priority |
| Budget vs actual by dept (Bar → `/budgeting/budget-vs-actual`) | warn/block/off 0.80/0.95/1.00/1.20 (D§28) → enforcement tuning |
| Approval SLA burndown (AreaTrend; ledger `approval_records` + 24h/48h sweep — C§2/§5) | stall hotspots per kind → delegation/nudge; payroll+custom excluded (board-blind, C§5) |

### 4. Forecast widgets (7-panel set, D§1.3; all gates pass by wildcard)
Headcount (`forecast.headcount` → `/dashboard/hr`) · revenue (`forecast.revenue` → `/dashboard/finance`) · defect rate (`→/dashboard/quality`) · supplier risk (`forecasting.view`) · cash flow (`→/forecasting/demand`) · capacity (`→/production/schedule`) · attendance (`→/hr/attendance`). Caveats: 12-mo history / 3-mo horizon MA+seasonal+regression (D§1.3); capacity/attendance panels have no history (trend ≈ flat arithmetic) and capacity-90% + attendance-8%/15% are hardcoded consts, not settings (D§doc-gaps); demand actuals late-filled — only NOT-NULL rows comparable (B-F7).

### 5. Approval inbox & quick actions
Acts on: **none by design** — top step of no seeded workflow (A§1.1/§3). Inbox = read-only cross-chain worklist (`approvals.pending` → `/approvals`, `ApprovalSourceScope::visibleIds()` per A§5.8, never `userCanView`). Quick actions: manage delegations (`/approvals/delegations`, M8 unguarded-SPA/service-scoped-backend — A§M8), user overrides (`/admin/users`, `admin.users.manage_permissions` + `RequireSystemAdmin` — A§3), role compare (`/admin/roles/compare`). **Board-blind flag:** payroll + 14 custom types show no cards for anyone (C§5, 00§blocker-1).

### 6. Alerts / exceptions
Alert-engine 1–13 (15-min, 24h dedup — D§1–13): stock critical/low, no-supplier, breakdown, mold 80/95%, WO overdue, MRP conflict, OEE-quality, AR 30d/60d, AP-due-3d, scrap 5%, scheduler-stall. Escalations 14–19 (approval 24h/48h 6-hourly; NCR 15-min tiers; 8D; CAPA 30d/14d; Action Center 24h default; doc-review 7d/PPAP/calibration/training sweeps — D§14–19). Badge severity >20 = danger; digest 07:05 (D§cross-cutting). **Gap:** `ApprovalRecord` has no audit trait — step decisions lack IP/user-agent rows (C§3, 00§blocker-6).

### 7. DSS recommendations (from D§5 opportunities + D§4 behaviors)
1. Recommended supplier + award recommendation (#1/#6) — admin views scoring weights (0.25/0.35/0.10/0.15/0.15, tiers A–D, D§26), cannot award (carve-out). 2. EOQ/coverage on PR lines (#2). 3. WO release earliest-feasible-date per machine; Gantt display-only today (#3). 4. Forecast method auto-select/blend per product (#12) with MAPE/bias evidence (D§1.3).

### 8. Activity feed
`audit_logs` across 136 models (stock tables excluded — B-F11); parents carry `HasAuditLog`; mid-chain approval steps absent (C§3 gap above). Feed = global audit stream (`/admin/audit-logs`, `/admin/audit-logs/entity`, `/admin/activity` — routes verified in `dashboardRoutes.tsx:109`/`adminRoutes.tsx:119,136`). Login/auth + financial events carry IP/user-agent (CLAUDE.md security).

### 9. Shortcuts (all route-verified)
`/dashboard/admin` · `/approvals` · `/approvals/delegations` · `/action-center` · `/notifications` · `/alerts` · `/admin/users` · `/admin/roles` · `/admin/roles/compare` · `/admin/settings` · `/admin/audit-logs` · `/admin/sessions` · `/admin/operations-health`.

### 10. Filters
Global: date range (promote `PeriodControl+PeriodNote` into shell header per E§gaps-3) · module picker (`dashboard_widgets.module` grouping — 00§dashboards) · severity (badge>20 rule) · role/user (override/delegation scope) · chain (1/2/3). Backend `rangeBounds()` needed for shift/payroll-cutoff presets (E§gaps-3).

---

## R2. `hr_officer` — HR Officer · page EXISTS (`/dashboard/hr`)

(A§1.2; `dashboardRoutes.tsx:58-59`; maker-not-approver on payroll, `RolePermissionSeeder.php:538-541`.)

### 1. Persona & decisions
HR/attendance/leave/loans operator; payroll maker (create/compute, never approver) (A§1.2, 00§A). **Daily:** absences/lates (DTR), on-leave-today coverage, OT queue, biometric import failures. **Weekly:** leave balances/forfeiture horizon, probation/training due, loan deduction files, payroll compute anomalies. **Monthly:** headcount movement, statutory export, 13th-month run, gov-table effectiveness (`payroll_date` selects tables — CLAUDE.md).

### 2. KPI cards (7: 1 snapshot + 6 live; only `attendance_rate` snapshot-gated — `dashboard.hr.view` is the sole KPI slug held, 00§0-map)
| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Attendance rate | snapshot `computeAttendanceRate` | MoM; daily `attendances` trend | 96/93↑ | `/hr/attendance` |
| Headcount by dept (live) | `hr.headcount` (`hr.employees.view` ✓) | WoW hires−separations (`employees` OVERWRITE-current; history via `employment_history` — B§) | data not available: no headcount KPI definition | `/hr/employees` |
| On leave today (live) | `hr.on_leave_today` | vs 4-wk avg | data not available: no leave KPI definition (balances OVERWRITE-per-year — B§) | `/hr/leaves` |
| Team DTR today (live) | `hr.team_dtr_today` (`attendance.view` ✓) | day-over-day present/late cuts | data not available: no DTR KPI definition | `/hr/attendance` |
| Probation alerts (live) | `hr.probation_alerts` | WoW | data not available: no KPI definition | `/hr/employees` |
| Upcoming payroll (live) | `payroll.upcoming` (`payroll.periods.view` ✓) | per-cutoff scope check (scope_* ANDed; cycle-claim UQ — CLAUDE.md) | data not available: payroll has no KPI definition | `/payroll/periods` |
| Outstanding loans (live) | `loans.outstanding` (company-wide operator — A§1.2) | MoM ledger (`loan_payments` LEDGER — B§) | data not available: zero-interest cap = 1-mo salary policy, not a KPI | `/hr/loans` |
Blocked snapshots (gate not held → stripped at render, D§2): oee/dppm/FPY/OTD/supplier-quality/AR/budget/NCR/inv-turnover/WO-completion — **data not available: `KpiSnapshotService` gate unheld**.

### 3. Graph analytics (7)
| Chart | Purpose → decision |
|---|---|
| AreaTrend attendance rate by dept/week (`attendances` OVERWRITE-per-day; intra-day = `attendance_punches` — B§) | spot dept slippage → coaching / shift review |
| BarComparison headcount movement (hires vs separations; `employment_history` + `employee_salary_history` — B§) | net growth → req planning |
| Donut leave-type mix + forfeiture horizon (leave HR-approval stamps `attendance_snapshot` — B-chain-3) | burn-down expiring balances → approve/deny leave |
| Table DTR exceptions (late/undertime/absent; manual rows protected — B-chain-3) | daily corrections → recompute DTR |
| Bar OT hours by employee/dept (OT auto-detect partial-UQ; min-30m/max-4h policy — B-chain-3/CLAUDE.md) | authorize OT vs hire (opp. #4) |
| Table loan aging + deduction load (max 1-mo salary; 1 loan + 1 CA — CLAUDE.md) | deduction capacity → approve/wait |
| Bar training completion vs due (training-30/14/7d sweeps — D§19) | schedule sessions |

### 4. Forecast widgets
- Attendance forecast ✅ (`attendance.view` gate — `HrDashboardService.php:71`; OVERWRITE-per-day source + punches; hardcoded 8%/15% consts — D§doc-gaps). → `/hr/attendance`.
- Headcount forecast ✅ (`hr.employees.view` gate — `DashboardWidgetSeeder.php:259`; history tables thin for new Hires — B§). → `/dashboard/hr`.
- Revenue / cash-flow / capacity / defect-rate / supplier-risk — **data not available: gates unheld** (`accounting.dashboard.view` / `forecasting.view` / `quality.view` all absent from §1.2 grants).

### 5. Approval inbox & quick actions (act-only; `ApprovalSourceScope::visibleIds()` — A§5.8)
| Type | Step & gate | Quick action → endpoint family |
|---|---|---|
| Leave | HR arm (step 2 of `department_head → hr_officer`; separate arms — C§1) | approve/reject on HR arm only; dept arm invisible (A§1.2 view-all tier note) → `/hr/leaves/:id` |
| Company loan | step 2 (`+hr_officer`; C§1; row-scoped decide — `LoanAccessPolicy`) | verify employment/eligibility → module act endpoint → `/hr/loans/:id` |
| Separation clearance | step 5 HR (`WorkflowSeeder.php:153-159`) | sign checklist → `/hr/separations/:id` |
| Payroll period | **cannot act** — no `periods.approve`; maker only (A§1.2); **board `payroll` kind dead, no notification fires** (C§1a/§5.1, stuck-risk-5) → create/compute only → `/payroll/periods` |
| OT requests | full-`attendance` module holder (A§1.2); custom single-step → **Action-ready, discovery-blind** (C§5: per-type card sourcing needed) | decide → `/hr/attendance/overtime/:id` |
| Payroll adjustment | `payroll.adjustments.view/create` held (A§1.2); custom single-step, no ledger/sweep (C§2) | create/act → `/payroll/adjustments` (approver UNVERIFIED — mark) |
**Board-blind flag:** payroll-period + OT + payroll-adjustment cards never appear on `/approvals` (C§5).

### 6. Alerts / exceptions
DTR missing-punch/import-failure · leave-balance forfeiture horizon (D§35) · probation horizon (D§35) · training 30/14/7d (D§19) · payroll anomaly flags block finalize (net-change 0.30, OT 80h, deduction 0.50 — D§27) · loan step-1 stall when borrower dept has no head (C§stuck-3; HR sees it at step 2 queue age) · session-timeout 30-min (non-employee — A§0). Present notifications: leave submit/arms/decide, loan submit + final decide, OT submit/decide (C§3); **missing: loan step transitions, payroll waiting-approval** (C§3).

### 7. DSS recommendations
1. OT/hiring recommendation: hours shortfall → authorize N OT-hours vs open req (opp. #4) from OT-hours + DTR-gap series. 2. Leave-forfeiture nudges before year-end job runs (D§35). 3. Payroll-anomaly pre-check on compute (D§27 thresholds) — unblocks finalize. 4. Probation/training slot planner from horizon sweeps (D§19/35).

### 8. Activity feed
Leave arms/decide + loan submit/final + OT submit/decide (C§3 present-events) · payroll compute/approve rows (explicit payroll-approve audit row — C§3) · `attendance_snapshot` stamps + balance mutations (B-chain-3). Intermediate approval steps leave no `audit_logs` row (C§3 gap). Surfaces: `/hr/leaves/:id`, `/hr/loans/:id`, `/payroll/periods/:id`.

### 9. Shortcuts (route-verified)
`/dashboard/hr` · `/hr/employees` · `/hr/attendance` · `/hr/attendance/import` · `/hr/attendance/shifts` · `/hr/attendance/holidays` · `/hr/attendance/overtime` · `/hr/leaves` · `/hr/leaves/calendar` · `/hr/loans` · `/hr/separations` · `/payroll/periods` · `/payroll/adjustments` · `/approvals` · `/action-center` · `/notifications`.

### 10. Filters
Department (dept ladder via `DepartmentScope`/`LoanAccessPolicy` — A§1.2/1.15) · date/cutoff (half derived from dates, never chosen — CLAUDE.md) · employment-type/pay-type scope (period `scope_*` — CLAUDE.md) · leave type · loan type (CA vs company) · shift · payroll status-machine (7 statuses — B§).

---

## R3. `finance_officer` — Finance Officer · page EXISTS (`/dashboard/finance`)

(A§1.3; `dashboardRoutes.tsx:64-67`; M1 dual-slug holder of both slugs.)

### 1. Persona & decisions
Accounting/budgeting owner; payroll checker; PR/PO/bill/asset step approver (A§1.3, 00§A). **Daily:** cash position, AP-due-this-week, AR-30d breaches, budget-ack queue on PR/PO. **Weekly:** AR/AP aging movement, unposted/draft JEs, RFQ quote review (no sourcing run), bill-payment ≥₱50k gate. **Monthly:** statements, budget-utilization, payroll approve/finalize/disburse + bank file, asset-disposal step 1.

### 2. KPI cards (9: 2 snapshots + 7 live; snapshot gates held: `accounting.dashboard.view` only)
| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| AR >60d | snapshot | MoM (current-balance snapshot, not historical aging — D§2 gap) | 5/8↓ | `/accounting/ar-aging` |
| Budget utilization | snapshot (FY figure, month ignored — D§2 gap) | MoM | 90/80↑ | `/budgeting/budget-vs-actual` |
| Cash balance (live) | `cash_balance` (`accounting.dashboard.view` — `FinanceDashboardService.php:77`) | daily (`LOWER()`+OR predicate risk R6 — F§) | data not available: no cash KPI definition | `/accounting/balance-sheet` |
| Revenue MTD (live) | `revenue_mtd` | vs prior MTD | data not available: no revenue KPI definition | `/accounting/income-statement` |
| AR outstanding (live) | `ar_outstanding` (`invoices.view` ✓) | WoW | data not available | `/accounting/invoices` |
| AP outstanding (live) | `ap_outstanding` (`bills.view` ✓) | WoW | data not available | `/accounting/bills` |
| Draft/unposted JEs (live) | `unposted_jes` (`journal.view` implied by full accounting — A§1.3; slug UNVERIFIED verbatim) | WoW | data not available | `/accounting/journal-entries` |
| AP due this week (live) | `ap_due_this_week` (`bills.view` ✓) | WoW | AP-due-soon 3d alert (D§9) | `/accounting/bills` |
| Pending credit notes (live) | `pending_credit_notes` (`credit_notes.view`; lines table pkey-only — B§4 index gap) | WoW | data not available | `/accounting/credit-notes` |
Stripped snapshots (gates unheld): oee/FPY/WO (production), dppm/NCR (quality), OTD (ppc), supplier-quality (purchasing), attendance (hr), inv-turnover (warehouse) — **data not available: gate unheld** (00§0-map).

### 3. Graph analytics (8)
| Chart | Purpose → decision |
|---|---|
| AreaTrend cash position (R6-index risk; delivery→invoice lossy — B-F2) | pay vs hold → disbursement timing |
| Bar AR aging buckets + top-overdue customers (`ar_aging_summary`, `top_overdue_ar` — `FinanceDashboardService.php:82-84`) | dunning tiers 7/15/30d (D§31) → collector worklist (opp. #11) |
| Bar AP aging + due-this-week (`bills.view` ✓) | avoid late fees → payment batching |
| Bar budget-util by dept/budget (budgets table pkey-only — B§4; warn/block/off 0.80/0.95/1.00/1.20 — D§28) | over-ceiling → transfer proposal (opp. #10) |
| Table PR→PO conversion + budget-ack states (RFQ-sourced skip chain; money-once-on-PO — CLAUDE.md) | acknowledge-and-approve vs return |
| Bar supplier score trend (0.25/0.35/0.10/0.15/0.15, neutral-50 — D§26; `purchasing.suppliers.performance.view` ✓) | preferred-supplier maintenance |
| Table unposted JEs + recent JEs (`recent_journal_entries` — `FinanceDashboardService.php:93`) | close checklist |
| Donut bill-payment gate (≥₱50k VP-single else auto-post — C§1 drift note) | what needs VP vs self-posts |

### 4. Forecast widgets
- Revenue ✅ (`accounting.dashboard.view` — `FinanceDashboardService.php:99`). → `/dashboard/finance`.
- Cash-flow ✅ (`forecasting.view` held — A§1.3; `FinanceDashboardService.php:102`). → `/forecasting/demand` (route-verified `advancedRoutes.tsx:33`).
- Capacity ✅ gate-wise (`forecasting.view`; no-history flat-arithmetic caveat — D§doc-gaps). → `/production/schedule` (link per `DashboardWidgetSeeder.php:94`; page guard UNVERIFIED for this role).
- Supplier-risk ✅ (`forecasting.view`; `PurchasingDashboardService` supplier_risk). → `/purchasing/suppliers/performance`.
- Headcount / attendance / defect-rate — **data not available: gates unheld** (`hr.employees.view`, `attendance.view`, `quality.view` absent from §1.3).

### 5. Approval inbox & quick actions (act-only)
| Type | Step | Quick action |
|---|---|---|
| Purchase request | step 1 Finance (money-only; RFQ-sourced skips — CLAUDE.md/C§1); budget-ack precondition surfaced (C§5) | approve/reject w/ remarks → PR detail `/purchasing/purchase-requests/:id` |
| Purchase order | step 1 Finance (buyer never a step; +vendor-SoD/PPAP/budget gates — C§5) | approve/reject → `/purchasing/purchase-orders/:id` |
| Bill payment | **cannot act** — single VP step ≥₱50k else auto-posts (C§1 drift note); **no submit/decide notification exists** (C§3) → monitor-only queue |
| Asset disposal | step 1 Reviewed-by (JE posts on final approve in-tx — C§1) | review → asset detail `/assets/:id` |
| Return (finance-only) | single Finance approval (`finance_only_return_request` — `WorkflowSeeder.php:180-185`) | approve → `/return-management/:id` |
| Payroll period | checker: approve / request_correction / finalize / bank_file / disburse (A§1.3); **custom maker-checker, board-blind** (C§1a) | multi-button panel → `/payroll/periods/:id` (finalize emits GL-post; void reverses JE — B-chain-3) |
| Budget / budget transfer | custom single-step + different-role rule; `budgeting.approve` ✓ (A§1.3); no ledger/sweep (C§2) | act → `/budgeting/:id`, `/budgeting/transfers` |
| Loan write-off | request+approve held (A§1.3); custom single-step (C§2) | act → `/hr/loans/:id` (route family verified; write-off sub-action UNVERIFIED) |
| Cost review / stock adjustment | `inventory.costs.approve` + `inventory.adjust.approve` ✓ (A§1.3); custom (C§2) | approve → `/crm/price-agreements/cost-reviews`, adjustment list (adjust-list path UNVERIFIED) |
| Invoice prebill | custom (C§2); actor UNVERIFIED — inbox surfaces queue, actability marked UNVERIFIED |

### 6. Alerts / exceptions
AR 30d/60d + AP-due-3d (D§8–9) · budget warn/block/off (D§28) · payroll anomalies block finalize (D§27) · dunning tiers (D§31) · period auto-relock 48h (D§cross-cutting) · PR/PO Finance→VP handoff has no notification (C§3 gap) — queue-age column substitutes · bill-payment stall when VP seat empty (C§stuck-2; finance sees submitted-but-undecided age).

### 7. DSS recommendations
1. Cash-commit scenarios at approval time (opp. #5): project cash after approving this PO/bill batch. 2. Budget-transfer proposals (opp. #10) for warn/breach lines. 3. Collector worklist by recoverability (opp. #11) from AR-aging + dunning tiers. 4. Award recommendation + price-renewal suggestion (opp. #6) — finance reviews quotes without running sourcing (A§1.3 desc).

### 8. Activity feed
JE post/void + PR/PO step decisions (ledger trail only; `ApprovalRecord` sans audit trait — C§3) · collection JE + 1:1 OR (B-chain-1) · bill 3-way-match snapshot + payment post/void-replacement chain (B-chain-2) · payroll finalize GL-post request + bank proofs (B-chain-3). Surfaces: JE/bill/invoice detail pages + `/payroll/periods/:id`.

### 9. Shortcuts (route-verified)
`/dashboard/finance` · `/accounting/journal-entries` · `/accounting/bills` · `/accounting/invoices` · `/accounting/ar-aging` · `/accounting/ap-aging` · `/accounting/income-statement` · `/accounting/balance-sheet` · `/accounting/credit-notes` · `/budgeting` · `/budgeting/transfers` · `/budgeting/budget-vs-actual` · `/purchasing/purchase-requests` · `/purchasing/purchase-orders` · `/purchasing/suppliers/performance` · `/payroll/periods` · `/approvals` · `/action-center` · `/chains` · `/chains/recovery`.

### 10. Filters
Fiscal year + accounting period (`accounting.periods.view` ✓ — `FinanceDashboardService.php:92`) · department/cost-center (budget lines) · vendor/customer · aging bucket · JE status (draft/posted/void) · enforcement mode warn/block/off (`budgeting.enforcement_mode` — `SettingsSeeder.php:432`) · date range (E§gaps-3 `PeriodControl`).

---

## R4. `vice_president` — Vice President · **NEW PAGE REQUIRED** → proposed `/dashboard/vp`

No VP-specific page exists by design ("reads what it signs"); lands on `/dashboard/plant-manager` via shared `dashboard.plant_manager.view` (A§1.4/§5.3, 00§A). Executive vs operations readings are not separated — this page separates them. Wiring: add `vp` catalog key + `dashboard.vp.view` (held by VP only), rarity-sort dispatch (A§0); URL follows the `/dashboard/<key>` catalog convention (existing 8 keys — `DashboardCatalog.php:33-47`).

### 1. Persona & decisions
Executive terminal approver on all money chains (A§1.4, 00§A). **Daily:** ≥₱50k PR/PO/bill queue, SLA-aging (>24h remind / >48h escalate — C§5). **Weekly:** cash-commit exposure of pending approvals, loan/salary/asset-disposal queue, complaint trend. **Monthly:** budget-utilization vs FY, statements review, supplier-quality posture. No escalation superior — VP stall is terminal (C§stuck-2, 00§blocker-5); auto-resolve ships disabled (enabling = silent spend/kill — 00§blocker-5).

### 2. KPI cards (7 live executive cards; **all 11 snapshots data-not-available: VP holds none of the 7 `MODULE_PERMISSIONS` slugs** — verified §1.4 grants vs 00§0-map)
| Card | Value (source the VP can read) | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Pending money-approvals ≥₱50k | open PR/PO/bill-payment rows awaiting VP (`po.view_all` + `purchasing.view` ✓) | WoW queue age | data not available: no approval-SLA KPI definition (sweep is notify-only — C§5) | `/approvals` |
| Cash exposure of queue | sum of awaiting-VP amounts | WoW | data not available | `/accounting/balance-sheet` |
| AR outstanding | `invoices.view` ✓ | WoW | data not available (snapshot gate unheld) | `/accounting/invoices` |
| AP outstanding | `bills.view` ✓ | WoW | data not available | `/accounting/bills` |
| Budget utilization (dept rollup) | `budgeting.view/approve` ✓ live rollup (pkey-only table — B§4) | MoM | 90 (ref; snapshot gate unheld) | `/budgeting/budget-vs-actual` |
| Open complaints | `crm.complaints.view` ✓ live count | WoW | data not available | `/crm/complaints` |
| Bottleneck count | `dashboard.view_bottlenecks` ✓ (hourly chain-bottleneck job — D§38) | WoW | data not available | `/chains` |

### 3. Graph analytics (6)
| Chart | Purpose → decision |
|---|---|
| Bar approvals awaiting VP by kind/age (ledger `approval_records`; 24h/48h sweep notify-only — C§5) | approve oldest-money-first; spot sole-seat stall (C§stuck-2) |
| AreaTrend cash-commit if queue approved (opp. #5) | approve vs defer tranches |
| Bar budget-util vs FY by dept (warn/block/off bands — D§28) | approve transfer or hold spend |
| Table supplier score + open RFQ awards (`rfq.view` ✓; money via `RfqCommercialCalculator` — 00§shared-helpers) | award sign-off (buyer runs sourcing; VP signs — A§1.4) |
| Bar complaint volume + 8D aging (8D 15-min sweep — D§16) | intervene on SLA-burn |
| Donut chain-stage + `manual_required` backlog (bottleneck API ✓; page/API diverge M10 noted — A§M10) | unblock handoffs |

### 4. Forecast widgets
All 7 panels **data not available: gates unheld** — revenue (`accounting.dashboard.view` ✗) · cash-flow/capacity/supplier-risk (`forecasting.view` ✗) · attendance (`attendance.view` ✗) · headcount (`hr.employees.view` ✗) · defect-rate (`quality.view` ✗) (gates per §0/F-service greps; §1.4 grants). Interim: live 12-wk spend/commit trend from PR/PO/bill sources (TRENDABLE-STATUS-MACHINE via `audit_logs` join — B§rule-of-thumb) until a `forecasting.view` grant decision is made (grant = access-model change, flagged not assumed).

### 5. Approval inbox & quick actions (terminal steps only — `WorkflowSeeder.php:32-103,142-149`)
PR ≥₱50k (step 2) · PO ≥₱50k (step 2) · bill-payment ≥₱50k (single) · cash-advance step 3 · company-loan step 4 (≥₱50k) · salary-adjustment step 2 · asset-disposal step 2. Each: amount + budget-ack/vendor-SoD/PPAP preconditions surfaced (C§5), approve/reject w/ remarks → module endpoints. Below-threshold rows skip VP (₱50k skip — C§engine). **Board-blind flag:** none of VP's act types is affected (all ledger-wired ✓), but VP also sees NO payroll/custom cards — correct, VP is step of none there (C§1).

### 6. Alerts / exceptions
SLA 24h reminders + 48h escalations (6-hourly, ledger rows only — C§5) · NCR 15-min tiers + 8D + CAPA 30d/14d (D§15–17; VP reads complaints) · AR 30d/60d + AP-due-3d (D§8–9) · self-approval refusals on own requests (stall-never-spend mode — C§stuck-4/6) · VP-seat-empty = permanent stall for above-threshold bills (C§stuck-2) → delegation coverage check (`/approvals/delegations`).

### 7. DSS recommendations
1. Cash-commit scenarios at approval time (opp. #5) — the executive tile. 2. Award recommendation: score+price+lead+MOQ blend (opp. #1/#6) on open comparisons → `/purchasing/rfqs/:id/compare`. 3. SO commit-date feasibility + expedite ranking (opp. #9) from bottleneck data. 4. Budget-transfer proposals (opp. #10) for breach lines awaiting spend approval.

### 8. Activity feed
VP step decisions (ledger-only trail; no `audit_logs` row per step — C§3 gap) · PR chain broadcasts + PO submit/full-approve notices (C§3 present) · salary/asset request notices **missing entirely** (C§3) → feed backfills from ledger polling. Surfaces: `/approvals`, PR/PO detail pages.

### 9. Shortcuts (route-verified)
`/dashboard/plant-manager` (current landing) · `/dashboard/vp` (proposed) · `/approvals` · `/approvals/delegations` · `/action-center` · `/chains` · `/purchasing/purchase-requests` · `/purchasing/purchase-orders` · `/purchasing/rfqs/:id/compare` · `/accounting/bills` · `/accounting/invoices` · `/accounting/income-statement` · `/accounting/balance-sheet` · `/budgeting` · `/budgeting/budget-vs-actual` · `/crm/complaints` · `/hr/employees` (salary-adjustment queue tab — A§1.4) · `/assets/:id`.

### 10. Filters
Amount band (≥₱50k terminal vs skipped) · kind (PR/PO/bill/loan/salary/asset) · SLA age (24h/48h) · department (cost-center on budgets) · date range · delegation-active toggle (A§3 `actsForRoles`).

---

## R5. `customer_service_officer` — Customer Service Officer · **NEW PAGE REQUIRED** → proposed `/dashboard/customer-service`

Lands on `/dashboard/default` (no catalog dashboard; no role-default layout rows — A§1.5/§5.2, 00§A). Generic widget home is the primary surface today (A§5.1). Wiring: add catalog key + `dashboard.customer_service.view`, seed role-default layout (A§5.2 gap), rarity dispatch (A§0). Path follows `/dashboard/<key>` convention.

### 1. Persona & decisions
CRM complaints/inquiries/RMA lifecycle owner (A§1.5, 00§A). **Daily:** new complaints/inquiries, RMA intake + QC-defect notices, return-receive/inspect handoffs (owned by warehouse/QC — A§1.5). **Weekly:** complaint SLA aging, 8D progress, CoC attach completeness on confirms. **Monthly:** complaint Pareto by product/defect, RMA cycle time, price-agreement renewal watch.

### 2. KPI cards (8 live; **all snapshots DNA: no KPI-module slug held** — §1.5 vs 00§0-map)
| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Open complaints | `crm.open_complaints` (`crm.view` ✓) live | WoW; daily from `complaints` created_at (TRENDABLE-STATUS-MACHINE — B§) | data not available: no complaint KPI definition | `/crm/complaints` |
| Complaint SLA-breach | age vs 8D tiers (15-min sweep — D§16) | WoW | data not available (8D SLA in sweeper, no KPI target) | `/crm/complaints` |
| Open inquiries | `crm.inquiries.view/manage` ✓ live | WoW | data not available | `/crm/inquiries` |
| Open returns | `rma.open_returns` (`return_management.view` ✓) | WoW (returns tables nearly unindexed — B-F12/F§: filter may be slow pre-index) | data not available | `/return-management` |
| Returns awaiting approval | `rma.pending_approval` live | WoW | data not available | `/return-management` |
| SOs awaiting confirm detail | `crm.sales_orders.view` ✓ open-SO count (8 indexed stage timestamps — B§) | WoW | data not available | `/crm/sales-orders` |
| Price offers pending | `price_agreements.view/manage/propose` ✓ | WoW | data not available | `/crm/price-agreements` |
| CoC coverage on confirms | confirms with CoC attached vs `manual_required` (B-F2/chain-1 CoC gate) | WoW | data not available: no CoC KPI definition | `/crm/sales-orders` |

### 3. Graph analytics (7)
| Chart | Purpose → decision |
|---|---|
| Donut open complaints by status/severity (`crm.open_complaints` breakdown → `/crm/complaints`) | triage order → assign/8D-open |
| Bar complaint Pareto by product/defect (NCR root-cause ranking input — opp. #7) | quality escalation → NCR/inspection loop (IATF touchpoint 4) |
| AreaTrend inquiry→complaint conversion (weekly, parent-timestamp join — B§ rule) | intervention timing |
| Table RMA pipeline by stage (request→receive→inspect→dispose/complete; receive/inspect owned by others — A§1.5) | chase handoff owners |
| Bar RMA cycle time by disposition (scrap/rework/use-as-is/return-to-supplier enum — CLAUDE.md) | policy tune |
| Table SO confirm readiness (delivery requires passed output-bound inspection + CoC — B-chain-1) | promise dates |
| Bar price-agreement expiry horizon (renewal suggestion — opp. #6) | renegotiate early |

### 4. Forecast widgets
All 7 DSS panels **data not available: gates unheld** (`forecasting.view`, `accounting.dashboard.view`, `quality.view`, `hr.employees.view`, `attendance.view` all absent — §1.5). Forecastable alternatives from TRENDABLE sources (B§): complaint-volume projection from `complaints` history (STATUS-MACHINE + `audit_logs`) — build as live trend (not MA/seasonal engine) until gate decision; demand-forecast panels remain PPC-owned (`/forecasting/demand` guard UNVERIFIED for this role — excluded from shortcuts).

### 5. Approval inbox & quick actions
Acts on: **none** — holds `return_management.view/manage/dispose/complete` but NOT `approve` (§1.5); return_request steps are `department_head → production_manager` + finance-only single (C§1) — CSO creates/manages lifecycle, approval by others. Return-case custom single-step actor UNVERIFIED (C§2) → queue surfaces at `/return-management/:id` with actability marked UNVERIFIED. Price propose (not approve — finance approves, A§1.3) → `/crm/price-agreements/requests/:id/offer`. Complaint 8D submit (reserved `8d_report` type NOT wired to `ApprovalService::submit()` — A§5.9) → lifecycle action, not approval. **Board-blind flag:** RMA/8D/price actions never appear on `/approvals` (custom/reserved, C§2/§5, A§5.9).

### 6. Alerts / exceptions
Portal RMA + QC-defect notices (C§3 present) · NCR 15-min tiers + 8D 15-min + CAPA (D§15–17) · return step-handoff notices **missing** (C§3) → age-based chase column · document-review 7d (D§19) for CoC/8D docs · Action Center 24h default (D§18) · returns index debt → tally slowness risk (B§4/F§).

### 7. DSS recommendations
1. NCR root-cause ranking (opp. #7) linked from complaint Pareto. 2. SO commit-date feasibility + expedite ranking (opp. #9) for promise-dating. 3. Collector-worklist assist: CSO holds `accounting.customers.view/manage` (A§1.5) → dunning-tier awareness (D§31) without collection posting. 4. Price-renewal suggestion (opp. #6) from expiry horizon.

### 8. Activity feed
Complaint/inquiry lifecycle + RMA `cases/lines/events` rows (B§ returns-10) · price-offer propose/approve trail · SO confirm + CoC attach + draft-invoice handoff (`Generated`/`ManualRequired`/`NotRequired` — B-chain-1) · activity feeds exist on SO (scope-cut list — §0). QC-inspect/receive steps appear as handoff events owned by other roles.

### 9. Shortcuts (route-verified)
`/dashboard/default` (current) · `/dashboard/customer-service` (proposed) · `/crm/complaints` · `/crm/complaints/new` · `/crm/inquiries` · `/crm/customers` · `/crm/products` · `/crm/price-agreements` · `/crm/price-agreements/requests` · `/crm/sales-orders` · `/return-management` · `/return-management/new` · `/return-management/cases` · `/approvals` · `/action-center` · `/notifications`.

### 10. Filters
Customer · product (finished-goods catalog in CRM — CLAUDE.md namespace gotcha) · complaint severity/status · RMA stage/disposition · date range · channel (portal vs direct; portal principals separate guards — A§0/§3) · CoC status (attached/`manual_required`).

---

## R6. `sales_officer` — Sales Officer · **NEW PAGE REQUIRED** → proposed `/dashboard/sales`

Lands on `/dashboard/default` (no catalog dashboard; no role-default layout rows — A§1.6/§5.2). Opens `/chains` but bottleneck widget 403s (M10 — A§M10, 00§A). Wiring: catalog key + `dashboard.sales.view`, role-default layout seed, rarity dispatch (A§0/§5.2).

### 1. Persona & decisions
SO full lifecycle + price propose (A§1.6, 00§A). No complaints/inquiries/RMA (A§1.6). **Daily:** open pipeline, confirm-ready SOs, commit-date feasibility. **Weekly:** conversion (quoted→confirmed), price-request outcomes, `manual_required` invoice backlog. **Monthly:** revenue vs target (target UNVERIFIED — no seeded sales target found), product mix, price-renewal watch.

### 2. KPI cards (7 live; **all snapshots DNA: no KPI-module slug held** — §1.6 vs 00§0-map)
| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Open SOs | `sales_orders.view` ✓ live by stage (8 indexed stage timestamps — B§) | WoW | data not available: no SO-volume KPI definition | `/crm/sales-orders` |
| Confirm-ready | MRP-planned + stock-available, minus failed-outgoing-QC re-plan owned by MRP (NCR-close creates no WO on SO-line — 00§shared-helpers `coversSalesOrderLine`) | WoW | data not available | `/crm/sales-orders` |
| Quoted→confirmed conversion | price-request → SO join (propose held — A§1.6) | MoM | data not available | `/crm/price-agreements/requests` |
| Avg commit-to-confirm lead time | stage-timestamp deltas (B§) | MoM | data not available | `/crm/sales-orders` |
| `manual_required` invoice backlog | confirms with NULL invoice (valid-lossy F2 — B§) | WoW | data not available (handoff dimension, not KPI) | `/crm/sales-orders` |
| Price requests pending offer | propose-queue age | WoW | data not available | `/crm/price-agreements/requests` |
| Delivered awaiting customer-confirm | delivery→confirm stage (CoC attached — B-chain-1) | WoW | data not available | `/supply-chain/deliveries` (read via `purchasing.view`? NO — sales lacks it; **route visible but data 403s → shortcut marked UNVERIFIED for this role**; use `/chains` tracker instead) |

### 3. Graph analytics (6)
| Chart | Purpose → decision |
|---|---|
| AreaTrend SO volume + confirm rate (weekly; lines dateless → join parent timestamps — B§ rule) | forecast input to PPC → commit-date promise (opp. #9) |
| Funnel quote→confirm→produce→deliver→invoice→collect (Chain-1 stages; F1 WO→SO unenforced → LEFT JOIN — B-F1) | find stage leak → expedite |
| Bar product mix by SO value (products in CRM — namespace gotcha) | push high-availability items |
| Table commit-date risk (bottleneck API 403s for this role — M10; uses SO-stage + MRP-plan data only) | re-promise early |
| Bar price-request win/loss (pricing-review daily + dispatch recovery — D§32) | re-price |
| Table `manual_required` backlog (F2 first-class — 00§blocker-9) | chase invoicing |

### 4. Forecast widgets
All 7 DSS panels **data not available: gates unheld** (no `forecasting.view` etc. — §1.6). Demand-forecast accuracy/MAPE + forecast→MRP advisory (D§1.3) are PPC-owned; sales consumes them via `/forecasting/demand` and `/forecasting/accuracy` only if granted (guard for this role UNVERIFIED — excluded from shortcuts). Interim: SO-volume run-rate projection from stage timestamps (TRENDABLE-STATUS-MACHINE — B§) as live trend, labeled non-DSS until method-blend engine covers it (opp. #12).

### 5. Approval inbox & quick actions
Acts on: **none** — SO confirm/cancel is lifecycle (holds `confirm/cancel` — A§1.6), not an approval-ledger step; price propose (approve = finance — A§1.3). Queue = own pending price-requests + draft SOs → `/crm/price-agreements/requests`, `/crm/sales-orders/create`. **Board-blind flag:** N/A by design — but sales must know confirm does NOT auto-invoice (`manual_required` path — B-chain-1/B-F2); the "waiting invoice" state has no approval card anywhere (C§5 custom `invoice prebill` discovery-blind).

### 6. Alerts / exceptions
Pricing-review daily (D§32) · chain-bottleneck hourly (D§38) — **page opens, bottleneck widget 403s** (M10 — A§M10) → page shows tracker without bottleneck data until M10 fixed · MRP schedule-conflict alerts (D§5) affect commit dates without notifying sales (notification gap class — C§3) → poll `/chains` · delivery-attempt events (attempts table — B§) for promise updates.

### 7. DSS recommendations
1. SO commit-date feasibility + expedite ranking (opp. #9) — the sales tile. 2. Price-renewal suggestion (opp. #6) from agreement horizons. 3. Demand-forecast visibility (read-only MAPE/bias + dispersion confidence — D§1.3) to promise against. 4. Order-quantity guidance (EOQ/coverage — opp. #2) translated as available-to-promise hints (UNVERIFIED as sales-visible — flagged).

### 8. Activity feed
SO lifecycle (confirm/cancel/stage advances; `SalesOrderConfirmed` tolerates newer state — 00§shared-helpers) · price propose/offer events · delivery→confirm + CoC attach (B-chain-1) · activity feeds exist on SO (§0 scope-cut list). Complaint/RMA events out of scope (not held — A§1.6).

### 9. Shortcuts (route-verified; M10-constrained)
`/dashboard/default` (current) · `/dashboard/sales` (proposed) · `/crm/sales-orders` · `/crm/sales-orders/create` · `/crm/products` · `/crm/customers` · `/crm/price-agreements` · `/crm/price-agreements/requests` · `/chains` (tracker opens; bottleneck data 403s — M10) · `/approvals` · `/action-center` · `/notifications`.

### 10. Filters
Customer · product · SO stage/status · date range · price-request status · commit-date risk band · invoice-handoff status (`Generated`/`ManualRequired`/`NotRequired` — B-chain-1).

---

## Cross-role notes for Phase 1 builders

1. **M10 fix precedes sales/VP chain widgets:** page gate (`crm.sales_orders.view`) vs API gate (`dashboard.view_bottlenecks`) diverge both ways — sales opens/403s, PPC calls/404s (A§M10, 00§A). Either align gates or grant `dashboard.view_bottlenecks` to sales_officer (access-model change — flagged).
2. **M1 pick per finance surface** (00§blocker-4); **board-blind card sourcing** for payroll + 14 custom types (00§blocker-1); **7+ notification gaps** mean inbox polling, not push (00§blocker-2); **returns/budget/WO-op indexes before aggregating** (00§blocker-3); **uncached widget-data needs a cache layer** (00§blocker-7); **KPI monthly grain** — sub-month from sources (00§blocker-8); **`manual_required` first-class** (00§blocker-9).
3. **Drift guards on any chain/seed touch:** `ApprovalChainRolePermissionDriftTest` (C§5) and `WidgetSeedIntegrityTest` (00§dashboards).
