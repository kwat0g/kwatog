# Ogami ERP — 6-Month Rebuild Roadmap

> Companion to `docs/REBUILD-AUDIT.md`. Sprint-by-sprint (two 2-week sprints/month).
> Dependency-aware. Each sprint delivers user-visible value + a demo target.
> REC-NN references map to Phase 4 cards in the main report.

---

## Month 1 — Financial-Integrity & Correctness Foundation

### Sprint 1.1 — GL integrity controls
- **Theme:** Make the ledger trustworthy.
- **Objectives (user-visible):** CFO can close a month and trust it stays closed; no one can post a JE they authored alone.
- **Deliverables:** REC-01 (period-close lock + reopen-with-trail), REC-02 (JE maker-checker + vendor/PO + inventory SoD checks).
- **Dependencies:** existing `ApprovalService`, audit log, `NotificationService`.
- **Risks:** posting guard must hook every GL entry path (JE, invoice finalize, bill, payroll GL) — miss one and the lock leaks. Mitigate with a single shared `PeriodGuard` consulted by all four services + a test per path.
- **Demo target:** attempt a backdated JE into a closed month → blocked; request reopen → approve → post → auto-relock; show the reopened-period report.

### Sprint 1.2 — Money-path correctness + payroll trust
- **Theme:** Stop silent money errors.
- **Objectives:** payroll pays leave correctly; bills can't be cut against cancelled POs; double-submits don't duplicate documents.
- **Deliverables:** REC-03 (daily-rate leave pay), REC-06 (cancelled-PO bill guard + per-delivery 3-way match + financial idempotency keys).
- **Dependencies:** none new.
- **Risks:** idempotency key reuse pattern from `WorkOrderOutputService` must be applied consistently; ensure the cache backend is Redis in prod.
- **Demo target:** run payroll with a daily-rated worker on approved VL → paid correctly; try to bill a cancelled PO → rejected; double-POST an invoice → single document.

---

## Month 2 — Chain 1 (Order to Cash) Credibility

### Sprint 2.1 — BIR-grade documents + CoC fix
- **Theme:** Documents an auditor and a customer accept.
- **Objectives:** issue a BIR-compliant invoice + a separate Official Receipt; CoC reflects real inspection results.
- **Deliverables:** REC-08 (invoice ATP/serial/buyer-TIN/ORIGINAL-DUPLICATE + zero-rated/exempt + Senior/PWD; OR template + numbering; CoC stops hardcoding "PASSED", prints critical-dimension measurements).
- **Dependencies:** invoice schema migration; `DocumentSequenceService` OR series.
- **Risks:** zero-rated/PEZA classification depends on confirming Toyota/Honda tax status with Ogami — sequence the classification UI behind that answer.
- **Demo target:** generate a VAT invoice with a Senior/PWD line + a zero-rated export invoice + an OR; print a CoC showing actual measured values and a FAILED case.

### Sprint 2.2 — Planning correctness
- **Theme:** The plan reflects reality.
- **Objectives:** multi-level products order the right raw material; two WOs can't collide on one machine; a killed MRP run self-heals.
- **Deliverables:** REC-15 (recursive BOM explosion + WO/machine conflict check + MRP stuck-run reaper), REC-14 (AR credit-memo slice).
- **Dependencies:** REC-01 (credit memo posts into an open period).
- **Risks:** recursive BOM must guard against cyclic BOMs — add a depth/visited guard.
- **Demo target:** explode a 2-level BOM to raw resin; block a double machine booking; issue an AR credit memo against a partially-paid invoice.

---

## Month 3 — Chain 2 (Procure to Pay) Depth

### Sprint 3.1 — Multi-UOM + incoming resin QC
- **Theme:** Receive and inspect resin the way the plant actually does.
- **Objectives:** buy in bags, issue in kg; inspect incoming resin against real parameters with COA + moisture.
- **Deliverables:** REC-04 (multi-UOM conversion across GRN/issue/BOM/stock card), REC-05 (incoming QC for raw-material Items + resin attributes + quarantine zone).
- **Dependencies:** REC-04 touches the same GRN path REC-05 fixes — sequence UOM first.
- **Risks:** UOM conversion touches every quantity math site; lock base-UOM storage and convert only at the edges. Comprehensive stock-card test required.
- **Demo target:** receive 10 bags → stock shows kg; issue 250 kg to a WO; inspect an incoming resin lot with moisture + COA upload; reject → goods land in quarantine.

### Sprint 3.2 — Traceability + AP completeness
- **Theme:** Trace material and control AP.
- **Objectives:** reconstruct lot→WO→part; bills require approval; POs can be amended; over-deliveries accepted within tolerance.
- **Deliverables:** REC-12 (lot/serial trace + adjustment reason codes + variance approval), REC-14 (AP credit memo + invoke bill-payment workflow + PO amendment + over-receipt tolerance).
- **Dependencies:** REC-02 (variance approval reuses SoD threshold infra).
- **Risks:** lot capture is additive but must thread through issue + output to be useful — verify the full chain, not just receipt.
- **Demo target:** trace a finished part back to its resin lot and supplier; amend an approved PO with a version trail; route a bill payment through Finance approval.

---

## Month 4 — Chain 3 (Hire to Retire) + Statutory Payroll

### Sprint 4.1 — Filing-grade statutory outputs
- **Theme:** Produce what actually gets filed.
- **Objectives:** generate official 2316, conformant Alphalist, 1601-C, 1604-CF, working SSS R-3, PhilHealth RF-1, Pag-IBIG MCRF.
- **Deliverables:** REC-07 (statutory-output rewrite + register SSS R-3 in `ExportRunner::MAP` + refresh/effective-date-resolve gov tables to 2025 + WHT annualization).
- **Dependencies:** `ExportColumnRegistry`/`ExportRunner`, gov-table service.
- **Risks:** official form fidelity is detail-heavy (box positions, DAT schemas). Budget the full XL; validate against current BIR/SSS templates.
- **Demo target:** export a fileable 2316 + Alphalist + 1601-C + SSS R-3 for a real payroll period.

### Sprint 4.2 — Payroll robustness
- **Theme:** Handle the messy real-world payroll cases.
- **Objectives:** void/re-run a finalized period; prorate a mid-cycle raise; import a real biometric export.
- **Deliverables:** REC-11 (Voided state + reversing GL, salary-effective-date proration, raw-punch sessionizer).
- **Dependencies:** REC-01 (void posts a reversing entry into an open period).
- **Risks:** void must reverse GL + payslips + loan deductions atomically; reuse the recompute path's loan-reversal logic.
- **Demo target:** import a raw ZKTeco punch file; run 200-employee payroll; void a mis-finalized run and re-run; prorate a mid-period promotion.

---

## Month 5 — Reporting, Migration & Demo Realism

### Sprint 5.1 — Migration toolkit + realistic dataset
- **Theme:** Make go-live and the demo both credible.
- **Objectives:** import opening balances + master data with reconciliation; the whole system shows realistic volume.
- **Deliverables:** REC-09 (opening-balance + master-data import with dry-run/reconciliation/rollback + cutover checklist), REC-10 (200-employee, 12-month deterministic dataset).
- **Dependencies:** REC-01 (opening balances post into an open period), REC-03 (seeded payroll is correct).
- **Risks:** the dataset generator must be deterministic + idempotent so demos are reproducible; opening-balance JE must net to a provided TB.
- **Demo target:** import a sample Excel opening TB → reconciled; reseed → dashboards show 12 months of trend, a real NCR Pareto, and ≥6 payroll cycles.

### Sprint 5.2 — Reporting + IATF completeness
- **Theme:** Close the reporting + IATF gaps.
- **Objectives:** email notifications actually send; aging is exportable; inventory turnover exists; calibration register for IATF.
- **Deliverables:** REC-16 (notification email channel + digest, standalone aging endpoints/exports, inventory-turnover report, calibration register).
- **Dependencies:** none new.
- **Risks:** calibration register is a small new module — keep it lean (gauge, last/next cal, status, alert cron) reusing the training-expiry pattern.
- **Demo target:** receive an emailed digest; export AR aging to Excel; show an overdue-calibration alert.

---

## Month 6 — Differentiation, Hardening & Defense Prep

### Sprint 6.1 — JP-parent differentiation
- **Theme:** The standout differentiator.
- **Objectives:** produce a JPY consolidation pack for the Japanese parent.
- **Deliverables:** REC-17 (multi-currency on JE/invoice/bill + FX-rate table + current-rate translation → JPY trial balance/consolidation pack; optional Japanese i18n toggle).
- **Dependencies:** REC-01 (translation runs at period close).
- **Risks:** XL effort; if the parent doesn't actually require JPY consolidation (confirm with Ogami), descope to multi-currency capture only.
- **Demo target:** close a month → generate a JPY trial-balance pack with FX rates and a delta explanation.

### Sprint 6.2 — Hardening + pilot dry-run
- **Theme:** Defense-ready.
- **Objectives:** resilient infra; smooth full-chain walkthrough; approval continuity.
- **Deliverables:** REC-18 (fix audit-prune vs immutability + scheduled backup + executed restore drill), REC-13 (approval delegation + org-hierarchy routing + escalate-not-reject default).
- **Dependencies:** none new.
- **Risks:** restore drill must be actually executed and timed against the RTO target, not just scripted.
- **Demo target:** run all three chains end-to-end as a panel would; show an approver-on-leave delegation; demonstrate a timed restore from backup.

---

## Critical path & sequencing notes

- **REC-01 (period lock) is the spine** — REC-09, REC-11, REC-14, REC-17 all post into periods and depend on it. Do it first.
- **REC-04 (multi-UOM) before REC-05/REC-12** — they share the GRN/stock path.
- **REC-03 before REC-10** — the realistic dataset must seed correct payroll.
- **Confirm two external facts early** (gates priority): Toyota/Honda PEZA/zero-rated status (REC-08), and whether the JP parent requires JPY consolidation (REC-17). Both can be asked of Ogami in week 1.
