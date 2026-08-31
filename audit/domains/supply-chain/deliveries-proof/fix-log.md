# M044 — fix log

No application source fixes were applied during the original 2026-08-25 audit pass. The module initially remained 📋 Plan Ready because the dominant work requires separate decisions around actor ownership, document retention, proof immutability, landed-cost money rules, idempotency, and schema backstops.

Verification recorded in `audit-report.md`:

- Focused Supply Chain backend suite: 77 tests, 176 assertions passed.
- SPA TypeScript typecheck passed.

The findings and ordered remediation steps are in `audit-report.md` and `action-plan.md`.

## 2026-08-25 resumed plan

### M044-F001 — resolved

- Before: `api/app/Modules/SupplyChain/Requests/CreateDeliveryRequest.php:60` allowed `items.*.inspection_id` to be omitted, while `DeliveryService` rejected every missing inspection; the SPA carried the field in its type but rendered no selector.
- After: `api/app/Modules/SupplyChain/Requests/CreateDeliveryRequest.php:60-63` requires the inspection; `api/app/Modules/SupplyChain/Requests/DeliveryInspectionOptionsRequest.php:11-31`, `api/app/Modules/SupplyChain/Controllers/DeliveryController.php:41-47`, `api/app/Modules/SupplyChain/Services/DeliveryService.php:111-183`, and `api/app/Modules/SupplyChain/routes.php:94-103` expose only passed outgoing inspections linked to the selected sales order and subtract active delivery reservations from accepted capacity.
- After: `spa/src/api/supply-chain/index.ts:75-104` types and fetches the options; `spa/src/pages/supply-chain/deliveries/create.tsx:25-31,98-102,232-301` requires a selection, shows remaining capacity, and aligns quantity input/validation to two decimal places.
- Tests: `api/tests/Feature/SupplyChain/CreateDeliveryDriverGateTest.php:74-115` covers the required inspection and sales-order-scoped remaining-capacity contract. Focused result: 4 tests, 15 assertions passed.
- SPA verification: `npm run typecheck` has no delivery-page errors; it remains blocked by the pre-existing `qrcode` module/type errors in `spa/src/pages/assets/detail.tsx:6,67`.

### Deferred at human-decision gate

- M044-F002–F010 remain pending. M044-F002 must first define assignment ownership, driver handoff permissions, and the customer-versus-internal confirmation actor. Implementing the next ordered item without that decision would guess at the RBAC and workflow contract.

## 2026-09-01 re-audit

Baseline before any change (own database `ogami_test_dlv`):
`docker compose run --rm -e DB_DATABASE=ogami_test_dlv api php artisan test tests/Feature/SupplyChain --no-coverage`
→ **93 tests: 91 passed, 2 failed, 226 assertions, 138.23s**. Both failures were the
inherited `CocAutoAttachOnConfirmTest` cases left red by the quality session (M056).

### Inherited task — `CocAutoAttachOnConfirmTest` fixture: resolved (case (a))

Conclusion: **the fixture was unrealistic; the quality session's guard is correct.**
Evidence, measured not assumed:

- `InspectionStatus::Passed` is written in exactly ONE place in the whole
  application — `api/app/Modules/Quality/Services/InspectionService.php:567`
  (`grep -rn "InspectionStatus::Passed" app/` returns 12 hits; every other one is a
  read/comparison).
- That writer is `complete()`, which refuses the fixture's state twice before it can
  reach `passed`: `InspectionService.php:552-554` ("Cannot complete: inspection has no
  measurement rows.") and `:556-559` (any `is_pass IS NULL` row blocks completion).
- Scaffold rows are created one per (sample unit x spec item) at
  `InspectionService.php:375-399`, so `count(distinct sample_index)` always reaches
  `sample_size` for a spec-backed inspection.
- The old fixture mass-assigned `status = passed` with **zero** measurement rows —
  precisely the falsified state `CoCService::assertEvidenceSupportsCertificate()`
  (`api/app/Modules/Quality/Services/CoCService.php:211-254`) exists to refuse.

- Before: `api/tests/Feature/SupplyChain/CocAutoAttachOnConfirmTest.php:225-237`
  created the inspection with no measurements; 2 of 4 tests failed
  (`COC_NO_MEASUREMENT_EVIDENCE`).
- After: the same helper seeds one resolved critical-dimension reading per sampled
  unit via a new `seedResolvedMeasurements()`; a passed lot reads in-tolerance, a
  failed lot fails its first unit. `accept_count`/`reject_count`/`defect_count` are now
  self-consistent with the verdict.
- Result: `tests/Feature/SupplyChain/CocAutoAttachOnConfirmTest.php` → **4 passed
  (13 assertions), 24.84s**. The guard was not weakened and no Quality file was touched.
