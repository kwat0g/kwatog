# M033 — Sales Orders Fix Log

## 2026-08-26 session (resumed after crash)

Reclaimed a 7h-stale lock. The 2026-08-25 fix-log below was already written and
its claims check out against the committed source, so items 1–4 of the plan were
genuinely done. However that session ALSO made two coupled, **unlogged** changes
to `ALLOWED_TRANSITIONS` and `transitionOrFail()` that appear in neither its
fix-log nor its audit report, and those changes broke order-to-cash.

### Fixed

- F-012 (new, unlogged regression from the 2026-08-25 session) —
  [SalesOrderService.php:44-84](/home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/Services/SalesOrderService.php:44)
  — `ALLOWED_TRANSITIONS` had been narrowed from forward-only to strictly
  linear, while `transitionOrFail()` was simultaneously changed from returning a
  typed `skipped` result to **throwing** `BusinessRuleException`. Together those
  turned every legitimate stage-skip into a hard rollback of the caller's
  transaction. Restored the forward-skip entries; kept the throw.

  Before (committed in 167de85e, no fix-log entry):
  ```php
  'confirmed'           => ['in_production'],
  'in_production'       => ['partially_delivered', 'delivered'],
  'partially_delivered' => ['delivered'],
  ```
  After:
  ```php
  'confirmed'           => ['in_production', 'partially_delivered', 'delivered', 'invoiced'],
  'in_production'       => ['partially_delivered', 'delivered', 'invoiced'],
  'partially_delivered' => ['delivered', 'invoiced'],
  ```

  Evidence the narrowing was wrong, not the callers:
  1. `in_production` is set ONLY by
     [WorkOrderService.php:369](/home/kwat0g/Desktop/kwatog/api/app/Modules/Production/Services/WorkOrderService.php:369)
     when a work order *starts*. An order filled from finished-goods stock has
     no WO to start, so its SO never leaves `confirmed`.
  2. [DeliveryService::create()](/home/kwat0g/Desktop/kwatog/api/app/Modules/SupplyChain/Services/DeliveryService.php:226)
     deliberately does **not** require the SO to be in production — it checks
     only remaining quantity and the outgoing inspection. So a delivery against
     a `confirmed` SO is a supported state, and
     [DeliveryService.php:901](/home/kwat0g/Desktop/kwatog/api/app/Modules/SupplyChain/Services/DeliveryService.php:901)
     then calls `markDelivered()` inside the confirm transaction → threw →
     rolled the whole delivery confirmation back.
  3. [InvoiceService::finalize()](/home/kwat0g/Desktop/kwatog/api/app/Modules/Accounting/Services/InvoiceService.php:299)
     calls `markInvoiced()` **after** posting the journal entry, in the same
     transaction. Refusing `partially_delivered → invoiced` therefore rolled
     back a posted JE — billing a partial delivery was impossible. No test
     covered this; it was a latent second blocker found by reading the call site.
  4. The original table, authored in the deliberate feature commit b8d57d0a
     ("C-2 wire SalesOrder status transitions"), allowed exactly these skips and
     its comment stated the intent: "Backwards or terminal transitions are
     absent". Forward skips were always in scope.

  `cancelled` intentionally remains absent as a target: no `mark*` helper
  requests it and `cancel()` is the only entry point, with its own
  `assertCancellableDownstreamState()` guards. That part of the narrowing was
  behaviour-neutral and is kept.

  Verified: `AutoInvoiceOnDeliveryConfirmTest` (4), `CocAutoAttachOnConfirmTest`
  (4) and `SalesOrderStatusTransitionsTest` (10) — 18 passed, 57 assertions,
  0 failed. Previously 8 of those failed with
  `BusinessRuleException: Transition from confirmed to delivered is not allowed.`

- F-013 (stale assertion, not a code bug) —
  [SalesOrderChainBridgeTest.php:125](/home/kwat0g/Desktop/kwatog/api/tests/Feature/CRM/SalesOrderChainBridgeTest.php:125)
  — `test_confirm_so_handles_missing_bom_gracefully` asserted
  `work_orders_created >= 1` for a product with no BOM. It now asserts `0`.

  This one is a test fix, not a service fix, and the distinction matters. The
  behaviour change is **intentional, owned by another module, and logged there**:
  B01 in
  [bom-mrp-planning/fix-log.md](/home/kwat0g/Desktop/kwatog/audit/domains/manufacturing/bom-mrp-planning/fix-log.md)
  states MRP previously "skipped only demand explosion and then created a
  standard root work order" and now blocks the standard-WO path
  ([MrpEngineService.php:400](/home/kwat0g/Desktop/kwatog/api/app/Modules/MRP/Services/MrpEngineService.php:400)).
  It is corroborated independently by
  [WorkOrderService::assertMaterialPlan()](/home/kwat0g/Desktop/kwatog/api/app/Modules/Production/Services/WorkOrderService.php:655),
  which refuses to START a standard WO with no material plan unless it is
  explicitly classed service/non_stock/prototype with an authorized reason — so
  the WO this test demanded could never have been started. I did not touch
  `MrpEngineService`; only the CRM-owned assertion.

  The test's intent is preserved and strengthened: confirmation still must not
  hard-fail, the plan must still exist, the `missing_bom` warning must still be
  recorded, and it now also asserts the SO reached `confirmed` and that the
  warning names the right product.

  Verified: `SalesOrderChainBridgeTest` — 8 passed, 56 assertions, 0 failed.

- F-008 (historical half only) —
  [2026_08_26_030000_backfill_sales_order_lifecycle_timestamps.php](/home/kwat0g/Desktop/kwatog/api/database/migrations/2026_08_26_030000_backfill_sales_order_lifecycle_timestamps.php)
  — new migration. `2026_08_25_100000_add_sales_order_lifecycle_timestamps`
  added six nullable columns with no backfill, so every order that transitioned
  before it shipped renders a blank date in
  [SalesOrderService::chain()](/home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/Services/SalesOrderService.php:832),
  which reads those columns directly.

  The dates are **recovered, not invented**. `SalesOrder` uses `HasAuditLog`,
  which writes `new_values = getChanges()` on every update, so the moment a
  status was first written is `MIN(created_at)` over the `audit_logs` rows whose
  `new_values->>'status'` names it. Confirmed against the dev DB, which already
  holds such rows (2 `confirmed`, 1 `invoiced`).

  Deliberately NOT done: no fallback to `updated_at`/`created_at` when the audit
  trail has no matching row. An operator reads these as "this is when it
  happened"; a plausible guess in an audit-relevant field is worse than an
  honest blank. Orders predating the audit trail stay NULL.

  Filename is timestamp-style, against CLAUDE.md's stated preference for
  `0NNN_`, and this is forced rather than sloppy: the migrator sorts by full
  filename and every `0NNN_` name sorts before every `2026_` one, so `0479_`
  would run *before* the migration that adds these columns and fail on a fresh
  database. Verified by `migrate:status` — all `0NNN_` files run first.

  Verified functionally on a scratch DB (`ogami_mig_s1`, dropped afterwards)
  with hand-built fixtures. All five behaviours held:
  | case | result |
  |---|---|
  | two `confirmed` audit rows (Jan 2 and Jan 9) | earliest won — `2026-01-02 11:00` |
  | statuses never reached (`partially_delivered`) | stayed NULL |
  | audit row changing only `notes` | ignored |
  | same `model_id` under `...Production\Models\WorkOrder` | ignored (`cancelled_at` NULL) |
  | order with an authoritative `confirmed_at` of 2020-01-01 plus a 2026 audit row | kept 2020-01-01, not overwritten |
  | order with no audit rows at all | all six columns NULL |
  Re-running after deleting its `migrations` row produced byte-identical output
  (idempotent). It also runs clean on an empty database and sorts last.

- F-014 (new) —
  [CustomerPortalService.php:136-149](/home/kwat0g/Desktop/kwatog/api/app/Modules/B2B/Services/CustomerPortalService.php:136)
  — `salesOrderDetail()` returned **500 on every call**. `WorkOrder` casts
  `status` to the `WorkOrderStatus` enum
  ([WorkOrder.php:46](/home/kwat0g/Desktop/kwatog/api/app/Modules/Production/Models/WorkOrder.php:46)),
  so the attribute is already an enum instance and
  `WorkOrderStatus::tryFrom((string) $workOrder->status)` raised
  `Error: Object of class …WorkOrderStatus could not be converted to string`.
  Any portal customer opening any of their own orders that had a linked work
  order got a 500.

  Before:
  ```php
  WorkOrderStatus::tryFrom((string) $workOrder->status)?->label() ?? (string) $workOrder->status,
  ```
  After — accepts either shape rather than assuming the raw string:
  ```php
  $status = $workOrder->status;
  ... $status instanceof WorkOrderStatus
        ? $status->label()
        : (WorkOrderStatus::tryFrom((string) $status)?->label() ?? (string) $status),
  ```

  Scope note: this file is under `api/app/Modules/B2B/`, but the portal
  sales-order surface is declared M033 scope — `status.md` lists
  `customer-portal` among the module's roles, F-006 already changed this
  surface, and the failing test is `test_sales_order_detail_matches_loaded_child_relations`.
  I fixed only the sales-order path and deliberately left the two neighbouring
  portal failures alone (see Deferred / not mine, below).

  Verified: `CustomerPortalServiceTest` — that test now passes; suite went from
  3 failed / 19 passed to 2 failed / 20 passed, and the 2 remaining failures are
  the unrelated delivery-proof and 8D-report cases, which also confirms they
  pre-date this session.

- F-015 (coverage gap that let F-012 ship) —
  [SalesOrderStatusTransitionsTest.php:116-212](/home/kwat0g/Desktop/kwatog/api/tests/Feature/CRM/SalesOrderStatusTransitionsTest.php:116)
  — nothing asserted that the transition table is forward-only rather than
  strictly linear, which is exactly why narrowing it passed the module's own
  suite while breaking three other suites. Added two data-driven tests:

  `test_forward_skips_are_permitted` pins the five reachable skips —
  `confirmed → delivered`, `confirmed → partially_delivered`,
  `confirmed → invoiced`, `in_production → invoiced`, and
  `partially_delivered → invoiced`. The last one had **no coverage at all**
  before, which is how the posted-JE rollback stayed invisible.

  `test_backwards_and_terminal_transitions_are_refused` pins the other half of
  the contract so restoring the skips cannot be misread as "anything goes":
  `invoiced` and `cancelled` are terminal, `draft` must be confirmed first, and
  `delivered`/`invoiced` cannot go backwards.

  Verified: `SalesOrderStatusTransitionsTest` — 20 passed, 30 assertions.

### Verification (2026-08-26)

Own database throughout: `ogami_test_s1` (never shared `ogami_test`).

| suite | result |
|---|---|
| `SalesOrderStatusTransitionsTest` | 20 passed, 30 assertions |
| `SalesOrderChainBridgeTest` | 8 passed, 56 assertions |
| `SalesOrderChainStageTest` | 4 passed |
| `SalesOrderLifecycleConcurrencyTest` | 2 passed |
| `SalesOrderRouteCoverageTest` | 9 passed |
| `CustomerProductPricingTest` | 12 passed |
| `CustomerPortalServiceTest` | 20 passed, **2 failed — both other modules** (see below) |
| Delivery + Invoice consumers of the transition table: `AutoInvoiceOnDeliveryConfirmTest`, `CocAutoAttachOnConfirmTest`, `DeliveryConfirmTest`, `DeliveryDeleteGuardsTest`, `DeliveryLifecycleConcurrencyTest`, `DeliveryQuantityReconciliationTest`, `InvoiceBirFieldsTest`, `InvoiceCollectionTest`, `InvoiceDraftNumberingTest` | 45 passed, 177 assertions |
| `php -l` on every modified PHP file | clean |
| SPA `npm run typecheck` | 3 errors, **all outside this module** — `assets/detail.tsx` (missing `qrcode` types, ×2) and `return-management/detail.tsx:902` (duplicate JSX attribute). Nothing in `crm/sales-orders/` or `portal/customer/`. |

All 9 of the failures this session was assigned now pass.

### Deferred — and why

- **F-005 — queued-MRP cancellation race. NOT a risk-label deferral: a hard
  scope constraint.** The fix is a re-lock/re-read of the sales order inside
  [MrpEngineService::runForSalesOrder()](/home/kwat0g/Desktop/kwatog/api/app/Modules/MRP/Services/MrpEngineService.php:73),
  which I re-verified still never re-reads `$so->status` — it locks the prior
  plan only. The trigger,
  `App\Modules\MRP\Listeners\QueueMrpOnSalesOrderConfirmed`, is also MRP. Both
  files are outside `commercial/sales-orders`, and there is no sales-order-side
  guard available: the SO cannot know a job is in flight. Route to the MRP
  module (M049 `bom-mrp-planning` or a capacity/engine module).

- **F-008 — canonical cancelled-chain semantics (the OTHER half; the historical
  backfill IS done above).** Confirmed still wrong and now quantified:
  [ChainDefinitions.php:37-51](/home/kwat0g/Desktop/kwatog/api/app/Common/Support/ChainDefinitions.php:37)
  maps `cancelled → 'closed'`, and `closed` is the **last of the nine**
  `STEPS_SALES_ORDER`. `ChainBroadcaster` calls
  `ChainDefinitions::resolveStrict()` and broadcasts the result
  ([ChainBroadcaster.php:80](/home/kwat0g/Desktop/kwatog/api/app/Common/Services/ChainBroadcaster.php:80)),
  so **a cancelled sales order is broadcast as step 9 of 9 with all eight
  earlier steps marked complete** — it renders as a fully successful order.
  Meanwhile `SalesOrderService::chain()` reports the same order as `skipped`.
  Fixing this means adding a cancelled/terminal-distinct state to the shared
  `ChainDefinitions` and updating every consumer of the canonical chain; both
  are shared `app/Common/` infrastructure outside this module. Deliberately not
  "fixed" from the sales-order side, because the only in-module change
  available — making `chain()` agree by reporting cancellation as `closed` —
  would spread the wrong answer rather than correct it.

- **F-010 / F-011 — genuine open questions for a human, not deferrals.** See the
  Questions section below.

### Questions for the product/operations owner

1. **Is a cancellation reason mandatory?** Today it is optional and capped at
   500 chars
   ([CancelSalesOrderRequest.php:16](/home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/Requests/CancelSalesOrderRequest.php:16)),
   and the detail page offers an optional textarea. Cancellation writes the
   reason into `notes` and is the only lifecycle event with no structured
   justification. If IATF 16949 traceability or finance requires a reason for a
   cancelled customer order, the FormRequest, the UI, and the tests must change
   together. I did not guess — making it required is a contract change that
   would 422 existing integrations.

2. **Should system administrators get an archived-order list and a restore
   control in the SPA?** The backend restore route exists and is now hardened
   (F-002), and the CRM API client already exposes `delete`/`restore`, but no
   internal UI surfaces either. Either the API is intentionally the
   recovery surface (in which case the client methods are dead code worth
   removing) or the admin surface is incomplete. This needs a decision on
   permissions and UX before any UI is invented.

3. **Should `partially_delivered → invoiced` leave the SO looking fully
   invoiced?** Restoring this transition was necessary — refusing it rolled back
   a posted journal entry (F-012) — but the resulting status does lose the
   "quantities still owed" signal at a glance. The underlying data is intact
   (`sales_order_items.quantity_delivered`), so this is a reporting-fidelity
   question, not a correctness one. A distinct `partially_invoiced` state would
   be the fuller answer and is a schema + enum + chain change well beyond this
   module.

### Found but NOT mine — route to the owning module

Both surfaced in `CustomerPortalServiceTest` and both pre-date this session
(proved by them still failing after F-014 fixed the sales-order case in the same
file). Neither is a sales-order surface, so I left them alone.

- `test_delivery_list_detail_and_proof_use_portal_safe_hash_ids` — **500** on
  `GET /api/v1/b2b/customer/deliveries/{delivery}/proofs/{proof}/view`
  (`api/tests/Feature/B2B/CustomerPortalServiceTest.php:326`). Portal
  delivery-proof streaming. → supply-chain / portal-deliveries.
- `test_portal_8d_report_requires_finalized_report_and_terminal_status` — **404**
  on `GET /api/v1/b2b/customer/complaints/{complaint}/8d-report`
  (`api/tests/Feature/B2B/CustomerPortalServiceTest.php:482`), i.e. the route or
  its model binding is missing, not an assertion mismatch. → CRM
  complaints/8D module.

## 2026-08-25 session

The module was claimed after a fresh registry refresh. The previous report/plan predated substantial uncommitted changes, so the current source was re-audited before fixing. All changes below are limited to the sales-order implementation, its role-facing portal surfaces, and M033 regression tests.

### Fixed

- F-001 — [create.tsx](/home/kwat0g/Desktop/kwatog/spa/src/pages/crm/sales-orders/create.tsx:169) — Removed literal backslashes from the draft-confirmation template literals and removed trailing whitespace; the interrupted create-page syntax is now valid.
- F-002 — [SalesOrderService.php](/home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/Services/SalesOrderService.php:706) and [SalesOrderController.php](/home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/Controllers/SalesOrderController.php:73) — Added a transactionally locked, soft-delete-aware restore service path and routed the controller through it.
- F-003 — [edit.tsx](/home/kwat0g/Desktop/kwatog/spa/src/pages/crm/sales-orders/edit.tsx:230) — Added the incoterm selector so the loaded/persisted field can be edited.
- F-004 — [SalesOrderService.php](/home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/Services/SalesOrderService.php:462) — Confirmation now locks referenced products/customer and rechecks active customer/product invariants before the state change.
- F-006 — [customer.ts](/home/kwat0g/Desktop/kwatog/spa/src/api/b2b/customer.ts:83) and [portal orders page](/home/kwat0g/Desktop/kwatog/spa/src/pages/portal/customer/orders/index.tsx:28) — Preserved the full paginator response, sent page/status parameters, and added status filtering and shared pagination controls.
- F-007 — [edit.tsx](/home/kwat0g/Desktop/kwatog/spa/src/pages/crm/sales-orders/edit.tsx:157) — Added lookup loading/empty helper text, disabled states, and retryable query errors matching create.
- F-009 — [SalesOrderRouteCoverageTest.php](/home/kwat0g/Desktop/kwatog/api/tests/Feature/CRM/SalesOrderRouteCoverageTest.php:27) — Added direct coverage for CRUD/restore/incoterm round-trip, date ordering, confirmation reference rechecks, cancellation downstream guard, removed generic transition route, named-route permissions, and customer-portal page two metadata.

### Verification

- php -l passes for the modified CRM controller/service and the new feature test.
- Scoped ESLint passes for the modified sales-order and portal TypeScript files.
- Focused existing backend suites: 52 tests, 183 assertions passed.
- New M033 route-coverage suite: 9 tests, 32 assertions passed.
- Final full SPA typecheck reports only unrelated project diagnostics: CreateAccountModal.tsx unused errors, accounting/periods.tsx possibly undefined data, and the existing qrcode module/type errors in assets/detail.tsx. No M033 or portal diagnostics remain.

### Deferred

- F-005 — The queued-MRP cancellation race needs a lock/re-read change in api/app/Modules/MRP/Services/MrpEngineService.php/job flow, outside M033 scope.
- F-008 — Cancelled-chain semantics and historical timestamp backfill need shared ChainDefinitions/broadcaster and migration coordination, outside M033 scope.
- F-010 — Cancellation-reason requiredness is a product/compliance decision; current optional behavior was not changed.
- F-011 — Whether system administrators need archive/restore UI is a product/operations decision; the API recovery path was hardened but no new UI action was invented.
