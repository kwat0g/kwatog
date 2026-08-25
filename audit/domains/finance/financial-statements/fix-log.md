# M029 — Financial Statements fix log

Audit date: 2026-08-24  
Module status: 🔁 Needs Re-audit  
Implementation status: Independent plan items implemented; fiscal-year policy remains pending

## Audit actions

- Refreshed the module registry before selecting work.
- Skipped the locked `finance/accounts-receivable` candidate and atomically claimed the next unlocked Tier-2 candidate, `finance/financial-statements`.
- Inspected statement services, controllers, routes, PDF templates, permissions, accounting settings, internal SPA pages, dashboard/sidebar entry points, tests, and the design system.
- PHP syntax checks passed for the six relevant controller/service files.
- SPA typecheck completed successfully.
- The focused PHP feature test was attempted but could not connect to PostgreSQL because host `db` was unresolved; it ran 0 assertions.

## Code changes

None. Only audit artifacts were authored.

## Why fixes were deferred

The findings are dominated by export authorization, fiscal-year balance-sheet semantics, money-safe PDF rendering, currency/export contracts, date validation, and missing financial regression coverage. These require separate design, authorization, financial-policy, and reconciliation work. The dashboard-link and responsive/accessibility items are contained, but applying them alone would leave the primary report risks unresolved.

## Deferred contained candidates

Dashboard report links and responsive/accessibility table polish remain in the action plan as `same-session-ok` candidates for a later implementation session.

## Release note

This log is complete for the audit session. M029 was released through the audit release script with status `📋 Plan Ready`, its lock was removed, and the registry was regenerated.

## 2026-08-25 implementation session

### Code changes

- **M029-F01 — export authorization:** `api/app/Modules/Accounting/Controllers/FinancialStatementController.php:32,58,82,112,136,173-177` now rejects CSV requests without `accounting.statements.export`; `PdfController.php:23-46` applies the same boundary to all statement PDFs. A concurrent direct AP-aging export handler at `FinancialStatementController.php:160-165` was minimally corrected to set the query format so it reaches the CSV serializer. SPA export buttons are now permission-gated in `spa/src/pages/accounting/trial-balance.tsx:36`, `income-statement.tsx:39`, `balance-sheet.tsx:32`, `ar-aging.tsx:33`, and `ap-aging.tsx:33`.
- **M029-F03 — strict date contract:** `api/app/Modules/Accounting/Requests/StatementDateRangeRequest.php:11-65` and `StatementAsOfRequest.php:10-32` enforce `Y-m-d`, application-timezone parsing, and non-reversed ranges across JSON, CSV, and PDF controllers (`FinancialStatementController.php:30,56,80,110,134`; `PdfController.php:23,30,37`).
- **M029-F04 — money-safe PDFs:** `api/app/Modules/Accounting/Services/StatementMoneyFormatter.php:16-42` formats decimal strings with string/BCMath operations. `PdfService.php:28-34,81-140` injects it into the three statement views, and the statement templates no longer cast amounts to floats (`api/resources/views/pdf/trial-balance.blade.php:33-40`, `income-statement.blade.php:22-42`, `balance-sheet.blade.php:25-41`).
- **M029-F05 — functional currency metadata:** JSON payloads and CSV rows now carry the configured currency in `FinancialStatementController.php:35-36,61-62,85-86,115-116,139-140`; all statement PDFs display it through `PdfService.php:88-102,137-138` and the three templates. SPA report headers display the returned currency (`spa/src/pages/accounting/*.tsx` statement pages).
- **M029-F06 — export reconciliation contract:** trial-balance CSVs now include typed account/total/status rows (`FinancialStatementController.php:38-51`), balance-sheet CSVs include equation and balanced-status rows (`:88-100`), and income/aging CSVs use the same row-type/currency metadata shape (`:64-75,118-126,142-150`).
- **M029-F07 — regression coverage:** added `api/tests/Feature/Accounting/FinancialStatementBoundaryTest.php:14-94` for view/export authorization, currency/CSV metadata, malformed dates, reversed ranges, and PDF validation; extended `StatementServicesTest.php:76-86` with a current-year balance-sheet invariant; updated the aging CSV contract assertion at `AgingReportTest.php:123`. Added formatter precision tests at `api/tests/Unit/Accounting/StatementMoneyFormatterTest.php:11-36`.
- **M029-F08 — discoverability:** replaced the five repeated dashboard placeholders with real report links at `spa/src/pages/dashboard/finance.tsx:185-203` and added permission-gated Finance sidebar entries at `spa/src/components/layout/Sidebar.tsx:509-545`.
- **M029-F09 — responsive/accessibility polish:** added horizontal overflow wrappers and minimum table widths to the five report pages, semantic `<thead>`/`<th scope="col">` headers to income/balance tables, and responsive balance-sheet summary spans (`spa/src/pages/accounting/trial-balance.tsx:55-80`, `income-statement.tsx:57-70`, `balance-sheet.tsx:45-78`, `ar-aging.tsx:50-86`, `ap-aging.tsx:50-86`).

### Verification

- Passed: PHP syntax checks for new/changed PHP files; `php artisan view:cache`; targeted ESLint for changed SPA files; `git diff --check`; and `StatementMoneyFormatterTest` (3 tests, 7 assertions).
- Blocked before assertions: `FinancialStatementBoundaryTest` and other database-backed feature checks could not resolve PostgreSQL host `db` in the execution environment.
- The full SPA typecheck remains blocked by a pre-existing syntax error in `spa/src/pages/crm/sales-orders/create.tsx:169,238,308,321,331,416`, outside M029. Targeted ESLint passed for the changed M029 SPA files.

### Deferred

- **M029-F02 remains pending:** the balance-sheet fiscal-year rollover/retained-earnings behavior needs an explicit accounting policy decision. The repository has no year-end close/carry-forward contract, so no financial state-machine or balance-sheet semantics were guessed. The next session must decide and implement either controlled annual P&L close into retained earnings or a documented cumulative closed-period calculation, then add January/non-January rollover tests.
- Because F02 is a P0 financial-policy blocker, M029 is released as `🔁 Needs Re-audit` rather than `✅ Verified`; F01 and F03-F09 changes are ready for database-backed endpoint/PDF verification after the policy decision and a working PostgreSQL service.

## 2026-08-25 continuation session

### Code changes

- **M029-F03 — balance-sheet PDF request accessor:** `api/app/Modules/Accounting/Controllers/PdfController.php:40` now calls `StatementAsOfRequest::asOfDate()`. The prior implementation called nonexistent `date()`, so an authorized balance-sheet PDF request would fail before rendering.
- **M029-F06 — aging CSV total-row parity:** `api/app/Modules/Accounting/Controllers/FinancialStatementController.php:124,148` now include the `d91_plus` bucket in AR/AP total rows, bringing each total row to the same nine columns as its header and account rows.

### Verification

- Passed: `php -l` for the changed statement controllers, request objects, PDF service, and money formatter; `php artisan view:cache`; `StatementMoneyFormatterTest` (3 tests, 7 assertions); targeted ESLint for the five statement pages, finance dashboard, and Finance sidebar; and `git diff --check` for the module surface.
- Blocked before assertions: `FinancialStatementBoundaryTest`, `StatementServicesTest`, and `AgingReportTest` could not connect because PostgreSQL host `db` could not be resolved (`SQLSTATE[08006]`); 8 tests ran with 0 assertions.

### Deferred

- **M029-F02 remains pending:** `api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:75-90` still synthesizes only the current fiscal year's net income, while `api/app/Modules/Accounting/routes.php:34-38` exposes monthly close/reopen only. The repository has no annual P&L close/carry-forward contract beyond the retained-earnings account seed, so choosing controlled retained-earnings closing versus cumulative closed-period reporting requires an explicit accounting-policy decision. No financial semantics were guessed.
- M029 remains `🔁 Needs Re-audit`; F01 and F03-F09 are implemented and statically checked, but F02 is a P0 financial-policy blocker and database-backed endpoint/PDF verification remains unavailable until PostgreSQL is reachable.

## 2026-08-25 re-audit session

### Re-check of implemented plan items

- **M029-F01 — export authorization:** verified the current controller boundary at `api/app/Modules/Accounting/Controllers/FinancialStatementController.php:30-53,56-77,80-102,110-152,160-177` and PDF boundary at `api/app/Modules/Accounting/Controllers/PdfController.php:23-47`. CSV requests and all statement PDFs require `accounting.statements.export`; JSON remains available through the view permission. The direct route middleware and generic aging export mapping remain present at `api/app/Modules/Accounting/routes.php:122-132` and `api/app/Common/Controllers/ExportController.php:121-145`. SPA export actions are gated at `spa/src/pages/accounting/trial-balance.tsx:36-41`, `income-statement.tsx:39-44`, `balance-sheet.tsx:32-37`, `ar-aging.tsx:33-37`, and `ap-aging.tsx:33-37`.
- **M029-F03 — strict date contract:** verified the shared range/as-of requests at `api/app/Modules/Accounting/Requests/StatementDateRangeRequest.php:18-64` and `StatementAsOfRequest.php:17-32`, and their use by JSON/CSV/PDF controllers at `FinancialStatementController.php:30-34,56-60,80-84,110-114,134-138` and `PdfController.php:23-41`.
- **M029-F04 — money-safe PDFs:** verified `api/app/Modules/Accounting/Services/StatementMoneyFormatter.php:16-41`, its injection at `api/app/Modules/Accounting/Services/PdfService.php:81-140`, and all three templates at `api/resources/views/pdf/trial-balance.blade.php:33-40`, `income-statement.blade.php:22-42`, and `balance-sheet.blade.php:25-41`; no statement template casts values to float.
- **M029-F05 — functional currency:** verified API/CSV currency metadata at `api/app/Modules/Accounting/Controllers/FinancialStatementController.php:35-36,61-62,85-86,115-116,139-140`, PDF currency injection at `api/app/Modules/Accounting/Services/PdfService.php:81-103,130-140`, and runtime-currency UI formatting via `spa/src/lib/formatNumber.ts:25-32` and the report headers at `spa/src/pages/accounting/*.tsx:29-36`.
- **M029-F06 — CSV reconciliation metadata:** verified trial-balance totals/status rows at `FinancialStatementController.php:38-51`, balance-sheet equation/status rows at `:88-100`, and nine-column AR/AP total rows at `:118-150`.
- **M029-F07 — regression coverage:** verified the new boundary/precision tests at `api/tests/Feature/Accounting/FinancialStatementBoundaryTest.php:24-82` and `api/tests/Unit/Accounting/StatementMoneyFormatterTest.php:13-36`, plus the current-year balance-sheet invariant at `api/tests/Feature/Accounting/StatementServicesTest.php:84-88`. Database-backed execution remains blocked by the unavailable PostgreSQL host noted below.
- **M029-F08 — discoverability:** verified real dashboard links at `spa/src/pages/dashboard/finance.tsx:185-206` and permission-gated Finance sidebar entries at `spa/src/components/layout/Sidebar.tsx:510-544`.
- **M029-F09 — responsive/accessibility polish:** verified horizontal overflow/minimum-width wrappers and semantic headers in the five report pages, including `spa/src/pages/accounting/trial-balance.tsx:54-65`, `income-statement.tsx:56-61`, `balance-sheet.tsx:64-69`, `ar-aging.tsx:49-62`, and `ap-aging.tsx:49-62`; the balance-sheet summary uses responsive spans at `balance-sheet.tsx:48-55`.

### Verification

- Passed: `php -l` for all changed M029 PHP classes/tests; `php artisan test --filter=StatementMoneyFormatterTest` (3 tests, 7 assertions); targeted ESLint for the five report pages, finance dashboard, and Finance sidebar; and `git diff --check` for the M029 surface.
- Blocked before assertions: `FinancialStatementBoundaryTest` (3 tests), `StatementServicesTest` (1 test), and `AgingReportTest` (4 tests) all failed during database bootstrap with `SQLSTATE[08006]` because PostgreSQL host `db` could not be resolved.

### Remaining blocker

- **M029-F02 remains pending:** `api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:75-90` still adds only current-fiscal-year net income, while `api/app/Modules/Accounting/routes.php:34-38` exposes monthly close/reopen only. The only retained-earnings evidence is the account seed at `api/database/seeders/ChartOfAccountsSeeder.php:71`; no annual P&L close/carry-forward contract exists. Implementing this requires an explicit choice between controlled annual close into retained earnings and cumulative closed-period reporting, so no financial semantics were guessed.
- No production-code changes were made in this session. M029 remains `🔁 Needs Re-audit` because F02 is a P0 accounting-policy decision and endpoint/PDF verification still needs a reachable PostgreSQL service.

## 2026-08-25 current module-audit session

### Re-check of implemented plan items

- **M029-F01 — export authorization:** the JSON/CSV split remains enforced by `api/app/Modules/Accounting/Controllers/FinancialStatementController.php:30-53,56-77,80-102,110-177` and the PDF boundary by `api/app/Modules/Accounting/Controllers/PdfController.php:23-47`; the direct statement routes remain view-gated while export responses perform the dedicated export check at `api/app/Modules/Accounting/routes.php:121-132`.
- **M029-F03 — strict date contract:** `StatementDateRangeRequest` and `StatementAsOfRequest` still enforce strict `Y-m-d` input and reversed-range rejection at `api/app/Modules/Accounting/Requests/StatementDateRangeRequest.php:18-64` and `StatementAsOfRequest.php:17-32`; all JSON/CSV/PDF statement controllers use those request accessors.
- **M029-F04 — money-safe PDFs:** `api/app/Modules/Accounting/Services/StatementMoneyFormatter.php:16-41` remains string/BCMath-based, is injected by `PdfService.php:28-34,81-140`, and the three statement templates use it without float casts at `api/resources/views/pdf/{trial-balance,income-statement,balance-sheet}.blade.php`.
- **M029-F05/F06 — currency and export contracts:** controller responses and CSV rows carry currency and reconciliation metadata at `api/app/Modules/Accounting/Controllers/FinancialStatementController.php:35-51,61-75,85-100,115-150`; PDFs receive currency and the formatter through `api/app/Modules/Accounting/Services/PdfService.php:81-103,130-140`.
- **M029-F07 — regression coverage:** the boundary/precision tests remain at `api/tests/Feature/Accounting/FinancialStatementBoundaryTest.php:24-82` and `api/tests/Unit/Accounting/StatementMoneyFormatterTest.php:13-36`, with the current-year balance-sheet invariant at `api/tests/Feature/Accounting/StatementServicesTest.php:84-88`.
- **M029-F08/F09 — discoverability and polish:** dashboard/sidebar links remain at `spa/src/pages/dashboard/finance.tsx:185-206` and `spa/src/components/layout/Sidebar.tsx:510-544`; the five report pages retain responsive wrappers and semantic headers, including `spa/src/pages/accounting/trial-balance.tsx:54-65` and `income-statement.tsx:56-61`.

### Verification

- Passed: `php -l` for the changed M029 PHP classes/tests; `php artisan test --filter=StatementMoneyFormatterTest` (3 tests, 7 assertions); targeted ESLint for the five statement pages, finance dashboard, Finance sidebar, and runtime number formatter; and `git diff --check` for the M029 surface.
- Blocked before assertions: `FinancialStatementBoundaryTest`, `StatementServicesTest`, and `AgingReportTest` (8 tests) could not bootstrap because PostgreSQL host `db` could not be resolved (`SQLSTATE[08006]`).

### Deferred blocker

- **M029-F02 remains pending:** `api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:75-90` still adds only current-fiscal-year net income, while `api/app/Modules/Accounting/routes.php:34-38` provides monthly close/reopen only. The repository exposes the retained-earnings account and fiscal start month but no annual close/carry-forward contract. Choosing controlled annual P&L close into retained earnings versus cumulative closed-period reporting requires an explicit accounting-policy decision; no financial semantics were guessed.
- No production-code changes were made in this session. M029 remains `🔁 Needs Re-audit` until F02 is decided and database-backed endpoint/PDF verification is possible.

## 2026-08-25 resumed module-audit session

### Session claim

- Refreshed the registry and atomically claimed unlocked M029 `finance/financial-statements` because it was the first available `🔁 Needs Re-audit` candidate; locked re-audit candidates were skipped.
- Read the existing `audit-report.md` and `action-plan.md` in full. The plan was not rewritten and no discovery pass was repeated.

### Re-check of implemented plan items

- **M029-F01 — export authorization:** re-confirmed the JSON/CSV split and PDF export boundary in `api/app/Modules/Accounting/Controllers/FinancialStatementController.php:30-53,56-77,80-102,110-177`, `PdfController.php:23-47`, and `api/app/Modules/Accounting/routes.php:121-132`; report-page export controls remain permission-gated.
- **M029-F03 — date contract:** re-confirmed strict date/range request accessors in `api/app/Modules/Accounting/Requests/StatementDateRangeRequest.php:18-64` and `StatementAsOfRequest.php:17-32`, used by the JSON/CSV/PDF statement controllers.
- **M029-F04 — money-safe PDFs:** re-confirmed the string-safe formatter in `api/app/Modules/Accounting/Services/StatementMoneyFormatter.php:16-41`, PDF injection in `PdfService.php:81-140`, and absence of float casts in the three statement templates.
- **M029-F05/F06 — currency and CSV contracts:** re-confirmed currency and reconciliation metadata in `FinancialStatementController.php:35-51,61-75,85-100,115-150` and PDF currency/formatter injection in `PdfService.php:81-103,130-140`.
- **M029-F07 — regression coverage:** re-confirmed the boundary, precision, current-year balance-sheet, and aging CSV assertions in `api/tests/Feature/Accounting/FinancialStatementBoundaryTest.php:24-82`, `api/tests/Unit/Accounting/StatementMoneyFormatterTest.php:13-36`, `StatementServicesTest.php:84-88`, and `AgingReportTest.php:123`.
- **M029-F08/F09 — discoverability and polish:** re-confirmed dashboard/sidebar links and responsive/semantic table changes in `spa/src/pages/dashboard/finance.tsx:185-206`, `spa/src/components/layout/Sidebar.tsx:510-544`, and the five statement pages.

### Verification

- Passed: targeted `php -l` for changed M029 PHP classes/tests; `php artisan view:cache`; `php artisan test --filter=StatementMoneyFormatterTest` (3 tests, 7 assertions); targeted ESLint for the five statement pages, finance dashboard, Finance sidebar, and runtime number formatter; and scoped `git diff --check`.
- Blocked before assertions: `FinancialStatementBoundaryTest` failed during database bootstrap with `SQLSTATE[08006]` because PostgreSQL host `db` could not be resolved; the combined statement/aging feature run was therefore not executable in this environment.

### Deferred blocker

- **M029-F02 remains pending:** `api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:75-90` synthesizes only current-fiscal-year net income. `AccountingPeriodService::close()` and `::reopen()` at `api/app/Modules/Accounting/Services/AccountingPeriodService.php:51-120` only manage monthly posting locks, while `api/app/Modules/Accounting/Models/FiscalYear.php:23-50` has no close/carry-forward behavior. The retained-earnings account seed is not an accounting policy. Choosing controlled annual P&L close into retained earnings versus cumulative closed-period reporting requires human accounting-policy input, so no financial semantics were guessed.
- No production-code changes were made in this resumed session. M029 remains `🔁 Needs Re-audit`; F01 and F03-F09 remain implemented but still need database-backed endpoint/PDF verification after PostgreSQL is reachable and F02 is decided.
