# M054 — fix log

Resumed plan execution: 2026-08-25. The module is released as 🔁 Needs Re-audit because the contained fixes below are complete, while F002/F003/F004/F009 require policy or cross-module decisions.

## Fixed and verified

- F001 — `api/app/Modules/Inventory/Services/QuarantineService.php:191-201,320-342,412-449`: added active-location, typed-zone, active-warehouse, same-warehouse, and good-zone validation for hold/release paths; auto-resolved locations also require an active warehouse. `spa/src/pages/inventory/mrb/index.tsx:246-267` and `spa/src/pages/inventory/mrb/detail.tsx:240-257` now filter the same valid choices. Before: explicit IDs bypassed resolver rules. After: forged inactive, wrong-zone, and cross-warehouse payloads fail before movement.
- F005 — `api/app/Modules/Inventory/Services/QuarantineService.php:111-164,472-531` and `api/app/Modules/Inventory/Requests/MrbQualityOptionsRequest.php:11-34`: added item-scoped failed-inspection/open-NCR lookup and item/status/quantity/event consistency checks. `api/app/Modules/Inventory/Resources/MaterialReviewRecordResource.php:37-61` and `spa/src/pages/inventory/mrb/detail.tsx:129-142` expose the quality state. Before: IDs were existence-only and the UI listed the first 100 NCRs. After: unrelated or non-actionable links are rejected and the selected inspection/NCR state is visible.
- F006 — `api/database/migrations/2026_08_25_190000_add_mrb_hold_idempotency.php:11-24`, `api/app/Modules/Inventory/Services/QuarantineService.php:180-223,450-470`, and `spa/src/api/inventory/mrb.ts:37-40`: added actor-scoped durable key/fingerprint replay handling and SPA retry headers. Before: every retry created a new MRB and movement. After: the same command returns its original row; changed-payload reuse returns a business-rule error.
- F007 — `api/app/Modules/Inventory/Requests/MrbIndexRequest.php:11-27` and `api/app/Modules/Inventory/Services/QuarantineService.php:50-89`; `spa/src/pages/inventory/mrb/index.tsx:144-145`: search is validated, forwarded, and matched against MRB/item/NCR/location fields while URL state remains synchronized.
- F008 — `spa/src/pages/inventory/mrb/index.tsx:38-47,229-278,321-416`: aligned quantity entry to three decimals, added searchable item and quality lookups, active-location filtering, scoped result limits, and retry/error states.

## Deferred with reason

- F002 remains pending: generic transfer/adjustment ownership crosses shared Inventory and Return Management paths, and the existing RMA quarantine flow is a valid exception. No authoritative exception/reconciliation policy was available; no dependency module was changed.
- F003 remains pending: no authoritative MRB rework/reinspection evidence or use-as-is concession approval model exists. A human decision is required before adding a release gate.
- F004 remains pending: supplier/PO/GRN/bill provenance and Purchasing/Accounting handoff ownership are unspecified, so no cross-module terminal-state or notification behavior was invented.
- F009 remains pending: segregation-of-duties thresholds and approval ownership are unspecified; changing shared RBAC/seeder policy is outside this module scope.

## Verification

- Passed: `docker compose run --rm api php artisan test tests/Feature/Inventory/QuarantineMrbTest.php tests/Feature/Inventory/MrbDoubleReleaseRaceTest.php` — 16 tests, 49 assertions.
- Passed: targeted ESLint for `spa/src/pages/inventory/mrb/index.tsx` and `detail.tsx`.
- Repository-wide SPA typecheck is blocked by pre-existing errors in `src/pages/assets/detail.tsx` (`qrcode`) and `src/pages/return-management/detail.tsx`; no M054 file appears in that error set.
- Repository-wide SPA lint remains blocked by three pre-existing hook dependency errors outside M054.
