# Ogami ERP — Rebuild & Enhance Ticket Backlog (2026-07-08)

> Import-ready tickets derived from `docs/REBUILD-AUDIT-2026-07-08.md` (43 REC cards).
> Ordered by priority (P0 → P3), then module. Each ticket is paste-ready for Linear / Jira / GitHub Issues.
> Estimate legend: **S** ≤2d · **M** 3d · **L** 5d · **XL** 8–15d.

---

## Tier P0 — Foundation / pilot blockers

---

### [REC-01] Expose payroll void (route + controller + button + SoD actor)

**Labels:** `priority:P0` · `module:payroll` · `bucket:half-built` · `bucket:failure-mode`
**Estimate:** S (1 day)

**Description**
Maria (HR clerk) finalizes a run with an error and is stuck — the one sanctioned correction path the code was already built for is unreachable. Without it, every bad finalized run is uncorrectable except via a blunt next-period adjustment, contradicting the void-and-reverse design the codebase already ships. The thesis "void-and-reverse" claim is undemonstrable.

**Acceptance criteria**
- [ ] `POST /payroll-periods/{period}/void` route added to `Payroll/routes.php`, guarded by `payroll.periods.void` permission.
- [ ] `PayrollPeriodController::void(VoidPayrollRequest)` delegates to the existing `PayrollPeriodService::void()`; a `reason` field is required and validated.
- [ ] `voided_by` (actor) is persisted on the period row when a void succeeds.
- [ ] SPA void button rendered on `periods/detail.tsx` behind `payroll.periods.void`, opening a confirm modal that captures the reason.
- [ ] Feature test asserts a finalized period can be voided via the route and that the reversing entries are produced.

**Evidence:** `PayrollPeriodService.php:451`; no route in `Payroll/routes.php`; `PayrollPeriodController.php` ends at `runThirteenthMonth:180`.
**Dependencies:** REC-04 (shares the controller/actor change).

---

### [REC-04] Payroll maker-checker + attributable approver/finalizer

**Labels:** `priority:P0` · `module:payroll` · `bucket:security-sod`
**Estimate:** S/M (2 days)

**Description**
For a ₱-material 200-employee run, one `finance_officer` runs the entire lifecycle (create → compute → approve → finalize) with no second set of eyes and no record of who approved. `payroll_periods` has no `approved_by`/`finalized_by`. This is audit-blocking — a JP-parent/BIR auditor cannot attribute the approval, and it is a salary-fraud vector across the largest cash-out process.

**Acceptance criteria**
- [ ] `approve()` and `finalize()` in `PayrollPeriodService` accept the acting `User`.
- [ ] Migration adds `approved_by`, `computed_by`, `finalized_by` columns to `payroll_periods`, populated on each transition.
- [ ] Approval is rejected when approver == computer unless the actor holds `payroll.periods.self_approve_override`.
- [ ] Seeded `hr_officer`/`finance_officer` permissions split so that create and approve are not both default to one role.
- [ ] Feature test asserts a same-actor approve is blocked without the override permission and allowed with it.

**Evidence:** `PayrollPeriodService.php:216,369`; `0032_create_payroll_periods_table.php:22`; `RolePermissionSeeder.php:411-415`.
**Dependencies:** REC-01 (shares the controller/actor change).

---

### [REC-06] Statutory filing fidelity — 1601-C, 1604-CF, Alphalist `.DAT`, wire SSS R-3

**Labels:** `priority:P0` · `module:payroll` · `bucket:localization` · `bucket:reporting`
**Estimate:** L (5 days)

**Description**
Maria files these monthly/annually; a 1-row summary and a CSV Alphalist are rejected by BIR/eBIRForms validation. The "full PH statutory" differentiator fails at the actual point of filing. SSS R-3 is already built but orphaned.

**Acceptance criteria**
- [ ] `Bir1601CService` expands to a taxable-vs-exempt split with tax-due-vs-withheld reconciliation (not a 1-row stub).
- [ ] `Bir1604CfService` emits the full annual reconciliation, not a summary row.
- [ ] Alphalist emits the BIR `.DAT` schema: fixed field order, header/trailer records, control totals, ATC codes.
- [ ] `SssR3Export` is wired: route added, `ExportRunner::MAP` entry registered, and a statutory-page button triggers it.
- [ ] Tests assert DAT field ordering / control totals and the 1601-C taxable/exempt split.

**Evidence:** `Bir1601CService.php:44-57`; `Bir1604CfService.php:43-52`; `BirAlphalistService.php:78`; `SssR3Export.php:26` (never instantiated).
**Dependencies:** gov-table currency (OGAMI-101). Full eBIRForms XML deferred to P1.

---

### [REC-09] OT premium day-type stacking correction (statutory)

**Labels:** `priority:P0` · `module:payroll` · `bucket:missing-feature`
**Estimate:** S (1–2 days)

**Description**
Every rest-day/holiday OT is under-paid — a DOLE wage violation. OT-on-holiday computes `1.25 × day_multiplier` instead of the statutory 1.69× (rest/special) / 2.6× (regular holiday) hourly factor.

**Acceptance criteria**
- [ ] Flat `OT_PREMIUM=1.25` stacking replaced with a day-type OT factor table (regular 1.25, restday 1.69, special-restday 1.95, regular-holiday 2.6, etc.).
- [ ] Night differential stacks on OT hours where applicable.
- [ ] Unit tests cover each day type asserting the correct hourly factor.
- [ ] Existing regular-OT computations remain unchanged (regression test).

**Evidence:** `PayrollCalculatorService.php:282-287,62`; `DTRComputationService.php:292-305`.
**Dependencies:** none.

---

### [REC-10] Reconcile the two year-end leave mechanisms + pay the conversion

**Labels:** `priority:P0` · `module:leave` · `module:payroll` · `bucket:cross-cutting`
**Estimate:** M (3 days)

**Description**
Employees lose convertible leave balance and are never paid the encashment (money-losing bug), and the two year-end jobs corrupt balances if both run. `ProcessYearEndLeave` zeroes remaining that `ResetLeaveBalancesForYear` reads for carry-forward → order-dependent double-handling with no carry cap; `days_converted` never becomes a payroll line.

**Acceptance criteria**
- [ ] A single source of truth for year-end: one job computes forfeit/convert/carry with a carry cap; the other consumes its output, not raw `remaining`.
- [ ] `days_converted` emits a payroll encashment line into the run.
- [ ] Carry-cap is configurable.
- [ ] The docblock / `now()->year` drift is fixed.
- [ ] Test asserts running both jobs in either order yields identical, non-double-counted balances and a paid encashment line.

**Evidence:** `ProcessYearEndLeave.php:104-134,50`; `ResetLeaveBalancesForYear.php:62`; grep Payroll `encash` = 0.
**Dependencies:** REC-04 (payroll line plumbing).

---

### [REC-02] Wire 3-way match into the bill-create flow + variance snapshot view

**Labels:** `priority:P0` · `module:purchasing` · `module:accounting` · `bucket:missing-feature`
**Estimate:** M (3 days)

**Description**
Ben (AP clerk) types ~50 invoices/week as free-text with zero PO validation; the entire 3-way match engine never runs. No qty/price variance detection, no GRN-short catch, no override trail — the headline P2P control is inert, exposing AP fraud/error.

**Acceptance criteria**
- [ ] `CreateBillData` + `bills/create.tsx` gain a PO selector and a per-line `item_id`.
- [ ] Bill create sends `purchase_order_id`, `items.*.item_id`, and `allow_override`.
- [ ] `billsApi.threeWayMatch(id)` client hits the existing `GET /three-way-match/{bill}`.
- [ ] `bills/detail.tsx` renders `three_way_match_snapshot` and `has_variances`.
- [ ] Over-receipt tolerance is surfaced in settings (links REC-24).
- [ ] Feature test asserts a qty/price variance is detected and an override is trailed.

**Evidence:** `StoreBillRequest.php:21,30`; `BillService.php:135-181`; `ThreeWayMatchService.php`; `bills/create.tsx:98-113`; `Purchasing/routes.php:67`.
**Dependencies:** REC-24 (tolerance UI), REC-23 (backorder view complements).

---

### [REC-05] Opening-balance loader + trial-balance reconciliation

**Labels:** `priority:P0` · `module:accounting` · `module:inventory` · `bucket:migration`
**Estimate:** L (5 days)

**Description**
Tanaka cannot start the books — GL/AR/AP/inventory all begin at zero with wrong WAC. Migrated books cannot be proven equal to source books (IATF/JP audit blocker, OGAMI-112).

**Acceptance criteria**
- [ ] Opening-balance JE generator must net to a provided legacy TB and rejects when unbalanced.
- [ ] `StockMovementType::Opening` case added; bulk opening-stock loader accepts cost basis and seeds correct WAC.
- [ ] Open-invoice and open-bill importers so AR/AP aging and dunning start correct.
- [ ] TB-match reconciliation report compares loaded balances to the source TB.
- [ ] Test asserts an unbalanced opening TB is rejected and a balanced one posts.

**Evidence:** `JournalEntryService` grep `opening` = 0; `StockMovementType.php` (no Opening); `StatementOfAccountService.php:40`.
**Dependencies:** REC-03 (COA/item import).

---

### [REC-08] Hold / quarantine workflow for nonconforming stock (MRB)

**Labels:** `priority:P0` · `module:inventory` · `module:quality` · `bucket:missing-module`
**Estimate:** M (3–4 days)

**Description**
Warehouse staff have nowhere to physically segregate rejected material; IATF §8.7 requires it. The `Quarantine` zone enum is dead. Nonconforming finished goods and rejected incoming resin commingle with good stock and remain issuable.

**Acceptance criteria**
- [ ] On inspection/NCR fail (in-process, outgoing, use_as_is), stock is moved to a Quarantine location and a "held" status is set that blocks issue.
- [ ] An MRB disposition record ties the NCR disposition to the physical move.
- [ ] Release-from-quarantine occurs on rework-pass or scrap.
- [ ] The `WarehouseZoneType::Quarantine` enum is referenced by the service (no longer dead).
- [ ] Test asserts held stock cannot be issued and is released on disposition.

**Evidence:** `WarehouseZoneType.php:13` (enum unused); grep `quarantine` in Inventory/Quality services = 0.
**Dependencies:** REC-07 (rework re-inspection feeds release).

---

### [REC-07] Re-inspection after rework/replacement WO

**Labels:** `priority:P0` · `module:quality` · `bucket:quality` · `bucket:missing-feature`
**Estimate:** S (1 day)

**Description**
Joel's reworked bushings ship without re-measurement; IATF §8.7.1.4 mandates re-verification. Reworked/replacement WOs are created without `sales_order_id`, so `TriggerOutgoingQC` skips them — reworked parts ship with an auto-CoC and zero actual re-measurement. The single most audit-exposed hole in the quality spine.

**Acceptance criteria**
- [ ] Either the parent's `sales_order_id` is passed into the rework/replacement WO in `NcrService::close()`, OR the `if (! $wo->sales_order_id) return;` skip in `TriggerOutgoingQC` is removed so any WO carrying `parent_ncr_id` triggers outgoing QC.
- [ ] A completed rework WO creates an outgoing inspection record.
- [ ] CoC is not auto-generated until the re-inspection passes.
- [ ] Test asserts a completed rework WO produces an outgoing inspection.

**Evidence:** `NcrService.php:268-299`; `TriggerOutgoingQC.php:42-43`; `WorkOrderService.php:137`.
**Dependencies:** none.

---

### [REC-03] Master-data import toolkit (employees / customers / vendors / items / BOMs / molds / machines / COA)

**Labels:** `priority:P0` · `module:cross` · `bucket:migration`
**Estimate:** XL (8–10 days)

**Description**
A real Ogami migrating off Excel/legacy cannot hand-key 200 employees + hundreds of items + BOMs. Without this, no pilot cutover is possible (OGAMI-111) — the single largest go-live blocker.

**Acceptance criteria**
- [ ] `import_batches` table tracks each batch with status and per-row error rows.
- [ ] Generic CSV pipeline: fixed schema per entity (no mapping UI per CLAUDE.md cut) → staging → validate (dry-run preview with error rows) → commit (single txn per batch).
- [ ] Batch rollback reverts a committed batch.
- [ ] Reuses the `DTRImportService` per-row-catch pattern with batch tracking added.
- [ ] Employees, items, and COA importers ship first (highest volume) with validation tests.

**Evidence:** routes grep = 0; `DTRImportService.php:82`; `REBUILD-AUDIT-2026-06-18-BACKLOG.md:77`.
**Dependencies:** blocks REC-05 (opening balances build on item/COA import).

---

### [REC-11] Systematic row-level data scope (dept + cost-center)

**Labels:** `priority:P0` · `module:cross` · `bucket:cross-cutting` · `bucket:security`
**Estimate:** L (5 days)

**Description**
A dept head can leak another department's rows through any list endpoint whose author forgot the hand-rolled scope block. There is no cost-center scope at all. Data-confidentiality breach across 200 employees; RA 10173 exposure.

**Acceptance criteria**
- [ ] A shared `ScopedByDepartment` trait / global scope resolves visibility from the user's grants (not role-slug string equality).
- [ ] The scope is applied centrally rather than copy-pasted per service.
- [ ] Every list service is audited and migrated to adopt it (Employee/Leave/Overtime and beyond).
- [ ] A cost-center dimension scope is added for Accounting/budgeting.
- [ ] Test asserts a dept head cannot read another department's rows through a previously-unscoped endpoint.

**Evidence:** grep `DataScope|scopeVisibleTo` = 0; `EmployeeService.php:30-42` (slug equality `:36`).
**Dependencies:** REC-13 (permission-vs-role source-of-truth unification helps).

---

## Tier P1 — Real-world usable

---

### [REC-12] JPY / multi-currency + parent-consolidation pack

**Labels:** `priority:P1` · `module:accounting` · `bucket:missing-module` · `bucket:schema` · `bucket:localization`
**Estimate:** XL (10–15 days)

**Description**
Tanaka's core monthly obligation is a JPY-translated TB/BS/IS for the Japanese parent; today he re-keys PHP CSV into Excel with a manual rate. The signature JP-parent differentiator does not exist and every money column is currency-blind. Largest true build; sequence after P0 hardening because it touches every monetary write path.

**Acceptance criteria**
- [ ] `fx_rates` table with dated rates.
- [ ] `currency_code` + transaction amount + functional amount + `exchange_rate` added to JE lines, invoices, bills, and POs (schema sketch: `journal_entry_lines += currency_code CHAR(3), txn_debit/credit DECIMAL(15,4), fx_rate DECIMAL(18,8)`).
- [ ] Transaction currency + rate captured at document date on every monetary write path.
- [ ] Realized FX gain/loss posted on collection/payment.
- [ ] JPY-translated statement export (current-rate method) with a CTA line + intercompany reconciliation.
- [ ] Tests assert FX gain/loss posting and a JPY-translated TB that balances.

**Evidence:** `StatementOfAccountService.php:77`; no currency column in any migration; OGAMI-105/106/107/113.
**Dependencies:** touches every monetary write path — sequence after P0 hardening.

---

### [REC-13] Credit-memo / credit-note instrument (AR + AP)

**Labels:** `priority:P1` · `module:accounting` · `bucket:missing-module` · `bucket:failure-mode`
**Estimate:** L (5 days)

**Description**
Ben and Tanaka have no way to credit a price dispute, damaged-goods claim, or over-billing; a partially-collected disputed invoice is stuck. Corrections become manual JEs that break subledger↔GL reconciliation; BIR credit-note VAT reversal is undocumented; RMA models credits as negative invoices that pollute aging.

**Acceptance criteria**
- [ ] First-class `credit_notes` table with distinct BIR document numbering.
- [ ] AR customer-credit and AP supplier-credit both supported.
- [ ] Application/offset of a credit note against open invoices/bills.
- [ ] `Disputed` invoice state added.
- [ ] The RMA negative-invoice hack is retired in favor of a linked credit note.
- [ ] Test asserts a credit note offsets a partially-collected invoice without polluting aging.

**Evidence:** grep `credit.?memo` = 0; `ReturnRequestService.php:299`; `InvoiceService.php:260`.
**Dependencies:** REC-15 (aging must treat credits correctly).

---

### [REC-14] Accounting period close/reopen UI

**Labels:** `priority:P1` · `module:accounting` · `bucket:half-built` · `bucket:cross-cutting`
**Estimate:** S (2 days)

**Description**
Tanaka's single most important month-end action (lock the month) is not clickable — backend + reopen-reason trail exist but no screen. Month-end control is API-only and the reopen audit trail is invisible.

**Acceptance criteria**
- [ ] `accounting/periods` list page renders periods with close/reopen buttons.
- [ ] A reason modal captures the reopen reason.
- [ ] Reopen history is displayed on the page.
- [ ] An api client wires to the existing service; the page is guarded by the existing `accounting.periods.manage` permission.
- [ ] Close/reopen round-trip verified against the backend.

**Evidence:** `AccountingPeriodService.php:50,87`; no `spa/src/pages/accounting/periods`.
**Dependencies:** none (see REC-16 for scope note).

---

### [REC-15] AR/AP aging reports (route + page + export)

**Labels:** `priority:P1` · `module:accounting` · `bucket:reporting` · `bucket:missing-feature`
**Estimate:** S (2 days)

**Description**
CFO and AP clerk cannot answer "who owes what, aged" or "what's 90+ days overdue" — the services compute the breakdowns every dashboard load and discard them. The core monthly finance deliverable is currently manual/Excel.

**Acceptance criteria**
- [ ] `GET /accounting/ar-aging` and `GET /accounting/ap-aging` return the existing `by_customer`/`by_vendor` breakdowns.
- [ ] Two SPA report pages render aging bucket columns.
- [ ] CSV export is wired through the REC-25 export engine.
- [ ] Credit notes (REC-13) are handled correctly within the buckets.
- [ ] Test asserts the aging buckets sum to the open AR/AP balance.

**Evidence:** `InvoiceService.php:339,375`; `BillService.php:350,386`; no aging route.
**Dependencies:** REC-13 (credit-note handling in buckets).

---

### [REC-16] Retain fiscal-period lock (contest the scope cut)

**Labels:** `priority:P1` · `module:accounting` · `bucket:cross-cutting` · `bucket:scope-disagreement`
**Estimate:** S (1 day: verification + doc)

**Description**
CLAUDE.md:81 lists "fiscal period LOCKING" as NOT BUILDING, but it is fully built and is the control that makes the GL audit-defensible for a BIR-registered, IATF, JP-parent-consolidated company. Lead auditor **disagrees with the cut**: an auditor will ask "prove no one back-dated a JE into a closed, filed month." Without it, any `journal.create` holder could silently alter a filed 1601-C / financial statement.

**Acceptance criteria**
- [ ] The lock is retained and formally un-cut in CLAUDE.md (move out of NOT BUILDING).
- [ ] Verify Invoice/Bill/Payroll GL postings call the same period guard as JE (resolves D2.2).
- [ ] Any subledger posting path found bypassing the guard is fixed to enforce it.
- [ ] REC-14 UI operates the lock.
- [ ] Test asserts a back-dated posting into a closed period is rejected across JE and subledger paths.

**Evidence:** `AccountingPeriodService.php:16-24`; enforced `JournalEntryService.php:95,183`; CLAUDE.md:81.
**Dependencies:** REC-14.

---

### [REC-17] Separation / retirement pay (RA 7641) + final-pay tax

**Labels:** `priority:P1` · `module:hr` · `module:payroll` · `bucket:missing-feature` · `bucket:localization`
**Estimate:** M (3 days)

**Description**
For a 200-employee plant, separation pay (½–1 month/year, authorized-cause) and RA 7641 retirement pay (22.5 days/year) are the most legally-loaded final-pay components — both absent. Result: illegal final pay on any authorized-cause termination or retirement, and no withholding / final 2316 for leavers.

**Acceptance criteria**
- [ ] `FinalPayService::compute()` adds years-of-service-based separation pay and RA 7641 retirement pay.
- [ ] Tax treatment applied: separation benefits exempt, taxable components taxed.
- [ ] The final BIR 2316 is driven off this computation.
- [ ] Tests cover authorized-cause separation and retirement pay math per years-of-service.

**Evidence:** `FinalPayService.php:42-57`; grep `separation_pay|RA 7641|tax` = 0.
**Dependencies:** REC-06 (2316 fidelity).

---

### [REC-22] De Minimis benefits UI + test

**Labels:** `priority:P1` · `module:payroll` · `bucket:half-built`
**Estimate:** M (2 days)

**Description**
Maria cannot enter rice/meal/uniform allowances (which feed taxable computation every run) without hitting the raw API. Every payroll run's tax computation depends on data no clerk can enter.

**Acceptance criteria**
- [ ] `payroll/de-minimis` list + create page built.
- [ ] An api client wires to the existing `DeMinimisService` routes.
- [ ] Feature test asserts the tax-exempt ceiling logic (excess over ceiling becomes taxable).
- [ ] The page is guarded by the appropriate payroll permission.

**Evidence:** `DeMinimisService.php` (292L); routes `Payroll/routes.php:93-98`; grep `de-minimis` in `spa/src` = 0.
**Dependencies:** none.

---

### [REC-18] EWT/2307 capture on bills + VAT return (2550M/Q)

**Labels:** `priority:P1` · `module:accounting` · `module:purchasing` · `bucket:localization`
**Estimate:** L (5 days)

**Description**
Ogami as a withholding agent must issue 2307 to suppliers (rent 5%, professional 5–10%, goods 1%, services 2%) and file periodic VAT. Without it there is no EWT-payable leg, no supplier cert, and no VAT-payable summary — blocking P2P go-live and creating supplier friction.

**Acceptance criteria**
- [ ] `ewt_rate` / `tax_withheld` + ATC fields added to bills.
- [ ] The EWT-payable leg is posted on bill recording.
- [ ] A 2307 PDF is generated per bill.
- [ ] A period VAT-return report aggregates output VAT (invoices) − input VAT (bills) for 2550M/Q.
- [ ] Credit notes (REC-13) correctly adjust the VAT return.
- [ ] Tests cover EWT posting and VAT-return aggregation.

**Evidence:** `Bill.php:23-42`; grep `2307|2550|ewt` = 0.
**Dependencies:** REC-13 (credit notes affect VAT).

---

### [REC-21] Idempotency keys on financial document creates

**Labels:** `priority:P1` · `module:accounting` · `bucket:failure-mode`
**Estimate:** M (2–3 days)

**Description**
A lost response on a collection/bill/JE submit makes Maria/Ben re-submit → a duplicate posted document with a fresh sequence number, hard to detect after the fact. Double-posted JE over-credits AR/AP; duplicate bills (OGAMI-104).

**Acceptance criteria**
- [ ] `X-Idempotency-Key` middleware applied to POST on collections, bills, invoices, and JE (reusing the Production/Edge pattern), or natural-key unique constraints (e.g. collection `reference_number`).
- [ ] A replayed request with the same key returns the original document rather than creating a duplicate.
- [ ] The SPA generates a key per submit.
- [ ] Test asserts a duplicate submit with the same key posts only one document.

**Evidence:** `InvoiceService.php:281-340` (no dedup); `WorkOrderOutputService.php:50-52` (pattern).
**Dependencies:** none.

---

### [REC-35 placeholder — see P2]

---

### [REC-24] UOM conversion — seed factors + receiving/issue UI selector

**Labels:** `priority:P1` · `module:inventory` · `bucket:missing-feature`
**Estimate:** M (3 days)

**Description**
Resin ordered in BAG and stored in KG cannot actually be received in bags — the entire multi-UOM value prop is inert (backend + tests only). `factor()` throws for every item if a code is ever passed; the warehouse cannot transact in purchase units.

**Acceptance criteria**
- [ ] `ItemUomConversion` factors are seeded for the resin/material items.
- [ ] GRN-create and material-issue-create gain a UOM selector emitting `received_uom_code` / `issued_uom_code`.
- [ ] An item-detail tab manages conversions per item.
- [ ] Over-receipt tolerance is exposed in `admin/settings`.
- [ ] Test asserts a bag→kg receipt converts correctly and does not throw.

**Evidence:** `UomConversionService.php:36,66`; `GrnService.php:121-141`; no UOM in `grn/create.tsx`; `ItemUomConversion` seed = empty.
**Dependencies:** pairs with REC-02 (bill match uses the same units).

---

### [REC-26] Stock-adjustment approval queue + reason codes (UI)

**Labels:** `priority:P1` · `module:inventory` · `bucket:half-built` · `bucket:missing-feature`
**Estimate:** S (2 days)

**Description**
Warehouse can create adjustments but never approve them from the SPA; the IATF reason taxonomy is invisible; if the threshold is ever set >0, above-threshold adjustments vanish unapprovable. Stock corrections silently stuck `pending`, with no supervisor sign-off or reason code.

**Acceptance criteria**
- [ ] Adjustments list + a pending-approval queue with an approve button.
- [ ] A `reason_code` field is added to the create form.
- [ ] `stockAdjustmentsApi.approve` client wired to the existing `approve()`.
- [ ] Cycle-count variances are routed through the same reason-coded, approvable path.
- [ ] Test asserts a created adjustment can be approved and reason-coded from the API.

**Evidence:** `StockAdjustmentService.php:75-132`; `stock-adjustments/create.tsx:21`; `stock.ts:15-18` (no approve).
**Dependencies:** none.

---

### [REC-19] Production-reject → NCR path (WO source + pause modal)

**Labels:** `priority:P1` · `module:production` · `module:quality` · `bucket:quality`
**Estimate:** M (2–3 days)

**Description**
Joel escalates high-scrap runs verbally/on paper because a WO with no formal inspection has no NCR path, and every downtime lands as "breakdown/Manual pause." Floor defects don't enter the NCR/Pareto loop and OEE downtime analytics are poisoned.

**Acceptance criteria**
- [ ] `WorkOrderReject` added to `NcrSource`; `CreateNcrRequest` accepts `work_order_id`.
- [ ] `production_manager` granted the NCR-raise permission.
- [ ] The hardcoded `pause(id,'Manual pause','breakdown')` is replaced with a modal capturing category + reason (backend already accepts both).
- [ ] A high-scrap threshold alert / auto-NCR fires on `scrap_rate` breach.
- [ ] Test asserts a WO-sourced NCR is created and a paused WO records the chosen category.

**Evidence:** `NcrSource.php:11-12`; `CreateNcrRequest.php:36-44`; `work-orders/detail.tsx:111`; `WorkOrderOutputService.php:125`.
**Dependencies:** none.

---

### [REC-27] Machines & Molds create/edit UI

**Labels:** `priority:P1` · `module:mrp` · `bucket:half-built`
**Estimate:** M (2 days)

**Description**
PPC/Joel cannot onboard a new machine or register a replacement mold when one hits shot-limit — master data is seed-only. Any new equipment requires DB/seed access.

**Acceptance criteria**
- [ ] Create/edit forms for machines calling the existing `store/update` routes.
- [ ] Create/edit forms for molds calling the existing `store/update` routes.
- [ ] Both wired into the existing index/detail pages.
- [ ] Forms use RHF + Zod matching backend validation.
- [ ] A new machine and a replacement mold can be created end-to-end from the SPA.

**Evidence:** `MRP/routes.php:32-34,44-46`; no `machinesApi.create`/`moldsApi.create` in `spa/src`.
**Dependencies:** none.

---

### [REC-23] Backorder / open-PO report + SO credit hold-release

**Labels:** `priority:P1` · `module:purchasing` · `module:crm` · `bucket:missing-feature`
**Estimate:** M (3 days)

**Description**
Ben can't see which PO lines are still owed on partial deliveries; a finance manager can't authorize a one-off over-limit SO without editing the customer's master credit limit. Partial-receipt matching is blind and credit control is a binary wall with no exception path.

**Acceptance criteria**
- [ ] `GET /purchasing/purchase-orders?status=partially_received` returns an open-qty (backorder) report.
- [ ] An SPA page renders open PO lines with remaining quantities.
- [ ] `on_hold` / `credit_hold` SO state added with a release action gated by permission and audited.
- [ ] Test asserts a partially-received PO surfaces the correct open qty and an SO can be placed on/off credit hold.

**Evidence:** grep `backorder` in Purchasing = 0; `SalesOrderService.php:73`; `SalesOrderStatus.php:9-15`.
**Dependencies:** none.

---

### [REC-25] Wire the export engine to all report modules + payroll register + DTR export

**Labels:** `priority:P1` · `module:cross` · `bucket:reporting`
**Estimate:** M (3–4 days)

**Description**
The scheduler, column-selector, and scheduled-export CRUD are built but wired only to HR employees; every other "export this list" 404s. No payroll register, no inventory valuation, no DTR export, no AR/AP aging export — all advertised, all unreachable.

**Acceptance criteria**
- [ ] `ExportRunner::MAP` entries + `registerColumns()` added for payroll register, inventory valuation/stock-card, AR/AP aging, and DTR.
- [ ] A payroll-register generator is implemented for `DocumentType::PayrollRegister`.
- [ ] The `ColumnSelectorModal` export button is mounted on each corresponding list page.
- [ ] DTR export endpoint added driving off `DTRComputationService`.
- [ ] Test asserts each newly-mapped export produces a non-throwing file.

**Evidence:** `ExportController.php:136-148` vs `ExportRunner.php:22-24`; `DocumentType::PayrollRegister` (no generator).
**Dependencies:** REC-15 (aging), REC-06 (payroll data).

---

### [REC-28] Seed the missing transactional legs (collections, bill_payments, work orders + outputs)

**Labels:** `priority:P1` · `module:cross` · `bucket:seed-realism`
**Estimate:** S (1–2 days)

**Description**
The "three chains end-to-end" thesis claim breaks in a live demo — Collections/Bill-Payments/Work-Orders pages render empty and AR/AP aging + GL won't tie out. Paid states are cosmetic column-sets, not transaction-backed.

**Acceptance criteria**
- [ ] Real `collections` seeded through the collection service (not `amount_paid` fakes).
- [ ] Real `bill_payments` seeded through the payment service.
- [ ] A spread of work orders with outputs + in-process QC seeded across the same 6-month window as attendance.
- [ ] AR/AP aging and GL tie out against the seeded transactions after seeding.
- [ ] Chain-1 production and cash-collection stages render populated in the SPA.

**Evidence:** grep `collections|bill_payments|work_orders` in `database/seeders` = empty; `ComprehensiveDemoSeeder.php:195-231`.
**Dependencies:** none.

---

### [REC-29] Missing statutory/operational PDFs — Official Receipt, Delivery Receipt, GRN, Picking List + void watermarks

**Labels:** `priority:P1` · `module:accounting` · `module:inventory` · `module:supply-chain` · `bucket:pdf`
**Estimate:** M (3 days)

**Description**
A VAT Official Receipt is BIR-mandatory on collections; a signed Delivery Receipt is customer-mandatory for Toyota/Nissan shipments; GRN + pick sheet are core warehouse docs. Today these are unprintable, and voided/cancelled invoices print identically to live ones (fraud-control gap).

**Acceptance criteria**
- [ ] `official-receipt`, `delivery-receipt`, `grn`, `picking-list` blade templates + routes created.
- [ ] OR is wired into the collection flow.
- [ ] `Accounting/PdfService` is routed through `PdfRenderService` so DRAFT/VOID/PAID/CANCELLED watermarks + "Page N of M" render.
- [ ] A voided invoice prints with a VOID watermark.
- [ ] The `OfficialReceipt` model (previously orphaned) is wired in rather than duplicated.

**Evidence:** no `official-receipt`/`delivery-receipt`/`grn`/`picking-list` blade; `Accounting/PdfService::invoice()` passes no watermark/generated.
**Dependencies:** REC-05 (OfficialReceipt model exists but orphaned — wire it).

---

### [REC-30] Bill create/pay SoD + budget approval through the generic engine

**Labels:** `priority:P1` · `module:accounting` · `bucket:security-sod`
**Estimate:** M (3 days)

**Description**
One `finance_officer` can enter a vendor bill and pay it (fictitious-vendor disbursement), and can create+approve a budget/transfer. Classic AP fraud vector plus ungoverned budget approvals.

**Acceptance criteria**
- [ ] `recordPayment` is blocked when payer == bill creator unless an override permission is held.
- [ ] Bill payment routes through `ApprovalService` (gaining self-approval guard, SLA, delegation).
- [ ] Budget + budget-transfer approvals route through `ApprovalService`.
- [ ] A self-approval guard is added to `StockAdjustmentService::approve` (REC-26 adjacent).
- [ ] Tests assert same-actor bill-pay and budget self-approval are blocked without override.

**Evidence:** `BillService.php:279,309`; `BudgetService.php:56`; `BudgetTransferService.php:42`.
**Dependencies:** REC-04 (consistent SoD approach).

---

### [REC-31] Biometric enrollment / device-identity mapping

**Labels:** `priority:P1` · `module:attendance` · `bucket:cross-cutting`
**Estimate:** M (2–3 days)

**Description**
Biometric terminals rarely accept `OGM-2026-0142` as the enrollee ID; today a mismatch fails the whole import row with `Unknown employee_no`. Chain-3 "Biometric CSV → DTR" is fragile — any device-id/employee_no skew breaks imports silently.

**Acceptance criteria**
- [ ] A `biometric_user_id` / `badge_no` column added on employees.
- [ ] An enrollment step added in onboarding to capture it.
- [ ] `DTRImportService` maps device IDs → employees via the new column.
- [ ] Unmapped punches are surfaced for resolution instead of failing the row.
- [ ] Test asserts an import with a device ID resolves to the correct employee and unmapped rows are reported, not dropped.

**Evidence:** `DTRImportService.php:68,185` (string match); no biometric column in `0016`.
**Dependencies:** REC-32 (onboarding step).

---

### [REC-32] Complete onboarding checklist (assets, training, biometric enroll)

**Labels:** `priority:P1` · `module:hr` · `bucket:cross-cutting`
**Estimate:** S (1–2 days)

**Description**
A clerk marking a new hire "complete" has no assurance they can clock in or received PPE/tools. Onboarding closure is meaningless for the physical/enrollment steps (4 of 7 chain steps omitted).

**Acceptance criteria**
- [ ] `asset_issued`, `training_assigned`, `biometric_enrolled` step keys added to `EmployeeOnboarding` (tables `0020`/`0191` already exist).
- [ ] `completed_at` is gated on all required step keys.
- [ ] The onboarding UI shows and tracks the new steps.
- [ ] Test asserts onboarding cannot complete until the new steps are satisfied.

**Evidence:** `EmployeeOnboarding.php:17-24`.
**Dependencies:** REC-31 (biometric enroll step).

---

## Tier P2 — Competitive

---

### [REC-35] Recurring / auto-reversing journal entries

**Labels:** `priority:P2` · `module:accounting` · `bucket:missing-feature`
**Estimate:** M (3 days)

**Description**
Tanaka hand-keys prepaid amortization, accruals, and depreciation-style standing entries every period. Month-end drudgery and error risk (OGAMI-114).

**Acceptance criteria**
- [ ] Recurring-JE template model + CRUD.
- [ ] `reverses_on` auto-reverse support for accruals.
- [ ] A scheduled generator posts due recurring entries each period.
- [ ] The generator is period-aware (respects the lock, REC-16).
- [ ] Test asserts a recurring template posts on schedule and an accrual auto-reverses.

**Evidence:** grep `recurring` in Accounting = 0; `reverse()` manual-only `:262`.
**Dependencies:** REC-16 (period awareness).

---

### [REC-36] GL year-end close (retained-earnings roll) + prior-period-adjustment register

**Labels:** `priority:P2` · `module:accounting` · `bucket:cross-cutting`
**Estimate:** L (5 days)

**Description**
A multi-year pilot's Income Statement accumulates across fiscal years because P&L is never zeroed; equity is synthesized at render, leaving no traceable retained-earnings journal for a JP-parent auditor. No permanent RE journal, no PPA disclosure — year-end books not audit-defensible.

**Acceptance criteria**
- [ ] Closing-entry generator rolls income-summary → retained earnings at year-end.
- [ ] After close, the new-year Income Statement starts at zero.
- [ ] `is_prior_period_adjustment` marker added on JEs.
- [ ] A prior-period-adjustment register report is available.
- [ ] Test asserts P&L is zeroed and RE increases by net income after a year-end close.

**Evidence:** grep `retained.?earning|income.?summary|closingEntry` = 0; `BalanceSheetService.php:80-86`.
**Dependencies:** REC-16, REC-05.

---

### [REC-33] Finish the CRM sales pipeline (Leads → Opportunities → Quotes) UI

**Labels:** `priority:P2` · `module:crm` · `bucket:half-built`
**Estimate:** XL (8–10 days) or S (document-and-defer)

**Description**
A full backend sales pipeline (3 controllers, 24 routes, `QuoteService` 269L) is 100% unreachable — either the largest dead surface or an undocumented competitive feature. Left as-is, it is dead code inviting rot.

**Acceptance criteria**
- [ ] A scope decision is recorded first (finish vs explicitly cut).
- [ ] **If in-scope:** 3 list + 3 create/detail pages built (leads, opportunities, quotes).
- [ ] **If in-scope:** 3 api clients + sidebar entries added (permissions already on routes).
- [ ] **If in-scope:** workflow-transition tests cover lead→opp→quote conversions.
- [ ] **If out-of-scope:** the backend is explicitly marked out-of-pilot-scope and removed from active testing/maintenance, and CLAUDE.md is reconciled (DR-4).

**Evidence:** `CRM/routes.php:57-84`; no `spa/src/pages/crm/{leads,opportunities,quotes}`.
**Dependencies:** scope decision.

---

### [REC-34] Quality PPAP + Calibration internal SPA UI

**Labels:** `priority:P2` · `module:quality` · `bucket:half-built`
**Estimate:** L (4–5 days)

**Description**
QC engineers can't manage PPAP submissions or the calibration register from the app — both are IATF deliverables auditors probe; GAP-ANALYSIS overstates PPAP as "shipped." Today PPAP is only visible via the B2B portal and calibration is invisible internally.

**Acceptance criteria**
- [ ] PPAP list + detail pages with submit/review/approve/elements actions.
- [ ] Calibration register list + create pages.
- [ ] Api clients wired to the existing `PpapController` / `CalibrationController` routes.
- [ ] Sidebar entries added under `qualityRoutes.tsx` (permissions exist).
- [ ] A PPAP submission and a calibration record can be managed end-to-end internally.

**Evidence:** `PpapController.php`, `CalibrationController.php`; grep `ppap|calibration` in `spa/src/pages/quality` = 0.
**Dependencies:** none.

---

### [REC-37] Audit-trail tamper-evidence + TRUNCATE guard + retention policy

**Labels:** `priority:P2` · `module:cross` · `bucket:security`
**Estimate:** M (3 days)

**Description**
The "immutable" audit log is bypassable via `TRUNCATE` and by a privileged operator disabling the trigger; a JP/BIR auditor grades tamper-evidence, not just append-only. The immutability guarantee currently has an undetectable bypass.

**Acceptance criteria**
- [ ] A `BEFORE TRUNCATE ... FOR EACH STATEMENT` guard is added on the audit table.
- [ ] A per-row `HMAC(previous_hash + payload)` hash-chain column provides tamper-evidence.
- [ ] Chain verification detects any altered/removed row.
- [ ] A 10-year BIR retention schedule is documented and binds the archive lifecycle.
- [ ] Test asserts a tampered row breaks chain verification and TRUNCATE is blocked.

**Evidence:** `2026_06_09_100001_...:20,24` (no TRUNCATE); no hash column in `0008`.
**Dependencies:** none.

---

### [REC-38] Dry-run / parallel-run / cutover checklist for migration

**Labels:** `priority:P2` · `module:cross` · `bucket:migration`
**Estimate:** L (5 days)

**Description**
A real cutover needs preview-before-commit, a 1–2 payroll-cycle parallel run against the legacy system, and a written freeze→load→reconcile runbook. Without it, switchover is unsafe and un-validated (OGAMI-111/112).

**Acceptance criteria**
- [ ] Import staging + validate/preview step (extends REC-03).
- [ ] Batch rollback for a committed migration batch.
- [ ] A parallel-run variance report compares imported legacy-period results side-by-side.
- [ ] A cutover runbook (freeze→load→reconcile) is written in `docs/`.
- [ ] A parallel-run produces a documented variance report against a legacy period.

**Evidence:** grep `dry.?run|parallel.?run` = 0.
**Dependencies:** REC-03, REC-05.

---

### [REC-39] Commission raw-id leak + BudgetController refactor

**Labels:** `priority:P2` · `module:crm` · `module:accounting` · `bucket:anti-patterns`
**Estimate:** S (1 day)

**Description**
`CommissionController::rates`/`setRate` return raw models → leak integer `id`/FKs, violating the hash_id mandate. `BudgetController` is a fat controller doing inline hash-decode + inline validation + direct `$budget->update()` bypassing the service layer.

**Acceptance criteria**
- [ ] `CommissionRateResource` added returning `hash_id` + nested resources; controller returns it instead of raw models.
- [ ] `StoreBudgetRequest` / `UpdateBudgetRequest` FormRequests extracted with `authorize()`.
- [ ] Budget status guards + writes moved into `BudgetService`.
- [ ] `BudgetController` no longer inline-decodes hashids or calls `$budget->update()` directly.
- [ ] No integer `id`/FK appears in commission API responses (test asserts hash_id).

**Evidence:** `CommissionController.php:29,36`; `BudgetController.php:32-66,119,177`.
**Dependencies:** none.

---

### [REC-43] RA 10173 (Data Privacy Act) governance layer

**Labels:** `priority:P2` · `module:cross` · `bucket:security`
**Estimate:** L (5 days)

**Description**
The system holds 200+ employees' SSS/TIN/PhilHealth/Pag-IBIG/bank numbers with no consent capture, DSAR export, erasure path, breach register, or DPO role — a real PH pilot compliance blocker. Security-of-processing is covered by encryption+masking; the rights/governance layer is not. (P2/P3 by scope decision — compliance blocker for a real pilot, deferrable for thesis-only.)

**Acceptance criteria**
- [ ] Consent / lawful-basis capture on onboarding.
- [ ] Per-employee DSAR export bundle.
- [ ] Scrub-on-separation anonymization, reconciled with the 10-year statutory retention of required fields.
- [ ] A breach-notification register.
- [ ] A DPO role is seeded with the appropriate permissions.

**Evidence:** grep `data.subject|consent|erasure|DPO|breach` = 0; SoftDeletes retains PII.
**Dependencies:** REC-11 (scope), REC-37 (retention).

---

## Tier P3 — Polish

---

### [REC-42] Resolve dead code — Loan gov-interest path + orphaned OfficialReceipt + return_management feature guard

**Labels:** `priority:P3` · `module:loans` · `module:accounting` · `module:return-management` · `bucket:half-built` · `bucket:hygiene`
**Estimate:** S (1–2 days)

**Description**
Speculative dead code (`generateWithInterest`, `SssLoan`/`PagibigLoan`) and an orphaned `OfficialReceiptService` rot and mislead; ReturnManagement's module toggle is frontend-only (disabled-module backend bypass).

**Acceptance criteria**
- [ ] Loan gov-interest path decided: either implemented (wired + UI types) or the enum cases/method/test deleted.
- [ ] `OfficialReceipt` wired into collections (via REC-29) or removed.
- [ ] `feature:return_management` middleware added to the RM route group.
- [ ] Workflow-transition tests added for the RM 8-transition service.
- [ ] No dead loan-interest code path remains after the decision.

**Evidence:** `AmortizationService.php:47` (test-only); `LoanService.php:137`; `OfficialReceiptService.php` (no route); `ReturnManagement/routes.php:13`.
**Dependencies:** REC-29.

---

### [REC-40] B2B portal + self-service design-system adoption

**Labels:** `priority:P3` · `module:b2b` · `module:portal` · `bucket:react-anti-patterns`
**Estimate:** M (3 days)

**Description**
Supplier/customer-facing screens hand-roll status pills, use raw `useState` forms without Zod, and manual-fetch bypassing `useQuery` — inconsistent and functionally weaker (no caching/validation on quality/HR-mutating forms). Unvalidated forms mutate quality/HR data and the portal looks unfinished next to the internal app.

**Acceptance criteria**
- [ ] Hand-rolled status pills replaced with `<Chip>` across portal detail pages.
- [ ] `self-service/overtime.tsx`, `quality/ncr-templates/create.tsx`, and portal forms converted to RHF + Zod.
- [ ] Manual-fetch portal pages converted to `useQuery`.
- [ ] `budgeting/create.tsx:81` no longer leaks raw `err.message` in a toast.
- [ ] Portal mutating forms validate client-side before submit.

**Evidence:** `portal/supplier/invoices/detail.tsx:57`; `self-service/overtime.tsx:210`; `portal/supplier/delivery-schedules.tsx:17`.
**Dependencies:** none.

---

### [REC-41] Currency formatting consolidation + replace native prompts

**Labels:** `priority:P3` · `module:spa` · `bucket:demo-readiness`
**Estimate:** S (0.5 day)

**Description**
A CFO demo shows `₱ 1500000.00` next to `₱1,500,000.00`; payroll force-unlock pops a raw OS `window.prompt`. Looks unfinished to a sharp examiner.

**Acceptance criteria**
- [ ] All 13 currency-format offenders routed through `formatPeso()`.
- [ ] The two `window.prompt()` calls (payroll force-unlock, maintenance cancel) replaced with styled modals.
- [ ] The 2 `en-US` date outliers corrected to the standard format.
- [ ] No raw `window.prompt` or unformatted peso string remains in the offending files.

**Evidence:** `formatNumber.ts:30`; `crm/price-agreements/index.tsx:39`; `periods/detail.tsx:240`.
**Dependencies:** none.

---

## Appendix — Milestone rollup (from report §7)

| Milestone | Theme | RECs |
|---|---|---|
| M1 | Foundation hardening + reachability wins | REC-01, REC-02, REC-04, REC-07, REC-15 |
| M2 | Statutory + labor-law correctness | REC-06, REC-09, REC-10, REC-17 |
| M3 | Cutover enablement | REC-03, REC-05, REC-28 |
| M4 | Finance completeness + controls | REC-13, REC-14, REC-16, REC-18, REC-20, REC-21, REC-30 |
| M5 | Operational depth + IATF edges | REC-08, REC-19, REC-22, REC-24, REC-25, REC-26, REC-27, REC-29 |
| M6 | Differentiator build + pilot/defense prep | REC-11, REC-12, REC-31, REC-32, REC-33, REC-34, REC-35, REC-36, REC-37, REC-38, REC-39, REC-40, REC-41, REC-42, REC-43 |

> Note: REC-20 (Optimistic locking on shared master data + documents) is tracked below as it was omitted from the P1 body above.

---

### [REC-20] Optimistic locking on shared master data + documents

**Labels:** `priority:P1` · `module:cross` · `bucket:failure-mode`
**Estimate:** M (3 days)

**Description**
Two HR users editing the same employee, or two planners editing a draft SO, silently clobber each other (last-write-wins; item delete+recreate). Lost updates on employees/SO/customer master with no warning (OGAMI-108).

**Acceptance criteria**
- [ ] A `lock_version` column added on high-contention tables (employees, SO, customer/vendor, PR/PO drafts).
- [ ] Controllers accept `expected_version` / `If-Match` and return 409 on mismatch.
- [ ] The `stock_levels.lock_version` (incremented but never compared) is actually enforced.
- [ ] The SPA surfaces a "record changed, reload" message on 409.
- [ ] Test asserts a stale-version update is rejected with 409.

**Evidence:** `stock_levels.lock_version` `0056:21` (never compared); `SalesOrderService.php:235`.
**Dependencies:** none.
