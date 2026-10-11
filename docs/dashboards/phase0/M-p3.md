# Phase 1 Dashboard/DSS Spec — P3 Roles (5)

Branch `docs/dss-discovery-phase0`. Part-author file: covers `maintenance_supervisor`,
`impex_officer`, `department_head`, `employee`, `driver`. Companion files: `M-p1.md` (roles 1–6),
`M-p2.md` (roles 7–12), synthesis `../01-matrix.md`.

**§0 shared contracts live in `M-p1.md` §0** (auth/guard stack, KPI grain + targets + gate map,
finance dual-slug M1, board-blind rule, notification darkness, handoff-status dimension, frontend
reuse, perf, scope cuts) and are binding here. Trace key: (00§X) = `../00-discovery.md` §X;
(A/B/C/D/E/F §Y) = phase0 section files. Spot-checks re-verified against
`api/database/seeders/RolePermissionSeeder.php` (grants), `DashboardWidgetSeeder.php` (key →
permission → `link_path`), `DashboardRoleLayoutSeeder.php` (role defaults) and `spa/src/routes/*.tsx`
(paths). Unverified claims are marked **UNVERIFIED**.

## §0-P3. The shape of this group

All five roles land on **`/dashboard/default`** — a registered dashboard but a **generic widget grid**,
not a composed page (A§5.1/5.2, D§1.1). Three of them (`department_head`, `employee`, `driver`) have a
role-default widget set; `maintenance_supervisor` and `impex_officer` reuse the sets of their tech /
logistics siblings (`DashboardRoleLayoutSeeder.php:31-129` — the maintenance pair share an identical
list). None holds a `kpi.*` gate, so the KPI plane is empty for all five; every card below is either an
existing widget scalar or a **new** card that Phase 2 must serve.

Two structural decisions this group forces (both land in `01-matrix.md` §D):

- **D-P3-1:** a generic grid is the right answer for `department_head` (approvals-first, department-scoped)
  and `employee` (self-service), but the wrong one for `maintenance_supervisor`/`maintenance_tech`
  (shared operational surface — see `M-p2.md` R12 proposed `/dashboard/maintenance`) and
  `impex_officer` (logistics surface).
- **D-P3-2:** `driver` should land on the **existing** `/driver` PWA, not a widget grid. That needs a
  catalog/PWA-aware dispatch entry, not a new dashboard page.

---

## R13. `maintenance_supervisor` — Maintenance Supervisor · **NEW PAGE REQUIRED** → `/dashboard/maintenance`

(A§1.13, 00§A; no `dashboard.*.view`; identical default layout to `maintenance_tech`.)

### 1. Persona & decisions

Assigns and verifies maintenance work, owns PM schedules and windows (no `wo.complete` — a supervisor
verifies, a tech completes). **Daily:** unassigned MWOs, overdue PM windows, breakdown queue, assignment
watchdog hits. **Weekly:** verify backlog (work done, not signed off), repeat breakdowns, schedule
compliance. **Monthly:** MTTR/MTBF, PM-vs-corrective ratio, spare consumption, maintenance cost proxy
(labour hours + spares — no costing module, data not available).

### 2. KPI cards (6–8; shared page with R12, supervisor view adds the queue cards)

| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Unassigned MWOs (new) | MWOs in open status with NULL assignee | WoW | 0 | `/maintenance/work-orders` |
| **Awaiting my verify** (new) | completed-not-verified MWOs | WoW | — | `/maintenance/work-orders` |
| Overdue PM windows (new) | `maintenance_schedules` due < now | MoM | PM compliance ≥ 90% (target not seeded — data not available) | `/maintenance/schedules` |
| Open MWOs | `maintenance.open_wos` (`maintenance.view` ✓) | WoW | — | `/maintenance/work-orders` |
| Due schedules ≤ N days | `maintenance.due_schedules` | WoW | — | `/maintenance/schedules` |
| Machine status | `machine.status` (`mrp.machines.view` ✓) | WoW | breakdown = 0 | `/mrp/machines` |
| Assets under maintenance | `assets.under_maintenance` (`assets.view` ✓) | WoW | — | `/assets` |
| Pending my action | `approvals.pending` (self-scoped) | WoW | — | `/approvals` |

### 3. Graph analytics (5, shared with the tech page)

Same five as `M-p2.md` R12 §3 (downtime Pareto, repeat breakdowns, MWO aging, PM due/overdue timeline,
usage-vs-limits), plus **assignment load by tech** (bar) → balance workload. All reuse existing
primitives (`DowntimeParetoChart`, `BarComparison`) — no new chart dependency (E§2 gaps).

### 4. Forecast widgets

**data not available: no maintenance forecast series + no `forecasting.view` grant.** Phase 3
candidate: maintenance workload, downtime hours, spare depletion. Interim: 30-day rollups.

### 5. Approval inbox & quick actions

Acts on: **none** — assign/verify/schedule-manage are lifecycle actions, not approvals (A§1.13).
Action Center is the queue (critical 8h / default 72h SLAs, D§18). Board read-only.

### 6. Alerts / exceptions

`machine_breakdown` · mold 80/95% · predictive thresholds → corrective MWO (D§29) ·
repeat-breakdown sweep · assignment watchdog (every 5 min — the supervisor is the addressee of an
unassigned MWO, D§30) · PM window expiry · **deployment divergence:** a migrated-but-not-reseeded DB
lacks `maintenance.wo.verify` + `mrp.machines.view` for this role (A§1.13/§5.5) — the verify card and
`machine.status` silently disappear until the seeder re-runs. Surface as a Release note, not a UI bug.

### 7. DSS recommendations

1. PM slot planning by usage rate (D§5.8) — the supervisor tile.
2. Assignment suggestion (skill/window/match) from MWO history — **data not available: no
   skill/window model**; log as a gap, do not fake it.
3. Replace-vs-repair from MTBF/MTTR + asset book value (`assets.view` ✓).

### 8. Activity feed

MWO assign/verify, schedule CRUD, window confirmations, downtime edits. Surfaces:
`/maintenance/work-orders/:id`, `/maintenance/schedules/:id`.

### 9. Shortcuts (route-verified)

`/dashboard/default` (current) · `/dashboard/maintenance` (proposed, shared) · `/maintenance` ·
`/maintenance/work-orders` (+`/create`) · `/maintenance/schedules` (+`/create`,
`/schedules/:id/edit`) · `/maintenance/downtime` · `/mrp/machines` · `/assets` · `/action-center` ·
`/approvals` (read) · `/notifications`.

### 10. Filters

Machine · mold · MWO status/priority · assignee (all/mine/unassigned) · schedule window · date range ·
downtime reason.

### As-built → Phase-1 delta

Ship the shared `/dashboard/maintenance` page (R12 §"As-built → Phase-1 delta") with a supervisor view
toggled by permission (`maintenance.wo.verify`), and add the three queue cards. Re-run
`ApprovalChainRolePermissionDriftTest` if any step role is touched — not needed here (no chain change).

---

## R14. `impex_officer` — ImpEx Officer · **NEW PAGE REQUIRED** → proposed `/dashboard/logistics`

(A§1.14, 00§A; no `dashboard.*.view`; defaults = supply tiles + purchasing reads + self set.)

### 1. Persona & decisions

Owns shipments, import/export documents, landed costs, deliveries, fleet. **Daily:** deliveries due
today + driver assignment, shipment docs outstanding, customs clearance blockers, delivery attempts
(customer not available). **Weekly:** on-time delivery, freight/landed-cost variance, fleet
utilization/driver assignments, in-transit aging. **Monthly:** OTD against 95, lead-time drift,
doc-expiry compliance, freight spend.

### 2. KPI cards (7)

| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Overdue deliveries | `supply.overdue_deliveries` (`supply_chain.deliveries.view` ✓) | WoW | 0 | `/supply-chain/deliveries` |
| Delivery schedule ≤ N days | `supply.delivery_schedule` | WoW | — | `/supply-chain/deliveries` |
| Open POs (inbound) | `purchasing.open_pos` (`purchasing.view` ✓) | WoW | — | `/purchasing/purchase-orders` |
| Pending PRs | `purchasing.open_prs` | WoW | — | `/purchasing/purchase-requests` |
| OTD | **data not available: strip/tile needs `dashboard.ppc.view`** (KPI gate), OTD KPI is supply_chain's — request access or add a new gate (see §C matrix G-P3-2) | MoM | 95 / 90 ↑ | `/supply-chain/deliveries` |
| Shipments in transit (new) | non-terminal `shipments` count | WoW | — | `/supply-chain/shipments` |
| Docs expiring ≤ 30d (new) | shipment/container documents by expiry | WoW | 0 | `/supply-chain/shipments/:id` |

### 3. Graph analytics (6)

| Chart | Purpose → decision |
|---|---|
| Delivery performance by customer (AreaTrend; `deliveries.actual_*` **unindexed** — index first, 00§blocker-3) | re-promise or add buffer |
| Shipment timeline / in-transit aging (Bar) | chase the oldest |
| Delivery attempts Pareto (reason) | address quality / slot booking |
| Fleet utilization (Bar) | assign vs outsource |
| Landed-cost variance per shipment (Bar; landed costs recorded on shipments, B) | renegotiate freight |
| **New:** doc-expiry horizon (timeline) | avoid customs delays (D§19 document-review sweep class) |

### 4. Forecast widgets

**data not available: no `forecasting.view` grant.** Demand/stock-out MRP projections are PPC-owned;
lead-time drift is a Phase 3 candidate (D§3 candidates list includes supplier lead-time drift).

### 5. Approval inbox & quick actions

Acts on: **none** (no approve slug; A§1.14). Quick actions are lifecycle: create shipment, attach
documents, dispatch delivery, confirm delivery, assign vehicle/driver. **Board-blind flag:** none of
these are approval types; the useful queue is "deliveries scheduled for today with no driver" (new card
above) and "shipments missing documents".

### 6. Alerts / exceptions

Overdue delivery vs `po.expected_delivery_date` · delivery attempts needing a re-schedule (attempts
table, B) · `mrp_schedule_conflict` delivery-risk notice (recipient is sales/PPC, not ImpEx — a gap) ·
AP-due (read) · doc expiry sweep (D§19, notify-only) · **cannot open the driver PWA** to preview
what the driver sees (`supply_chain.driver.access` is driver-only, A§5.7) — a QA/report gap worth
flagging, not a permission to grant casually.

### 7. DSS recommendations

1. Expedite ranking for overdue deliveries (D§5.9) — the ImpEx tile.
2. Lead-time drift per supplier/lane → re-set promised dates (D§3 candidates).
3. Load/vendor suggestion for freight (needs a freight rate table — **data not available**).
4. Doc-expiry nudges with one-click upload.

### 8. Activity feed

Shipment status transitions + document uploads, delivery dispatch/confirm, attempt logs, vehicle
changes. Surfaces: `/supply-chain/shipments/:id`, `/supply-chain/deliveries/:id`. Delivery→invoice is
lossy by design (F2) — the feed must show `manual_required` explicitly.

### 9. Shortcuts (route-verified)

`/dashboard/default` (current) · `/dashboard/logistics` (proposed) · `/supply-chain` ·
`/supply-chain/shipments` (+`/create`) · `/supply-chain/deliveries` (+`/create`) · `/supply-chain/fleet` ·
`/purchasing/purchase-orders` · `/purchasing/purchase-requests` · `/approvals` (read) ·
`/notifications`.

### 10. Filters

Customer · vendor · shipment status · delivery status/date · vehicle · driver · doc type/expiry ·
incoterm · date range.

### As-built → Phase-1 delta

New key `logistics` → `dashboard.logistics.view` → `/dashboard/logistics`, seeded to `impex_officer`.
**KPI-gate consequence:** `on_time_delivery`'s module gate is `dashboard.ppc.view`
(`KpiSnapshotService::MODULE_PERMISSIONS['supply_chain']`); giving ImpEx OTD means either a second
qualified gate or moving the gate map to a permission the logistics role holds — flag as
G-P3-2, do not silently add a role-name branch (forbidden).

---

## R15. `department_head` — Department Head · **NEW PAGE REQUIRED** → proposed `/dashboard/department`

(A§1.15, 00§A; no `dashboard.*.view`; department-scoped role — the only one whose row scopes read
`users.employee.department_id`.)

### 1. Persona & decisions

Department-scoped approver/creator: leave step 1, loan/cash-advance step 1, PR create, RMA step 1,
clearance signature, OT approval. **Daily:** approvals queue (team leave/OT/loans), today's team
presence, unplanned absences. **Weekly:** OT hours vs plan, loan deduction load, probation/training
due, PR needs. **Monthly:** headcount movement, leave-forfeiture horizon, dept overtime cost, budget
consumption (read via `budgeting`? **not held** — data not available for this role).

### 2. KPI cards (9)

| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Pending my approval | `approvals.pending` (self-scoped, top-8 SLA table) | WoW | SLA 24h/48h | `/approvals` |
| Team present today | `hr.team_dtr_today` (`attendance.view`) | WoW | — | `/hr/attendance` |
| Team on leave today | `hr.team_on_leave_today` (`leave.view`) | vs 4-wk avg | — | `/hr/leaves` |
| Dept headcount | `hr.headcount` (`hr.employees.view`, dept-scoped unless sensitive) | MoM | — | `/hr/employees` |
| Probation alerts | `hr.probation_alerts` | WoW | 0 | `/hr/employees` |
| Dept open PRs | `purchasing.open_prs` (dept rows added by policy) | WoW | — | `/purchasing/purchase-requests` |
| Dept loans outstanding | `loans.outstanding` (dept ladder) | MoM | — | `/hr/loans` |
| Returns awaiting decision | `rma.pending_approval` (`return_management.view` ✓) | WoW | — | `/return-management` |
| Attendance rate (dept) | **new, snapshot** — `kpi.attendance_rate` gate `dashboard.hr.view` is unheld; either grant or add a dept-rollup card (G-P3-2) | MoM | 96 / 93 ↑ | `/hr/attendance` |

### 3. Graph analytics (6)

| Chart | Purpose → decision |
|---|---|
| Approval queue by type/age (Bar; ledger rows only — see §5) | decide oldest first |
| Team attendance by day (AreaTrend; `attendances` overwrite-per-day, punches for intra-day) | spot slippage → coaching/shift change |
| OT hours by employee (Bar) | authorize vs hire (D§5.4) |
| Leave calendar / forfeiture horizon (timeline) | approve before balances lapse (D§35) |
| Headcount movement (hires vs separations; `employment_history`) | req planning |
| **New:** dept leave-type mix (Donut) | coverage planning by leave reason |

### 4. Forecast widgets

`forecast.headcount` ✅ (gate `hr.employees.view` ✓, dataset-gate rule — visible to this role by
design) · `forecast.attendance` gate-wise ✅ (`attendance.view` held, but see the G-P2-1 leak) ·
everything else stripped. Recommendation: seed `forecast.headcount` into the role default and mark the
attendance panel as team-scoped in its copy.

### 5. Approval inbox & quick actions

| Type | Step & gate | Quick action → endpoint | Page |
|---|---|---|---|
| Leave | step 1 `leave.approve_dept` (dept + own scope) | approve-dept / reject w/ remarks | `/hr/leaves/:id` |
| Loan / cash advance | step 1 (both chains, dept ladder) | approve/reject | `/hr/loans/:id` |
| Return request | step 1 (`return_management.approve`, **not** dept-scoped) | approve/reject | `/return-management/:id` |
| Overtime | custom single-step (`attendance.ot.approve`) | approve/reject — **board-blind** (C§1b) | `/hr/attendance/overtime` |
| Separation clearance | step 1 sign (`hr.clearance.sign`, own-department items only) | sign item | `/hr/separations/:id` |
| PR | creator only (`purchasing.pr.create`, no chain step since 2026-09-10) | create/submit | `/purchasing/purchase-requests/create` |
**Known stalls to surface:** own leave (sole-head department) stalls at step 1 (C§2.1); a headless
department stalls loans/RMA step 1 (C§2.3) — the dashboard should show "unassigned approver" rather
than an empty card. OT cards never appear on `/approvals` (payroll/custom family, 00§blocker-1).

### 6. Alerts / exceptions

OT queue age (no SLA sweep on custom types — C§2.10) · team leave-forfeiture horizon (D§35) ·
probation and training expiries (D§19) · loan step-1 stall when the dept has no head · self-approval
refusals on the head's own requests · 15-min? no — 30-min session (A§0) · notification darkness: loan
mid-chain handoffs and OT submits fire (OT does notify — C§3), PR submit does not.

### 7. DSS recommendations

1. Team coverage vs leave forecast ("approve this leave?" → who else is out) — new composition from
   leave calendar + headcount, no new engine.
2. OT-vs-hiring for the department (D§5.4).
3. Leave-forfeiture nudges before the Dec-31 job (D§35).
4. PR consolidation hints (est. total + days waiting, already in the purchasing page payload).

### 8. Activity feed

Leave arms (dept approve), loan step decisions, RMA step, clearance signs, OT decisions, PR
submissions. Surfaces: `/hr/leaves/:id`, `/hr/loans/:id`, `/return-management/:id`,
`/purchasing/purchase-requests/:id`.

### 9. Shortcuts (route-verified)

`/dashboard/default` (current) · `/dashboard/department` (proposed) · `/approvals` ·
`/action-center` · `/hr/employees` · `/hr/leaves` · `/hr/attendance` ·
`/hr/attendance/overtime` · `/hr/loans` · `/hr/separations` · `/purchasing/purchase-requests`
(+`/create`) · `/return-management` · `/notifications` · `/calendar` · self-service.

### 10. Filters

Department (implicit, always applied server-side) · employee · request type · status · date range ·
leave type · OT window.

### As-built → Phase-1 delta

The role-default widget set is already approvals-first and department-scoped (good). What is missing:
dedicated cards for the dept OT/hour shortfall, an "unassigned approver" state, and a page that puts
the five approval types in one queue (today they are five separate module pages plus a board that
omits OT).

---

## R16. `employee` — Employee (self-service baseline) · page EXISTS (`/dashboard/default` + `/self-service`)

(A§1.16, 00§A; 15-min session timeout; 3-KPI employee dashboard endpoint exists:
`GET /dashboards/employee` → `HrDashboardService::employee`, D§1.1.)

### 1. Persona & decisions

Self-service only: file leave, file/see OT, see DTR, see payslips, see loans, read assigned work
orders. **Daily:** clock status, own DTR, my tasks. **Weekly:** leave balance, OT filed vs approved,
payslip questions. **Monthly:** payslip, statutory deductions, leave forfeiture horizon.

### 2. KPI cards (7)

| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Attendance (month) | page KPI on the employee dashboard; `attendance.view` self-scoped | MoM | 96 / 93 (reference) | `/self-service/dtr` |
| Leave remaining | page KPI + `self.leave_balance` | — | forfeiture Dec 31 | `/self-service/leaves` |
| Pending requests | page KPI + `self.pending_requests` | — | — | `/self-service` |
| Latest payslip net | `self.payslip_summary` | vs prior cutoff | — | `/self-service/payslips` |
| DTR hours today | `self.dtr_today` | vs 4-wk avg | — | `/self-service/dtr` |
| Assigned work orders (new tile) | `/self-service/work-orders` (WOs where the employee is crew) | — | — | `/self-service/work-orders` |
| Next holiday | employee dashboard panel | — | — | `/calendar` |

### 3. Graph analytics (4) — deliberately few; this is a self-service page

| Chart | Purpose → decision |
|---|---|
| My attendance 6-month trend (AreaTrend, self) | spot own lates → correct DTR |
| My leave balance burn-down (Bar/line) | file before forfeiture |
| My net pay trend (Bar) | question a deduction |
| My OT hours by cutoff (Bar) | manage load |

### 4. Forecast widgets

`forecast.attendance` gate-wise ✅ via the shared self-service slug — but a personal attendance
projection is not meaningful. **Recommendation:** strip it for this role (role defaults already omit
it; the picker should hide it too).

### 5. Approval inbox & quick actions

Acts on: **none** — the board is read-only (informational). Quick actions: file leave, file OT,
view payslip, acknowledge documents, submit profile update request. **Board-blind flag:** nothing an
employee raises can be tracked on the board except leave (ledger) — OT, profile updates and payroll
adjustments have no card (C§1b), so "where is my request?" needs a self-service request tracker rather
than the approval board.

### 6. Alerts / exceptions

Payslip ready (`chain.payslip_ready`, C§3) · leave approved/rejected · OT decided · assigned MWO ·
training/document expiry tiers (30/14/7, D§19) · 15-min session timeout notice (A§0) · missing
timeout notice 07:00 (D§34).

### 7. DSS recommendations

1. Leave-balance / forfeiture projection ("file N days before Dec 31").
2. Payslip anomaly explainer ("net changed 22% — OT down, loan amortisation").
3. Personal DTR exception self-check before the cutoff locks.

### 8. Activity feed

Own leave/OT/profiling requests, payslip releases, assigned-WO progress. Surfaces:
`/self-service/leaves/:id`, `/self-service/work-orders/:id`.

### 9. Shortcuts (route-verified)

`/dashboard/default` · `/self-service` · `/self-service/dtr` · `/self-service/overtime` ·
`/self-service/leaves` (+`/create` via `/self-service/leaves`) · `/self-service/loans` ·
`/self-service/payslips` · `/self-service/work-orders` · `/self-service/profile` · `/calendar` ·
`/notifications` · `/action-center`. Note **M7**: these pages carry no `PermissionGuard` while the APIs
do — a revoked self-service slug keeps the page and 403s the calls.

### 10. Filters

Month/date range · request status · leave type. Nothing department-wide (self only).

### As-built → Phase-1 delta

Prefer the **existing** `GET /dashboards/employee` payload as the employee landing (3 KPIs + payslip +
holiday) over the generic grid, and hide the mis-gated team widgets (G-P2-1) from this role. Fix M7
guards.

---

## R17. `driver` — Driver · **NEW LANDING REQUIRED** → existing `/driver` PWA

(A§1.17, 00§A; holds self-service + `supply_chain.driver.access` only.)

### 1. Persona & decisions

Executes assigned delivery runs on a phone: see the run, navigate, capture proof-of-delivery/photo,
mark attempts. **Daily:** today's runs, next stop, POD capture, failed-attempt reason. **Weekly:**
own run volume, attempts. **Monthly:** payslip, leave.

### 2. KPI cards (5 — the PWA should carry them, not a grid tile)

| Card | Value | Delta / sparkline | Target | Click-through |
|---|---|---|---|---|
| Runs assigned today | `GET /driver/deliveries*` (own runs, D§1.1/A§1.17) | WoW | — | `/driver` |
| Completed / remaining | own run statuses | — | 100% | `/driver` |
| POD missing (new) | completed runs without photo/signature | WoW | 0 | `/driver/:id` |
| Latest payslip net | `self.payslip_summary` | vs prior cutoff | — | `/self-service/payslips` |
| Leave remaining | `self.leave_balance` | — | Dec 31 | `/self-service/leaves` |

### 3. Graph analytics (3) — own-run scale only

| Chart | Purpose → decision |
|---|---|
| My runs per day (Bar, 30d) | workload awareness |
| Attempts by reason (Bar) | self-correct / raise access issues |
| On-time against planned window (AreaTrend, own) | improve the next run |

### 4. Forecast widgets

**data not available: no `supply_chain.driver.access`-scoped forecast, no `forecasting.view`** — and a
forecast is not the driver's decision surface. Do not mount any.

### 5. Approval inbox & quick actions

Acts on: **none** (no approve slug; self-service + driver PWA only). Quick actions = the PWA's own:
start run, next stop, capture POD, log attempt. **Board:** read-only; the `approvals.pending` widget in
their default layout is effectively empty and should be swapped for a "my runs today" card.

### 6. Alerts / exceptions

Delivery assigned/changed (push — Reverb is not event-wired for driver runs today; polling only,
F§1 events list has no driver event) · POD not captured by end of run · attempt reasons · 15-min
session timeout (A§0).

### 7. DSS recommendations

1. Route/sequence suggestion for the assigned stops — **data not available: no routing/geocoding model**
   (leaflet maps exist for display only, E§2). Log as a Phase 3+ gap.
2. Remaining-stop ETA from planned windows.

### 8. Activity feed

Own run status changes, POD/photo uploads, attempt logs. Surface: `/driver/:id`.

### 9. Shortcuts (route-verified)

`/driver` · `/driver/:id` · `/driver/:id/photo` · `/self-service` · `/self-service/payslips` ·
`/self-service/leaves` · `/notifications`.

### 10. Filters

Date (today/this week) · run status · delivery area (own runs only).

### As-built → Phase-1 delta

Dispatch currently sends every role without a dashboard permission to `/dashboard/default`, so a driver
lands on a widget grid to reach a phone workflow. Add a **catalog/PWA-aware dispatch entry** so
`supply_chain.driver.access` (or a new `dashboard.driver.view` held by the driver role) lands on
`/driver`. Keep the widget grid as a secondary link. Driver default layout should drop
`approvals.pending` and gain the run cards.

---

## Cross-role notes for the P3 roles (feed into `01-matrix.md`)

1. **G-P3-1 (leak, sharpest here):** `attendance.view` / `leave.view` are granted to **every** role via
   `selfService()` (`RolePermissionSeeder.php:1011-1019`), and the widget gates
   `hr.team_dtr_today`, `hr.team_on_leave_today`, `forecast.attendance` use exactly those slugs
   (`DashboardWidgetSeeder.php`). An `employee` or `driver` picker therefore offers *department-wide*
   attendance/leave tiles — the same leak class CLAUDE.md records for the Approval Queue
   ("a self-scoped permission reused as a GLOBAL gate is a leak"). The payload is department-scoped and
   null-safe, so the fix is a gate change (or an explicit, documented decision that team tiles are
   viewable by all staff) — never a client-side role check.
2. **G-P3-2 (gate map is modules, roles are not):** OTD's gate is `dashboard.ppc.view`; attendance
   rate's is `dashboard.hr.view`. `impex_officer` (logistics = the supply-chain owner on the floor) and
   `department_head` (dept attendance) both need one of those readings and hold neither. Resolve by
   extending `KpiSnapshotService::MODULE_PERMISSIONS` to accept a set of qualifying slugs, or by adding
   new KPI definitions — not by branching on role name.
3. **G-P3-3 (landing ≠ surface):** three of five roles here have a better primary surface than the grid:
   `/driver` (PWA), `/self-service` (employee), `/dashboard/maintenance` + `/dashboard/logistics`
   (proposed). Landing selection is permission-derived rarity sort — so each new surface needs its own
   permission slug, and each new slug changes landing/rarity behaviour for existing holders
   (`DashboardDispatchService::resolve()`). Sequence these one at a time and record the dispatch diff.
4. **G-P3-4 (role defaults vs gates):** `DashboardRoleLayoutSeeder` says "the five roles with no
   `dashboard.*.view` grant get none" but eight roles lack one (A§5.1/§5.2). Every default in this group
   should be validated by `WidgetSeedIntegrityTest` (which asserts no role default references a widget
   the role cannot see) after the gate fixes above — otherwise `driver`'s and `employee`'s defaults sit
   one permission change away from silently stripping to an empty grid.
