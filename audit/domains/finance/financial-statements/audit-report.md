# M029 — Financial Statements audit report

Audit date: 2026-08-27
Actual claim: fallback finance/financial-statements (M029)
Status: 📋 Plan Ready  
Release recommendation: separate implementation work required

## Scope and claim

The preferred claim attempt for commercial/customer-complaints-8d (M034) failed with:

    ERROR: module folder not found: /home/kwat0g/Desktop/kwatog/audit/domains/commercial/customer-complaints-8d/M034

Per the assigned-card fallback, this session claimed finance financial-statements, which returned CLAIMED. The fresh registry, existing audit artifacts, current git diff, and file modification times were inspected before relying on prior status. The generated registry and dependency modules were not modified.

This audit covers M029's statement services, HTTP routes/controllers, exports/PDFs, SPA report surfaces, navigation, and focused tests. The AR/AP calculation services were read only for contract context; they were not audited or changed.

## Discovery pass

The module is implemented rather than a stub. It contains:

- Trial balance, income statement, and balance sheet services that aggregate posted journal entries with decimal-string money operations (api/app/Modules/Accounting/Services/Statements/TrialBalanceService.php:30-84, IncomeStatementService.php:30-90, BalanceSheetService.php:39-100).
- JSON, CSV, and PDF statement routes plus AR/AP aging routes. All report reads are behind authentication, the accounting feature gate, and the statement-view permission; the dedicated AP aging export route is export-gated (api/app/Modules/Accounting/routes.php:20-22, :121-133).
- A configurable functional currency used by the controller and PDF service (api/app/Modules/Accounting/Controllers/FinancialStatementController.php:174-177, api/app/Modules/Accounting/Services/PdfService.php:107-121).
- Typed SPA API helpers and report pages for all five report surfaces (spa/src/api/accounting/statements.ts:5-26, spa/src/types/accounting.ts:331-390, spa/src/pages/accounting/trial-balance.tsx:14-84, income-statement.tsx:16-86, balance-sheet.tsx:15-77, ar-aging.tsx:14-87, ap-aging.tsx:14-87).
- Finance dashboard and sidebar links to the report routes (spa/src/pages/dashboard/finance.tsx:185-206, spa/src/components/layout/Sidebar.tsx:533-565).

Prior audit artifacts were not accepted as proof without re-checking. Earlier fixes for export authorization, strict dates, string-safe PDFs, dynamic currency, CSV metadata, report links, and table structure were re-read and exercised where possible.

## Hardening pass

### M029-F02 — P0, Broken: fiscal-year rollover drops prior-period income from equity

Evidence:

- The balance-sheet query accumulates posted asset, liability, and equity lines through the selected date (api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:39-52).
- It then adds synthetic net income only from the current fiscal-year start through the selected date (api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:75-87).
- The result exposes balanced rather than repairing or blocking a rollover mismatch (api/app/Modules/Accounting/Services/Statements/BalanceSheetService.php:89-100).
- Period routes close/reopen individual months; no annual P&L close or retained-earnings carry-forward is implemented (api/app/Modules/Accounting/routes.php:34-39). The seeded retained-earnings account is a normal equity account (api/database/seeders/ChartOfAccountsSeeder.php:69-75).

An isolated probe on the dedicated ogami_test_m034_agent_a database posted a valid FY2025 cash sale for 10,000.00. The report returned assets 10,000.00, liabilities plus equity 10,000.00, balanced=true at 2025-12-31, then assets 10,000.00, liabilities plus equity 0.00, balanced=false at 2026-04-30. A clean posted ledger therefore becomes visibly unbalanced at fiscal-year rollover unless an undocumented manual closing entry is posted.

Impact: the balance sheet can report a false financial imbalance immediately after a fiscal boundary, or omit prior-period earnings from equity.

Required action: decide and implement the annual-close policy—controlled posting to retained earnings or a correct cumulative closed-period calculation—then add rollover, non-January fiscal-year, closing-entry, repeat-close, and genuine-imbalance tests. This is large and separate-recommended.

### M029-F06 — P1, Incomplete: CSV metadata places non-money values in money columns

Evidence:

- Trial-balance CSV adds a status row whose true/false value is placed in the eighth field while the header names that field Balance (api/app/Modules/Accounting/Controllers/FinancialStatementController.php:40-55).
- Balance-sheet CSV adds a Balanced row with a boolean string in the Amount field (api/app/Modules/Accounting/Controllers/FinancialStatementController.php:96-106).
- The typed JSON balance-sheet contract keeps balanced separate from monetary fields (spa/src/types/accounting.ts:362-370).

Impact: downstream CSV consumers that parse Balance or Amount as decimals must special-case metadata rows, and JSON/CSV/PDF exports do not share a self-consistent schema. The trial-balance flag is also always true after the service's imbalance exception (api/app/Modules/Accounting/Services/Statements/TrialBalanceService.php:74-77), so its current placement provides little value.

Required action: publish an explicit CSV schema with a dedicated flag/value field or separate metadata section, and test all statement CSV widths, totals, reconciliation/status fields, and JSON/CSV/PDF parity. This is medium and separate-recommended because it changes an export contract.

### M029-F07 — P1, Missing: statement regression coverage does not match the risk surface

Evidence:

- The current service test covers one balanced ledger and trial-balance/income-statement happy paths (api/tests/Feature/Accounting/StatementServicesTest.php:31-88).
- Boundary tests now cover selected authorization, date, CSV, and PDF cases, but not fiscal rollover or the full export matrix (api/tests/Feature/Accounting/FinancialStatementBoundaryTest.php:27-136).
- Aging tests cover AR/AP JSON and AR CSV, but not the dedicated AP export route (api/tests/Feature/Accounting/AgingReportTest.php:41-184).

Missing checks include income-statement and balance-sheet CSV contracts, dedicated AP export, non-PHP currency, cache invalidation, empty/no-posted-entry paths, rollover, and browser accessibility/responsive behavior. This is medium and separate-recommended.

### M029-F10 — P1, Broken: CSV exports permit spreadsheet formula injection

Evidence:

- Customer and vendor names accept arbitrary leading =, +, -, or @ characters because their request rules only constrain type and length (api/app/Modules/Sales/Requests/StoreCustomerRequest.php:16-27, api/app/Modules/Purchasing/Requests/StoreVendorRequest.php:16-26).
- Aging CSV rows place those names directly into fputcsv output (api/app/Modules/Accounting/Controllers/FinancialStatementController.php:125-156).
- The shared writer does not neutralize formula-leading cell values (api/app/Modules/Accounting/Controllers/FinancialStatementController.php:186-195).

Impact: opening a downloaded aging CSV in a spreadsheet can interpret attacker-controlled customer/vendor names as formulas.

Required action: define a central CSV-cell neutralization policy for user-controlled text, preserve ordinary text and leading whitespace semantics, and add formula-prefix regression tests for AR/AP exports. This is medium and separate-recommended because it affects a shared export boundary.

## Polish pass

### M029-F11 — P1, Incomplete: SPA date defaults can shift dates and ignore the configured fiscal year

Evidence:

- The report pages derive date defaults with new Date(...).toISOString().slice(0, 10) (spa/src/pages/accounting/trial-balance.tsx:17-22, income-statement.tsx:19-25, balance-sheet.tsx:18, ar-aging.tsx:17, ap-aging.tsx:17). In the configured Asia/Manila application timezone (api/config/app.php:15), local-midnight values can serialize to the preceding UTC date.
- Income Statement defaults to January 1 through December 31 rather than the configured fiscal start month (spa/src/pages/accounting/income-statement.tsx:19-25, api/database/seeders/SettingsSeeder.php:78-82).
- The backend date contract explicitly parses strict Y-m-d values in the application timezone (api/app/Modules/Accounting/Requests/StatementDateRangeRequest.php:51-65), so the frontend and backend can disagree about the intended boundary.

Impact: a user can request a report for the wrong day near timezone boundaries, and the default income statement period can omit or include the wrong fiscal months.

Required action: use a date-only formatter for input defaults, derive fiscal-year defaults from the fiscal setting, and add Manila-boundary and non-January-fiscal-year tests. This is medium and separate-recommended.

### M029-F12 — P2, Incomplete: AR and AP aging response metadata is asymmetric

Evidence:

- AR aging returns as_of, buckets, and customer rows (api/app/Modules/Sales/Services/InvoiceService.php:539-543).
- AP aging returns buckets and vendor rows but no as_of (api/app/Modules/Purchasing/Services/BillService.php:758-765, :825).
- The controller adds currency to AP aging without adding the missing date (api/app/Modules/Accounting/Controllers/FinancialStatementController.php:140-158), while the shared aging types omit an as_of field for both report shapes (spa/src/types/accounting.ts:382-390).

Impact: consumers cannot reliably display the effective report date, and AR/AP screens have different server metadata despite presenting the same aging concept.

Required action: approve one aging response contract and expose the effective date consistently without modifying the separately owned calculation services in this audit. This is small and same-session-ok after contract approval.

### M029-F13 — P2, Polish: export authorization is duplicated in controller bodies

Evidence:

- The project guidance requires action permissions through middleware/FormRequest authorization rather than controller-body checks (CLAUDE.md:184-188, :259-264).
- CSV authorization is implemented in a controller helper (api/app/Modules/Accounting/Controllers/FinancialStatementController.php:162-184), and PDF authorization is duplicated in PdfController (api/app/Modules/Accounting/Controllers/PdfController.php:44-47).

Impact: the current checks work for the exercised paths, but the policy is easier to bypass or regress when a new format or route is added.

Required action: consolidate the export policy at the route/request boundary and keep controller actions focused on rendering. This is small but separate-recommended because it changes a security boundary.

## Re-checked prior findings

- Export permission behavior (historical F01): route listing and focused HTTP tests confirm view-only JSON succeeds while CSV/PDF exports require accounting.statements.export; the dedicated AP export route is export-gated (api/app/Modules/Accounting/routes.php:121-133).
- Strict date behavior (historical F03): current FormRequests reject malformed and reversed dates for the tested JSON/PDF paths (api/app/Modules/Accounting/Requests/StatementDateRangeRequest.php:18-39, StatementAsOfRequest.php:18-32).
- String-safe PDF money formatting (historical F04) and configured currency propagation (historical F05): current templates use the formatter and dynamic currency (api/app/Modules/Accounting/Services/PdfService.php:65-85, :107-121; api/resources/views/pdf/trial-balance.blade.php:33-40, income-statement.blade.php:22-42, balance-sheet.blade.php:25-41).
- Dashboard/sidebar report links and table semantics (historical F08/F09): source links, overflow wrappers, and headers are present in the inspected pages. Browser-level verification remains part of F07's deferred matrix.

## Verification

- Focused PHPUnit: 12 tests, 69 assertions, passed on DB_DATABASE=ogami_test_m034_agent_a; the shared ogami_test database was not used.
- Isolated balance-sheet rollover probe: reproduced the 10,000.00 prior-year mismatch described in F02 on the same dedicated database.
- PHP lint: clean for the inspected accounting routes, controllers, requests, services, and focused tests.
- php artisan route:list --path=accounting/statements -v: passed; all nine statement routes and middleware were inspected.
- Targeted SPA ESLint with --max-warnings 0: passed for the five report pages, API/types, dashboard/sidebar, and currency helper.
- git diff --check: passed for the scoped audit work.
- SPA API route audit exited 1 only for two unrelated pre-existing HR endpoints (spa/src/api/hr/employee-skills.ts:29, employee-trainings.ts:20); no M029 request was reported.
- Full suite, browser/Playwright, and broad typecheck were not run; the coordinator owns those checks.

## Gate and release decision

No production-code fixes were applied. Only F12 is a contained same-session candidate; the majority of the plan is separate-recommended, including the P0 fiscal-year accounting defect, export/security contracts, frontend date semantics, and regression matrix. The total scope is not small. The gate therefore requires 📋 Plan Ready.

The module audit artifacts and findings below are ready for a separate implementation session. The lock will be released after the artifacts are committed.
