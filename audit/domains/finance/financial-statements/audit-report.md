# M029 — Financial Statements audit report

Audit date: 2026-08-24  
Status: 📋 Plan Ready  
Release recommendation: separate implementation work required

Production audit: 48/100, blocked by an unenforced export boundary, a fiscal-year balance-sheet correctness gap, unsafe PDF money formatting, and missing endpoint-level regression coverage.

## Executive assessment

The module is implemented rather than stubbed. It has trial-balance, income-statement, and balance-sheet services; JSON, CSV, and PDF endpoints; five internal report screens including AR/AP aging; shared accounting types/API helpers; and a finance-dashboard entry point. The core services correctly read posted journal entries and use string-based `Money` arithmetic for their main calculations. Routes are authenticated, feature-gated, and protected by the statement-view permission.

The release risks are at the report boundary. The dedicated `accounting.statements.export` permission is catalogued but is not applied to the direct CSV/PDF routes or their buttons. Balance-sheet equity only receives current-fiscal-year net income, with no implemented year-end close/carry-forward contract. PDF templates convert decimal strings to floats and one template hardcodes PHP despite a configurable functional currency. The test suite covers one service happy path, but not the balance-sheet invariant, HTTP authorization, export parity, date errors, or PDF output.

AR/AP aging calculations are delegated to the invoice and bill services and have already been separately audited under M028/M027. This audit covers their shared statement route/export/date boundary and the internal screen contract, not a duplicate historical aging audit.

## Discovery

### Implemented surface

- `TrialBalanceService`, `IncomeStatementService`, and `BalanceSheetService` aggregate posted journal-entry lines, return decimal-string payloads, and cache under the `financial_statements` tag (`api/app/Modules/Accounting/Services/Statements/TrialBalanceService.php:26-85`, `IncomeStatementService.php:25-91`, `BalanceSheetService.php:34-101`).
- `FinancialStatementController` exposes JSON and `?format=csv` for the three statements plus AR/AP aging (`api/app/Modules/Accounting/Controllers/FinancialStatementController.php:27-128`). `PdfController` and `PdfService` expose the three statement PDFs (`api/app/Modules/Accounting/Controllers/PdfController.php:22-39`, `api/app/Modules/Accounting/Services/PdfService.php:69-122`).
- The SPA has a typed adapter and pages for trial balance, income statement, balance sheet, AR aging, and AP aging (`spa/src/api/accounting/statements.ts:8-26`, `spa/src/pages/accounting/trial-balance.tsx:14-84`, `income-statement.tsx:16-86`, `balance-sheet.tsx:15-77`, `ar-aging.tsx:14-87`, `ap-aging.tsx:14-87`). Routes guard all of those pages with `accounting.statements.view` (`spa/src/routes/accountingRoutes.tsx:91-100`).
- `StatementServicesTest` creates and posts two balanced entries, then verifies trial-balance reconciliation and income-statement revenue/net income (`api/tests/Feature/Accounting/StatementServicesTest.php:30-82`). There is no corresponding balance-sheet, controller, export, PDF, or permission test in this file.

### Not implemented / not evidenced

- No dedicated request object or explicit date-range contract exists for statement JSON, CSV, or PDF inputs; controllers call `Carbon::parse` directly.
- No implemented statement-specific year-end close or retained-earnings carry-forward path was found. The accounting-period routes only close/reopen individual periods (`api/app/Modules/Accounting/routes.php:33-38`).
- No statement navigation item exists in the Finance sidebar (`spa/src/components/layout/Sidebar.tsx:457-523`). The dashboard is intended to be the entry point, but its statement panel is still placeholder text (F08 below).

## Findings

### M029-F01 — P0, Broken: direct statement exports bypass the dedicated export permission

Evidence: all three JSON/CSV-capable statement routes and all three PDF routes use only `accounting.statements.view` (`api/app/Modules/Accounting/routes.php:109-116`). The AR/AP aging routes also use only the view permission even when `?format=csv` is requested (`api/app/Modules/Accounting/routes.php:117-120`). The permission catalog explicitly defines a separate `accounting.statements.export` capability (`api/database/seeders/RolePermissionSeeder.php:183-185`), and the generic export controller maps accounting aging exports to that capability (`api/app/Common/Controllers/ExportController.php:121-145`). The SPA renders CSV/PDF buttons for view-only pages without checking the export grant (`spa/src/pages/accounting/trial-balance.tsx:29-37`, `income-statement.tsx:32-40`, `balance-sheet.tsx:25-34`, `ar-aging.tsx:25-33`, `ap-aging.tsx:25-33`).

Impact: any user granted statement view can download sensitive financial data, regardless of the intended view/export separation. The UI also promises an export action to users for whom that action should be unavailable.

Action: authorize JSON reads with `accounting.statements.view` and CSV/PDF responses with `accounting.statements.export`; gate or hide the SPA export controls with the same capability. Add direct API tests for view-only JSON, view-only export rejection, and authorized CSV/PDF downloads. Separate implementation is required because this changes RBAC policy.

### M029-F02 — P0, Broken: balance-sheet equity loses prior-fiscal-year income without a close/carry-forward contract

Evidence: the balance-sheet query accumulates every posted asset, liability, and equity line through the selected date (`api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:39-52`). It then adds only income from the current fiscal year start through the selected date (`:75-87`), while the returned `balanced` flag is merely a boolean and the endpoint still returns the report (`:89-100`). The chart seeds retained earnings as a normal equity account (`api/database/seeders/ChartOfAccountsSeeder.php:69-72`), but the available accounting-period routes only close/reopen individual periods and do not perform an annual P&L close (`api/app/Modules/Accounting/routes.php:33-38`).

Impact: a balanced prior-year entry such as DR Cash / CR Revenue can leave cash in the cumulative balance-sheet totals after year rollover while its prior-year revenue is no longer included in synthetic current-period net income. Unless an operator manually posts a retained-earnings close, the report returns an unbalanced balance sheet for a valid posted ledger. There is no documented or tested contract saying that manual closing JEs are mandatory.

Action: define the fiscal-year policy first. Either implement a controlled year-end close/carry-forward into retained earnings, or make the balance-sheet calculation include the correct cumulative closed-period result. Add hand-calculated tests for prior-year income, current-year income, non-January fiscal starts, closing entries, and an intentionally unbalanced ledger. Separate, large financial implementation is required.

### M029-F03 — P1, Incomplete: statement date inputs have no strict validation or range invariant

Evidence: JSON/CSV trial balance and income statement requests use raw `Carbon::parse` and return a range without checking `from <= to` (`api/app/Modules/Accounting/Controllers/FinancialStatementController.php:27-59`, `:131-136`). Balance sheet, AR aging, and AP aging do the same for `as_of` (`:62-80`, `:88-128`). PDF endpoints duplicate the raw parsing (`api/app/Modules/Accounting/Controllers/PdfController.php:22-39`).

Impact: malformed or ambiguous dates can become an exception/500 path instead of a stable 422 response, and a reversed range silently returns an empty report with a successful response. Direct API callers are not constrained to the browser's native date input.

Action: add request validation for `Y-m-d`, define application timezone semantics, reject `from > to`, and use the same contract for JSON, CSV, and PDF. Add invalid-date, reversed-range, and boundary-date tests. Separate implementation is recommended because the input contract drives financial report calculations.

### M029-F04 — P1, Broken: statement PDFs convert money strings to floats

Evidence: the repository money convention explicitly requires string arithmetic and says never to use floats for money (`api/app/Common/Support/Money.php:7-11`). Every statement PDF template casts decimal strings to `float` before formatting: trial balance (`api/resources/views/pdf/trial-balance.blade.php:24-36`), income statement (`api/resources/views/pdf/income-statement.blade.php:17-38`), and balance sheet (`api/resources/views/pdf/balance-sheet.blade.php:20-37`).

Impact: large balances can lose cent precision or display a rounded value different from the JSON/CSV source. The PDF becomes a second, float-based representation of a financial report.

Action: introduce or reuse a string-safe money formatter for PDF output, keep the source values as decimal strings, and add large-value/negative-value/half-cent rendering tests. Separate implementation is required because it changes financial presentation and shared money handling.

### M029-F05 — P1, Incomplete: statement currency is not consistently carried into exports

Evidence: the functional currency is a persisted, admin-configurable accounting setting (`api/database/seeders/SettingsSeeder.php:408`, `api/app/Modules/Admin/Requests/UpdateSettingRequest.php:350-351`). `PdfService` passes statement data, company, and user to the three templates but no currency code (`api/app/Modules/Accounting/Services/PdfService.php:69-88`, `:114-122`). The income-statement PDF hardcodes `PHP` (`api/resources/views/pdf/income-statement.blade.php:38`), while the trial-balance and balance-sheet PDFs show bare numbers (`trial-balance.blade.php:29-30`, `balance-sheet.blade.php:21-37`). CSV rows likewise contain amounts without a currency field (`api/app/Modules/Accounting/Controllers/FinancialStatementController.php:32-36`, `:69-78`).

Impact: changing the configured functional currency can produce a mislabeled income-statement PDF and ambiguous trial-balance/balance-sheet/CSV files. A downloaded report is not self-describing for an auditor or external recipient.

Action: make the statement response/export contract carry the configured currency code, pass it to all PDF views, remove the hardcoded PHP label, and render a consistent currency header/metadata in JSON-facing UI, CSV, and PDF. Separate implementation is recommended because it crosses API/export presentation and financial policy.

### M029-F06 — P1, Incomplete: CSV exports omit reconciliation totals and status metadata

Evidence: trial-balance CSV maps only account rows and never appends the service's `totals` values (`api/app/Modules/Accounting/Controllers/FinancialStatementController.php:32-36`; service totals are returned at `api/app/Modules/Accounting/Services/Statements/TrialBalanceService.php:79-84`). Balance-sheet CSV appends section totals but omits `total_liabilities_equity` and `balanced` (`FinancialStatementController.php:69-80`; service fields at `BalanceSheetService.php:92-100`). The PDFs do include their relevant totals (`api/resources/views/pdf/trial-balance.blade.php:33-36`, `balance-sheet.blade.php:23-37`).

Impact: exported trial balances cannot directly prove debit/credit reconciliation, and exported balance sheets cannot carry the equation result or imbalance indicator. Consumers comparing JSON/PDF/CSV receive different report contracts.

Action: define CSV schemas with explicit total/reconciliation rows or metadata, include the balance-sheet equation and status, and add JSON/CSV/PDF parity tests. Separate implementation is recommended because it changes a financial export contract.

### M029-F07 — P1, Missing: critical statement invariants and authorization paths lack regression tests

Evidence: the only focused statement test covers one happy-path trial balance and income statement (`api/tests/Feature/Accounting/StatementServicesTest.php:30-82`). It does not exercise balance-sheet generation, fiscal-year rollover, malformed/reversed dates, CSV totals, PDF output, the direct export routes, or view-versus-export permissions. The shared aging test covers only aging view/CSV behavior and does not cover the three core statement routes (`api/tests/Feature/Accounting/AgingReportTest.php:41-140`).

Impact: the highest-risk report behavior can regress while the existing statement test remains green. In particular, the dedicated export permission can be removed from direct routes without a failing test.

Action: add controller-level authorization tests, service invariant fixtures, date validation tests, all three CSV/PDF contract tests, currency/precision tests, and a role matrix for view-only versus export-capable users. Separate implementation is recommended because the suite must cover the financial and RBAC changes above.

### M029-F08 — P2, Incomplete: finance-dashboard statement links are placeholder text and statements are absent from navigation

Evidence: the finance dashboard claims to provide a Financial Statements entry point but renders the same literal `Trial Balance →` text five times, with no links or actions (`spa/src/pages/dashboard/finance.tsx:185-195`). The Finance sidebar contains accounting operational pages but no trial balance, income statement, balance sheet, AR aging, or AP aging item (`spa/src/components/layout/Sidebar.tsx:457-523`), even though all five routes exist (`spa/src/routes/accountingRoutes.tsx:91-100`).

Impact: users cannot discover the income statement, balance sheet, or aging reports through the intended internal navigation, and the visible dashboard panel reads as unfinished UI.

Action: replace the placeholder with permission-aware links for each available report, or add statement navigation items with the same permission/feature gates. Add a dashboard render/navigation test for finance officer and system administrator. `small`; `same-session-ok` for a contained UI session, but deferred here because the overall plan is dominated by separate-recommended findings.

### M029-F09 — P2, Polish: report tables miss responsive and accessibility details from the design system

Evidence: the design system requires right-aligned mono numbers and semantic table headers (`docs/DESIGN-SYSTEM.md:435-447`, `:523-531`). Trial balance and AR/AP aging render wide tables inside `overflow-hidden` containers without an `overflow-x-auto` wrapper (`spa/src/pages/accounting/trial-balance.tsx:49-79`, `ar-aging.tsx:45-81`, `ap-aging.tsx:45-81`). Income statement and balance sheet use body-only tables without `<thead>`/`<th scope="col">` (`spa/src/pages/accounting/income-statement.tsx:53-64`, `balance-sheet.tsx:61-74`). Balance-sheet totals use unconditional `col-span-3` inside a grid that is one column below `sm` (`balance-sheet.tsx:45-52`).

Impact: narrow screens can clip report columns, screen readers cannot associate the amount column with a header on two reports, and the balance-sheet summary can create an awkward mobile grid span.

Action: add responsive table wrappers, semantic headers, and breakpoint-specific grid spans; verify keyboard/focus order and narrow viewport rendering. `small`; `same-session-ok` for contained SPA polish, but deferred here for the same session-decision reason.

## Strengths observed

- Statement queries include only posted journal entries and use explicit date predicates (`TrialBalanceService.php:30-43`, `IncomeStatementService.php:30-43`, `BalanceSheetService.php:39-52`).
- Core service calculations use `Money` strings rather than float arithmetic, and the trial balance fails closed when debit and credit totals disagree (`TrialBalanceService.php:45-76`).
- Journal-entry mutations flush statement caches through an observer (`api/app/Modules/Accounting/Observers/JournalEntryObserver.php:17-34`).
- Statement routes are inside authenticated accounting feature middleware and have a read permission gate (`api/app/Modules/Accounting/routes.php:20`, `:109-124`).
- SPA pages provide loading, error, retry, and empty states, and trial balance/aging tables use the shared `Th`/`Td` primitives in the main table surface.

## Evidence checked

- Current worktree and branch were inspected. Existing dirty production changes in backup, notification, landing, Docker, and database-script areas were left untouched; this audit only claimed the M029 audit directory.
- Accounting statement controllers, routes, services, PDF service/templates, permission catalog, chart/settings policy, relevant SPA routes/pages/types/API helper, dashboard/sidebar entry points, tests, and `docs/DESIGN-SYSTEM.md` were read.
- PHP syntax checks passed for the six relevant controller/service files.
- `npm run typecheck` completed successfully.
- `php artisan test --filter=StatementServicesTest` was attempted but could not bootstrap because PostgreSQL host `db` could not be resolved; it ran 0 assertions. Runtime behavior therefore remains unverified.

## Evidence missing / follow-up validation

- A working PostgreSQL service and focused endpoint test run.
- Browser validation of the five report screens at narrow and wide viewports.
- Hand-calculated fiscal-year rollover fixtures, closing-entry policy, and JSON/CSV/PDF reconciliation parity.
- Deployed permission matrix verification, including a user with statement view but no export grant.

## Release decision

No production-code fixes were applied. The majority of findings are financial calculations, money/export contracts, authorization policy, or test-boundary work and are `separate-recommended`. M029 is released as `📋 Plan Ready` with the ordered action plan below.
