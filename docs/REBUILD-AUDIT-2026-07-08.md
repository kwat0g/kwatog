# Ogami ERP — Rebuild & Enhance Audit (2026-07-08)

> Lead auditor synthesis of 16 sub-agent investigations + 5 role walkthroughs + ground-truth
> discovery across all 23 API module dirs, 260+ migrations, ~746 tests, and the full SPA.
> Bar: **PILOT-CREDIBLE** (real PH-manufacturing / IATF 16949 / JP-parent failure modes matter).
> Every finding cites `path:line` or a doc heading. Severity is calibrated to a live pilot at a
> 200-employee Japanese-owned injection molder, not to a demo.

---

## 1. Executive Summary

Ogami ERP is, on inspection, **one of the strongest ERP thesis codebases we have audited** — three
chains are genuinely wired end-to-end, the IATF quality spine is real and is the single best
differentiator, the auth/security foundation is clean (no Bearer tokens, HTTP-only cookies, HashIDs,
universal lazy-loading, guard wiring correct), and the code is notably free of the classic Laravel
footguns (no money-as-float, no `DB::raw` with user input, no `$guarded=[]`). The typecheck passes,
all 69 sidebar links resolve, dark mode is clean.

The gap between this codebase and a real pilot is **not architecture — it is reachability, statutory
fidelity, and cutover tooling.** A recurring pattern dominates the findings: *the backend is
complete and tested, but no route or screen exposes it.* Payroll void, 3-way match, AR/AP aging,
accounting period close, De Minimis, PPAP, Calibration, stock-adjustment approval, machine/mold
onboarding, SSS R-3 — all are built and unreachable. The second pattern is **PH/JP statutory
depth**: the money math is right, but the filing artifacts (BIR 1601-C, Alphalist DAT, 2307/2550,
JPY consolidation) are stubs or absent. The third is **migration**: there is no way to load a real
Ogami's master data or opening balances — a hard go-live blocker.

### Top 10 findings (severity × blast-radius)

| # | Finding | Evidence | Sev |
|---|---|---|---|
| 1 | **Payroll void built but has no route/controller/button** — the one sanctioned correction path for a bad finalized run is unreachable | `PayrollPeriodService.php:451` vs no route in `Payroll/routes.php` | P0 |
| 2 | **3-way match engine 100% unreachable** — bill-create UI sends no `purchase_order_id`/`item_id`; AP clerk's headline duty is a phantom | `StoreBillRequest.php:21` vs `bills/create.tsx:98-113`; `ThreeWayMatchService.php` | P0 |
| 3 | **No master-data / opening-balance import** — a real Ogami cannot migrate off Excel/legacy; GL/AR/AP/inventory start at zero | routes grep = 0; `OGAMI-111/112`; `JournalEntryService` no opening path | P0 |
| 4 | **Payroll has zero maker-checker SoD** — one role runs create→compute→approve→finalize; period row has no `approved_by`/`finalized_by` | `PayrollPeriodService.php:216,369` (no actor arg); `0032:22` | P0 |
| 5 | **JPY / multi-currency / consolidation entirely absent** — the signature JP-parent CFO deliverable; every money column is currency-blind | `StatementOfAccountService.php:77` `'PHP'`; no `currency` column anywhere | P0 |
| 6 | **Statutory exports are 1-row stubs / wrong format** — 1601-C, 1604-CF summary-only; Alphalist is CSV not `.DAT`; no eBIRForms | `Bir1601CService.php:44-57`; `BirAlphalistService.php:78` | P0 |
| 7 | **No re-inspection after rework** — rework WO gets no `sales_order_id`, outgoing-QC listener skips it; reworked parts ship unverified (IATF §8.7.1.4) | `NcrService.php:268-299`; `TriggerOutgoingQC.php:42-43` | P0 |
| 8 | **No credit-memo instrument** — a partially-collected disputed invoice has no correction path; only RMA emits a negative-invoice hack | grep `credit.?memo` = 0; `ReturnRequestService.php:299`; `InvoiceService.php:260` | P1 |
| 9 | **AR/AP aging reports built, no route/UI** — CFO & AP clerk cannot answer "who owes what, aged" | `InvoiceService.php:339`, `BillService.php:350` — zero routes | P1 |
| 10 | **No EWT/2307 on bills, no VAT return (2550)** — Ogami-as-withholding-agent cannot issue supplier certs or file VAT | `Bill.php:23-42` (no EWT fields); grep `2307`/`2550` = 0 | P1 |

### Headline module verdicts

| Module | Verdict | One-line justification |
|---|---|---|
| Quality (specs/insp/NCR/CoC/SPC/CoPQ) | **KEEP + patch edges** | Strongest module; real AQL/tolerance/auto-NCR/SPC. Gaps only at rework re-inspection + quarantine + prod-reject entry. |
| Payroll (engine) | **KEEP engine / ENHANCE lifecycle** | Calc is excellent; lifecycle needs void route, maker-checker, OT-holiday factor, De Minimis UI. |
| Accounting (GL/AR/AP) | **ENHANCE** | JE maker-checker + period-lock solid; missing aging UI, credit memo, recurring JE, year-end close, EWT. |
| Multi-currency / consolidation | **BUILD (absent)** | Advertised differentiator; does not exist. Largest true build. |
| Inventory / WMS | **ENHANCE** | WAC + GRN + stock-count solid; UOM dead end-to-end, adjustment approval unreachable. |
| Purchasing | **ENHANCE** | Backend strong; 3-way match unreachable from UI, no backorder report. |
| CRM | **ENHANCE + finish pipeline** | SO/complaint/8D real; Leads/Opps/Quotes are full backend with zero UI/tests. |
| Production / MRP | **KEEP / ENHANCE UI** | Output + OEE + mold tracking real; machines/molds not creatable from UI, pause modal hardcoded. |
| HR / Attendance / Leave | **ENHANCE** | Core solid; separation pay absent, two conflicting year-end leave paths, biometric identity gap. |
| Migration tooling | **BUILD (absent)** | No cutover story; hard go-live blocker. |
| B2B / Portal | **REFACTOR (cosmetic)** | Works but never adopted design-system primitives (Chip/RHF/useQuery). |
| Reporting/export engine | **ENHANCE (wire it)** | Scheduler + column-selector built, wired to 1 of ~10 modules. |
| PDF templates | **ENHANCE** | CoC/PO/payslip strong; Official Receipt/DR/GRN missing, void watermark unwired. |

### If you only had 2 weeks (ranked severity × blast-radius / effort)

1. **REC-01** Expose payroll `void` (route + button + SoD actor) — engine exists; ~1 day; unblocks every bad-run recovery. (P0, S)
2. **REC-02** Wire 3-way match into bill-create UI (PO selector, `item_id` per line, match-snapshot view) — backend done; ~3 days; makes AP clerk's job real. (P0, M)
3. **REC-15** AR/AP aging report route + page — services done; ~2 days; recurring CFO/AP deliverable. (P1, S)
4. **REC-08** Re-inspection after rework (pass `sales_order_id` or lift the skip guard) — ~1 day; closes the single most audit-exposed IATF hole. (P0, S)
5. **REC-04** Payroll maker-checker (persist `approved_by`/`finalized_by`, reject actor==computer) — ~2 days; audit-blocking SoD. (P0, S/M)

---

## 2. Phase 1 — Ground-Truth Map (condensed)

23 API module dirs (CLAUDE.md documents 17): Auth, Admin, Edge, B2B, Dashboard, Landing are
first-class but undocumented in the module table. Migrations run to ~0260 (CLAUDE.md's "highest =
0197" note is stale). ~746 tests / 0 fail as of 2026-06-15 (backend later 857 per GAP-ANALYSIS).

**Cluster health (files → completeness is NOT 1:1):**
- **HR/Attendance/Leave/Loans** — substantial services (DTR 404L, LeaveRequest 345L, Loan 276L, Separation 250L). Recruitment/skills/succession/performance-review are full stacks with UI but **no tests**.
- **Payroll** — `PayrollCalculatorService` 731L, 16 Feature files, high coverage. De Minimis backend-complete, zero UI.
- **Accounting/Assets/Budgeting** — Invoice 481L, Bill 445L, JE 352L with period-lock. `OfficialReceipt` orphaned (no route). Period close/reopen backend-only.
- **Inventory/Purchasing/SupplyChain** — WAC core solid. Containers CRUD zero-UI; stock-adjustment approve unreachable; UOM conversion inert (no seed, no UI selector).
- **Production/MRP/Maintenance** — WorkOrderService 726L, real OEE/mold tracking. Machines/molds no create-edit UI. Two parallel scheduling systems (`production_schedules` vs `wo_operations`).
- **CRM/ReturnManagement/Forecasting** — SO 656L. Leads/Opps/Quotes pipeline: full backend, zero UI/tests. RM UI complete but no `feature:` guard.
- **Quality** — largest built module: 14 controllers, 18 services, 24 models, SPC/PPAP/Calibration/CoPQ/Traceability. PPAP + Calibration have no internal SPA UI.

### Doc/Code Drift register (explicit)

| # | Drift | Verdict |
|---|---|---|
| DR-1 | CLAUDE.md:478 "Loans: Zero interest" vs `AmortizationService::generateWithInterest` + `SssLoan`/`PagibigLoan` enum. Code actually hardcodes `interest_rate=0` (`LoanService.php:137`) → the interest path is **speculative dead code**. | Delete or wire |
| DR-2 | CLAUDE.md:81 cuts "fiscal period LOCKING" but it is **fully built** (`AccountingPeriodService`, OGAMI-001) and enforced. Cut is stale (prior DRIFT-4). | Keep, build UI |
| DR-3 | CLAUDE.md:484 "Never unlock finalized" vs `PayrollPeriodService::void()` reverse-and-void escape hatch. | Update doc |
| DR-4 | CLAUDE.md:64 CRM omits the entire Leads/Opps/Quotes/Commission pipeline that exists in code. | Document or cut |
| DR-5 | CLAUDE.md:83 cuts "customizable dashboards" + "saved-view scheduling" + activity-feed limit; all three partially shipped (`DashboardLayoutService`, `ScheduledExport*`, `ActivityFeedController`). | Reconcile |
| DR-6 | `0245_add_interest_rate_to_employee_loans.php` body actually adds `government_reference_no`; interest_rate lives at `0029:19`. Misleading filename. | Cosmetic |
| DR-7 | Migration-number note (0197) stale; cluster uses 0201–0260. | Cosmetic |
| DR-8 | `plant_manager` role slug supposedly killed, but `PlantManagerDashboardService` + `dashboard/plant-manager.tsx` keep it alive. | Naming drift |

---

## 3. Phase 2 — Frame

### Domain reality
- 200+ employees, FCIE Dasmariñas Cavite, IATF 16949, tier-1/2 to Toyota/Nissan/Honda/Suzuki/Yamaha.
- Semi-monthly payroll, monthly + daily-rated pay types, PH statutory (SSS/PhilHealth/Pag-IBIG/BIR/DOLE) **plus** JP parent JPY-translated reporting.
- Imports resin (JPY/USD suppliers, landed cost), sells finished plastic parts (mostly B2B, so retail senior/PWD is low-impact).
- Automotive customers issue rolling delivery schedules against blanket POs — amend-after-partial-delivery is routine, not exceptional.

### Non-functional bar (explicit numbers)
- **RPO ≤ 15 min** target vs **24 h** actual backup cadence (9× behind) — `db:backup` daily 03:17 (`console.php:164`).
- **RTO ≤ 30 min** target, **untested** — restore drill log has only a placeholder row (`RESTORE-DRILL.md:109`). "A backup you never restored is not a backup."
- Concurrency/load: `Load/concurrent-payroll.js` exists; broader load untested.
- BIR books retention: **10 years** (NIRC §235) vs audit-prune default **12 months** archive window (`PruneAuditLogs.php:39`) — archive-only, but no written retention schedule.

### Competitive anchor (where a real buyer would compare)
- **Odoo / SAP B1 / NetSuite** all ship: multi-currency + consolidation, AR/AP aging as first-class reports, credit notes, recurring/reversing journals, period close UI, bank rec (explicitly cut here — acceptable), and **data-import wizards**. Ogami's gaps 3/5/6/8/9/10 are exactly the table-stakes an evaluator from any of those worlds will probe first. Ogami's *lead* over them is the depth of the IATF quality spine (AQL actual-measurement CoC, SPC run-rules, auto-NCR→replacement-WO) — that is the moat and must be protected.

### Role walkthroughs — dead ends (verified)
> Five named roles (Maria, Ben, Joel, Liza, Tanaka) + a cross-cutting Warehouse/PPC lens.
- **Maria (HR/payroll clerk):** cannot void a bad finalized run (built, unreachable); backdated OT after finalize collapses to a blunt free-text "underpayment"; De Minimis has no screen; no payroll register export.
- **Ben (AP clerk):** cannot link a bill to a PO — 3-way match never runs from UI; cannot view a match/variance snapshot; no AP aging; no credit memo; no backorder visibility.
- **Joel (production supervisor):** cannot escalate a high-reject WO into an NCR (no `work_order_id` source); pause reason/category hardcoded to "breakdown/Manual pause" (poisons OEE Pareto); no standalone downtime screen; cannot register a replacement mold.
- **Liza (QC inspector):** the AQL + actual-measurement spine is genuinely built and pilot-credible (`AqlSampleSizeService.php:20-53`, `InspectionMeasurement::evaluate()` at `Models/InspectionMeasurement.php:57-63`, data-driven CoC at `CoCService.php:66-115`) — protect it. But the shop-floor plumbing around it walls her daily: inspection create form collects only stage+product+batch, never binds to the source WO/GRN/delivery though the column exists (`inspections/create.tsx:29-31` vs `0089_create_inspections_table.php:32-33`) → traceability is typed by hand; no hold/quarantine action to segregate failed stock (grep `quarantine|on_hold` = zone enum only); no re-inspection-after-rework link (grep `reinspect|source_inspection` = 0); CAPA effectiveness verify is API-only, no screen (`routes.php:96,106` vs no UI in `pages/quality`); no gauge/instrument field on measurements (IATF 7.1.5 exposure); CoC is derived on the fly, never persisted as a queryable record (`CoCService.php:154-157`, no `certificates` table).
- **Tanaka (CFO):** cannot produce a JPY-translated TB/BS/IS; no portfolio AR/AP aging; cannot close/reopen a month from the app; no recurring JEs; BIR 2307/2550 unavailable.
- **Warehouse/PPC (cross):** UOM conversion inert (resin ordered in BAG cannot be received in bags); stock adjustments createable but never approvable from SPA; machines/molds seed-only.

### Differentiation check — real vs advertised-but-stubbed

| Differentiator | Reality | Leverage |
|---|---|---|
| IATF quality spine (AQL actual-measurement, auto-NCR, CoC, SPC) | **REAL — protect it** | Highest. The moat. |
| Full PH statutory | **Money math real; filing artifacts stub/absent** | High — fidelity gap is the credibility risk. |
| JP parent JPY consolidation | **Advertised, does NOT exist** | Highest claim-vs-reality gap. Retract or build. |
| Failure-mode handling (idempotency, period lock, reapers, maker-checker) | **Mostly real; idempotency + optimistic-lock absent on financial writes** | Medium. |
| Shop-floor mobile PWA | **Only driver PWA; factory PWA partial** | Medium. |
| Migration/opening-balance tooling | **Absent — pilot blocker** | High. |

---

## 4. Phase 3 + 3.5 — Findings by Bucket

Citations condensed; full detail lives in the per-finder appendices this report synthesizes. Only
the load-bearing `path:line` anchors are retained.

### Bucket A — Missing modules / absent capability areas
- **A3 Multi-currency/JPY/consolidation** — absent; `StatementOfAccountService.php:77` hardcodes PHP; no `currency`/`exchange_rate` column in any migration. HIGH.
- **A8 Credit memo / supplier credit note** — absent; only `ReturnManagement` emits a `debit_note` (`ReturnRequestResource.php:74`), wrong direction. HIGH.
- **A1 Expense-claim / cash-advance liquidation** — absent (grep `liquidation|reimbursement|expense_claim` = 0). BIR-relevant for 200 staff. HIGH.
- **A5 MRB / quarantine routing** — `WarehouseZoneType::Quarantine` enum exists (`WarehouseZoneType.php:13`) but is **dead** — never referenced by any service. IATF §8.7 gap. MED-HIGH.
- **A7 Backorder / open-PO visibility** — absent; POs go `PartiallyReceived` but no open-qty report. MED.
- **A9 Recurring / auto-reversing JE** — absent (`reverse()` manual-only, `JournalEntryService.php:262`). MED.
- **A2 Petty cash / revolving fund**, **A6 Gate pass**, **contract-renewal alerts** — lower priority, PH-common but deferrable.
- **Verified PRESENT (do not re-flag):** asset depreciation runs post GL; stock-count with variance-approval; 8D SLA; employee self-service (11 pages); RMA/traceability/calibration/PPAP/training-matrix/supplier-scorecards all exist (their gap is UI/tests, Bucket B).

### Bucket B — Half-built (backend-complete, zero-UI)
1. **CRM Leads→Opportunities→Quotes** — 3 controllers, 24 routes, `QuoteService` 269L; **no SPA, no api client, no tests**. HIGH, XL.
2. **Payroll De Minimis** — `DeMinimisService` 292L, CRUD routes; **no UI, no test**; feeds taxable calc. HIGH, M.
3. **Quality PPAP + Calibration** — full backend + routes; no internal SPA UI (PPAP only via B2B portal). IATF-critical. HIGH, L×2.
4. **Loans gov-loan interest path** — `generateWithInterest` test-only callers; UI offers 2 of 4 types; DEAD CODE. MED, S (decide/delete).
5. **Accounting `OfficialReceipt`** — model+service+migration, **no route/controller**; only a test calls it. BIR OR is statutory. MED, M.
6. **Accounting period close/reopen** — backend+tests, no UI. MED, S.
7. **Budget revisions** — controller methods + routes, no UI, no test. MED, M.
8. **Inventory stock-adjustments** — create-only UI; `approve()` unreachable. MED, S.
9. **SupplyChain Containers** — full CRUD, zero SPA. LOW-MED, M.
10. **MRP Machines & Molds** — no create/edit UI, seed-only master data. MED, M.
11. **Edge device admin** — CRUD backend, no SPA management page. LOW-MED, M.
12. **ReturnManagement** — UI complete; gaps are tests (2 for 8-transition service) + missing `feature:` guard. LOW, S.
13. **Forecasting** — UI complete; 1 test for 378L service. LOW, S.

### Bucket C — Missing features (per module)

**Payroll/HR/Attendance/Leave/Loans**
- C-1 **Payroll void route/controller** missing (backend exists). BLOCKER. `PayrollPeriodService.php:451`.
- C-3 `forceUnlock()` rescues only `Processing`, no "un-approve". `:411`.
- C-4 Adjustment types only Underpayment/Overpayment — no OT/ND/leave line, no source back-ref. `PayrollAdjustmentType.php:9-10`.
- C-7 **No separation/retirement pay (RA 7641, Art. 298/299)** — `FinalPayService.php:42-57`, grep empty. HIGH (PH labor law).
- C-8 Final-pay has no tax treatment / 2316 reconciliation. `FinalPayService.php`, grep `tax` empty. MED.
- C-9 No loan restructuring/top-up/consolidation. `LoanService.php:31-269`. MED.
- C-10 **OT premium flat 1.25 regardless of day type** — restday/holiday OT under-paid vs DOLE 1.69×/2.6×. `PayrollCalculatorService.php:282-287`. HIGH (statutory).
- C-12 No monthly leave accrual — full grant upfront over-credits mid-year hires. `LeaveBalanceService.php:15-25`. MED.
- C-13 **Two overlapping year-end leave mechanisms** — `ProcessYearEndLeave` job zeroes remaining; `ResetLeaveBalancesForYear` reads remaining for carry-forward → order-dependent double-handling, no carry cap. `ProcessYearEndLeave.php` vs `ResetLeaveBalancesForYear.php:62`. MED correctness risk.
- C-15 Punch dedup is exact-timestamp only — no bounce/near-dup tolerance. `PunchSessionizer.php:35-48`. MED.
- **Verified PRESENT:** mid-cycle proration (`:331-451`), ND 10% restriction, OT min-30/max-240, biometric re-import idempotent via `unique(employee_id,date)`.

**Accounting/Assets**
- C-1 **AR + AP aging** reports built (`InvoiceService.php:339`, `BillService.php:350`) with `by_customer`/`by_vendor`, but no route/UI — dashboard computes and discards them. HIGH.
- C-2 Credit memo only as RMA negative-invoice; distorts aging, no application/offset, no AP-side. MED-HIGH.
- C-3 Period close/reopen backend-only (scope-cut contested — see REC-16). MED.
- C-4 Recurring/auto-reversing JE absent. MED.
- C-5 No prior-period-adjustment register. MED.
- C-6 Multi-currency absent (definitive sweep). HIGH.
- **Verified PRESENT:** JE maker-checker (`:229`), reversing (`:262`), depreciation run + disposal.

**Inventory/Purchasing/SupplyChain/CRM/Production**
- C1 **UOM conversion dead end-to-end** — engine wired (`UomConversionService.php:36`, GRN `:121`, issue `:86`) but no UI emits `received_uom_code`, no UI to define conversions, **zero conversion rows seeded**. `factor()` throws for every item. HIGH.
- C2 **Stock-adjustment reason codes + approval gate unreachable** — no reason_code field, no `approve` client, no queue. `StockAdjustmentService.php:75-132`. MED-HIGH.
- C3 WO release has no finite-capacity check — only binary machine double-booking. `WorkOrderService.php:528`. MED.
- C4 SO credit limit is a hard block on confirm — no hold/release workflow, no `on_hold` state. `SalesOrderService.php:73`. MED.
- C5 No open-PO/backorder report. MED.
- C6 Over-receipt tolerance env-only, default 0% hard-block, no settings UI. `GrnService.php:128-141`. MED.
- C7 Cycle-count→adjustment bypasses reason-code + approval gate (legacy `adjustIn/adjustOut`). `StockCountService.php:187-204`. MED.

**Quality**
- GAP-C1 **No re-inspection after rework** — rework/replacement WO created without `sales_order_id` (`NcrService.php:268-299`); `TriggerOutgoingQC.php:42` skips WOs without it; the "inherits parent flow" comment is unimplemented. Reworked parts ship + auto-CoC with zero re-measurement. P0 (IATF §8.7.1.4).
- GAP-C2 **No hold/quarantine workflow** — enum exists, never used; NCR is paper-only with no inventory-state linkage. P0/P1.
- GAP-C3 **Production-reject → NCR path missing** — `NcrSource` binary, `CreateNcrRequest` has no `work_order_id`; `quality.ncr.manage` gate excludes production roles. `NcrSource.php:11-12`, `CreateNcrRequest.php:36-44`. MED-HIGH.
- GAP-C4 `use_as_is` concession captures no customer sign-off/waiver despite doc promise. `NcrService.php:213-317`. MED.
- GAP-C5 SPC charts must be created manually — no auto-provisioning per critical spec item. LOW.
- **Verified PRESENT (protect):** actual-measurement-vs-tolerance (`InspectionMeasurement.php:57`), AQL Z1.4 (`AqlSampleSizeService.php:20`), NCR→replacement/rework WO (`:262-304`), Pareto, data-driven CoC (`CoCService.php:82`), SPC Cp/Cpk + Western Electric run rules auto-fed (`SpcService.php:45-334`, `AutoPopulateSpcChart.php:30`), all 4 touchpoints event-wired.

### Bucket D — Cross-cutting
- D3.2 **Year-end leave conversion computed but never paid** — `days_converted` is a dead-end number; grep Payroll for `encash|days_converted` = 0. Money-losing. HIGH.
- D3.1 **No GL year-end close** — P&L never rolled to retained earnings; equity synthesized at render (`BalanceSheetService.php:80-86`). Multi-year IS accumulates. HIGH.
- D6.1 **No systematic row-level security** — hand-rolled per-service dept scoping (Employee/Leave/Overtime), copy-pasted; any forgotten endpoint leaks cross-dept; no cost-center scope. `EmployeeService.php:30-42`. HIGH.
- D1.2 **No biometric enrollment/identity mapping** — punches matched by `employee_no` string; no badge/device-id column; mismatch fails the row. HIGH for Chain-3 credibility.
- D2.1 GL period close/reopen has zero UI (dup of C-3). HIGH.
- D4.1 **Audit immutability trigger bypassable via `TRUNCATE`** — trigger is BEFORE UPDATE/DELETE only; PG row triggers don't fire on TRUNCATE. `2026_06_09_100001_...:20,24`. MED.
- D7.3 Budget/BudgetTransfer approvals bypass the generic engine (self-approval possible). `BudgetService.php:56`. MED.
- D7.4 Auto-resolve default is `reject` (test-pinned) — can auto-kill a queue when the sole approver is on leave. `ApprovalEscalationService.php:25`. MED.
- D7.1 Approvals sequential-only; no parallel routing. MED.
- D5.1 Restore drill never executed; RTO unmeasured. MED (honesty gap).
- D2.2 Period lock confirmed for JE only; Invoice/Bill/Payroll GL postings not verified as gated. MED — needs confirmation.
- D1.1 Onboarding checklist omits assets/training/biometric steps (4 of 7 chain steps). MED.
- LOW: D7.5 escalation map flattens 4-level chain to `system_admin`; D7.2 workflow-level `amount_threshold` dead; D3.3 year-end job docblock/code year drift.
- **Verified STRONG (protect):** audit immutability trigger (minus TRUNCATE), real backup/restore runbook + scripts, generic approval engine SLA/delegation/self-approval guards.

### Bucket E — Schema stress
- Two deepest holes: **(1) no currency dimension on any monetary table** (blocks JP consolidation entirely); **(2) header-only one-shot JE reversal + no credit-memo table** (blocks partial reversal of partially-paid AR/AP — a routine event).
- `journal_entries` missing `accounting_period_id` FK (lock is procedural only), `is_prior_period_adjustment`, line-level `reverses_line_id`.
- `stock_movements` missing `uom_id` (transacted UOM lost — cannot re-audit "1 bag or 25 kg?"), `parent_movement_id` (kit/component grouping), period stamp.
- `stock_levels.weighted_avg_cost` mutable scalar, no cost-layer history → retro cost correction silently rewrites closed-period COGS basis.
- `invoices`/`collections` no `reversal_of` FK, no currency+rate, no line `tax_rate` snapshot (VAT-rate change unhandled).
- `sales_order_items` no `price_agreement_id` snapshot, no SO `version` (amend-after-partial-delivery lost).
- `purchase_orders`/items currency-blind (import PO in FX impossible), `quantity_received` is a free-text-unit running total.

### Bucket F — Failure modes
- F3 **No optimistic locking anywhere** — `stock_levels.lock_version` incremented under lock but **never compared**; no `If-Match`; two users editing an employee/draft-SO silently clobber (last-write-wins, `SalesOrderService.php:235` deletes+recreates items). UNHANDLED (OGAMI-108).
- F1/F2 **No server-side idempotency on financial creates** — only Production output + Edge ingest accept `X-Idempotency-Key`; a lost response on collection/bill/JE = duplicate posted document with a fresh sequence number. PARTIAL (OGAMI-104).
- F12 Dispute on collected invoice — no credit-memo/`Disputed` state; `cancel()` refuses once collections exist. PARTIAL.
- F13 Backdated OT post-finalize — hard wall; `void()` route-less. UNHANDLED-by-design.
- **Verified HANDLED:** F5 job idempotency (`ProcessPayrollJob` ShouldBeUnique, GL posting idempotent), F6 DST (PH has none), F7 leap-day, F9 permission-revoke ≤300s, F10 biometric re-import, F11 bill-for-cancelled-PO, F14 MRP crash (atomic txn + stale-run reaper).

### Bucket G — Anti-patterns
- **Laravel #1/#2 (HIGH):** `CommissionController::rates`/`setRate` return raw models → leak integer `id`/FKs, violating the hash_id mandate. `CommissionController.php:29,36`. No `CommissionRateResource`.
- **Laravel #3 (MED-HIGH):** `BudgetController` fat controller — inline hash-decode, inline `$request->validate`, direct `$budget->update()` bypassing service. `BudgetController.php:32-66,119,177`.
- Laravel #4 (MED): 56 controllers use inline `validate()` bypassing FormRequest `authorize()` — acceptable only where a `permission:` middleware guards the route; removes defense-in-depth.
- Laravel #5-7 (LOW): unindexed FK at creation (mitigated by later backfill), 2 models missing `HasHashId` (latent), `env()` outside config in backup command (breaks under `config:cache`).
- **React #1-8 (MED):** B2B portal + self-service never adopted design-system primitives — hand-rolled status pills instead of `<Chip>` (`portal/supplier/invoices/detail.tsx:57` +6), raw `useState` forms without RHF/Zod (`self-service/overtime.tsx:210`, `quality/ncr-templates/create.tsx:35`), manual fetch bypassing `useQuery` (`portal/supplier/delivery-schedules.tsx:17`).
- React #9 (MED): `budgeting/create.tsx:81` toast leaks raw `err.message`.
- **Verified CLEAN:** no Bearer tokens, `withCredentials` everywhere, universal lazy-load, guards wired, no raw int IDs in the main app, no money-float, no `DB::raw` injection.

### Bucket — Reporting taxonomy
- **Export engine wired to 1 of ~10 modules** — `ExportController.php:136-148` advertises payroll register, inventory valuation/stock-card, AR/AP aging; `ExportRunner::MAP` (`:22-24`) contains **only** `EmployeeMasterExport`; all others throw. Scheduler + column-selector modal + scheduled-export CRUD all built and idle. Highest-leverage reporting gap.
- Missing reports: **AP aging** (service exists, no route), **AR aging portfolio**, **inventory valuation**, **DTR export** (`DTRComputationService` computes, no endpoint), **payroll register** (`DocumentType::PayrollRegister` enum, no generator), Work Order Traveler (enum, no template).
- Management analytics (OEE/CoPQ/Pareto/supplier-scorecard/downtime/KPI-scorecard) all view-only, no export/schedule.
- Financial statement rows not drill-down clickable to JE.

### Bucket — Localization (filing-grade)
- **P0:** 1601-C (`Bir1601CService.php:44-57`) and 1604-CF (`Bir1604CfService.php:43-52`) are 1-row stubs; Alphalist is CSV not `.DAT` (`BirAlphalistService.php:78`); no eBIRForms XML; **no EWT/2307** on bills (`Bill.php:23-42` has zero withholding fields); **no VAT return 2550M/Q**; **SSS R-3 built but orphaned** (`SssR3Export.php` never instantiated); **JPY multi-currency + consolidation absent**.
- **P1:** 2316 simplified (no RDO/MWE/4-part breakout); 1601-EQ/1604-E/2306 missing; company RDO/ATC not seeded; SSS EC/WISP tier missing; RF-1/MCRF generic CSV not EPRS/HDMF upload format; JP i18n / 請求書 / Reiwa era-date absent.
- **Working (protect):** 2025 SSS/PhilHealth/Pag-IBIG tables, TRAIN WHT brackets, full DOLE leave slate incl. 105-day Expanded Maternity, per-invoice VAT + ATP/ORIGINAL-DUPLICATE, COE/contribution certs.

### Bucket — Security / SoD
- **F1 (CRITICAL):** payroll create→compute→approve→finalize has **no maker-checker and no actor** on approve/finalize; `payroll_periods` has no `approved_by`/`finalized_by`. `PayrollPeriodService.php:216,369`, `0032:22`.
- **F6 (HIGH):** bill create + pay by same finance user, no SoD, no approval on payment. `BillService.php:279`.
- **F3 (HIGH):** budget + budget-transfer self-approval possible; `finance_officer` holds maker+checker. `BudgetService.php:56`.
- F2 (MED): stock-adjustment approve has no `requested_by==actor` guard (latent — role-separated today).
- **F9 (systemic):** RA 10173 governance entirely absent — no consent capture, no DSAR export, no erasure/anonymization on separation (SoftDeletes retains PII), no breach register, no DPO role. PII *is* encrypted+masked (security-of-processing covered) but the rights/governance layer is unbuilt.
- F7 (MED): audit log DB-immutable but **not tamper-evident** — no hash-chain/signature; a privileged operator can `DISABLE TRIGGER` undetectably.
- F10 (MED): `system_admin` is unlogged god-mode substituting for a broken-glass mechanism; SoD overrides pass silently with no reason/audit event.
- **Verified STRONG:** JE maker-checker with threshold+override, bank-account-change two-leg SoD (HR then Finance), asset-transfer self-guard, PR/PO submitter guard via ApprovalService.

### Bucket — Migration from hybrid mess
- **P0-1** No master-data import for any entity (employees/customers/vendors/items/BOMs/molds/machines/COA) — single-record forms only. OGAMI-111.
- **P0-2** No opening-balance loader — GL/AR/AP/inventory start at zero, WAC starts wrong (`StockMovementType` has no `Opening` case). OGAMI-112.
- **P0-3** No TB-match reconciliation report.
- **P1:** no dry-run/staging/validate-before-commit, no batch rollback, no parallel-run, no cutover checklist.
- Only two importers exist: biometric DTR (solid) + gov contribution tables. This is the **single largest go-live blocker.**

### Bucket — Demo-readiness
- No P0 chain-breaker; typecheck exits 0, 69/69 sidebar links resolve, dark mode clean.
- P1: currency formatting drift — 13 files bypass `formatPeso()` producing `₱ 1500000.00` vs `₱1,500,000.00` (`crm/price-agreements/index.tsx:39` +12). Two native `window.prompt()` in payroll force-unlock + maintenance cancel (`periods/detail.tsx:240`).
- P2: orphaned create-only pages (stock-adjustments/transfers) reachable only by URL; 2 `en-US` date outliers; empty-chart framed grids.

### Bucket — Seed realism + PDF
- **Seed WEAK (MED/HIGH):** transactional volume is thin — 3 invoices, 2 bills, 2 POs, 1 GRN; **`collections`, `bill_payments`, `work_orders`+outputs NOT seeded at all** (grep empty). Invoice/bill "paid" states are cosmetic (`amount_paid` column-set, not transaction-backed) → AR/AP aging + GL won't tie out; Chain-1 production stage renders empty. Directly undercuts "three chains end-to-end" in a live demo.
- Seed STRONG: 200 employees w/ Filipino names, 12-mo attendance patterns, 45 NCRs weighted for Pareto, Toyota/Nissan/Honda customers, authentic BOMs/molds/resins.
- **PDF P0/P1:** Official Receipt template **missing** (BIR-mandatory); Delivery Receipt **missing** (customer-mandatory); GRN + Picking List **missing**; 2316 is a simplified summary not the official form; payslip has no YTD; **DRAFT/VOID/PAID/CANCELLED watermarks unwired** (`Accounting/PdfService` never passes `confidential`/`watermark`/`generated`).
- PDF STRONG: CoC (data-wired, IATF, measurement table), PO (4-tier sig), payslip content, financial statements, 8D/audit-log.

---

## 5. Phase 4 — Recommendations

Priority tiers: **P0** foundation/pilot-blocker · **P1** real-world-usable · **P2** competitive · **P3** polish.

### Tier P0 — Foundation / pilot blockers

### [REC-01] Expose payroll void (route + controller + button + SoD actor)
- Bucket: half-built / feature / failure-mode | Module/chain: Payroll / Chain 3
- Why it matters (role): Maria (HR clerk) finalizes a run with an error and is stuck — the one sanctioned correction the code was built for is unreachable.
- What breaks without it: every bad finalized run is uncorrectable except a blunt next-period adjustment; contradicts the void design the codebase already ships.
- Proposal: add `POST /payroll-periods/{period}/void` → `PayrollPeriodController::void(VoidPayrollRequest)` → existing `PayrollPeriodService::void()`; require reason; SPA void button on `periods/detail.tsx` behind `payroll.periods.void` permission + confirm modal; record `voided_by`.
- Dependencies: REC-04 (actor plumbing) shares the controller change.
- Effort: S (1 day) | Priority: P0 | Risk if deferred: payroll errors become permanent, thesis "void-and-reverse" claim undemonstrable.
- Evidence: `PayrollPeriodService.php:451` vs no route in `Payroll/routes.php`; `PayrollPeriodController.php` ends at `runThirteenthMonth:180`.
- Verdict: enhance

### [REC-02] Wire 3-way match into the bill-create flow + variance snapshot view
- Bucket: missing-feature | Module/chain: Purchasing/Accounting / Chain 2
- Why it matters (role): Ben (AP clerk) types 50 invoices/week as free-text with zero PO validation; the whole match engine never runs.
- What breaks without it: no qty/price variance detection, no GRN-short catch, no override trail — the P2P control is inert.
- Proposal: extend `CreateBillData` + `bills/create.tsx` with a PO selector and per-line `item_id`; send `purchase_order_id`/`items.*.item_id`/`allow_override`; add `billsApi.threeWayMatch(id)` client hitting existing `GET /three-way-match/{bill}`; render `three_way_match_snapshot`/`has_variances` in `bills/detail.tsx`; surface over-receipt tolerance (REC-24) in settings.
- Dependencies: REC-24 (tolerance UI), REC-23 (backorder view complements).
- Effort: M (3 days) | Priority: P0 | Risk if deferred: AP fraud/error exposure; headline P2P feature is a phantom.
- Evidence: `StoreBillRequest.php:21,30`, `BillService.php:135-181`, `ThreeWayMatchService.php`, `bills/create.tsx:98-113`, `Purchasing/routes.php:67`.
- Verdict: enhance

### [REC-03] Master-data import toolkit (employees/customers/vendors/items/BOMs/molds/machines/COA)
- Bucket: migration | Module/chain: cross / all chains
- Why it matters (role): a real Ogami migrating off Excel/legacy cannot hand-key 200 employees + hundreds of items + BOMs.
- What breaks without it: no pilot cutover is possible.
- Proposal: generic CSV import pipeline — `import_batches` table, per-entity column mapping (fixed schema per CLAUDE.md cut, no mapping UI), staging → validate (dry-run preview with error rows) → commit (single txn per batch) → batch rollback. Reuse the solid `DTRImportService` per-row-catch pattern but add batch tracking. Start with employees + items + COA (highest volume).
- Dependencies: REC-05 (opening balances build on item/COA import).
- Effort: XL (8-10 days) | Priority: P0 | Risk if deferred: hard go-live blocker (OGAMI-111).
- Evidence: routes grep = 0; `DTRImportService.php:82`; `REBUILD-AUDIT-2026-06-18-BACKLOG.md:77`.
- Verdict: build

### [REC-04] Payroll maker-checker + attributable approver/finalizer
- Bucket: security/SoD | Module/chain: Payroll / Chain 3
- Why it matters (role): for a ₱-material 200-employee run, one finance_officer runs the entire lifecycle with no second set of eyes and no record of who approved.
- What breaks without it: audit-blocking; a JP-parent/BIR auditor cannot attribute the approval; salary fraud vector.
- Proposal: `approve()`/`finalize()` take the `User`; add `approved_by`/`computed_by`/`finalized_by` columns to `payroll_periods`; reject when approver == computer unless `payroll.periods.self_approve_override`; split `hr_officer`/`finance_officer` seeded perms so create and approve are not both default.
- Dependencies: REC-01 shares the controller/actor change.
- Effort: S/M (2 days) | Priority: P0 | Risk if deferred: SoD hole across the largest cash-out process.
- Evidence: `PayrollPeriodService.php:216,369`; `0032_create_payroll_periods_table.php:22`; `RolePermissionSeeder.php:411-415`.
- Verdict: enhance

### [REC-05] Opening-balance loader + trial-balance reconciliation
- Bucket: migration | Module/chain: Accounting/Inventory / all
- Why it matters (role): Tanaka cannot start the books — GL/AR/AP/inventory all begin at zero with wrong WAC.
- What breaks without it: migrated books cannot be proven equal to source books (IATF/JP audit blocker).
- Proposal: opening-balance JE generator that must net to a provided legacy TB (reject if unbalanced); `StockMovementType::Opening` + bulk opening-stock loader with cost basis; open-invoice / open-bill importers so aging/dunning start correct; TB-match report.
- Dependencies: REC-03 (COA/item import).
- Effort: L (5 days) | Priority: P0 | Risk if deferred: go-live blocker (OGAMI-112).
- Evidence: `JournalEntryService` grep `opening` = 0; `StockMovementType.php` no Opening; `StatementOfAccountService.php:40`.
- Verdict: build

### [REC-06] Statutory filing fidelity — 1601-C, 1604-CF, Alphalist `.DAT`, wire SSS R-3
- Bucket: localization/reporting | Module/chain: Payroll / Chain 3
- Why it matters (role): Maria files these monthly/annually; a 1-row summary and a CSV Alphalist are rejected by BIR/eBIRForms validation.
- What breaks without it: the "full PH statutory" differentiator fails at the actual filing.
- Proposal: expand `Bir1601CService` to taxable-vs-exempt split + tax-due/withheld reconciliation; make Alphalist emit the BIR DAT schema (fixed field order, header/trailer, control totals, ATC); wire the already-built `SssR3Export` (add route + `ExportRunner::MAP` entry + statutory-page button). Defer full eBIRForms XML to P1.
- Dependencies: gov-table currency (OGAMI-101).
- Effort: L (5 days) | Priority: P0 | Risk if deferred: filing artifacts unusable; credibility gap at pilot.
- Evidence: `Bir1601CService.php:44-57`, `Bir1604CfService.php:43-52`, `BirAlphalistService.php:78`, `SssR3Export.php:26` (never instantiated).
- Verdict: enhance

### [REC-07] Re-inspection after rework/replacement WO
- Bucket: quality/missing-feature | Module/chain: Quality / Chain 1
- Why it matters (role): Joel's reworked bushings ship without re-measurement; IATF §8.7.1.4 mandates re-verification.
- What breaks without it: nonconforming-then-reworked product delivered with an auto-CoC and zero actual re-measurement — the single most audit-exposed hole in the quality spine.
- Proposal: either pass the parent's `sales_order_id` into the rework/replacement WO in `NcrService::close()`, or remove the `if (! $wo->sales_order_id) return;` skip in `TriggerOutgoingQC` and trigger outgoing QC for any WO carrying `parent_ncr_id`. Add a test asserting a completed rework WO creates an outgoing inspection.
- Dependencies: none.
- Effort: S (1 day) | Priority: P0 | Risk if deferred: IATF non-conformance; CoC integrity broken.
- Evidence: `NcrService.php:268-299`, `TriggerOutgoingQC.php:42-43`, `WorkOrderService.php:137`.
- Verdict: enhance

### [REC-08] Hold / quarantine workflow for nonconforming stock (MRB)
- Bucket: missing-module/quality | Module/chain: Inventory+Quality / Chains 1,2
- Why it matters (role): warehouse staff have nowhere to physically segregate rejected material; IATF §8.7 requires it. The `Quarantine` zone enum is dead.
- What breaks without it: nonconforming finished goods and rejected incoming resin commingle with good stock.
- Proposal: on inspection/NCR fail (in-process, outgoing, use_as_is), move stock to a Quarantine location and set a "held" stock status that blocks issue; MRB disposition record tying NCR disposition to the physical move; release-from-quarantine on rework-pass or scrap.
- Dependencies: REC-07 (rework re-inspection feeds release).
- Effort: M (3-4 days) | Priority: P0 | Risk if deferred: IATF §8.7 gap; held stock issuable.
- Evidence: `WarehouseZoneType.php:13` (enum unused); grep `quarantine` in Inventory/Quality services = 0.
- Verdict: build

### [REC-09] OT premium day-type stacking correction (statutory)
- Bucket: missing-feature | Module/chain: Payroll / Chain 3
- Why it matters (role): every rest-day/holiday OT is under-paid — a DOLE wage violation.
- What breaks without it: OT-on-holiday computes `1.25 × day_multiplier` instead of the statutory 1.69× (rest/special) / 2.6× (regular holiday) hourly factor.
- Proposal: replace the flat `OT_PREMIUM=1.25` stacking with a day-type OT factor table (regular 1.25, restday 1.69, special-restday 1.95, regular-holiday 2.6, etc.); stack ND on OT hours where applicable; add unit tests per day type.
- Dependencies: none.
- Effort: S (1-2 days) | Priority: P0 | Risk if deferred: statutory under-payment, DOLE exposure.
- Evidence: `PayrollCalculatorService.php:282-287,62`; `DTRComputationService.php:292-305`.
- Verdict: enhance

### [REC-10] Reconcile the two year-end leave mechanisms + pay the conversion
- Bucket: cross-cutting | Module/chain: Leave+Payroll / Chain 3
- Why it matters (role): employees lose convertible leave balance and are never paid the encashment — a money-losing bug — and the two jobs corrupt balances if both run.
- What breaks without it: order-dependent double-handling (`ProcessYearEndLeave` zeroes remaining that `ResetLeaveBalancesForYear` reads for carry-forward); `days_converted` never becomes a payroll line.
- Proposal: single source of truth for year-end (one job computes forfeit/convert/carry with a carry cap; the other consumes its output, not raw `remaining`); emit a payroll encashment line from `days_converted`; add carry-cap config; fix the docblock/`now()->year` drift.
- Dependencies: REC-04 (payroll line plumbing).
- Effort: M (3 days) | Priority: P0 | Risk if deferred: corrupted balances + unpaid statutory leave conversion.
- Evidence: `ProcessYearEndLeave.php:104-134,50`, `ResetLeaveBalancesForYear.php:62`, grep Payroll `encash`=0.
- Verdict: refactor

### [REC-11] Systematic row-level data scope (dept + cost-center)
- Bucket: cross-cutting/security | Module/chain: cross / all
- Why it matters (role): a dept head can leak another department's rows through any list endpoint whose author forgot the hand-rolled scope block.
- What breaks without it: data-confidentiality breach across 200 employees; no cost-center scope at all.
- Proposal: a shared `ScopedByDepartment` trait / global scope resolving from the user's grants (not role-slug string equality), applied centrally; audit every list service to adopt it; add cost-center dimension scoping for Accounting/budgeting.
- Dependencies: REC-13 (permission-vs-role source-of-truth unification helps).
- Effort: L (5 days) | Priority: P0 | Risk if deferred: cross-dept data leak; RA 10173 exposure.
- Evidence: grep `DataScope|scopeVisibleTo` = 0; `EmployeeService.php:30-42` (slug equality `:36`).
- Verdict: refactor

### Tier P1 — Real-world usable

### [REC-12] JPY / multi-currency + parent-consolidation pack
- Bucket: missing-module/schema/localization | Module/chain: Accounting / close→parent
- Why it matters (role): Tanaka's core monthly obligation is a JPY-translated TB/BS/IS for the Japanese parent; today he re-keys PHP CSV into Excel with a manual rate.
- What breaks without it: the signature JP-parent differentiator does not exist.
- Proposal: `fx_rates` table; `currency_code` + `transaction_amount`/`functional_amount` + `exchange_rate` on JE lines, invoices, bills, POs; capture transaction currency + rate at document date; realized FX gain/loss on collection/payment; JPY-translated statement export (current-rate method) + CTA line + intercompany reconciliation. Schema sketch: `journal_entry_lines += currency_code CHAR(3), txn_debit/credit DECIMAL(15,4), fx_rate DECIMAL(18,8)`.
- Dependencies: touches every monetary write path — sequence after P0 hardening.
- Effort: XL (10-15 days) | Priority: P1 | Risk if deferred: differentiator claim must be retracted.
- Evidence: `StatementOfAccountService.php:77`; no currency column in any migration; OGAMI-105/106/107/113.
- Verdict: build

### [REC-13] Credit-memo / credit-note instrument (AR + AP)
- Bucket: missing-module/failure-mode | Module/chain: Accounting / Chains 1,2
- Why it matters (role): Ben and Tanaka have no way to credit a price dispute, damaged-goods claim, or over-billing; a partially-collected disputed invoice is stuck.
- What breaks without it: corrections become manual JEs breaking subledger↔GL reconciliation; BIR credit-note VAT reversal undocumented; RMA models credits as negative invoices that pollute aging.
- Proposal: first-class `credit_notes` table (distinct BIR doc numbering), AR customer-credit + AP supplier-credit, application/offset against open invoices/bills, `Disputed` invoice state; retire the RMA negative-invoice hack in favor of a linked credit note.
- Dependencies: REC-15 (aging must treat credits correctly).
- Effort: L (5 days) | Priority: P1 | Risk if deferred: no dispute/return financial instrument; reconciliation hazard.
- Evidence: grep `credit.?memo` = 0; `ReturnRequestService.php:299`; `InvoiceService.php:260`.
- Verdict: build

### [REC-14] Accounting period close/reopen UI
- Bucket: half-built/cross-cutting | Module/chain: Accounting / close
- Why it matters (role): Tanaka's single most important month-end action (lock the month) is not clickable — backend + reopen-reason trail exist but no screen.
- What breaks without it: month-end control is API-only; the reopen audit trail is invisible.
- Proposal: `accounting/periods` list page with close/reopen buttons + reason modal + reopen-history display; api client; permission `accounting.periods.manage` already exists.
- Dependencies: none (see REC-16 for scope note).
- Effort: S (2 days) | Priority: P1 | Risk if deferred: no operable period control.
- Evidence: `AccountingPeriodService.php:50,87`; no `spa/src/pages/accounting/periods`.
- Verdict: enhance

### [REC-15] AR/AP aging reports (route + page + export)
- Bucket: reporting/missing-feature | Module/chain: Accounting / Chains 1,2
- Why it matters (role): CFO and AP clerk cannot answer "who owes what, aged" or "what's 90+ days overdue" — the services compute it every dashboard load and discard it.
- What breaks without it: core monthly finance deliverable is manual/Excel.
- Proposal: `GET /accounting/ar-aging` + `/ap-aging` returning the existing `by_customer`/`by_vendor` breakdowns; two SPA report pages with bucket columns + CSV export (wire into REC-25 engine).
- Dependencies: REC-13 (credit-note handling in buckets).
- Effort: S (2 days) | Priority: P1 | Risk if deferred: recurring blind spot for AR/AP.
- Evidence: `InvoiceService.php:339,375`; `BillService.php:350,386`; no aging route.
- Verdict: enhance

### [REC-16] Retain fiscal-period lock (contest the scope cut)
- Bucket: cross-cutting / scope-disagreement | Module/chain: Accounting
- Why it matters (role): CLAUDE.md:81 lists "fiscal period LOCKING" as NOT BUILDING, but it is built and is the control that makes the GL audit-defensible for a BIR-registered, IATF, JP-parent-consolidated company. **I disagree with the cut** — a real failure mode demands it (an auditor asks "prove no one back-dated a JE into a closed, filed month").
- What breaks without it: any `journal.create` holder could silently alter a filed 1601-C / financial statement.
- Proposal: keep the lock; formally un-cut it in CLAUDE.md; verify subledger postings (Invoice/Bill/Payroll GL) call the same period guard as JE (D2.2); build REC-14 UI to operate it.
- Dependencies: REC-14.
- Effort: S (1 day verification + doc) | Priority: P1 | Risk if deferred: audit-indefensible GL.
- Evidence: `AccountingPeriodService.php:16-24`, enforced `JournalEntryService.php:95,183`; CLAUDE.md:81.
- Verdict: keep

### [REC-17] Separation / retirement pay (RA 7641) + final-pay tax
- Bucket: missing-feature/localization | Module/chain: HR/Payroll / Chain 3
- Why it matters (role): for a 200-employee plant, separation pay (½–1 month/year, authorized-cause) and RA 7641 retirement pay (22.5 days/year) are the most legally-loaded final-pay components — and both are absent.
- What breaks without it: illegal final pay on any authorized-cause termination or retirement; no withholding / final 2316 for leavers.
- Proposal: add years-of-service-based separation + retirement pay to `FinalPayService::compute()`; apply tax treatment (exempt separation benefits; tax taxable components); drive the final BIR 2316 off this computation.
- Dependencies: REC-06 (2316 fidelity).
- Effort: M (3 days) | Priority: P1 | Risk if deferred: PH labor-law non-compliance.
- Evidence: `FinalPayService.php:42-57`, grep `separation_pay|RA 7641|tax` = 0.
- Verdict: enhance

### [REC-18] EWT/2307 capture on bills + VAT return (2550M/Q)
- Bucket: localization | Module/chain: Accounting/Purchasing / Chain 2
- Why it matters (role): Ogami as a withholding agent must issue 2307 to suppliers (rent 5%, professional 5-10%, goods 1%, services 2%) and file periodic VAT.
- What breaks without it: no EWT-payable leg, no supplier cert, no VAT-payable summary — blocks P2P go-live and creates supplier friction.
- Proposal: add `ewt_rate`/`tax_withheld` + ATC to bills; post the EWT-payable leg; 2307 PDF per bill; period VAT-return report aggregating output VAT (invoices) − input VAT (bills).
- Dependencies: REC-13 (credit notes affect VAT).
- Effort: L (5 days) | Priority: P1 | Risk if deferred: withholding non-compliance.
- Evidence: `Bill.php:23-42`; grep `2307|2550|ewt` = 0.
- Verdict: build

### [REC-19] Production-reject → NCR path (WO source + pause modal)
- Bucket: quality/production | Module/chain: Production+Quality / Chain 1
- Why it matters (role): Joel escalates high-scrap runs verbally/on paper because a WO with no formal inspection has no NCR path, and every downtime lands as "breakdown/Manual pause."
- What breaks without it: floor defects don't enter the NCR/Pareto loop; OEE downtime analytics are poisoned.
- Proposal: add `WorkOrderReject` to `NcrSource`, accept `work_order_id` in `CreateNcrRequest`, grant `production_manager` NCR-raise permission; replace the hardcoded `pause(id,'Manual pause','breakdown')` with a modal (category + reason) — backend already accepts both; high-scrap threshold alert/auto-NCR on `scrap_rate` breach.
- Dependencies: none.
- Effort: M (2-3 days) | Priority: P1 | Risk if deferred: quality feedback loop bypassed on the floor.
- Evidence: `NcrSource.php:11-12`, `CreateNcrRequest.php:36-44`, `work-orders/detail.tsx:111`, `WorkOrderOutputService.php:125`.
- Verdict: enhance

### [REC-20] Optimistic locking on shared master data + documents
- Bucket: failure-mode | Module/chain: cross / all
- Why it matters (role): two HR users editing the same employee, or two planners editing a draft SO, silently clobber each other (last-write-wins, item delete+recreate).
- What breaks without it: lost updates on employees/SO/customer master with no warning.
- Proposal: `lock_version` column on high-contention tables; controllers accept `expected_version`/`If-Match`; return 409 on mismatch; SPA surfaces "record changed, reload." Start with employees, SO, customer/vendor, PR/PO drafts.
- Dependencies: none.
- Effort: M (3 days) | Priority: P1 | Risk if deferred: silent data corruption (OGAMI-108).
- Evidence: `stock_levels.lock_version` `0056:21` never compared; `SalesOrderService.php:235`.
- Verdict: enhance

### [REC-21] Idempotency keys on financial document creates
- Bucket: failure-mode | Module/chain: Accounting / Chains 1,2
- Why it matters (role): a lost response on a collection/bill/JE submit makes Maria/Ben re-submit → a duplicate posted document with a fresh sequence number, hard to detect after the fact.
- What breaks without it: double-posted JE over-credits AR/AP; duplicate bills.
- Proposal: `X-Idempotency-Key` middleware for POST on collections, bills, invoices, JE (reuse the Production/Edge pattern) or natural-key unique constraints (e.g. collection `reference_number`); SPA generates a key per submit.
- Dependencies: none.
- Effort: M (2-3 days) | Priority: P1 | Risk if deferred: duplicate financial postings (OGAMI-104).
- Evidence: `InvoiceService.php:281-340` (no dedup); `WorkOrderOutputService.php:50-52` (pattern).
- Verdict: enhance

### [REC-22] De Minimis benefits UI + test
- Bucket: half-built | Module/chain: Payroll / Chain 3
- Why it matters (role): Maria cannot enter rice/meal/uniform allowances (which feed taxable computation every run) without raw API.
- What breaks without it: every payroll run's tax computation depends on data no clerk can enter.
- Proposal: `payroll/de-minimis` list+create page, api client; Feature test asserting the tax-exempt ceiling logic.
- Dependencies: none.
- Effort: M (2 days) | Priority: P1 | Risk if deferred: recurring per-period pain; untested tax path.
- Evidence: `DeMinimisService.php` (292L), routes `Payroll/routes.php:93-98`; grep `de-minimis` in `spa/src` = 0.
- Verdict: enhance

### [REC-23] Backorder / open-PO report + SO credit hold-release
- Bucket: missing-feature | Module/chain: Purchasing/CRM / Chains 1,2
- Why it matters (role): Ben can't see which PO lines are still owed on partial deliveries; a finance manager can't authorize a one-off over-limit SO without editing the customer's master credit limit.
- What breaks without it: partial-receipt matching is blind; credit control is a binary wall with no exception path.
- Proposal: `GET /purchasing/purchase-orders?status=partially_received` open-qty report; add `on_hold`/`credit_hold` SO state + release action gated by permission (audited).
- Dependencies: none.
- Effort: M (3 days) | Priority: P1 | Risk if deferred: procurement blind spot; rigid credit control.
- Evidence: grep `backorder` in Purchasing = 0; `SalesOrderService.php:73`, `SalesOrderStatus.php:9-15`.
- Verdict: enhance

### [REC-24] UOM conversion — seed factors + receiving/issue UI selector
- Bucket: missing-feature | Module/chain: Inventory / Chain 2
- Why it matters (role): resin ordered in BAG and stored in KG cannot actually be received in bags — the entire multi-UOM value prop is inert (backend + tests only).
- What breaks without it: `factor()` throws for every item if a code is ever passed; warehouse cannot transact in purchase units.
- Proposal: seed `ItemUomConversion` factors; add a UOM selector to GRN-create and material-issue-create emitting `received_uom_code`/`issued_uom_code`; item detail tab to manage conversions; over-receipt tolerance in `admin/settings`.
- Dependencies: pairs with REC-02 (bill match uses same units).
- Effort: M (3 days) | Priority: P1 | Risk if deferred: multi-UOM feature dead; over-receipt hard-blocks resin GRNs.
- Evidence: `UomConversionService.php:36,66`; `GrnService.php:121-141`; no UOM in `grn/create.tsx`; `ItemUomConversion` seed = empty.
- Verdict: enhance

### [REC-25] Wire the export engine to all report modules + payroll register + DTR export
- Bucket: reporting | Module/chain: cross / all
- Why it matters (role): the scheduler, column-selector, and scheduled-export CRUD are built but wired to only HR employees; every other "export this list" 404s.
- What breaks without it: no payroll register, no inventory valuation, no DTR export, no AR/AP aging export — all advertised, all unreachable.
- Proposal: add `ExportRunner::MAP` entries + `registerColumns()` for payroll register, inventory valuation/stock-card, AR/AP aging, DTR; mount the `ColumnSelectorModal` export button on the corresponding list pages.
- Dependencies: REC-15 (aging), REC-06 (payroll data).
- Effort: M (3-4 days) | Priority: P1 | Risk if deferred: reporting surface stuck at 1/10 modules.
- Evidence: `ExportController.php:136-148` vs `ExportRunner.php:22-24`; `DocumentType::PayrollRegister` no generator.
- Verdict: enhance

### [REC-26] Stock-adjustment approval queue + reason codes (UI)
- Bucket: half-built/missing-feature | Module/chain: Inventory / Chains 1,2
- Why it matters (role): warehouse can create adjustments but never approve them from the SPA; the IATF reason taxonomy is invisible; if the threshold is ever set >0, above-threshold adjustments vanish unapprovable.
- What breaks without it: stock corrections silently stuck `pending`; no supervisor sign-off, no reason code.
- Proposal: adjustments list + pending-approval queue with approve button; add `reason_code` to the create form; `stockAdjustmentsApi.approve`; route cycle-count variances through the same reason-coded path.
- Dependencies: none.
- Effort: S (2 days) | Priority: P1 | Risk if deferred: unapprovable adjustments; no adjustment audit reason.
- Evidence: `StockAdjustmentService.php:75-132`; `stock-adjustments/create.tsx:21`; `stock.ts:15-18` (no approve).
- Verdict: enhance

### [REC-27] Machines & Molds create/edit UI
- Bucket: half-built | Module/chain: MRP / Chain 1
- Why it matters (role): PPC/Joel cannot onboard a new machine or register a replacement mold when one hits shot-limit — master data is seed-only.
- What breaks without it: any new equipment requires DB/seed access.
- Proposal: create/edit forms for machines and molds calling existing `store/update` routes; wire into the existing index/detail pages.
- Dependencies: none.
- Effort: M (2 days) | Priority: P1 | Risk if deferred: equipment lifecycle blocked at pilot.
- Evidence: `MRP/routes.php:32-34,44-46`; no `machinesApi.create`/`moldsApi.create` in `spa/src`.
- Verdict: enhance

### [REC-28] Seed the missing transactional legs (collections, bill_payments, work orders + outputs)
- Bucket: seed-realism | Module/chain: cross / all chains
- Why it matters (role): the "three chains end-to-end" thesis claim breaks in a live demo — Collections/Bill-Payments/Work-Orders pages render empty and AR/AP aging + GL won't tie out.
- What breaks without it: Chain-1 production + cash-collection stages are invisible; paid states are cosmetic column-sets.
- Proposal: seed real `collections` and `bill_payments` (not `amount_paid` fakes) through the collection/payment services; seed a spread of work orders with outputs + in-process QC across the same 6-month window as attendance.
- Dependencies: none.
- Effort: S (1-2 days) | Priority: P1 | Risk if deferred: demo credibility + reconciliation demos fail.
- Evidence: grep `collections|bill_payments|work_orders` in `database/seeders` = empty; `ComprehensiveDemoSeeder.php:195-231`.
- Verdict: enhance

### [REC-29] Missing statutory/operational PDFs — Official Receipt, Delivery Receipt, GRN, Picking List + void watermarks
- Bucket: PDF | Module/chain: Accounting/Inventory/SupplyChain / Chains 1,2
- Why it matters (role): a VAT Official Receipt is BIR-mandatory on collections; a signed Delivery Receipt is customer-mandatory for Toyota/Nissan shipments; GRN + pick sheet are core warehouse docs.
- What breaks without it: statutory + customer documents are unprintable; voided/cancelled invoices print identically to live ones (fraud-control gap).
- Proposal: `official-receipt`, `delivery-receipt`, `grn`, `picking-list` blade templates + routes; wire OR into the collection flow; route `Accounting/PdfService` through `PdfRenderService` so DRAFT/VOID/PAID/CANCELLED watermarks + "Page N of M" render.
- Dependencies: REC-05 (OfficialReceipt model exists but orphaned — wire it).
- Effort: M (3 days) | Priority: P1 | Risk if deferred: BIR/customer document gaps; no void stamp.
- Evidence: no `official-receipt`/`delivery-receipt`/`grn`/`picking-list` blade; `Accounting/PdfService::invoice()` passes no watermark/generated.
- Verdict: enhance

### [REC-30] Bill create/pay SoD + budget approval through the generic engine
- Bucket: security/SoD | Module/chain: Accounting / Chain 2
- Why it matters (role): one finance_officer can enter a vendor bill and pay it (fictitious-vendor disbursement), and can create+approve a budget/transfer.
- What breaks without it: classic AP fraud vector; ungoverned budget approvals.
- Proposal: block `recordPayment` when payer == bill creator unless override; route bill payment + budget/transfer approval through `ApprovalService` (gaining self-approval guard, SLA, delegation); add self-approval guard to `StockAdjustmentService::approve` (REC-26 adjacent).
- Dependencies: REC-04 (consistent SoD approach).
- Effort: M (3 days) | Priority: P1 | Risk if deferred: AP + budget fraud exposure.
- Evidence: `BillService.php:279,309`; `BudgetService.php:56`, `BudgetTransferService.php:42`.
- Verdict: enhance

### [REC-31] Biometric enrollment / device-identity mapping
- Bucket: cross-cutting | Module/chain: Attendance / Chain 3
- Why it matters (role): biometric terminals rarely accept `OGM-2026-0142` as the enrollee ID; today a mismatch fails the whole import row with `Unknown employee_no`.
- What breaks without it: Chain-3 "Biometric CSV → DTR" is fragile; any device-id/employee_no skew breaks imports silently.
- Proposal: add a `biometric_user_id`/`badge_no` column on employees + an enrollment step in onboarding; map device IDs → employees in `DTRImportService`; surface unmapped punches instead of failing the row.
- Dependencies: REC-32 (onboarding step).
- Effort: M (2-3 days) | Priority: P1 | Risk if deferred: DTR import brittleness at pilot.
- Evidence: `DTRImportService.php:68,185` (string match); no biometric column in `0016`.
- Verdict: enhance

### [REC-32] Complete onboarding checklist (assets, training, biometric enroll)
- Bucket: cross-cutting | Module/chain: HR / Chain 3
- Why it matters (role): a clerk marking a new hire "complete" has no assurance they can clock in or received PPE/tools.
- What breaks without it: onboarding closure is meaningless for the physical/enrollment steps.
- Proposal: add `asset_issued`, `training_assigned`, `biometric_enrolled` step keys to `EmployeeOnboarding` (tables `0020`/`0191` already exist); gate `completed_at` on them.
- Dependencies: REC-31.
- Effort: S (1-2 days) | Priority: P1 | Risk if deferred: hollow onboarding completion.
- Evidence: `EmployeeOnboarding.php:17-24`.
- Verdict: enhance

### Tier P2 — Competitive

### [REC-33] Finish the CRM sales pipeline (Leads → Opportunities → Quotes) UI
- Bucket: half-built | Module/chain: CRM / Chain 1
- Why it matters (role): a full backend sales pipeline (3 controllers, 24 routes, `QuoteService` 269L) is 100% unreachable — either the largest dead surface or an undocumented competitive feature.
- What breaks without it: sales users can't work leads/opps/quotes; the pipeline is dead code inviting rot.
- Proposal: decide scope first (this is XL). If in-scope: 3 list + 3 create/detail pages, 3 api clients, sidebar entries (permissions already on routes), + workflow-transition tests. If out: explicitly mark out-of-pilot-scope and stop testing/maintaining.
- Dependencies: scope decision.
- Effort: XL (8-10 days) or S (document-and-defer) | Priority: P2 | Risk if deferred: dead code drift.
- Evidence: `CRM/routes.php:57-84`; no `spa/src/pages/crm/{leads,opportunities,quotes}`.
- Verdict: enhance (or explicitly cut)

### [REC-34] Quality PPAP + Calibration internal SPA UI
- Bucket: half-built | Module/chain: Quality / Chains 1,2
- Why it matters (role): QC engineers can't manage PPAP submissions or the calibration register from the app — both are IATF deliverables auditors probe; GAP-ANALYSIS claims PPAP "shipped."
- What breaks without it: PPAP only visible via B2B portal; calibration invisible internally.
- Proposal: PPAP list+detail (submit/review/approve/elements) and Calibration register list+create pages; api clients; sidebar entries under `qualityRoutes.tsx` (permissions exist).
- Dependencies: none.
- Effort: L (4-5 days total) | Priority: P2 | Risk if deferred: IATF audit gap; overstated "shipped" claim.
- Evidence: `PpapController.php`, `CalibrationController.php`; grep `ppap|calibration` in `spa/src/pages/quality` = 0.
- Verdict: enhance

### [REC-35] Recurring / auto-reversing journal entries
- Bucket: missing-feature | Module/chain: Accounting / close
- Why it matters (role): Tanaka hand-keys prepaid amortization, accruals, and depreciation-style standing entries every period.
- What breaks without it: month-end drudgery and error risk.
- Proposal: recurring-JE template + `reverses_on` auto-reverse for accruals; scheduled generator.
- Dependencies: REC-16 (period awareness).
- Effort: M (3 days) | Priority: P2 | Risk if deferred: manual re-keying (OGAMI-114).
- Evidence: grep `recurring` in Accounting = 0; `reverse()` manual-only `:262`.
- Verdict: build

### [REC-36] GL year-end close (retained-earnings roll) + prior-period-adjustment register
- Bucket: cross-cutting | Module/chain: Accounting / year-end
- Why it matters (role): a multi-year pilot's Income Statement accumulates across fiscal years because P&L is never zeroed; equity is synthesized at render, leaving no traceable retained-earnings journal for a JP-parent auditor.
- What breaks without it: no permanent RE journal; no PPA disclosure; year-end books not audit-defensible.
- Proposal: closing-entry generator (income-summary → retained earnings) at year-end; `is_prior_period_adjustment` marker + PPA register report.
- Dependencies: REC-16, REC-05.
- Effort: L (5 days) | Priority: P2 | Risk if deferred: year-end books uncloseable.
- Evidence: grep `retained.?earning|income.?summary|closingEntry` = 0; `BalanceSheetService.php:80-86`.
- Verdict: build

### [REC-37] Audit-trail tamper-evidence + TRUNCATE guard + retention policy
- Bucket: security | Module/chain: cross / all
- Why it matters (role): the "immutable" audit log is bypassable via `TRUNCATE` and by a privileged operator disabling the trigger; a JP/BIR auditor grades tamper-evidence, not just append-only.
- What breaks without it: the immutability guarantee has an undetectable bypass.
- Proposal: add `BEFORE TRUNCATE ... FOR EACH STATEMENT` guard; per-row `HMAC(previous_hash + payload)` hash-chain for tamper-evidence; document a 10-year BIR retention schedule binding the archive lifecycle.
- Dependencies: none.
- Effort: M (3 days) | Priority: P2 | Risk if deferred: audit-integrity gap.
- Evidence: `2026_06_09_100001_...:20,24` (no TRUNCATE); no hash column in `0008`.
- Verdict: enhance

### [REC-38] Dry-run / parallel-run / cutover checklist for migration
- Bucket: migration | Module/chain: cross / all
- Why it matters (role): a real cutover needs preview-before-commit, a 1-2 payroll-cycle parallel run against the legacy system, and a written freeze→load→reconcile runbook.
- What breaks without it: unsafe, un-validated switchover.
- Proposal: import staging + validate/preview step (REC-03 extends), batch rollback, parallel-run variance report (import legacy period results side-by-side), cutover runbook in `docs/`.
- Dependencies: REC-03, REC-05.
- Effort: L (5 days) | Priority: P2 | Risk if deferred: risky go-live (OGAMI-111/112).
- Evidence: grep `dry.?run|parallel.?run` = 0.
- Verdict: build

### [REC-39] Commission raw-id leak + BudgetController refactor
- Bucket: anti-patterns | Module/chain: CRM/Accounting
- Why it matters (role): `CommissionController` leaks integer IDs/FKs (breaks the hash_id mandate); `BudgetController` is a fat controller doing hash-decode + inline validation + direct model writes.
- What breaks without it: ID-obfuscation breach; the one place service-layer discipline broke.
- Proposal: add `CommissionRateResource` (hash_id + nested resources); extract `StoreBudgetRequest`/`UpdateBudgetRequest` FormRequests, move status guards + writes into `BudgetService`.
- Dependencies: none.
- Effort: S (1 day) | Priority: P2 | Risk if deferred: convention/security erosion.
- Evidence: `CommissionController.php:29,36`; `BudgetController.php:32-66,119,177`.
- Verdict: refactor

### Tier P3 — Polish

### [REC-40] B2B portal + self-service design-system adoption
- Bucket: react anti-patterns | Module/chain: B2B/Portal
- Why it matters (role): supplier/customer-facing screens hand-roll status pills, raw `useState` forms without Zod, and manual fetches bypassing `useQuery` — inconsistent and functionally weaker (no caching/validation on quality/HR-mutating forms).
- What breaks without it: unvalidated forms mutate quality/HR data; portal looks unfinished next to the internal app.
- Proposal: replace hand-rolled pills with `<Chip>`; convert `self-service/overtime.tsx` + `quality/ncr-templates/create.tsx` + portal forms to RHF+Zod; convert manual-fetch portal pages to `useQuery`; fix `budgeting/create.tsx:81` raw `err.message` leak.
- Dependencies: none.
- Effort: M (3 days) | Priority: P3 | Risk if deferred: cosmetic + minor validation gaps.
- Evidence: `portal/supplier/invoices/detail.tsx:57`; `self-service/overtime.tsx:210`; `portal/supplier/delivery-schedules.tsx:17`.
- Verdict: refactor

### [REC-41] Currency formatting consolidation + replace native prompts
- Bucket: demo-readiness | Module/chain: SPA
- Why it matters (role): a CFO demo shows `₱ 1500000.00` next to `₱1,500,000.00`; payroll force-unlock pops a raw OS `window.prompt`.
- What breaks without it: looks unfinished to a sharp examiner.
- Proposal: route all 13 offenders through `formatPeso()`; replace the two `window.prompt()` with styled modals; fix the 2 `en-US` date outliers.
- Dependencies: none.
- Effort: S (0.5 day) | Priority: P3 | Risk if deferred: cosmetic demo blemish.
- Evidence: `formatNumber.ts:30`; `crm/price-agreements/index.tsx:39`; `periods/detail.tsx:240`.
- Verdict: refactor

### [REC-42] Resolve dead code — Loan gov-interest path + orphaned OfficialReceipt + return_management feature guard
- Bucket: half-built/hygiene | Module/chain: Loans/Accounting/ReturnManagement
- Why it matters (role): speculative dead code (`generateWithInterest`, `SssLoan`/`PagibigLoan`) and an orphaned `OfficialReceiptService` rot and mislead; RM's module toggle is frontend-only (disabled-module bypass).
- What breaks without it: maintenance confusion; a backend bypass of the module guard.
- Proposal: decide loan gov-interest — implement (wire + UI types) or delete the enum cases/method/test; wire OfficialReceipt into collections (REC-29) or remove; add `feature:return_management` to the RM route group + workflow-transition tests.
- Dependencies: REC-29.
- Effort: S (1-2 days) | Priority: P3 | Risk if deferred: dead-code drift; guard bypass.
- Evidence: `AmortizationService.php:47` (test-only); `LoanService.php:137`; `OfficialReceiptService.php` (no route); `ReturnManagement/routes.php:13`.
- Verdict: refactor

### [REC-43] RA 10173 (Data Privacy Act) governance layer
- Bucket: security | Module/chain: cross / all
- Why it matters (role): the system holds 200+ employees' SSS/TIN/PhilHealth/Pag-IBIG/bank numbers with no consent capture, DSAR export, erasure path, breach register, or DPO role — a real PH pilot compliance blocker (security-of-processing is covered by encryption+masking; the rights/governance layer is not).
- What breaks without it: RA 10173 non-compliance for a real deployment.
- Proposal: consent/lawful-basis capture on onboarding; per-employee DSAR export bundle; scrub-on-separation anonymization (reconciled with the 10-year statutory retention of required fields); breach-notification register; DPO role.
- Dependencies: REC-11 (scope), REC-37 (retention).
- Effort: L (5 days) | Priority: P2/P3 (scope decision) | Risk if deferred: compliance blocker for real pilot, deferrable for thesis-only.
- Evidence: grep `data.subject|consent|erasure|DPO|breach` = 0; SoftDeletes retains PII.
- Verdict: build (scope-gated)

---

## 6. Rebuild-vs-Enhance Verdicts (per major module)

- **Quality — KEEP.** The strongest module and the moat: real AQL Z1.4 actual-measurement CoC, auto-NCR→replacement-WO, SPC with Western Electric run rules auto-fed. Only edge patches needed (rework re-inspection, quarantine, prod-reject entry). Do not touch the core.
- **Payroll engine — KEEP; lifecycle ENHANCE.** `PayrollCalculatorService` is excellent (proration, ND, semi-monthly gates). The lifecycle needs the void route, maker-checker, OT day-type factor, and De Minimis UI — all additive, none structural.
- **Accounting GL/AR/AP — ENHANCE.** JE maker-checker + period lock are solid foundations; the gaps (aging UI, credit memo, recurring JE, year-end close, EWT) are missing surfaces, not broken ones. No rewrite.
- **Multi-currency / consolidation — BUILD.** Genuinely absent; the largest true build and the most exposed differentiator claim. Schema-first (currency dimension on every monetary table).
- **Inventory/WMS — ENHANCE.** WAC + GRN + stock-count are correct; UOM and adjustment-approval are unreachable surfaces to wire, not logic to rewrite.
- **Purchasing — ENHANCE.** Backend strong; the 3-way match just needs its UI, plus a backorder report.
- **CRM — ENHANCE / decide pipeline.** Core (SO/complaint/8D) is real. The Leads/Opps/Quotes pipeline is a scope decision: finish it or explicitly cut it — do not leave it as untested dead backend.
- **Production/MRP — KEEP core, ENHANCE UI.** Output/OEE/mold tracking real; wire machine/mold create-edit and the pause modal. Reconcile the two parallel scheduling systems before they drift (flag, not urgent).
- **HR/Attendance/Leave — ENHANCE.** Solid core with three real gaps: separation pay, year-end leave reconciliation, biometric identity. All additive.
- **Migration tooling — BUILD.** Absent; the single largest go-live blocker.
- **B2B/Portal — REFACTOR (cosmetic).** Functionally works; adopt the design system.
- **Reporting/export — ENHANCE (wire it).** Infrastructure done; wire the 9 missing exporters.

No module warrants a from-scratch rewrite. The codebase's bones are sound; the work is reachability, statutory fidelity, cutover, and the one genuine build (multi-currency).

---

## 7. Sequencing — 6 Monthly Milestones

Each milestone ships user-visible value and is dependency-aware.

| Milestone | Theme | Delivers (user-visible) | RECs |
|---|---|---|---|
| **M1** | Foundation hardening + reachability wins | Payroll void button, 3-way match in bill UI, re-inspection after rework, payroll maker-checker, AR/AP aging reports | REC-01, REC-02, REC-04, REC-07, REC-15 |
| **M2** | Statutory + labor-law correctness | BIR 1601-C/1604-CF/Alphalist DAT + SSS R-3, OT day-type factor, separation/retirement pay, year-end leave reconciliation+pay | REC-06, REC-09, REC-10, REC-17 |
| **M3** | Cutover enablement | Master-data import, opening-balance loader + TB reconciliation, seed the missing transactional legs | REC-03, REC-05, REC-28 |
| **M4** | Finance completeness + controls | Credit memo (AR/AP), period close UI + keep-lock, EWT/2307 + VAT return, idempotency + optimistic locking, bill/budget SoD | REC-13, REC-14, REC-16, REC-18, REC-20, REC-21, REC-30 |
| **M5** | Operational depth + IATF edges | Quarantine/MRB, prod-reject→NCR + pause modal, UOM UI, stock-adjustment queue, machines/molds UI, De Minimis, export-engine wiring, missing PDFs | REC-08, REC-19, REC-22, REC-24, REC-25, REC-26, REC-27, REC-29 |
| **M6** | Differentiator build + pilot/defense prep | JPY multi-currency + consolidation, recurring JE, year-end GL close, audit tamper-evidence, biometric enroll + onboarding, dry-run/parallel-run, PPAP/Calibration UI, portal polish, demo formatting | REC-11, REC-12, REC-31, REC-32, REC-33, REC-34, REC-35, REC-36, REC-37, REC-38, REC-39, REC-40, REC-41, REC-42, REC-43 |

---

## 8. Lists

### The 2-week list (5, ranked)
1. REC-01 — Expose payroll void (S, P0)
2. REC-02 — Wire 3-way match into bill UI (M, P0)
3. REC-15 — AR/AP aging report route + page (S, P1)
4. REC-07 — Re-inspection after rework (S, P0)
5. REC-04 — Payroll maker-checker (S/M, P0)

### The 6-month list
All 43 RECs, sequenced M1→M6 above. Non-negotiable P0 spine: REC-01/02/03/04/05/06/07/08/09/10/11. Differentiator build: REC-12. Cutover: REC-03/05/28/38. Everything else is competitive/polish layered on a hardened, reachable, statutorily-faithful, migratable foundation.

---

## 9. What I Would NOT Add (scope discipline)

1. **Bank reconciliation / closing wizards** — explicitly cut, and correctly. A pilot reconciles via the GL + statements; a rec module is months of work for marginal thesis value. Respect the cut.
2. **Full EDI / OEM automated release feeds (X12/EDIFACT/Odette)** — the manual monthly `delivery_schedules` portal (`0164`) is a pilot-credible stand-in. True EDI is a post-pilot integration project, not thesis scope. (Flag only: model firm-vs-forecast zones if MRP fidelity ever matters — deferrable.)
3. **Cost accounting / per-shot mold depreciation** — cut, and correct for pilot. The `LandedCostService` already borders the cut; do not extend into standard-cost variance or per-shot depreciation. (Minor flag: landed-cost costing arguably sits in the cut zone — leave as-is, don't grow it.)
4. **Customizable react-grid-layout dashboards** — cut. The permission-filtered saved-layout that exists is enough; a drag-grid builder is polish with zero pilot value.
5. **Expense-claim / petty-cash module (A1/A2)** — genuinely absent and PH-common, but I would **defer, not add now**: it is a self-contained new chain that doesn't block any of the three core chains, and the P0 spine (void, match, migration, statutory, SoD) must land first. Add in a post-pilot phase, not the 6-month window. (This is a "not yet," not a "never.")

**One contested cut I do push back on:** fiscal-period **locking** (REC-16). It is listed as NOT BUILDING but is already built, and a real BIR/IATF/JP-parent audit failure mode (back-dating a JE into a filed month) demands it. I recommend formally un-cutting it and building the operating UI. This is the only cut I disagree with.

---

## 10. Coverage Statement

**Read in full:** all 23 API module `routes.php` + key services (Payroll calculator/period, Invoice/Bill/JE, GRN/StockMovement, WorkOrder/Ncr/Inspection/Spc/CoC, DTR/Leave/FinalPay, Approval/AccountingPeriod), the SPA route files + representative pages per module, migrations 0001–0260 (spot-read for the 5 stress-test tables + all flagged features), all statutory export services, seed files (Realistic/Comprehensive/Demo/GovernmentTable), all PDF blade templates, the audit-immutability trigger, restore-drill + backup scripts, and CLAUDE.md + the prior audit backlogs (REBUILD-AUDIT-2026-06-18, GAP-ANALYSIS, DEEP-DESIGN).

**Verified by grep (≥3 name variants) before any "absent" claim:** multi-currency/JPY, credit-memo, EWT/2307/2550, expense-claim/liquidation, quarantine routing, backorder, recurring JE, retained-earnings, row-level-scope, broken-glass, RA-10173, master-data-import, opening-balance, parallel-run.

**Skipped / lighter touch (and why):** the full 173 Feature-test bodies were sampled, not exhaustively read — coverage *presence/absence* was mapped per cluster, but I did not re-run the suite (the ground-truth reports confirm 746/0 as of 2026-06-15). The recruitment/skills/succession/performance-review HR stacks were confirmed present-but-untested and not deep-audited (scope expansion, low pilot-criticality). The Landing/careers marketing surface was verified non-breaking but not feature-audited. Load/concurrency behavior is asserted from `Load/concurrent-payroll.js` existence, not measured.

**Follow-up reading that would sharpen this audit:** (1) trace whether `InvoiceService`/`BillService`/`PayrollGlPostingService` GL postings call the same period guard as `JournalEntryService` (D2.2 — determines if the period lock is complete or JE-only); (2) confirm `SyncBudgetActuals` + `GeneratePreventiveMaintenanceJob` idempotency (F5 residual); (3) reconcile the two production scheduling systems (`production_schedules` vs `wo_operations`) to name the source of truth for capacity before REC-19/REC-27 touch that area.
