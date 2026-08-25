# M022 — Payslip / statutory / disbursement audit report

Audit date: 2026-08-24  
Status: 📋 Plan Ready  
Release recommendation: separate implementation work required

Production audit: 32/100, blocked, because a normal employee payslip permission can authorize cross-employee vault downloads and the publication/statutory boundaries do not consistently prove that an output is final, complete, reproducible, or filing-ready. The focused Docker suite passed 39 tests and 119 assertions, but the critical negative access/state races and official filing fixtures are absent.

## Executive assessment

M022 has credible foundations: the main payroll API scopes ordinary users to their employee and department heads to their department; company-wide statutory routes use a dedicated payroll.statutory.export permission; self-service controllers resolve the employee from the session rather than accepting an employee id; PDF responses are private/no-store when they pass through the vault or self-service renderer; email delivery uses a locked queued claim, retry state, and reconciliation command; the SPA has loading, empty, error, and permission-gated states.

The publication boundary is not safe enough for payroll data. DocumentController treats payroll.view, which is intentionally granted to every self-service role, as sufficient to view any payslip vault row, and its owner check compares a Payroll id with an Employee id. Direct payslip and certificate routes accept rows without requiring finalized/disbursed status. Statutory outputs disagree on taxable base, silently emit blank identifiers/zero components, and explicitly stop at a plain CSV for the BIR alphalist while the UI presents it as a filing export. Direct CSV/XLSX downloads omit the confidential no-store/audit-run boundary. Email can send a queued payslip after its period is voided. These are P1 data-boundary and statutory-publication risks, so M022 is plan-ready rather than release-ready.

## Blockers and high-value findings

### M022-F01 — P1, Broken: ordinary payslip permission can open another employee's vault document

Evidence: PayslipPdfService.php:63-67 passes the Payroll model to DocumentVaultService::store, which stores entity_type and the Payroll primary key at DocumentVaultService.php:70-81. DocumentController.php:100-115 maps a payslip to payroll.view and sets hasView true when the caller has payroll.view; the owner comparison at :109-111 compares document.entity_id with user.employee_id even though the document entity id is a payroll id. RolePermissionSeeder.php:724-739 grants payroll.view through selfService to every employee-type role. The vault routes at api/app/Modules/Admin/routes.php:154-167 deliberately have no blanket permission and rely on this controller check.

Impact: any authenticated user with the ordinary own-payslip permission can pass the vault authorization for any payslip document whose hash id they obtain. Hash ids are identifiers, not an authorization boundary. This bypasses the stronger owner/department checks in PayrollController.php:97-113 and exposes confidential payroll PDFs across employees.

Action: resolve the owning Payroll and compare its employee_id with the caller, or store an explicit owner reference; reserve payroll.payslip.view_all/hr sensitive permissions for cross-employee access; verify document_type/entity_type/entity_id combinations before streaming. Add cross-owner view/download denial, owner success, department-head policy, admin policy, and forged entity-pair tests.

### M022-F02 — P1, Broken: payslips and self-service tax certificates can publish non-final payroll data

Evidence: PayrollController.php:24-70 lists Payroll rows without a period-status predicate, and :89-95 authorizes the row then calls PayslipPdfService::stream. PayslipPdfService.php:30-49 renders without checking the parent period status or error_message. PayrollPeriodStatus.php:7-15 includes computed, approved, finalized, disbursed, and voided states, while voided payroll rows remain historical records in the dependency lifecycle. SelfServiceDocumentService.php:156-161 and :189-194 aggregate rows with only whereNull(error_message), not finalized/disbursed status. SelfServiceController.php:451-464 uses any prior-year row as catalogue availability, and :505-510 directly renders an arbitrary requested year without enforcing that catalogue gate or a filed status.

Impact: an employee or authorized viewer can retrieve a computed/approved row before checker finalization, a voided row, or a certificate built from an unfiled period. The result can show provisional amounts as an official-looking payslip/BIR/contribution certificate and can reveal values that the UI assumes are available only after the payroll run is complete.

Action: define one publication predicate for employee payslips and certificates, enforce it server-side on list/show/render and email jobs, and make voided/replaced rows inaccessible to ordinary self-service users. Keep a separately authorized draft-preview path only if the product requires it. Add negative tests for draft/processing/computed/approved/finalized/disbursed/voided and error rows, plus direct URL access independent of UI state.

### M022-F03 — P1, Missing: the advertised BIR alphalist is explicitly not a filing-ready artifact

Evidence: BirAlphalistService.php:12-20 states that official BIR DAT/eBIRForms XML, fixed field order, header/trailer records, control totals, and ATC codes are not implemented and that the service intentionally emits a plain CSV. BirAlphalistController.php:21-32 and spa/src/pages/payroll/statutory/index.tsx:125-130 expose that CSV as BIR 2316 Alphalist, while the page calls the group statutory filing exports at :64-69.

Impact: the system can encourage an operator to treat an internal CSV as a statutory filing deliverable even though the service itself says it is not the official format. If the product requirement is only an internal reconciliation extract, the name and UI contract are misleading; if the requirement is filing-ready output, the required artifact is absent.

Action: record the statutory owner's decision explicitly. Either rename/gate the CSV as an internal staging extract and add a manual filing handoff, or implement the approved official format from an authoritative specification with control totals, validation, versioning, and acceptance fixtures. Do not infer the missing format from the current code.

### M022-F04 — P1, Broken: taxable income is calculated inconsistently across statutory surfaces

Evidence: BirAlphalistService.php:75-85 computes taxable_income as total_gross minus total_deductions. Bir1601CService.php:21-29 defines the exempt portion as only mandatory employee statutory contributions and :51-65 computes taxable compensation as gross minus that exempt portion. Bir1604CfService.php:32-55 repeats the same gross-minus-mandatory formula. SelfServiceDocumentService.php:205-214 also computes BIR 2316 taxable as gross minus mandatory contributions. Payroll total_deductions contains statutory, withholding, loan, other, and adjustment lines in Payroll.php:29-45.

Impact: the same finalized payroll can produce different taxable bases in the alphalist versus monthly/annual remittance and employee BIR 2316 outputs. Loans, withholding, adjustments, and other deductions change the alphalist base even though the other outputs exclude them. The output set cannot be reconciled reliably, and the code does not identify which formula is authoritative.

Action: make the tax-base policy explicit with a single shared decimal calculation/DTO, include the approved treatment of statutory, withholding, loan, adjustment, de minimis, and 13th-month components, and generate reconciliation totals across every export. Add fixtures where each deduction category is nonzero and assert alphalist, 1601-C, 1604-CF, BIR 2316, payroll, and GL identities.

### M022-F05 — P1, Incomplete: statutory files silently contain blank identifiers and unmodelled contribution components

Evidence: PhilhealthRf1Service.php:45-57 maps a missing PhilHealth number to an empty string; PagibigMcrfService.php:45-57 does the same for Pag-IBIG; SssR3Export.php:61-83 maps a missing SSS number to an empty string. BirAlphalistService.php:67-85 maps a missing encrypted TIN to an empty string. No service preflight rejects or reports incomplete identifiers. SssR3Export.php:66-82 also hard-codes EC share to 0.0 because it is not tracked, while the export headings expose an EC Share and Total Contribution column.

Impact: a file can download successfully while containing rows the agency cannot identify or a contribution total that omits a component. Operators have no blocked/manual-review state or row-level exception report and may submit an apparently complete artifact with incomplete statutory data.

Action: add a preflight that validates required identifiers and all agency-specific components, returns a reviewable exception report, and blocks the final export until an authorized override is recorded. Decide and document the SSS EC model from the authoritative agency specification; do not silently substitute zero. Add missing-id, malformed-id, missing-component, override, and total-reconciliation tests.

### M022-F06 — P1, Incomplete: confidential statutory downloads bypass the document confidentiality and audit-run boundary

Evidence: StatutoryExportController.php:20-25 returns CSV responses with only Content-Type and Content-Disposition; BirAlphalistController.php:29-32 does the same. SpreadsheetExportService.php:20-26 returns the XLSX response without Cache-Control or no-store headers. DocumentType.php:35-47 marks statutory types confidential, and DocumentVaultService.php:212-227 applies no-store only when a document actually passes through the vault, but the current statutory controllers stream direct responses and do not create a stored export record, checksum, run actor, or input snapshot.

Impact: company-wide TIN, statutory identifiers, names, compensation, and contribution data has no explicit no-store response contract and no reproducible/auditable artifact run. A later payroll correction, void, or table change can make the same URL produce different contents with no record of what was downloaded or reviewed.

Action: route statutory output through an explicit confidential export service that sets no-store/nosniff headers, records actor, scope, source period ids, policy/table versions, checksum, and generated time, and supports controlled download/revocation. If persistence is intentionally forbidden, add an equivalent immutable run ledger and verify proxy/cache policy. Add header, audit, reproducibility, void-after-export, and authorization tests.

### M022-F07 — P1, Incomplete: a queued payslip email can survive a period void

Evidence: EmailPayslipPdfOnPayrollFinalized.php:35-50 selects rows by period id and email state but does not constrain the parent period to finalized/disbursed. SendPayslipEmailJob.php:38-67 checks only that the payroll row exists, is not already emailed, is queued, and has an email address; it never re-reads or validates the parent period status before rendering and sending. The job stamps sent only after Mail::send at :69-85. The period state machine includes a void path after finalization in the M021 dependency.

Impact: finalization can queue a job, Finance can void/correct the period before the worker runs, and the worker can still send a payslip for a voided run. A worker crash after the mail provider accepts the message but before the sent stamp commits can also cause a duplicate on retry. The email state machine is retryable but not publication-safe at the final send boundary.

Action: re-read and lock the parent period immediately before rendering/sending, refuse non-published states, and define the void/replacement behavior for already queued jobs. For duplicate delivery, choose provider/message-id idempotency or an explicit at-least-once policy with support handling. Add queued-vs-void, out-of-order finalization/void, provider-accepted crash, and retry tests.

### M022-F08 — P2, Incomplete: each payslip download renders and stores a new artifact

Evidence: PayslipPdfService.php:63-76 calls generateAndStore from stream on every request. DocumentVaultService.php:56-82 creates a timestamped random filename and a new documents row for every store operation; the documents migration has only entity/type/time indexes at 0122_create_documents_table.php:19-55 and no current-document or deduplication key. Self-service and payroll pages call the stream endpoint directly on row clicks.

Impact: repeated clicks, browser retries, or automated requests consume PDF CPU and private storage, create multiple audit rows for the same payroll, and make it unclear which artifact is canonical. Large employee populations increase the cost of a simple payslip view and make retention/pruning harder.

Action: make rendering/storing an explicit idempotent versioned operation and make ordinary download read-only against the current approved artifact. Add a unique payroll/document-version key, retention policy, concurrency handling, and bounded render rate. Add repeat-download, concurrent-download, changed-payroll, missing-blob, and cleanup tests.

### M022-F09 — P2, Polish: frontend labels and statutory controls do not expose the publication contract

Evidence: spa/src/pages/self-service/payslips.tsx:21-29 labels computed_at as Period, and :50-68 labels every non-error row Computed while adding only a separate Disbursed/Awaiting disbursement marker. spa/src/pages/payroll/statutory/index.tsx:48-61 accepts arbitrary year/month values and only toggles a spinner; :64-131 offers direct downloads without showing included periods, missing identifiers, reconciliation totals, or the deferred-format warning. The backend controllers cast query values directly without range validation at StatutoryExportController.php:28-63 and BirAlphalistController.php:21-32.

Impact: employees can misread a computation timestamp as a pay period and operators cannot tell whether a statutory file is complete, provisional, or internally staged before downloading it. Invalid date input becomes an empty/ambiguous export instead of a clear validation state.

Action: return period dates and authoritative status in the API, use them in the table, validate year/month at the request boundary and UI, and add a preflight/review panel with coverage, exceptions, totals, and format status. Preserve Atelier loading/error/empty patterns and make the download action explicit and keyboard discoverable.

## Strengths observed

- PayrollController has a clear server-side owner/department/view-all policy for its primary list/show routes, and the self-service controller resolves the employee from the session without accepting a cross-employee id.
- Statutory routes require authentication, the payroll feature, and a dedicated company-wide PII permission. Existing tests prove a plain employee with only payroll.view is denied the statutory endpoints.
- Payslip PDFs use a central renderer and private vault; confidential vault/self-service responses set no-store, nosniff, and appropriate content disposition. Vault rows carry checksums and generator metadata.
- Email finalization has locked row claims, queued/failed/sent state, attempts, backoff, failure notification, and a scheduled reconciliation path. Sequential replay tests are present.
- Statutory services exclude error payroll rows and non-regular 13th-month periods, and the SSS exporter refuses non-filed period statuses.
- The SPA follows the Atelier system: token-only colors, compact panels/data tables, semantic text-plus-color status, labels/focus rings, loading/error/empty states, and disabled duplicate-download controls.

## Evidence checked

- Current release surface: main at b269eafd, origin aligned; recent commits cover backup, health, export, and mail hardening. Pre-existing dirty changes were preserved and no M022 production files were modified.
- Payroll/HR/Admin routes, controllers, services, jobs, listeners, models, enums, document vault, migrations, permission seeders, scheduler, frontend pages/API/types, CI workflows, design-system guidance, deployment, restore, and related tests were inspected.
- Clean focused Docker run: 39 passed, 119 assertions, 00:22.783.
- SPA typecheck and lint passed; token discipline passed with 769 files checked.

## Evidence missing / follow-up validation

- Browser/e2e and Vitest execution were blocked by EACCES writing Vite config artifacts into root-owned spa/node_modules/.vite-temp.
- No cross-owner document authorization test, unfinalized/voided publication test, self-service certificate status test, or email void race test exists.
- No statutory owner-approved official-format, tax-base, identifier, EC, cache-header, snapshot, or submission-acceptance fixture exists.
- No large-population PDF/statutory export run, real SMTP delivery, queue worker recovery, staging filing test, or restore/retention drill for generated documents was available.

## Release decision

No production-code fixes were applied. The majority of findings involve authorization, finalization state, statutory/tax policy, confidential artifact governance, or queue publication semantics. Applying only the frontend labeling or performance improvements would leave the P1 cross-employee and statutory risks unresolved. M022 is released as 📋 Plan Ready with the ordered action plan below.
