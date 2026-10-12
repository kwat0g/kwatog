# PDF Exports — Findings (honesty log)

Append-only. If a field doesn't exist, a process is incomplete, or an export is broken beyond safe fix, record it here rather than papering over it.

## 2026-10-12 (Phase 0/1, code-read only — no visual pass yet)
- F-01 (D2): statements (PDF-05/06/07), BIR-2307 (PDF-04), audit-log (PDF-22), quality-policy (PDF-24), HR certs (PDF-19/20/21) bypass `DocumentVaultService` — no `documents` audit row. Cannot be called "audited" until Phase 3/4 routes them through the vault or documents the exception.
- F-02 (D1): `ImpexDocumentService.php:51,80` hardcodes `isDraft=true`. Unknown whether final Impex documents exist as a business state. Awaiting owner answer; do not design a "final" stamp until then.
- F-03 (D4): `BulkPdfService` docblock lists `employee-loan|cash-advance|clearance` with no Blade/route/whitelist; `purchase_request` has a Blade but is rejected by `Rule::in`. Treat docblock as aspirational, not as scope.
- F-04: `DocumentType::Ncr|WorkOrderTraveler|PayrollRegister|SssR3|PhilHealthRf1|PagIbigRemittance|Bir1601c` have enum cases but no PDF route/Blade. Statutory outputs are CSV/XLSX by design; NCR/WO-traveler/register have no output at all.
- F-05: no Japanese/CJK text found in seeds/templates; CJK font need UNVERIFIED against production data. Do not bundle CJK fonts speculatively (memory + file size).
- F-06: company identity settings seed empty-from-env; production values for legal name/TIN/address/phone/email/VAT/logo/certification/disclaimer NOT verified in this environment. Nothing invented; checklist in `00-discovery.md §E`.
- F-07: BIR/statutory layouts (PDF-04, PDF-21, CSV/XLSX siblings) CANNOT be declared compliant here — flagged for accountant/HR/legal verification per mission rule 7.
- F-08 (stale audit): `audit/purchasing.md:28` claim re PO `show`/`pdf` authorization is outdated; current `PurchaseOrderController.php:157` enforces `canView`. Left recorded so nobody re-files it.

## 2026-10-12 (Phase 2 foundation)
- F-09: pixel visual pass NOT done in this PR — no rasterizer on the box (no pdftotext/pdftoppm in the api image). Render verified by bytes (`%PDF`, metadata markers, 4.4KB/0.16s for `pdf.demo`) + Blade HTML assertions. Per-export Phase 7 owns the look-at-every-page gate with `screenshots/`.
- F-10: `_bulk` inner docs inherit company/generator context via Blade `@include` scope (verified by reading the compiler behavior, not by a bulk render test — bulk render needs seeded PO/bill fixtures; covered when PDF-23 gets its per-export PR).
- F-11: no signature-image storage exists in the system — signature blocks stay typed-names + wet-lines. Phase 6 "signature images" needs a prior upload/storage decision; not built here.
- F-12 (settings honesty): dev-DB audit 2026-10-12 showed `legal_name/address/tin/phone/email/logo/public_url/certification/disclaimer` all empty. Seeded ONLY repo-evidenced defaults (name per CLAUDE.md, address per migration 0442, disclaimer per 0405, accent per Atelier tokens). NOT seeded — no evidence: TIN, phone, email, logo, public URL, certification, tagline copy, terms copy, CoC manager. Env trap documented in `02-design-system.md`.
