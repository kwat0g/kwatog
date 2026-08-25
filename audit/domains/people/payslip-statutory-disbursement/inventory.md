# M022 — Payslip / statutory / disbursement inventory

Audit date: 2026-08-24  
Release target: 📋 Plan Ready  
Audit mode: discovery, hardening, and polish review with local evidence only

## Purpose and boundary

M022 covers employee-facing payroll outputs and company statutory/remittance outputs:

- authenticated employee and authorized office-user payslip list, detail, and PDF download;
- generated payslip storage, document-vault access, confidentiality headers, and ownership checks;
- finalization-triggered payslip email delivery, retry/reconciliation, and employee notification;
- self-service employment, contribution, and BIR 2316 certificates;
- BIR 1601-C, BIR 1604-CF, BIR 2316 alphalist, SSS R-3, PhilHealth RF-1, and Pag-IBIG MCRF exports;
- statutory export routes, roles, frontend controls, file response behavior, and operator recovery states;
- employee-facing display of payroll/disbursement status.

Payroll period computation, GL posting, bank-file generation, and the period/proof state machine belong to the M021 dependency and were read only for contract context. They were not re-audited or modified here. The documents-exports and journal-ledger dependencies were likewise context only.

## Dependency context

The registry showed payroll-period-processing, employee-master, documents-exports, and journal-ledger at 📋 Plan Ready with no locks when M022 was claimed. M022 was the first unlocked Not Started Tier 2 candidate after the registry refresh. Dependency source was inspected only where it defines the publication/status or document contract.

## Surface inventory

| Surface | Primary evidence | Audit coverage |
|---|---|---|
| Payroll output routes | api/app/Modules/Payroll/routes.php:49-151 | feature gate, payslip permission, statutory permission, route-model binding |
| Payroll read/PDF controller | api/app/Modules/Payroll/Controllers/PayrollController.php:18-114 | employee/department/view-all scope, status gate, error-row behavior |
| Payslip renderer and vault | api/app/Modules/Payroll/Services/PayslipPdfService.php; api/app/Common/Services/DocumentVaultService.php; api/app/Common/Controllers/DocumentController.php | PDF contents, persistence, confidentiality, ownership, repeat-download behavior |
| Email delivery | SendPayslipEmailJob.php:21-125; EmailPayslipPdfOnPayrollFinalized.php:18-134; api/routes/console.php:96-102 | claim/retry state, finalization replay, void/race behavior, query bounds |
| Self-service certificates | api/app/Modules/HR/routes.php:240-264; SelfServiceController.php:451-510; SelfServiceDocumentService.php:15-226 | session employee scope, year/status availability, contribution and tax totals, PDF headers |
| Statutory controllers | StatutoryExportController.php:18-84; BirAlphalistController.php:11-33 | request validation, authorization, file headers, filing contract |
| Statutory calculations | Statutory/Bir1601CService.php; Bir1604CfService.php; PhilhealthRf1Service.php; PagibigMcrfService.php; BirAlphalistService.php; Exports/Government/SssR3Export.php | period selection, taxable bases, identifiers, totals, official-format completeness |
| Permissions and shared documents | database/seeders/RolePermissionSeeder.php:108-140, 454-517, 724-739; DocumentType.php:12-47; Admin/routes.php:154-170 | least privilege, confidential document mapping, owner boundary |
| SPA output surfaces | spa/src/pages/self-service/payslips.tsx; spa/src/pages/payroll/statutory/index.tsx; payroll routes and API clients | state display, loading/error/empty behavior, export selection, download affordance |
| Release/deployment | docs/DESIGN-SYSTEM.md; docs/DEPLOY.md:153-228; docs/RESTORE-DRILL.md:94-125; CI workflows | Atelier conformance, migration/worker order, rollback/restore, CI evidence |
| Verification | focused Payroll, Documents, and Security tests; SPA typecheck/lint/token audit | authorization, export fixtures, email recovery, document headers, frontend static gates |

## Observed workflow

1. Payroll finalization emits a durable event. The email listener claims eligible payroll rows by locking each row, marks them queued, and dispatches one retryable SendPayslipEmailJob per employee.
2. An employee opens the self-service payslip page. The backend scopes the payroll list to the session employee unless the caller has view-all or department-head authority. Clicking a row calls the payslip endpoint, which renders a confidential PDF and stores a new vault document before streaming it.
3. Self-service document endpoints resolve the employee from the authenticated user and render contribution certificates or BIR 2316 directly. The catalogue marks BIR 2316 available after any prior-year payroll row exists.
4. HR or Finance opens the statutory page and downloads monthly/yearly CSV files or the per-period SSS R-3 workbook. The routes require payroll.statutory.export, and the services select finalized/disbursed regular payroll rows.
5. The frontend shows loading cards, retryable payslip errors, empty states, status chips, and a filing-period selector. It does not show a statutory preflight, included-period manifest, or artifact audit/revision state.

## Verification completed

The clean Docker-backed focused run passed on 2026-08-24:

    docker compose run --rm -T api sh -lc 'test -x vendor/bin/phpunit && ./vendor/bin/phpunit tests/Feature/Payroll/StatutoryExportsTest.php tests/Feature/Payroll/BirAlphalistTest.php tests/Feature/Payroll/EmailPayslipOnFinalizeTest.php tests/Feature/Payroll/SendPayslipEmailJobTest.php tests/Feature/Payroll/PayslipEmailRecoveryTest.php tests/Feature/Documents/DocumentControllerTest.php tests/Feature/Documents/DocumentVaultServiceTest.php tests/Feature/Security/PayrollAuthorizationTest.php'

Result: 39 passed, 119 assertions, 00:22.783, PHPUnit 11.5.56 on PHP 8.3.30.

SPA checks completed:

    npm run typecheck
    npm run lint
    npm run audit:tokens

Typecheck and lint exited successfully; token discipline reported 769 files clean. Vitest and Playwright could not start because Vite could not write under the pre-existing root-owned spa/node_modules/.vite-temp directory. No ownership change or cache cleanup was performed.

## Discovery, hardening, and polish observations

- Discovery: the module has a real route/controller/service split, dedicated company-wide statutory permission, session-employee resolution for self-service, a central confidential PDF renderer/vault, and durable queued/failed/sent email state.
- Hardening: the document-vault owner comparison uses a Payroll document id as if it were an Employee id, and the ordinary payroll.view permission is treated as view-all for vault documents. Payslip and self-service certificate endpoints also lack a consistent finalized/disbursed publication gate.
- Statutory integrity: the BIR alphalist deliberately emits a plain CSV while deferring official DAT/XML; taxable-base formulas differ between the alphalist, remittance summaries, and self-service BIR 2316; missing statutory identifiers and the SSS EC column become blank/zero output instead of a blocked preflight.
- Artifact governance: direct CSV/XLSX responses do not carry the vault's confidential no-store contract and are not stored as immutable export runs with a reproducible input snapshot.
- Reliability: the email claim/retry state is thoughtful, but the job does not re-check the parent period status before sending and a process crash after mail acceptance can cause a duplicate retry. PDF downloads render and persist a fresh artifact on every request.
- Polish: the payslip table labels computed_at as the period and always labels successful rows Computed; the statutory screen has no coverage/validation preview and advertises the deferred CSV alphalist as an annual filing export.
- The inspected SPA uses Atelier role tokens, compact panels/tables, labels, focus rings, loading/error/empty states, and semantic status text. The browser/runtime path remains unverified locally.

## Evidence gaps

- No regression test covers cross-employee vault access, payslip access for computed/approved/voided periods, or self-service certificates from unfinalized rows.
- No statutory owner-approved fixture proves the official DAT/XML contract, taxable-base policy, SSS EC treatment, identifier validation, or period-boundary treatment.
- No test covers no-store headers or immutable/auditable statutory export runs.
- No test covers a queued email racing with period void, mail-provider acceptance followed by worker crash, or large-population PDF/statutory generation.
- No staging/production verification of SMTP delivery, queue workers, statutory filing acceptance, browser journeys, storage retention, or restore of generated document artifacts was available.
- The worktree contains pre-existing user changes in backup, notifications, landing, Docker, database scripts, SPA notification/backup pages, and audit/docs areas. No M022 production source files were changed.
