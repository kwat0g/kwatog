# M029 — Financial Statements action plan

Date: 2026-08-24  
Status: 📋 Plan Ready  
Overall recommendation: separate implementation work; no production-code fixes in this audit session

The module has working core report services and screens, but the highest-risk work changes financial calculations, export contracts, or RBAC. The contained dashboard and responsive-polish items are intentionally deferred so they do not create a misleading “finished” state while the report authority remains unresolved.

## Ordered implementation plan

### 1. M029-F01 — Enforce separate statement view and export authorization

- Classification/severity: Broken, P0
- Scope: medium; routes, SPA capability gates, tests
- Session recommendation: `separate-recommended`
- Require `accounting.statements.view` for JSON and `accounting.statements.export` for CSV/PDF. Apply the same split to AR/AP aging exports and to any generic export path.
- Tests: view-only JSON success, view-only CSV/PDF 403, export-capable CSV/PDF success, system-admin bypass, and direct URL/UI parity.

### 2. M029-F02 — Define and implement fiscal-year close/carry-forward semantics

- Classification/severity: Broken, P0
- Scope: large; balance-sheet service, fiscal-year/retained-earnings policy, possibly schema/workflow, tests
- Session recommendation: `separate-recommended`
- Decide whether prior-year revenue/expense is closed into retained earnings or whether the report must derive a cumulative closed-period result. Make the policy explicit and prevent a valid posted ledger from producing an unexplained rollover imbalance.
- Tests: prior-year income, current-year income, January and non-January fiscal starts, manual close entry, repeated close, and intentionally corrupted ledger behavior.

### 3. M029-F03 — Add a strict shared statement-date request contract

- Classification/severity: Incomplete, P1
- Scope: medium; FormRequest/validation, JSON/CSV/PDF controllers, tests
- Session recommendation: `separate-recommended`
- Validate `from`, `to`, and `as_of` as strict `Y-m-d` values, define timezone behavior, reject `from > to`, and return the standard 422 validation envelope on all report formats.
- Tests: omitted defaults, valid boundaries, malformed dates, ambiguous dates, reversed ranges, and PDF/CSV parity.

### 4. M029-F04 — Make PDF rendering money-safe

- Classification/severity: Broken, P1
- Scope: medium; shared PDF money formatter/templates, tests
- Session recommendation: `separate-recommended`
- Remove `(float)` conversions from statement templates. Format decimal strings with a shared string-safe helper and preserve sign, scale, and large values.
- Tests: large balances, negative contra balances, zero values, half-up rounding, and JSON/PDF amount parity.

### 5. M029-F05 — Carry functional currency through every statement output

- Classification/severity: Incomplete, P1
- Scope: medium; API response/export schema, PDF service/templates, SPA display contract, tests
- Session recommendation: `separate-recommended`
- Include the configured functional currency code in report metadata, pass it to all PDFs, remove hardcoded `PHP`, and label CSV/JSON/UI outputs consistently.
- Tests: PHP default, a non-PHP configured currency, PDF/CSV metadata, and runtime setting changes.

### 6. M029-F06 — Make CSV contracts reconcile with JSON/PDF contracts

- Classification/severity: Incomplete, P1
- Scope: small/medium; controller serializers, export fixtures, tests
- Session recommendation: `separate-recommended`
- Add trial-balance totals and balance-sheet equation/status metadata to CSV, define headers/total rows, and document the schema for downstream consumers.
- Tests: row totals, debit-credit reconciliation, balance-sheet equation, imbalance flag, and JSON/CSV/PDF parity.

### 7. M029-F07 — Build the missing statement regression matrix

- Classification/severity: Missing, P1
- Scope: medium; feature tests and report fixtures
- Session recommendation: `separate-recommended`
- Cover all service invariants and HTTP boundaries rather than only the current two-entry happy path.
- Tests: balance sheet, fiscal rollover, date validation, view/export permissions, CSV/PDF downloads, currency, precision, cache invalidation, and no-posted-entry empty results.

### 8. M029-F08 — Replace the dashboard placeholder and restore report discoverability

- Classification/severity: Incomplete, P2
- Scope: small; finance dashboard/sidebar links and SPA tests
- Session recommendation: `same-session-ok`
- Render real permission-aware links for trial balance, income statement, balance sheet, AR aging, and AP aging, or add equivalent Finance sidebar entries. Avoid exposing a link whose route is not granted.
- Verification: finance officer and system administrator see working destinations; a user without statement view sees neither the panel nor navigation items.

### 9. M029-F09 — Finish responsive and accessible report tables

- Classification/severity: Polish, P2
- Scope: small; SPA table wrappers, headers, grid breakpoints, visual tests
- Session recommendation: `same-session-ok`
- Add `overflow-x-auto` to wide report tables, add `<thead>`/`<th scope="col">` where missing, and change balance-sheet totals to responsive spans such as `sm:col-span-2 lg:col-span-3`.
- Verification: keyboard traversal, screen-reader table semantics, 320px/768px/desktop layouts, loading/error/empty states, and no horizontal page overflow.

## Session decision

No production-code implementation was applied. F01–F07 are the majority of the plan and are financial, money, export-contract, authorization, or broad test changes. F08–F09 are safe contained candidates for a later same-session polish pass after the report authority contract is agreed.

## Definition of done for the next implementation session

- A view-only user cannot retrieve CSV/PDF statement data, while an export-capable user can.
- Balance-sheet output remains mathematically correct across fiscal-year rollover and explicitly reports/handles genuine ledger imbalance.
- All report date inputs have one strict validation/error contract.
- JSON, CSV, and PDF amounts retain exact decimal values and consistently identify the configured currency.
- Service, controller, permission, export, and UI tests cover both happy paths and direct/adversarial calls.
- Dashboard/sidebar navigation reaches every granted report and the screens pass narrow-viewport/accessibility review.
