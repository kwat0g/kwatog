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

---

# Re-audit — 2026-08-30

- Re-audit date: 2026-08-30
- Claim result: **RECLAIMED** (lock was an orphan, 107h old, owner
  `codex-coordinator-blocker-quarantine`, claimed 2026-08-25T11:51:42Z).
- Predecessor state: the crashed session's work was **already committed**, in
  `167de85e chore: remaining uncommitted work from ~50 crashed audit sessions`.
  `git status` for `api/app/Modules/CRM`, `spa/src/pages/crm`, `spa/src/api/crm`,
  `spa/src/types/crm.ts` and `api/tests/Feature/CRM` was clean at the start of
  this session, and `fix-log.md` was fully written (not a blank scaffold). This
  re-audit is therefore verification plus fresh discovery, not recovery.
- Method: every invariant below was executed against real PostgreSQL rows in an
  isolated database (`ogami_test_pricing`), not reasoned about. Row-lock
  behaviour was additionally verified with two concurrent `psql` sessions.

## Status of the 15 prior findings

Re-measured, not taken on trust.

| Prior | Prior claim | Re-measured 2026-08-30 |
|---|---|---|
| F-001 restore unreachable | fixed | **Confirmed fixed.** `routes.php:33-35,43-45` carry `->withTrashed()`; archive→restore round-trips. |
| F-002 archive ignores dependents | fixed | **Confirmed fixed.** `ProductService.php:90-118` blocks on sales-order lines, active BOM and price agreements, with the message the SPA promises. |
| F-003 inactive/archived refs enter pricing | fixed | **Confirmed fixed.** Refused in the FormRequests (`Rule::exists(...)->where('is_active', true)->whereNull('deleted_at')`) and again in `PriceAgreementService::assertActiveReferences()`. A trashed customer and a trashed product each make `resolve()` throw. |
| F-004 overlap check-then-write | fixed | **Partly fixed — see R-003.** Application serialization is real (row lock verified). No database-level guarantee, and `resolve()` is non-deterministic if an overlap exists by any other route. |
| F-005 float money | fixed | **Confirmed fixed.** No `(float)`, `floatval` or `round(` anywhere in `PriceAgreementService`, `SalesOrderService`, `ProductService`, `PriceAgreementResource`. ₱3.33 × 7.77 = 25.8741 persisted as `25.87` (half-up, exact). |
| F-006 tiers unmanageable in SPA | fixed | **Fixed as a surface, but it exposed R-001.** The SPA now edits tiers — and its copy contradicts the resolver about money. |
| F-007 revenue_account_id unreachable | deferred | **Still open (R-004).** Absent from `StoreProductRequest`, `UpdateProductRequest`, `ProductResource` and `products/form.tsx`. |
| F-008 UOM not validated | fixed | **Confirmed fixed.** `pcs` normalises to `PCS`; unknown `EA` is a 422. |
| F-009 undeclared MRP/Quality dependency | fixed | **Confirmed fixed.** `ProductService::hasBomSchema()/hasInspectionSpecSchema()` guard the enrichment and the `has_bom` filter. |
| F-010 search is a no-op | fixed | **Confirmed fixed.** `PriceAgreementService.php:44-54` searches part number, product name and customer name. |
| F-011 unauthorized actions visible | fixed | **Fixed on the list page only — see R-011.** |
| F-012 lookups truncate at 100 | fixed | **Confirmed fixed.** `form.tsx:76,81` request `per_page: 100` (the real cap) and drive it from a search box. |
| F-013 no archive lifecycle UI | fixed | **Confirmed fixed.** Archive + restore + archive-scope filter present, all gated on `crm.price_agreements.manage`. |
| F-014 no direct regression coverage | fixed | **Confirmed.** `CustomerProductPricingTest.php` covers the module directly. |
| F-015 role matrix unconfirmed | deferred | **Still open (R-008).** |

**2 of 15 prior findings still reproduce** (F-007, F-015 — both were explicitly
deferred pending a human decision, and both still need one). The other 13 are
genuinely fixed. This re-audit adds 13 new findings, one of which is a money
defect more serious than anything in the original list.

## Pricing invariants executed

Every row was measured. "Probe" names the mechanism.

| Invariant | Measured result | Probe |
|---|---|---|
| Overlapping windows, same customer+product | **REFUSED** — `BusinessRuleException`, "A price agreement already exists for this customer/product in the selected date range." | service `create()` after an existing 2026-01-01..06-30 |
| Windows touching on one day (…06-30 / 06-30…) | **REFUSED** — bounds are inclusive on both ends, so a shared day is an overlap | service `create()` |
| Windows adjacent (…06-30 / 07-01…) | **ACCEPTED** | service `create()` |
| Inverted window (end before start), ISO dates | **REFUSED** twice — FormRequest `after_or_equal`, and the service backstop | HTTP POST + service |
| Inverted window via partial update (only `effective_from`), ISO | **REFUSED** by the service backstop (422 on `effective_to`) | HTTP PUT |
| Inverted window via partial update, **non-ISO** (`12/01/2026`) | **ACCEPTED — 200 OK**, persisted `2026-12-01 .. 2026-03-31` | HTTP PUT → **R-002** |
| Open-ended window (`effective_to: null`) | **REFUSED** — 422 "required"; column is `is_nullable = NO` | HTTP POST + `information_schema` → **R-007** |
| Window entirely in the past | `resolve()` throws `NoPriceAgreementException`; SO create returns a field-scoped 422 | service + HTTP POST /sales-orders |
| **Insertion-order determinism**, overlap sharing one `effective_from` | **NON-DETERMINISTIC — ₱80.00 when inserted A-then-B, ₱100.00 when B-then-A** for the identical customer/product/date | two direct inserts in both orders → **R-003** |
| Insertion-order determinism, overlap with *different* `effective_from` | Deterministic — ₱80.00 both orders (later `effective_from` wins) | same, both orders |
| Database-level overlap constraint | **ABSENT** — `pg_constraint` on `product_price_agreements` holds only the PK and 2 FKs | `pg_constraint` query → **R-003** |
| Concurrent-write serialization | **REAL** — a second session blocked: `while locking tuple (0,8) in relation "products"` | two `psql` sessions, `statement_timeout` |
| Tier **gap** (does qty 100 fall between 1–99 and 101–200?) | **STRUCTURALLY IMPOSSIBLE** — a tier declares only `min_qty`, so tiers are half-open ranges with no upper bound to gap against. qty 1…99.99 → ₱12.00, 100+ → ₱10.00 | `resolveUnitPrice()` at 10 quantities |
| Tier **overlap** / **inverted bounds** (min > max) | **STRUCTURALLY IMPOSSIBLE** — same reason; there is no `max_qty` to invert | schema + `assertTierContract()` |
| Duplicate `min_qty` | **REFUSED** — 422 at both layers | HTTP POST |
| `min_qty` = 0 | **REFUSED** — 422 "must be at least 1" | HTTP POST |
| `pricing_method: tiered` with no tiers | **REFUSED** — 422 "Tiered pricing requires at least one price tier." | HTTP POST |
| Descending qty with **ascending** price (volume penalty) | **ACCEPTED** — min_qty 10 → ₱10.00, 100 → ₱50.00 | service `create()` → **R-009** |
| **Quantity below the lowest tier** | Charged the **lowest tier's bulk price**, not the operator's fallback: tiers from min_qty 10, `price` ₱99.99, qty 1 → **₱12.00** on a persisted sales order | `resolveUnitPrice()` + real SO via `SalesOrderService::create()` → **R-001** |
| Fractional quantity money exactness | ₱3.33 × 7.77 = 25.8741 → persisted **25.87** (half-up, defined direction) | real SO |
| Which date prices a line | The **delivery date**, not the order date: order 2026-08-30, delivery 2026-09-15, windows Aug ₱10 / Sep ₱20 → charged **₱20.00** | real SO |
| Archived (soft-deleted) **customer** | `resolve()` throws — cannot price an order | service |
| Archived (soft-deleted) **product** | `resolve()` throws — cannot price an order | service |
| Archived references on read surfaces | Agreement **still listed**, and `forCustomer` still returns it (count=1), chipped from dates alone | `list()` + HTTP GET → **R-005** |
| Permission gate, every endpoint | **All 403.** list, show, forCustomer, POST, PUT, DELETE, PATCH restore, GET /products | 8 HTTP calls with a wrong-permission actor |
| Raw integer id in a payload | **None.** No `"id": <int>` in the list body; raw-int route binding is gated to `environment('testing')` | regex over the response body + `HasHashId.php:33,58` |
| Existence oracle in an error body | **None.** `GET /price-agreements/999999` → 404 with an empty `message` and no id | HTTP GET |
| Raw integer id as a list **filter** | **Accepted in every environment** — `?customer_id=<int>` returned 2 rows | HTTP GET → **R-013** |
| Dead surfaces | **None.** Every one of the 7 pricing API routes has an SPA caller; every pricing/product page file is routed | grep of `spa/src` against `routes.php` + `crmRoutes.tsx` |

Not verifiable in this harness, stated plainly: a two-process race on
`PriceAgreementService::create()` could not be driven through PHPUnit, because
`RefreshDatabase` holds its rows in an uncommitted transaction that a second
connection cannot see (a first attempt appeared to show "no lock" for exactly
that reason and was discarded as a probe artifact). The lock *statement* was
verified to block with two real `psql` sessions instead, which is what the
serialization argument rests on.

## Findings

### R-001 — A tiered agreement charges the bulk price below the lowest tier, while the UI promises the fallback price

- Classification: **Broken** (money) — but resolving it decides what a customer pays, so it is raised as a **question**, not fixed.
- Evidence:
  - `api/app/Modules/CRM/Services/PriceAgreementService.php:195-197` — below the smallest `min_qty` the resolver returns `$lowest['unit_price']`, i.e. the *lowest tier's* price. `$agreement->price` is used only if a tier row has no `unit_price` key at all.
  - `spa/src/pages/crm/price-agreements/form.tsx:206` labels the `price` field **"Fallback price"** when the method is tiered.
  - `spa/src/pages/crm/price-agreements/form.tsx:268` states: *"Enter tiers from the smallest to largest quantity. **The fallback price applies below the first threshold.**"*
  - Nothing requires the first tier to start at `min_qty` 1 — `assertTierContract()` (`:321`) only requires `>= 1`, and the SPA's `min={1}` is a floor, not a mandate.
- Measured end-to-end: agreement with `price` ₱99.99 and tiers `[10 → ₱12.00, 100 → ₱10.00]`; a real sales order for **quantity 1** persisted `unit_price = 12.00`, `subtotal = 12.00`. The operator entered ₱99.99 as the fallback and was told in the form that it applies. The customer was billed ₱12.00 — **₱87.99 per unit under the stated price**.
- Impact: on every tiered agreement whose first tier does not start at 1, all sub-threshold orders are billed at the bulk rate. `price` is stored, displayed, required, labelled as the fallback — and never read. Two plausible intents disagree by 8x on the same row.
- Question for the owner (pick one; both are one-line changes that bill differently):
  1. `price` *is* the fallback → change `:195-197` to return `Money::round2((string) $agreement->price)`. Matches the UI and the operator's mental model. Re-prices existing sub-threshold lines upward on any new order.
  2. The lowest tier *is* the fallback → keep the resolver, delete the "Fallback price" label and the sentence at `:268`, rename the field "List price (not used for tiered pricing)", and require the first tier to be `min_qty = 1` so the case cannot arise.
- Recommendation: do not ship either without the decision. Existing sales orders froze their `unit_price` at create time and are unaffected either way.

### R-002 — The inverted-window backstop compared raw date strings, so a non-ISO bound persisted an impossible window

- Classification: **Broken**. **FIXED in this session.**
- Evidence (before): `api/app/Modules/CRM/Services/PriceAgreementService.php:214` — `if ($from > $to)`, a PHP string comparison. Both FormRequests validate `effective_from`/`effective_to` with `date`, not `date_format:Y-m-d`, so either bound can arrive in any `strtotime()`-parseable shape. `'12/01/2026' > '2026-03-31'` is **false** (`'1' < '2'`), so the guard passed.
- Reachability: `UpdatePriceAgreementRequest` marks both dates `sometimes`, so a partial update that sends only `effective_from` skips `after_or_equal:effective_from` entirely — the service backstop is the only check, and its own comment at `:216-220` says so.
- Measured (before): `PUT /api/v1/crm/price-agreements/{id}` with `{"effective_from": "12/01/2026"}` against a stored `2026-01-01 .. 2026-03-31` returned **200 OK** and persisted `effective_from = 2026-12-01`, `effective_to = 2026-03-31`.
- Impact: no date can satisfy `effective_from <= d AND effective_to >= d` inside an inverted window, so `resolve()` never matches it. That customer/product silently loses **every** price, and the only symptom is a 422 "No active price agreement" on the next sales order, pointing at the product rather than the corrupt window. The identical request in ISO form was correctly refused, so the failure is purely a formatting loophole.
- Fix applied: normalise both bounds with `CarbonImmutable::parse(...)->toDateString()` before comparing, which also stops the `whereDate()` bindings depending on the server's `DateStyle` to interpret an ambiguous bound. No previously-accepted input is now rejected — only genuinely inverted windows are.

### R-003 — No database-level overlap guarantee, and `resolve()` is order-dependent if one exists

- Classification: **Missing**.
- Evidence:
  - `api/app/Modules/CRM/Services/PriceAgreementService.php:158-166` — `resolve()` orders by `effective_from` **descending only**, with no tiebreaker. Two matching rows sharing an `effective_from` are returned in whatever order PostgreSQL yields.
  - `pg_constraint` on `product_price_agreements` contains only `product_price_agreements_pkey` and the two FKs. `0070_create_product_price_agreements_table.php:19-31` adds two plain indexes; there is no `EXCLUDE USING gist` and no partial unique index.
  - `api/database/seeders/PriceAgreementSeeder.php:71` writes with `PriceAgreement::firstOrCreate()`, bypassing `PriceAgreementService` and therefore `assertNoOverlap()` entirely. Its `firstOrCreate` key is `(product_id, customer_id, effective_from)`, so it matches on the start date only.
  - The overlap guard landed with the 2026-08-25 work; rows written before it were never reconciled.
- Measured: two overlapping rows sharing `effective_from = 2026-01-01` (₱100.00 ending 12-31, ₱80.00 ending 06-30), inserted in both orders. Resolving 2026-03-01 returned **₱80.00** for A-then-B and **₱100.00** for B-then-A. Same customer, same product, same date, two different prices decided by physical row order. With *different* `effective_from` values the result was stable (₱80.00 both ways).
- Current exposure: the dev database has **0 overlaps across 15 rows**, so this is latent, not realised. All three service write paths (create/update/restore) are closed and their row lock genuinely serializes (verified with two `psql` sessions blocking on `products`).
- Impact: the invariant rests entirely on one code path with no data backstop. A seeder, a CSV import, a data fix, or any future writer that does not go through the service reintroduces an ambiguous price that no test would catch and no error would report.
- Recommendation: (a) `CREATE EXTENSION btree_gist` + an `EXCLUDE USING gist (customer_id WITH =, product_id WITH =, daterange(effective_from, effective_to, '[]') WITH &&) WHERE (deleted_at IS NULL)` constraint; (b) a reconciliation query run before it, since the constraint will refuse to build over existing overlaps; (c) route `PriceAgreementSeeder` through the service. Adding a `->orderByDesc('id')` tiebreaker to `resolve()` would make the answer *stable* but would also decide which price wins — that is a pricing decision, not a containment fix, and is deliberately not applied here.

### R-004 — Per-product revenue-account override is still unreachable (carried F-007)

- Classification: **Missing**. Unchanged since 2026-08-24; re-verified absent.
- Evidence: `0175_add_revenue_account_id_to_products.php` adds the column and `DeliveryService` consumes it, but `revenue_account_id` appears in none of `StoreProductRequest.php`, `UpdateProductRequest.php`, `ProductResource.php`, `spa/src/pages/crm/products/form.tsx`.
- Impact: every product stays on the delivery-invoice fallback GL account unless the column is edited outside the API.
- Still blocked on the same thing: Accounting/GL ownership, because it changes invoice posting.

### R-005 — `is_currently_active` ignores whether the product or customer is archived

- Classification: **Incomplete**.
- Evidence: `api/app/Modules/CRM/Models/PriceAgreement.php:54-59` computes the flag from `effective_from`/`effective_to` alone; `PriceAgreementResource.php:37` ships it; three surfaces render it as a status chip — `spa/src/pages/crm/price-agreements/index.tsx:85`, `spa/src/pages/accounting/customers/detail.tsx:258`, `spa/src/pages/crm/customers/detail.tsx:120`.
- Measured: after soft-deleting the product, `list()` still returned the agreement and `GET /crm/customers/{id}/price-agreements` still returned it (`count=1`) — while `resolve()` refuses it.
- Impact: an operator reads a green **Active** chip for a price that every sales order will reject. This is the pattern `global-search` found (archived counterparties reaching live surfaces), except here the transaction is correctly blocked and only the human is misled.
- Recommendation: derive a third state from reference activity (Active / Expired / **Unavailable — product or customer archived**) rather than overloading "Expired", which would be equally wrong.

### R-006 — Switching a tiered agreement to flat is refused, citing a field the caller did not send

- Classification: **Incomplete**.
- Evidence: `api/app/Modules/CRM/Requests/Concerns/ValidatesPriceAgreementTiers.php:23` falls back to `$agreement?->tiers` when the payload omits `tiers`, then `:30-33` rejects the combination "flat + tiers present". `PriceAgreementService::assertTierContract()` (`:308-310`) does the same at the service boundary.
- Measured: `PUT /api/v1/crm/price-agreements/{id}` with `{"pricing_method": "flat"}` on a tiered agreement → **422** "Price tiers are only allowed for tiered pricing." The stored row remained `tiered`, and `resolveUnitPrice` kept returning ₱10.00.
- Reachability: **not reachable from the SPA** — `form.tsx:127-129` always sends `tiers` explicitly (`null` for flat). API-only.
- Impact: an API client cannot express "make this flat" without knowing to send `tiers: null`, and the error names a field it never supplied.
- Recommendation: treat an explicit `pricing_method: flat` as clearing tiers. Deferred rather than applied because it changes an existing agreement's effective price the moment it succeeds (₱10.00 tiered → the flat `price`).

### R-007 — No open-ended price window

- Classification: **Incomplete** / question.
- Evidence: `0070_create_product_price_agreements_table.php:25` declares `effective_to` NOT NULL (`information_schema` confirms `is_nullable = NO`); both FormRequests mark it `required`.
- Measured: `effective_to: null` → 422 "The effective to field is required."
- Impact: "this is the price until further notice" cannot be expressed. Operators must invent a sentinel far-future date, and the day a window lapses **every** new sales order for that customer/product fails with a 422 — no warning, no grace, no expiry alert anywhere in the module.
- Question: is a mandatory expiry the intended commercial control (contracts are always dated, so a lapse *should* stop trading), or an accident? If intended, an expiry-warning surface is missing. Do not relax the column without deciding.

### R-008 — Pricing is system-admin-only, and the documentation disagrees (carried F-015)

- Classification: **Incomplete** / question. Unchanged; re-verified.
- Evidence: `RolePermissionSeeder.php:308-309` declares `crm.price_agreements.view` and `.manage`, but **no role block grants the `crm` module** — `grep "module('crm'"` returns nothing, and no role lists an individual `crm.price_agreements.*` slug. Only `system_admin`'s `'permissions' => '*'` reaches them. `finance_officer` gets `module('crm_commissions')`, a different key.
- Conflict: `docs/USER-MANUAL.md:166-172` describes finance users creating sales orders against active pricing.
- Impact: either intentional (pricing is a controlled master-data function) or the sales/finance roles cannot do their documented job. The code does not say which.
- Recommendation: confirm the matrix with the owner, then align registry, seeded grants, route guards and SPA visibility together. Do not broaden permissions by assumption.

### R-009 — No monotonicity check on tier prices

- Classification: **Polish**.
- Evidence: `assertTierContract()` (`PriceAgreementService.php:301-339`) validates that `min_qty` is ascending, unique and >= 1, and that `unit_price` is a non-negative 2-decimal amount. It does not relate prices to each other.
- Measured: tiers `[10 → ₱10.00, 100 → ₱50.00]` were **accepted** — buying more costs more per unit.
- Impact: a transposed pair of tier prices is a silent overcharge on large orders. Some contracts legitimately price small lots lower, so this may be intentional latitude.
- Recommendation: warn rather than refuse, or add an explicit opt-in. Listed as Polish because refusing it outright could reject legitimate existing agreements.

### R-010 — A deep link to an archived agreement 404s, and the body names the model class

- Classification: **Polish**.
- Evidence: `api/app/Modules/CRM/routes.php:39` — `GET /price-agreements/{priceAgreement}` has no `->withTrashed()`, unlike the restore route at `:43-45`. The archive-scope filter on the list *does* surface archived rows.
- Measured: `GET /crm/price-agreements/{archived}` → **404**, body `"No query results for model [App\Modules\CRM\Models\PriceAgreement]"`.
- Reachability: **not reachable from the list** — `price-agreements/index.tsx:91-101` renders a Restore button for archived rows and never an Edit link, and there is no whole-row click. Direct URL / bookmark only.
- Impact: minor. The FQCN in the message is framework-default route-binding behaviour, not module code.

### R-011 — Row click on the CRM customer detail sends a view-only user to a forbidden route

- Classification: **Polish**.
- Evidence: `spa/src/pages/crm/customers/detail.tsx:267` — `onRowClick={(r) => navigate('/crm/price-agreements/${r.id}/edit')}`, unconditional. That route is guarded by `crm.price_agreements.manage` (`crmRoutes.tsx:68-69`), while the panel itself only needs `.view` (`:260`).
- Impact: a `.view`-only user clicking a price row lands on the 403 page. This is the same defect F-011 fixed on the list page, not carried to this surface.

### R-012 — Seeded prices are PHP float literals

- Classification: **Polish**.
- Evidence: `api/database/seeders/PriceAgreementSeeder.php:26-51` — `'WB-001' => 22.50`, `'RC-002' => 70.00`, and 12 more, all unquoted floats written to a `decimal(15,2)` column.
- Impact: none observable today; every current value round-trips exactly. It contradicts the documented rule that money is never a float, and it is the one remaining float in the pricing path after F-005 removed them from the services — so the next value someone adds is unguarded.

### R-013 — Raw integer ids work as list filters in every environment

- Classification: **Polish**, and **not owned by this module**.
- Evidence: `api/app/Common/Support/HashIdFilter.php:19` accepts `ctype_digit` input unconditionally, whereas `HasHashId::resolveRouteBinding` (`HasHashId.php:33,58`) gates the same shortcut behind `app()->environment('testing')`.
- Measured: `GET /crm/price-agreements?customer_id=<raw int>` → 200, 2 rows.
- Impact: a permitted user can map integer ids to customer names, which HashIDs exist to prevent. The caller already sees every agreement, so this is enumeration comfort rather than escalation. Repo-wide shared helper — flagged here, to be fixed where it lives.

## Contract with `sales-orders` (M033) — established, not assumed

CLAUDE.md records an open question in `purchase-requests` about whether a catalog
price is an enforced standard or a requester estimate. The analogous question
here has a clear answer in the code, and the SPA agrees with it:

- **A sales order may not override an agreed price.** Neither `StoreSalesOrderRequest`
  nor `UpdateSalesOrderRequest` accepts `unit_price` — only `product_id`,
  `quantity` and `delivery_date`. `SalesOrderService.php:351,430` computes
  `unit_price` solely from `PriceAgreementService::resolveUnitPrice()`, and the
  SPA exposes no editable price field. The agreement is an **enforced price**,
  and no role can override it — not even `system_admin`.
- **No agreement means no order.** A missing or lapsed window is a hard 422
  scoped to `items.{n}.product_id`, with the message "No active price agreement
  for this customer and product on the selected delivery date."
- **Lines price at the delivery date, not the order date** (`SalesOrderService.php:343-345`),
  and the resolved price is frozen onto the line at create time. Re-pricing
  happens only on a draft update; a confirmed order whose delivery slips keeps
  its original price.

This is a coherent contract and no contradiction was found between the service
comment and the SPA — unlike `purchase-requests`. It does mean R-001, R-003 and
R-007 are all felt by M033 first: an under-priced line, an ambiguous price, and
a blocked order respectively. M033 is `📋 Plan Ready` and was read for context
only; nothing in it was changed.

## Verification

- Module suite, isolated database `ogami_test_pricing`: **13 passed, 61 assertions** (baseline before this session's change: 12 passed, 45 assertions).
- Whole CRM feature suite, same database: **95 passed, 306 assertions**, no regressions.
- Row-lock serialization: two concurrent `psql` sessions, second cancelled by `statement_timeout` with `while locking tuple (0,8) in relation "products"`.
- `php -l`: clean on both changed files.
- `phpstan analyse` on both changed files: **[OK] No errors**.
- `pint --test` fails on both changed files with fixer lists **byte-identical** to the same files extracted from `HEAD` (`PriceAgreementService.php`: 10 fixers; `CustomerProductPricingTest.php`: `concat_space`). Inherited, not introduced.
- Dev database checked for live exposure to R-003: **0 overlapping agreements across 15 rows**.
- SPA: not re-run. No SPA file was changed in this session.

## Disposition

One finding fixed (**R-002**, a Broken money-integrity defect whose fix only
refuses ambiguous input and rejects nothing previously accepted). Everything
else deferred: R-001 and R-006 change what a customer is charged, R-003 needs a
migration plus data reconciliation, R-004 and R-008 need owner decisions, R-005
and R-009 through R-013 are contained but not worth reopening the pricing path
for at the tail of this session. Released `🔁 Needs Re-audit`.
