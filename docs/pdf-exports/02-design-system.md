# Phase 2 — PDF Design System Guide (Atelier chrome)

One foundation, every document a composition. Never one-off copy-paste templates.

## Tech decision: keep DomPDF (measured 2026-10-12)

- In use: `barryvdh/laravel-dompdf ^3.0` / `dompdf 3.1.6`, hardened
  (`enable_php/js/remote=false`, `chroot=base_path()`, asserted by
  `DompdfHardeningTest`). Only renderer in the codebase; all 25 PDF paths
  already route through it (except the public quality-policy — Phase 3).
- Measured: `pdf.demo` (1-page, letterhead+footer+signatures) renders
  **4,413 bytes in 0.16 s, 61.5 MiB peak** (includes framework boot) on the
  dev container. No memory/time pressure at this size.
- Requirements check: CSS subset covers layout/tables/page-breaks/running
  footers; fonts embedded (DejaVu); `add_info` metadata set. Missing vs
  ideal: no JS charts (use server-side SVG/PNG later), no flexbox/grid
  (appendix: tables only).
- Chromium/Browsershot rejected: no layout need justifies +500 MB RAM and a
  new service on a 4 GB box. Rollback: N/A (no switch).
- Large-report budgets (500-row audit log, bulk-50) are measured per-export
  in Phase 7 with queue+notify thresholds in Phase 3. The 128 MiB boot OOM
  seen in grouped suites is route-registration weight, not renderer cost.

## Tokens (mirror of `spa/src/styles/tokens.css`, print-safe)

| Role | Value | Use |
|---|---|---|
| Ink | `#1f1b16` | body, headings, table text, grand total |
| Secondary | `#4a4239` | values, chip base text |
| Muted | `#6b6259` | labels, meta, footer |
| Canvas tint | `#f7f4ef` | info cards, zebra rows, grand-total bg, filter summary |
| Border | `#e8e2d8` / `#f0eae0` | cards, dividers, row rules |
| Accent (clay) | `#b4542a` (default; `pdf.accent_color` overrides, hex-validated) | brand bar ONLY |
| Success/warn/danger/info | moss `#3f6d54` / ochre `#b07a22` / oxide `#a8392f` / slate `#3d5a80` (+tint bgs) | chips — label text + border carry meaning in B&W |

Type: Helvetica/Arial/DejaVu Sans 9pt body, tabular numerals for ALL money
columns (`.mono`/`.num`, `font-variant-numeric: tabular-nums`). Weights only
`bold`/`normal` (DomPDF maps 400/700 only). A4 default; A5 payslip;
landscape for wide reports. Dates `M d, Y` + timestamps `M d, Y g:i A`
(Asia/Manila). Money `₱1,234.56` formatted from decimal STRINGS (never
float) by the owning service.

## Components (`api/resources/views/pdf/_components/`)

| Partial | Props | Notes |
|---|---|---|
| `letterhead` | `$company`, `$docTitle`, `$generated` | logo (embedded data URI), name, tagline, address/contact/TIN/vat/certification — each hides when empty |
| `doc_head` | `$docNumber`!, `$docStatus`, `$docStatusVariant`, `$docDateLabel`, `$docDate`, `$references[]` | status from the REAL enum value (humanized here, never relabeled upstream) |
| `party` | `$title`, `$name`, `$lines[]`, `$meta[][label,value]`, `$last` | null/empty → `—`, never blank or "null" |
| `totals` | `$rows[][label,value,grand]`, `$amountInWords` | values PRE-FORMATTED by the owning service |
| `notes` | `$notes`, `$notesTitle`, `$company['terms']` | per-doc notes + `pdf.terms_conditions` fallback |
| `filter_summary` | `$filters[]`, `$shown`, `$total` | "Showing N of M" + every active filter |
| `kpi_strip` | `$kpis[][label,value]` (max 4) | headline figures above grouped sections |
| `empty_state` | `$message` | bordered no-rows box, never an empty table |
| `control_block` | `$formNo`, `$revision`, `$effectiveDate` | renders only when control metadata exists |
| `signatures` / `approval_signatures` | `$signatures[]` / `$approvals[]` | blank lines for wet signatures, typed names+dates for acted steps |
| `watermark` | `$watermark` | state-driven stamps: DRAFT/VOID/PAID/REPRINT/CONFIDENTIAL |
| `footer` | `$company`, `$generated`, `$confidential`, `$docId?` | disclaimer, generator+timestamp, "Page X of Y", confidential notice |
| `qr` | `$qrSvg` / `$qrUrl` / `$qrText` | pre-rendered only; DomPDF-safe fallback text |

Layout rules baked into `_layout`: `thead` repeats every page,
`tr` never splits, totals/signatures/notes `page-break-inside: avoid`.

## How to build a new PDF (copy-paste contract)

1. Service computes data with the SAME query/scope as the screen; money as
   decimal strings; status as the raw enum value; approvals as
   `[role,name,signed_at]` rows.
2. Call `PdfRenderService::render($view, $data, [title, paper, orientation,
   confidential, watermark_text, generator])` — never `Pdf::loadView`
   directly (quality-policy is the documented exception being fixed).
3. Blade: `@extends('pdf._layout')`, `@include` letterhead is automatic;
   compose `doc_head` → `party` cards → `table.lines` → `totals`
   (+`amountInWords` via `AmountInWords::peso`) → `notes` → signatures;
   reports add `filter_summary` → `kpi_strip` → grouped `lines` w/ subtotals
   → grand `totals`, or `empty_state` on zero rows.
4. Vault-store via `DocumentVaultService::store` and stream through it
   (statements/2307/audit/HR-certs are the documented exceptions being fixed).
5. Filename: human convention `PO-2026-0042_ACME-Corp_2026-10-12.pdf`
   (Phase 3/4 renames vault naming; keep inline `Content-Disposition`).

## Settings required (branding checklist for the operator)

`company.legal_name, address, tin, phone, email, public_url, vat_status,
logo_path` (local file under `storage/app/`, NOT a URL — remote loading is
off), `certification`, **`company.tagline`** (new), **`pdf.accent_color`**
(new, `#b4542a` default), **`pdf.terms_conditions`** (new),
`pdf.footer_disclaimer`. Migration `2026_10_12_120000` seeds empties;
`SettingsSeeder` + `api/.env.example` (`COMPANY_TAGLINE`,
`PDF_ACCENT_COLOR`, `PDF_TERMS_CONDITIONS`) wire env defaults. Enter real
values before any external PDF ships — nothing is invented.

### Empty-env trap (root cause of the blank letterheads, fixed in migration)

`.env` declares `COMPANY_*`/`PDF_*` as EMPTY strings, and `env($k, $default)`
only falls back when the variable is ABSENT — so plain `env()` defaults never
fired and every fresh install printed a nameless letterhead. The migration
uses an `$envOr` helper (empty env = not set → repo-evidenced default) and
backfills ONLY what the repo evidences (name/address/disclaimer); operator
values are never overwritten (verified: a preset TIN survived migrate,
rollback, and re-migrate on a scratch DB).

## Definition of Done (foundation slice, this PR)

- [x] All chrome on Atelier tokens; B&W-safe chips; tabular numerals
- [x] Logo/tagline/accent/terms from settings, graceful hide, hex-gated accent
- [x] PDF metadata (Title/Author/Subject/Creator/Language) on every render
- [x] 8 new partials + reskinned layout/letterhead/footer/watermark docs
- [x] `PdfFoundationTest` 10/10 + neighbours green (vault/bulk/docs/hardening)
- [ ] Pixel visual pass → per-export Phase 7 (rasterize + `screenshots/`)
- [ ] Per-doc migration to partials → per-export PRs (highest-priority first)
