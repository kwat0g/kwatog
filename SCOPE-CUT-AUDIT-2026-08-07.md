# OGAMI ERP — Scope Cut Audit (Defense Simplification)

> **Goal:** Identify low-value features safe to remove. Thesis defense in ~2 months;
> simpler surface = clearer story. Cut anything not serving: (1) the 3 business
> chains, (2) ADV1-12 requirements, (3) IATF 16949 traceability proof.

**Audit date:** 2026-08-07  
**Method:** Cross-reference demo script, defense traceability matrix, inbound coupling,
LOC weight, test coverage, seeder mentions.

---

## Protected scope (NEVER cut)

### Mandatory (ADV + 3 chains)
- **ADV1** — Payroll disbursement proof (chain 3 finale)
- **ADV3** — Batch/lot traceability (IATF flagship, chain 1 core)
- **ADV4** — Dynamic RBAC
- **ADV5** — Procurement + 3-way match (chain 2 core)
- **ADV6** — Purchase Request automation
- **ADV7** — Proof of delivery (chain 1 delivery gate)
- **ADV8** — Warehouse Management (bin-level, stock count, MRB quarantine)
- **ADV9** — **Budgeting overview + enforcement** (allocation + critical %, wired into PR/PO)
- **ADV10** — B2B portals (supplier + customer, separate guards)
- **ADV11** — **Forecasting** (demand, stock-out projection, MRP feed toggle)
- **ADV12** — Return Management (RMA, disposition → credit note)

### Supporting (woven through chains or IATF touchpoints)
- **Quality** — inspection specs, inspections, NCRs, CoC (4 IATF touchpoints)
- **Maintenance** — mold shot tracking, preventive schedules (production continuity)
- **Inventory** — GRN, material issue, stock levels, movements (chain 1+2 backbone)
- **Production** — work orders, output, routings, Gantt (chain 1 core)
- **MRP** — plans, BOMs, machines, molds (chain 1 planning)
- **CRM** — sales orders, customers, products, price agreements, complaints/8D (chain 1 start)
- **Purchasing** — PRs, POs, approved suppliers (chain 2 core)
- **Supply Chain** — shipments, deliveries, fleet (chain 1+2 logistics)
- **Accounting** — COA, journal entries, invoices (AR), bills (AP), **credit notes**, vendors, customers, financial statements (chain finale, all 3)
- **Payroll** — periods, statutory exports, de minimis (chain 3 core)
- **HR** — employees, departments, positions, separation/clearance (chain 3 core)
- **Attendance** — DTR, shifts, OT approval (chain 3 payroll input)
- **Leave** — requests, balances, approval (chain 3 payroll input)
- **Loans** — company loan + cash advance (chain 3 payroll deduction)
- **Dashboard** — live KPIs, chain stage breakdown, alerts (demo opening)
- **Admin** — users, roles, audit logs, SoD, settings, imports

---

## HIGH-CONFIDENCE CUTS (17 features, ~29.5k LOC)

### 1. Landing page (5,715 LOC) — marketing fluff
| | |
|---|---|
| Surface | `/` root, 27 route files, 3D hero (three.js `^0.184`), marquee, 48 files |
| Backend | `Modules/Landing` — public quote-request form, company-site content |
| Why cut | Zero chain/ADV/IATF relevance. 3D hero + marketing copy = demo distraction. Panel asks "who is this for?" |
| Keep | None — ERP auth redirects to `/dashboard` already |
| Risk | None (public-facing, decoupled). Removing three.js shaves bundle |

### 2. CRM sales pipeline — Leads + Opportunities (3,860 LOC)
| | |
|---|---|
| Surface | `/crm/leads/*`, `/crm/opportunities/*` (8 routes) |
| Backend | `LeadService`, `OpportunityService`, quote funnel |
| Why cut | Ogami doesn't hunt leads — a Japanese molder takes contracted orders. Sales funnel ≠ Order-to-Cash; panel can't tie it to the thesis. ~34 SPA files |
| Keep | None (SO is the chain-1 start; leads/opps are pre-sales noise) |
| Risk | Low — no inbound coupling from SO/WO/Invoice |

### 3. CRM Quotes (1,205 LOC, backend-only)
| | |
|---|---|
| Surface | No SPA page at all — API-only `Quote`/`QuoteService` |
| Why cut | Dead UI + unused endpoint weight. Quote→SO conversion never built |
| Keep | None |
| Risk | Zero (nothing imports it; SO has no `quote_id` FK) |

### 4. Sales Commissions (1,051 LOC)
| | |
|---|---|
| Surface | `/crm/commissions` (rates + earnings, 3 subpages) |
| Why cut | Commission calculation for sales reps — not a factory cost; not in any chain, not in ADV list |
| Keep | None |
| Risk | Zero (no coupling; MoldService hit was false positive — "commission a mold") |

### 5. HR Recruitment (3,278 LOC)
| | |
|---|---|
| Surface | `/hr/recruitment/*` (jobs, applicants, interviews) + `careers` public site |
| Why cut | Hiring funnel is pre-employment — Chain 3 starts at **hire**. ADP: Ogami uses direct hire + referral, no job boards. 24 files, biggest HR drag |
| Keep | None |
| Risk | Low (self-contained; dashboard headcount forecast is HR-side, not recruitment) |

### 6. HR Succession Planning (1,078 LOC)
| | |
|---|---|
| Surface | `/hr/succession-plans` (readiness, priority, status) |
| Why cut | Headcount replacement planning — strategic, not operational. Zero ADV/chain value |
| Keep | None |
| Risk | Low |

### 7. HR Performance Reviews (1,467 LOC)
| | |
|---|---|
| Surface | `/hr/performance-reviews` (reviews, goals) |
| Why cut | Semi-annual evaluation — not in Hire-to-Retire payroll flow. 11 files |
| Keep | None |
| Risk | Low |

### 8. Employee Skills module (616 LOC) — ~~CUT~~ **WITHDRAWN, KEEP**
| | |
|---|---|
| Surface | `/hr/skills` (skills catalog), Skills tab on employee detail |
| Original call | "Redundant with Training Matrix" — **wrong** |
| Why keep | Skills is not redundant with the Training Matrix, it *is* the matrix's data source. `TrainingMatrixController` cross-tabulates `Skill::query()` × `employee.skills` to produce trained/expired/gap cells — the IATF clause 7.2 competence evidence. Cutting Skills leaves `/hr/training/matrix` (live, routed, in sidebar) an empty grid. The catalog CRUD is also the only way to create skills, so it is load-bearing for the demo too |
| Verified | `api/app/Modules/HR/Controllers/TrainingMatrixController.php:10,39,52-63,92-100` |
| Separate finding | `skills` and `employee_skills` are both **0 rows** — the matrix renders blank in a demo today. Seeding gap, not a scope problem. Worth adding demo rows before defense |

### 9. SPC — PARTIAL CUT (control charts out, Cp/Cpk kept) ✅ DONE
| | |
|---|---|
| Surface | `/quality/spc`, `/quality/spc/charts/:id` (charts) · Cp/Cpk panel on inspection-spec editor + `/quality/capability-study` |
| Why cut | IATF *says* SPC is optional — traceability + inspections + NCRs are the mandatory proof. The chart half was a **second, parallel detector** for a signal the inspection path already raises: `InspectionService::recordMeasurements()` auto-evaluates every measurement against tolerance and opens an NCR on failure |
| Evidence | `spc_control_charts` = **1 row**, `spc_data_points` = **0**, `spc_alerts` = **0**. No chart ever reached the 20-point minimum its own policy required to compute limits, so no limits were ever calculated. `quality.spc.view` was **already revoked** from every role — all 3 chart pages were unreachable |
| Kept | `SpcService::compute()` / `computeForSpec()` / `computeCapabilityStudy()` / `capabilityThresholds()` — they read `inspection_measurements` directly, need no chart row, and back two live screens. New `CapabilityController` serves the 2 surviving endpoints |
| Removed | `SpcService` trimmed 436 → 217 lines; chart controller/models/resources/requests/listeners, 3 SPA pages, `spcApi`, 3 tables (migration 0460), 7 chart-only settings (0461) + their validation rules |
| Verified | `SpcServiceTest` 9/9 pass (Cp/Cpk math untouched) · quality suite 103/103 · `tsc --noEmit` clean · 903 routes register |
| Risk | Low — no inbound refs; capability seam is a pure read over kept inspection data |

### 10. COPQ (1,885 LOC, 17 files)
| | |
|---|---|
| Surface | `/quality/copq` (cost of poor quality) + dashboard widget |
| Why cut | Cost quantification of defects — nice-to-have. NCR Pareto already shows the same failures |
| Keep | None (Pareto from NCR data remains on Quality dashboard) |
| Risk | Low (dashboard coupling = one widget, removable) |

### 11. QMS Document Management (786 LOC)
| | |
|---|---|
| Surface | `/quality/documents` (documents, review/approval, acknowledgments) |
| Why cut | IATF wants *controlled documents* — but the mandatory proof is traceability + inspections, not a document DMS. 6 files |
| Keep | None |
| Risk | Low |

### 12. FX Rates + JP Parent Pack (615 LOC)
| | |
|---|---|
| Surface | `/accounting/fx-rates`, `/accounting/parent-pack` (JPY parent translation) |
| Why cut | Ogami is **₱-only** (CLAUDE.md: "Currency: Philippine Peso only"). Multi-currency + JPY parent pack is Japanese-accounting fluff the panel can't ask about |
| Keep | None (trial-balance FX variants removed with it) |
| Risk | Low (7 files, self-contained) |

### 13. Opening Balances (738 LOC)
| | |
|---|---|
| Surface | `/accounting/opening-balances` (GL + stock TB reconciliation) |
| Why cut | Go-live transition tool — the demo DB doesn't need it; statements render from live data. 6 files |
| Keep | None (TB/statements stay) |
| Risk | Low (demo runs fresh-seeded) |

### 14. Accounting Periods close/lock — ~~CUT~~ **KEEP** (verdict reversed 2026-08-07)
| | |
|---|---|
| Surface | `/accounting/periods` (close/reopen fiscal periods) |
| Original call | Cut — "audit-close ceremony, statements don't depend on it" |
| Why reversed | **Wrong.** `AccountingPeriodService::assertPostingAllowed()` is called on 6 live GL-posting paths: `JournalEntryService` (create + post), `InvoiceService`, `BillService`, `CreditNoteService`, `PayrollGlPostingService`. It is an enforced internal control that blocks backdated posting into a closed month — not ceremony |
| Cost of cutting | Would require stripping the guard out of 5 **kept** services, removing a control. Negative value, high risk |
| Defense value | Easy to explain in one line ("you cannot post to a closed month"), and a recognised accounting control a panel will respect |
| Note | CLAUDE.md's NOT BUILDING list says "❌ fiscal period locking". The feature exists, is enforced and is tested — the **spec line is stale**, not the code. Recommend updating CLAUDE.md rather than deleting the control |

### 15. Budget Transfers (472 LOC)
| | |
|---|---|
| Surface | `/budgeting/transfers` |
| Why cut | Reallocation between budget lines — a controller's chore. ADV9 needs **overview + enforcement** (kept) |
| Keep | Budget overview, budget-vs-actual, **PR/PO enforcement** (the panel demo) |
| Risk | Low |

### 16. Budget Revisions (79 LOC, 2 files)
| | |
|---|---|
| Surface | L-26 revision approval flow |
| Why cut | Tiny, adds approval-branch complexity. Budget close/reopen already exists |
| Keep | None |
| Risk | Zero |

### 17. Edge / IoT device sync (1,508 LOC, 27 files)
| | |
|---|---|
| Surface | No SPA UI. Device registration, scan, output, health — API only |
| Why cut | Factory-floor IoT ingestion. No panel demo, no chain/ADV link. The `EdgeSystemUserResolver` is reused by B2B — **keep that one class** |
| Keep | `EdgeSystemUserResolver` (B2B portal impersonation) |
| Risk | Medium if resolver moves — otherwise the whole module is disposable |

---

## REVIEW BEFORE CUTTING (7 features — real value, verify)

| # | Feature | LOC | The case for keeping |
|---|---|---|---|
| A | Budgeting overview (ADV9) | ~1k | **KEEP** — the demo's 98%-critical showpiece; PR/PO enforcement wired. Only cut transfers/revisions |
| B | Forecasting (ADV11) | ~1.5k | **KEEP** — adviser-required. But trim: `stock-out` + `accuracy` pages are bonus weight — confirm they're demo-critical before touching |
| C | Fixed Assets + Depreciation | 2,458 | Keep module; check `admin/depreciation` page + cron vs demo needs |
| D | Fleet (supply-chain) | small | Keep — ADV2 SCM separation + delivery logistics |
| E | Multi-currency | 615 | Cut (see #12) — contradicting "₱-only" |
| F | QMS Documents | 786 | Lean cut (see #11) |
| G | Dashboard variants | 3,935 | Keep scorecard + finance; others are cheap page variants |

---

## ALREADY EXPLAINED (not bloat)

- **Excess sidebar items** (`Approvals`, `Notifications`, `Action Center`, `Exceptions`, `Chains`, `KPI Scorecard`, `Sessions`, `SoD`, `Operations Health`) — all small pages (<350 LOC each) or demo props. Keep.
- **Dashboards** — one per role is surface area, but each is a lightweight query page. Keep; they *look* impressive to panel.
- **Warehouse Scanner / Warehouse Map / Stock Count / Transfer Orders / Picking** — ADV8 requirement. Keep.
- **Return Management + Credit Notes** — ADV12 + REC-13, wired into RMA. Keep.
- **Self-service + portal PWAs** — ADV10. Keep.

---

## REMOVAL ORDER (safest first)

1. **Phase 1 — dead/free:** Quotes (no UI), Budget Revisions, QMS Documents (no demo refs). ~~Employee Skills~~ withdrawn — see §8, it backs the IATF training matrix
2. **Phase 2 — zero-coupling features:** Commissions, Succession, Performance Reviews, Leads/Opportunities, Opening Balances, FX Rates/Parent Pack. ~~Accounting Periods~~ withdrawn — see §14, `assertPostingAllowed()` guards 6 live GL-posting paths
3. **Phase 3 — heavy but standalone:** SPC, COPQ (needs dashboard widget detach), Landing page (needs root redirect), Recruitment (+ careers site)
4. **Phase 4 — surgical:** Edge module (keep `EdgeSystemUserResolver`), Budget Transfers (keep overview/enforcement)

## REMOVAL METHOD (per feature)

1. Delete SPA route group + page files; remove sidebar entries
2. Delete controllers/services/models/resources/routes; remove `use` imports
3. Delete migrations ONLY if table is unused elsewhere (check FKs first — e.g. `credit_notes` stays, `fx_rates` goes)
4. Remove permissions from `RolePermissionSeeder` + menu seeders (check `feature` toggle keys)
5. Remove seeder references (ComprehensiveDemoSeeder, GoldenPathDemoSeeder, KpiDefinitionSeeder)
6. Remove tests for deleted features; re-run full suite (1,242 tests baseline)
7. Update `docs/` — DESIGN-SYSTEM, TASKS, SCHEMA, SEEDS, USER-MANUAL, DEMO-SCRIPT (keep only if needed)

**Verification:** `make test` green, `npm run build` green, demo seeder runs, no orphan `import` errors.

---

## NET RESULT

- **~29.5k LOC removed** (backend + SPA)
- **Sidebar:** ~110 → ~75 items (cleaner panel walk-through)
- **Story:** "Three chains, quality woven through, 12 adviser items" — nothing left to explain
- **Risk:** all cuts are leaf-node features; every kept feature was checked for inbound coupling first

---

## LIVE FOLLOW-UP PASSES (PASS 3–6 + follow-ups, 2026-08-08)

*Re-applied 2026-08-08 after a `git reset` wiped the original uncommitted pass
work. Each item below follows the **"hide access, keep code"** policy: routes and
sidebar entries are commented out (with re-enable notes) or removed, but page
files, services, models, and tests stay in the tree so nothing is lost.*

### PASS 3 — Inventory + HR hides (stock transfers, ABC, property, directory)

| Feature | Module | What was hidden | Why |
|---|---|---|---|
| Stock Transfers | Inventory | `POST /inventory/stock-transfers` commented; SPA create route removed | Secondary movement path — GRN/material-issue already cover chain 1+2. `StockTransferService`/model kept |
| ABC classification | Inventory | `POST /inventory/recompute-abc` route removed | Background re-ranking, no panel story. `AbcClassification` compute kept in service |
| Employee Property tab | HR | Property tab on employee detail removed (SPA) | Custody ledger not in Hire-to-Retire payroll flow; model/API kept |
| Employee Directory | HR | `/hr/employees/directory` + org-chart routes commented | Org chart is a nice-to-have view; `hr.directory.view` permission **kept** because the employee-photo gate (`permission_any:hr.employees.view,hr.directory.view`) depends on it |

### PASS 4 — Accounting + Purchasing hides (periods page, PR templates)

| Feature | Module | What was hidden | Why |
|---|---|---|---|
| Accounting Periods page | Accounting | Standalone `/accounting/periods` SPA surface hidden | **Enforcement KEPT** — `assertPostingAllowed()` still guards 6 live GL-posting paths (see §14). Only the page was cut; control untouched |
| PR Templates | Purchasing | `/purchasing/pr-templates` backend routes + SPA routes commented | Quick-fill templates for PR creation — 7 live rows, no chain value. Controller/service kept per policy |

### PASS 5 — MRP hide (mold cost-trend)

| Feature | Module | What was hidden | Why |
|---|---|---|---|
| Mold cost-trend | MRP | `GET /molds/{mold}/cost-trend` route commented | Trend chart is a report on top of mold costs; the mold cost panel already shows the numbers. `MoldController::costTrend` kept |

### PASS 6 — HR/Assets hides (Leave Types → modal, asset transfers, machine health)

| Feature | Module | What changed | Why |
|---|---|---|---|
| Leave Types | HR/Leave | **Standalone page → modal inside the Leave page** | Same pattern as De Minimis: type CRUD is a settings chore, not a destination. `types.tsx` rewritten as `LeaveTypesManager` component, embedded in `leaves/index.tsx`; route + sidebar entry removed; guard access tightened to `leave.types.manage` |
| Asset Transfers | Assets | Backend route group + SPA routes commented | Weakest Assets piece — 0 live rows, unreachable from asset detail. `AssetTransferTest` converted to call `AssetTransferService` directly (service-level coverage kept) |
| Machine Health | Maintenance | Backend `condition-readings` routes, SPA page routes, mobile routes + layout nav all commented | System has no direct connection to machines — readings would be manually entered, no sensor feed. `MachineConditionReadingService`/model kept; `MobileMaintenanceTest` + hash-id test converted to service-level asserts |

### Follow-up — OEE report (Production)

- **Sidebar entry removed** — OEE is a report, not a module destination.
- **Production dashboard links re-pointed** — the OEE card now links to the production dashboard instead of the standalone OEE page.
- **`plant-manager.tsx` fixed** — `linkByCode`/navigate wiring that referenced the removed page corrected.
- Page file + `ProductionDashboardService::oee()` **kept** as dead code per policy.

### Follow-up — Payroll declutter (pipeline, header, PII)

| Button | Verdict | Action |
|---|---|---|
| BIR 2316 | Moved, not cut | Now a **"BIR 2316 Alphalist" export card on the Statutory Exports page** (its natural home with 1601-C/1604-CF/RF1/MCRF) |
| Adjustments | Moved, not cut | **Load-bearing** — `PayrollCalculatorService` consumes `PayrollAdjustment` on every compute (retro pay, corrections). Moved to the **Payroll sidebar section** |
| Pipeline | **Removed** | 181-LOC read-only year-board, redundant with per-row status on the periods list. SPA route + header button + `periods/pipeline` endpoint commented; `PayrollPeriodService::pipeline()` kept as dead code |
| Gov Tables | Moved, not cut | **126 live bracket rows** feeding all four gov computation services (SSS/BIR/PhilHealth/Pag-IBIG) — cutting would break payroll math. Moved to the **Administration sidebar section** |

**Security fix (PII):** the old BIR 2316 button was gated on `payroll.view` (granted to **every role** via self-service). Its new home sits behind `payroll.statutory.export` — granted only to `hr_officer` — closing a company-wide PII leak (everyone's TIN, income, tax withheld).

### Items audited and kept (no change)

- **Holidays page** — master data feeding holiday-pay computation (`day_type_rate` → DTR → payroll → GL → 13th-month). Only CRUD surface for the `holidays` table; keep.
- **Trainings / Training Matrix** — IATF clause 7.2 competence evidence; keep.
- **De Minimis** — consumed by payroll compute; keep.
- **Routings** — WO operations; keep.
- **MRB / Quarantine, Issuance, Stock Counts, Warehouse Scanner** — ADV8 requirement; keep.
- **Payroll hub pages** (BIR 2316, Adjustments, Gov Tables) — statutory/load-bearing as above; keep (relocated).

### Re-application verification

- All hidden routes are commented with `HIDDEN 2026-08-08 (scope cut)` + re-enable notes
- Sidebar: Leave Types, OEE, Machine Health entries gone; Adjustments (Payroll) + Gov Tables (Administration) added
- Tests converted to service-level coverage still green; Payroll suite 217/217
- `periodsApi.downloadBirAlphalist` trimmed (2026-08-08): BIR 2316 was **moved**, not hidden — the old method duplicated `statutoryApi.bir2316Alphalist` on the same endpoint with zero callers. `periodsApi.pipeline` was **kept** because the retained `pipeline.tsx` page file still references it
- Hash-id regressions (dropped deliberately): the HTTP-layer "garbage id → 422, never 500" guarantee (`ConditionReadingHashIdTest`) no longer has an HTTP surface to assert on — the kept `PredictiveMaintenanceService` takes a decoded int, so garbage would cast to `0`. Covered instead by the decode-roundtrip + service-accepts-decoded-int tests
- ⚠️ **Process hazard:** running two `php artisan test` suites in parallel against the same `ogami_test` DB deadlocks on `RefreshDatabase`'s `drop table … cascade` and corrupts the DB (must be dropped/recreated). Always run test suites **sequentially**.

### HR sidebar declutter (2026-08-08) — 14 items → 11

The Human Resources sidebar section was the busiest in the app. Three entries that
were settings chores or single-action pages (not destinations) were folded into
their parent pages — the same consolidation pattern as Leave Types:

| Removed from sidebar | New home | Why |
|---|---|---|
| **Year-End Leave** (`/hr/leaves/year-end`) | **Modal on the Leave page** (next to Manage Types) | 47-LOC page that was literally a year input + one submit button queuing a job. Same permission (`leave.types.manage`) as the button it now sits beside |
| **De Minimis** (`/payroll/de-minimis`) | **Modal on the Payroll Periods page** | Settings CRUD — same shape as Leave Types. Data stays load-bearing: `PayrollCalculatorService` reads `de_minimis_benefits` on every compute run |
| **Salary Adjustments** (`/hr/salary-adjustments`) | **Tab on the Employees page** (header toggle: Employees ↔ Salary Adjustments) | Maker-checker approval queue with its own permission (`hr.salary_adjustments.view`); belongs inside the Employees workspace, not a standalone sidebar slot |

**Kept in sidebar:** Employees, Departments, Attendance, Overtime (has its own
pending badge), Leave, Payroll, Adjustments (load-bearing calculator input),
Statutory Exports, Recruitment, Training Matrix, Trainings.

**Verified:** tsc + ESLint clean · 39 Leave + 217 Payroll tests pass · no lingering
references to the removed routes · backend endpoints kept live for the modals
(`/process-year-end`, `/payroll/de-minimis`).

**Permission-coupling fix (review catch):** `production_manager` holds
`hr.salary_adjustments.view` + `.act` (step-1 checker on the REC-03 chain) but
**not** `hr.employees.view`. Moving the queue under `/hr/employees` would have
silently locked them out. Fix: the `/hr/employees` route guard and the sidebar
Employees entry now use `anyOf(['hr.employees.view', 'hr.salary_adjustments.view'])`,
and the page defaults to the adjustments view (hiding the employee list + toggle)
for users without `hr.employees.view`. De Minimis and Year-End Leave were checked
safe: only `hr_officer` holds `payroll.adjustments.create` (and has
`payroll.periods.view`), and `leave.types.manage` is already in the Leave page's
`anyOf`.

**API path bugs found while wiring the modals (pre-existing, fixed):**
- `spa/src/pages/payroll/de-minimis` called `/payroll/de-minimis` but the backend
  registers the group under `de-minimis` (no `payroll/` prefix — unlike statutory's
  `payroll/statutory`). The old page would have 404'd on load. Fixed to `/de-minimis`.
- `spa/src/api/leave` called `/leaves/types/process-year-end` but the live route is
  `POST /leaves/process-year-end`. The old Year-End Leave page would have failed on
  submit. Fixed.

### Sidebar sweep 2 — Sales & CRM, Production, Procurement, Warehouse (2026-08-08)

Second lean pass over the remaining sections. Production (3) and Procurement (4)
were already lean — all entries feed chain 1/2 or are ADV-protected. Two items
were consolidated:

| Removed from sidebar | New home | Why |
|---|---|---|
| **AR Customers** (`/accounting/customers`) | **One 'Customers' entry kept under Sales & CRM** (`/crm/customers`) | **Same table + controller.** Both routes hit `App\Modules\Accounting\Controllers\CustomerController` on the single `customers` table with the same permission. The CRM entry is now gated on `accounting.customers.view` (the shared backend permission) — `crm.sales_orders.view` is only held by wildcard `system_admin`, so the old CRM entry was invisible to `finance_officer` anyway. The `/accounting/customers` URL + routes stay live for inbound links (complaint detail, invoice/customer pickers) |
| **Movements** (`/inventory/movements`) | **View toggle on the Stock Levels page** (Stock Levels ↔ Movements, deep-linkable via `?view=movements`) | 109-LOC read-only audit trail, 0 live rows, no badge, no actions. Stock Levels was already the page the dashboard + Items page link to |

**Also fixed (pre-existing):** `dashboardLinks.ts` 'Pending Transfers' KPI pointed
at `/inventory/stock-movements` — a path that was **never a registered route**.
Now `/inventory/stock-levels?view=movements&type=transfer&pending=1`.

**Verified:** tsc + ESLint clean · 877 routes · Sidebar permissions test 4/4 ·
111 Inventory/Stock tests pass · no live `/inventory/movements` references
(remaining ones are inside the PASS-3 hidden stock-transfers dead code).

**Review fixes:** (1) removed the now-dead `type → movement_type` URL-mapping
effect from `StockMovementsTab` — the deep-link `?type=transfer` now flows through
the `initialMovementType` prop, so the old `useEffect` could never fire. (2) The
Stock Levels view toggle now writes `?view=` back to the URL (`navigate(...,
replace)`) so refresh and back-navigation restore the movements view, matching the
codebase's `useUrlFilters` convention.

### Sidebar sweep 3 — Overview, Quality, Finance, Maintenance, Assets, Administration (2026-08-08)

Final lean pass. Quality (4), Finance (8), Maintenance (3), Assets (1) were clean
— all load-bearing or already hidden. Overview and Administration had one item
each:

| Removed from sidebar | New home | Why |
|---|---|---|
| **Exceptions** (`/exceptions`) | **'Exceptions' scope toggle on the Action Center page** (deep-linkable via `?scope=exceptions`) | The page was a *pre-filtered copy of Action Center*: `ActionCenterService::exceptions()` is literally `for()` minus the `approval` category. Same queue, same rows — a second sidebar entry for one dataset. Action Center already had category filters + search, so the scope toggle is a natural fit. **Triage preserved, not dropped:** the workbench's bulk Claim / Acknowledge / Snooze 4h / Resolve toolbar + row checkboxes (via `actionCenterApi.updateTasks`) were folded into the exceptions scope verbatim. Page file + `actionCenterApi.exceptions` kept as dead code; backend endpoint kept live |
| **Depreciation** (`/admin/depreciation`) | **'Run depreciation' button/modal on the Fixed Assets page** (`DepreciationRunner.tsx`) | 62-LOC single-action page (year + month + one button) wearing a sidebar slot under Administration while gated on `assets.depreciation.view` — an *asset* operation in the wrong section. Converted to a modal, same permission gate, same idempotent runner |

**Permission safety checks (both folds):** `dashboard.action_center.view` and
`dashboard.exceptions.view` are auto-granted to **every** role in the seeder's
universal merge, so folding Exceptions into the (equally universal) Action Center
locks nobody out. `assets.depreciation.view` is only held by wildcard
`system_admin` — the relocated button uses the identical gate, so visibility is
unchanged.

**Review fixes:** (1) bulk triage (Claim/Acknowledge/Snooze 4h/Resolve + row
checkboxes) was **preserved** in the Action Center exceptions scope — the fold
would otherwise have dropped the workbench's core capability. (2) The
DepreciationRunner month default comment corrected: `getMonth()` is 0-indexed so
"7" in the 1-based input = July (previous month) — behavior unchanged, comment
now accurate.

**Verified:** tsc + ESLint clean · 877 routes · Sidebar permissions test 4/4 · no
live `/exceptions` or `/admin/depreciation` references (page files kept) ·
`asset-depreciations/run` backend endpoint still live for the modal.

### PASS 7 — Warehouse Map + Stock Count merged (2026-08-08)

The two ADV8 warehouse views were two sidebar entries for one warehouse: the
**Map** (read-only colour-coded bin grid answering "what's in every bin now")
and **Stock Count** (physical-count sessions — scope, freeze, record, variance
approval, auto-adjustments). A count session *is* walking the map's bins, so
Stock Count was folded into the Map page behind a **Map | Stock Count** toggle
(the same view-toggle pattern as Stock Levels ↔ Movements):

| Removed from sidebar | New home | Why |
|---|---|---|
| **Stock Count** (`/inventory/stock-count`) | **`/inventory/warehouse-map`** — Stock Count toggle (`?view=count` deep-link) | Same warehouse, same zone/bin geometry. The Map is the working surface the count workflow naturally sits on; the merge also lets future counts be driven from the grid |

**Improvements landed with the merge:** bin-grid **legend** (the colour coding
was previously unexplained), **bin search** (by bin code, current item code or
name, or block reason — reset on zone switch), and the page now switches
cleanly between the two modes.

**Kept live, not removed:** the `/inventory/stock-count` **route** — the
`WarehouseScanController` barcode-scan flow returns a `record_count` URL there,
so it now renders the merged page in count view (an alias, not a 404).
`stock-count.tsx` was rewritten as a named `StockCountManager` (compact toolbar,
no PageHeader) — same pattern as `LeaveTypesManager` / `ItemCategoriesManager`.
The sidebar permissions test was updated: the sidebar now shows one entry
(Warehouse Map, `inventory.view`); the Stock Count tab inside the page is gated
on `inventory.stock_count.view`, and the alias route keeps its stock_count gate.

**Transfer Orders kept separate:** it is an *action* workflow (create → execute
→ cancel intra-warehouse moves), not a view — merging it would bloat the page.

**Verified:** tsc + ESLint clean · Sidebar permissions test 4/4 · live browser
check of the Map | Stock Count toggle, legend, and bin search.

### Hardening — Procurement: PO must trace to an approved PR + item-sourced PR lines (2026-08-08)

User-reported: (1) a PO could be created with no PR behind it; (2) the PR create
form showed bare item codes, empty description, and an empty est. unit price
that had to be typed by hand.

| Rule | Enforcement |
|---|---|
| **PO requires an approved PR** | `PurchaseOrderService::create()` now throws unless a PR id is present AND the PR is `approved` (same gate as `convertFromPr`). `StorePurchaseOrderRequest` makes `purchase_request_id` required. The SPA's **New PO** flow now opens a **source-PR picker** (approved, unconverted PRs) on the create page when no `?pr_id` is linked — no blank PO form. **Exemption:** `AutoPurchaseOrderService` (Task A8 critical-shortage auto-PO, VP-routed) creates its own POs outside this path and is unchanged by design |
| **PR lines inherit from the Item record** | SPA PR create: dropdown now shows `code — name`; picking an item auto-fills **description** (item's `description`, falling back to name), **unit** (UOM), and **est. unit price** (standard cost) and locks all three (read-only) — only `— ad hoc —` lines stay free-form. Backend `PurchaseRequestService` (create + update) mirrors the same defaults for catalog lines when the client omits them, matching `AutoReplenishmentService`'s source of truth. PO create dropdown labels also show `code — name` |
| **Auto-PO on PR final approval** | New `ConsolidatePurchaseOrders` listener (the class the `PurchaseRequestApproved` event docblock already anticipated): when a PR's last approval lands, it is converted straight into PO(s) with zero re-typing. Lines are grouped by their `suggested_vendor_id` (pre-filled from the preferred approved supplier on submit) via the existing `convertFromPr` — one PO per vendor. **Skip rule:** if ANY line lacks a suggested vendor or a unit price, the whole PR is left `approved` for the manual convert flow (no partial conversion). POs are created **Draft** so the normal PO approval chain (submit → VP threshold → PPAP gate → budget) still applies; the PR flips to `converted` exactly like manual conversion, which also makes a stale event dispatch idempotent. Always-on (no settings toggle, per user). Attribution: PR requester, falling back to the shared `SystemActorService` (extracted from `AutoReplenishmentService`, which now uses it too — same `system.automation.actor_roles` setting). 9 new tests (`ConsolidatePurchaseOrdersTest`: draft PO, multi-vendor grouping, missing-vendor skip, missing-price skip, double-dispatch + exists()-guard idempotency, system-actor fallback, real approve()-path end-to-end, event binding) + the chain-wiring test resolves the listener from the container |
| **Follow-ups: re-open, banner, skip alert** | (1) **PR re-opens on PO close-out** — `PurchaseOrderService::cancel/reject/delete` now flip a `converted` PR back to `approved` when the PO being closed was its **last live link** (auto-PO drafts are the common case); a live sibling PO keeps the PR converted. (2) **Auto-conversion banner** — listener-created POs are stamped `is_auto_generated` (exposed on the PR resource's `purchase_orders`); the PR detail page shows a success banner listing the linked PO(s) when any is auto-generated. (3) **Skip alert** — when auto-conversion is skipped (missing supplier/price, or a conversion failure), the purchasing audience (`purchasing.purchase_request_approved.notification_roles`) gets a `chain.pr_auto_convert_skipped` notification with a link back to the PR. **Bonus fix:** `PurchaseOrderService::reject()` mass-assigned non-fillable `status` (would throw in production) — now `forceFill`, caught by the new `PurchaseOrderReopenPrTest` (5 tests) |

**Tests updated for the new rule:** `PoVendorSodTest` + `BudgetEnforcementWiringTest`
now create an **approved** PR before posting/creating POs (status is non-fillable,
so `forceFill(['status' => 'approved'])`). `ApprovalWorkflowTest` is PR-side only
— untouched. `AutoPurchaseOrderService`/MRP tests unaffected.

---

## Auto-GRN on PO sent (2026-08-08)

The Procure-to-Pay chain now stages the expected goods receipt the moment a PO
leaves for the supplier — mirroring the auto-PO-on-PR-approval link.

| Piece | What changed |
|---|---|
| `PurchaseOrderSent` event | New; fired from `PurchaseOrderService::markAsSent()` **and** the B2B supplier acknowledge path — both "PO went out" moments |
| `CreateDraftGrnOnPoSent` listener | Queued; stages a **Draft GRN** (one line per PO line, zero quantities, no stock/QC). Idempotent: reuses the existing draft, skips when the PO already has a non-draft GRN, no-ops on stale/cancelled copies |
| `GrnStatus::Draft` + `label()` | New status. **Also fixes a latent 500:** `PurchaseOrderResource` called `$g->status?->label()` on a `GrnStatus` that had no `label()` method — any PO detail page with GRNs would have crashed |
| `GrnService::createDraftForPo()` | Pre-fills lines from the PO (unit cost copied), null received-date/bin — the goods haven't arrived yet |
| `GrnService::finalizeDraft()` | Warehouse assigns bins + actual quantities → `pending_qc` → synchronous incoming-QC + `GoodsReceiptNoteCreated` event. Reuses `create()`'s exact over-receipt cap + PO-total advance + status refresh |
| `PATCH /inventory/grn/{grn}/finalize` | New endpoint behind `inventory.grn.create`; `FinalizeGrnRequest` validates line ownership against the GRN's PO and enforces `quantity_received >= 0.001` |
| Migration `2026_08_08_100000` | `received_date`/`received_by`/`location_id` nullable so a draft can exist pre-arrival |
| SPA | GRN detail page draft view with per-line location + qty finalize UI (deep check on skipped rows — each filled line carries its own `purchase_order_item_id`); GRN chain builder gains the draft step; list status chip + `draft` variant |

**Semantics (deliberate):** the draft GRN is an *expected receipt*, not a physical claim
— stock is untouched until finalize. Drafts are excluded from every live surface
(pending-GRN KPI counts `pending_qc` only; `GrnGlPostingService` guards on
accepted statuses; a non-draft GRN on the PO blocks re-staging).

**Verified:** `DraftGrnOnPoSentTest` (4 tests: sent→draft, idempotency, finalize→
pending_qc+QC, over-receipt cap) · Inventory 90 · Purchasing+Chain 90 · tsc +
ESLint clean · GRN list page smoke-checked live (Draft chip renders).

## Auto-bill on GRN accept (2026-08-08)

Third P2P link, mirroring the first two: when a GRN is **fully accepted** (goods
moved into stock, inventory JE posted), a draft supplier bill is pre-created for
the payables team — no re-typing, and **nothing hits the ledger until a human
posts it**.

| Piece | What changed |
|---|---|
| `GoodsReceiptNoteAccepted` event | New; fired from `GrnService::accept()` **and** `acceptInternal()`, and — review catch — from `partialAccept()` when the partial accept ends up covering every line (it transitions to `Accepted`; without this the all-full partial path silently skipped the bill). Fired via `DB::afterCommit` so the stock/GL rows are visible |
| `AutoCreateBillOnGrnAccepted` listener | Queued; calls `BillService::createDraftForGrn()`. Idempotent (guard inside the service), attributes to `SystemActorService`, and **never throws** — a misconfigured expense account logs a warning and leaves manual bill entry available |
| `BillService::createDraftForGrn()` | Draft bill from accepted lines: qty = `quantity_accepted`, unit price = GRN unit cost, description from item/PO-line, **expense account from `accounting.default_expense_account_code`** (same setting as the B2B portal's `submitInvoice`), vendor terms → due date, **vatability inherited from the PO's `is_vatable`** (not the company-wide flag — a VAT-exempt PO must not spawn a VAT bill). `BillStatus::Draft`, no JE |
| `BillService::postDraft()` | The human step: `assertPostingAllowed(date)` → builds the AP/expense JE (DR expense + VAT input, CR AP) → flips bill to `unpaid`. Shares `postBillToGl()` with the manual `create()` path so the two can never drift |
| `BillStatus::Draft` | New status; `isOverdue`/`agingBucket` treat drafts as not-yet-due (a draft must not age) |
| Migration `2026_08_08_100001` | `bills.goods_receipt_note_id` FK — links the auto-bill to its source receipt (3-way-match lineage) |
| `PATCH /api/v1/bills/{bill}/post` | Post-draft endpoint; Bill detail page gains the **Post bill** action; GRN detail page shows an auto-bill banner |
| SPA | `BillStatus` type + draft chip, `billsApi.postDraft`, bill detail action, GRN detail banner + **Post button**, PO detail Billing panel shows the **draft bill with an inline Post button** (marked "auto-created from GRN"), **PR detail auto-bill banner** (draft bills on linked POs, each with a Post button + its source PO link) — the whole chain is reviewable from any step · `bills` on the GRN resource, `bill` on each PR-linked PO |

**Semantics (deliberate):** the draft bill is an *expectation of payment*, not a
payable — AP is only created on post. `partialAccept` → `PartialAccepted` stays
billing-silent (nothing accepted in full yet).

**Verified:** `AutoCreateBillOnGrnAcceptedTest` (7 tests: accept→draft pre-filled,
idempotency, partial-skip, **all-full partial→bill**, post-draft JE + balance,
vendor-terms due date, missing-expense-account never-throw) · Accounting 57 ·
Inventory 90 · Purchasing+Chain 90 · tsc + ESLint clean.

## Final P2P link — bill paid → chain settled (2026-08-08)

The GL payment entry already existed (`BillService::recordPayment()` posts
DR AP / CR Cash inline); what was missing was the **live chain completion**
so the last link behaves like the others:

| Piece | What changed |
|---|---|
| `ChainDefinitions` `bill` chain | New steps `draft → posted → partial → paid → closed` + status map (`unpaid`→`posted`, `paid`→`paid`, `cancelled`→`closed`) + `allowedTypes` + `viewPermission('bill')` = `accounting.bills.view`. **Robustness fix:** `configured()` now **merges code defaults with the stored `workflow.chain_definitions` snapshot** — a stale settings row (seeded before a chain existed) can no longer block a new chain from resolving; admin overrides for known types still win |
| `ChainBroadcaster` | `Bill` registered (type `bill`, doc field `bill_number`) so `recordPayment` / credit-note application can broadcast real-time steps |
| `BillService::recordPayment()` | Broadcasts the chain step after commit (partial → `partial`, settling → `paid`) — the bill detail page advances without a manual refresh |
| `BillService::postDraft()` | Also broadcasts — posting a draft bill advances the chain to the `posted` step (draft → posted → paid), matching the GRN pattern of broadcasting every status transition |
| `CreditNoteService::apply()` | A supplier credit settling a bill broadcasts the same chain step (wasChanged guard) |
| SPA | `ChainEntityType` gains `'bill'`; bill detail page subscribes via `useChainProgress('bill', id, …)` alongside its existing Procure-to-Pay `ChainHeader` |
| Tests | `ChainDefinitionsTest` +4 (bill posted/partial/paid/cancelled, six allowed types, bill view permission) |

**Verified:** ChainDefinitions 13/13 · Accounting + Chain 70 · tsc + ESLint clean ·
bill detail page smoke-checked live (chain header renders, zero console errors).
The stale dev/test settings rows were refreshed to the new defaults (the merge
fix makes this non-fatal, but keeps the stored snapshot truthful).

### P2P compact stepper (2026-08-08) — PR → PO → GRN → Bill → Paid on both ends

- **New shared builder** `spa/src/lib/chains/p2p.ts` — `buildP2pChain(input)`
  renders the whole chain as a compact 5-step stepper with **per-step links**
  to each source document (PR, PO, GRN, Bill). State derivation follows the
  `buildGrnChain` convention: only **accepted** (`accepted`/`partial_accepted`)
  receipts advance the GRN step to done, and the Bill step only turns active
  once goods are accepted — a pending-QC receipt shows "awaiting QC" instead of
  a premature bill.
- **PO detail** swapped its detailed 8-step chain panel for the compact
  `buildP2pChain` (approval info stays in the header + approval records).
  `buildPurchaseOrderChain` is marked deprecated/superseded, kept as dead code
  per the keep-code policy.
- **PR detail** gained a "Procure-to-pay chain" panel (shown when purchase
  orders exist) aggregating GRNs + bills across all linked POs — downstream
  completion visible from the very first step of the chain.
- **Backend:** `PurchaseRequestService::show()` eager-loads
  `purchaseOrders.goodsReceiptNotes` (+ existing bills/vendor); the resource
  exposes each PO's `grns` (id, grn_number, status) alongside the staged `bill`.
- **Verified:** tsc + ESLint clean · Purchasing 79/79 · live browser — stepper
  renders on both pages with zero real JS errors.

### P2P compact stepper — now on all four chain pages (2026-08-08)

`buildP2pChain` (PR → PO → GRN → Bill → Paid) now drives the chain panel on
**every** step of the chain: PR, PO, GRN, and Bill detail pages all render the
same 5-step cross-document view, so downstream completion is visible and
clickable from anywhere.

- **GRN detail** — panel swapped from `buildGrnChain` (PO → GRN → QC → Stock)
  to the compact stepper; the GRN step derives from the receipt's own status.
  `buildGrnChain` marked `@deprecated`, kept as dead code.
- **Bill detail** — page-local `buildBillChain` (status-only 3 steps) removed;
  the panel now uses the shared stepper with the source GRN + PO + PR exposed.
- **Backend:** `GrnService::show()` and `BillService::show()` eager-load
  `purchaseOrder.purchaseRequest` (constrained columns — `purchase_request_id`
  keeps the nested load resolvable without pulling the full PO row);
  `BillResource` exposes `goods_receipt_notes` (source receipt) and
  `GoodsReceiptNoteResource` exposes `purchase_request` on the PO.
- **Derivation hardening (review catch):** a **rejected** GRN now renders the
  GRN step as `pending` (stuck) instead of `active`; and when a **bill exists
  without a linked receipt** (manual bill), the GRN step is treated as
  satisfied — no more misleading "goods not received yet" on a bill that
  obviously received goods.
- **Verified:** PHP lint · tsc + ESLint clean · Inventory+Accounting 149/149 +
  targeted Bill/GRN 31/31 · live browser — stepper renders on GRN + Bill pages
  with zero JS errors.

### Order-to-Cash parity — the customer side gets the same treatment (2026-08-08)

The supplier side (P2P) had been fully automated + stepper-ified; this pass
brings the customer side (O2C) to parity. **Key discovery: the auto-invoice on
delivery confirm already existed** (`DeliveryService::confirm()` creates a draft
AR invoice under a row lock and fires `DeliveryConfirmed` with the invoiceId) —
so the work focused on the genuinely missing pieces:

- **New shared compact stepper** `spa/src/lib/chains/o2c.ts` — `buildO2cChain`
  (SO → Delivery → Invoice → Payment) with per-step links, rendered on the **SO,
  Delivery, and Invoice** detail pages (the SO page keeps its MRP API chain
  above it; the Invoice page drops its page-local `buildInvoiceChain`). Delivery
  step is satisfied only on delivered/confirmed (or when an invoice exists);
  **cancelled deliveries render as stuck (`pending`), not in progress** — mirroring
  the P2P rejected-GRN convention. Draft invoices (no number until finalize)
  render as `(draft)` in the tooltip, never "Invoice  issued".
- **`invoice` chain definition + live broadcasts** — ChainDefinitions gains
  `draft → finalized → partial → paid → closed` (status map incl.
  cancelled→closed); ChainBroadcaster registers Invoice; `finalize()` broadcasts
  `finalized` and `recordCollection()` broadcasts `partial`/`paid` via
  `DB::afterCommit`; the Invoice detail page now subscribes via
  `useChainProgress('invoice', …)` (same as the bill page).
- **Draft-invoice banner + Finalize action** on the SO and Delivery pages
  (mirrors the P2P auto-bill banner): "Customer invoice auto-created —
  {number/(draft)} · {amount}" with a Finalize button gated on
  `accounting.invoices.create` (same as the backend route), invalidating both
  the source page and `['accounting','invoices']`.
- **Data threading:** Invoice model gains `delivery()` BelongsTo;
  `InvoiceService::show()` eager-loads `salesOrder:id,so_number` +
  `delivery:id,delivery_number`; `InvoiceResource` exposes `sales_order` +
  `delivery` (relationLoaded guards — index lists stay cheap).
- **Deprecated/kept:** `buildDeliveryChain` superseded by `buildO2cChain` (kept
  as dead code per policy). The delivery page's lifecycle detail (Scheduled →
  Loading → In Transit) is no longer in the chain view — status chips + the
  advance flow remain, deliberate for the "same view on every page" goal.
- **Verified:** PHP lint · tsc + ESLint clean · ChainDefinitions 14/14 ·
  Accounting 59/59 · CRM + SupplyChain 93/93 · live browser — stepper + banner
  render on all three pages with zero JS errors; demo chain exercised
  end-to-end: SO → delivery → draft invoice → **Finalize → paid** (JE +
  collection posted, chain broadcasts fired). Demo records kept for the
  walkthrough: `SO-DEMO-O2C-0001` / `DLY-DEMO-O2C-0001` / `INV-202608-0001`.

### Customer-side returns credit note — draft per-line (2026-08-08)

The O2C twin of the supplier credit note. **Discovery:** auto credit-note on
customer return **already existed** (`ReturnRequestService::dispose →
createCreditNote`) but wrote a single aggregate line and **finalized it
immediately** (GL posted without a review step). Reworked to the same
review-then-post pattern used by the auto-bill / auto-invoice chains:

| Change | Detail |
|---|---|---|
| **Draft, not finalized** | `createCreditNote()` builds the credit note and stops at `draft` — no finalize call, no JE. Finance reviews + posts from the returns detail page |
| **Per-line detail** | One line per creditable returned item (disposition ≠ scrap/return_to_supplier), amount = settled returned qty × unit price (`creditableAmount`), revenue account from `product.revenue_account_id` ?? default, description = product name + RMA no |
| **SPA banner + Finalize** | Returns detail page shows a "Credit note auto-created (draft)" banner with a Finalize button (gated `accounting.credit_notes.manage`, same as the backend route) |
| **Re-entry guard (verified)** | `dispose()` already throws on `disposition_status === 'disposed'` under `lockForUpdate` — a second dispose cannot mint a second credit note |
| **Supplier-return replacement PO fix** | The PR-gate rule (PO must come from a PR) blocked the system-generated replacement PO that supplier returns create. `PurchaseOrderService::create()` gains a third `bool $systemGenerated = false` param — only automation paths pass `true`; user-facing controllers never set it. The replacement PO is marked `is_auto_generated => true` |

**Tests:** `DispositionTest` customer-credit case now asserts `status = draft`,
`journal_entry_id = null`, `return_request_id` set, lines ≥ 1. Run green:
ReturnManagement 31/31 · Purchasing 79/79 · Accounting. tsc + ESLint clean.

**Verified live:** demo RMA `RMA-DEMO-O2C-0001` → dispose → **draft** 800.00
credit note (1 per-line entry) → returns page banner + Finalize →
`CN-202608-0002` **finalized** with balanced JE (dr = cr = 800.00).

---

## Customer returns — restock at disposition time (2026-08-08)

**Ask:** "When a return line is disposed as 'restock', auto-create the inventory
receipt/adjustment to put the goods back into stock."

**Gap found:** restock already existed but only at the *separate* `complete()`
step (AdjustmentIn movement + location picker, M-36). Disposing a line as
restock never touched stock, and the UI never showed goods coming back — the
movement was invisible until a later manual step.

**Change — restock now happens at `dispose()` (O2C twin of GRN acceptance):**

| Area | What changed |
|---|---|---|
| Service | `dispose()` gains `?int $locationId` (5th, optional param). New `restockAtDispose()` auto-creates the **AdjustmentIn** movement for restock/rework lines into the declared location, stamps `stock_movement_quantity` per line, sets `stock_movement_id`. `moveLine()` helper shared with `complete()`; `hasPendingMovement()` decides if completion still needs a location |
| Rule | Customer return with any restock/rework line **requires** a location at dispose (service throws + `DisposeReturnRequest` 422) — goods cannot re-enter the ledger without a warehouse |
| Complete | Now idempotent: lines already stamped `stock_movement_quantity` are skipped (never move twice). Supplier `return_to_supplier` still ships out at completion; location only required when a line actually still needs to move |
| API | `ReturnRequestResource` exposes `moved_quantity` (sum of per-line moved qty) + `stock_movement` (with `to_location`/`from_location` codes); item resource exposes `moved_quantity` per line (renamed from `restocked_quantity` when the supplier side landed) |
| SPA | Dispose dialog gains a warehouse-location picker (appears the moment any line is Restock/Rework; submit disabled until chosen). Detail page shows a **"Goods restocked into inventory"** banner (qty + location + link to stock movements) and a per-line **Restock ✓** chip. Complete modal no longer asks for a location on customer returns (already restocked); supplier returns keep the picker |

**Latent crash fixed en route:** the old `complete()` resolved a line's item via
`$line->product?->items()->first()?->id` — but `Product` has **no** `items()`
relation (CRM products and inventory items are separate entities), so a
product-only RMA line would have 500'd the moment it reached a movement.
Replaced with `resolvableItemId()` (line `item_id`, else null + warning log).

**Tests:** `CustomerReturnRestockOnDisposeTest` (dispose restock without location
→ 422; restock + location → immediate AdjustmentIn, stock up by returned qty,
line stamped, credit note still draft; complete after dispose → no double
movement, no location needed; scrap → no movement).
`ReturnRequestCompleteRequiresLocationTest` rewritten for the new contract.
`DispositionTest`/scenario dispose calls updated to pass the now-required
location for restock/rework.

**Verified:** PHP lint · tsc + ESLint clean · ReturnManagement 36/36 · live
browser: demo `RMA-DEMO-RESTOCK-0001` → Dispose → Restock + location → banner
"Goods restocked into inventory" + `✓ 8` chip → Complete (no location prompt)
→ Completed; ledger shows `adjustment_in` qty 8.000, on-hand up 8, zero JS
errors.

## Supplier returns — ship at disposition time (2026-08-08)

**Ask:** "Add the same dispose-time movement for supplier returns: when a line
is disposed as 'return_to_supplier', auto-create the ReturnToVendor movement at
dispose instead of waiting for complete."

**Change — `return_to_supplier` lines now leave stock at `dispose()` (P2P twin
of the customer restock):**

| Area | What changed |
|---|---|---|
| Service | `restockAtDispose()` generalized to `moveAtDispose()` — `shouldMove()` decides the side: customer restock/rework → **AdjustmentIn** in, supplier return_to_supplier → **ReturnToVendor** out. Runs for both types after `processSupplierDisposition()` (same transaction, full rollback on failure). **Fail-fast guard added at the top of `dispose()`**: a movement line without a location throws *before* the credit-note / replacement-PO work runs |
| Rule | Supplier dispose with any return_to_supplier line requires a location (form request 422 + service guard) — goods cannot leave the ledger without naming the warehouse |
| Complete | Already idempotent (`stock_movement_quantity`), so shipped lines are never moved twice; complete no longer needs a location from the fresh UI |
| API | `restocked_quantity` renamed to the direction-neutral **`moved_quantity`** (item + RMA root); `stock_movement` gains `from_location` (the shipping source) alongside `to_location` |
| SPA | Dispose dialog shows the location picker for supplier `return_to_supplier` lines too ("Warehouse location for returned goods"). Detail page shows a **"Goods shipped back to supplier"** banner (qty + `from_location` code) and a per-line **`{qty} out`** chip (danger variant) under a type-aware **Return** column. Complete modal shows the location picker only when a supplier line is actually still unmoved (`pendingSupplierShip`) |

**Tests:** `SupplierReturnShipOnDisposeTest` (dispose without location → 422;
dispose + location → immediate ReturnToVendor, stock down by returned qty, line
stamped, GRN reversed + credit raised; complete after dispose → no double
movement, no location). `SupplierReturnLifecycleTest` walks the whole workflow
with the movement asserted at dispose. `DispositionTest` supplier tests seed
shelf stock + pass the location. Review catch fixed: the no-lineage tests now
send a location so the lineage guard is genuinely exercised instead of being
short-circuited by the new location rule.

**Verified:** PHP lint · tsc + ESLint clean · ReturnManagement **39/39** · live
browser: demo `RMA-DEMO-SUP-0001` → Dispose → return_to_supplier + location →
banner "Goods shipped back to supplier" + `18 out` chip → Complete (no
location prompt) → Completed; ledger shows `return_to_vendor` qty 18.000,
on-hand 82.000 (100 − 18), zero JS errors. Dev-DB note: `tax.ph.vat_rate` was
missing from `settings` (the settings-hardening removed silent defaults), so
the vatable supplier credit note 500'd until it was set to `0.12`.

### Warehouse notification on customer-return restock (2026-08-08)

Disposing a customer-return line as **restock/rework** now alerts the warehouse
team the moment the goods land back in sellable stock (the `AdjustmentIn`
movement already created at dispose time).

- **New catalog type** `return.restocked` (Chain 1 · Order to Cash) in
  `NotificationCatalog::defaults()` — automatically surfaced in the
  notification-preferences UI via the existing merge-with-defaults mechanism.
- `ReturnRequestService` now injects `NotificationService`; `moveAtDispose()`
  calls `notifyRestock()` for customer restocks with the total moved quantity.
- **Recipients:** active users whose role holds `inventory.view` **plus
  wildcard `system_admin`** — a plain `whereHas('role.permissions', …)` would
  silently drop admins, since `system_admin` stores a `'*'` permission rather
  than the explicit slug (same blind spot `ArDunningService` has; avoided here
  deliberately).
- Message carries the RMA number, the moved quantity (trailing decimal zeros
  trimmed), and a deep link to the RMA detail page. Best-effort: wrapped in
  `try/catch` so a notification failure never rolls back the stock movement.

**Verified:** `ReturnRestockNotificationTest` **5/5** (warehouse user notified
with message+link, wildcard admin notified, outsider skipped, scrap fires
nothing, supplier dispose fires nothing) · NotificationCatalogTest still
scrapes and finds the new key · ReturnManagement **39/39** · live dev-DB run:
6 notification rows landed for real `inventory.view` holders + admin.

### Purchasing notification on supplier-return ship-out (2026-08-08)

The mirror of the restock alert: disposing a supplier-return line as
**return_to_supplier** alerts purchasing the moment the goods ship back out
(the `ReturnToVendor` movement already created at dispose time).

- **New catalog type** `return.shipped_to_vendor` (Chain 2 · Procure to Pay).
- `ReturnRequestService::moveAtDispose()` fires `notifySupplierShip()` for
  supplier returns (customer restocks still fire `notifyRestock()`); both now
  share one `sendMovementAlert()` envelope helper — recipients = active users
  holding the audience permission **plus wildcard `system_admin`**
  (`inventory.view` for restock, `purchasing.po.view` for ship-out).
- Message carries the RMA number, trimmed quantity, and a deep link to the
  RMA detail page. Best-effort: a notification failure never rolls back the
  stock movement.

**Verified:** `SupplierReturnShipNotificationTest` **4/4** (purchasing user
notified with message+link, wildcard admin notified, outsider skipped,
customer restock fires no supplier type — all through the real
PO→GRN→stock→bill fixture) · both notification suites + catalog test +
ReturnManagement **54/54** total · live dev-DB run: `return.shipped_to_vendor`
rows landed for real `purchasing.po.view` holders + admin.

