# Ogami ERP — 6-Month Rebuild Roadmap (2026-07-08)

> Derived from `REBUILD-AUDIT-2026-07-08.md`. 12 two-week sprints (S1–S12) grouped
> under the audit's 6 monthly milestones (M1–M6). Ordering is dependency-aware and
> priority-tiered: the P0 spine (REC-01/02/04/05/06/07/08/09/10/11 + migration
> REC-03) lands before P1 finance completeness, P2 competitive, and P3 polish.
> Each sprint references REC-NN ids from the report. Effort tags (S/M/L/XL) are the
> report's. A ~2-week sprint budget is ~10 working days; XL items (REC-03, REC-12)
> intentionally span two sprints.

---

## Milestone M1 — Foundation hardening + reachability wins
*Theme: make the already-built, unreachable P0 controls clickable and attributable.*

### Sprint 1 — Payroll integrity spine (void + maker-checker)
- **Theme:** Turn the sanctioned payroll correction + SoD path from backend-only into a real, attributable workflow.
- **Objectives (user-visible):** Maria can void a bad finalized run behind a confirm modal; every approve/finalize/void records *who* did it and blocks self-approval.
- **Deliverables:** REC-01 (payroll void route + controller + button + `voided_by`), REC-04 (maker-checker: `approved_by`/`computed_by`/`finalized_by` columns, reject approver==computer, split hr/finance seeded perms).
- **Dependencies:** None inbound; REC-01 and REC-04 share the `PayrollPeriodController`/actor plumbing, so co-locate them. Unblocks M2 payroll work.
- **Risks:** Migration adds actor columns to `payroll_periods` (0032) — backfill existing rows; permission-seeder split can regress role tests. Follow-up read D2.2 (confirm payroll GL posting hits the period guard).
- **Demo target:** Finalize a run, void it with a reason, show the reversal + `voided_by`; attempt self-approval and get rejected.

### Sprint 2 — P2P + IATF + finance reachability
- **Theme:** Wire the three highest-leverage phantom features (3-way match, rework re-inspection, AR/AP aging).
- **Objectives (user-visible):** Ben links a bill to its PO and sees a variance snapshot; reworked WOs get re-measured before CoC; CFO/AP open live AR & AP aging pages.
- **Deliverables:** REC-02 (PO selector + per-line `item_id` in bill-create, `threeWayMatch` client, snapshot view), REC-07 (pass `sales_order_id`/lift skip guard so rework WO triggers outgoing QC + test), REC-15 (AR/AP aging routes + two SPA pages + CSV export).
- **Dependencies:** REC-02 pairs with REC-24 (tolerance UI, M5) — stub tolerance from env for now. REC-15 partially depends on REC-13 credit-note handling (M4) — ship raw buckets now, refine later.
- **Risks:** REC-02 touches `CreateBillData` contract → coordinate SPA/API types; REC-07 could double-trigger QC if guard logic is loose (add idempotent test).
- **Demo target:** Create a bill against a PO with a qty variance flagged; complete a rework WO and show its auto-generated outgoing inspection; open the aged-receivables page.

---

## Milestone M2 — Statutory + labor-law correctness
*Theme: the money math is right; make the filing artifacts and DOLE factors right.*

### Sprint 3 — BIR / SSS filing fidelity
- **Theme:** Filing-grade statutory exports that pass eBIRForms/EPRS validation.
- **Objectives (user-visible):** Maria exports a real 1601-C, 1604-CF, `.DAT` Alphalist, and an SSS R-3 that upload cleanly.
- **Deliverables:** REC-06 (expand 1601-C taxable/exempt + reconciliation, 1604-CF, Alphalist `.DAT` schema with header/trailer/control totals + ATC, wire orphaned `SssR3Export` route + `ExportRunner::MAP` entry + button).
- **Dependencies:** Gov-table currency (OGAMI-101). Feeds REC-25 export-engine wiring (M5) and REC-17's 2316.
- **Risks:** BIR DAT fixed-field-order fidelity is finicky and hard to verify without a real validator — allocate test-data time; company RDO/ATC not seeded (P1 follow-on). eBIRForms XML deferred to later.
- **Demo target:** Generate the four artifacts; open the Alphalist `.DAT` and show correct fixed-width layout + control totals.

### Sprint 4 — DOLE labor-law correctness (OT, leave, separation)
- **Theme:** Close statutory under-payment and money-losing leave bugs.
- **Objectives (user-visible):** Rest-day/holiday OT pays the correct DOLE factor; year-end leave converts to a paid payroll line without corrupting balances; terminations/retirements compute lawful final pay.
- **Deliverables:** REC-09 (day-type OT factor table 1.25/1.69/1.95/2.6 + ND stacking + per-type tests), REC-10 (single year-end leave source of truth + carry cap + emit encashment payroll line + fix year drift), REC-17 (RA 7641 separation/retirement pay + final-pay tax + drive final 2316).
- **Dependencies:** REC-10 needs REC-04 payroll-line plumbing (S1). REC-17 needs REC-06 2316 fidelity (S3).
- **Risks:** REC-10 reconciles two order-dependent jobs (`ProcessYearEndLeave` vs `ResetLeaveBalancesForYear`) — regression-test both run orders; OT factor change alters historical recompute paths.
- **Demo target:** Run holiday OT and show 2.6× factor; run year-end leave and show forfeit/carry/encashment; terminate an employee (authorized cause) and show separation pay + tax on 2316.

---

## Milestone M3 — Cutover enablement
*Theme: without import + opening balances + real transactional seed, no pilot go-live and no credible demo.*

### Sprint 5 — Master-data import toolkit (build, part 1)
- **Theme:** Generic CSV import pipeline with staging + dry-run + batch rollback.
- **Objectives (user-visible):** A migrator uploads employees, items, and COA via CSV, previews error rows, and commits per-batch.
- **Deliverables:** REC-03 (part 1 of XL — `import_batches` table, per-entity fixed-schema mapping, staging → validate/dry-run preview → commit-in-txn → rollback; start with employees + items + COA, reusing the `DTRImportService` per-row-catch pattern).
- **Dependencies:** None inbound. Directly unblocks REC-05 (opening balances build on item/COA import) and REC-38 (M6 dry-run/parallel-run extends this).
- **Risks:** XL scope — resist mapping-UI creep (CLAUDE.md cuts it); fixed schema per entity only. Batch rollback + single-txn-per-batch must be watertight.
- **Demo target:** Import 200 employees from CSV with two deliberately bad rows surfaced in preview, then commit the clean batch.

### Sprint 6 — Opening balances + transactional seed
- **Theme:** Start the books provably equal to source, and make the three chains visible in-demo.
- **Objectives (user-visible):** Tanaka loads a legacy TB that must net to zero, opening stock lands with correct WAC, and Collections/Bill-Payments/Work-Orders pages are populated.
- **Deliverables:** REC-05 (opening-balance JE generator with TB-match reject-if-unbalanced, `StockMovementType::Opening` + bulk opening-stock loader, open-invoice/open-bill importers, TB-match report), REC-28 (seed real `collections`, `bill_payments`, work orders + outputs + in-process QC across the 6-month window), remaining entities of REC-03 (customers/vendors/BOMs/molds/machines).
- **Dependencies:** REC-05 needs REC-03 COA/item import (S5). REC-28 makes REC-15 aging (S2) and GL actually tie out.
- **Risks:** WAC opening cost basis must match the layer model; seeded transactional legs must go *through the services* (not `amount_paid` fakes) or aging still won't reconcile.
- **Demo target:** Load a legacy TB that reconciles to zero; show AR/AP aging + GL tying to the seeded collections/payments; open a populated Work Orders page.

---

## Milestone M4 — Finance completeness + controls
*Theme: the correction instruments, period control, withholding, and the failure-mode + SoD guards a finance evaluator probes first.*

### Sprint 7 — Correction instruments + period control
- **Theme:** Credit notes, period close UI, and the fiscal-lock we contest un-cutting.
- **Objectives (user-visible):** Ben/Tanaka credit a disputed invoice or over-billing with a first-class credit note; Tanaka closes/reopens a month from the app with a reason trail.
- **Deliverables:** REC-13 (first-class `credit_notes` AR+AP with application/offset + `Disputed` state, retire RMA negative-invoice hack), REC-14 (`accounting/periods` list + close/reopen + reason + history), REC-16 (keep the fiscal-period lock, un-cut in CLAUDE.md, verify subledger postings share the JE period guard — D2.2).
- **Dependencies:** REC-14→REC-16 (UI operates the lock). REC-13 must feed REC-15 aging buckets correctly (retro-fix S2 buckets). REC-35/REC-36 (M6) depend on this period awareness.
- **Risks:** Credit-note BIR doc numbering + VAT reversal semantics; D2.2 verification may reveal Invoice/Bill/Payroll GL postings *don't* hit the period guard (scope discovery).
- **Demo target:** Issue a credit note against a partially-collected invoice and show it offset in aging; close a month and show a back-dated JE rejected.

### Sprint 8 — Withholding + financial failure-mode guards
- **Theme:** EWT/VAT compliance plus idempotency, optimistic locking, and bill/budget SoD.
- **Objectives (user-visible):** Ogami issues 2307 to suppliers and files a VAT return; duplicate submits and concurrent edits are caught; bill-pay and budget approval require a second actor.
- **Deliverables:** REC-18 (EWT rate/ATC + `tax_withheld` on bills, EWT-payable leg, 2307 PDF, 2550M/Q VAT return), REC-20 (optimistic `lock_version` + `If-Match`/409 on employees/SO/customer/vendor/PR/PO), REC-21 (`X-Idempotency-Key` middleware on collections/bills/invoices/JE), REC-30 (bill create/pay SoD + budget/transfer approval through `ApprovalService`).
- **Dependencies:** REC-18 needs REC-13 (credit notes affect VAT). REC-30 aligns with REC-04 SoD approach (S1). REC-21 reuses the Production/Edge idempotency pattern.
- **Risks:** Optimistic locking must handle SO's delete+recreate-items path (`SalesOrderService:235`); VAT-return aggregation depends on per-invoice tax snapshots (schema stress E).
- **Demo target:** Enter a bill with 5% EWT and print its 2307; run a period VAT return; two users edit one employee and the second gets a 409; same finance user blocked from paying a bill they created.

---

## Milestone M5 — Operational depth + IATF edges
*Theme: shop-floor + warehouse reachability, quarantine, and the export/PDF surfaces.*

### Sprint 9 — IATF floor + quarantine
- **Theme:** Close the physical-segregation and floor-defect-entry gaps in the quality spine.
- **Objectives (user-visible):** Rejected stock is physically quarantined and blocked from issue; Joel raises an NCR from a high-scrap WO and pauses machines with real category/reason.
- **Deliverables:** REC-08 (MRB/quarantine: move failed stock to Quarantine location + held status blocking issue, disposition record, release-on-rework-pass/scrap), REC-19 (add `WorkOrderReject` to `NcrSource`, `work_order_id` on `CreateNcrRequest`, production-manager NCR perm, pause modal category+reason, scrap-threshold alert).
- **Dependencies:** REC-08 consumes REC-07 rework re-inspection (S2) for release. Follow-up read: reconcile `production_schedules` vs `wo_operations` before touching WO area.
- **Risks:** Held-status must be enforced at *every* issue path (including cycle-count `adjustIn/adjustOut`); pause modal wiring must not poison existing OEE Pareto history.
- **Demo target:** Fail an incoming inspection, watch resin auto-quarantine and become non-issuable, then release on scrap; raise a WO-sourced NCR from the floor with a categorized pause.

### Sprint 10 — Warehouse/PPC reachability + reporting/PDF surfaces
- **Theme:** Wire the unreachable master-data + export + document surfaces.
- **Objectives (user-visible):** Warehouse transacts in purchase UOM and approves adjustments; PPC onboards machines/molds; every list exports; statutory/customer PDFs print with void watermarks.
- **Deliverables:** REC-24 (seed UOM factors + GRN/issue UOM selector + item conversion tab + tolerance setting), REC-26 (stock-adjustment approval queue + reason codes), REC-27 (machines & molds create/edit UI), REC-22 (De Minimis benefits UI + test), REC-25 (wire `ExportRunner::MAP` for payroll register/inventory valuation/AR-AP aging/DTR + column-selector buttons), REC-29 (Official Receipt/Delivery Receipt/GRN/Picking-List blades + route `PdfService` through `PdfRenderService` for DRAFT/VOID/PAID/CANCELLED watermarks).
- **Dependencies:** REC-24 pairs with REC-02 (same match units). REC-25 needs REC-15 (aging) + REC-06 (payroll data). REC-29 wires the orphaned `OfficialReceipt` from REC-05.
- **Risks:** Heaviest sprint (7 RECs, mostly S/M) — if velocity slips, defer REC-22 De Minimis into S11 buffer. UOM `factor()` throws until seeded — ship seed + UI atomically.
- **Demo target:** Receive resin in BAG converted to KG; approve a reason-coded adjustment; register a replacement mold; export a payroll register; print a VOID-watermarked invoice + an Official Receipt.

---

## Milestone M6 — Differentiator build + pilot/defense prep
*Theme: the one genuine build (JPY multi-currency), remaining controls, governance, cutover safety, and demo polish.*

### Sprint 11 — Multi-currency build + data-scope + year-end GL (part 1)
- **Theme:** Start the signature JP-parent differentiator and the P0 row-level-scope refactor.
- **Objectives (user-visible):** Documents capture transaction currency + rate; dept/cost-center scoping is systematic, not hand-rolled; groundwork for JPY-translated statements.
- **Deliverables:** REC-11 (shared `ScopedByDepartment` global scope from grants + cost-center dimension; audit every list service), REC-12 (part 1 of XL — `fx_rates` table, `currency_code`/`txn`/`functional`/`fx_rate` columns on JE lines/invoices/bills/POs, capture at document date), REC-36 (GL year-end close: income-summary→retained-earnings generator + PPA register).
- **Dependencies:** REC-11 (P0) is scheduled here per the milestone map but is a hard prerequisite for RA-10173 (REC-43); do it first in the sprint. REC-12 touches every monetary write path — sequenced after M1–M4 hardening. REC-36 needs REC-16 + REC-05.
- **Risks:** REC-11 auditing every endpoint is easy to under-scope — forgotten endpoints = the leak it fixes; REC-12 schema migration is broad and must not regress existing PHP-only paths (default currency = PHP).
- **Demo target:** A dept head sees only their rows through a previously-unscoped endpoint; enter a JPY-denominated import bill with a captured rate; run a year-end close producing a retained-earnings journal.

### Sprint 12 — Multi-currency consolidation (part 2) + governance + cutover + polish
- **Theme:** Finish JPY consolidation, close governance/audit gaps, ship cutover safety, and polish for defense.
- **Objectives (user-visible):** Tanaka exports a JPY-translated TB/BS/IS with CTA; audit log is tamper-evident; a parallel run validates cutover; PPAP/Calibration are usable internally; the demo looks finished.
- **Deliverables:** REC-12 (part 2 — realized FX gain/loss on collection/payment, current-rate JPY statement export + CTA line + intercompany reconciliation), REC-31 (biometric `badge_no`/device-id mapping + surface unmapped punches), REC-32 (onboarding checklist: asset/training/biometric-enroll steps gate `completed_at`), REC-33 (CRM Leads/Opps/Quotes UI *or* explicit cut), REC-34 (PPAP + Calibration internal SPA UI), REC-35 (recurring/auto-reversing JE), REC-37 (audit TRUNCATE guard + HMAC hash-chain + 10-yr retention schedule), REC-38 (dry-run/parallel-run variance report + cutover runbook), REC-39 (Commission `hash_id` Resource + BudgetController refactor), REC-40 (portal/self-service design-system adoption), REC-41 (currency formatting + replace native prompts), REC-42 (resolve loan gov-interest dead code + orphaned OfficialReceipt + RM feature guard), REC-43 (RA 10173 governance: consent/DSAR/erasure/breach register/DPO — scope-gated).
- **Dependencies:** REC-31→REC-32 (onboarding step). REC-35 needs REC-16 period awareness. REC-38 extends REC-03/REC-05. REC-43 needs REC-11 (scope) + REC-37 (retention).
- **Risks:** Overloaded closing sprint — REC-33 (XL) should default to *document-and-defer* unless velocity allows; REC-43 is P2/P3 scope-gated (deferrable for thesis-only). Protect the demo: land REC-41 polish regardless of what else slips.
- **Demo target:** Export a JPY-translated Balance Sheet with a CTA line; show a tamper-evident audit hash-chain; run a parallel-run variance report against a legacy payroll cycle; a clean CFO demo with consistent `₱1,000,000.00` formatting.

---

## Sequencing notes
- **P0 spine placement:** REC-01/02/04/07 (S1–S2), REC-06/09/10 (S3–S4), REC-03/05 (S5–S6), REC-08 (S9), REC-11 (S11). REC-11 is P0 but the milestone map places it in M6; it is scheduled first-in-sprint at S11 and gates REC-43.
- **XL items span two sprints by design:** REC-03 (S5→S6), REC-12 (S11→S12).
- **Cutover safety net:** REC-03/05/28 (M3) → REC-38 (S12) closes the loop.
- **Contested cut:** REC-16 formally un-cuts fiscal-period locking (S7), per the auditor's one disagreement with CLAUDE.md scope.
- **Deferred-not-added (respect the cut):** expense-claim/petty-cash, bank rec, full EDI, cost accounting, react-grid dashboards — out of the 6-month window.
