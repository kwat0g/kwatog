# Price Agreement audit — October 4, 2026

## Purpose and current behavior

A Price Agreement defines the selling price of one product for one customer during an inclusive date range. It can use a flat price or volume thresholds. For example, Toyota's WB-001 agreement is PHP 22.50, while Nissan's seeded price for the same part is PHP 23.00.

The current implementation also uses agreements to determine which products appear in the customer portal. There is no independent product base selling price. Products contain `standard_cost`, an internal cost used for valuation and margin calculations. WB-001 has a standard cost of PHP 18.50 and a Toyota selling price of PHP 22.50. Cost must not become a selling-price fallback.

Production data was queried read-only: 20 products, 15 agreements, 4 Toyota agreements, no overlapping active agreement windows, no zero base prices, no expired or future agreements, and no tiered agreements. Toyota's four agreements run January 1 through December 31, 2026. Pricing scenarios below were reproduced only in an isolated test database; they do not establish that the production orders currently have those discrepancies.

## Findings

| Priority | Finding and consequence | Smallest practical correction |
| --- | --- | --- |
| High | Customer preview and submitted prices use different dates. `CustomerPortalService::catalog()` defaults to today and the form does not send its selected delivery date; `SalesOrderService::create()` resolves by delivery date. An item can be visible but unavailable for delivery, a future valid agreement can be hidden, or the submitted price can exceed the preview. Reproduced PHP 200.00 preview versus PHP 300.00 saved subtotal. | Resolve previews and submission through the same server pricing path with an explicit pricing-date policy. If a submitted quote changes, require the customer to review refreshed totals before creation. |
| High | Volume discounts are calculated only during server creation. Catalog responses return the price for quantity 1 and omit tiers; the customer form multiplies that price by every entered quantity. Reproduced PHP 2,000.00 preview versus PHP 1,500.00 saved subtotal for 100 units. | Recalculate displayed prices for the requested quantity using the shared pricing path. Show the applied volume price. |
| High | Every draft update resolves prices again and replaces all lines. Even a notes-only save can change the total after an agreement is edited. Reproduced PHP 200.00 changing to PHP 300.00 with identical products, quantities and delivery dates. | Preserve unchanged commercial lines and their frozen prices. Explicitly quote and review changes that affect pricing. |
| High, business policy | Any holder of `crm.price_agreements.manage` can immediately change a price or archive the agreement; there is no approval state, minimum-margin check, or special authorization for zero prices. Zero and below-cost prices are allowed. Seeded Customer Service Officer permissions include management; seeded Sales Officer permissions provide viewing only. | Assign pricing ownership explicitly. Require confirmation or authorized exception for zero/below-cost pricing. Introduce a separate approval role only if the business requires it. |
| Medium | Agreement availability and product visibility are coupled. An expired or archived agreement removes the product from ordering even if the business wants to sell it at a normal base price. All current agreements expire December 31, 2026. No agreement renewal notification was found. | Separate catalog eligibility from price selection. Show expiry status and notify the pricing owner ahead of expiry. |
| Medium | Sales order lines freeze the unit price but do not record the agreement identity, applicable tier, or pricing-date snapshot. Audit logs exist, but explaining a historical line requires reconstructing changes rather than reading its explicit source. | Store the selected price source and relevant quote snapshot with the order line; later agreement changes must not rewrite existing orders. |
| Medium | Pricing date policy conflicts with delivery negotiation. Creation prices by delivery date, while accepting a sales date proposal deliberately changes only delivery dates and retains prices. Later customer edits resolve the new date again. | Define a consistent rule: accepted commercial prices remain locked; operational rescheduling does not silently reprice. Document when a new commercial quote is necessary. |
| Medium, concurrency risk | Lock order differs between portal creation and agreement writes. Portal placement locks the customer first, then the SO service locks products. Agreement creation locks products then the customer. Concurrent requests can deadlock. Agreement archival locks only the agreement row, bypassing the product/customer coordination locks used by other pricing writes. Stale agreement updates also choose reference locks before refreshing the agreement. These are static-code findings, not reproduced races. | Use one consistent lock order, refresh the authoritative agreement before relying on its references, and coordinate archival with other price writers. |
| Low | The agreement list labels every non-current agreement `Expired`, including future agreements. The active flag checks only date bounds, although the resolver also rejects inactive or archived customer/product references. | Show Scheduled, Active, Expired and Archived accurately, with unavailable references visible. |

## Existing protections

The API checks management permissions and active customer/product references. Agreement creation, update and restoration check date overlap and coordinate through master-data locks. Date normalization rejects inverted windows. Tier thresholds must be positive, ascending and unique. Money uses decimal string arithmetic. Agreement changes do not automatically rewrite existing order prices, and archive operations retain audit history through soft deletion.

The database has lookup indexes but no exclusion constraint for overlapping agreement windows. Application writes provide the ordinary overlap protection; direct database writes can bypass it. No additional database constraint is necessary solely for this audit, but any future import must use the same validated service.

## Recommended simple model

1. Give each sellable product an explicit base selling price, separate from standard cost.
2. Make Price Agreements optional customer-specific overrides, including volume discounts when needed.
3. Use an applicable agreement when one exists; otherwise use the valid base selling price for products the customer is allowed to order.
4. Keep private or customer-specific parts restricted. A base-price fallback must not automatically publish every product to every customer.
5. Show whether the quote uses a standard or agreed price. Use identical date, quantity and pricing rules for preview and submission.
6. Freeze the accepted quote on the SO. Operational delivery negotiation preserves it; commercial changes receive an explicit refreshed quote.
7. Preserve existing negotiated agreements and order prices during migration. Do not derive base prices from cost or choose one customer's price as a universal default.

This removes the need to create an agreement for every customer/product combination while preserving negotiated OEM pricing.

## Verification

Existing `CustomerProductPricingTest.php`: 19 tests and 75 assertions passed.

Temporary isolated audit probes: 4 tests and 8 assertions passed, reproducing volume preview differences, delivery-date preview differences, notes-only draft repricing, and acceptance of zero prices below standard cost. These probes characterize current gaps; they are not passing regression tests for corrected behavior.

No application behavior or production pricing records were changed during this audit.

## Main code references

- `api/app/Modules/CRM/Models/Product.php:27` — standard cost, no base selling price.
- `api/app/Modules/B2B/Services/CustomerPortalService.php:133` — catalog eligibility and quantity-1 prices.
- `spa/src/pages/portal/customer/orders/new.tsx:57` — catalog query lacks selected date; line totals use cached unit prices.
- `api/app/Modules/CRM/Services/SalesOrderService.php:474` — create resolves delivery-date and volume prices.
- `api/app/Modules/CRM/Services/SalesOrderService.php:610` — draft updates resolve and replace lines.
- `api/app/Modules/CRM/Requests/StorePriceAgreementRequest.php:44` — zero allowed by pricing validation.
- `api/app/Modules/CRM/Services/PriceAgreementService.php:97` — stale reference locking during update.
- `api/app/Modules/CRM/Services/PriceAgreementService.php:124` — archival lock differs from other writers.
- `api/app/Modules/CRM/Models/SalesOrderItem.php:24` — price snapshot without agreement source.
- `api/app/Modules/CRM/Services/SalesOrderResponseService.php:124` — rescheduling deliberately preserves prices.
- `spa/src/pages/crm/price-agreements/index.tsx:82` — future agreements labeled expired.
