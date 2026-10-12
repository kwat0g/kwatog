# Phase 1 — Export Inventory & Classification (master table)

Source: `00-discovery.md` (all verified against code 2026-10-12). IDs `PDF-01…PDF-25` are stable; use them in PRs and `PROGRESS.md`.
WYSIWYE rule and accuracy-first rule (prompt §HARD RULES 4–5) govern every row.

## Master table

| ID | Module | Document / report | Trigger (page, button, role) | Endpoint / function | Template | Fam* | Pri | Risk | Grade |
|---|---|---|---|---|---|---|---|---|---|
| PDF-01 | Accounting | Journal Entry voucher | JE detail → Print (any `accounting.journal.view`) | `GET /journal-entries/{id}/pdf` → `PdfService#journalEntry` | `pdf.journal-entry` | T | P2 | M | C |
| PDF-02 | Accounting | Supplier Bill | Bill detail → Print (`accounting.bills.view`) | `GET /bills/{id}/pdf` → `PdfService#bill` | `pdf.bill` | T | P1 | H | C |
| PDF-03 | Accounting | Customer Invoice (internal) | Invoice detail → Print (`accounting.invoices.view`) | `GET /invoices/{id}/pdf` → `PdfService#invoice` | `pdf.invoice` | T | P1 | H | C |
| PDF-04 | Accounting | BIR Form 2307 | Vendor detail → 2307 PDF (`accounting.bills.view`, year+quarter) | `GET /vendors/{id}/bir-2307/pdf` → `PdfService#bir2307` | `pdf.bir-2307` | T† | P1 | H | C |
| PDF-05 | Accounting | Trial Balance | Statements page → PDF (`statements.view`+`statements.export`) | `GET .../trial-balance/pdf?from,to` | `pdf.trial-balance` | R | P1 | H | C |
| PDF-06 | Accounting | Income Statement | same | `GET .../income-statement/pdf?from,to` | `pdf.income-statement` | R | P1 | H | C |
| PDF-07 | Accounting | Balance Sheet | same | `GET .../balance-sheet/pdf?as_of` | `pdf.balance-sheet` | R | P1 | H | C |
| PDF-08 | Purchasing | Purchase Request | PR detail → Print (`purchasing.view`+canView) | `GET /purchasing/purchase-requests/{id}/pdf` | `pdf.purchase-request` | T | P1 | H | B |
| PDF-09 | Purchasing | Purchase Order (internal) | PO detail → Print (`purchasing.view`+canView) | `GET /purchasing/purchase-orders/{id}/pdf` | `pdf.purchase-order` | T | P1 | H | B |
| PDF-10 | B2B | Supplier PO (portal) | Supplier portal PO detail (supplier guard) | `GET /b2b/supplier/purchase-orders/{id}/pdf` | `B2B/Views/supplier-purchase-order` | T | P1 | M | B |
| PDF-11 | B2B | Supplier Bill (portal) | Supplier portal invoice detail | `GET /b2b/supplier/invoices/{id}/pdf` | `B2B/Views/supplier-bill` | T | P2 | M | B |
| PDF-12 | B2B | Customer Invoice (portal) | Customer portal invoice detail | `GET /b2b/customer/invoices/{id}/pdf` | `pdf.customer-invoice` | T | P1 | M | C |
| PDF-13 | Supply Chain | Packing List (DRAFT-only) | Shipment detail → Packing list (impex/wh) | `GET /supply-chain/shipments/{id}/packing-list` | `pdf.packing-list` | T | P1 | M | C |
| PDF-14 | Supply Chain | Commercial Invoice (DRAFT-only) | Shipment detail → Commercial invoice | `GET .../commercial-invoice` | `pdf.commercial-invoice` | T | P1 | H | C |
| PDF-15 | Quality | Certificate of Conformance | Inspection detail → CoC (QC, passed outgoing only) | `GET /quality/inspections/{id}/coc` | `pdf.coc` | Q | P0 | H | B |
| PDF-16 | CRM | 8D Report | Complaint detail → 8D PDF (`crm.complaints.view`, finalized only) | `GET /crm/complaints/{id}/8d/pdf` | `pdf.complaint-8d` | Q | P1 | M | B |
| PDF-17 | Payroll | Payslip (operator) | Payroll detail → Payslip (`payroll.view`+scope+publishable) | `GET /payrolls/{id}/payslip` | `pdf.payslip` A5 | S | P0 | H | B |
| PDF-18 | HR SS | Payslip (self) | Self-service → Payslips → Download (owner only) | `GET /hr/self-service/payslips/{id}/download` | `pdf.payslip` A5 | S | P0 | H | B |
| PDF-19 | HR SS | Employment Certificate | Self-service → Documents → Employment cert | `GET .../employment-certificate?with_salary` | `pdf.employment-certificate` | S | P2 | M | C |
| PDF-20 | HR SS | Contribution Certificate | Self-service → Contributions (sss/phic/pagibig, current yr) | `GET .../contributions/{type}?year` | `pdf.contribution-certificate` | S | P2 | M | C |
| PDF-21 | HR SS | BIR 2316 | Self-service → BIR 2316 (prior yr) | `GET .../bir-2316?year` | `pdf.bir-2316` | S† | P1 | H | C |
| PDF-22 | Admin | Audit Trail Report | Audit logs → Export PDF / entity trail (`admin.audit_logs.view`) | `GET /admin/audit-logs/export/pdf?...` landscape, cap 500 | `pdf.audit-log` | R | P2 | L | C |
| PDF-23 | Admin | Bulk Print (PO/bill/invoice) | List → select ≤50 → bulk print (`admin.print.bulk`) | `POST /print/bulk {type,ids}` | `pdf._bulk` + per-type | T | P2 | M | C |
| PDF-24 | Public | Quality Policy | Landing footer → Download (public, throttled) | `GET /landing/quality-policy` direct `Pdf::loadView` | `pdf.quality-policy` | Q | P1 | L | C |
| PDF-25 | Vault | Stored-document view/download | Document list / PdfPreviewModal (per-type perm + ownership) | `GET /documents/{id}/view|download`, `entity/...` | stored bytes | — | P2 | H‡ | B |

\* Family: T = transactional single-record · R = tabular report · S = sensitive/personal · Q = controlled/quality. No label/tag/barcode outputs exist. † BIR/statutory: implement-what-code-says + flag for accountant/HR/legal; NO compliance claim. ‡ Vault read risk is confidentiality of the stored bytes, not the endpoint itself.
Priority: P0 = external/regulated or pay-critical (CoC, payslips) · P1 = external-facing or chain-blocking · P2 = internal/operational. Risk: H = money/legal/PII/external · M = operational · L = public/internal low-sensitivity.
Grade (current state, A–F, from code reading — visual pass is Phase 7): B = shared `_layout`+letterhead+footer+watermark+generator, vault-audited, scoped; C = renders on shared layout but with a structural gap from §D-defects (no vault row, hardcoded draft, missing params, docblock drift, naming).

### Classification summary
- Transactional (T): PDF-01,02,03,04,08,09,10,11,12,13,14,23 (+25 serves stored copies).
- Reports (R): PDF-05,06,07,22.
- Sensitive (S): PDF-17,18,19,20,21 (CONFIDENTIAL watermark + no-store where vaulted; HR certs PDF-19/20/21 bypass vault — gap D8).
- Controlled/quality (Q): PDF-15,16,24 (CoC has doc-control substance: number, spec revision, inspector/reviewer; 8D has finalizer + finalized gate; quality-policy has effective date but no revision block).
- Labels/tags: none exist.

### Target template family (Phase 2 composition — no new libraries)
All compose from ONE foundation (`_layout` + `letterhead/footer/signatures/approval_signatures/qr/watermark` + `totals/notes/report-kit` to be built in Phase 2): T→`doc-kit`, R→`report-kit` (filter-summary box, KPI strip, grouped subtotals, landscape), S→`doc-kit`+confidential treatment, Q→`doc-kit`+document-control block. Tech decision (preliminary, to be measured in Phase 2): KEEP DomPDF — it is the only backend renderer, already hardened, and the 128M OOMs are a budget/queue problem, not a renderer-capability problem. Chromium would break the 4GB dev box; any switch needs measured memory/time + rollback.

## Coverage matrices

### Module × Export (✓ = exists, — = recommendation-track gap)
| Module | PDF exists | Missing (→ §D list in 00-discovery) |
|---|---|---|
| Accounting | PDF-01,02,03,04,05,06,07 | official receipt PDF, statement-of-account PDF |
| Purchasing | PDF-08,09 | GRN PDF (Inventory-owned), RFQ quote PDF |
| B2B portals | PDF-10,11,12 | portal statement-of-account PDF, portal 8D PDF |
| Supply Chain | PDF-13,14 | delivery-receipt PDF, shipment label |
| Production | — | work-order traveler PDF (enum-only) |
| MRP | — | plan/schedule/Gantt PDF |
| CRM | PDF-16 | sales-order PDF, delivery-linked packet |
| Quality | PDF-15 | NCR PDF (enum-only), incoming/in-process report PDF |
| Maintenance | — | maintenance WO PDF |
| Inventory | — (stock-card CSV+window.print only) | GRN PDF, stock/movement/count report PDFs |
| HR | PDF-19,20,21 (+18 self payslip) | clearance PDF (docblock-only), training/skill cert PDFs are upload-blobs not generated |
| Payroll | PDF-17 (+statutory CSV/XLSX, bank file) | payroll-register PDF (enum-only; CSV/XLSX by design — confirm before adding) |
| Admin | PDF-22,23,25 | — |
| Landing | PDF-24 | — |
| Dashboard | — | executive snapshot PDF (deferred proposal) |

### Role × Export (backend gate; frontend guards are UX-only)
| Export | Gate |
|---|---|
| PDF-01,02,03 | `accounting.journal|bills|invoices.view` |
| PDF-04 | `accounting.bills.view` + `Bir2307Request` |
| PDF-05,06,07 | route `accounting.statements.view` + controller `accounting.statements.export` |
| PDF-08,09 | `purchasing.view` + row `canView` (PR/PO policies) |
| PDF-10,11 | `auth:supplier_portal` + vendor ownership |
| PDF-12 | `auth:customer_portal` + customer ownership |
| PDF-13,14 | `permission_any:supply_chain.view,supply_chain.shipments.view` (no row ownership — any viewer prints any shipment) |
| PDF-15 | `quality.inspections.view` + eligibility (outgoing+passed+evidence) |
| PDF-16 | `crm.complaints.view` + finalized gate |
| PDF-17 | `payroll.view` + payroll scope + publishable |
| PDF-18,19,20,21 | auth + self scope (+catalogue / year gates for certs) |
| PDF-22 | `admin.audit_logs.view` |
| PDF-23 | `admin.print.bulk` |
| PDF-24 | public + throttle (intentional) |
| PDF-25 | per-type `permissionFor` + payslip/2316 ownership + publication |

### Export → data source → filters honored
| Export | Source of truth (must be shared with screen in Phase 3) | Honors today |
|---|---|---|
| PDF-01,02,03,08,09,10,11,12 | entity + fixed relations (no list query) | record ID only |
| PDF-04 | `Bir2307Service#forVendorQuarter` | year, quarter |
| PDF-05,06,07 | `TrialBalance/IncomeStatement/BalanceSheetService#generate` | from/to or as_of |
| PDF-13,14 | GRN receipts (non-draft) else PO intent; `Money` math | record ID only; `isDraft` hardcoded |
| PDF-15 | inspection + product/inspector/reviewer/specRevision/measurements + lot trace | record ID only |
| PDF-16 | complaint + customer/product/8D finalizer | record ID only |
| PDF-17,18 | payroll + publishable guard | record ID only |
| PDF-19 | employee + `with_salary` rate column | `with_salary` |
| PDF-20 | publishable payrolls sums | type, year(=current) |
| PDF-21 | publishable payrolls sums | year(=prior) |
| PDF-22 | `AuditLog::filteredQuery` (shared with index) | action/model_type/model_id/user_id/from/to; caps differ (500 vs paginated) |
| PDF-23 | per-ID entity loads (no list query) | type + ids[1..50] |
| PDF-24 | active customers + quality-policy settings | none |
| PDF-25 | vault row | document ID only |

## Top defects (from 00-discovery §B/D, ranked by accuracy/custody impact)
1. Statements + 2307 + audit-log + quality-policy + HR certs bypass the vault (no audit row/checksum/re-download) — D2/D8/D3.
2. Packing list / commercial invoice hardcoded `isDraft=true` — D1 (needs owner decision: internal-draft vs final).
3. `BulkPdfService` docblock advertises 4 unreachable types; `purchase_request` blade exists but is `Rule::in`-rejected — D4.
4. PO approvals only render `if relationLoaded` (silent empty block risk) — D5.
5. Audit-log PDF caps at 500 with no truncation notice; CSV twin caps 50k — D7 (WYSIWYE).
6. Vault filenames are machine refs, not human convention — D6.
7. Portal `formatMoney` duplicates money formatting outside `Money`/`StatementMoneyFormatter` — drift risk.
8. `enable_font_subsetting=false` — file-size watch item, not a defect yet.
9. Missing-PDF track (§D of 00-discovery) is recommendation-only; sales-order, delivery-receipt, GRN, NCR, WO-traveler top the request list by chain impact.

## Skipped / explicitly deferred (nothing silently omitted)
- Visual render pass of every template (needs Phase 7 rasterization; 4GB box — one export at a time). Current look undescribed beyond code structure; screenshots dir created empty.
- Load/size measurement per export (needs seeded edge-case data + `pdftoppm`/`pdftotext` runs).
- Pixel audit of Japanese/CJK glyph need (no JP text found in seeds/templates; needs real-data check).
- `pdf.demo` (dev fixture, no route) — not graded, not rebuilt unless Phase 2 needs a harness.
- Enum-only `Ncr|WorkOrderTraveler|PayrollRegister|SssR3|PhilHealthRf1|PagIbigRemittance|Bir1601c` PDF shapes — no route/Blade; tracked as missing-PDF recommendations, not as redesign targets.
