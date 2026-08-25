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

## Session execution status — 2026-08-26 (resumed after crash)

Reclaimed a 7h-stale lock. The 2026-08-25 fix-log was already written, and its
claims verify against the committed source, so items 1–4 really were done. But
that session also made two coupled changes it logged **nowhere** — narrowing
`ALLOWED_TRANSITIONS` to a strict linear chain while simultaneously changing
`transitionOrFail()` from returning a `skipped` result to throwing — and those
broke order-to-cash in 8 tests across two other modules.

### Item 0 (unplanned, highest severity) — restore the forward-only transition contract

- Finding: F-012. **Fixed.** Order-to-cash could not complete: confirming a
  delivery for an order that never entered production threw and rolled the whole
  confirmation back, and finalizing an invoice for a partially delivered order
  rolled back a posted journal entry. Restored the forward-skip entries, kept the
  louder throw, and added F-015 regression coverage so the table cannot be
  silently narrowed again.

### Items 1–4 — already done before this session

Verified in the committed source, not taken on trust. Nothing re-fixed.

### Item 5 — queued-MRP cancellation race

- Finding: F-005. **Deferred on a hard scope constraint, not a risk label.**
  The fix belongs in `MrpEngineService::runForSalesOrder()` and
  `MRP\Listeners\QueueMrpOnSalesOrderConfirmed`, both outside this module.
  Re-verified the race is still open (the method locks the prior plan but never
  re-reads `$so->status`). There is no sales-order-side guard: the SO cannot know
  a job is in flight. Route to the MRP module.

### Item 6 — cancelled-chain semantics and historical timestamps

- Finding: F-008. **Split. Historical half done, canonical half deferred.**
  - Done: `2026_08_26_030000_backfill_sales_order_lifecycle_timestamps` recovers
    the six lifecycle timestamps from `audit_logs` for orders that transitioned
    before the columns existed. Truthful — no `updated_at` guessing; orders with
    no audit trail stay NULL.
  - Deferred: `ChainDefinitions` maps `cancelled → closed`, the last of nine
    steps, so a cancelled order is broadcast as 9/9 complete. Correcting it means
    a new terminal state in shared `app/Common/` chain infrastructure plus every
    consumer. Not fixable from inside this module without spreading the wrong
    answer.

### Item 7 — policy-dependent behaviour

- Findings: F-010, F-011. **Escalated as questions, not deferred work.** Both
  need a human business decision before the contract changes; guessing would
  either 422 existing integrations (mandatory cancellation reason) or invent an
  admin surface and permission set nobody asked for. Written up in `fix-log.md`
  under "Questions for the product/operations owner", along with a third question
  raised by the F-012 fix (whether `partially_delivered → invoiced` should leave
  the order looking fully invoiced).

### Also fixed, outside the plan

- F-013 — a stale assertion in `SalesOrderChainBridgeTest` demanding a work order
  that the intentional, separately-logged B01 no-BOM policy no longer creates.
- F-014 — `CustomerPortalService::salesOrderDetail()` returned 500 on every call
  (enum cast to string). Portal sales-order surface, i.e. this module's declared
  scope.

### Status

`🔁 Needs Re-audit` — item 5 and the canonical half of item 6 are genuinely out
of module scope, and item 7 is blocked on a human decision.
