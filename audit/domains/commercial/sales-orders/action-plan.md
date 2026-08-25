# M033 — Sales Orders Action Plan

Date: 2026-08-25  
Source: current-source re-audit in `audit-report.md`

The module was re-audited because the previous report predates substantial uncommitted source changes. Items below are ordered by user impact and dependency. This session implements the module-contained items it can verify safely; items requiring MRP/shared-chain changes or a business decision remain explicitly pending.

## 1. Restore the frontend compile and complete the internal edit form

- Findings: F-001, F-003, F-007
- Scope: medium
- Session recommendation: `same-session-ok`
- Remove the literal escaping that makes the create page invalid TypeScript.
- Add the incoterm selector to edit, using the same allowed values as create and preserving the update payload.
- Add loading/empty/error/retry behavior to edit’s customer/product lookups, matching create.
- Verify with SPA typecheck and diff whitespace checks.

## 2. Put restore and confirmation invariants behind the sales-order service

- Findings: F-002, F-004
- Scope: medium
- Session recommendation: `separate-recommended`
- Move restore into a transactionally locked `SalesOrderService` method, enforce the module’s restore rule, and make the controller return structured business-rule errors.
- At confirmation time, revalidate the customer and item product references as active before changing the order state.
- Add focused verification for the new paths if the existing fixtures support it.

## 3. Preserve and expose customer-portal pagination

- Finding: F-006
- Scope: medium
- Session recommendation: `same-session-ok`
- Return the full `PaginatedResponse<PortalSoSummary>` from the SPA adapter, pass page/status parameters, and render status filtering plus shared pagination controls.
- Keep customer scoping and the existing backend paginator unchanged.
- Verify with SPA typecheck and the existing customer-portal feature suite.

## 4. Add direct sales-order route and invariant regression coverage

- Finding: F-009
- Scope: large
- Session recommendation: `separate-recommended`
- Add a direct feature matrix for create/update/delete/restore, incoterm round-trip, delivery-date ordering, active-reference confirmation, archived binding, cancellation downstream guards, stale write races, and portal pagination.
- Include authorization assertions for each named lifecycle route and an assertion that the removed generic transition route is unavailable.
- Run the focused and full relevant backend suites after the fixture/test additions.

## 5. Close the queued-MRP cancellation race

- Finding: F-005
- Scope: large
- Session recommendation: `separate-recommended`
- Coordinate with MRP to re-lock and re-read the sales order inside `runForSalesOrder` immediately before plan/work-order creation, and make cancellation/outbox behavior idempotent against the same race.
- Add a deterministic concurrency test covering cancellation between MRP selection and execution.
- This session does not modify MRP files because they are outside M033 scope.

## 6. Reconcile cancelled-order chain semantics and historical timestamps

- Finding: F-008
- Scope: large
- Session recommendation: `separate-recommended`
- Decide whether canonical cancellation should be `skipped`, `cancelled`, or a terminal closed state, then update shared `ChainDefinitions`/broadcast consumers and the sales-order projection together.
- Define a backfill/reconciliation strategy for the nullable lifecycle timestamps added by `2026_08_25_100000_add_sales_order_lifecycle_timestamps.php`.
- This session does not modify shared chain infrastructure or historical data outside M033.

## 7. Resolve policy-dependent recovery and cancellation behavior

- Findings: F-010, F-011
- Scope: small
- Session recommendation: `separate-recommended`
- Confirm whether cancellation reason is mandatory for particular roles/statuses.
- Confirm whether system administrators need an archived-order list and restore controls in the SPA, or whether the API is intentionally the recovery surface.
- Only after the decision, update request validation, permissions, UI, and tests as one behavior change.

## Session execution status — 2026-08-25

- Completed items 1–3 within M033.
- Item 4 is covered by SalesOrderRouteCoverageTest.php plus the existing lifecycle/chain/concurrency suites; the direct suite passed 9 tests/32 assertions.
- Items 5–6 remain deferred because their correct fixes are in MRP/shared chain infrastructure outside this module.
- Item 7 remains deferred pending the cancellation-reason and archive/restore UI decisions.
