# Stock Fulfillment Safety Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task with review checkpoints. Execute inline; do not spawn subagents unless the user requests them. Steps use checkbox syntax for tracking.

**Goal:** Correct eight reviewed gaps in stock production and stock-first Sales Order fulfillment without adding routine workflow steps.

**Architecture:** Repair the existing BOM, purchasing-supply, work-order cancellation, order allocation, and delivery services. Establish shared current-source validation before recovery and cancellation changes. Reuse existing transactions, planning locks, cancellation, reservations, and audit history.

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL 16, React 18, TypeScript, TanStack Query, PHPUnit, Vitest, Playwright. No new dependencies.

**Spec:** [Stock production and fulfillment safety](../specs/2026-10-09-stock-fulfillment-safety-design.md).

**Status:** Implemented and rolled out. Core safety gates pass. Validation limits and baseline fixture failures are recorded below.

## Global constraints

- New ordinary Sales Orders use approved stock first and produce shortages; PPC may choose fresh production before commitments progress.
- Production uses integer pieces. Inventory quantities use BCMath decimal strings at three decimal places. Preserve money helpers and costing behavior.
- Automatic changes require explicit ownership and untouched state. Do not identify ownership from notes text.
- Incoming purchasing and QC-held receipts are planning supply, never authorization to Start or issue materials.
- Keep driver proof, customer receipt, free replacement quantities, invoicing, and payment verification intact.
- Preserve failed QC, cancelled records, allocation provenance, and historical financial entries.
- Revalidate server-side; use house UI components and red errors. Do not clamp ordinary typing.
- No new dependencies, broad permissions, fake Sales Orders, fake production, or fake QC.
- Preserve unrelated dirty files. Database tests run sequentially in an isolated database; live route checks are read-only.
- No schema change is planned. Prove ownership and competing-run budgets using existing persisted relationships before accepting this design.
- Never automatically cancel or rewrite historical records during rollout. Produce a read-only affected-record report first.

## File map

| Responsibility | Files |
|---|---|
| Shared allocation readiness and relationship loading | `api/app/Modules/CRM/Services/SalesOrderSupplyService.php`, `api/app/Modules/SupplyChain/Services/DeliveryStockService.php`, `api/app/Modules/SupplyChain/Services/DeliveryStockReservationService.php`, `api/app/Modules/Inventory/Services/StockMovementService.php` |
| Rounded manufactured targets | `api/app/Modules/MRP/Services/BomService.php`, `api/app/Modules/Production/Services/StockProductionPlanningService.php` |
| Owner-aware incoming purchasing and material budgets | `api/app/Modules/Purchasing/Services/OpenSupplyService.php`, `api/app/Modules/Production/Services/StockProductionPlanningService.php`, `api/app/Modules/MRP/Services/MrpEngineService.php` |
| Dependent and order cancellation | `api/app/Modules/Production/Services/WorkOrderService.php`, `api/app/Modules/CRM/Services/SalesOrderService.php`, `api/app/Modules/SupplyChain/Services/DeliveryService.php` |
| Invalid automatic-shipment recovery | `api/app/Modules/SupplyChain/Services/DeliveryService.php`, `api/app/Modules/CRM/Services/SalesOrderSupplyService.php`, `api/app/Modules/MRP/Services/MrpEngineService.php` |
| Product setup readiness | `api/app/Modules/Production/Services/StockProductionPlanningService.php`, `api/app/Modules/Production/Controllers/WorkOrderController.php`, `spa/src/api/production/workOrders.ts`, `spa/src/pages/production/work-orders/create.tsx` |
| Durable regressions | Existing `api/tests/Feature/CRM/SalesOrderStockFulfillmentTest.php`, `api/tests/Feature/MRP/MultiLevelBomTest.php`, `api/tests/Feature/MRP/SubassemblyWorkOrderTest.php`, `api/tests/Feature/CRM/SalesOrderLifecycleConcurrencyTest.php`, `api/tests/Feature/SupplyChain/DeliveryDispatchProvenanceTest.php`; new `api/tests/Feature/Purchasing/StockProductionSupplyBudgetTest.php`; existing `spa/src/pages/production/work-orders/create.test.tsx` |
| Evidence | `docs/stock-production-rollout-2026-10-09.md`, this plan |

## Task 1: Make allocation readiness authoritative and load its dependencies

**Findings:** 5 and 7. **Files:** Shared-readiness row above; existing CRM stock-fulfillment and delivery-provenance tests.

**Interfaces:** Keep `lineCoverage(SalesOrderItem $line): array`, `validAllocation(SalesOrderStockAllocation $allocation): bool`, and their existing response keys. `assertAllocationForDelivery(...)` must consume the same validation. Departure uses existing `assertReadyForDeparture(Delivery $delivery): void`.

- [x] Add durable blocked-bin and multi-source tests to the existing stock-fulfillment suite. Use its `stockFixture()` and actual allocation services, not the review probe's reflection.

```php
[$so, $line, $item, $location, $actor] = $this->stockFixture();
$supply = app(SalesOrderSupplyService::class);
$supply->allocate($so, $actor, Str::uuid()->toString());
$this->assertSame('10.000', $supply->lineCoverage($line->fresh())['ready_quantity']);
$location->update(['is_blocked' => true]);
$this->assertSame('0.000', $supply->lineCoverage($line->fresh())['ready_quantity']);
```

- [x] Reproduce current failures with PHPUnit. Create two valid output lots and two allocations for one order; leave `Model::preventLazyLoading(true)` enabled throughout the multi-source test. Exercise preview, detail/list preparation, drafting, and dispatch.
- [x] Extend existing eager-load lists with the complete relationships read by allocation validation: order line/product/current spec, output/work order/receipt, inventory item, and location/zone/warehouse. Use `loadMissing()` at public validation boundaries for isolated models; batch-load before loops. Clear cached coverage after mutations.
- [x] Validate exact current lot/bin capacity with existing lot and availability helpers. Add back only the allocation's untransferred hold or its linked shipment reservation, according to handoff stage. Do not treat the same hold twice. Verify current bin is active, unblocked, in finished goods, and in an active warehouse.
- [x] Revalidate bin, lot, QC, specification, and expiry under departure locks before any issue. Preserve ordinary stock-transfer semantics; place delivery restrictions at the delivery boundary rather than banning all movements from blocked bins.
- [x] Add cases for inactive warehouse/location, changed zone, missing lot, expiry, competing holds, and dispatched history. Repeat coverage after mutations to prove no stale cache. Normal fully reserved stock remains ready; already dispatched delivery history remains counted.
- [x] Run CRM stock-fulfillment and delivery-provenance tests. Review the exact diff before proceeding.

## Task 2: Explode materials from whole-piece child targets

**Finding:** 3. **Files:** `BomService.php`, `StockProductionPlanningService.php`, existing multi-level and subassembly tests.

**Interfaces:** Preserve `BomService::productionPlan(int $productId, float $finishedQuantity, Closure $quantityToManufacture): array`, including `materials` and `subassemblies` keys. Child work-order targets remain integer pieces.

- [x] Add a fixture where one parent requires `0.5` piece of a manufactured child; child requires `3` kg per piece. Existing `stockFixture()` supplies the parent product and valid spec; create child product/item and both BOMs as in the existing genuine-child test.
- [x] Assert actual work-order materials and purchasing match:

```php
$child = $root->children()->firstOrFail();
$this->assertSame(1, (int) $child->quantity_target);
$this->assertSame('3.000', bcadd((string) $child->materials()->sum('bom_quantity'), '0', 3));
$request = PurchaseRequest::where('stock_production_work_order_id', $root->id)->firstOrFail();
$this->assertSame('3.000', bcadd((string) $request->items()->sum('quantity'), '0', 3));
```

- [x] Run the new test and confirm the current `1.500` purchasing result.
- [x] Round positive manufactured piece shortage before recursing into its BOM. Do not round raw-material quantities or non-piece units to integers. Preserve gross component consumption, usable component netting, waste, cycle detection, and depth limits. Ensure the returned node quantity and the created child's target agree.
- [ ] Add nested fractional manufactured components, waste, shared raw materials, and available child stock. Raw purchasing equals actual rounded child work requirements; existing exact integer examples stay unchanged.
- [x] Run `MultiLevelBomTest`, `SubassemblyWorkOrderTest`, and CRM stock-fulfillment tests. Review affected MRP callers because the shared explosion serves both stock and Sales Orders.

## Task 3: Net incoming purchasing once, with ownership

**Finding:** 2. **Files:** `OpenSupplyService.php`, `StockProductionPlanningService.php`, `MrpEngineService.php`; new `StockProductionSupplyBudgetTest.php`.

**Interfaces:** Keep existing `openRequestBaseQuantity(...)` and `inTransitBaseQuantity(int $itemId): string` behavior for unchanged callers. Add a narrowly scoped `stockPlanningSupply(int $itemId, int $stockRootId): array` returning base-unit decimal strings `uncommitted_incoming`, `owned_incoming`, `other_stock_demand`, and `available_to_root`. It reads persisted commitments; it never reserves physical stock.

- [x] Add an acknowledged unallocated PO for 3 kg, no physical stock, and a new stock job requiring 3 kg. Assert the existing incoming helper sees 3 kg and no extra shortage request is created:

```php
$this->assertSame('3.000000', app(OpenSupplyService::class)->inTransitBaseQuantity($raw->id));
$request = PurchaseRequest::where('stock_production_work_order_id', $root->id)->first();
$this->assertTrue($request === null || $request->status->value === 'cancelled');
```

- [x] Add two stock jobs requiring 3 kg each with only 3 kg incoming. Across both jobs, additional purchase requests total 3 kg. Run both replay orders and separate-process creation. Add an SO-owned PO which supplies no stock job, and a stock-owned PO which supplies no unrelated SO.
- [x] Implement owner classification from `purchase_requests.mrp_plan_id`, its Sales Order, and `stock_production_work_order_id`, including descendants' root ownership. Treat stock-linked PRs as committed; exclude them from MRP's currently general `whereNull('mrp_plan_id')` supply pool.
- [x] Reuse existing base-unit conversion, open-status filtering, received-quantity subtraction, PR-to-PO conversion subtraction, and QC-held receipt calculation. A received quantity pending QC is neither incoming PO remainder nor usable inventory; show it separately and never count it twice.
- [x] Build a deterministic budget from persisted active material requirements, their issued/reserved coverage, and owned purchasing. Use existing priority/deadline ordering and ID tie-breakers for uncommitted supply. Deduct other jobs' unmet commitments before exposing supply to the root. Exclude the current editable draft PR when calculating its own new shortage; retain its committed PO/approved purchasing evidence.
- [x] Compute net shortage only after rounded actual work requirements from Task 2. Reuse safety-stock floors and minimum-order-quantity multiples. Keep physical readiness unchanged when incoming supply covers purchasing demand.
- [x] Preserve a root's draft PR identity when editable. Do not rewrite approved/converted PRs or RFQ/PO-backed requests. If recomputed requirements cannot be represented without changing those commitments, return a procurement blocker identifying the records and preserve the plan transaction.
- [x] Verify all budgets within the existing planning lock. Test stock planning concurrent with SO MRP, purchasing conversion, and cancellation. Replayed PRs cannot satisfy their own demand; the same incoming quantity cannot satisfy both a stock job and an SO.
- [x] Stop this task if derived ownership cannot be proved across separate runs. Document the failing case before changing the schema; do not ship a cache-only workaround. This is the highest-complexity task and has its own review gate.

## Task 4: Cancel untouched dependents and automatic deliveries atomically

**Findings:** 1 and 6. **Files:** `WorkOrderService.php`, `SalesOrderService.php`, `DeliveryService.php`; CRM stock-fulfillment and lifecycle-concurrency tests.

**Interfaces:** Preserve `WorkOrderService::cancel(WorkOrder $wo, ?string $reason = null): WorkOrder` and `SalesOrderService::cancel(...)`. Add `DeliveryService::isUntouchedAutomaticSupply(Delivery $delivery): bool` for both cancellation and Task 5. Its predicate requires a nonempty explicit automation key, scheduled status, no driver/vehicle/departure/issue/receipt/invoice, and matching order ownership.

- [x] Move both cancellation probes into durable tests. Planned child and draft PR become cancelled with their parent. Stock-covered SO with an untouched automatic delivery becomes cancelled and its reserved balance becomes zero.

```php
app(WorkOrderService::class)->cancel($root, 'Replenishment no longer needed.');
$this->assertSame('cancelled', $child->fresh()->status->value);
$this->assertSame('cancelled', $request->fresh()->status->value);

$cancelled = app(SalesOrderService::class)->cancel($so->fresh(), 'Cancelled before assignment.');
$this->assertSame('cancelled', $cancelled->status->value);
$this->assertSame('0.000', (string) StockLevel::where('item_id', $item->id)->value('reserved_quantity'));
```

- [x] Preflight the stock root's complete descendant and purchasing tree under the planning lock. Automatic child retirement requires planned state, no schedule/assignment, no issued material, and no output. Purchasing retirement requires an automatic draft owned by the tree with no RFQ/PO. A progressed dependency blocks the entire cancellation with its document number.
- [x] Cancel eligible descendants deepest-first and draft PRs through existing services, then root. Preserve records and source IDs. Release reservations/equipment/schedules exactly once. Do not soft-delete the cancellation tree or route confirmed/issued work through this automatic path.
- [x] For SO cancellation, preflight all deliveries and retain the existing invoice/production guards. Retire only eligible automatic drafts through `DeliveryService::updateStatus(..., DeliveryStatus::Cancelled)`, then run downstream guard and existing SO cancellation. All changes share one transaction so a later blocker rolls back earlier releases.
- [x] Test approved PR, RFQ-linked draft, assigned child, issued child, manual shipment, driver/vehicle assignment, loading, dispatch, and invoice blockers. Assertions cover unchanged rows, audit history, reserved counters, and no partial cancellation.
- [ ] Add separate-process cancellation versus assignment/dispatch. Valid outcomes are complete cancellation or complete retained commitment; never cancellation with an active issued shipment. Review normal repair/return cancellation tests for preservation.

## Task 5: Rebuild invalid untouched automatic shipments before replanning

**Finding:** 4. **Files:** `DeliveryService.php`, `SalesOrderSupplyService.php`, `MrpEngineService.php`; CRM stock-fulfillment and delivery-lifecycle tests.

**Interfaces:** Add `DeliveryService::reconcileAutomaticSupply(SalesOrder $order, User $actor): array` returning `retired_delivery_ids` and `blockers`. It consumes Task 1 source validation and Task 4 untouched predicate. `draftOrderSupply(...)` remains the drafting endpoint; MRP invokes reconciliation before allocation and remaining-demand calculations.

- [x] Reproduce the existing invalid-delivery probe using two real approved source outputs in separate bins. Draft the first delivery, revoke its QC, then make the second output available. Leave lazy-loading enforcement on.
- [x] Assert one active replacement delivery, only valid source IDs, released old order/shipment hold, protected quarantine, and no physical issue:

```php
$active = Delivery::where('sales_order_id', $so->id)
    ->where('status', '!=', 'cancelled')->with('items')->get();
$this->assertCount(1, $active);
$this->assertTrue($active->first()->items->every(fn ($row) => $row->inspection_id === $replacement->id));
$this->assertSame('cancelled', $oldDelivery->fresh()->status->value);
```

- [x] In the planning transaction, find invalid order allocations and shipment sources before counting their reserved delivery quantities. Cancel affected untouched automatic deliveries through ordinary cancellation. Release obsolete untransferred order holds separately through existing allocation release, never through raw counter updates.
- [x] Reallocate valid sources and draft once from the reconciled order. A whole unassigned automatic delivery may be rebuilt when one line is invalid; release/reacquire unaffected lines only within the same transaction. Preserve original source/QC/delivery history.
- [x] Assigned/manual/progressed deliveries produce named blockers and remain immutable. Make readiness false for their invalid supply and departure reject it. Notify the responsible PPC/Quality/ImpEx users through existing in-app notification paths without adding a mandatory button sequence.
- [x] Invoke this reconciliation on normal MRP replay and fulfillment review. Avoid recursion between drafting and planning; the QC release listener continues to initiate existing MRP behavior.
- [ ] Test no replacement available, replacement arriving later, expiry/spec changes, an unaffected second product, another order holding the replacement lot, repeated queue delivery, and concurrent replays. Missing supply creates only the genuine remaining production shortage, not duplicate jobs or shipments.
- [x] Run stock-only and mixed fulfillment, source-provenance, driver proof, receipt, and free-replacement connected tests. Review before rollout.

## Task 6: Show product setup blockers before submission

**Finding:** 8. **Files:** `StockProductionPlanningService.php`, `WorkOrderController.php`, `spa/src/api/production/workOrders.ts`, stock create page and its existing tests.

**Interfaces:** Add `StockProductionPlanningService::productReadiness(Product $product): array` returning `eligible: bool` and `blockers: list<string>`. Use it both for form options and authoritative creation checks. Extend each existing product option with optional `stock_production` so other form-options callers remain compatible.

```typescript
products: Array<{
  id: string;
  part_number: string;
  name: string;
  stock_production?: { eligible: boolean; blockers: string[] };
}>;
```

- [x] Add an API test with eligible product and products missing mapping, BOM, or specification. Preserve all existing form-options fields and permissions; creation still rejects setup that changed after options loaded.
- [x] Reuse active-item mapping, BOM integrity, and inspection-spec validation from creation in the shared readiness method. Batch-load product/item/BOM/spec relationships for the option list. Return domain blocker messages; do not disguise unexpected exceptions as setup warnings or mutate/publish a BOM while loading options.
- [x] Disable ineligible product options and append a concise blocker to their labels. Show readable setup guidance below the selector, without relying only on disabled-option tooltips. Keep form submit disabled for an ineligible selection restored from a draft.
- [x] Keep quantity/date/worker validation, retry payload/idempotency key, unsaved draft, and combined assignment review unchanged. Server rejection for stale setup appears in red and allows refresh/reselection after a validation failure.
- [x] Add Vitest cases for disabled products, explanatory text, valid selection, setup changing after load, and failed request preserving entered fields. Use existing Select/Input/Button components.
- [x] Run stock create tests and browser checks at 1440 px and 390 px. Check keyboard selection, labels, red errors, modal focus, and no overflow. Do not automatically configure the seven missing mappings or five missing specifications from the earlier rollout.

## Task 7: Verification, affected-record report, and controlled rollout

**Files:** Existing rollout evidence, durable tests, and this plan. No historical cleanup migration.

- [x] Before edits, take file hashes/backups of the scoped current files and use an isolated copy/database. The previous review environment is available but must be checked for current file parity. Promote probe scenarios into durable tests; do not commit reflection-based tests or disabled lazy-loading settings.
- [x] Run focused backend gates sequentially. Example command for the existing isolated container:

```bash
docker exec ogami-finish-first-20261008 php -d memory_limit=512M vendor/bin/phpunit \
  -c phpunit.case-validation.xml \
  tests/Feature/CRM/SalesOrderStockFulfillmentTest.php \
  tests/Feature/Purchasing/StockProductionSupplyBudgetTest.php \
  tests/Feature/MRP/MultiLevelBomTest.php \
  tests/Feature/MRP/SubassemblyWorkOrderTest.php \
  tests/Feature/CRM/SalesOrderLifecycleConcurrencyTest.php \
  tests/Feature/SupplyChain/DeliveryDispatchProvenanceTest.php \
  tests/Feature/SupplyChain/DeliveryLifecycleConcurrencyTest.php
```

- [x] Run UI gates in the isolated SPA copy:

```bash
npm run test:run -- src/pages/production/work-orders/create.test.tsx \
  src/pages/production/work-orders/StockProductionPlanDialog.test.tsx \
  src/pages/crm/sales-orders/FulfillmentDialog.test.tsx
npm run build
```

- [ ] Execute stock-only and mixed isolated lifecycles through planning, assignment, material issue, output, independent QC, delivery assignment/dispatch, driver proof, customer receipt, and payment verification. Also execute stock cancellation, blocked bin, QC recovery, and competing incoming-supply cases. Verify on-hand/reserved/lot counters and financial postings at each handoff.
- [x] Use separate processes for incoming budget competition, allocation/dispatch, cancellation/assignment, and recovery replay. Existing race runner remains a starting point; use a dedicated test database and record actual outcomes rather than interpreting sequential tests as concurrency evidence.
- [x] Produce a read-only report of cancelled stock roots with active descendants/PRs, invalid active allocations, and automatic scheduled deliveries with invalid sources. Include document IDs, reason, ownership, and untouched/progressed classification. Historical cleanup remains an explicit operating task, not a deploy side effect.
- [x] Compare representative original/replacement SOs, deliveries, case, invoice, collection, and posted journals before/after rollout. Copy only the reviewed feature files, retain additive schema, rebuild SPA, and use read-only authenticated route checks.
- [x] Update rollout evidence with exact checks, remaining setup blockers, affected-record report, and rollback locations. Any existing baseline fixture failure must be reproduced against unchanged code and reported separately; do not call the full suite passing.

## Completion checklist

| Finding | Deliverable | Gate |
|---|---|---|
| 1: Orphaned dependencies | Task 4 atomic stock cancellation | Untouched tree cancels; progressed tree rolls back |
| 2: Duplicate purchasing | Task 3 owner-aware persisted budget | Two jobs share incoming only once; reruns stable |
| 3: Rounded-child material shortage | Task 2 whole-piece explosion | 0.5 child produces 1 child and requires 3 kg |
| 4: Revoked delivery stuck | Task 5 source reconciliation | One valid replacement shipment, original retained |
| 5: Blocked bin counted ready | Task 1 current physical validation | Zero readiness and no departure from blocked source |
| 6: SO cancellation blocked by automation | Task 4 safe automatic draft retirement | SO cancels before assignment; all holds release once |
| 7: Missing relationship loads | Task 1 complete dependency loading | Multiple allocations pass with lazy loading disabled |
| 8: Unusable products selectable | Task 6 setup readiness | Disabled options with reasons; server rechecks |

Stop when these gates pass and rollout evidence is recorded. Forecasting, new inventory types, generalized workflow redesign, automatic approval, and unrelated cleanup remain outside this plan.

## Execution evidence and deviations

- The six reproduced probes became durable tests in the existing CRM stock-fulfillment suite. Purchasing regressions stayed in that suite to reuse its real setup rather than introduce a duplicate fixture or reflection-based shipped test.
- The dedicated verification database is `ogami_test_stock_safety_20261009`. Final connected run passed 80 tests and 425 assertions. Five UI tests, a production build, and Chromium form checks at 390 px and 1440 px passed.
- Separate-process probes passed for purchasing creation, allocation/departure, cancellation versus assignment, and recovery replay. The recovery race checks cancellation/release without replacement available; the durable recovery test checks the valid replacement shipment.
- Five `DeliveryLifecycleConcurrencyTest` fixtures fail before delivery at trial-piece setup. The same five failures were reproduced with all scoped original service files loaded. Production safeguards were preserved. One intermediate test invocation overlapped another migration and was discarded; final verification used a fresh dedicated database sequentially.
- Representative original/replacement orders, deliveries, case, invoice, collection, payment submission, journal entries and journal lines were unchanged byte-for-byte after rollout. The live read-only report found no cancelled-stock orphan trees, invalid allocations, or invalid automatic scheduled shipments.
- No schema change, historical cleanup, automatic product configuration, or live business fixture was introduced. All eight fixes are implemented; the checklist records implementation and available gate coverage, not exhaustive testing of every example. Nested fractional waste/stock combinations, stock planning versus MRP in separate processes, cancellation versus dispatch, and a complete newly manufactured stock-to-cash browser journey were not individually exercised in this safety pass.
- Rollback file backups, hashes, raw checks and screenshots: `/tmp/stock-safety-20261009`. Restore only files listed in `changed-files.json`; preserve the previously deployed additive schema and unrelated changes.
