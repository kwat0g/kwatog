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

## 2026-08-27 verification session (database-backed, first executed run)

This is the separate session the earlier `separate-recommended` items were waiting
for. A working PostgreSQL service was available, so the module's tests ran for the
first time. Executed on a dedicated database (`ogami_w2_fin`) so as not to tear the
schema out from under the three concurrent audit sessions.

### Root cause of the two suite failures — one shared defect, in the tests

`fputcsv()` encloses any field containing a **space** (PHP 8.3.30, verified by
probe: `fputcsv(["Row Type","Currency","Debit Total","Plain","91+","1-30"])` emits
`"Row Type",Currency,"Debit Total",Plain,91+,1-30`). Both assertions hardcoded an
**unquoted** header line, which `api/app/Modules/Accounting/Controllers/FinancialStatementController.php:181-191`
can never emit. The strings were written in a source-only session and never executed.

The exporter is correct: header labels, column order, column count (9 = 9 on every
row), bucket placement and currency were all checked against real output and are
right. No money value was reformatted or rounded to satisfy an assertion.

- Actual AR aging: `"Row Type",Currency,Customer,Current,1-30,31-60,61-90,91+,Total`
  / `Account,PHP,"Honda Cars Phils",0.00,750.00,0.00,0.00,0.00,750.00`
  / `Total,PHP,TOTAL,0.00,750.00,0.00,0.00,0.00,750.00`
- Actual trial balance: `"Row Type",Currency,Code,Name,Type,"Debit Total","Credit Total",Balance,Side`
  / `Total,PHP,,,,0.00,0.00,,` / `Status,PHP,,Reconciled,,,,true,`

### Code changes

- **Stale assertion, AR aging CSV** — `api/tests/Feature/Accounting/AgingReportTest.php:121-146`.
  Before: `assertStringContainsString('Row Type,Currency,Customer,Current,1-30', $body)`.
  After: decode the body (`str_getcsv` per line via a local `parseCsv()` helper) and
  `assertSame` the decoded header array, assert every row's width equals the header's,
  and pin both data rows in full — so `750.00` is asserted to sit in `1-30` and not in
  `Current`. Asserting decoded columns pins label *and* position (the thing a finance
  consumer depends on) instead of the writer's quoting rules.
- **Stale assertion, trial-balance CSV** — `api/tests/Feature/Accounting/FinancialStatementBoundaryTest.php:53-77`.
  Before: `assertStringContainsString('Row Type,Currency,Code,Name,Type,Debit Total,Credit Total,Balance,Side', $body)`.
  After: same decode-then-compare approach; asserts the header array, per-row width,
  and the two metadata rows in full (`['Total','PHP','','','','0.00','0.00','','']`,
  `['Status','PHP','','Reconciled','','','','true','']`), so the currency column is
  verified on every row rather than only in the header.
- **Money comparison convention** — `api/app/Modules/Accounting/Controllers/FinancialStatementController.php:44-53`.
  Before: `$data['totals']['debit'] === $data['totals']['credit'] ? 'true' : 'false'`.
  After: `Money::cmp($data['totals']['debit'], $data['totals']['credit']) === 0 ? 'true' : 'false'`
  (plus the `App\Common\Support\Money` import at `:8`). **No reported figure changes** —
  both totals are scale-2 `Money::add` accumulations today, so byte-identity happens to
  agree. Byte-comparing decimal strings would report "unreconciled" the moment a scale
  differed (`1000.0` vs `1000.00`); `BalanceSheetService.php:90` already uses `Money::cmp`
  for the same question.
- **New coverage: authorized PDF render** — `api/tests/Feature/Accounting/FinancialStatementBoundaryTest.php:42-71,74-108`.
  Only the 403 and 422 arms of the PDF path had ever been covered, which is precisely
  how the nonexistent-accessor call previously fixed at `PdfController.php:40` survived
  review. The new case posts a balanced ledger (DR Cash 12,345,678.90 / CR Capital Stock,
  plus a 5,000.00 cash sale so the income statement and the balance sheet's synthetic
  net-income line both have rows) and renders all three statement PDFs, asserting
  `application/pdf` and a `%PDF` magic header. This executes the three blade templates
  and the injected `StatementMoneyFormatter` for the first time.

### Verification — actual output

- `AgingReportTest` — **4 passed (21 assertions)**, was 1 failed / 3 passed.
- `FinancialStatementBoundaryTest` — **4 passed (32 assertions)**, was 1 failed / 2 passed
  (the 4th is the new PDF case).
- `StatementServicesTest` — **1 passed (9 assertions)**. First execution; the
  current-year balance-sheet invariant added on 2026-08-25 genuinely holds.
- `StatementMoneyFormatterTest` — **3 passed (7 assertions)**.
- `php -l` clean on all three changed files.

### Plan items now verified by execution (previously implemented-but-unexecuted)

- **F01** export authorization — verified: view-only user gets 200 on JSON, 403 on
  `?format=csv` and on the PDF route; export-capable user gets 200 on both CSV and PDF.
- **F03** date contract — verified: `from=2026-02-31` → 422 on `from`; reversed range →
  422 on `to`; `as_of=08/31/2026` → 422 on `as_of`; reversed range on the PDF route → 422.
- **F04** money-safe PDFs — formatter unit-verified and all three templates now render
  without error. Partial: no assertion reads a formatted amount back out of the PDF
  binary (DomPDF compresses its content streams), so template-level float damage would
  still not be caught by value. A rendered-HTML assertion would close that.
- **F05** currency metadata — verified: `data.currency` is `PHP` on JSON, and the
  `Currency` column carries `PHP` on every CSV row including the total/status rows.
- **F06** CSV reconciliation contract — verified for trial balance and AR aging
  (header, widths, totals, status). See the finding below on what the flag can express.
- **F07** regression matrix — improved, not complete. See gaps below.

### Findings requiring a decision (NOT taken — these change reported figures or a
published export contract)

1. **F02 is now measured, not merely reasoned.** `BalanceSheetService::generate()`
   sums asset/liability/equity cumulatively from inception
   (`BalanceSheetService.php:39-52`, `whereDate('je.date','<=',$asOf)`) but adds net
   income only for the **current** fiscal year (`:74-87`). Prior-year P&L therefore
   appears in neither place. A throwaway probe (deleted; not committed) posted one
   legitimate FY2025 cash sale of 10,000.00 and asked for two balance sheets:
   - `as_of 2025-12-31`: assets=10000.00, L+E=10000.00, balanced=**true**
   - `as_of 2026-04-30`: assets=10000.00, L+E=**0.00**, balanced=**false**
   A clean, balanced, posted ledger yields a balance sheet that is out by the whole
   prior-year result the moment it crosses a fiscal boundary. `accounting/periods`
   exposes monthly close/reopen only (`api/app/Modules/Accounting/routes.php:34-39`).
   Options, unchanged from prior sessions but now with a number attached: (a) controlled
   annual close posting P&L into the seeded retained-earnings account, or (b) have the
   report derive the cumulative closed-period result. Both change a reported figure, so
   neither was applied.
2. **The trial-balance "Reconciled" flag can never be `false`.**
   `TrialBalanceService.php:74-77` throws `LedgerImbalanceException` when
   `Money::cmp($td,$tc) !== 0`, so serialization is unreachable for an imbalanced
   ledger and the `false` arm at `FinancialStatementController.php:52` is dead. The
   metadata is not *wrong* (`true` is guaranteed at that point) but it is vacuous, and
   the F06 plan line asking for an "imbalance flag" test is unsatisfiable through HTTP
   by deliberate design — the exception's own docblock argues a 500 is the correct
   answer, and `BusinessRuleRenderingTest.php:243-252` pins that. Either drop the row
   as redundant or keep it as an explicit "checked and held" marker; it is a contract
   choice, not a bug. The balance sheet's `Balanced` row is different — it *is*
   variable and meaningful, because that service returns a flag instead of throwing.
3. **The trial-balance `Status` row puts a boolean in a money column.** Actual row:
   `Status,PHP,,Reconciled,,,,true,` — `true` lands under `Balance`, a
   `decimal(15,2)` column, and the label `Reconciled` under `Name`
   (`FinancialStatementController.php:44-53`). A downstream consumer typing the
   `Balance` column as money will fail or coerce on that row. A dedicated
   `Value`/`Flag` column, or moving the flag into `Name`, would fix it — but that is a
   breaking change to a published export shape, so it was left alone.

### Remaining gaps in F07

- Income-statement and balance-sheet **CSV** shapes have no test (only trial balance
  and AR aging are pinned). Their row widths do match their headers by inspection
  (6 = 6).
- The dedicated `GET accounting/statements/ap-aging/export` route
  (`routes.php:132`, handler at `FinancialStatementController.php:160-166`) is untested.
- Untested: non-PHP configured currency, `financial_statements` cache-tag invalidation,
  and the empty-ledger/no-posted-entry path for the aging reports.
- **F08/F09 (SPA) remain unverified.** No SPA files were touched this session and no
  browser/Playwright run was attempted — the host is shared by four sessions and the
  UI suite needs Chromium.

### Release

Released as `🔁 Needs Re-audit`. The two assigned failures are fixed and green by
execution, and F01/F03/F05/F06 are now genuinely verified, but F02 is an open P0
accounting-policy decision with a measured wrong figure, and F04's value-level PDF
assertion plus the F07/F08/F09 gaps above remain.

## 2026-08-27 — batch2-agent-a re-audit

- Claim: the preferred attempt `commercial/customer-complaints-8d M034` returned
  `ERROR: module folder not found: /home/kwat0g/Desktop/kwatog/audit/domains/commercial/customer-complaints-8d/M034`.
  Per the assigned fallback, `finance financial-statements` returned `CLAIMED`;
  actual module audited: M029.
- Re-audit preparation: read the refreshed registry, prior audit-report.md,
  action-plan.md, and fix-log.md; checked current git diff and module file mtimes.
  The generated registry and dependency modules were left untouched.
- Audit result: discovery, hardening, and polish passes completed. Current findings
  are M029-F02 (P0 Broken), M029-F06 (P1 Incomplete), M029-F07 (P1 Missing),
  M029-F10 (P1 Broken), M029-F11 (P1 Incomplete), M029-F12 (P2 Incomplete), and
  M029-F13 (P2 Polish).
- Gate: no production-code or dependency fixes were applied. The majority of the
  action plan is separate-recommended and the total scope is not small; status is
  `📋 Plan Ready`.
- Verification: focused PHPUnit passed with 12 tests and 69 assertions on
  `DB_DATABASE=ogami_test_m034_agent_a`; the shared `ogami_test` database was not
  used. The isolated rollover probe reproduced the 10,000.00 prior-year mismatch.
  PHP lint, statement route listing, targeted SPA ESLint, and scoped diff checks
  passed. The SPA API route audit reported only two unrelated pre-existing HR
  endpoint mismatches. Full suite and browser checks remain coordinator work.
- Changed audit artifacts: audit-report.md and action-plan.md were refreshed; this
  session entry was appended here. No production files were changed. Release and
  lock verification were pending at the time of this entry.
