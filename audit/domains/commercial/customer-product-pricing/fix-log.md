# M032 — customer-product-pricing fix log

Audit date: 2026-08-24
Final disposition: Plan Ready

## Before

- M032 was claimed from Not Started.
- Audit artifacts were inventory scaffolds.
- No production source changes were made in this session.

## Decision

No fixes were applied. The majority of findings involve Money arithmetic, effective-dated pricing, cross-module dependencies, permissions, or missing API regression coverage. Those changes require a coordinated implementation session and product-owner decisions.

## Verification after audit

- Container CRM suite: 43 tests passed, 131 assertions.
- SPA typecheck: passed.
- Targeted SPA ESLint with zero warnings: passed.
- PHP syntax checks for audited API files: passed.

## Deferred findings

All findings remain pending implementation and re-audit: F-001 through F-015. This is a Plan Ready handoff, not a claim that any finding is fixed.

## Interrupted-session recovery

The parallel implementation worker terminated before completing Step 8 and did not produce an item-level fix log. Treat F-001 through F-015 as pending until the current worktree is checked against the action plan and each item is logged and verified by the next session.

## Resumed implementation session — 2026-08-25

M032 was claimed from Needs Re-audit. The interrupted worktree already contained
unlogged partial implementation changes; they were verified and hardened in this
session. No files outside the customer/product-pricing scope were intentionally
modified. The shared ogami_test database was concurrently reset by another
session, so final backend verification used the isolated ogami_m032_test database.

### Fixed and verified

- F-001 — api/app/Modules/CRM/routes.php:33-45, api/app/Modules/CRM/Services/ProductService.php:117-126, and api/app/Modules/CRM/Services/PriceAgreementService.php:128-149 now bind archived rows and restore through transactional service paths. Restore and conflict behavior are covered by CustomerProductPricingTest.php:33-69.
- F-002 — api/app/Modules/CRM/Services/ProductService.php:87-114 now applies an explicit conservative archive policy: active BOMs, non-archived price agreements, and sales-order lines block product archival, with deterministic dependency locking. The matching explanation is in spa/src/pages/crm/products/index.tsx:169-177; coverage is in CustomerProductPricingTest.php:145-183.
- F-003 — api/app/Modules/CRM/Requests/StorePriceAgreementRequest.php:36-43, UpdatePriceAgreementRequest.php:36-43, StoreSalesOrderRequest.php:34-42, UpdateSalesOrderRequest.php:34-42, and PriceAgreementService.php:156-171,262-274 enforce active, non-trashed references. SalesOrderService.php:144-179,297-306,387-389 rechecks and locks references inside write transactions. Coverage is in CustomerProductPricingTest.php:116-143,193-205.
- F-004 — api/app/Modules/CRM/Services/PriceAgreementService.php:77-149,225-259 serializes agreement create/update/restore checks on sorted product/customer locks and re-runs overlap validation on restore. Restore-overlap and CRUD coverage is in CustomerProductPricingTest.php:45-69,72-111.
- F-005 — api/app/Modules/CRM/Services/PriceAgreementService.php:183-198 returns decimal strings, while SalesOrderService.php:308-342,399-419 uses Money for unit, line, subtotal, VAT, and total arithmetic and preserves decimal quantities. Exact regression coverage is in CustomerProductPricingTest.php:303-328 and TaxPolicyCalculationTest.php:38-69.
- F-006 — api/app/Modules/CRM/Requests/Concerns/ValidatesPriceAgreementTiers.php:11-55, both price-agreement requests, PriceAgreementService.php:277-346, PriceAgreementResource.php:31-36, spa/src/types/crm.ts:21-63, and spa/src/pages/crm/price-agreements/form.tsx:22-59,68-130,219-269 now share a flat/tiered contract, ascending unique thresholds, and centavo-safe prices. Coverage is in CustomerProductPricingTest.php:72-111,211-249.
- F-008 — api/app/Modules/CRM/Requests/StoreProductRequest.php:23-35 and UpdateProductRequest.php:24-36 normalize and validate active catalog UOM codes; the product form uses the canonical UOM lookup. Coverage is in CustomerProductPricingTest.php:185-208.
- F-009 — api/app/Modules/CRM/Services/ProductService.php:23-70,132-141 now guards BOM/inspection enrichment and BOM filtering against optional schemas instead of issuing unconditional cross-module queries.
- F-010 — api/app/Modules/CRM/Services/PriceAgreementService.php:44-53 implements product/customer search, exposed by spa/src/pages/crm/price-agreements/index.tsx:137-142; coverage is in CustomerProductPricingTest.php:251-262.
- F-011 — spa/src/pages/crm/price-agreements/index.tsx:27,89-135 gates create/edit/archive/restore affordances with crm.price_agreements.manage, matching the route guards.
- F-012 — spa/src/pages/crm/price-agreements/form.tsx:71-82,155-199 uses server-side search selectors rather than a silently truncated 200-row preload; the existing product/customer services cap each request at 100.
- F-013 — spa/src/api/crm/priceAgreements.ts:21-24 and spa/src/pages/crm/price-agreements/index.tsx:89-123,172-183 expose permission-aware archive/restore controls and an archive filter.
- F-014 — api/tests/Feature/CRM/CustomerProductPricingTest.php:22-328 adds focused permission, CRUD, restore, lifecycle, overlap, tier, UOM, search, resolution, and exact-money coverage: 12 tests, 45 assertions passed in the isolated test database.

### Deferred pending human decision

- F-007 — revenue_account_id still needs Accounting/GL ownership confirmation before exposing an account selector or changing the product API. No source behavior was guessed.
- F-015 — the intended non-admin CRM role matrix still needs product-owner confirmation. The current implementation remains system-admin-only; no seeded permission broadening was guessed.

### Verification

- Isolated M032 suite: 12 passed, 45 assertions.
- Related CRM regression suite in the isolated database: 15 passed, 26 assertions.
- Targeted CRM SPA ESLint: passed with zero warnings.
- Targeted M032 PHP syntax checks: passed.
- Full SPA typecheck remains blocked by an unrelated pre-existing syntax error in the dirty dependency file spa/src/pages/crm/sales-orders/create.tsx:169 (literal escaped backticks); it was not modified because sales-orders is outside this module’s scope.

F-007 and F-015 remain pending, so this module requires re-audit after those decisions and implementation.

## Verification session — 2026-08-25

The interrupted implementation was re-checked without re-running the full discovery/hardening/polish audit:

- F-001 through F-006 and F-008 through F-013 remain represented by the current scoped source changes and their focused regression paths. The isolated command `DB_DATABASE=ogami_m032_test php artisan test --filter=CustomerProductPricingTest` passed 12 tests with 45 assertions.
- Scoped PHP syntax checks passed for the changed M032 controllers, models, requests, resource, services, routes, and `CustomerProductPricingTest.php`.
- Targeted ESLint passed with zero warnings for the pricing/product pages, pricing API, and CRM types. Repository-wide lint remains blocked by pre-existing unrelated errors, including the invalid character at `spa/src/pages/crm/sales-orders/create.tsx:169`; that file is outside M032 and was not changed here.
- F-007 is still pending: `StoreProductRequest.php:23-28`, `UpdateProductRequest.php:24-29`, `ProductResource.php:15-22`, and `spa/src/pages/crm/products/form.tsx:179-198` do not expose `revenue_account_id`. This requires Accounting/GL ownership confirmation before changing the product API or adding an account selector.
- F-015 is still pending: `status.md:5` remains system-admin-only, while the CRM permission catalog is present in `RolePermissionSeeder.php:290-305` without an agreed non-admin grant matrix. Product-owner confirmation is required before broadening permissions.

Final disposition remains `Needs Re-audit`; no additional production source changes were made in this verification session.

## Current audit-session verification — 2026-08-25

M032 was resumed from `Needs Re-audit` under the existing action plan. The
implementation was not re-audited from scratch and no production source files
were changed in this session.

- Container-backed focused verification passed:
  `DB_DATABASE=ogami_m032_test php artisan test --filter=CustomerProductPricingTest`
  — 12 tests, 45 assertions.
- Targeted SPA ESLint passed with zero warnings for the pricing agreement
  API/form/list, product list, and CRM types.
- The scoped CRM PHP syntax sweep passed for `api/app/Modules/CRM` and
  `api/tests/Feature/CRM`.
- A host-side test attempt could not resolve Docker-only PostgreSQL host `db`;
  the isolated container run above is the authoritative verification result.

F-007 and F-015 remain pending and are the only unresolved plan items. F-007
requires Accounting/GL ownership confirmation before exposing
`revenue_account_id`; F-015 requires product-owner confirmation of the
non-admin CRM role matrix. Final disposition remains `Needs Re-audit`.
