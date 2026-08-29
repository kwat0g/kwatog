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

## Re-audit session — 2026-08-30

M032 was **RECLAIMED** from an orphaned lock (107h old, owner
`codex-coordinator-blocker-quarantine`, claimed 2026-08-25T11:51:42Z).

**Predecessor state established before planning anything:** the crashed
session's work was already **committed**, in `167de85e chore: remaining
uncommitted work from ~50 crashed audit sessions`. `git status` for
`api/app/Modules/CRM`, `spa/src/pages/crm`, `spa/src/api/crm`,
`spa/src/types/crm.ts` and `api/tests/Feature/CRM` was clean at session start,
and this log was fully written rather than a blank scaffold. So there was
nothing to recover — this session re-measured the claimed fixes and audited
fresh. **13 of the 15 prior findings are genuinely fixed; 2 still reproduce**
(F-007 → R-004 and F-015 → R-008, both previously deferred pending a human
decision, both still needing one). See the dated re-audit section of
`audit-report.md` for the per-finding re-measurement table and the full
invariant table.

### Fixed

**R-002 — the inverted-window backstop compared raw date strings, so a non-ISO
bound persisted an impossible price window.**

- File: `api/app/Modules/CRM/Services/PriceAgreementService.php`
- Before (`:207-224`, `assertNoOverlap()` opened directly on the comparison):

  ```php
  private function assertNoOverlap(
      int $productId,
      int $customerId,
      string $from,
      string $to,
      ?int $exceptId = null,
  ): void {
      if ($from > $to) {
  ```

- After (`:207-236`, plus `use Carbon\CarbonImmutable;` at `:17`):

  ```php
  ): void {
      // Normalise to Y-m-d BEFORE comparing. Both FormRequests accept `date`,
      // not `date_format:Y-m-d`, so either bound can arrive in any format
      // strtotime() understands. A raw string comparison then sorts a non-ISO
      // bound wrongly against an ISO one — '12/01/2026' < '2026-03-31' because
      // '1' < '2' — so the guard below silently passed and an impossible
      // window was persisted. ...
      $from = CarbonImmutable::parse($from)->toDateString();
      $to = CarbonImmutable::parse($to)->toDateString();

      if ($from > $to) {
  ```

- Why it was reachable: `UpdatePriceAgreementRequest` marks both dates
  `sometimes`, so a partial update sending only `effective_from` skips
  `after_or_equal:effective_from` entirely and this service backstop is the only
  remaining check — as its own comment at `:216-220` already noted.
- Measured before: `PUT /api/v1/crm/price-agreements/{id}` with
  `{"effective_from": "12/01/2026"}` over a stored `2026-01-01 .. 2026-03-31`
  returned **200 OK** and persisted `2026-12-01 .. 2026-03-31`. The identical
  request as `2026-12-01` was correctly refused with 422 — the defect was purely
  the string comparison.
- Impact of the old behaviour: nothing satisfies
  `effective_from <= d AND effective_to >= d` inside an inverted window, so
  `resolve()` never matched it and that customer/product silently lost **every**
  price. The only symptom was a 422 "No active price agreement" on the next
  sales order, pointing at the product rather than the corrupt window.
- Containment: the fix rejects nothing that was previously accepted. It closes a
  formatting loophole in a guard that already refused the same window in ISO
  form, and it also stops the `whereDate()` bindings relying on the server's
  `DateStyle` to interpret an ambiguous bound. No price changes.
- Regression lock: `api/tests/Feature/CRM/CustomerProductPricingTest.php:258-297`
  — `test_inverted_window_is_refused_whatever_date_format_the_caller_uses`,
  looping four date shapes (`2026-12-01`, `12/01/2026`, `01-Dec-2026`,
  `December 1, 2026`) and asserting both the 422 and that the stored window is
  never inverted. **Confirmed RED against unmodified source**: it failed with
  "Expected response status code [422] but received 200" at the assertion, after
  5 passing assertions (the ISO case passes, then `12/01/2026` slips through) —
  so it genuinely exercises the defect and is not a lock that would pass either
  way.

### Deferred — see the 2026-08-30 action plan for ordering and rationale

- **R-001** (Broken, money) — a tiered agreement charges the lowest tier's bulk
  price below the first threshold while the SPA labels `price` "Fallback price"
  and states it applies there. Measured end-to-end: a real sales order for
  quantity 1 with tiers from `min_qty` 10 and `price` ₱99.99 persisted
  `unit_price = 12.00` — ₱87.99/unit under the stated price. **Not fixed
  deliberately**: both candidate one-line fixes bill different amounts, so this
  needs the commercial owner, and this session must never unilaterally change
  what a customer is charged.
- **R-003** (Missing) — no database-level overlap guarantee; `resolve()` returned
  ₱80.00 vs ₱100.00 for the same customer/product/date depending purely on
  insertion order when two overlapping rows share an `effective_from`. Write
  paths are closed and the row lock is real (verified with two `psql` sessions),
  but `PriceAgreementSeeder.php:71` bypasses the service and pre-existing rows
  were never reconciled. Needs a migration plus a data decision.
- **R-004** (Missing, carried F-007) — `revenue_account_id` still unreachable.
  Needs Accounting/GL ownership.
- **R-005** (Incomplete) — `is_currently_active` is date-only, so an agreement
  whose product or customer is archived reads Active on three surfaces while
  `resolve()` refuses it.
- **R-006** (Incomplete) — `pricing_method: flat` on a tiered agreement is a 422
  naming a field the caller did not send. SPA-unreachable; API only. Deferred
  because succeeding changes the agreement's effective price.
- **R-007** (Incomplete/question) — `effective_to` is NOT NULL, so no open-ended
  window exists and a lapsed window hard-blocks every new order with no expiry
  warning anywhere.
- **R-008** (Incomplete, carried F-015) — no role but `system_admin` reaches
  `crm.price_agreements.*`; `docs/USER-MANUAL.md:166-172` implies otherwise.
- **R-009** (Polish) — no tier price monotonicity check; `[10 → ₱10.00,
  100 → ₱50.00]` accepted.
- **R-010, R-011, R-012** (Polish) — archived-agreement deep link 404s;
  view-only row click lands on a 403 route; seeded prices are float literals.
- **R-013** (Polish, not this module's) — `HashIdFilter::decode` accepts raw
  integers in every environment, so `?customer_id=<int>` filters the list.

### Verification

- Module suite in the isolated database `ogami_test_pricing`:
  `docker compose run --rm -e DB_DATABASE=ogami_test_pricing api php artisan test tests/Feature/CRM/CustomerProductPricingTest.php --no-coverage`
  → **13 passed, 61 assertions**. Baseline before this session: 12 passed, 45
  assertions.
- Whole CRM feature suite, same isolated database: **95 passed, 306 assertions**.
  No regressions from the change.
- `php -l` clean on both changed files.
- `phpstan analyse app/Modules/CRM/Services/PriceAgreementService.php tests/Feature/CRM/CustomerProductPricingTest.php --memory-limit=1G`
  → **[OK] No errors**.
- `pint --test` fails on both changed files, with fixer lists **byte-identical**
  to the same files extracted from `HEAD` via `git show` and re-tested
  (`PriceAgreementService.php`: `new_with_parentheses, control_structure_braces,
  method_chaining_indentation, unary_operator_spaces, braces_position,
  statement_indentation, not_operator_with_successor_space,
  blank_line_before_statement, binary_operator_spaces, phpdoc_align`;
  `CustomerProductPricingTest.php`: `concat_space`). Inherited at HEAD, not
  introduced here. No formatter was run in write mode.
- Row-lock serialization checked outside the harness with two concurrent `psql`
  sessions: the second was cancelled by `statement_timeout` with
  `while locking tuple (0,8) in relation "products"`.
- Live exposure to R-003 checked against the dev database: **0 overlapping
  agreements across 15 rows**.
- SPA checks not run — no SPA file was changed in this session.
- Two scratch probe test classes (`ZzPricingProbeTest`, `ZzPricingProbe2Test`)
  produced the invariant measurements and were **deleted** before release;
  `git status` for `api/tests/Feature/CRM` shows only
  `CustomerProductPricingTest.php`.
- Probe database `ogami_test_pricing` dropped after the final run.

### Could not verify

- A genuine two-process race on `PriceAgreementService::create()`. `RefreshDatabase`
  holds its rows inside an uncommitted transaction that a second connection
  cannot see, so a second connection's `SELECT ... FOR UPDATE` matches zero rows
  and returns instantly — a first attempt appeared to report "no lock" for
  exactly that reason and was discarded as a probe artifact rather than written
  up as a finding. The lock *statement* was instead proven to block with two real
  `psql` sessions, which is what the serialization claim rests on. The residual
  risk is writers that never take the lock at all, which is R-003.

Final disposition: **🔁 Needs Re-audit** — R-002 fixed and verified; R-001,
R-003 through R-013 deferred, several pending owner decisions.
