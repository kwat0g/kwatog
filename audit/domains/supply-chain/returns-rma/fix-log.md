# M046 — fix log

## Historical audit session — 2026-08-25

- No application source fixes were applied during the earlier audit session. The module was left `📋 Plan Ready` for dedicated implementation work.
- That session recorded 55 backend tests / 273 assertions and a passing SPA typecheck at its then-current source revision.

## Fresh audit session — 2026-08-25

- The earlier report/plan were invalidated because the module source had substantial uncommitted changes after that report and those changes were not represented in this log.
- Re-ran discovery, hardening, and polish against the current working tree. No production source files were changed by this session.
- PHP syntax passed for the Return Management API files.
- Current SPA typecheck is not green: `spa/src/pages/return-management/detail.tsx:902` has duplicate `minLength` JSX attributes, in addition to unrelated asset QR-code errors.
- The shared backend test database was not stable enough for a clean rerun under parallel sessions; the refreshed report records the observed result and the verification limitation.
- Open findings and the ordered handoff are in `audit-report.md` and `action-plan.md`.

## Implementation session — 2026-08-25

- **RMA-001 fixed:** `api/app/Modules/ReturnManagement/Services/ReturnRequestService.php:239-250` now preserves the associative source-kind keys before resolving the source ID, so invoice, sales-order, and delivery branches receive their intended kind and authoritative source limit.
- **RMA-003 fixed:** `api/app/Modules/ReturnManagement/Requests/StoreReturnRequestRequest.php:155-163` and `ReturnRequestService.php:249-254` require product provenance for non-finance stockable customer returns; `spa/src/pages/return-management/create.tsx:145-150,183-195,218-225` now preserves and submits the selected source product for create and draft-edit flows.
- Added API regression coverage for invoice, sales-order, and delivery source resolution, authoritative prices, source allocations, and missing product provenance in `api/tests/Feature/ReturnManagement/ReturnRequestScenarioTest.php:573-735`.
- Verification: PHP syntax checks, `git diff --check`, and `spa/npm run typecheck` passed. The focused API tests could not execute because the configured PostgreSQL test host `db` was unavailable (`SQLSTATE[08006]`, `ogami_test`); no passing test result is claimed.
- Deferred from the next ordered item: **RMA-002/RMA-004** require a product/operations decision on invoice-less SO/delivery credit-note policy and the downstream Replace/Refund outcomes. I did not guess that policy. Items 4-11 remain pending behind that decision and the unavailable database verification.
