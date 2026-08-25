# M032 — Customer/product pricing audit report

- Audit date: 2026-08-24
- Domain/module: commercial / customer-product-pricing
- Tier: 2
- Surface: L
- Status: Plan Ready
- Source changes: none; this session produced audit artifacts only.

## Scope and summary

The module owns product master CRUD, product archive/restore, customer-specific price agreements, date-window resolution, and the product/pricing surfaces used by sales orders. The happy paths exist, but lifecycle recovery, effective-date integrity, financial arithmetic, and the tiered-pricing contract are not production-safe. The frontend also presents a partially implemented flat-pricing workflow.

Most findings require financial or cross-module decisions, so this module is Plan Ready and no production source fixes were applied.

## Findings

### F-001 — Soft-deleted products and agreements cannot be restored

- Classification: Broken
- Tags: [small] [same-session-ok]
- Evidence: The restore routes in routes.php:27-33 and :35-41 do not opt into withTrashed(). Product.php:20 and PriceAgreement.php:20 use SoftDeletes. HasHashId.php:46-67 documents that restore bindings require the soft-deletable route binding path. ProductController.php:52-55 and PriceAgreementController.php:58-61 call restore only after binding has succeeded.
- Impact: The product list calls productsApi.restore() from products/index.tsx:49-56, but an archived product resolves as not found. The price-agreement restore endpoint has the same failure, and there is no recovery path for an archived agreement.
- Recommendation: Add withTrashed() to both restore routes, then test archive → restore for each model.

### F-002 — Product archive does not protect dependent BOMs or price agreements

- Classification: Broken
- Tags: [medium] [separate-recommended]
- Evidence: ProductService.php:70-76 checks only salesOrderItems before soft-deleting. Product.php:39-47 exposes priceAgreements and activeBom relations. The product archive UI describes only the sales-order restriction at products/index.tsx:165-177. The BOM and price-agreement foreign keys use restrictOnDelete, but soft deletion does not invoke those database restrictions (0070_create_product_price_agreements_table.php:19-30 and 0073_create_bill_of_materials_table.php:18-20).
- Impact: A product with an active BOM or current customer pricing can be hidden from the product master while dependent records remain operational or become difficult to manage. The service comment claims BOM protection that the implementation does not provide.
- Recommendation: Define an archive policy for BOMs and price agreements, enforce it in one transaction, and make the UI explain whether the product must be deactivated instead.

### F-003 — Inactive or archived products/customers can still enter pricing and sales-order resolution

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: StorePriceAgreementRequest.php:30-41 and StoreSalesOrderRequest.php:31-43 use raw exists rules, which do not enforce is_active and do not apply the SoftDeletes scope. PriceAgreementService.php:101-114 resolves only a date-covered agreement and does not require active product/customer relations. Product.php:69-72 and Customer.php:41-43 define active scopes, but the pricing path does not use them.
- Impact: An API caller who knows a hash ID can create or resolve pricing for an inactive/soft-deleted product or customer. A sales order can therefore be created against commercial master data that the UI no longer offers.
- Recommendation: Enforce active, non-trashed product/customer invariants in the request and the service-level resolver; add negative tests for both inactive and archived records.

### F-004 — Overlap protection is check-then-write and restore bypasses it

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: PriceAgreementService.php:64-89 wraps create/update in a transaction but assertNoOverlap() at :148-175 performs an unlocked exists query before writing. There is no database exclusion/serializing constraint in 0070_create_product_price_agreements_table.php:19-30. PriceAgreementController.php:58-61 restores directly without re-running the overlap rule.
- Impact: Concurrent creates or updates can both pass the check and create overlapping windows. Restoring an archived window can also introduce an overlap with a newer agreement; resolve() then chooses one by effective_from rather than rejecting the ambiguous state.
- Recommendation: Serialize the customer/product date range or add a database-enforced strategy, and route restore through the same locked invariant check.

### F-005 — Pricing and sales-order totals use floating-point arithmetic

- Classification: Broken
- Tags: [large] [separate-recommended]
- Evidence: PriceAgreementService.php:126-141 returns float unit prices. SalesOrderService.php:182-212 and :249-276 casts quantities/prices to float and uses round() for line, VAT, subtotal, and total calculations. Money.php:7-11 requires string BCMath arithmetic and explicitly forbids float for money.
- Impact: Certain decimal prices, quantities, VAT rates, or tier values can produce binary rounding differences between the resolved unit price, line total, and persisted decimal columns. This is a financial correctness risk.
- Recommendation: Return decimal strings from pricing resolution and use Money helpers for line, subtotal, VAT, and total calculations; add exact decimal regression cases.

### F-006 — Tiered pricing is exposed by the API but not manageable in the SPA

- Classification: Incomplete
- Tags: [large] [separate-recommended]
- Evidence: The tier fields were added in 0233_add_price_tiers_to_price_agreements.php:16-25 and are validated/resolved by StorePriceAgreementRequest.php:38-41 and PriceAgreementService.php:117-141. However, the frontend type omits pricing_method and tiers at types/crm.ts:19-50, and PriceAgreementForm.tsx:19-28 and :123-146 only submit a flat price and dates.
- Impact: Administrators cannot create or edit tiered agreements through the supported UI. Direct API clients can also submit tiers with numeric unit prices of arbitrary precision and duplicate min_qty values, leaving ambiguous or financially unsafe tier data.
- Recommendation: Choose the tier contract, expose it consistently in types/form/resource tests, validate scale/order/uniqueness, and resolve with decimal-safe arithmetic.

### F-007 — Per-product revenue-account override is unreachable

- Classification: Missing
- Tags: [medium] [separate-recommended]
- Evidence: Migration 0175_add_revenue_account_id_to_products.php:11-18 adds the intended per-product GL override. Product.php:27-30 and :59-66 model it, and DeliveryService.php:1010-1024 consumes it. StoreProductRequest.php:16-25, UpdateProductRequest.php:17-27, ProductResource.php:15-22, and ProductForm.tsx:179-198 expose only standard cost; none accepts, returns, or edits revenue_account_id.
- Impact: Every product remains on the delivery invoice fallback account unless data is changed outside the product API. The documented C-1 override cannot be used by an administrator through the module.
- Recommendation: Add an authorized account selector and validation, or remove/defer the field explicitly. This needs Accounting/GL ownership because it changes invoice posting.

### F-008 — Product UOM is not validated against the canonical UOM catalog

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: StoreProductRequest.php:19-24 and UpdateProductRequest.php:21-26 accept any string up to 20 characters for unit_of_measure. ProductForm.tsx:51-65 and :145-157 deliberately load options from the UOM catalog, while Uom.php:20-26 identifies that catalog as canonical.
- Impact: Direct API callers can create products with a UOM that does not exist or has been archived. Downstream conversion, BOM, and quantity logic can then receive a unit that the managed catalog cannot resolve.
- Recommendation: Validate an active UOM code server-side and decide how existing products behave when a UOM is archived.

### F-009 — Product endpoints have an undeclared MRP/Quality schema dependency

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: ProductService.php:22-26 injects a raw bill_of_materials subquery into every product list and :49-54 always loads activeBom and inspectionSpec. Product.php:49-56 defines those MRP and Quality relations, while status.md:5-6 declares only system-admin roles and auth-session/rbac dependencies for M032.
- Impact: The product master cannot operate independently of those schemas even though the registry does not declare them as dependencies. The comment at ProductService.php:22-26 says the BOM query gracefully handles a missing table, but no Schema guard or fallback exists.
- Recommendation: Either declare the cross-module schema dependency and its migration gate, or guard optional enrichment so core product CRUD remains available.

### F-010 — Price-agreement search control is a no-op

- Classification: Incomplete
- Tags: [small] [same-session-ok]
- Evidence: PriceAgreementsListPage sets a search URL filter and renders FilterBar at price-agreements/index.tsx:21-28 and :71-83. PriceAgreementService.php:27-48 consumes customer_id, product_id, and active_on, but never reads search or filters product/customer names.
- Impact: Users can type into “Search agreement…” and receive the same result set, which is misleading on a growing price catalog.
- Recommendation: Implement server-side search over product part number/name and customer name, or remove the search control until a real filter exists.

### F-011 — View-only users see unauthorized price-agreement actions

- Classification: Polish
- Tags: [small] [same-session-ok]
- Evidence: The list always renders the New button and edit link at price-agreements/index.tsx:57-80. Unlike ProductsListPage, it does not call usePermission. The corresponding create/edit routes are guarded by crm.price_agreements.manage in routes.tsx:64-69.
- Impact: A user with view permission sees controls that lead to a forbidden route instead of a clear read-only experience.
- Recommendation: Gate create/edit affordances with crm.price_agreements.manage and add a role-based UI check.

### F-012 — Pricing form lookup lists silently truncate at 100 records

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: PriceAgreementForm.tsx:41-49 requests per_page 200 for products and customers. ProductService.php:45-46 and CustomerService.php:39-40 cap pagination at 100.
- Impact: Once either catalog exceeds 100 active records, administrators cannot select records beyond the first page, with no search or pagination in the form.
- Recommendation: Provide server-side lookup/search endpoints or paginated selectors; do not rely on a capped list hidden behind a 200-row request.

### F-013 — Price-agreement archive lifecycle has no usable UI

- Classification: Missing
- Tags: [small] [same-session-ok]
- Evidence: The API client exposes delete and restore at priceAgreements.ts:20-23, but the global list renders only an edit action at price-agreements/index.tsx:57-68 and has no archive filter. The API restore itself is also affected by F-001.
- Impact: Users cannot retire an obsolete agreement from the pricing screen or recover it from the screen; they must edit dates or call an unavailable backend workflow.
- Recommendation: Add permission-aware archive/restore controls and an archive filter after the restore invariant is fixed.

### F-014 — Direct product/pricing API regression coverage is missing

- Classification: Missing
- Tags: [medium] [separate-recommended]
- Evidence: The tests/Feature/CRM directory contains downstream sales-order and complaint tests but no ProductController or PriceAgreementController test. TaxPolicyCalculationTest.php:38-69 constructs Product/PriceAgreement models directly and checks VAT, not CRUD, permissions, restore, overlap, tier resolution, or inactive-record rejection.
- Impact: The highest-risk module behaviors can regress without a focused test failure.
- Recommendation: Add feature coverage for authorization, CRUD/restore, archive dependencies, overlap concurrency, tiered resolution, exact money values, and inactive/trashed record rejection.

### F-015 — Role policy conflicts with CRM documentation and needs confirmation

- Classification: Incomplete
- Tags: [medium] [separate-recommended]
- Evidence: M032 status.md:5 lists only system_admin. The permission catalog defines CRM product/pricing permissions at RolePermissionSeeder.php:285-292, but the non-admin role definitions shown at :454-529 do not grant the CRM module. The CRM module audit describes sales/service users at docs/SYSTEM-MODULE-AUDIT-2026-08-13.md:96-102, and the user manual says finance users create sales orders using active pricing at docs/USER-MANUAL.md:166-172.
- Impact: The implementation may be intentionally system-admin-only, or legitimate sales/finance users may be unable to maintain product/customer pricing. The current evidence does not establish which policy is correct.
- Recommendation: Confirm the role matrix with the product owner, then align registry, seeded grants, route guards, and frontend visibility. Do not broaden permissions by assumption.

## Verification

- API focused suite in the project container: 43 tests passed, 131 assertions.
- SPA TypeScript check: passed with npm run typecheck.
- Targeted SPA ESLint for product/pricing pages, APIs, and types: passed with --max-warnings 0.
- PHP syntax checks for the audited API files: passed.
- No direct M032 endpoint tests exist; the green CRM suite is downstream evidence, not proof that this module’s CRUD/restore/overlap paths are covered.

## Disposition

No production source fixes were applied. The majority of findings are financial, cross-module, or policy-sensitive and are tagged separate-recommended. F-001, F-010, F-011, and F-013 are small same-session candidates, but the total plan is not small and should be implemented as a coordinated pricing/lifecycle change followed by a fresh re-audit.
