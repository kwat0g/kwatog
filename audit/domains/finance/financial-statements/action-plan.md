# M029 — Financial Statements action plan

Date: 2026-08-27
Status: 📋 Plan Ready  
Overall recommendation: separate implementation work; no production-code fixes in this audit session

The plan is ordered by financial correctness, security/export risk, contract stability, then UI polish. Dependencies were read for context only and are not part of this plan's implementation scope.

## Ordered fixes

### 1. M029-F02 — Define and implement fiscal-year close/carry-forward semantics

- Classification/severity: Broken, P0
- Size: large
- Session tag: separate-recommended
- Decide whether prior-year revenue/expense is closed into retained earnings or whether the balance sheet derives a cumulative closed-period result. Make the policy explicit and prevent a valid posted ledger from becoming unexplained at rollover.
- Tests: prior-year income, current-year income, January and non-January fiscal starts, manual close entry, repeated close, and intentionally corrupted ledger behavior.

### 2. M029-F10 — Neutralize spreadsheet formulas in aging CSV text

- Classification/severity: Broken, P1
- Size: medium
- Session tag: separate-recommended
- Add a central CSV-cell policy for user-controlled text and apply it to customer/vendor names while preserving ordinary text. Cover leading equals, plus, minus, and at-sign values in AR and AP exports.
- Tests: formula-prefix regression cases and ordinary-name/export parity cases.

### 3. M029-F06 — Publish a type-safe CSV metadata contract

- Classification/severity: Incomplete, P1
- Size: medium
- Session tag: separate-recommended
- Define explicit header/row schemas. Keep status flags out of decimal columns, include trial-balance reconciliation and balance-sheet equation/status metadata, and document compatibility expectations.
- Tests: every core statement CSV, row widths, totals, status values, and JSON/CSV/PDF parity.

### 4. M029-F11 — Correct SPA date-only and fiscal-year defaults

- Classification/severity: Incomplete, P1
- Size: medium
- Session tag: separate-recommended
- Replace UTC ISO conversion for date-only inputs with a date-only formatter, and derive income-statement defaults from the configured fiscal start month.
- Tests: Asia/Manila local-midnight boundaries, leap/day boundaries, and non-January fiscal years.

### 5. M029-F07 — Complete the statement regression matrix

- Classification/severity: Missing, P1
- Size: medium
- Session tag: separate-recommended
- Add service and HTTP coverage for rollover, all CSV/PDF shapes, dedicated AP export, permissions, currency changes, precision, cache invalidation, and empty/no-posted-entry paths. Add browser checks for responsive/accessibility behavior.
- Keep the test database isolated per agent and preserve the coordinator's full-suite ownership.

### 6. M029-F12 — Align AR/AP aging response metadata

- Classification/severity: Incomplete, P2
- Size: small
- Session tag: same-session-ok
- Approve one aging response contract and expose the effective as-of date consistently without changing separately owned aging calculation services during this module audit.
- Tests: AR/AP JSON, CSV, and UI display of the selected/effective date.

### 7. M029-F13 — Move export authorization to the request/route boundary

- Classification/severity: Polish, P2
- Size: small
- Session tag: separate-recommended
- Consolidate CSV/PDF export permission enforcement in route middleware or FormRequest authorization so new formats cannot accidentally bypass the policy.
- Tests: view-only JSON, view-only export rejection, export-capable CSV/PDF, and route additions.

## Gate decision for this session

No production-code implementation was applied. F12 is the only same-session-ok item, and it still requires contract approval. The majority of the ordered plan is separate-recommended, with a large P0 accounting-policy item and several medium security/contract/test changes. The total scope is not small, so the gate result is 📋 Plan Ready.

## Definition of done

- Fiscal-year rollover produces mathematically correct equity under an explicit annual-close policy.
- User-controlled CSV text cannot execute as spreadsheet formulas.
- CSV schemas keep metadata and money types distinct and reconcile with JSON/PDF outputs.
- Statement date defaults are timezone-safe and respect the configured fiscal year.
- Service, controller, permission, export, currency, precision, cache, empty-state, and browser checks cover the report surface.
- AR/AP aging metadata is symmetric and all export authorization is enforced at the boundary.
