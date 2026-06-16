# Ogami ERP — Rebuild Audit Ticket Backlog

> Import-ready cards. Companion to `docs/REBUILD-AUDIT.md`.
> Each ticket = one Phase 4 recommendation. Labels: `priority/*`, `module/*`, `bucket/*`.
> Estimates: S ≤2d · M 3-5d · L 1-2w · XL >2w.

---

## P0 — Foundation

### OGAMI-001 — GL period-close lock with reopen-with-trail
- **Labels:** `priority/P0` `module/accounting` `bucket/cross-cutting` `bucket/missing-feature`
- **Estimate:** M (4-5d)
- **Blocks:** OGAMI-009, OGAMI-011, OGAMI-014, OGAMI-017 (all post into periods)
- **Depends on:** existing `ApprovalService`, audit log, `NotificationService`
- **Description:** Add `accounting_periods` (year, month, status open|closed|reopened, closed_by/at, reopened_by/at, reason). A shared `PeriodGuard` must be consulted by `JournalEntryService`, `InvoiceService` (finalize), `BillService`, and `PayrollGlPostingService` to block any posting whose effective date falls in a closed period unless a time-boxed, approved reopen is active. Reopen workflow via `ApprovalService`; auto-relock after the window. Add a "transactions posted to reopened periods" report.
- **Acceptance:** backdated JE into a closed month is rejected; reopen → approve → post → auto-relock works; all four GL paths enforce the guard (one test each); reopened-period report lists entries with approver + reason.
- **Evidence:** `JournalEntryService.php:90-195`; grep `accounting_period` → 0; `FiscalYear` budgeting-only (`0162_create_budgeting_tables.php`).
- **Note:** contests the CLAUDE.md:81 "fiscal period locking" cut — justified as an integrity control for a BIR/IATF/JP-audited entity.

### OGAMI-002 — Maker-checker on Journal Entries + close SoD conflicts
- **Labels:** `priority/P0` `module/accounting` `bucket/security`
- **Estimate:** S-M (2-3d)
- **Depends on:** `ApprovalService`; pairs with OGAMI-012 (inventory reason codes)
- **Description:** Block a user from posting a JE they authored (compare `created_by` vs poster), or route JE post through `ApprovalService` above a threshold. Add a "PO approver did not create the payee vendor" check. Require secondary approval on inventory adjustments above a value threshold.
- **Acceptance:** same user cannot create + post a JE above threshold; PO approver ≠ vendor creator enforced; high-value inventory adjustment requires a second approver.
- **Evidence:** `JournalEntryService.php:167-195`; `Accounting/routes.php:46` vs `Purchasing/routes.php:53`.

### OGAMI-003 — Pay daily-rated employees for approved paid leave
- **Labels:** `priority/P0` `module/payroll` `bucket/failure-mode`
- **Estimate:** S (1-2d)
- **Blocks:** OGAMI-010 (seeded payroll must be correct)
- **Description:** `PayrollCalculatorService` must count approved paid-leave days into a paid-leave earnings line (daily_rate × leave_days) for daily-rated staff — implementing the "payroll will apply leave-pay logic" the leave service already promises.
- **Acceptance:** a daily-rated worker with approved VL/SIL is paid for those days; monthly staff unchanged; regression test for both pay types.
- **Evidence:** `LeaveRequestService.php:316-334`; `PayrollCalculatorService.php:244,307-308`.

### OGAMI-004 — Multi-UOM with conversion factors
- **Labels:** `priority/P0` `module/inventory` `bucket/schema` `bucket/missing-feature`
- **Estimate:** L (1-2w)
- **Blocks:** OGAMI-005, OGAMI-012 (shared GRN/stock path)
- **Description:** Add `uom` master + `item_uom_conversions` (item, from_uom, to_uom, factor, rounding); base-UOM per item. Convert at GRN receipt, material issue, BOM consumption, and stock card. Store in base UOM, display in transaction UOM.
- **Acceptance:** receive 10 bags → stock reflects kg via factor; issue kg to a WO; WAC stays correct; full stock-card test across bag↔kg.
- **Evidence:** `Item.php:29`; grep `conversion_factor` → 0.

### OGAMI-005 — Fix incoming QC for raw materials + resin cert/moisture
- **Labels:** `priority/P0` `module/quality` `module/inventory` `bucket/failure-mode` `bucket/half-built`
- **Estimate:** M-L (5-8d)
- **Depends on:** OGAMI-004 (shares GRN path)
- **Description:** Allow `InspectionSpec` to target raw-material `Item`s (polymorphic spec subject or Item-spec path) so incoming inspections seed real parameter rows. Add resin attributes (lot, moisture %, COA/cert upload, MFI). Fix the trigger that currently sets `product_id = item_id`. Add a quarantine warehouse zone so pending/rejected stock is segregated.
- **Acceptance:** an incoming resin GRN creates an inspection with real measurement rows; inspector records moisture + uploads COA; rejected lot moves to quarantine; QC gate cannot be bypassed by a null inspection link.
- **Evidence:** `TriggerIncomingQC.php:59` vs `InspectionService.php:94-101`; grep `moisture|resin.*cert` → 0; no quarantine zone.

### OGAMI-006 — Money-path integrity bundle
- **Labels:** `priority/P0` `module/accounting` `module/purchasing` `bucket/failure-mode`
- **Estimate:** M (3-4d)
- **Description:** Reject bill creation when PO status ∈ {cancelled, closed}. Change 3-way match to compare against the specific GRN/delivery, not summed GRNs across the PO. Add an `Idempotency-Key` guard (reuse `WorkOrderOutputService` cache pattern) on Invoice/Bill/JE/payment create.
- **Acceptance:** bill against cancelled PO rejected; split-shipment invoice matches its own delivery; double-POST creates one document.
- **Evidence:** `BillService.php:130-160`; `ThreeWayMatchService.php:41-49`; idempotency absent on money paths.

### OGAMI-007 — BIR/SSS/PhilHealth/Pag-IBIG filing-grade outputs
- **Labels:** `priority/P0` `module/payroll` `bucket/localization`
- **Estimate:** XL (>2w)
- **Depends on:** `ExportColumnRegistry`/`ExportRunner`, gov-table service
- **Description:** Rewrite the statutory-output layer to filing grade: official-layout 2316 (RDO, de-minimis, 13th-month, substituted-filing); conformant Alphalist (DAT/schedule + ATC); 1601-C + 1604-CF generators; register SSS R-3 in `ExportRunner::MAP` and fix its column references; build PhilHealth RF-1 + Pag-IBIG MCRF exporters; refresh gov tables to 2025 and resolve them by effective date at compute time; add WHT year-end annualization/true-up.
- **Acceptance:** each form exports in its official format for a real period; historical recompute uses the table in force then; SSS R-3 no longer throws "no export class registered".
- **Evidence:** `BirAlphalistService.php:26-109`; `ExportRunner.php:21-23`; `GovernmentTableSeeder.php:90`; `DocumentType.php:25-29`.

### OGAMI-008 — BIR-compliant Sales Invoice + Official Receipt + VAT classification
- **Labels:** `priority/P0` `module/accounting` `module/crm` `bucket/localization`
- **Estimate:** M-L (5-8d)
- **Depends on:** `DocumentSequenceService` (OR series); confirm Toyota/Honda PEZA status
- **Description:** Add invoice fields: ATP/permit no., serial range, buyer TIN, ORIGINAL/DUPLICATE marker, VATable/exempt/zero-rated line classification, Senior/PWD discount. Build a separate Official Receipt template + numbering series for collections. Fix CoC PDF to stop hardcoding "PASSED" and to print actual disposition + critical-dimension measurements.
- **Acceptance:** generate a VAT invoice with Senior/PWD line, a zero-rated export invoice, and a distinct OR; CoC reflects real inspection outcome incl. a FAILED case.
- **Evidence:** `0048_create_invoices_table.php:22`; `invoice.blade.php`; `coc.blade.php:50`; grep `zero_rated|pwd|official_receipt` → 0.
- **Sub-slice (P1-demo, S):** CoC "PASSED" fix alone — pull forward if demo is near.

### OGAMI-009 — Opening-balance + master-data migration toolkit
- **Labels:** `priority/P0` `module/cross-cutting` `bucket/migration`
- **Estimate:** L-XL
- **Depends on:** OGAMI-001 (post opening balances into an open period)
- **Description:** Build import pipelines (CSV → staging → validate → commit) for COA, employees, customers, vendors, items, BOMs with dry-run + reconciliation report + variance threshold + rollback. Opening-balance JE generator that must net to a provided trial balance. Documented cutover + parallel-run checklist. (Distinct from the cut "import center with mapping/preview" — opening balances are mandatory for go-live.)
- **Acceptance:** import a sample Excel opening TB → reconciled to zero variance; master-data import dry-run reports rejects before commit; rollback restores prior state.
- **Evidence:** grep `opening_balance|DataMigration` → 0.
- **Note:** P0 for pilot, P2 for pure thesis demo.

### OGAMI-010 — Realistic demo dataset
- **Labels:** `priority/P0` `module/cross-cutting` `bucket/demo`
- **Estimate:** M (3-5d)
- **Depends on:** OGAMI-003 (seeded payroll must be correct)
- **Description:** Deterministic, idempotent generator: 200+ employees with realistic Filipino names + departments; 12 months of biometric punches with realistic late/OT/absent/leave distributions; ≥6 finalized payroll cycles; 12-month SO/PO/GRN/invoice/payment streams with value distribution; ≥40 NCRs across defect types for a real Pareto; demand-forecast history. Stop `ComprehensiveDemoSeeder` truncating-and-shrinking richer seeds.
- **Acceptance:** post-seed, dashboards show 12-month trends, a non-trivial NCR Pareto, ≥6 payroll cycles, and a populated forecasting screen; reseed is reproducible.
- **Evidence:** `DemoDataSeeder.php:44`; `ComprehensiveDemoSeeder.php:43-101`.

---

## P1 — Real-world usable

### OGAMI-011 — Payroll robustness: void, mid-cycle proration, raw-punch import
- **Labels:** `priority/P1` `module/payroll` `bucket/missing-feature` `bucket/failure-mode`
- **Estimate:** L
- **Depends on:** OGAMI-001 (void posts reversing entry into open period)
- **Description:** Add a `Voided` payroll state with reversing GL + payslip + loan-deduction reversal and re-run. Consult a salary-effective-date history for mid-cycle proration. Add a biometric punch-event sessionizer (pair raw IN/OUT into day records; handle offline backlog + duplicate punches with explicit merge; block import into a finalized period).
- **Acceptance:** void + re-run a finalized period atomically; a mid-period raise prorates; a raw ZKTeco export imports without manual pre-processing.
- **Evidence:** `PayrollPeriodStatus.php:9-13`; `PayrollCalculatorService.php:314-321`; `DTRImportService.php:35,72-83`.

### OGAMI-012 — Inventory lot/serial traceability + adjustment reason codes
- **Labels:** `priority/P1` `module/inventory` `bucket/missing-feature`
- **Estimate:** L
- **Depends on:** OGAMI-002 (variance approval threshold)
- **Description:** Capture lot/batch at GRN and thread it through issue → output → finished part (lot ledger). Add reason-code enum on stock adjustments + value-threshold approval.
- **Acceptance:** trace a finished part back to its resin lot + supplier; an adjustment requires a reason code; high-value adjustments require approval.
- **Evidence:** `GrnItem`/`StockMovement` no lot column; `StockAdjustmentService.php:17-49`.

### OGAMI-013 — Approval delegation + org-hierarchy routing
- **Labels:** `priority/P1` `module/approvals` `bucket/missing-feature`
- **Estimate:** M
- **Description:** Add a delegation/acting-for table with date ranges. Route approvals to the requester's actual department head via org hierarchy (not a global role slug). Gate the `is_urgent` Dept-Head skip behind a value cap or approval. Change escalation auto-resolve default from reject to escalate.
- **Acceptance:** an approver on leave delegates; a Molding PR routes to the Molding head only; `is_urgent` no longer self-bypasses without a cap; queue no longer auto-rejects on absence.
- **Evidence:** `ApprovalService.php:146-149`; `ApprovalEscalationService.php:17`; `PurchaseRequestService.php:190,232-253`.

### OGAMI-014 — AR/AP credit-debit memos + bill-payment approval + PO amendment & over-receipt
- **Labels:** `priority/P1` `module/accounting` `module/purchasing` `bucket/missing-feature`
- **Estimate:** L
- **Depends on:** OGAMI-001 (memos post into open periods)
- **Description:** Add credit/debit memo documents (AR + AP) with GL linkage. Invoke the seeded `bill_payment` workflow inside `recordPayment`. Add PO amendment with a version trail. Add configurable over-receipt tolerance %.
- **Acceptance:** issue an AR credit memo against a partially-paid invoice; bill payment routes through approval; amend an approved PO with history preserved; receive 1002kg against a 1000kg PO within tolerance.
- **Evidence:** grep `credit_memo` → 0; `BillService.php:257-318`; `PurchaseOrderService.php:210`; `GrnService.php:101-106`.

### OGAMI-015 — Multi-level BOM + WO/machine conflict + MRP stuck-run reaper
- **Labels:** `priority/P1` `module/mrp` `module/production` `bucket/schema` `bucket/failure-mode`
- **Estimate:** M-L
- **Description:** Make BOM explosion recursive to raw materials (with cyclic-BOM depth guard). Add a machine-occupancy conflict check in `WorkOrderService::confirm`. Add a heartbeat/reaper that marks stale `Running` MRP runs `Failed` and cancels their orphan draft PRs.
- **Acceptance:** a 2-level BOM orders raw resin correctly; two WOs cannot confirm onto the same machine simultaneously; a killed MRP run is reaped, no permanent `Running` row.
- **Evidence:** `BomService.php:104-119`; `WorkOrderService.php:194-196`; `MrpEngineService.php:280-358`.

### OGAMI-016 — Notification email + aging exports + inventory turnover + calibration register
- **Labels:** `priority/P1` `module/cross-cutting` `bucket/missing-feature` `bucket/reporting`
- **Estimate:** M-L
- **Description:** Dispatch email in `NotificationService::send` per channel preference + add a digest mode. Expose AR/AP aging as standalone endpoints + PDF/Excel exports. Add an inventory-turnover report. Add an IATF calibration register (gauge, last/next cal date, status, alert cron) reusing the training-expiry pattern.
- **Acceptance:** an email notification + digest send; AR aging exports to Excel; turnover report renders; an overdue-calibration alert fires.
- **Evidence:** `NotificationService.php:21`; `InvoiceService.php:302`/`BillService.php:328`; grep `turnover`/`calibrat` → 0.

---

## P2 — Competitive / differentiation

### OGAMI-017 — Multi-currency + JPY consolidation pack
- **Labels:** `priority/P2` `module/accounting` `bucket/missing-module`
- **Estimate:** XL
- **Depends on:** OGAMI-001 (translation at period close); confirm JP parent requirement
- **Description:** Add `currency` + `exchange_rate` to JE/invoice/bill; FX-rate table; period-end current-rate translation to a JPY trial-balance/consolidation pack. Optional Japanese i18n toggle (react-i18next).
- **Acceptance:** close a month → generate a JPY trial-balance pack with FX rates and a delta explanation.
- **Evidence:** grep `currency_code|fx_rate|jpy` → 0.
- **Note:** descope to multi-currency capture only if the parent doesn't require JPY consolidation.

### OGAMI-018 — Audit-prune vs immutability fix + scheduled backup + restore drill
- **Labels:** `priority/P2` `module/cross-cutting` `bucket/security`
- **Estimate:** S-M
- **Description:** Replace the `audit:prune` delete (which conflicts with the immutability trigger) with cold-archive-then-detach, or remove it and keep append-only. Add a scheduled `pg_dump` cron and execute + document a timed restore drill against the RTO target.
- **Acceptance:** audit prune no longer errors on PG / silently deletes on SQLite; backups run on schedule; a restore drill is executed and timed.
- **Evidence:** `PruneAuditLogs.php:26`; `console.php:143`; `Makefile:79-104`.

---

## Quick-win micro-tickets (cheap correctness/demo fixes, fold into nearest sprint)

### OGAMI-019 — Fix `plant_manager` notification role slug
- **Labels:** `priority/P2` `module/quality` `bucket/failure-mode` · **Estimate:** S (≤0.5d)
- **Description:** `TriggerOutgoingQC.php:120` notifies non-existent role `plant_manager` → no one is notified. Change to `production_manager` (per seeded roles).
- **Evidence:** `TriggerOutgoingQC.php:120` (DRIFT-3).

### OGAMI-020 — In-process QC trigger
- **Labels:** `priority/P1` `module/quality` `bucket/half-built` · **Estimate:** M
- **Description:** `InspectionStage::InProcess` exists but nothing creates an in-process inspection. Wire a trigger (periodic sampling between operations) so the IATF in-process touchpoint is real, not enum-only.
- **Evidence:** `InspectionStage.php:17`; no in-process trigger found.

### OGAMI-021 — `env()` in LogSlowQueries breaks under config:cache
- **Labels:** `priority/P2` `module/cross-cutting` `bucket/anti-pattern` · **Estimate:** S (≤0.5d)
- **Description:** `LogSlowQueries.php:51,56` calls `env()` outside config → returns null after `config:cache` in prod. Move to a config value.
- **Evidence:** `LogSlowQueries.php:51-56`.

### OGAMI-022 — Add `HasHashId` to `PurchaseRequestTemplate`
- **Labels:** `priority/P2` `module/purchasing` `bucket/anti-pattern` · **Estimate:** S (≤0.25d)
- **Description:** Only model missing the trait; raw integer id could leak. Add `HasHashId`.
- **Evidence:** `PurchaseRequestTemplate.php:13`.

---

## Dependency summary (critical path)

```
OGAMI-001 (period lock) ──┬─> OGAMI-009 (migration)
                          ├─> OGAMI-011 (payroll void)
                          ├─> OGAMI-014 (memos)
                          └─> OGAMI-017 (JPY consolidation)

OGAMI-004 (multi-UOM) ────┬─> OGAMI-005 (incoming QC)
                          └─> OGAMI-012 (lot trace)

OGAMI-003 (leave pay) ────> OGAMI-010 (realistic dataset)
OGAMI-002 (SoD) ──────────> OGAMI-012 (variance approval)
```

**Confirm two external facts in week 1** (gate priority): Toyota/Honda PEZA/zero-rated status (OGAMI-008) and whether the JP parent requires JPY consolidation (OGAMI-017).
