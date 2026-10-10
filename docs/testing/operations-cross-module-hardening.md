# Operations cross-module hardening

This completes the approved repairs from [the baseline audit](operations-cross-module-audit.md). Regression coverage uses `ogami_test_pricing_20261004`; browser fixtures refuse to run outside `ogami_test_audit_20261002`. Neither suite writes production business records.

## Corrected behavior

| Findings | Result |
| --- | --- |
| OPS-01 | Ordinary stock transfers cannot release quarantine or scrap-zone material. The shared movement service requires the canonical, independently reviewed MRB release. |
| OPS-02 | Conversions referenced by retained purchase orders, open purchase requests, receipts, or material issues cannot change or disappear. Historical purchase documents retain the factor they rely on. |
| OPS-03 | Receiving quantities, pending balances, and QC acceptance use base units. GRN forms lock receiving UoM to the item's base unit. Supplier documents and schedules use purchase units; PO progress compares consistent units. A two-bag order at five kilograms per bag still shows five kilograms due after accepting five. Supplier schedule availability is truncated to its supported two-decimal precision so it never rounds above the remaining balance. |
| OPS-04, OPS-05 | Maintenance claims molds and machines under locks, rejects active production and duplicate maintenance ownership, and releases equipment only when no active maintenance order still owns it. Cancelling an unstarted order cannot release another order's equipment. |
| OPS-06 | Technicians can search maintenance targets, active spare parts, and usable source stock through narrow endpoints. General inventory and mold administration remain permission protected. Lookup failures show retry actions. Search uses the existing 300 ms debounce. |
| OPS-07, OPS-12 | Paused production preserves runtime accrued before the pause. Service completion refreshes machine runtime before setting preventive-maintenance baselines. |
| OPS-08, OPS-17 | Shipment cost edits recalculate allocations transactionally. First stock acceptance refreshes costs against actual positive receipt content. Accepted cost snapshots remain immutable. |
| OPS-09 | Driver ownership and loading dates are checked again against the locked delivery, preventing stale reassignment or rescheduling requests from advancing it. |
| OPS-10, OPS-11 | Transfers and picking split quantities across available lots. Lot identity and expiry survive transfer, reservations remain protected, and picking never reuses a location's aggregate availability for each lot. Insufficient transfers roll back. |
| OPS-13 | Maintenance usage preserves the stock ledger's three-decimal quantities and four-decimal unit costs. Quantities below supported precision are rejected. Piece, each, unit, and set UoMs require whole numbers; weight-based parts support fractions. Mobile entry uses the same limits and prevents recording more than usable stock. |
| OPS-14 | Item archive and deactivation reject open procurement dependencies before they strand receiving. |
| OPS-15 | Shipment and receipt allocation share integer-cent distribution. All shares remain nonnegative and sum to the charge, including small totals split across many lines. |
| OPS-16 | Return Pending counts as an active fleet commitment for status changes and archive guards. Historical vehicle references remain readable. |
| OPS-18 | The former Manual allocation option is labeled Equal split. Its persisted value remains compatible. |
| OPS-19 | Days-of-supply demand includes deliveries, material issues, and scrap. Quantity and requested date use the same effective lead time. Item details explain the configured history window and coverage buffer. |
| OPS-20 | ABC classification assigns the leading positive-usage item to A using the pre-item cumulative boundary; zero-usage items remain C. |
| ACC-01 | Maintenance spare-part consumption posts to configured Repairs & Maintenance Expense, default 6100. Production material consumption continues using its existing account. Account policy requires an expense account. |

## Schema and compatibility

`2026_10_04_000007_align_maintenance_spare_part_precision` expands usage precision and reconciles it with linked immutable stock movements. Rollback refuses to round values that no longer fit the former schema. `2026_10_04_000008_add_maintenance_consumption_account` inserts the default only when absent; rollback preserves operator configuration.

Only these two migrations are part of this repair's rollout. Existing posted journal entries and accepted landed-cost snapshots are not rewritten. Historical PO factor snapshots were not introduced: referenced conversions remain locked instead, using the existing dependency mechanism.

Supplier schedules retain their existing two-decimal quantity contract. A remainder below 0.01 purchase units is displayed as unschedulable rather than permitting an excessive delivery plan. Receiving still accepts three-decimal base quantities.

## Verification

Role evidence and commands are recorded below. Browser checks cover technician maintenance creation/start, mobile spare-part usage, completion, narrow-access permissions, warehouse receipt lookup, and Impex pages. They supplement backend transaction and lifecycle regression tests; they do not represent an entire Sales Order-to-cash scenario.

- Backend safety tests: `docker exec ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml tests/Feature/Operations` passed **23 tests / 116 assertions** after the final quantity and conversion fixes.
- Nearby Inventory and Supply Chain regressions passed **93 tests / 1,031 assertions**, including canonical MRB release, transfer ordering, GRN purchase UoM, landed-cost capitalization, movement accounting, replenishment overlap, driver access, delivery concurrency, and shipment handoff.
- Final Operations, Maintenance, supplier capabilities/scheduling, supplier response, and PO close/cancel gate passed **166 tests / 545 assertions**. Command: `docker exec ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml tests/Feature/Operations tests/Feature/Maintenance tests/Feature/B2B/SupplierPoCapabilitiesTest.php tests/Feature/B2B/SupplierDeliveryScheduleTest.php tests/Feature/Purchasing/SupplierResponseTest.php tests/Feature/Purchasing/PurchaseOrderShortCloseTest.php tests/Feature/Purchasing/PurchaseOrderCancelTest.php`.
- Frontend: scoped ESLint passed. `cd spa && npm run build` passed TypeScript and Vite production compilation. The mechanical Impeccable scan reported **zero findings** across the eight changed UI targets.
- Headless Playwright: `cd spa && node e2e-real/operations-hardening.cjs` passed with maintenance technician, warehouse staff, and Impex officer accounts. It recorded **0.001 kg** consumption at **1.2345** unit cost, verified **1.999 kg** usable stock, completed maintenance, and selected the two-bag PO in the GRN form, verifying **10.000 kg** initial quantity and a read-only base UoM. No browser runtime or page-request errors occurred.
- Migration proof on the isolated audit database passed idempotent reapplication, exact ledger reconciliation, lossy rollback refusal, and customized account-setting preservation.
- The application database received only migrations `000007` and `000008`. Before and after rollout: **0 maintenance usages, 45 stock movements, 26 journal entries**. No existing business records changed. Verified quantity scale 3 and unit-cost scale 4.

Screenshots and browser results: `spa/.impeccable/review/operations/`. Fixture source: `api/tests/Browser/operations_fixture.php`; execute it using the audit API container and copy its `/tmp/operations-browser-fixtures.json` to the host before running the browser script. The fixture enforces its database/environment guard before creating records.
