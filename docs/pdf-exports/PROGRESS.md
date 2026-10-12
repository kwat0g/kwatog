# PDF Exports — Progress (single source of truth)

Mission: every PDF in OGAMI ERP to professional standard, accuracy first (`pdf.md`).
Rule: no template touched until Phase 0 + Phase 1 exist (done 2026-10-12). One PR per unit of work; no direct commits to main.

## Done
- [x] Phase 0 discovery → `docs/pdf-exports/00-discovery.md` (25 serving paths, Blade inventory, CSV/print beside-PDFs, missing-PDF track, foundation facts, 11 code-read defects D1–D11)
- [x] Phase 1 inventory → `docs/pdf-exports/01-inventory.md` (PDF-01…PDF-25, families T/R/S/Q, priority/risk/grade, 3 coverage matrices)
- [x] Phase 2 foundation → `docs/pdf-exports/02-design-system.md` (branch `feat/pdf-foundation-phase2`): Atelier re-skin of `_layout`/letterhead/footer/watermark, 8 new partials (doc_head, party, totals, notes, filter_summary, empty_state, control_block, kpi_strip), `PdfRenderService::brandContext` (logo data-URI, hex-gated accent, tagline, terms) + PDF metadata on every render, 3 branding settings (migration `2026_10_12_120000` + seeder + `.env.example`). `PdfFoundationTest` 10/10, neighbours (vault/bulk/docs/hardening) 26/26 green. Pixel visual pass deferred to per-export Phase 7.

## Pending (in Phase 2 order)
- [ ] Phase 3/4 shared services: single-source queries, export-options dialog contract, reconciliation tests, audit-logging for direct PDFs, rate limits — own PR(s)
- [ ] Per-export rebuilds, externally-facing first: PDF-15 (CoC) → PDF-03/12 (invoices) → PDF-09/10 (POs) → PDF-13/14 (Impex, needs D1 decision) → PDF-04/21 (BIR, accountant-gated) → PDF-17/18 (payslips) → PDF-05/06/07 (statements) → PDF-08 → PDF-01/02 → PDF-16 → PDF-22/23/25 → PDF-19/20 → PDF-11 → PDF-24 → PDF-24-last (public policy, lowest custody risk)
- [ ] Phase 7 per export: seeded edge cases, rasterized visual pass (`screenshots/`), `pdftotext` assertions, reconciliation + permission + fidelity tests, DoD checklist

## Blocked / needs owner decision
- D1: Impex `isDraft=true` always — are packing list / commercial invoice internal drafts or should a final state exist?
- BIR/statutory formats (PDF-04, PDF-21, CSV alphalist/1601c/RF1/MCRF/1604cf): implement code-as-is, then accountant/HR/legal verification (no compliance claim).
- Missing-PDF recommendations (sales-order, delivery-receipt, GRN, NCR, WO-traveler, maintenance WO, inventory reports, clearance, dashboard snapshot): separate track, does not compete with polish deadline.
- Company data to enter: legal name, FCIE address, TIN, phone, email, VAT status, logo local path, certification line, footer disclaimer, CoC manager name/role, quality-policy bodies/objectives/effective date (`00-discovery.md §E`).

## New findings (append-only)
- 2026-10-12: `audit/purchasing.md:28` (PO show/pdf lack canView) is STALE — `PurchaseOrderController.php:157` enforces it. Do not re-file.
- 2026-10-12: only `window.print()` is stock-card; no `@media print` sheets; no `ExportButton` component (ColumnSelectorModal + exportsApi is the pattern).

## Coverage ledger (every discovered export redesigned or deferred with reason)
| ID | Status | Disposition |
|---|---|---|
| PDF-01…PDF-25 | inventoried, not yet rebuilt | pending per-export PRs above |
| pdf.demo | inventoried | deferred (dev fixture, no route) |
| enum-only Ncr/WorkOrderTraveler/PayrollRegister/SssR3/PhilHealthRf1/PagIbigRemittance/Bir1601c | inventoried | deferred to missing-PDF track (no route/Blade; statutory CSV/XLSX by design) |
| docblock-only employee-loan/cash-advance/clearance | inventoried | deferred (no Blade/route; D4 documents the drift) |

## How to resume
Read `pdf.md` → `00-discovery.md` → `01-inventory.md` → this file. Then continue at the first unchecked box.
