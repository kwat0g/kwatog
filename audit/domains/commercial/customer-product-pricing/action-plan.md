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
