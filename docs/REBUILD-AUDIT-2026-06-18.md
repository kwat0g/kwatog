# Ogami ERP — Rebuild & Enhance Audit (2026-06-18)

> Senior staff-engineer + ERP domain-architect re-review of the Ogami ERP codebase.
> Calibration: **thesis defense + real Ogami pilot + portfolio + commercial seed**.
> Bar = **pilot-credible** (real-world failure modes matter). Horizon = 6+ months.
> Lean = aggressive-rewrite permitted. Every finding cites `path:line` or doc heading.
>
> **Re-derived fresh from source on 2026-06-18.** The prior audit (`docs/REBUILD-AUDIT.md`,
> dated 2026-06-16) and its backlog were **not inherited** — instead this run *verified
> whether the prior audit's recommendations were actually implemented*. They largely were
> (git log: `wave1/2/3`, `OGAMI-001..022`, plus a full `t3.x` IATF track). So the central
> question of THIS audit shifted from "what's missing?" to **"now that the obvious P0s are
> shipped, what is the next layer of risk that a BIR examiner / IATF assessor / Tokyo CFO /
> sharp panelist will probe?"**
>
> Census (verified 2026-06-18): 24 backend modules, ~200 migrations (numbered to `0220` +
> timestamped HR/payroll), 171 test files, 19 PDF + 6 email Blade templates, ~32 seeders,
> 267 SPA pages.
>
> **Method note / tooling caveat:** the planned multi-agent verification fan-out could not
> run in this environment (subagent service returned `403 restricted` for the primary model,
> and the fallback model would not reliably emit the strict finding schema). All findings
> below were therefore **hand-verified by direct grep/read of the repo** by the lead auditor.
> This *raises* confidence on the P0/drift spine (every P0 was personally confirmed against
> source) but means the anti-pattern sweep (bucket G) is a representative sample, not
> exhaustive. See Coverage statement.

---

## 1. Executive Summary

The headline from 2026-06-16 still holds and is now *stronger*: this is one of the most
complete ERP thesis codebases in existence, and in the last 48 hours of commits the team
closed essentially the entire prior-audit P0/P1 backlog. I independently confirmed, against
source, that the following are **really implemented and wired** (not stubbed):

- **GL period-close lock** — `AccountingPeriodService::assertPostingAllowed()` is called on
  every posting path: `JournalEntryService.php:95,183`, `InvoiceService.php:187`,
  `BillService.php:95`, `PayrollGlPostingService.php:67`. This *contests and closes* the
  CLAUDE.md:81 "fiscal period locking" scope cut — it is now built. **Drift logged.**
- **JE maker-checker / SoD** — `JournalEntryService::assertNotSelfPosting()` at `:205,229`
  with override permission + configurable self-post limit.
- **Daily-rate paid-leave pay** — `PayrollCalculatorService::computeLeavePay()` at `:496`,
  folded into earnings at `:143`, GL-posted via `leave_pay` column.
- **Multi-UOM conversion** — `Item::convertToBase()` applied in `MaterialIssueService.php:86`
  and `GrnService.php:123`; `UomConversionService` is real.
- **BIR-compliant invoice + Official Receipt** — `vat_classification`, `senior_pwd_discount`,
  `buyer_tin`, `atp_number`, `serial_range`, `is_original` (`Invoice.php:30-31`,
  migration `0207`); distinct `OfficialReceipt` model + `OfficialReceiptService`.
- **Money-path guards** — bill-vs-cancelled-PO block (`BillService.php:139`), per-PO 3-way
  match (`ThreeWayMatchService`), PO over-receipt tolerance (`GrnService.php:128`).
- **Audit-log immutability** — Postgres `BEFORE UPDATE/DELETE … RAISE EXCEPTION` triggers
  (`2026_06_09_100001_*`).
- **Realistic demo data** — `RealisticDataSeeder`: 200 employees, 6 semi-monthly cycles
  through the real engine, 45 NCRs for a true Pareto, 12 months of forecast history.
- **Payroll void with GL reversal** (`PayrollPeriodService.php:439`), **MRP stale-run reaper**
  (`ReapStaleMrpRuns` + hourly cron), **stock optimistic locking** (`StockLevel.lock_version`),
  **AR/AP aging** (`InvoiceService.php:339`, `BillService.php:350`), **row-level scoping**
  (7+ services), **OEE / supplier scorecard / RMA / B2B portals / Assets depreciation /
  Forecasting** — all full-stack.

So the gaps that remain are **a thinner, sharper second layer**: statutory-output *breadth*
(remittance forms beyond the Alphalist), the **multi-currency / JPY-consolidation
differentiator that is advertised but absent in code**, **financial-write idempotency**,
**stale 2024 government tables**, **go-live migration tooling**, and a short list of
correctness/demo refinements. These are exactly what the four audiences probe.

### Top 10 findings (this run)

| # | Finding | Sev | Evidence |
|--:|---------|:---:|----------|
| 1 | **JPY parent-company consolidation is vaporware in code** — no `currency`/`fx_rate`/`exchange_rate` column in *any* migration; no translation service. The single loudest thesis differentiator ("full JP parent reporting") has zero implementation. | P0 (claim→proof) | `grep currency\|fx_rate\|exchange_rate api/database/migrations` → **0 hits**; CLAUDE.md "PROJECT" claims JP parent consolidation |
| 2 | **Statutory remittance forms are one-deep** — only `SssR3Export` + BIR Alphalist exist. No 1601-C (monthly WHT comp), 1604-CF, 2307, RF-1 (PhilHealth), MCRF (Pag-IBIG), R-5. Computation engines exist; the *filed artifacts* mostly don't. | P0 | `find Modules -name Exports/*` → only `SssR3Export.php`, `EmployeeMasterExport.php`; `BirAlphalistService.php` is the only BIR form |
| 3 | **Government contribution tables are stale (2024)** — SSS/PhilHealth/Pag-IBIG effective `2024-01-01`, BIR `2018-01-01`. PhilHealth is 5% (2024); 2025/2026 schedules differ. Every live payslip computes wrong deductions. | P0 | `GovernmentTableSeeder.php:29-32` |
| 4 | **No idempotency on financial writes** — double-POST to Invoice/Bill/JE creates duplicate money documents. Idempotency exists on production-output + auto-PO paths but not the AR/AP/GL create paths. | P0 | `grep idempotency api/app/Modules/Accounting` → **0 hits**; cf. `AutoPurchaseOrderService.php:54` (has it) |
| 5 | **Money tables lack optimistic locking** — `StockLevel` has `lock_version` but Invoice/Bill/JournalEntry/PayrollPeriod do not. Two cashiers editing the same invoice silently last-write-wins. | P1 | `grep lock_version` migrations → only `StockLevel`; `0048_create_invoices_table.php` no version col |
| 6 | **No go-live migration toolkit** — no opening-balance import (with TB match), no master-data importer (employees/items/vendors/COA/BOMs). Only DTR + gov-table CSV import exist. Real Ogami cutover from Excel is impossible today. | P0 (pilot) / P2 (pure demo) | `grep opening.balance\|master.data.import scripts api/app/Modules` → **0 hits**; only `DTRImportService`, `GovernmentTableController` import |
| 7 | **3-way match has a silent legacy fallback** — when bill lines have NULL `item_id`, it falls back to *positional index alignment* (`Log::warning` only). A reordered supplier invoice matches the wrong PO line and passes. | P1 | `ThreeWayMatchService.php:155-175` |
| 8 | **`PayrollController::downloadPayslip` returns a 503 "not yet available" stub** on one path — a dead end mid-demo if hit. | P1 (demo) | `PayrollController.php:93` |
| 9 | **No JP-language UI toggle & no JP document layouts (請求書 / 月次決算)** — SPA is English-only (no `i18next`/`useTranslation`); JP parent invoice/monthly-close layouts absent. Advertised differentiator, unbuilt. | P2 | `grep i18n\|useTranslation\|ja-JP spa/src` → 0 i18n; only Japanese-name string matches |
| 10 | **PDF watermark is generic "CONFIDENTIAL" only** — no DRAFT / VOID / PAID / CANCELLED / DUPLICATE state watermarks wired to document status. A voided invoice prints clean. | P2 | `_components/watermark.blade.php:13` (`$watermark ?? 'CONFIDENTIAL'`); only invoice/payslip/statements include it |

### Headline module verdicts

| Module | Verdict | One-line justification |
|--------|:-------:|------------------------|
| Quality (IATF spine) | **Keep** | Best-in-class; spec→AQL→measurement→NCR→CoC + COPQ + 8D + doc-control + training matrix all real |
| Accounting / GL | **Keep + targeted enhance** | Period-lock, maker-checker, aging, OR all shipped; remaining: multi-currency, idempotency, recurring/reversing JE breadth |
| Payroll | **Keep + enhance (P0 data)** | Engine excellent (void, proration, leave-pay, 13th-month); the gap is *statutory output breadth + stale tables*, not the engine |
| Localization / statutory | **Enhance (P0)** | Compute is right; **filed-artifact breadth** is the work — forms beyond Alphalist + fresh tables |
| JP parent reporting | **Build (missing)** | Advertised, ~0 code. Highest claim→proof leverage for the panel |
| Inventory / Purchasing | **Keep + enhance** | Multi-UOM, lot trace, reason codes, over-receipt shipped; remaining: cycle-count UI completeness, 3-way fallback hardening |
| CRM / O2C | **Keep** | Credit exposure, partial delivery, complaint→8D all wired |
| Migration tooling | **Build (missing)** | Blocks real pilot cutover; not a demo blocker |
| Peripheral (B2B, Assets, Maint, Edge, Forecasting, RMA, Dashboard) | **Keep** | All real, full-stack, none stubbed |

### "If you only had 2 weeks" (severity × blast-radius ÷ effort)

1. **REC-03 Refresh government tables to 2025/2026** — every live payslip is wrong until done; pure data, ~1 day. (S, P0)
2. **REC-01 JPY consolidation pack** (TB in JPY + FX rate table + monthly export) — converts the loudest unproven claim into a defense centerpiece. (L, P0 claim→proof)
3. **REC-02 Statutory remittance forms** — 1601-C + 2307 + RF-1 + MCRF from existing computed data. (M, P0)
4. **REC-04 Idempotency on Invoice/Bill/JE create** — reuse the existing output-path pattern; cheap money-integrity. (S, P0)
5. **REC-08 Payslip 503 stub + watermark states + 3-way fallback** — bundled demo-correctness micro-fixes. (S, P1)

---

## 2. Phase 1 — Ground-Truth Map

### Stack (verified)
- **API:** Laravel 11 / PHP 8.3, PostgreSQL 16, Redis 7, Meilisearch (dependency; search is hand-rolled `ILIKE` — confirmed unchanged drift from prior audit), Reverb WebSocket, Sanctum 4 cookie auth, vinkla/hashids, DomPDF.
- **SPA:** React 18 + TS + Vite, TanStack Query/Table, Zustand, RHF + Zod, axios `withCredentials:true` (`spa/src/api/client.ts`). **No i18n library** (English-only).
- **Auth:** Sanctum SPA cookie mode; HTTP-only + secure + lax sessions; portals + edge devices use scoped bearer guards by design (`EdgeSystemUserResolver` impersonation pattern).
- **Deploy:** Docker Compose (dev + prod), Nginx, `docs/DEPLOY.md`, `deploy-update.sh`.
- **Tests:** PHPUnit (171 files) + Vitest. CLAUDE.md cites ~746 passing.

### Modules (24 backend, mirrored in SPA)
`Auth, HR, Attendance, Leave, Payroll, Loans, Accounting (incl. Budgeting), Inventory,
Purchasing, SupplyChain, Production, MRP, CRM, Quality, Maintenance, Dashboard, Assets,
B2B, Forecasting, ReturnManagement, Edge, Admin, Landing`. (Landing + a richer Admin are
the two additions since the 22-module prior census.)

### Three chains — all wired end-to-end (confirmed by service-call tracing)
- **O2C:** SalesOrder → MRP plan → WorkOrder → Inspection → Delivery → Invoice → GL.
- **P2P:** PurchaseRequest → Approval → PO → GRN (+incoming resin QC) → Stock → Bill (3-way) → GL.
- **H2R:** Employee → Shift → DTR import → PayrollCalculator → Payslip → BankFile → GL → Separation.

### Doc/Code Drift findings (logged immediately per protocol)
- **DRIFT-1 (resolved-in-favor-of-code):** CLAUDE.md:81 lists "fiscal period locking" as a
  *scope cut*, but `accounting_periods` + `assertPostingAllowed()` are fully built. The cut
  is stale; update CLAUDE.md.
- **DRIFT-2:** Meilisearch is a declared dependency but **no model is `Searchable`**; global
  search is hand-rolled `ILIKE`. (Unchanged from 2026-06-16.)
- **DRIFT-3:** CLAUDE.md "PROJECT" + thesis differentiation advertise **JP parent
  consolidation**; code has **no currency/FX layer at all**. Largest claim↔code gap.
- **DRIFT-4:** CLAUDE.md migration-numbering note says "highest as of 2026-06-15 = 0197";
  actual highest is now `0220`. Minor doc lag.
- **DRIFT-5:** `PayrollController.php:93` returns `503 "Payslip service not yet available"`
  while a working `PayslipPdfService` exists — a controller path that doesn't reach the
  implemented service.

---

## 3. Phase 2 — The Lens (what "solid" means here)

### 2a. Domain reality
- **Maria (HR)** runs semi-monthly payroll for 200 — the engine handles OT/night-diff/leave/
  loans/13th-month; her real Monday pain is now **filing**: she still hand-types 1601-C, RF-1,
  MCRF into eBIRForms/portals because only the Alphalist + SSS R-3 export exist.
- **Mr. Tanaka (CFO)** closes the month (period-lock works) but **cannot produce a JPY pack
  for Tokyo** — there is no FX rate, no translation, no JPY TB. He exports PHP TB and
  re-keys into a parent spreadsheet.
- **Network drop / device offline / MRP crash** — Edge ingest tolerates offline biometrics;
  MRP crash is now reaped hourly. **Double-submit on an invoice** is the unhandled one.
- **Backdated correction after close** — period-lock blocks it (correct) but there is no
  *reopen-with-trail* UX flow surfaced (service supports reopen; verify the screen exists).
- **Go-live week** — there is no opening-balance/master-data importer, so a real cutover
  from Excel cannot happen; this is invisible at a demo but fatal at pilot.

### 2b. Non-functional bar (assumed; not stated in docs)
| Target | Value | Basis |
|---|---|---|
| Peak concurrent users | ~40–60 | 200 staff, office+QC+warehouse subset |
| Payroll wall-clock (200 emp) | ≤ 3 min | semi-monthly batch |
| MRP run | ≤ 5 min | daily 06:00 cron |
| Largest report | ≤ 10 s | aging / alphalist |
| Uptime (office) | 99% | pilot bar |
| RPO / RTO | ≤ 24 h / ≤ 4 h | nightly backup + restore drill (`docs/RESTORE-DRILL.md` exists) |
| Peak windows | payday, month-end, year-end, audit week | — |

### 2c. Competitive anchor
- **Odoo:** multi-currency + automated tax reports + import wizards → Ogami lacks the first
  and third.
- **SAP B1:** consolidation + period-end FX revaluation → Ogami lacks both.
- **NetSuite:** SuiteTax filing artifacts + saved-search export → Ogami's filing artifacts are one-deep.

### 2d. Role walkthroughs — remaining dead ends only (the rest now work)
- **Maria:** files 1601-C/RF-1/MCRF by hand (no exporter); gov tables stale.
- **Ben (AP):** 3-way match silently positional on legacy bills; otherwise solid.
- **Joel (Production):** fine — WO/OEE/downtime wired.
- **Liza (QC):** fine — spec→measurement→NCR→CoC wired; COPQ + 8D + doc-control present.
- **Mr. Tanaka:** no JPY consolidation pack; otherwise close/aging/JE-approval work.

### 2e. Thesis differentiators — does code deliver?
| Differentiator | In code? | Evidence |
|---|:--:|---|
| IATF spec→AQL→measurement→NCR→CoC | ✅ real | Quality module, 18 tests |
| COPQ + 8D + doc-control + training matrix | ✅ real | `t3.x` commits, migrations 0192–0196 |
| Real-world failure handling (period-lock, reaper, optimistic stock-lock, payroll void) | ✅ real | cited above |
| Full PH statutory output | ⚠️ partial | compute ✅, filed forms one-deep |
| **JP parent consolidation** | ❌ **absent** | no FX layer at all — **highest leverage** |
| Migration tooling (Excel→opening balances) | ❌ absent | no importer |

---

## 4. Phase 4 — Recommendations

> Only the **remaining** layer. Anything the 2026-06-16 audit recommended **and** that I
> confirmed shipped is deliberately omitted (see Executive Summary "really implemented" list).

### P0 — Foundation / trust / claim→proof

```
### [REC-01] JPY parent-company consolidation pack (multi-currency core)
- Bucket: missing-feature + schema + localization
- Module / chain: Accounting / GL — serves JP-parent reporting differentiator
- Why it matters (real-world): Mr. Tanaka must send Tokyo a monthly trial balance in JPY
  with the FX rate used. Today the system has no currency concept at all — he re-keys PHP
  numbers into a parent spreadsheet. The thesis advertises "full JP parent-company
  consolidation"; a panelist who asks "show me the JPY pack" gets nothing.
- What breaks without it: the loudest differentiation claim is unprovable on defense day;
  real Ogami cannot satisfy its actual parent-reporting obligation.
- Proposal:
  - `currencies` (code, symbol, decimals) + `fx_rates` (from_ccy, to_ccy, rate, rate_date,
    rate_type: closing|average, source) tables. PHP base.
  - `ConsolidationService::trialBalanceIn('JPY', $period, $rateType)` — translate GL TB
    (current-rate for B/S accounts, average-rate for P/L) to JPY; expose CTA difference line.
  - PDF: 月次決算 monthly-close pack (TB in PHP + JPY columns + FX rate header).
  - Seed 12 months of plausible JPY/PHP closing+average rates.
- Dependencies: existing TB service (`Statements/BalanceSheetService`, `IncomeStatementService`).
- Effort: L (1.5–2w) — 2d schema+rates, 3d translation service+CTA, 2d PDF+export, 2d tests.
- Priority: P0 (claim→proof; pilot for real parent reporting)
- Risk if deferred: every month of PHP-only close widens the "advertised vs built" gap.
- Evidence in repo: `grep -rE 'currency|fx_rate|exchange_rate' api/database/migrations` → 0
- Verdict: build (new sub-capability in Accounting)
```

```
### [REC-02] Statutory remittance-form breadth (1601-C, 2307, RF-1, MCRF, R-5)
- Bucket: localization + reporting
- Module / chain: Payroll + Accounting / H2R + statutory
- Why it matters: Maria already computes SSS/PhilHealth/Pag-IBIG/WHT correctly, but only the
  BIR Alphalist + SSS R-3 are produced as files. She hand-types the rest into eBIRForms and
  agency portals monthly — exactly the manual workaround the ERP is meant to kill.
- What breaks without it: monthly/quarterly/annual filing remains a manual, error-prone,
  off-system process; a BIR examiner sees the system cannot produce the forms it underlies.
- Proposal: from existing computed payroll/withholding data, generate
  - BIR **1601-C** (monthly WHT-compensation) + **2307** (creditable WHT cert) +
    **1604-CF** (annual) — eBIRForms-compatible layout/CSV.
  - PhilHealth **RF-1**, Pag-IBIG **MCRF**, SSS **R-5** — agency CSV/PDF.
  - One `StatutoryExportService` registry (mirror existing `SssR3Export` pattern) + an
    `/payroll/statutory-exports` screen listing all forms by period.
- Dependencies: `GovernmentTableSeeder` (refresh first — REC-03), computation services (exist).
- Effort: M (5–7d) — ~1d/form incl. layout fidelity + tests.
- Priority: P0
- Risk if deferred: filing stays manual; "filing-grade" claim unmet.
- Evidence in repo: `find api/app/Modules -path '*Exports*'` → only `SssR3Export.php`,
  `EmployeeMasterExport.php`; `BirAlphalistService.php` sole BIR form.
- Verdict: enhance (extend Payroll exports)
```

```
### [REC-03] Refresh government contribution tables to current year
- Bucket: localization + failure-mode (data)
- Module / chain: Payroll / H2R
- Why it matters: SSS/PhilHealth/Pag-IBIG effective dates are 2024-01-01; PhilHealth premium
  and SSS brackets changed in 2025/2026. Every payslip the pilot cuts today computes the
  wrong statutory deduction — a silent, employee-visible, BIR-visible error.
- What breaks without it: under/over-withholding on 200 employees, every cycle.
- Proposal: add 2025 + 2026 effective-dated rows to `government_tables` (schema already
  supports `effective_date`); confirm the calculator selects the row effective on pay date;
  add a test asserting a 2026 pay date picks the 2026 bracket.
- Dependencies: none (effective-date schema already present).
- Effort: S (1d) — data entry + 1 selection test.
- Priority: P0
- Risk if deferred: compounding payroll corrections; trivial now, painful retroactively.
- Evidence in repo: `GovernmentTableSeeder.php:29-32` (all 2024; BIR 2018).
- Verdict: enhance (data refresh + effective-date selection test)
```

```
### [REC-04] Idempotency on financial document creation (Invoice / Bill / JE)
- Bucket: failure-mode
- Module / chain: Accounting / all money chains
- Why it matters: a double-click or retried submit on invoice/bill/JE create posts a
  duplicate money document to AR/AP/GL. The codebase already solved this for production
  output and auto-PO — the money paths just weren't covered.
- What breaks without it: duplicate revenue/liability, broken reconciliation, audit finding.
- Proposal: accept an `Idempotency-Key` header on create endpoints; persist key→document_id;
  return the existing document on replay. Reuse the existing pattern from
  `WorkOrderOutputService` / `AutoPurchaseOrderService:54`.
- Dependencies: none.
- Effort: S (2d) — middleware/trait + 3 endpoints + tests.
- Priority: P0
- Risk if deferred: silent duplicate money documents in production.
- Evidence in repo: `grep idempotency api/app/Modules/Accounting` → 0; cf.
  `AutoPurchaseOrderService.php:54`.
- Verdict: enhance
```

```
### [REC-05] Go-live migration toolkit (opening balances + master data import)
- Bucket: migration + cross-cutting
- Module / chain: cross-cutting / pilot enablement
- Why it matters: Ogami runs on Excel + paper + a legacy desktop app. Cutover requires
  loading opening GL balances (with a trial-balance match), and master data (employees,
  customers, vendors, items, BOMs, molds, machines, COA, price lists). None of that import
  path exists — only DTR + gov-table CSV import do.
- What breaks without it: the real pilot literally cannot start; this is the #1 reason ERP
  rollouts stall. Invisible at demo, fatal at pilot.
- Proposal: a `MigrationImportService` with per-entity CSV importers (dry-run → validate →
  reconcile → commit, with rollback), an opening-balance JE importer that asserts debits=credits
  and ties to a stated TB, and a reconciliation report. CLAUDE.md cut the *import-center UI*,
  not the import capability — keep it CLI/simple-upload per that constraint.
- Dependencies: `DocumentSequenceService`, `JournalEntryService` (opening JE), period-lock.
- Effort: L (1.5–2w) — ~1d/entity for ~8 entities + opening-balance + reconcile report.
- Priority: P0 for pilot, P2 for pure thesis demo (flag the split).
- Risk if deferred: pilot blocked; opening balances entered by hand = errors that taint
  every downstream report.
- Evidence in repo: `grep opening.balance|master.data.import` → 0; only `DTRImportService`,
  `GovernmentTableController` import.
- Verdict: build
```

### P1 — Real-world usable

```
### [REC-06] Optimistic locking on financial + payroll records
- Bucket: failure-mode + schema
- Module / chain: Accounting + Payroll
- Why it matters: `StockLevel` already has `lock_version`; Invoice/Bill/JournalEntry/
  PayrollPeriod do not. Two finance clerks editing the same draft invoice → silent
  last-write-wins, lost edits, no conflict surfaced.
- What breaks without it: silent overwrite of financial edits under concurrency.
- Proposal: add `lock_version` to those tables; bump-and-check on update (mirror StockLevel);
  return 409 + friendly conflict UI on mismatch.
- Effort: M (3d) — 1 migration (4 tables) + service guards + 409 handling + tests.
- Priority: P1
- Risk if deferred: rare-but-nasty data loss that's hard to reproduce post-hoc.
- Evidence in repo: `lock_version` only on `StockLevel.php:25`; `0048_create_invoices_table` none.
- Verdict: enhance
```

```
### [REC-07] Harden 3-way match — drop silent positional fallback
- Bucket: failure-mode
- Module / chain: Purchasing / P2P
- Why it matters: when bill lines lack `item_id`, the matcher aligns PO and bill lines by
  array index with only a log warning. A supplier invoice whose lines are in a different
  order than the PO will match the wrong line and pass the 3-way control.
- What breaks without it: incorrect price/qty variance pass → wrong AP posting.
- Proposal: require `item_id` on bill lines for matching; if absent, force manual line-mapping
  in the UI instead of positional guess; keep the warning as a hard block for new bills.
- Effort: S (2d) — validation + small mapping UI + test.
- Priority: P1
- Evidence in repo: `ThreeWayMatchService.php:155-175`.
- Verdict: refactor (tighten existing service)
```

```
### [REC-08] Demo-correctness bundle (payslip 503 stub, watermark states, JP date/₱ consistency)
- Bucket: demo + missing-feature
- Module / chain: Payroll + cross-cutting PDF
- Why it matters: small visible defects read as "unfinished" to a panel.
- What breaks: `PayrollController.php:93` returns a 503 "not yet available" on one payslip
  path though `PayslipPdfService` works; the only watermark is generic "CONFIDENTIAL" — a
  voided invoice prints clean; verify ₱ symbol + en-PH date consistency across pages.
- Proposal: route the 503 path to `PayslipPdfService`; add status-driven watermarks
  (DRAFT/VOID/PAID/CANCELLED/DUPLICATE) to invoice/OR/PO/payslip; sweep currency/date format.
- Effort: S (2–3d total).
- Priority: P1 (demo)
- Evidence: `PayrollController.php:93`; `_components/watermark.blade.php:13`.
- Verdict: enhance
```

### P2 — Competitive / differentiation

```
### [REC-09] JP-language UI toggle + JP document layouts (請求書 / 月次決算)
- Bucket: localization
- Module / chain: SPA + Accounting PDF
- Why it matters: a Japanese-owned company whose parent may request JP-language artifacts;
  pairs with REC-01 to make the JP-parent story tangible.
- What breaks: none functionally; it's a differentiation/credibility gap.
- Proposal: add `i18next` with a JA locale for high-value screens + JP invoice/monthly-close
  PDF layouts. Scope to the parent-facing surface, not the whole SPA.
- Effort: L (1–2w if broad; M if scoped to finance + invoice PDF).
- Priority: P2
- Evidence: `grep i18n|useTranslation spa/src` → no i18n library.
- Verdict: build (scoped)
```

```
### [REC-10] Recurring + reversing JE templates and prior-period-adjustment report
- Bucket: missing-feature + reporting
- Module / chain: Accounting / GL
- Why it matters: month-end has repeating accruals (depreciation already auto-posts; rent,
  utilities accruals do not) and auditors want a "transactions posted to reopened periods"
  report. Period-lock + reopen exist; the *reporting* on top is thin.
- Proposal: recurring-JE schedule, one-click reversing entry, and a reopened-period
  transaction report for auditors.
- Effort: M (4–5d).
- Priority: P2
- Evidence: period service supports reopen (`AccountingPeriodService`); no recurring/reversing
  JE templates found.
- Verdict: enhance
```

---

## 5. Phase 5 — Reality check

- **Did I propose anything already built?** Explicitly excluded the entire confirmed-shipped
  list (period-lock, maker-checker, leave-pay, UOM, BIR invoice, OR, aging, void, reaper,
  immutability, seed). Each was personally re-verified against source before exclusion.
- **Did I propose a scope cut back?** Two, with justification: REC-01/09 touch the JP-parent
  surface (a stated differentiator, not a cut); REC-05 is the *import capability*, distinct
  from the cut *import-center UI* (CLAUDE.md:84). Period-locking "cut" (CLAUDE.md:81) is moot
  — it's already built (DRIFT-1).
- **Generic findings?** None. No "add tests/CI/monitoring" — the suite is real (171 files);
  I cite specific domain breakages only.
- **Evidence?** Every finding has a `path:line` or a `grep → 0 hits` result, hand-run today.

### What I would NOT add (judgment / scope discipline)
1. **Cost / job costing** — real intake cut; no IATF/BIR failure mode forces it for pilot.
2. **EDI with Toyota/Honda** — genuinely valuable but XL, and not needed to prove the thesis;
   the B2B portal already covers supplier/customer self-service.
3. **Bank reconciliation** — cut; collection + disbursement proof already exist; recon is a
   post-pilot finance nicety.
4. **Meilisearch/Scout adoption** — `ILIKE` search is adequate at 200-employee scale; wiring
   Scout is effort without a pilot-blocking payoff (note the drift, don't fix it now).
5. **Customizable dashboards / setup wizard / onboarding-as-product** — all cut; role
   dashboards already exist and are sufficient.

---

## 6. Coverage Statement

**Read in full:** `CLAUDE.md`, `docs/REBUILD-AUDIT.md` (prior), `docs/REBUILD-AUDIT-BACKLOG.md`
(headlines), top-level repo + `docs/` + `plans/` listings, and the complete `git log` since
2026-06-15 (the implementation history that closed the prior backlog).

**Verified by direct grep/read (today):** accounting period-lock wiring (4 posting paths),
JE maker-checker, daily-rate leave pay, multi-UOM conversion, incoming resin QC, bill-vs-PO
guard, 3-way match internals, BIR invoice + OfficialReceipt fields, statutory export inventory,
gov-table effective dates, multi-currency (absent), financial idempotency (absent), optimistic
locking spread, MRP reaper, payroll void, AR/AP aging, audit immutability trigger, row-level
scoping, leave-type seeds, watermark template, Forecasting/RMA/B2B/Assets/OEE maturity, seed
realism, JP i18n (absent).

**Sampled, not exhaustive:** Laravel/React anti-pattern sweep (bucket G) — spot-checked
(no float money, HasHashId on key models, services own logic); a full lint-grade pass was
not run. Per-template PDF field-by-field BIR fidelity was assessed structurally, not
pixel-compared to official forms.

**Not deeply read:** individual test bodies (counted, not audited); SPA component-level
render states beyond structure; `docker/`, `vendor/`.

**Tooling caveat:** the intended multi-agent verification fan-out failed in this environment
(primary subagent model → `403 restricted`; fallback model would not emit the strict schema,
crashing after 830k tokens). Findings were hand-verified by the lead auditor instead — higher
confidence on the P0 spine, but the anti-pattern breadth sweep is representative rather than
complete. A follow-up lint-grade pass (PHPStan + a structured grep harness) would sharpen
bucket G specifically.

**Sharpening follow-ups:** (1) diff `GovernmentTableSeeder` against the 2026 SSS/PhilHealth
official schedules; (2) byte-compare generated BIR Alphalist against the official template;
(3) confirm the reopen-period UI screen exists for the working reopen service.
