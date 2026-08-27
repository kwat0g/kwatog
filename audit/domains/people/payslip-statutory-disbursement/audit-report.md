# M022 — Payslip & Statutory Disbursement

Audit date: 2026-08-27
Assigned card: batch4-agent-b
Claim: people/payslip-statutory-disbursement
Registry snapshot: audit/2026-08-26-five-modules
Current status: 📋 Plan Ready

## Audit result

The core payslip ownership and publication gates are now materially stronger than the previous audit: ordinary users are owner-scoped, payroll documents require a publishable period, self-service certificates use the same predicate, and finalized-payslip email work is void-aware. The module is not release-ready because statutory outputs still lack an agreed official artifact contract, a single filing-date and taxable-base policy, completeness enforcement, and an auditable export run. Payslip generation and email delivery also remain non-idempotent across crash boundaries.

The gate is Plan Ready. The active plan is predominantly separate-recommended and includes large policy/data-contract work, so no production code was changed in this session.

## Evidence reviewed

- Fresh module registry, module inventory, status, prior audit report, action plan, and fix log.
- Current M022 controllers, services, models, routes, migrations, SPA pages/API types, and focused feature tests.
- Dependency context in payroll GL posting and accounting journal creation; no dependency files were changed.
- Git status, relevant history/diff, and source/document mtimes.
- Focused backend verification on the unique database ogami_test_m022_agent_b.
- SPA token audit, lint, and typecheck. Token discipline passed; lint/typecheck failures are outside M022.

## Audit passes

### Discovery

Mapped the employee, HR, payroll, vault, email, and statutory routes to their permissions and session/period scopes. The discovery pass identified the missing official artifact contract (M022-F03), implicit filing-date basis (M022-F10), and cross-surface calculation drift (M022-F04).

### Hardening

Checked negative authorization/publication cases, direct downloads, missing identifiers, soft-deleted employees, export persistence, email retry ordering, document storage, and operator failure filtering. F01/F02 and the no-store/void-check portions of F06/F07 are verified resolved; M022-F05 through M022-F08 and M022-F12 remain active.

### Polish

Reviewed SPA loading/empty/error/permission states, statutory labels, period validation, download affordances, and the filing-readiness warning. M022-F09 remains incomplete because the page has no preflight/review evidence or run state. Browser/e2e evidence was not available in this session.

## Findings resolved since the previous audit

### M022-F01 — Broken, resolved: payslip document ownership

DocumentController now resolves payslip documents back to Payroll and checks the employee/department authorization path in DocumentController.php:148-227. The focused DocumentController tests cover owner access, another employee denial, department scope, and forged entity pairs in tests/Feature/Documents/DocumentControllerTest.php:85-133. A confidential payslip response also sets no-store headers in DocumentController.php:230-243.

### M022-F02 — Broken, resolved: publication gate drift

PayrollPublicationPolicy.php:20-76 is used by payroll list/detail/download, self-service payslips and certificates, email enqueue/send, and document access. It permits only Finalized or Disbursed periods with no payroll error. PayslipPublicationTest.php:20-74 verifies list filtering, direct draft/error rejection, and draft certificate rejection. The current email worker also rechecks the parent period under lock in SendPayslipEmailJob.php:64-77.

### M022-F06a — Incomplete, resolved in part: confidential download headers

StatutoryExportController.php:20-28, 96-110 and BirAlphalistController.php:31-37 now apply private no-store, Pragma, and nosniff headers. The remaining export-run audit gap is tracked below as M022-F06.

### M022-F07a — Incomplete, resolved in part: voided queued email

The listener claims delivery work only for a publishable period, and SendPayslipEmailJob.php:64-77 cancels queued work when the period is voided or otherwise unpublished. The provider-acceptance crash/idempotency gap remains M022-F07.

## Active findings

### M022-F10 — Broken, P1: statutory filing period is implicitly period_start-based

Evidence:

- The repository business rule says payroll_date is load-bearing for government-table selection, de minimis month, and GL posting date in CLAUDE.md:329-334.
- BirAlphalistService.php:41-47 and :55-57, Bir1601CService.php:43-54, Bir1604CfService.php:32-42, PhilhealthRf1Service.php:21-39, and PagibigMcrfService.php:21-39 filter reporting periods using period_start.
- SelfServiceDocumentService.php:154-182 and :190-225, plus SelfServiceController.php:728-740, use period_start for employee contribution and tax-year summaries as well.

Impact: a payroll whose effective/pay date crosses a calendar month or year can be placed in the wrong statutory filing period, while employee certificates and statutory files can disagree. The code does not make the filing-date policy explicit or test a cross-month case.

Required action: decide and document the statutory basis, then centralize the selector (using payroll_date if that is the declared invariant), update every statutory and self-service aggregate, and add cross-month/year fixtures. This is a large, policy-sensitive change.

### M022-F04 — Broken, P1: taxable-base and thirteenth-month rules do not reconcile

Evidence:

- BirAlphalistService.php:41-61 aggregates gross_pay and total_deductions, and :75-85 emits taxable as gross less total_deductions.
- Bir1601CService.php:43-68 and Bir1604CfService.php:32-56 calculate taxable totals as gross less mandatory employee contributions.
- SelfServiceDocumentService.php:190-225 includes every publishable payroll in the tax summary, including thirteenth-month rows, while the statutory services explicitly exclude thirteenth-month rows.
- Payroll.php:39-41 shows that total_deductions includes statutory, withholding, loan, other, and adjustment categories; docs/SCHEMA.md:4 requires decimal Money rather than float, while these exporters cast to float and round.

Impact: the alphalist, monthly/annual BIR files, and employee BIR2316 summary can produce different taxable bases and totals for the same published payroll. The generated files can look internally valid while failing reconciliation or applying the wrong statutory treatment to deductions and thirteenth-month pay.

Required action: establish one authoritative taxable-base policy and an explicit thirteenth-month treatment, expose it through a shared calculation result, retain centavo-exact values through export, and add cross-export reconciliation fixtures. This is a large, policy-sensitive change.

### M022-F03 — Missing, P1: official statutory artifact contract

Evidence:

- BirAlphalistService.php:12-20 explicitly describes the output as plain CSV and notes that official BIR DAT/eBIRForms XML field order, header/trailer, control totals, and ATC handling are not implemented.
- BirAlphalistController.php:15-37 labels the endpoint as an alphalist CSV download.
- The SPA correctly calls the alphalist an internal staging CSV and says it is not the official DAT/XML artifact in statutory/index.tsx:71-74 and :138-140.

Impact: the system has no authoritative filing artifact, official field contract, versioned spec, or machine-checkable filing-readiness boundary. A user can download a staging extract, but the product cannot claim that it is ready for submission.

Required action: define the supported BIR/SSS/PhilHealth/Pag-IBIG artifact versions, ownership of format updates, validation rules, control totals, and the staging-versus-filing workflow. Implement or explicitly gate each unsupported format. This is a large, separate-recommended change.

### M022-F05 — Incomplete, P1: statutory completeness is not enforced

Evidence:

- BirAlphalistService.php:67-85, SssR3Export.php:61-84, PhilhealthRf1Service.php:45-57, and PagibigMcrfService.php:45-57 emit blank identifiers when employee numbers are missing.
- SssR3Export.php:61-84 hard-codes EC share to 0.0 and includes it in the total, with no source field or explicit policy.
- SssR3Export.php:24-37 does not exclude soft-deleted employees in the query, unlike several other statutory services.
- The statutory controller paths generate/download files directly without a completeness preflight in StatutoryExportController.php:52-84.

Impact: a successful HTTP download can contain incomplete member identifiers, a fabricated zero EC share, or stale employee rows. The operator receives a file rather than an actionable exception list and cannot tell whether the result is filing-ready.

Required action: add a preflight that reports missing identifiers, deleted/inactive employees, unsupported rows, and contribution anomalies; require an explicit reviewed override where policy permits; source or deliberately omit EC share; and test every export’s completeness contract. Medium-to-large, separate-recommended.

### M022-F06 — Incomplete, P1: no immutable/auditable direct export run

Evidence:

- Direct statutory endpoints call services and stream the result immediately in StatutoryExportController.php:52-84 and BirAlphalistController.php:21-37.
- SpreadsheetExportService.php:22-29 builds a workbook in memory and returns it without persisting an export run, input snapshot, policy/table version, checksum, or actor record.
- ScheduledExportArtifactService.php:10-41 persists scheduled artifacts, but the direct statutory routes do not use that run/artifact path.
- Confidential response headers are present as noted above, but headers do not provide reproducibility, approval, retention, or revocation.

Impact: after a filing or download there is no durable answer to who generated the file, which payroll rows and policy versions were included, which preflight result was accepted, or whether a later rerun is the same artifact. This is a material audit and dispute-reconstruction gap.

Required action: define a statutory export-run record with actor, scope, selected period basis, source-row snapshot/version, calculation-policy version, preflight result, checksum, artifact retention, and download/approval events. Connect both CSV and spreadsheet paths. Large, separate-recommended.

### M022-F07 — Incomplete, P1: provider-acceptance crash can duplicate payslip email

Evidence:

- SendPayslipEmailJob.php:90-103 sends through Mail before recording sent state.
- The delivery state migration provides status, attempts, queued time, and last error in 2026_08_10_160000_add_payslip_email_delivery_state.php:14-30, but no provider/message idempotency key.
- The current period lock and void check in SendPayslipEmailJob.php:64-77 prevent stale queued work, but do not cover a provider accepting a message followed by a worker/database failure before the sent update commits.

Impact: a retry can submit the same payslip again after an external provider accepted the first submission. Payroll staff cannot distinguish a safe retry from a duplicate delivery using the current state machine.

Required action: use provider-supported idempotency/message keys or a durable outbox/accepted state with a documented retry contract; add a test for provider acceptance followed by a process/transaction failure and retry. Medium, separate-recommended.

### M022-F08 — Incomplete, P2: payslip download is still a write-side-effect without a version key

Evidence:

- PayslipPdfService.php:65-79 calls generateAndStore on every stream request.
- DocumentVaultService.php:43-89 creates a new timestamped document row/blob for each store, and :101-157 only replaces a row when an explicit regeneration path is used.
- The documents migration 0122_create_documents_table.php:19-55 has entity/type/time indexes but no unique current-version or source/payroll revision key.

Impact: repeated downloads consume CPU/storage, create multiple audit/document rows, and leave no canonical relationship between a published payroll revision and its payslip artifact. Concurrent requests can create duplicate versions.

Required action: make generation an explicit versioned operation and make ordinary download read-only; add a source revision/content key, concurrency rule, retention policy, and tests for repeated/concurrent downloads. Medium, separate-recommended.

### M022-F09 — Incomplete, P2: statutory UI has warnings but no preflight/review contract

Evidence:

- The SPA states that alphalist is staging-only and validates year/month ranges in statutory/index.tsx:50-74 and :138-140.
- It displays a general “verify periods, IDs, totals, and filing readiness” warning in statutory/index.tsx:104-107, but the cards in :108-143 do not show included rows, exceptions, control totals, artifact version, or an approval state.
- The download API in api/payroll/statutory.ts:8-28 is a direct request/response path with no preflight manifest or export-run identifier.

Impact: the interface communicates caution but does not give an operator evidence to review before downloading or a durable acknowledgment of filing readiness. This remains incomplete even though labels and basic validation are improved.

Required action: expose the backend preflight/run contract in the UI with coverage, missing identifiers, totals, format/version, and reviewed/download state. Medium; this is the only same-session-ok candidate, but the overall gate forbids fixing it now.

### M022-F11 — Missing, P2: personal statutory certificates lack an audit/version contract

Evidence:

- SelfServiceDocumentService.php:17-25 explicitly states that personal certificates bypass the document vault.
- BIR2316 and contribution certificate bytes are rendered directly in SelfServiceDocumentService.php:121-182, with only inline no-store headers in :228-237.
- No document/run record, source revision, policy version, or reproducibility token is created for these sensitive PDFs.

Impact: an employee can receive a sensitive certificate, but the organization cannot reliably attribute or reproduce the exact artifact later. If the bypass is intentional, its retention and audit limits are not expressed as a product contract.

Required action: obtain a policy decision for direct personal certificates; either document the deliberate no-retention boundary or route them through a versioned, access-audited artifact record. Medium, separate-recommended.

### M022-F12 — Broken, P2: payroll failure tab is query-contradictory

Evidence:

- PayrollController.php:31-32 applies PayrollPublicationPolicy::scopePublishable, which excludes rows with errors.
- The same query adds whereNotNull(error_message) for failed_only at PayrollController.php:63-65.
- The SPA requests failed_only for the Failures tab in pages/payroll/periods/detail.tsx:210-219.

Impact: the operator failure view cannot return a row that has an error because the preceding publication scope excludes it. Recovery/diagnostic work is therefore blind even though the UI presents a failure tab.

Required action: split operator failure diagnostics from employee-visible publishable results, preserving permission and period scope while allowing error rows in the dedicated failure query. Medium, separate-recommended because it changes a shared payroll query contract.

## Dependency blocker

P02-01 remains outside this claimed module. On ogami_test_m022_agent_b, the focused regression test fails because the payroll journal entry has a null actor:

- PayrollMoneyFindingsRegressionTest.php:195-215 fails at :208: “Payroll JE must record who created it.”
- JournalEntryService.php:139 deliberately sets created_by to null when reference_type is present.

This is a finance/journal-ledger policy/implementation decision. It was not changed during this M022 audit.

## Verification

Focused backend command:

    DB_DATABASE=ogami_test_m022_agent_b php artisan test \
      tests/Feature/Documents/DocumentControllerTest.php \
      tests/Feature/Payroll/PayslipPublicationTest.php \
      tests/Feature/Payroll/StatutoryExportsTest.php \
      tests/Feature/Payroll/BirAlphalistTest.php \
      tests/Feature/Payroll/EmailPayslipOnFinalizeTest.php \
      tests/Feature/Payroll/SendPayslipEmailJobTest.php \
      tests/Feature/Payroll/PayslipEmailRecoveryTest.php \
      tests/Feature/Payroll/PayslipNotificationDedupeTest.php \
      tests/Feature/Payroll/PayrollGlPostingTest.php

Result: 52 passed, 156 assertions, 33.49 seconds.

Dependency regression:

    DB_DATABASE=ogami_test_m022_agent_b php artisan test --filter=test_p02_01_payroll_je_has_actor_and_audit_row tests/Feature/Payroll/PayrollMoneyFindingsRegressionTest.php

Result: 1 failed, 2 assertions. The failure is the dependency blocker above.

SPA checks:

- npm run audit:tokens passed: 786 files checked.
- npm run lint failed only on existing findings in useChainProgress.tsx, accounting/journal-entries/edit.tsx, and quality/inspections/create.tsx; none is in M022.
- npm run typecheck failed only because assets/detail.tsx cannot resolve qrcode and has an implicit-any parameter; none is in M022.
- No browser/e2e run was treated as release evidence for this audit.

No production source or test files were changed in this session.
