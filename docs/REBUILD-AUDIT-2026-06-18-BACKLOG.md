# Ogami ERP — Rebuild Audit Ticket Backlog (2026-06-18)

> Import-ready tickets from `docs/REBUILD-AUDIT-2026-06-18.md`. Numbered `OGAMI-1xx` to avoid
> collision with the 2026-06-16 backlog (`OGAMI-001..022`, now implemented). Each maps to a
> REC card. Labels: `priority/*`, `module/*`, `bucket/*`.
>
> **Context:** the prior backlog (OGAMI-001..022) is shipped (`wave1/2/3` + `t3.x` commits).
> These tickets are the verified *remaining* layer only.

---

## P0 — Foundation / trust / claim→proof

### OGAMI-101 — Refresh government contribution tables to 2025/2026
- **Labels:** `priority/P0` `module/payroll` `bucket/localization` `bucket/failure-mode`
- **Estimate:** S (1d)
- **Why:** SSS/PhilHealth/Pag-IBIG effective `2024-01-01`, BIR `2018-01-01`; every live payslip
  computes the wrong deduction. (`GovernmentTableSeeder.php:29-32`)
- **Done when:** 2025 + 2026 effective-dated rows seeded; calculator selects row by pay date;
  test asserts a 2026 pay date → 2026 bracket; sourced to the official agency circulars.
- **Maps to:** REC-03

### OGAMI-102 — BIR 1601-C + 2307 exporters
- **Labels:** `priority/P0` `module/payroll` `bucket/localization` `bucket/reporting`
- **Estimate:** M (3d)
- **Why:** only Alphalist + SSS R-3 are produced as files; 1601-C/2307 hand-typed monthly.
  (`find Modules -path '*Exports*'` → `SssR3Export` only)
- **Done when:** eBIRForms-compatible 1601-C (monthly WHT-comp) + 2307 (creditable WHT cert)
  generate from computed data; covered by tests; reachable from a statutory-exports screen.
- **Maps to:** REC-02 (slice 1)
- **Blocked by:** OGAMI-101 (forms read tables)

### OGAMI-103 — PhilHealth RF-1 + Pag-IBIG MCRF + SSS R-5 + BIR 1604-CF
- **Labels:** `priority/P0` `module/payroll` `bucket/localization` `bucket/reporting`
- **Estimate:** M (4d)
- **Why:** complete the remittance form set; remove the last manual filing workarounds.
- **Done when:** RF-1, MCRF, R-5, 1604-CF generate as agency-format CSV/PDF; one
  `StatutoryExportService` registry; tests per form.
- **Maps to:** REC-02 (slice 2)
- **Blocked by:** OGAMI-101

### OGAMI-104 — Idempotency-Key on Invoice / Bill / Journal Entry creation
- **Labels:** `priority/P0` `module/accounting` `bucket/failure-mode`
- **Estimate:** S (2d)
- **Why:** double-submit posts duplicate money documents; pattern already exists elsewhere.
  (`grep idempotency api/app/Modules/Accounting` → 0; cf. `AutoPurchaseOrderService.php:54`)
- **Done when:** create endpoints accept `Idempotency-Key`; replay returns the original
  document; tests cover double-POST.
- **Maps to:** REC-04

### OGAMI-105 — Multi-currency core (currencies + fx_rates)
- **Labels:** `priority/P0` `module/accounting` `bucket/schema` `bucket/missing-feature`
- **Estimate:** M (3d)
- **Why:** no currency/FX layer exists; prerequisite for JP-parent reporting.
  (`grep currency|fx_rate|exchange_rate api/database/migrations` → 0)
- **Done when:** `currencies` + `fx_rates` (closing/average, rate_date, source) tables; PHP
  base; 12 months of PHP/JPY rates seeded; model + service to fetch effective rate.
- **Maps to:** REC-01 (slice 1)

### OGAMI-106 — JPY trial-balance translation + CTA
- **Labels:** `priority/P0` `module/accounting` `bucket/missing-feature`
- **Estimate:** M (4d)
- **Why:** Tokyo needs the monthly TB in JPY; today it's re-keyed by hand.
- **Done when:** `ConsolidationService::trialBalanceIn('JPY', period, rateType)` translates GL
  TB (current-rate B/S, average-rate P/L), exposes the CTA line; tested against a worked example.
- **Maps to:** REC-01 (slice 2)
- **Blocked by:** OGAMI-105

### OGAMI-107 — 月次決算 monthly-close pack (PHP + JPY) PDF/export
- **Labels:** `priority/P0` `module/accounting` `bucket/localization` `bucket/reporting`
- **Estimate:** S (2d)
- **Why:** the tangible defense artifact for the JP-parent differentiator.
- **Done when:** one-click PDF + export with PHP + JPY columns and FX-rate header.
- **Maps to:** REC-01 (slice 3)
- **Blocked by:** OGAMI-106

### OGAMI-111 — Master-data import toolkit (per-entity CSV: dry-run→validate→commit→rollback)
- **Labels:** `priority/P0` (pilot) / `priority/P2` (demo) `module/cross-cutting` `bucket/migration`
- **Estimate:** L (1.5w)
- **Why:** no importer for employees/customers/vendors/items/BOMs/molds/machines/COA/prices;
  real Excel→ERP cutover is impossible. (`grep master.data.import` → 0)
- **Done when:** each entity importable with dry-run validation, reconciliation, rollback;
  CLI/simple-upload (respect the import-center-UI cut, CLAUDE.md:84).
- **Maps to:** REC-05 (slice 1)

### OGAMI-112 — Opening-balance JE importer + trial-balance reconciliation report
- **Labels:** `priority/P0` (pilot) `module/accounting` `bucket/migration`
- **Estimate:** M (4d)
- **Why:** opening balances must load and tie to a stated TB before go-live.
- **Done when:** importer posts a balanced opening JE through the period-lock, asserts
  debits=credits, ties to a target TB, emits a variance/reconciliation report.
- **Maps to:** REC-05 (slice 2)
- **Blocked by:** OGAMI-111

---

## P1 — Real-world usable

### OGAMI-108 — Optimistic locking on financial + payroll records
- **Labels:** `priority/P1` `module/accounting` `module/payroll` `bucket/failure-mode` `bucket/schema`
- **Estimate:** M (3d)
- **Why:** `lock_version` only on `StockLevel`; concurrent edits to invoices/bills/JE/payroll
  silently last-write-wins. (`StockLevel.php:25`)
- **Done when:** `lock_version` on those tables; bump-and-check on update; 409 + conflict UI; tests.
- **Maps to:** REC-06

### OGAMI-109 — Harden 3-way match (drop silent positional fallback)
- **Labels:** `priority/P1` `module/purchasing` `bucket/failure-mode`
- **Estimate:** S (2d)
- **Why:** NULL-item_id bills match PO lines by array index with only a log warning; reordered
  invoices match the wrong line. (`ThreeWayMatchService.php:155-175`)
- **Done when:** matching requires `item_id`; otherwise forces manual line mapping; new bills
  cannot use positional alignment; test.
- **Maps to:** REC-07

### OGAMI-110 — Demo-correctness bundle (payslip 503 stub, status watermarks, ₱/date sweep)
- **Labels:** `priority/P1` `module/payroll` `module/cross-cutting` `bucket/demo`
- **Estimate:** S (2-3d)
- **Why:** `PayrollController.php:93` 503s though `PayslipPdfService` works; watermark is
  generic "CONFIDENTIAL" only (`_components/watermark.blade.php:13`); verify ₱/date consistency.
- **Done when:** 503 path routes to the service; status watermarks (DRAFT/VOID/PAID/CANCELLED/
  DUPLICATE) on invoice/OR/PO/payslip; currency/date format consistent across pages.
- **Maps to:** REC-08

---

## P2 — Competitive / differentiation

### OGAMI-113 — JP-language UI toggle (scoped) + JP document layouts (請求書 / 月次決算)
- **Labels:** `priority/P2` `module/spa` `module/accounting` `bucket/localization`
- **Estimate:** M–L (1–2w; M if scoped to finance + invoice PDF)
- **Why:** Japanese-owned firm; pairs with the JPY pack to make the JP-parent story tangible.
  (`grep i18n|useTranslation spa/src` → no i18n library)
- **Done when:** i18next JA locale on finance screens + JP invoice/monthly-close PDF layouts.
- **Maps to:** REC-09
- **Depends on:** OGAMI-105..107 (consolidation) for the monthly-close layout

### OGAMI-114 — Recurring + reversing JE templates + reopened-period audit report
- **Labels:** `priority/P2` `module/accounting` `bucket/missing-feature` `bucket/reporting`
- **Estimate:** M (4-5d)
- **Why:** period reopen exists but reporting on top is thin; month-end accruals are manual.
- **Done when:** recurring-JE schedule, one-click reversing entry, "postings to reopened
  periods" auditor report.
- **Maps to:** REC-10

---

## Dependency summary (critical path)
```
OGAMI-101 ─┬─> OGAMI-102 ─┐
           └─> OGAMI-103 ─┴─> (statutory complete)
OGAMI-104 (independent, money-path safety)
OGAMI-105 ──> OGAMI-106 ──> OGAMI-107 ──> (JP pack)  ──┐
                                                       └─> OGAMI-113 (JP layouts)
OGAMI-111 ──> OGAMI-112 (pilot cutover)
OGAMI-108, OGAMI-109, OGAMI-110 (independent hardening/demo)
OGAMI-114 (independent, GL reporting depth)
```

## Suggested labels for import
`priority/P0` `priority/P1` `priority/P2` · `module/accounting` `module/payroll`
`module/purchasing` `module/cross-cutting` `module/spa` · `bucket/localization`
`bucket/failure-mode` `bucket/schema` `bucket/migration` `bucket/reporting`
`bucket/missing-feature` `bucket/demo`
