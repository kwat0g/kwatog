# M032 — Customer/product pricing action plan

Status: Plan Ready
Audit date: 2026-08-24

## Ordered work

1. Establish commercial master lifecycle invariants: F-001, F-002, F-003.
   - Scope: [medium]/[large]
   - Session recommendation: [separate-recommended]
   - Fix restore binding, define product archive behavior with BOM/agreement dependencies, and reject inactive/trashed customers/products in both request and service paths.

2. Make pricing windows and calculations financially safe: F-004, F-005, F-006.
   - Scope: [large]
   - Session recommendation: [separate-recommended]
   - Serialize overlap checks and restore, replace floats with Money/string arithmetic, and complete the tiered-pricing contract with strict tier validation and UI support.

3. Complete product accounting and unit master integration: F-007, F-008.
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Resolve the revenue-account ownership with Accounting and validate product UOMs against the active catalog.

4. Resolve module boundaries: F-009, F-015.
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Decide whether MRP/Quality enrichment is a hard dependency and confirm the intended non-admin role matrix before changing permissions.

5. Repair list/form usability: F-010, F-011, F-012, F-013.
   - Scope: [small]/[medium]
   - Session recommendation: F-010, F-011, and F-013 [same-session-ok]; F-012 [separate-recommended] if a reusable lookup/pagination component is required.
   - Make search real, hide unauthorized actions, support catalogs larger than 100 rows, and expose archive/restore controls.

6. Add the M032 regression suite: F-014.
   - Scope: [medium]
   - Session recommendation: [separate-recommended]
   - Cover the API surface and negative paths before implementation is marked verified.

## Re-audit acceptance gates

- Archived product and price agreement rows can be restored through authenticated routes.
- Product archive policy is explicit and tested against sales orders, BOMs, and price agreements.
- Inactive and trashed customer/product records cannot be used for new agreements or sales orders.
- Concurrent and restored agreement windows cannot overlap for one customer/product.
- All persisted prices and sales-order totals preserve exact decimal values without float arithmetic.
- Tiered pricing is either fully supported in API and SPA or deliberately removed from the contract.
- Product revenue account and UOM behavior are explicit, authorized, and tested.
- Product list/detail behavior remains valid under the declared module dependency set.
- Search, role-aware actions, large-catalog selectors, and archive controls work in the SPA.
- Direct M032 API tests cover permissions, CRUD, restore, overlap, tier resolution, and lifecycle rejection.

## Session decision

The plan is not small: it includes financial arithmetic, cross-module lifecycle behavior, permission policy, and missing endpoint coverage. Do not apply production fixes in this audit session. Keep M032 at Plan Ready and schedule a coordinated implementation followed by re-audit.

---

# Action plan — re-audit 2026-08-30

Status: 🔁 Needs Re-audit
Supersedes the 2026-08-24 plan above: F-001 through F-006 and F-008 through
F-014 were implemented and are re-verified fixed. Only F-007 (→ R-004) and
F-015 (→ R-008) carry forward. Items are ordered by money impact.

## Done in this session

0. **R-002 — inverted-window backstop compared date strings.** ✅ Fixed.
   - Scope: [small]
   - Session recommendation: `same-session-ok` — applied.
   - Justification for fixing it here rather than deferring: it is a *missing
     validation that refuses ambiguous input*, which the pipeline rule judges on
     containment. It rejects nothing that was previously accepted; it only closes
     a formatting loophole in a guard whose ISO path already refused the same
     window. One private method, two lines plus an import. Logged in `fix-log.md`.

## Ordered work

1. **R-001 — decide, then align, the below-lowest-tier price.**
   - Scope: [small] code, [large] decision
   - Session recommendation: **`separate-recommended`**
   - Why gated: it changes what a customer is charged, by up to 8x on the
     measured example (₱12.00 billed where the UI promised ₱99.99). Both
     candidate fixes are one line and they bill differently, so the decision —
     not the edit — is the work. Needs the commercial owner.
   - Sequence: get the decision → change *either* `PriceAgreementService.php:195-197`
     *or* the two SPA strings at `form.tsx:206,268`, never both → if option 2,
     also require the first tier to be `min_qty = 1` in `assertTierContract()`
     and in the Zod schema → add a regression test pinning the chosen price for
     a sub-threshold quantity.
   - Consumed by `sales-orders` (M033): every tiered line below its first
     threshold reprices. Do not land this in the same session as M033 work.

2. **R-003 — make the no-overlap invariant a database guarantee.**
   - Scope: [large]
   - Session recommendation: **`separate-recommended`**
   - Why gated: a migration plus a data-reconciliation step, and the constraint
     will refuse to build over any pre-existing overlap — so it can fail
     `migrate:fresh` for everyone if a seeder produces one. Cross-module: M033
     consumes `resolve()`.
   - Sequence: reconciliation query first (report overlaps, decide per pair which
     window wins — that is a pricing decision) → route
     `PriceAgreementSeeder.php:71` through `PriceAgreementService::create()` so
     the seed cannot violate the constraint it is about to be measured against →
     `CREATE EXTENSION IF NOT EXISTS btree_gist` +
     `EXCLUDE USING gist (customer_id WITH =, product_id WITH =, daterange(effective_from, effective_to, '[]') WITH &&) WHERE (deleted_at IS NULL)`
     → only then consider a `resolve()` tiebreaker, which by that point is dead
     code and can be omitted.
   - Migration naming: this touches only tables created by `0NNN_` migrations, so
     a numbered prefix is correct. Re-confirm the next unused prefix at the time
     (`ls api/database/migrations | grep '^04'` — do not trust a recorded max;
     other sessions are landing migrations).

3. **R-004 (was F-007) — resolve revenue-account ownership with Accounting.**
   - Scope: [medium]
   - Session recommendation: **`separate-recommended`**
   - Why gated: it changes invoice GL posting. Needs Accounting/GL to own the
     decision, and an authorized account selector implies a permission question.
   - Either expose an authorized `revenue_account_id` selector across request /
     resource / form, or drop the column explicitly. Do not guess.

4. **R-008 (was F-015) — confirm the non-admin CRM role matrix.**
   - Scope: [medium]
   - Session recommendation: **`separate-recommended`**
   - Why gated: alters RBAC. Verified state: no role but `system_admin` reaches
     `crm.price_agreements.*`.
   - Once decided, align `status.md`, `RolePermissionSeeder`, route guards and
     SPA visibility in one change, and add a 403 test per endpoint per role.

5. **R-006 — let an explicit `pricing_method: flat` clear stale tiers.**
   - Scope: [small]
   - Session recommendation: **`separate-recommended`**
   - Why gated: succeeding changes the agreement's effective price the moment it
     lands (tiered ₱10.00 → the flat `price`). Also decide this *after* R-001,
     since R-001 may redefine what `price` means on a tiered row.
   - Not reachable from the SPA; API clients only.

6. **R-005 — distinguish "unusable" from "expired" on read surfaces.**
   - Scope: [medium]
   - Session recommendation: `separate-recommended`
   - Why gated only mildly: display-only and safe, but it touches a resource
     field, the SPA type, and three pages, and "Expired" is the wrong label to
     reuse — it needs a third state, which is a small design decision.

7. **R-010, R-011, R-012 — contained polish.**
   - Scope: [small] each
   - Session recommendation: **`same-session-ok`** (all three together)
   - R-010: add `->withTrashed()` to `routes.php:39` so an archived agreement is
     viewable by a holder of `.view`, consistent with the list already returning
     archived rows to the same permission.
   - R-011: gate the `onRowClick` at `spa/src/pages/crm/customers/detail.tsx:267`
     on `crm.price_agreements.manage`, mirroring what F-011 did on the list page.
   - R-012: quote the money literals in `PriceAgreementSeeder.php:26-51` as
     decimal strings. Verified value-preserving — every current literal
     round-trips to the identical `decimal(15,2)`.

8. **R-009 — tier price monotonicity.**
   - Scope: [small]
   - Session recommendation: `separate-recommended`
   - Why gated: adding a hard refusal could reject legitimate existing
     agreements (small-lot-cheaper contracts are a real thing). Decide warn vs.
     refuse vs. opt-in before implementing.

9. **R-013 — raw integer ids accepted as list filters.**
   - Scope: [small]
   - Session recommendation: `separate-recommended`
   - **Out of this module's scope.** `HashIdFilter` is a shared helper used
     repo-wide; changing it affects every list endpoint. Hand to whoever owns
     `app/Common/Support`.

## Re-audit acceptance gates

- The below-lowest-tier price is decided, documented, and the API and SPA say the
  same thing about it, with a test pinning the chosen amount.
- No two live agreements for one customer+product can overlap, enforced by the
  database and not only by the service, with the seeder routed through the
  service and pre-existing overlaps reconciled.
- Every price window is a valid interval regardless of the date format the caller
  sends (covered now by `test_inverted_window_is_refused_whatever_date_format_the_caller_uses`).
- `revenue_account_id` is either exposed with authorization or removed.
- The CRM role matrix is confirmed, and every pricing endpoint has a 403 test for
  a role that must not reach it.
- A price agreement whose product or customer is archived does not read as Active
  on any surface.
- An API client can move an agreement between flat and tiered without knowing to
  send a field it does not care about.
