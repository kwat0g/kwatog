# Pricing cross-module hardening

Implemented October 4, 2026, following the [focused audit](pricing-cross-module-access-audit.md). This covers pricing requests, supplier cost reviews, product financial maintenance, BOM costing and their access/notification/health handoffs.

| Finding | Result |
| --- | --- |
| PA-01 | Finance approval requires the displayed proposal version and cost signature. The service checks both inside the existing locked transaction. The review panel displays those exact terms and refreshes them after a conflict. |
| PA-02 | Financial review uses the highest configured product cost, fresh BOM estimate and mapped finished-good Inventory cost. The mapping must have an active finished good with matching base unit. Existing BOM, routing or production history makes a missing manufacturing BOM incomplete. Approved mapped Inventory cost changes also enqueue a pricing review. |
| PA-03 | Product-maintenance permission cannot directly change existing cost, base price or minimum price. PPC proposes financial changes from the product record; a different Finance reviewer approves or rejects. Current values stay in effect while pending. Changed financial values invalidate stale proposals. The existing audit trail retains proposed values, decision and reason. |
| PA-04 | Unexpired offers can be approved or accepted after their start date. The original commercial dates remain unchanged. Expired offers remain blocked. |
| PA-05 | Restricted products must already be assigned to the customer before Sales proposes terms. Customer acceptance retains its availability check. |
| PA-06 | Rejected supplier reviews can be renewed only after source receipt cost or standard-cost basis changes. The same receipt review keeps its previous decisions in version history. Old versions cannot approve renewed reviews. Archived-item reviews can be rejected; approval still requires active, eligible sources. |
| PA-07 | Supplier cost reviews use Inventory's feature guard and permissions, keeping their existing URL. CRM being disabled does not hide an enabled Inventory review page. |
| PA-08 | An additive migration grants Finance product/agreement read access. Fresh seeds match. Other role grants and overrides are preserved. Custom cost approvers can read the linked product record without product-maintenance permission. |
| PA-09 | Alerts select active users by relevant permissions, including custom roles and overrides. Revoked approval authority excludes that actor. Withdrawal and expiration notify staff and expose a customer-safe reason without internal history or costs. |
| PA-10 | Pricing refreshes pending or processing for at least 15 minutes cause Operations Health to report attention. Failed refreshes remain visible. |
| PA-11 | Active BOMs for active products with an unresolved costing snapshot also cause attention, even when the outbox dispatch succeeded. Health links authorized planners to BOM correction. |
| PA-12 | Open negotiations protect product identity and archiving through the existing dependency guards. Pending financial changes also prevent archiving. |

## Verification

Run the focused suite only against the dedicated isolated database `ogami_test_pricing_20261004`:

```bash
docker exec ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml --filter 'PricingCrossModuleSafetyTest|PricingNegotiationMigrationTest|PriceNegotiationLifecycleTest|ItemCostReviewTest|CustomerStandardPricingTest|CustomerProductPricingTest|AutomaticBomPricingReviewTest|BomInventoryCostSafetyTest|RolloutHealthTest'
```

From `spa`:

```bash
node e2e-real/pricing-negotiation.cjs
node e2e-real/bom-pricing-automation.cjs
npm run build
```

The expanded headless pricing script uses customer, Sales, Finance, PPC, Warehouse and Admin accounts on the isolated audit site. It verifies financial proposals, unauthorized direct financial edits, stale proposal approval rejection, refreshed Finance approval, customer acceptance, locked agreement terms, supplier cost approval, rejected-review renewal, and mobile document width. Its CRM-disabled page check intercepts the browser's feature response, without changing shared environment settings. The separate BOM script verifies automatic cost refresh, unchanged agreement prices, visible quantity tiers and staff handoff alerts.

Verification covered all 70 focused tests: the expanded run passed 69 and identified one outdated restricted-product fixture; after adding its customer assignment, all 19 tests in that affected class passed (65 assertions). The other 51 tests had already passed in the expanded run. The earlier 46-test gate also passed with 188 assertions. TypeScript/Vite build, targeted ESLint, targeted Laravel Pint and both headless scripts passed. Both browser scripts captured no application errors.

The migration adds one nullable product proposal field and supplier-review version/history fields. It leaves existing financial values unchanged and refuses rollback with pending proposals or renewed histories. Decision history remains in the existing immutable audit log after a financial proposal closes. There is no new approval engine or dependency.

Live read-only checks preserve `SO-202610-0027` at `6272.00` and `WB-001` base selling price at `200.00`. Finance now has product/agreement read access. Both live and audit databases received the targeted additive migration; no full role reseed was run.

## Limits

BOM gross margin remains an estimate. Finance chooses suitable prices and margin policy; the system does not infer actual net profit. Existing agreements receive risk notices when cost rises and still require customer acceptance of replacement terms. A product without manufacturing history can be reviewed from its configured cost without a BOM; manufacturing history makes the missing BOM unsafe. Standard-cost approval does not rewrite inventory valuation or saved sales-order prices.
