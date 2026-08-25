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
