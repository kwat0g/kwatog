# Ogami ERP — Rebuild & Enhance Audit

> Senior staff-engineer + ERP domain-architect review of the Ogami ERP codebase,
> calibrated to: **thesis defense + real Ogami pilot + portfolio + commercial seed**.
> Bar = **pilot-credible** (real-world failure modes matter). Horizon = 6+ months.
> Lean = aggressive-rewrite permitted. Every finding cites `path:line` or doc heading.
>
> Generated: 2026-06-16. Re-derived fresh from source (prior-dated audit files in
> `docs/` were **not** inherited — discovery rules require re-derivation).
> Codebase census: 180 migrations · 123 controllers · 154 services · 151 test files
> (739 test methods) · 22 backend modules · 27 PDF/email Blade templates.

---

## 1. Executive Summary

This is, bluntly, one of the strongest ERP thesis codebases I have reviewed. All
three business chains are wired **end-to-end in code** — not stubbed. The IATF
quality spine (per-product spec → AQL Z1.4 sample sizing → actual measurements vs
tolerance → auto-NCR → auto-CoC on delivery) is real and is the single best
differentiator the project has. Auth, audit trail (app + DB trigger immutability),
PII encryption, and server-side row scoping are genuinely production-grade.

The gaps that matter are **not** "missing modules." They are **financial-integrity
controls, statutory-output fidelity, a handful of real-world correctness bugs in
payroll/QC/procurement, and demo-data volume**. These are exactly the things a BIR
auditor, an IATF assessor, a CFO from the Japanese parent, or a sharp defense
panelist will probe — so they are disproportionately high-leverage.

### Top 10 findings (headline)

| # | Finding | Sev | Evidence |
|--:|---------|:---:|----------|
| 1 | **No GL period-close lock** — any user can post/back-date a JE into a "closed" fiscal year; no period table consulted on any posting path. *Contests the CLAUDE.md:81 scope cut — see §3.D.* | P0 | `JournalEntryService.php:90-195`; grep `accounting_period` → 0 hits |
| 2 | **No maker-checker on Journal Entries** — same user creates AND posts a JE alone; JE bypasses `ApprovalService` entirely. | P0 | `JournalEntryService.php:167-195` |
| 3 | **Daily-rated workers paid ₱0 for approved paid leave** — leave sets `regular_hours=0`, "payroll will apply leave-pay logic" — that logic does not exist. | P0 | `LeaveRequestService.php:316-334`; `PayrollCalculatorService.php:244,307-308` |
| 4 | **No multi-UOM conversion** — resin bought in bags, issued in kg; single `unit_of_measure`, all qty math raw bcmath on one unit. Corrupts every resin receipt/issue. | P0 | `Item.php:29`; grep `conversion_factor` → 0 |
| 5 | **Incoming QC broken for raw materials** — trigger sets `product_id = item_id`, but inspection requires a CRM `Product` + spec; resin inspections seed zero measurement rows. No moisture/cert field exists. IATF incoming verification non-functional for the primary purchased material. | P0 | `TriggerIncomingQC.php:59` vs `InspectionService.php:94-101` |
| 6 | **Bills post against cancelled POs** — no PO-status check at bill creation; supplier invoice for a cancelled PO posts to AP + GL silently. | P0 | `BillService.php:130-160`; `StoreBillRequest.php:18` |
| 7 | **PH statutory outputs are demo-grade, not filing-grade** — 2316 not official layout, Alphalist non-conformant format, 1601-C/1604-CF/2307 missing, SSS R-3 exporter is dead-wired, RF-1/MCRF missing, gov tables stale (2024 SSS). | P0 | `BirAlphalistService.php`; `ExportRunner.php:21-23`; `GovernmentTableSeeder.php:90` |
| 8 | **VAT/invoice not BIR-compliant** — no ATP/permit/serial, no buyer TIN, no zero-rated/exempt split (Toyota/Honda exports are likely zero-rated/PEZA), no Senior/PWD discount, no Official Receipt distinct from Sales Invoice. | P0 | `0048_create_invoices_table.php:22`; `invoice.blade.php`; grep `zero_rated|pwd` → 0 |
| 9 | **No idempotency on financial writes** — double-POST to Invoice/Bill/JE creates duplicate money documents (idempotency exists only on Edge/production-output paths). | P1 | `WorkOrderOutputService.php:51` (present) vs `InvoiceService`/`BillService` (absent) |
| 10 | **Demo data is placeholder-volume** — 5 employees (not 200), 5 perfect attendance days, 1 payroll cycle, ~3 invoices/POs/NCRs all dated "now", no 12-month history, no forecast seed; `ComprehensiveDemoSeeder` truncates richer data then reseeds tiny volumes. Pareto/forecast/payroll-scale narratives will look empty on defense day. | P0 (demo) | `DemoDataSeeder.php:44`; `ComprehensiveDemoSeeder.php:43-101` |

### Headline module verdicts

| Module | Verdict | One-line justification |
|--------|:-------:|------------------------|
| Quality (IATF spine) | **Keep + targeted enhance** | Genuinely excellent; fix raw-material incoming QC + in-process trigger + CoC measurement print |
| Accounting / GL | **Enhance (P0)** | Add period-close lock + JE maker-checker + credit memo; structure is sound |
| Payroll | **Enhance (P0/P1)** | Fix daily-rate leave pay, mid-cycle proration, finalized-run void; engine core is good |
| Localization / statutory | **Rewrite the output layer** | Demo-grade forms must become filing-grade; biggest "claim vs proof" gap |
| Inventory / Purchasing | **Enhance (P0)** | Multi-UOM, over-receipt tolerance, PO amendment, bill-vs-cancelled-PO guard |
| CRM / O2C | **Enhance (P1)** | Multi-level BOM, credit hold, credit memo |
| Migration tooling | **Build (missing)** | No opening-balance / master-data import path exists — blocks real go-live |
| Peripheral (B2B, Assets, Maint, Edge, Forecasting, RMA, Dashboard) | **Keep** | All real, none stubbed; Assets needs tests |

### "If you only had 2 weeks" (ranked by severity × blast-radius ÷ effort)

1. **REC-03 Daily-rate leave pay bug** — silent underpayment on every leave; tiny fix, huge trust impact. (S, P0)
2. **REC-10 Seed a realistic dataset** (200 employees, 12-mo history, ≥6 payroll cycles, volume NCRs/SOs/POs) — unlocks every demo narrative at once. (M, P0-demo)
3. **REC-01 GL period-close lock + REC-02 JE maker-checker** — the two financial-integrity P0s, shared infra. (M, P0)
4. **REC-06 Bill-vs-cancelled-PO guard + 3-way-match-per-delivery fix** — cheap correctness on the money path. (S, P0)
5. **REC-08 BIR-compliant invoice + Official Receipt + zero-rated/Senior-PWD** — the most-probed compliance surface. (M, P0)

---

## 2. Phase 1 — Ground-Truth Map

### Stack (verified)
- **API:** Laravel 11 / PHP 8.3 (`api/composer.json`), PostgreSQL 16, Redis 7, Meilisearch dep present (`meilisearch/meilisearch-php`), Reverb WebSocket (`laravel/reverb`), Sanctum 4 (`laravel/sanctum`), HashIDs (`vinkla/hashids`), DomPDF for PDF. **Note:** Meilisearch is a dependency but **Scout `Searchable` is used by zero models** — global search is hand-rolled `ILIKE` (`GlobalSearchService`). Doc/code drift.
- **SPA:** React 18 + TypeScript + Vite, TanStack Query/Table, Zustand, React Hook Form + Zod, react-hot-toast, axios with `withCredentials: true` confirmed (`spa/src/api/client.ts:27`).
- **Auth:** Sanctum SPA cookie mode confirmed (`bootstrap/app.php:38` `statefulApi()`); HTTP-only + secure + lax (`config/session.php:23-25`). No bearer tokens for human users; portals + edge devices use scoped bearer guards by design.
- **Deploy:** Docker Compose (dev + `docker-compose.prod.yml`), Nginx, `docs/DEPLOY.md`.
- **Tests:** PHPUnit 11 (739 methods / 151 files) + Vitest. Per CLAUDE.md the full suite is ~746 tests / 0 fail / ~4 min.

### Modules / bounded contexts (22 backend, mirrored in SPA)
`Auth, HR, Attendance, Leave, Payroll, Loans, Accounting (incl. Budgeting), Inventory,
Purchasing, SupplyChain, Production, MRP, CRM, Quality, Maintenance, Dashboard, Assets,
B2B, Forecasting, ReturnManagement, Edge, Admin`. Each follows
`Controllers/Models/Services/Requests/Resources/Jobs/routes.php`. Module routes
auto-mount under `/api/v1` via `ModuleServiceProvider`.

### Three chains (all implemented end-to-end in code)
- **Chain 1 O2C:** `SalesOrderService` → `MrpEngineService` → `CapacityPlanningService` → `WorkOrderService`/`WorkOrderOutputService` → `InspectionService` (outgoing AQL) → `DeliveryService` (+ `CoCService`) → `InvoiceService` → collections → `JournalEntryService`.
- **Chain 2 P2P:** MRP shortage → `PurchaseRequestService` → `ApprovalService` → `PurchaseOrderService` → `ShipmentService` → `GrnService` (+ incoming QC) → `StockMovementService` (WAC) → `BillService` → payment → GL.
- **Chain 3 H2R:** HR `Employee` → `ShiftService` → `DTRImportService` → `DTRComputationService` → `OvertimeService`/`LeaveRequestService` → `PayrollCalculatorService` → `PayslipPdfService` → `BankFileService` → `PayrollGlPostingService` → `SeparationService`/`FinalPayService`.

### Async / scheduled hand-offs (`api/routes/console.php`)
`mrp:run-daily` (06:00), `alerts:run` (15m), `payroll:auto-create-period` (14th + last day),
`approvals:run-escalations` (6h), `maintenance:generate-preventive` (02:00),
`assets:run-monthly-depreciation` (1st 03:00), `ncr:escalate` (15m),
`complaints:check-8d-slas` (15m), `docs:check-reviews` (06:45), `audit:prune`,
`RunDueScheduledExports`. Payroll GL posting and payslip email are queued jobs with
`ShouldBeUnique`.

### Explicit scope cuts (CLAUDE.md:78-88) — treated as intentional
Cost accounting, cash-flow forecasts, bank reconciliation, **closing wizards / fiscal
period locking**, tax compliance calendar, customizable dashboards, setup wizard /
onboarding-as-product, system-health dashboard, import center with mapping/preview,
activity feeds everywhere, RFQ, per-shot mold depreciation.

> **Contested cut (one only):** "fiscal period locking" (CLAUDE.md:81). I am
> recommending it back as REC-01 — justified in §3.D and §4 because, for a
> BIR-audited, IATF-certified manufacturer reporting to a Japanese parent, the
> absence of a period lock is a *financial-integrity* failure mode, not a
> convenience feature. All other cuts I accept and do not propose back.

### Doc/Code Drift findings (logged immediately per rules)
- **DRIFT-1:** README/architecture claim Meilisearch; **no model is `Searchable`** — search is raw `ILIKE` in `GlobalSearchService`. (P2)
- **DRIFT-2:** `audit:prune` (`PruneAuditLogs.php:26`, cron `console.php:143`) issues `DB::table('audit_logs')->delete()`, which **conflicts with the append-only immutability trigger** (`2026_06_09_100001_add_audit_log_immutability_trigger.php`): on PostgreSQL the prune will *raise and fail every run*; on SQLite it silently deletes. An "immutable" trail with a deletion job wired into cron. (P1)
- **DRIFT-3:** `TriggerOutgoingQC.php:120` notifies role slug `plant_manager`, which does not exist in the seeded roles (should be `production_manager`); the `whereIn` matches nothing — production managers never receive the outgoing-QC notification. (P2)
- **DRIFT-4:** CLAUDE.md:81 cuts "fiscal period locking" while the same project intends JP-parent consolidation + BIR/IATF audit-readiness — the cut and the goal are in tension. (Promoted to REC-01.)

---

## 3. Phase 2 — The Lens (what "real-world solid" means here)

### 3a. Domain reality at Philippine Ogami Corp
- **Monday 07:00:** HR pulls the biometric CSV from the device; production supervisors release WOs against the week's SO load; QC sets up AQL samples for Friday's Toyota shipment. *Blocker today:* the biometric importer expects pre-paired day rows, not raw IN/OUT punch events (`DTRImportService.php:35,72-77`) — real ZKTeco exports won't load without manual Excel pre-processing.
- **Reports by deadline:** DTR before the 16th/1st cut-off; payroll register at payday-2; AR aging Friday; **NCR Pareto monthly for IATF**; **BIR 1601-C monthly, 2316 + Alphalist + 1604-CF annually, SSS R-3 / PhilHealth RF-1 / Pag-IBIG MCRF monthly**; JP parent monthly consolidation pack in JPY. *Most of the statutory set does not yet produce a filing-grade artifact.*
- **Month-end close:** today there is **nothing stopping a back-dated edit** to a closed month (no period lock) — the CFO's trial balance can silently drift after he sends it to Tokyo.
- **Failure moments:** MRP batch leaves a permanent `Running` row on a hard kill (`MrpEngineService.php:280-358`, no reaper); biometric backlog can't sessionize; backdated OT after finalize *is* handled via next-period adjustment (good), but late OT landing in an already-finalized period is silently lost (`DTRComputationService.php:62-68`).

### 3b. Non-functional bar (stated, since docs don't)
| Metric | Target (assumed) | Basis |
|--------|------------------|-------|
| Concurrent users (peak) | 40-60 | ~200 staff, office + supervisors + QC + a few portal |
| Payroll wall-clock (200 emp, semi-monthly) | ≤ 3 min | queued `ProcessPayrollJob`, per-employee compute |
| MRP run wall-clock | ≤ 5 min | nightly batch over active SOs |
| Largest report (Alphalist / aging) | ≤ 10 s | annual aggregate |
| Uptime | 99% office; factory Edge tolerant of API downtime | shop-floor must buffer |
| RPO | ≤ 15 min | nightly `pg_dump` today is **24h RPO** — gap |
| RTO | ≤ 4 h | manual restore script only, no drill evidence |
| Peak windows | payday (16th/1st), month-end, year-end, IATF/BIR audit week | |

### 3c. Competitive anchor
- **Odoo 17 (mfg + PH localization community):** multi-UOM with conversion, credit notes, period lock, supplier EWT/2307, lot/serial traceability — **all present in Odoo, missing or partial here** (multi-UOM `Item.php:29`; credit memo grep → 0; period lock → 0; EWT → 0; lot trace → incidental only).
- **SAP Business One:** period-end close with posting periods + locking, maker-checker on JE, landed-cost documents — **all missing here** (landed cost grep → 0).
- **NetSuite:** revenue-recognition + multi-currency consolidation — multi-currency entirely absent (grep `currency_code|fx_rate` → 0), which directly blocks the JP-parent differentiator.

### 3d. Role walkthroughs (dead ends today)
- **Maria (HR/payroll):** imports biometric CSV → must hand-pair punches first (P1); runs payroll → **daily-rated leave pays ₱0** (P0); a mid-period raise pays the wrong rate all period (P1); cannot void a finalized run if she fixed the wrong period (P1); payslip lacks employee TIN/SSS numbers + YTD that DOLE/BIR expect (P1).
- **Ben (AP):** receives an invoice for a PO that was cancelled → system **posts it to GL anyway** (P0); 2nd partial-shipment invoice → 3-way match compares against *cumulative* GRN qty, variance math wrong (P1); supplier short-ships a return → **no credit memo instrument** exists (P1); pays a bill → **no Finance/VP approval** despite a seeded-but-dead `bill_payment` workflow (P1).
- **Joel (production):** can confirm two WOs onto the same machine — `WorkOrderService::confirm` only checks machine/mold non-null, no conflict detection (P1); scheduler has no shift calendar, "scheduled" dates are fiction under load (P1); reject codes exist and work.
- **Liza (QC):** outgoing AQL is genuinely strong (real Z1.4 table, actual measurements vs tolerance, auto-NCR) — **her happy path is the system's best feature**; but **incoming resin QC seeds zero measurement rows** (P0), in-process QC has no trigger (P1), and the CoC PDF doesn't print the actual critical-dimension values automotive PPAP expects (P2).
- **Mr. Tanaka (CFO):** closes the month → **no lock, silent drift risk** (P0); approves a JE → can also *post his own* (P0 SoD); exports TB for Tokyo → **PHP only, no JPY translation** (P1); signs BIR forms → **2316/Alphalist not in official format** (P0).

### 3e. Thesis differentiators — claim vs proof
| Differentiator | In docs? | In code? | Verdict |
|----------------|:--------:|:--------:|---------|
| IATF spec→inspection→NCR→CoC chain | yes | **yes, real** (`InspectionService.php:111-315`, `CoCService.php`) | **PROVEN — protect it** |
| AQL 0.65 Level II with actual measurements vs tolerance | yes | **yes** (`AqlSampleSizeService.php:29-53`; `InspectionMeasurement.php:57-65`) | **PROVEN** |
| Mold shot tracking + preventive maint tied to output | yes | **yes** (`WorkOrderOutputService.php:135-140`; `GeneratePreventiveMaintenanceJob`) | **PROVEN** |
| Real-world failure handling (idempotency, locks) | implied | **partial** — output/Edge yes; money paths no; no period lock | **HALF-PROVEN — highest leverage** |
| Full PH statutory output (filing-grade) | yes | **no — demo-grade** | **ADVERTISED, NOT PROVEN — fix to convert claim→proof** |
| JP parent consolidation | implied | **no** (no multi-currency) | **NOT PROVEN** |
| Migration tooling (Excel → opening balances) | implied | **no** | **NOT PROVEN** |

> The three "advertised-not-proven" rows are the **highest-leverage Phase 4 work**:
> they turn thesis *claims* into demonstrable *proof*.

---

## 4. Phase 3 + 3.5 — Findings by Bucket

### A. Missing modules / capability areas
- **Multi-currency / FX** (grep `currency_code|fx_rate|exchange_rate|jpy` → **0**). Blocks JP-parent consolidation. (P1)
- **Migration / opening-balance tooling** (grep `opening_balance|OpeningBalance|DataMigration` → **0**). Blocks real go-live. (P0 for pilot)
- **Credit memo / debit memo** (AR and AP) (grep `credit_memo|credit_note` → **0**). Returns/disputes have no accounting instrument. (P1)
- **Landed cost** on imports (grep `landed|duty|freight` on shipment → **0**). Imported resin valued at PO price only; WAC understated. (P1)
- **Calibration register** (IATF gauge/equipment calibration) (grep `calibrat` → demo text only). Material IATF compliance gap. (P1)
- **Supplier EWT / 2307** withholding on AP (grep `ewt|2307|creditable` → **0**). (P0 statutory)

### B. Half-built / advertised-not-wired
- **SSS R-3 exporter** exists (`SssR3Export.php`) but is **not registered** in `ExportRunner::MAP` (`ExportRunner.php:21-23`) and reads non-existent columns (`sss_employer`, `period_id`) — dead code. (P0)
- **`bill_payment` approval workflow** seeded (`WorkflowSeeder.php:78-85`) but **never invoked** — `BillService::recordPayment` pays with no approval. (P1)
- **In-process QC stage** exists in the enum (`InspectionStage.php:17`) but **no trigger** creates it. (P1)
- **`PhilHealthRf1` / `PagIbigRemittance` DocumentType enums** exist with permission stubs but **no exporter classes**. (P0)

### C. Missing features inside existing modules
- **Payroll:** no finalized-run void/reversal (`PayrollPeriodStatus.php:9-13`); no mid-cycle salary proration (only `date_hired` prorated, `PayrollCalculatorService.php:314-321`); gov tables not effective-date-aware at compute (`GovernmentContributionTable.php:48-51` filters `is_active` only) and SSS table stale to 2024 (`GovernmentTableSeeder.php:90`); 13th-month ₱90k tax exemption not applied (`ThirteenthMonthService.php:85-88`); final-pay components are placeholder math (`FinalPayService.php:142-151,216-225`).
- **Inventory:** no multi-UOM (P0, §1.4); no lot/serial trace on receipt (`GrnItem`/`StockMovement` carry no lot); adjustment reason is free text, no reason codes, no value-based approval threshold (`StockAdjustmentService.php:17-49`).
- **Purchasing/PO:** no over-receipt tolerance (`GrnService.php:101-106` hard-blocks); no PO amendment after submit (`PurchaseOrderService.php:210`); no price-variance approval at PO; no back-order entity.
- **GRN/match:** 3-way match aggregates GRN qty across **all** GRNs for the PO, wrong for split invoicing (`ThreeWayMatchService.php:41-49`); match override is a single boolean, no secondary approval (`BillService.php:153`).
- **SO/O2C:** no credit hold/release state (`SalesOrderStatus` has none); credit limit checked once at confirm, never re-checked (`SalesOrderService.php:295`); single-level BOM explosion under-orders sub-assemblies (`BomService.php:104-119`); no credit memo (§A).
- **Approvals:** no delegation when approver on leave — auto-resolve **defaults to reject** (`ApprovalEscalationService.php:17`); routing is by global role slug, not requester's actual department head (`ApprovalService.php:146-149`); `is_urgent=true` self-bypasses the Dept-Head step with only free text (`PurchaseRequestService.php:190,232-253`).
- **Notifications:** central `send()` writes in-app only and **never dispatches email** despite a channel matrix (`NotificationService.php:21`); no digest.
- **Reporting:** AR/AP aging computed but only inside the finance dashboard, **no standalone endpoint/export** (`InvoiceService.php:302`, `BillService.php:328`); **inventory turnover missing entirely**; no training-matrix view.

### D. Cross-cutting processes
- **Period close / GL lock — the headline P0.** Grep `accounting_period|period_close|period_lock` → **0 accounting hits**. `FiscalYear` exists **only for budgeting** (`0162_create_budgeting_tables.php`), no monthly periods, no posting linkage. `JournalEntryService.create/post` (`:90-195`), Invoice finalize, Bill, and Payroll **never consult period status**. A user can post or back-date into any prior/"closed" period. *This is the cut at CLAUDE.md:81 — I contest it; for a BIR/IATF/JP-audited entity it is an integrity control, not a convenience.*
- **Maker-checker on JE — P0 SoD.** `JournalEntryService.post(JE, User $by)` (`:167`) never compares `created_by` to `$by`; JE bypasses `ApprovalService`. One user authors and posts. Vendor-create vs PO-approve and inventory adjust-vs-approve are likewise not control-separated.
- **Onboarding:** `OnboardingService` tracks account/role/banking but **not asset issuance or biometric/Edge enrollment** — partial orchestration. (P2)
- **Audit trail:** strong (app `AuditLog.php:34-58` + PG triggers) — but the `audit:prune` deletion job contradicts it (DRIFT-2). (P1)
- **Backup/restore:** real `make backup`/`restore` scripts (`Makefile:79-104`) but **no scheduled backup cron and no restore-drill evidence**; 24h RPO. (P1)
- **Migration for go-live:** absent (§A). (P0 pilot)

### E. Schema stress test (5 central tables)
- **`invoices`** (`0048`): no currency/FX → JPY consolidation impossible; no zero-rated/exempt classification → wrong VAT for PEZA/export sales; no credit-memo linkage → partial reversal of a partially-paid invoice impossible; no ATP/serial → not a valid BIR document.
- **`journal_entries`:** no `accounting_period_id`/period status → back-dated post into closed month; no currency → single-ledger only; no reversing-entry linkage.
- **`items`** (`Item.php:29`): single `unit_of_measure`, no conversion → bag↔kg corruption; no lot/serial → resin traceability cannot be reconstructed; no effective-dated standard cost.
- **`payrolls`:** no salary-effective-date source → mid-cycle proration impossible; gov tables not date-resolved → historical recompute uses today's table; no void state.
- **`stock_movements`:** no lot/batch column → IATF lot→WO→part trace broken on procurement side; WAC recompute is pessimistic-locked (good) but `lock_version` on `StockLevel` is bookkeeping only, never enforced in a `where` (`StockMovementService.php:68,117`).

### F. Failure-mode catalog
| Scenario | Handled? | Evidence |
|----------|:--------:|----------|
| Duplicate submit on money write | **No** | idempotency only on `WorkOrderOutputService.php:51`; absent on Invoice/Bill/JE |
| Concurrent edit of same business doc | **No** | `lock_version` only on `StockLevel`, never checked in `where`; last-write-wins elsewhere |
| Background job retried twice | **Mostly** | payroll jobs `ShouldBeUnique`; depreciation/preventive `tries=1` but no unique → double-dispatch unguarded |
| Multi-table money write atomicity | **Yes** | `DB::transaction` in Invoice/Bill/Payroll/JE services |
| Doc numbering under concurrency | **Yes** | `DocumentSequenceService.php:72-107` `lockForUpdate`; but no gap detection |
| MRP crash mid-run | **No** | `MrpEngineService.php:280-358` leaves permanent `Running`, no reaper |
| Permission revoked mid-session | **Delayed** | `User::permission_slugs` cached (`User.php:116`), needs `flushPermissionsCache()` |
| Biometric offline → bulk sync | **No** | importer needs pre-paired day rows (`DTRImportService.php:35`) |
| Supplier invoice for cancelled PO | **No** | `BillService.php:130-160` no status check |
| Payroll finalized then backdated OT | **Partial** | next-period adjustment works (`PayrollAdjustmentService.php:46-86`); late OT into finalized period lost |

### G. Stack anti-patterns (swept)
- **Clean:** no `->float/->double` money columns (0 hits); no `$guarded=[]` (0); no localStorage/bearer auth in SPA; `withCredentials:true` everywhere; `DB::raw` uses are static aggregates (no user interpolation); FK indexing hygiene good in sampled migrations.
- **Minor:** one model missing `HasHashId` (`PurchaseRequestTemplate.php:13`); 7/139 `FormRequest::authorize()` return bare `true` (rely on route middleware); `env()` called in `LogSlowQueries.php:51,56` (breaks under `config:cache`); 3 fat controllers >400 lines (`SupplierPortalController` 526, `SelfServiceController` 476, `BudgetController` 405); ~4 mutations missing `invalidateQueries` (of 128).

### 3.5a Reporting taxonomy
- **Operational:** DTR, GRN, picking/delivery proof, production schedule, QC logs — present.
- **Management:** OEE, downtime Pareto, NCR Pareto, supplier scorecard — present as endpoints; **AR/AP aging dashboard-only** (no export); **inventory turnover missing**.
- **Statutory:** see 3.5b — largely demo-grade.
- **Ad-hoc:** strong — `ExportController` + `ExportColumnRegistry` with saved column selections + **scheduled email exports** (`RunDueScheduledExports.php:88`). This is a genuine strength.

### 3.5b PH + JP localization (filing-grade audit)
| Item | Status | Sev | Evidence |
|------|:------:|:---:|----------|
| WHT on compensation (graduated) | **Works (per-period)** | P1 | `BirTaxComputationService.php:28-57`; no year-end annualization/true-up |
| Form 2316 | **Partial — not official layout** | P0 | `bir-2316.blade.php`; no RDO/prior-employer/de-minimis/substituted-filing blocks |
| Alphalist | **Non-conformant format** | P0 | `BirAlphalistService.php:26-109` generic CSV, not DAT schedule |
| 1601-C | **Stub (enum only)** | P0 | `DocumentType.php:29`; no generator |
| 1604-CF / 1601-EQ / 1604-E / 2306 | **Missing** | P0/P1 | grep → 0 |
| 2307 creditable WHT (supplier) | **Missing** | P0 | grep `2307|ewt` → 0 |
| SSS R-3 | **Dead-wired** | P0 | `ExportRunner.php:21-23` doesn't register it |
| PhilHealth RF-1 / Pag-IBIG MCRF | **Missing (enum stub)** | P0 | no exporter classes |
| Gov contribution tables | **Effective-dated schema, stale data, not date-resolved at compute** | P1 | `GovernmentContributionTable.php:48-51`; SSS 2024 (`GovernmentTableSeeder.php:90`) |
| DOLE statutory leave catalog | **Good** | P1 | `LeaveTypeSeeder.php:14-23` (SIL, 105-day maternity, paternity, solo-parent, VAWC, Magna Carta) |
| VAT 12% calc | **Flat only** | P0 | `invoice.blade.php:54`; no zero-rated/exempt split |
| Senior/PWD discount | **Missing** | P0 | grep → 0 |
| BIR invoice (ATP/serial/buyer-TIN/ORIGINAL-DUPLICATE) | **Missing** | P0 | `invoice.blade.php` |
| Official Receipt (distinct from SI) | **Missing** | P1 | no OR template |
| Multi-currency / JPY TB / intercompany | **Missing** | P1 | grep → 0 |
| Japanese i18n toggle | **Missing** | P1 | no i18next in `spa/package.json` |

### 3.5c Security / SoD
Auth, lockout, password history, append-only audit (app + DB trigger), PII encryption +
masking (`Employee.php:54-58`, `EmployeeResource.php:57-94`), server-side row scoping
(`PurchaseRequestService.php:54-60`, `LeaveRequestService.php:50-66`) — **all genuinely
implemented**. Gaps: **no JE maker-checker (P0)**, vendor-create vs PO-approve not
separated (P1), inventory adjust-vs-approve unguarded (P1), `audit:prune` vs immutability
(P1), no PII retention/erasure policy for separated employees (P1, RA 10173),
`system_admin` bypasses all permissions (P2).

### 3.5d Migration readiness
**Absent.** No extract/transform/load tooling, no opening-balance import with TB match, no
dry-run/reconciliation, no parallel-run support, no cutover plan in docs. Master-data
seeders exist but are demo seeders, not import pipelines. For a real Ogami pilot migrating
off Excel+paper+legacy, this is a **P0 go-live blocker** (distinct from the cut
"import center with mapping/preview" — opening balances are not optional).

### 3.5e Demo-day failure modes
No dead sidebar links (all 56 targets resolve), no placeholder `lorem/Item 1` leakage,
dark mode functional, SPA currency consistent (`formatPeso` `en-PH`). Real risks:
**(P0)** every data-volume narrative is empty (§3.5f); **(P1)** CoC PDF **hardcodes result
"PASSED"** (`coc.blade.php:50`) regardless of inspection outcome — a panelist who ships a
failed lot sees a passing cert; **(P1)** dashboards (Pareto/forecast/payroll trend) render
flat from thin data; **(P2)** PDF currency split (`PHP` in finance PDFs vs `₱` in HR PDFs),
PO/payslip skip shared page-numbering, generic non-part-specific inspection specs.

### 3.5f Seed realism
| Dimension | Reality | Sev |
|-----------|---------|:---:|
| Employees | **5** (`DemoDataSeeder.php:44`), real names | P0 |
| Attendance | **5 perfect days**, no late/OT/absent (`DemoDataSeeder.php:210-252`) | P0 |
| Customers | Toyota/Nissan/Honda/Suzuki/Yamaha PH, real TINs (`CustomerSeeder.php:19-23`) | OK |
| Suppliers | Plausible resin/tooling (4) | OK |
| Products/BOMs | Real part names + realistic BOMs (`ProductSeeder.php:19-26`, `BomSeeder.php:24-67`) | OK |
| Invoices/POs/GRNs/SOs | ~3 each, all dated "now" | P0 |
| NCRs | **3** — too few for a Pareto | P0 |
| Payroll cycles | **1 finalized + 1 open** | P0 |
| 12-month history / forecasts | **None** (no DemandForecast seeder) | P0 |
| Architecture | `ComprehensiveDemoSeeder` truncates 40+ tables then reseeds tiny volumes (`:43-101`) | P0 |

### 3.5g Print / PDF
Shared `_components` (conditional watermark, letterhead, real `Page N of M` footer via
DomPDF php). **Issues:** PO + payslip don't extend `_layout` → miss page-numbering;
payslip watermark hardcoded `CONFIDENTIAL`; **CoC result hardcoded "PASSED" (P1)**;
invoice not BIR-compliant + no OR (P0/P1); 2316 simplified vs official (P0); currency glyph
split (P2).

---

## 5. Phase 4 — Recommendations

> Grouped by priority. Effort: S ≤2d · M 3-5d · L 1-2w · XL >2w.
> Recommendations sharing infrastructure are merged.

### P0 — Foundation (trust with real money / people / shipments)

#### [REC-01] GL period-close lock with reopen-with-trail *(contests CLAUDE.md:81 cut)*
- Bucket: cross-cutting + missing-feature
- Module/chain: Accounting/GL — all 3 chains
- Why it matters: Mr. Tanaka closes January, sends the TB to Tokyo; Maria posts a backdated Jan adjustment on Feb 12; the TB silently drifts. BIR/IATF auditor catches it, JP parent loses trust. The project intends JP consolidation + audit-readiness, so the documented cut of "fiscal period locking" is in direct tension with the goal.
- What breaks without it: silent retroactive edits → TB drift → audit finding.
- Proposal: `accounting_periods` (year, month, status open|closed|reopened, closed_by/at, reopened_by/at, reason); a posting guard consulted by `JournalEntryService`, `InvoiceService`, `BillService`, `PayrollGlPostingService` that blocks writes whose effective date falls in a closed period unless a time-boxed reopen is approved; reopen workflow via existing `ApprovalService`; "transactions posted to reopened periods" report.
- Dependencies: `ApprovalService`, audit log, `NotificationService`.
- Effort: M (4-5d). Priority: **P0**.
- Risk if deferred: every closed month is an integrity time bomb; cleanup compounds.
- Evidence: grep `accounting_period|period_lock` → 0; `JournalEntryService.php:90-195` posts without period check; `FiscalYear` is budgeting-only (`0162`).
- Verdict: **enhance**.

#### [REC-02] Maker-checker on Journal Entries + close the obvious SoD conflicts
- Bucket: security/SoD
- Module/chain: Accounting; Purchasing; Inventory.
- Why it matters: one user authoring and posting a JE is the canonical audit finding; vendor-create + PO-approve by one user enables fraud.
- Proposal: route JE post through `ApprovalService` (or at minimum block `created_by === poster` above a threshold); add a "PO approver did not create the payee vendor" check; require secondary approval on inventory adjustments above a value threshold.
- Dependencies: `ApprovalService`, REC-12 (reason codes) for inventory.
- Effort: S-M (2-3d). Priority: **P0**.
- Evidence: `JournalEntryService.php:167-195` (no maker-checker); `Accounting/routes.php:46` vs `Purchasing/routes.php:53`.
- Verdict: **enhance**.

#### [REC-03] Pay daily-rated employees for approved paid leave
- Bucket: failure-mode (correctness bug)
- Module/chain: Payroll/Leave — Chain 3.
- Why it matters: every VL/SIL day pays a daily-rated line worker **₱0** today; this is a silent, recurring underpayment that destroys payroll trust on first run.
- Proposal: `PayrollCalculatorService` counts approved paid-leave days into a paid-leave earnings line (rate × leave-days) for daily-rated staff; implement the "leave-pay logic" the leave service already promises.
- Dependencies: none.
- Effort: S (1-2d). Priority: **P0**.
- Evidence: `LeaveRequestService.php:316-334`; `PayrollCalculatorService.php:244,307-308`.
- Verdict: **enhance**.

#### [REC-04] Multi-UOM with conversion factors (purchase ↔ stock ↔ issue)
- Bucket: schema + missing-feature
- Module/chain: Inventory — Chains 1 & 2.
- Why it matters: resin is bought in bags/drums and consumed in kg; with one `unit_of_measure` every receipt/issue corrupts the balance and WAC.
- Proposal: `uom` master + `item_uom_conversions` (item, from_uom, to_uom, factor, rounding); base-UOM per item; convert at GRN receipt, material issue, BOM consumption, stock card. Display in transaction UOM, store in base.
- Dependencies: touches GRN, MaterialIssue, BOM explode, StockCard.
- Effort: L (1-2w). Priority: **P0**.
- Evidence: `Item.php:29`; grep `conversion_factor` → 0.
- Verdict: **enhance** (significant but contained).

#### [REC-05] Fix incoming QC for raw materials + capture resin cert/moisture
- Bucket: failure-mode + half-built (IATF differentiator)
- Module/chain: Quality/Inventory — Chain 2.
- Why it matters: the IATF incoming-verification story is non-functional for the primary purchased material — resin inspections seed zero measurement rows.
- Proposal: let `InspectionSpec` target raw-material `Item`s (polymorphic spec subject, or an Item-spec path) so incoming inspections seed real parameters; add resin attributes (lot, moisture %, COA/cert upload, MFI); wire the trigger to the correct subject; add a quarantine zone so pending/rejected stock is physically segregated.
- Dependencies: `InspectionService`, `WarehouseZoneType`, GRN gate.
- Effort: M-L (5-8d). Priority: **P0**.
- Evidence: `TriggerIncomingQC.php:59` vs `InspectionService.php:94-101`; grep `moisture|resin.*cert` → 0; no quarantine zone.
- Verdict: **enhance**.

#### [REC-06] Money-path integrity bundle: cancelled-PO bill guard + per-delivery 3-way match + financial idempotency
- Bucket: failure-mode (merged — shared money-write infra)
- Module/chain: Accounting/Purchasing — Chain 2.
- Why it matters: Ben can post a bill against a cancelled PO; split-shipment invoices match against cumulative qty (wrong); a double-submit duplicates a money document.
- Proposal: reject bill creation when PO status ∈ {cancelled, closed}; match 3-way against the specific GRN/delivery, not summed GRNs; add an `Idempotency-Key` guard (reuse the `WorkOrderOutputService` cache pattern) on Invoice/Bill/JE/payment create.
- Dependencies: `ThreeWayMatchService`, `BillService`, `InvoiceService`.
- Effort: M (3-4d). Priority: **P0**.
- Evidence: `BillService.php:130-160`; `ThreeWayMatchService.php:41-49`; idempotency absent on money paths.
- Verdict: **enhance**.

#### [REC-07] BIR/SSS/PhilHealth/Pag-IBIG filing-grade outputs
- Bucket: localization (merged — one statutory-output workstream)
- Module/chain: Payroll/Accounting — Chain 3 + statutory.
- Why it matters: 2316, Alphalist, 1601-C, 1604-CF, SSS R-3, RF-1, MCRF are what actually get filed; today they're demo-grade, stub, or dead-wired. This is the single biggest "claim → proof" conversion for the thesis.
- Proposal: official-layout 2316 (RDO, de-minimis, 13th-month, substituted-filing); conformant Alphalist (DAT/schedule, ATC); 1601-C + 1604-CF generators; register SSS R-3 in `ExportRunner::MAP` and fix its columns; build RF-1 + MCRF exporters; refresh + effective-date-resolve gov tables (SSS 2025) so historical recompute uses the table in force then; add WHT year-end annualization/true-up.
- Dependencies: `ExportColumnRegistry`/`ExportRunner`, gov table service.
- Effort: XL (>2w). Priority: **P0**.
- Evidence: §3.5b table.
- Verdict: **rewrite the statutory-output layer** (generators), keep computation engines.

#### [REC-08] BIR-compliant Sales Invoice + Official Receipt + VAT classification (zero-rated / Senior-PWD)
- Bucket: localization + reporting
- Module/chain: Accounting/CRM — Chain 1 + statutory.
- Why it matters: Toyota/Honda exports are likely zero-rated/PEZA; the invoice today is flat-12% with no ATP/serial/buyer-TIN and no OR. A panelist probing tax compliance finds a credibility gap.
- Proposal: invoice fields for ATP/permit no., serial range, buyer TIN, ORIGINAL/DUPLICATE, VATable/exempt/zero-rated line classification, Senior/PWD discount; separate Official Receipt template + numbering for collections; CoC PDF stops hardcoding "PASSED" and prints actual disposition + critical-dimension measurements.
- Dependencies: invoice schema migration, `DocumentSequenceService` (OR series).
- Effort: M-L (5-8d). Priority: **P0** (CoC "PASSED" fix is **P1-demo, S**).
- Evidence: `0048_create_invoices_table.php:22`; `invoice.blade.php`; `coc.blade.php:50`; grep `zero_rated|pwd|official_receipt` → 0.
- Verdict: **enhance**.

#### [REC-09] Opening-balance + master-data migration toolkit
- Bucket: migration
- Module/chain: cross-cutting — pilot go-live.
- Why it matters: a real Ogami cutover needs opening GL balances (with TB match), employee/customer/vendor/item/BOM/COA import, and a parallel-run plan. None exists.
- Proposal: import pipelines (CSV → staging → validate → commit) with dry-run + reconciliation report + variance threshold + rollback; opening-balance JE generator that must net to a provided TB; documented cutover + parallel-run checklist. (Distinct from the cut "import center with mapping/preview" — this is balances, not a UI builder.)
- Dependencies: REC-01 (post opening balances into an open period).
- Effort: L-XL. Priority: **P0 for pilot** (P2 for pure thesis demo).
- Evidence: grep `opening_balance|DataMigration` → 0.
- Verdict: **build**.

#### [REC-10] Realistic demo dataset (the highest demo-leverage item)
- Bucket: cross-cutting (demo)
- Why it matters: 5 employees, 5 perfect attendance days, 1 payroll cycle, 3 NCRs, no history — every Pareto/forecast/payroll-scale screen looks empty on defense day, undercutting otherwise-real features.
- Proposal: deterministic generator — 200+ employees with realistic Filipino names + departments; 12 months of biometric punches with realistic late/OT/absent/leave distributions; ≥6 finalized payroll cycles; 12-month SO/PO/GRN/invoice/payment streams with value distribution; ≥40 NCRs across defect types for a real Pareto; demand-forecast history. Stop `ComprehensiveDemoSeeder` from truncating-and-shrinking richer seeds.
- Dependencies: depends on REC-03 (so seeded payroll is correct).
- Effort: M (3-5d). Priority: **P0 (demo)**.
- Evidence: `DemoDataSeeder.php:44`; `ComprehensiveDemoSeeder.php:43-101`.
- Verdict: **rewrite the demo-seed layer**.

### P1 — Real-world usable (else users revert to Excel within a month)

#### [REC-11] Payroll real-world correctness: finalized-run void, mid-cycle salary proration, biometric raw-punch import
- Bucket: missing-feature + failure-mode (merged — Payroll robustness)
- Why it matters: Maria can't void a mis-finalized run; a mid-period raise pays the wrong rate; a real ZKTeco export won't import.
- Proposal: add a `Voided` payroll state with reversing GL + re-run; consult a salary-effective-date history for proration; add a punch-event sessionizer (pair raw IN/OUT into day records, handle offline backlog + duplicate punches with explicit merge, block import into a finalized period).
- Effort: L. Priority: **P1**.
- Evidence: `PayrollPeriodStatus.php:9-13`; `PayrollCalculatorService.php:314-321`; `DTRImportService.php:35,72-83`.
- Verdict: **enhance**.

#### [REC-12] Inventory control depth: lot/serial traceability, adjustment reason codes + variance approval
- Bucket: missing-feature (IATF traceability)
- Why it matters: IATF resin lot→WO→finished-part trace can't be reconstructed on the procurement side; write-offs are unaudited free text.
- Proposal: lot/batch capture at GRN + lot ledger through issue/output; reason-code enum on adjustments + value-threshold approval (pairs with REC-02).
- Effort: L. Priority: **P1**.
- Evidence: `GrnItem`/`StockMovement` no lot column; `StockAdjustmentService.php:17-49`.
- Verdict: **enhance**.

#### [REC-13] Approval delegation + true org-hierarchy routing
- Bucket: missing-feature
- Why it matters: a 3-day approver absence auto-**rejects** the queue; any dept-head-role user can approve any department's PR; `is_urgent` self-bypass.
- Proposal: delegation/acting-for table with date ranges; route to the requester's actual department head via org hierarchy; gate `is_urgent` skip behind a value cap or approval; change auto-resolve default from reject to escalate.
- Effort: M. Priority: **P1**.
- Evidence: `ApprovalService.php:146-149`; `ApprovalEscalationService.php:17`; `PurchaseRequestService.php:190,232-253`.
- Verdict: **enhance**.

#### [REC-14] AR/AP credit-debit memos + bill-payment approval + PO amendment & over-receipt tolerance
- Bucket: missing-feature (merged — P2P/O2C document completeness)
- Why it matters: returns/disputes have no accounting instrument; bills pay with no approval; a 1002kg delivery on a 1000kg PO can't be received; an approved PO can't be revised.
- Proposal: credit/debit memo documents (AR + AP) with GL linkage; invoke the seeded `bill_payment` workflow in `recordPayment`; PO amendment with version trail; configurable over-receipt %.
- Effort: L. Priority: **P1**.
- Evidence: grep `credit_memo` → 0; `BillService.php:257-318`; `PurchaseOrderService.php:210`; `GrnService.php:101-106`.
- Verdict: **enhance**.

#### [REC-15] Multi-level BOM explosion + WO/machine conflict + MRP stuck-run reaper
- Bucket: schema + failure-mode (merged — planning correctness)
- Why it matters: sub-assembly products under-order raw resin; two WOs can be confirmed onto one machine; a killed MRP run leaves a permanent `Running` row.
- Proposal: recursive BOM explosion to raw materials; machine-occupancy check in `WorkOrderService::confirm`; a heartbeat/reaper that marks stale `Running` MRP runs `Failed` and cancels their orphan draft PRs.
- Effort: M-L. Priority: **P1**.
- Evidence: `BomService.php:104-119`; `WorkOrderService.php:194-196`; `MrpEngineService.php:280-358`.
- Verdict: **enhance**.

#### [REC-16] Notification email channel + aging reports/exports + inventory turnover + calibration register
- Bucket: missing-feature + reporting (merged — operational completeness)
- Why it matters: the central notifier never emails despite a channel matrix; AR/AP aging is trapped in a dashboard; inventory turnover is missing; IATF calibration register is absent.
- Proposal: dispatch email in `NotificationService::send` per channel pref + digest; standalone aging endpoints + PDF/Excel export; inventory-turnover report; calibration register (gauge, last/next cal date, status, cron alerts) reusing the training-expiry pattern.
- Effort: M-L. Priority: **P1**.
- Evidence: `NotificationService.php:21`; `InvoiceService.php:302`/`BillService.php:328`; grep `turnover`/`calibrat` → 0.
- Verdict: **enhance**.

### P2 — Competitive / differentiation

#### [REC-17] Multi-currency + JPY consolidation pack for the JP parent
- Bucket: missing-module
- Why it matters: the JP-parent differentiator is unproven without currency translation; a monthly JPY TB pack would be a standout demo moment.
- Proposal: `currency` + `exchange_rate` on JE/invoice/bill; FX-rate table; period-end translation (current-rate) to a JPY trial-balance/consolidation pack; optional Japanese i18n toggle.
- Effort: XL. Priority: **P2** (high differentiation, large effort).
- Evidence: grep `currency_code|fx_rate|jpy` → 0.
- Verdict: **build**.

#### [REC-18] Fix DRIFT-2 (audit prune vs immutability) + scheduled backup + restore drill
- Bucket: cross-cutting/security
- Why it matters: an "immutable" audit trail with a cron deletion job is a contradiction that fails on PG and silently deletes on SQLite; 24h RPO with no tested restore.
- Proposal: replace `audit:prune` delete with cold-archive-then-detach (or remove it and keep append-only); scheduled `pg_dump` cron + a documented, executed restore drill.
- Effort: S-M. Priority: **P2**.
- Evidence: `PruneAuditLogs.php:26`; `console.php:143`; `Makefile:79-104`.
- Verdict: **enhance**.

---

## 6. Rebuild-vs-Enhance Verdicts (per module)

| Module | Verdict | Justification |
|--------|:-------:|---------------|
| Quality (IATF spine) | **Keep** | Best feature in the system; AQL Z1.4 + actual measurements + auto-NCR + auto-CoC are real. Only targeted fixes (REC-05 incoming, in-process trigger, REC-08 CoC print). |
| Accounting / GL | **Enhance** | Sound double-entry + JE/Invoice/Bill services; needs period lock (REC-01), maker-checker (REC-02), memos (REC-14). No rewrite. |
| Payroll | **Enhance** | Engine (semi-monthly, gov, loans, 13th, adjustments) is good; fix REC-03/REC-11 correctness. |
| Localization output | **Rewrite (output layer only)** | Forms are demo-grade; generators must be rebuilt to filing-grade (REC-07/08). Computation engines kept. |
| Inventory | **Enhance** | WAC + stock card + cycle count solid; add multi-UOM (REC-04), lot trace (REC-12). |
| Purchasing | **Enhance** | Approval + 3-way match present; fix cancelled-PO bill (REC-06), amendment + over-receipt (REC-14). |
| CRM / O2C | **Enhance** | Credit limit + price agreements real; add hold, multi-level BOM (REC-15), memo (REC-14). |
| Approvals (Common) | **Enhance** | Engine works; add delegation + hierarchy routing (REC-13). |
| Demo-seed layer | **Rewrite** | Truncate-and-shrink pattern actively harmful; replace with volume generator (REC-10). |
| Migration tooling | **Build** | Does not exist (REC-09). |
| B2B, Assets, Maintenance, Edge, Forecasting, ReturnManagement, Dashboard | **Keep** | All real, none stubbed. Assets needs tests; B2B bearer-vs-cookie is a documented, scoped exception. |

---

## 7. Sequencing — 6-Month Roadmap

| Month | Theme | Headline deliverables (REC) | Demo-able outcome |
|:-----:|-------|-----------------------------|-------------------|
| **M1** | Financial-integrity + correctness foundation | REC-01 period lock, REC-02 JE maker-checker, REC-03 daily-leave pay, REC-06 money-path bundle | "You cannot back-date a closed month; you cannot post your own JE; payroll pays leave correctly; you can't bill a cancelled PO." |
| **M2** | Chain 1 O2C credibility | REC-08 BIR invoice + OR + CoC fix, REC-15 multi-level BOM + WO conflict, REC-14 (AR memo slice) | Confirm SO → produce → AQL inspect → ship with a real CoC → issue a BIR-compliant invoice → credit-memo a return. |
| **M3** | Chain 2 P2P depth | REC-04 multi-UOM, REC-05 incoming resin QC + quarantine, REC-12 lot trace, REC-14 (AP memo + bill approval + PO amend) | Receive resin in bags / issue in kg, inspect with moisture+COA, trace lot→WO→part, approve a bill. |
| **M4** | Chain 3 H2R + statutory payroll | REC-07 filing-grade BIR/SSS/PhilHealth/Pag-IBIG, REC-11 void + proration + raw-punch import | Import a real biometric export, run 200-employee payroll, void+re-run, generate 2316/Alphalist/1601-C/R-3. |
| **M5** | Reporting, migration, demo realism | REC-09 migration toolkit, REC-10 realistic dataset, REC-16 email + aging + turnover + calibration | Live Pareto/forecast/aging on 12 months of data; import opening balances; calibration register for IATF. |
| **M6** | Differentiation + hardening + defense | REC-17 JPY consolidation, REC-18 backup/restore + audit-prune fix, REC-13 delegation, pilot dry-run | JPY consolidation pack for the parent; tested restore; smooth full-chain defense walkthrough. |

---

## 8. Time-Boxed Lists

**If you only had 2 weeks** (max leverage): REC-03 (daily-leave pay, S) → REC-10 (realistic dataset, M) → REC-01 + REC-02 (period lock + JE maker-checker, M) → REC-06 (money-path bundle, M) → REC-08 CoC "PASSED" fix slice + invoice ATP/serial (S-M). These convert the largest trust/demo risks at the lowest cost.

**If you had 6 months:** the table in §7 — integrity first, then each chain to filing/IATF grade, then migration + realism, then JP differentiation and pilot hardening.

---

## 9. What I Would NOT Add (scope discipline)

1. **Cost accounting / job costing** — CLAUDE.md cut; standard cost + WAC + the BOM already give the thesis its costing story. Adding process costing is months of work for marginal defense value.
2. **A drag-and-drop report/dashboard builder** — the existing `ExportColumnRegistry` + scheduled exports already cover ad-hoc needs; a builder is a product, not a thesis differentiator, and CLAUDE.md cut it.
3. **EDI integration with Toyota/Honda** — real in industry, but a multi-month integration with external trading partners is out of reach for a pilot; the CoC + delivery flow demonstrates the concept without it.
4. **Swapping the hand-rolled global search for Meilisearch now** — the `ILIKE` search works for pilot volumes; wiring Scout is a nice-to-have, not a blocker. Fix the *doc claim* (DRIFT-1), not the implementation, for now.
5. **Generic e-signature / contract-lifecycle module** — approval signatures + audit trail already cover the IATF/finance sign-off need; a full CLM is scope creep an advisor might suggest but the chains don't require.

---

## 10. Coverage Statement

**Read in full or substantially:** CLAUDE.md, README.md, `docs/` index (SCHEMA, SEEDS, PATTERNS headings, DEPLOY); stack manifests (`composer.json`, `package.json`); routing (`api/routes/console.php`, module `routes.php` samples); and — via eight parallel subagents with `path:line` evidence — the three chains (CRM/MRP/Production/Quality/SupplyChain/Accounting services), Payroll + Attendance + Leave + Loans + HR, all PH/JP localization surfaces (BIR/SSS/PhilHealth/Pag-IBIG/DOLE/VAT exporters + Blade templates), security/SoD/audit/period-close, failure-modes + Laravel/React anti-patterns (grep-swept), demo/seed/PDF readiness, and the peripheral modules (B2B, Assets, Forecasting, ReturnManagement, Maintenance, Edge, Dashboard, ESS). Key P0 claims (single-level BOM, JE maker-checker absence, multi-UOM absence, fiscal-period-lock absence) were re-verified directly against source after the subagent pass.

**Sampled, not exhaustively read:** the full 180-migration set (sampled hot tables: invoices `0048`, stock levels `0056`, budgeting `0162`, audit immutability trigger, recent doc-control `0194-0196`); the full 151-test suite (counted + spot-checked, not read line-by-line); every one of 123 controllers (3 fattest measured, 5 FormRequests spot-checked for `authorize()`); the complete SPA page tree (Sidebar cross-checked against routes for dead links; 3 list pages checked for state handling).

**Deliberately not re-derived:** the prior-dated `docs/REBUILD-AUDIT*.md` files (per discovery rules, findings were re-derived fresh from code, not inherited; this file overwrites the prior report).

**What would sharpen this audit:** (1) running the seed + a payroll cycle in a live container to confirm the daily-leave-pay and gov-table-staleness defects empirically rather than by static read; (2) a real ZKTeco/biometric export sample to confirm the importer-format gap; (3) confirmation from Ogami whether Toyota/Honda sales are PEZA zero-rated (drives REC-08 priority) and whether the JP parent actually requires JPY consolidation (drives REC-17). These three checks would move several P1s to confirmed-P0 or down to P2.
