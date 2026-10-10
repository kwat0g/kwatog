# Customer pricing and price agreement validation

Validated on 2026-10-04. This change separates catalog access from negotiated prices. Standard products can use a configured base selling price without individual agreements. Private products require customer assignments. An applicable agreement overrides the base price for its delivery-date window and quantity tier.

Products now have nullable base and minimum selling prices. The minimum blocks lower base, flat agreement and volume-tier prices, including changed internal commercial proposals. Prices remain before VAT. A missing minimum does not provide profitability protection; standard cost is not assumed to be the full cost. Real live prices were not invented.

The customer form retrieves an authoritative server quote and exact VAT totals. A signature rejects a changed quote before saving. Existing integrations may omit the signature and use current server prices; the browser always supplies it. Unchanged draft lines keep their unit prices, source and line IDs. Accepted delivery-only negotiations retain prices. New lines record the base/agreement source, agreement reference, tier threshold and pricing date.

The New/Edit Price Agreement form has paired product/customer selection, base and minimum context, date validity, a scrollable volume-tier table and a live summary. Selected records survive lookup refreshes. Mobile layout was verified at 390 pixels; the tier table scrolls within its container.

## Verification

- Initial new feature tests failed because the schema did not exist; five core API cases then passed.
- Broad regression: 63 API tests and 311 assertions passed across customer portal ordering, customer pricing, SO lifecycle and SO responses.
- Final additional checks: 23 API tests and 93 assertions passed, including migration preservation, unsafe agreement rejection without floor disclosure, retained assignments and internal price proposal compatibility.
- UI: 16 tests passed across order entry, order detail, product validation and internal SO detail.
- Production build passed. Final responsive selection fixes received another production build.
- Headless Playwright passed using administrator, customer service officer, sales officer and customer sessions in the isolated audit environment. It created standard/private/hidden products, placed a base-price order, created a volume agreement through the form, blocked lower-than-minimum pricing and sales management access, rejected an outdated quote, preserved a notes-only order's prices and line ID, and placed a volume-priced order whose saved total matched its quote. Both mobile pages had no document overflow and no browser errors.
- Browser fixtures were cancelled and their agreements/products archived in cleanup.

Run the browser check with `node spa/e2e-real/customer-standard-pricing.cjs`. It uses only `http://localhost:18080` and the isolated audit database. Screenshots are under `.impeccable/review/customer-pricing`.

## Additive migration and live preservation

Applied only `2026_10_04_000002_add_standard_customer_pricing.php` to live and audit. It preserved all existing product costs, agreement prices, SO amounts and SO line commercial fields, verified against a snapshot. It created 15 explicit assignments from existing agreements. Existing products stayed private and base/minimum selling prices stayed unset. SO-202610-0027's amount at the migration snapshot was 6272.00 and remained unchanged.

## Required business setup

Configure reviewed base/minimum prices and Standard visibility in Products before publishing products to every customer. Private products can use the same base price after customer assignment; agreements are needed only for customer-specific overrides.

A starting recommendation is 25% gross margin for the base and 15% minimum, calculated from verified full unit cost: base = cost / 0.75, minimum = cost / 0.85. Round upward to cents if these are strict thresholds. For an illustrative full cost of 18.50, use 24.67 and 21.77. These examples were not applied to live products. Missing labor, overhead, scrap, freight or other relevant costs can make apparent profit misleading.
