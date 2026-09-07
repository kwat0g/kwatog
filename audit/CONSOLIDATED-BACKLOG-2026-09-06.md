# Consolidated Backlog — Audit Pass 2026-09-06

Phase 2 consolidation by the orchestrator. Inputs: 19 unit reports in `audit/*.md`
(all personally reviewed). Raw counts: **5 Critical · 35 High · ~95 Medium · ~102 Low**.
Duplicates merged, cross-module findings resolved to one owning fix below.

Scoring: severity × blast radius, deadline-aware (fixes due Fri 2026-09-11).
Effort: S <2h · M 2–8h · L >8h. Grouping: items touching the same files are marked
`[GROUP x]` and go to one subagent session back-to-back (still one PR each).

---

## TIER A — CRITICAL (fix all, first)

| # | ID | Fix | Files | Sev | Effort |
|---|----|-----|-------|-----|--------|
| A1 | HR-01 | Final-pay + bank-file double pay: FinalPayService must refuse compute (or finalize) while the covering payroll period is Computed/Approved/Finalized-but-undisbursed; BankFileService skips employees whose clearance consumed that row. Test the exact ordering. | HR/Services/FinalPayService.php, Payroll/Services/BankFileService.php | Critical | M |
| A2 | PU-01 | PO `show`/`pdf` bypass PurchaseOrderAccessPolicy → add `canView` to policy, call in both endpoints (mirror PR controller). Visibility-matrix test. | Purchasing/Controllers/PurchaseOrderController.php, Policies/PurchaseOrderAccessPolicy.php | Critical | S |
| A3 | AB-01 | Reversed JEs vanish from TB/IS/BS/COA/budget-actuals: include `posted`+`reversed` rows in period aggregates; reversal event reflected from reversal date. Reconcile with AR/AP aging semantics. Regression test: reversal crossing report windows. | Accounting/Services/Statements/* (4 services), AccountService.php:82, BudgetConsumptionService.php:283 | Critical | M |
| A4 | AS-01 | Disposal month depreciated after derecognition: make `dispose()` catch up depreciation through the prior month inside its transaction AND exclude disposed assets from the disposal-month run (align both halves). Regression test: dispose-then-run. | Assets/Services/AssetService.php, DepreciationService.php | Critical | M |
| A5 | FC-01 | Forecast MRP projection double-counts total+per-customer rows: prefer NULL-customer total row, else sum per-customer (mirror StockOutProjectionService). Invariant test. | Forecasting/Services/ForecastMrpService.php | Critical | S |

## TIER B — HIGH, fast wins first (deadline-ordered)

| # | ID | Fix | Files | Effort |
|---|----|-----|-------|--------|
| B1 | AT-01 | Night-diff premium × `day_type_rate` (DOLE: ND is 10% of the holiday-rated hourly). One-line + monster-test case. | Payroll/Services/PayrollCalculatorService.php:474 | S |
| B2 | PR-01 | Floor PWA zero WOs: comma-joined `status` treated as equality → send array + `whereIn` server-side (validate status values). Test multi-status filter. | spa/src/api/factory.ts, Production/Services/WorkOrderService.php:96 | S |
| B3 | SC-02 | Delivery-create dropdown filters `confirmed` only → include `in_production`, `partially_delivered`. | spa/src/pages/supply-chain/deliveries/create.tsx:51 | S |
| B4 | FC-02 | Recompute silently overwrites manual overrides → skip `method=manual` rows unless explicit flag; report skipped periods. | Forecasting/Services/ForecastingService.php:104 | S |
| B5 | MT-01+MRP-02 | Mold stuck in `maintenance` after MWO complete → restore `available` in complete() mold branch (unless retired). Test asserts post-complete status. | Maintenance/Services/MaintenanceWorkOrderService.php:239 | S |
| B6 | LV-01 | `max_carryover_days` unwireable → add to both FormRequests + LeaveTypeResource. | Leave/Requests/*, Resources/LeaveTypeResource.php | S |
| B7 | LV-02 | Mid-year hires get FULL credits → prorate in EmployeeService::create synchronous path (listener stays idempotent fallback); rehire must not reset `used`. | HR/Services/EmployeeService.php:224, HR/Listeners/InitializeLeaveBalances.php | S |
| B8 | AB-02 | Statement of Account ledger omits credit-note applications → add as negative ledger rows; closing balance must equal aging total. | Accounting/Services/StatementOfAccountService.php | S |
| B9 | PY-02 | Missing gov tables silently compute ₱0 → hard-block compute/approve when any agency lacks a table effective on payroll_date (parity with de-minimis hard-block). | Payroll/Services/Government/* (4), PayrollCalculatorService or period gate | M |
| B10 | CR-01+SC-01 | SO terminal on first invoice → remaining deliveries stuck: allow `invoiced → delivered/partially_delivered` in ALLOWED_TRANSITIONS (billing is not physical terminal) + test deliver-after-invoice sequence. [GROUP 1] | CRM/Services/SalesOrderService.php:69, SupplyChain/Services/DeliveryService.php:916 | M |
| B11 | PS-01+LN-01 (PO half) | PO approval chain stalls at step 2: grant finance_officer `purchasing.po.approve` (+view) in RolePermissionSeeder + add chain-participant branch to PurchaseOrderAccessPolicy. Approval-queue visibility test. [GROUP 2] | RolePermissionSeeder.php, PurchaseOrderAccessPolicy.php | M |
| B12 | LN-01 (loan half) | Company-loan chain stalls at step 2: grant production_manager `loans.approve`+`loans.view` + chain-participant branch in LoanAccessPolicy. Test. [GROUP 2] | RolePermissionSeeder.php, Loans/Policies/LoanAccessPolicy.php | M |
| B13 | RM-01 | ReturnRequest absent from ApprovalTypeRegistry → add entry (kind/label/link/permissions); expose pending-step on resource. Note: RM has no row scope — state explicitly at call site per aggregator rule. | Common/Support/ApprovalTypeRegistry.php, ReturnManagement/Resources/ReturnRequestResource.php | S |
| B14 | LN-02+LN-03+HR-09 | No loan settlement path → expose `recordPayment` via permission-gated route (`loans.write_off`), LoanPaymentType Manual/FinalPay; FinalPayService finalize creates the FinalPay payment and reconciles the ledger. Unblocks separation deadlock. | Loans/routes.php, LoanService.php, Controllers/LoanController.php, HR/Services/FinalPayService.php | M |
| B15 | HR-03 | Clearance signing has no per-department gate → implement the documented check (item dept membership or hr_officer/system_admin); grant finance_officer clearance permissions in seeder. | HR/Services/SeparationService.php:298, RolePermissionSeeder.php | M |
| B16 | HR-04 | No cancel path for initiated separation → add `cancel()` (Cancelled status), guards: no final pay computed, restore employee status, audit. | HR/Services/SeparationService.php, Enums/ClearanceStatus.php | M |
| B17 | HR-02+HR-12 | Duplicated divergent proration → extract one shared service (payroll's calendar-day formula authoritative), FinalPayService delegates; fix CLAUDE.md reference. [GROUP 3] | Payroll/Services/PayrollCalculatorService.php:559, HR/Services/FinalPayService.php:305, CLAUDE.md | M |
| B18 | HR-05 | Salary adjustment applied at approval not effective date → defer live-salary write until effective date (payroll reads history rows incl. future-dated as period start rate). Test both money-wrong cases. [GROUP 3] | HR/Services/SalaryAdjustmentService.php, Payroll/Services/PayrollCalculatorService.php:622 | M |
| B19 | AT-02 | Biometric re-import clobbers manual corrections → refuse (or warn-skip) rows with `is_manual_entry=true` outside locked periods; keep idempotent replay for untouched rows. | Attendance/Services/DTRImportService.php:103 | M |
| B20 | MT-02+MT-03 | MWO machine-status writes bypass MachineStatusChanged → route through MRP MachineService::updateStatus (or dispatch event in-transaction); open `planned_maintenance` downtime row (with maintenance_order_id) at MWO start, close at complete/cancel. Tests: downtime row lifecycle + event emission. | Maintenance/Services/MaintenanceWorkOrderService.php, MRP/Services/MachineService.php | M |
| B21 | AS-02 | Backdated asset bricks all depreciation runs → catch-up path posts supplemental JE+row for assets missing from posted periods (backfill actually fills), or refuse backdated entry before last unposted month; SPA runner sends `backfill`. | Assets/Services/DepreciationService.php:48-240, spa pages/assets/DepreciationRunner.tsx | M |
| B22 | AS-03 | Disposal bypasses 4-step workflow → wire `asset_disposal` workflow via ApprovalService (fix seeder roles to real roles: production_manager→manager step etc. per seeded role slugs) OR maker-checker gate; decide in PR description. | Assets/Services/AssetService.php:178, routes.php, WorkflowSeeder.php | M |
| B23 | PR-03 | resume()/start() re-acquire machine+mold without occupancy recheck → assertMachineAvailable-style gate in both (machine status + mold status + no other in_progress WO). Race test. | Production/Services/WorkOrderService.php:313,440 | M |
| B24 | PU-02 | PO mutations have no row ownership → add canManageDraft/canCancel/canSend equivalents to policy, enforce inside locked transactions (mirror PR service). [GROUP 2] | PurchaseOrderController.php, PurchaseOrderService.php, Policy | M |
| B25 | BP-01 | Supplier portal bearer/sessionStorage → session driver migration (mirror customer portal): drop token from login response, storage key, Authorization header; tests updated. | spa/src/api/b2b/client.ts, supplier.ts, B2B auth controllers, config/auth.php | M |
| B26 | IN-01 | Reservation-linked manual issue broken → release-then-move + ownership/status/qty guards (or delete the parameter; decide in PR). | Inventory/Services/MaterialIssueService.php:102 | M |
| B27 | MRP-01 | Netting ignores safety_stock/reorder_point/MOQ → floor available by safety_stock; round PR qty up to MOQ multiples. Tests. | MRP/Services/MrpEngineService.php:858 | M |
| B28 | PR-02 | Floor PWA cannot record rejects → collect defect types on the floor form (endpoint exists) or unclassified bucket feeding Pareto; surface 422 field errors. | spa/src/pages/factory/RecordOutput.tsx, Production/Requests/RecordOutputRequest.php | M |
| B29 | PU-03 | Dept-head auto-approval dead code + latent 403 trap → remove dead branch + setting reference (feature never worked; full chain is the safe behavior). | Purchasing/Services/PurchaseRequestService.php:313, SettingsSeeder | S |
| B30 | MRP-03 | MRP rerun clobbers concurrently-submitted draft auto-PR → lockForUpdate + conditional rewrite only if still draft. | MRP/Services/MrpEngineService.php:316 | S |

## TIER C — MEDIUM, only if Tier A+B finishes early (selected)

AB-03 (bill centavo contract, S) · AB-04 (bill budget enforcement semantics, S) ·
PY-03 (13th-month clamp, S) · PY-05 (13th-month payroll_date bound, S) ·
MT-05 (predictive dedup ILIKE, S) · SC-03/SC-04 (restore routes + file deletion, S/M) ·
LV-03 (year-end live-year guard, S) · LV-04 (conversion_rate clamp at separation, S) ·
QC-03 (receive-with-QC tolerance clash, M) · QC-04 (spec-less outgoing fallback dead end, M) ·
BP-03 (customer portal internal resource leak, S) · PS-02/PS-03 (badge + purchasing widget row scopes, M) ·
MRP-06 (raw machine/mold id leak, S) · PU-05/IN-02 (quantity_received widen to 15,3 — migration 0061 dep, numeric prefix OK, S) ·
IN-04 (GRN GL 3dp valuation, S) · HR-06 (voided-period final pay zero, S) · RM-02+AB-06 (credit-note party guard + RM header validation + fixture, M) ·
AT-09 (stale DTR rows recompute sweep, M) · FC-04/FC-05 (past-period guard + chart sort, S)

## DEFERRED (written reasons — revisit post-deadline)

| ID | Reason |
|----|--------|
| PR-04 (closed period blocks floor output) | Needs an explicit accounting×production policy call (degrade-to-manual vs hard stop is pinned by an existing test). User decision required. |
| PR-05 (factory QC FAIL false promise) | Cross-module product decision on what quick-check should create (draft inspection vs NCR + hold); M–L; not safely decided asleep. |
| QC-01/CR-02 (complaint NCR replacement WOs) | Product decision: should complaint-scrap spawn replacement demand? Seam characterized; both sides documented. |
| QC-05 (in-process gate advisory-only) | Design-level IATF scope decision (hard gate at WO complete?); L effort. |
| AS-04 (declining-balance asymptote) | Medium; rare method choice; workaround = straight-line. Fix post-deadline with crossover-to-SL. |
| LN-04/LN-05 (interest machinery, disbursement JE) | Dormant (rates seeded 0); accounting policy decision on disbursement booking. |
| LV-05/LV-06/LV-07 (is_paid inert, filer-vs-employee guard, separation recording) | Medium risk; all 8 seeded types paid; guards present for the common path. |
| HR-07 (final-pay JE gross) | Withholding policy for separation pay is a finance decision; flagged. |
| BP-02 (vendor deactivate ≠ portal deactivate) | Medium; seam hook spans Accounting+B2B; fix post-deadline. |
| MT-04/06/07/08, AT-03/05/07/10, SC-05/06/10, MRP-04/05/07/08/09, PU-04/06/07/08, IN-03/05/06, PY-04/06/07/08/09/10, AB-05/06/09, QC-02/06/09/10, FC-03, all Lows | Medium/Low severity, bounded blast radius, or dormant surfaces. Catalogued in unit reports for the follow-up pass. |
| Docs drift (SCHEMA.md CRM/LEAVE/LOANS/QUALITY/PAYROLL sections, SEEDS.md roles) | Batch doc-regen after fixes land so docs match final state. |

---

## Execution plan (Phase 3)

- Strictly sequential subagents, one PR per fix, branch `fix/<id>-<slug>` off `main`.
- Order: A1→A5, then B1→B30 as capacity allows (fast S-items first within waves to bank progress).
- Groups to one session back-to-back: GROUP 1 (B10), GROUP 2 (B11,B12,B24 — purchasing row-scope/chain), GROUP 3 (B17,B18 — payroll×HR money).
- Per PR: fix + regression test + `--filter` module suite green + push + `gh pr create` (never merge).
- Migration naming rule honored if any fix needs one (only PU-05/IN-02 candidate; depends on `0061_` numeric → `0482_` prefix is safe — confirm at fix time).
- Capacity estimate: ~4.5 days. Expect all of Tier A + ~18–22 of Tier B; remainder deferred with reasons above.

## Phase 4
Full suite once (`ogami_test`, single runner), fixed-vs-deferred tally, open-PR list grouped by severity.
