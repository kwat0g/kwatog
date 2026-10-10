# Stock Production and Order Fulfillment Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task by task with review checkpoints. Do not spawn subagents unless the user explicitly requests them. Steps use checkbox syntax for tracking.

**Goal:** Produce approved finished goods for inventory with automatic resource suggestions, then fulfill new Sales Orders from that stock and manufacture only shortages.

**Architecture:** Extend ordinary work orders, BOM planning, finite-capacity assignment, and existing stock movement services. A new order allocation records the exact output/inspection/lot/bin authorized for another order; a shared coverage service drives MRP, delivery validation, and chain status. Existing order-bound production and dispatched history remain intact.

**Tech Stack:** Laravel 11, PHP 8.3, PostgreSQL 16, React 18, TypeScript, TanStack Query, PHPUnit, Vitest, Playwright. No new dependencies.

**Spec:** [Stock production and fulfillment design](../specs/2026-10-09-stock-production-and-order-fulfillment-design.md).

**Status:** Approved and implemented on 2026-10-09. Additive migration and feature files rolled out. See [verification and operating notes](../../stock-production-rollout-2026-10-09.md) for evidence, baseline fixture failures and readiness blockers.

## Global constraints

- New order lines default to approved stock first. Existing order lines retain their existing production behavior until explicitly reviewed.
- Production uses whole pieces. Inventory quantities are decimal strings at three decimal places; money remains exact through existing Money/BCMath helpers.
- Stock-production deadlines use Asia/Manila and the existing 12-hour display. Customer jobs preserve the existing production-to-delivery buffer.
- Number of workers is 1–4; preserve active Production employee/account, shift, leave, overlap, and runtime attendance checks.
- Automatic planning proposes equipment and workers. PPC confirmation, physical material issue, actual Start/output, and independent QC remain required.
- No new broad permissions, fake Sales Orders, fake completed WOs, fabricated QC evidence, or automatic purchasing approvals.
- Stock preview never reserves. Confirmed allocation never issues stock, posts COGS, or increases delivered quantities.
- Preserve ordinary order production, repair, free replacement, returns, invoices, receipts, and payment verification.
- Retain source ownership, spec/revision compatibility, approval, expiry, and lot/bin identity through every handoff.
- Reuse house UI components/tokens; validate on the server and show field errors in red without clamping typing.
- Preserve raw lifecycle states and existing chain keys. Display supply coverage honestly across internal and portal callers.
- Read current files before executing; this shared workspace contains unrelated changes. No broad staging, rollback, or live fixture writes.
- Run database tests sequentially against an isolated database. No simultaneous RefreshDatabase suites on a shared database.

## File and interface map

| Responsibility | Files |
|---|---|
| Stock purpose and idempotent creation | Existing `Production/Models/WorkOrder.php`, `Requests/StoreWorkOrderRequest.php`, `Controllers/WorkOrderController.php`, `Services/WorkOrderService.php`, `Resources/WorkOrderResource.php`; new `Services/StockProductionPlanningService.php` |
| Stock BOM children/material shortages | Existing `MRP/Services/BomService.php`, `MrpEngineService.php`, `Production/Services/WorkOrderMaterialUsageService.php`, `Purchasing/Models/PurchaseRequest.php`; stock planner above |
| Proposal and combined confirmation | Existing `MRP/Services/CapacityPlanningService.php`, `Production/Services/ProductionResourceService.php`, `WorkOrderAssignmentService.php`, `Controllers/WorkOrderAssignmentController.php` |
| Fulfillment policy, coverage, allocation | Existing `CRM/Models/SalesOrderItem.php`; new `CRM/Models/SalesOrderStockAllocation.php`, `CRM/Services/SalesOrderSupplyService.php`, `Controllers/SalesOrderFulfillmentController.php`, `Requests/UpdateSalesOrderFulfillmentRequest.php` |
| Physical holds and lot eligibility | Existing `Inventory/Services/StockMovementService.php`, `StockLocationSummaryService.php`, `Quality/Services/QualityStockHoldService.php`, `SupplyChain/Services/DeliveryStockReservationService.php` |
| Delivery provenance and automatic drafting | Existing `SupplyChain/Services/DeliveryService.php`, `DeliveryStockService.php`, `Models/DeliveryItem.php`, `Resources/DeliveryResource.php`, `Quality/Listeners/CreateDeliveryDraftOnQcPass.php` |
| Shared order/portal status | Existing `CRM/Services/SalesOrderService.php`, `Resources/SalesOrderResource.php`, `B2B/Services/CustomerPortalService.php`, CRM/B2B lists and chain responses |
| Production and order UI | Existing `spa/src/pages/production/work-orders/{index,create,detail}.tsx`, `CrewPanel.tsx`, `StartProductionDialog.tsx`, `spa/src/pages/crm/sales-orders/detail.tsx`, `spa/src/pages/portal/customer/orders/detail.tsx`, inventory stock pages, `spa/src/pages/supply-chain/deliveries/detail.tsx` |
| API, types, and notification callers | Existing `spa/src/api/production/workOrders.ts`, `spa/src/api/crm/salesOrders.ts`, `spa/src/types/{production,production-crew,crm,b2b,chain}.ts`, `spa/src/lib/chains/o2c.ts`; CRM/Production routes and Common notification catalog |

New service is justified by one shared invariant: the same allocation/coverage facts must authorize MRP, delivery, and the displayed chain. Do not introduce a generic inventory framework or rewrite the entire MRP engine.

### Contracts to implement

All service IDs are integers; HTTP IDs use existing HashID resolution. New quantities over HTTP are strings.

```php
final class SalesOrderSupplyService
{
    public function preview(SalesOrder $order): array;
    public function allocate(SalesOrder $order, User $actor, string $requestKey): array;
    public function lineCoverage(SalesOrderItem $line): array;
    public function releaseUnshipped(SalesOrder $order, User $actor, string $reason): void;
    public function assertAllocationForDelivery(SalesOrderStockAllocation $allocation,
        SalesOrderItem $line, Inspection $inspection, string $quantity): void;
    public function transferToDelivery(SalesOrderStockAllocation $allocation,
        DeliveryItem $deliveryItem, User $actor): void;
}

final class StockProductionPlanningService
{
    public function create(array $validated, User $actor): WorkOrder;
    public function reconcile(WorkOrder $root, User $actor): array;
}
```

`preview()` returns order revision, `as_of`, policy/editability reasons, per-line requested quantity, current holds, eligible free stock, proposed stock quantity, production commitments, and shortage. It does not mutate stock, schedules, or WOs. `allocate()` locks and applies the same calculation, returning the actual coverage and changes from preview. Never accept client-supplied free/approved totals.

`lineCoverage()` returns named disjoint quantities: demand, received/delivered, allocated stock not yet shipped, order-bound approved output not represented by delivery, remaining active production commitments, awaiting QC, and uncovered demand. Transfer links identify which output quantity a shipment already represents. Stock and source production cannot both increase the same coverage.

## Task 1: Add compatible purpose, policy, and allocation ownership

**Modify:** WorkOrder, SalesOrderItem, DeliveryItem, PurchaseRequest models and their resources/types.

**Create:** `api/app/Modules/CRM/Models/SalesOrderStockAllocation.php`; `api/database/factories/SalesOrderStockAllocationFactory.php`; additive migration `api/database/migrations/2026_10_09_220000_add_stock_production_and_order_allocations.php`; `api/tests/Feature/CRM/SalesOrderStockAllocationSchemaTest.php`.

- [ ] Add a failing migration test using an existing order, WO, delivery, and collection. Assert migration preserves IDs, status, quantities, and money. Assert ambiguous internal history remains unclassified.
- [ ] Add nullable `production_purpose` (`stock`, `order`, `internal`) plus stock-creation UUID/fingerprint fields to WOs. Existing order-linked WOs can be backfilled `order`; existing non-order WOs are not automatically classified as saleable stock. A stock root and its children are `stock`, while repair classification still comes from repair relationships.
- [ ] Add nullable `fulfillment_policy` (`stock_first`, `produce_new`) to order lines. Existing lines with null behave as `produce_new`; new ordinary lines explicitly default `stock_first`. Existing replacement policy remains its current behavior until covered by the dedicated replacement tests.
- [ ] Add nullable `stock_production_work_order_id` on automatic material PRs and nullable `sales_order_stock_allocation_id` on delivery lines. Allocation retains source output, inspection, item, location, lot, expiry, allocated quantity, transferred quantity, consumed quantity, released quantity, status, actor/time, request key, and fingerprint. Do not store a second physical on-hand ledger.
- [ ] Add nonnegative quantity constraints: consumed cannot exceed transferred, and transferred plus released cannot exceed allocated quantity. A stable order-line/source-output/lot/bin allocation identity prevents replay duplication; a single request key can authorize multiple distinct source rows but never a changed payload. Reference deletion restricts live allocation history rather than cascading it away. Shipment cancellation releases its remaining transferred hold and creates an allocation revision; do not count it again as an untransferred release.
- [ ] Implement rollback preflight: refuse destructive downgrade when stock jobs, allocations, or linked PR provenance exists. Preserve representative old records through empty-data up/down/up.

Example invariant test:

```php
$allocation = SalesOrderStockAllocation::factory()->create([
    'quantity' => '5.000', 'transferred_quantity' => '0.000',
    'consumed_quantity' => '0.000', 'released_quantity' => '0.000',
]);
$this->expectException(\Illuminate\Database\QueryException::class);
$allocation->update(['released_quantity' => '6.000']);
```

Expected proof: old rows unchanged; invalid ownership/counters rejected; downgrade with provenance refuses safely.

## Task 2: Make stock creation and planning complete

**Modify:** WorkOrderController/Service/request/resource; CapacityPlanningService; existing assignment endpoints; PurchaseRequest model; BOM child reconciliation seam.

**Create:** StockProductionPlanningService; `api/tests/Feature/Production/StockProductionPlanningTest.php`.

**Regression suites:** `WorkOrderCrewTest`, `WorkOrderOutputFgReceiptTest`, `WorkOrderMaterialIssueHandoffTest`, `WorkOrderMaterialHandoverGateTest`, `CapacityPlanningServiceTest`, `SubassemblyWorkOrderTest`, `QualityReworkReinspectionTest`.

- [ ] First write tests: two identical stock-create retries return one Planned WO; changed payload with the same key fails; stock WO has no Sales Order; internal stock QC creates an outgoing inspection and does not draft a delivery.
- [ ] Extend the existing create endpoint for explicit stock purpose. Require request UUID, product, whole positive quantity, internal stock-ready deadline, and existing staffing limits. Forbid Sales Order/line IDs for stock creation and forbid clients from submitting approval/actual-production fields. Keep ordinary create callers compatible.
- [ ] In a transaction, validate active product/finished-good item mapping, BOM integrity, units, routing, and current production readiness. Create the Planned root, direct requirements, genuine manufactured children, and idempotent draft material-shortage PRs. Reconcile only untouched automatic records; do not approve purchases or issue materials.
- [ ] Generalize the narrow child-creation seam to accept a stock root without inventing a nullable-Sales-Order MRP plan. Preserve ordinary order reconciliation, root/child ownership, netting, and cost snapshots. Use existing reservations for BOM components and exclude order-held lots from available component supply.
- [ ] Generate the existing schedule/crew proposal after root reconciliation. A scheduling failure keeps one recoverable Planned WO and returns blockers; retry reuses that WO. A proposal is provisional capacity, not a confirmed worker assignment.
- [ ] Reuse assignment apply to confirm equipment, crew, recorder, and slot once. Preserve stale-schedule timestamps, worker checks, minimum/maximum range, mold life, maintenance, material/quality, and no-overlap rules. No automatic runtime Start or overtime.

Representative assertions:

```php
$first = app(StockProductionPlanningService::class)->create($payload, $ppc);
$again = app(StockProductionPlanningService::class)->create($payload, $ppc);
$this->assertSame($first->id, $again->id);
$this->assertNull($first->sales_order_id);
$this->assertSame('stock', $first->production_purpose);
$this->assertSame(1, WorkOrder::where('stock_creation_request_key', $payload['request_key'])->count());
```

HTTP flow stays `POST /production/work-orders` for creation and the existing assignment proposal/apply routes for scheduling. Responses include readiness, materials, and the saved WO ID, so network recovery never requires another independent creation.

## Task 3: Reserve approved finished goods for order lines

**Create:** SalesOrderSupplyService, SalesOrderFulfillmentController, UpdateSalesOrderFulfillmentRequest; `api/tests/Feature/CRM/SalesOrderStockFulfillmentTest.php`.

**Modify:** CRM routes and request/resources, stock movement/quality/lot availability callers, SalesOrderItem relation, and material-issue reservation protection.

- [ ] Write failing stock fixtures with real production output, process release, independently reviewed outgoing QC, a production receipt, lot movements, and warehouse stock. A naked stock counter or Passed label is insufficient.
- [ ] Implement the shared eligible-source calculation: explicit stock purpose; active finished-good mapping; latest valid approval; remaining accepted capacity; usable lot/bin quantity; nonexpired/spec-compatible sources; no quarantine, scrap, return hold, live-customer commitment, or material/order/shipment hold. Deduct safety stock exactly once per item across selected lots.
- [ ] `GET /crm/sales-orders/{salesOrder}/fulfillment` calls preview. `PUT .../fulfillment` accepts order revision, per-line policy, audit reason, and request key; permissions require existing CRM read plus production planning/confirmation authority for edits. Reject foreign lines, duplicate IDs, unsupported policies, and guarded commitments.
- [ ] Allocate at confirmed-order planning under existing cross-order MRP serialization plus authoritative SQL locks. Acquire order/line, output/inspection, allocation, and stock rows in the same established order as delivery/material/quality mutations. Do not introduce an inverse lock order. Use bounded deadlock retry and re-evaluate eligibility inside the transaction.
- [ ] Reserve selected lot/bin quantities through existing StockMovementService, persist allocation, and revalidate lot holds in all relevant issue/transfer paths. One order's allocation must block another order and a competing parent-WO material issue.
- [ ] Release only unused stock portions on pre-dispatch cancellation/reduction. Produce-new switches release editable holds and preserve progressed commitments; increase/decrease demands reconcile under revision locks. Never unreserve another owner or exceed remaining accepted release.

Scenario matrix:

| Demand / free eligible stock | Expected stock hold | New production |
|---|---:|---:|
| 500 / 500 | 500 | 0 |
| 500 / 300 | 300 | 200 |
| 500 / 0 | 0 | 500 |
| 500 / 500, policy produce_new | 0 | 500 |
| 500 / 500 physical, 200 held | 300 | 200 |
| 500 / 550 approved, safety stock 100 | 450 | 50 |

Concurrency proof must use two separate database connections/processes: two 400-piece orders against 500 usable pieces may reserve at most 500 in total. At least 300 remains production demand. Replay cannot increase stock_reserved; reservations do not change on-hand or journals.

## Task 4: Net top-level finished goods before BOM/production demand

**Modify:** MrpEngineService, MrpAutomationService, scope/diagnostic serializers, SalesOrder confirmation handoff, stock planner trigger integration.

**Tests:** Extend `MrpRerunSafetyTest`, `SubassemblyWorkOrderTest`, `SalesOrderStockFulfillmentTest`; add `api/tests/Feature/MRP/StockFirstPlanningTest.php`.

- [ ] Write failing tests for stock-only, mixed, no-stock, produce-new, multiple products, repeated same-product lines, and replan after stock arrival/QC revocation.
- [ ] Run allocation before checking a BOM for top-level production. A fully stock-covered order must not fail because today's production BOM is absent; source quality/spec must still be valid. A positive manufacturing shortage still requires a valid BOM.
- [ ] Replace the top-level raw production remainder with the shared disjoint line coverage. Keep existing repair/production commitments, delivered quantities, child supplies, shared material netting, and safety stock. Generate costs and materials for the manufacturing shortage only, not pieces already in stock.
- [ ] Reconcile untouched auto WOs/PRs once. Preserve confirmed/issued/started/completed jobs; newly available stock cannot create surplus by replacing progressed work. Explicit policy/quantity changes return commitment blockers, not silent deletions.
- [ ] Stock-production QC release requests one idempotent allocation/replanning pass for eligible confirmed-order shortages. It must not manufacture a customer delivery from the internal WO itself. Outbox/replay failures appear in current chain recovery reporting.
- [ ] Re-run the matrix after retry, queue replay, concurrent confirmation, cancellation, and a material failure. Demand remains conserved and no duplicate root/child/PR appears.

Core acceptance assertions for stock-only and mixed orders:

```php
$plan = app(MrpEngineService::class)->runForSalesOrder($stockOnlyOrder);
$this->assertSame(0, WorkOrder::where('sales_order_id', $stockOnlyOrder->id)->count());
$this->assertSame('500.000', bcadd((string) $stockOnlyOrder->items()->first()->stockAllocations()->sum('quantity'), '0', 3));
// A separate mixed fixture has 300 approved pieces and demand of 500.
app(MrpEngineService::class)->runForSalesOrder($mixedOrder);
$this->assertSame(200, (int) WorkOrder::where('sales_order_id', $mixedOrder->id)->whereNull('parent_wo_id')->sum('quantity_target'));
```

Use the current runForSalesOrder signature/return type during execution; keep its existing actor/run context rather than bypassing the normal confirmation job.

## Task 5: Transfer holds and draft deliveries from real supply coverage

**Modify:** DeliveryService, DeliveryStockService, DeliveryStockReservationService, DeliveryItem/resources, CreateDeliveryDraftOnQcPass, inventory dispatch reservation DTO/guards, CoC assembly.

**Create:** `api/tests/Feature/SupplyChain/StockFulfillmentDeliveryTest.php`.

**Regression suites:** DeliveryDispatchProvenanceTest, DeliveryInventoryDecrementTest, DeliveryLifecycleConcurrencyTest, DeliveryCreateIdempotencyTest, DeliveryConfirmTest, CocAutoAttachOnConfirmTest, AutoInvoiceOnDeliveryConfirmTest, return-case/replacement receipt suites.

- [ ] Write a stock-production fixture fulfilled by a different destination order. First prove existing unrelated order-bound inspection still rejects; valid allocation allows the internal source only.
- [ ] Introduce the allocation-authorized branch at both create and dispatch provenance checks. Require destination line ownership, product/spec match, source output/inspection/receipt consistency, latest approval, and remaining allocated quantities. Keep ordinary same-order validation.
- [ ] Transfer order hold to delivery reservation atomically: bind allocation, create the shipment hold using its already reserved quantity, and mark ownership transfer. Do not call the counter-increasing reserve operation twice. Dispatch consumes once through StockMovementService; reflect consumed ownership in the allocation without posting another journal.
- [ ] Unify auto drafting by order supply identity with a structured source key. For mixed orders hold partial approved stock, then draft/complete one shipment once total approved supply is ready. If explicitly partial-shipped, preserve that immutable shipment and draft the remaining quantity separately. Do not append automatically to assigned or dispatched delivery contents.
- [ ] Handle return/release: shipment cancellation releases physical hold and leaves an auditable allocation revision; continued order demand re-allocates through the shared service. In-transit/received goods use the existing physical-return flow. No cancellation shortcut restores dispatched stock.
- [ ] Update CoC and delivery details for multiple real source outputs. Preserve per-source lines internally; invoices use destination price and actual confirmed billable quantity. Free replacements stay outside original demand/billing totals.

Exactly-once stock test:

```php
$before = (string) $stockLevel->fresh()->quantity;
$held = (string) $stockLevel->fresh()->reserved_quantity;
$supply->transferToDelivery($allocation, $deliveryLine, $ppc);
$this->assertSame($held, (string) $stockLevel->fresh()->reserved_quantity);
$deliveryService->updateStatus($delivery, DeliveryStatus::InTransit);
$this->assertSame(bcsub($before, '500.000', 3), (string) $stockLevel->fresh()->quantity);
$this->assertSame('0.000', (string) $stockLevel->fresh()->reserved_quantity);
```

Also test concurrent transfer/departure, expiry before departure, quality revocation, bin transfer, same lot across bins, issue to another WO, and forged allocation IDs. Invalid transitions leave stock, deliveries, and journals unchanged.

## Task 6: Show honest supply/quality/fulfillment progress everywhere

**Modify:** SalesOrderService `chain()`, `hasCompletedProduction()`, `deriveOutgoingQcStage()`; SalesOrderResource/portal projections, chain tracer, dashboard/notification callers, lists and delivery preparation.

**Tests:** SalesOrderChainStageTest, SalesOrderChainBridgeTest, SalesOrderChainBillingDatesTest, SalesOrderStatusTransitionsTest; `spa/src/lib/chains/o2c.test.ts`, CRM and portal order-detail tests.

- [ ] Write chain tests before edits: stock-only is supply done/QC done without an order WO; mixed supply stays active until all pieces are ready; historical failure does not override fully resolved current coverage; recalled allocated stock blocks readiness.
- [ ] Keep current keys and derive labels: stock-only **Ready from stock**, mixed **Stock + production**, otherwise **Production**. Quality coverage links actual source inspections; source production/QC dates remain historical while allocation has its own date.
- [ ] Use the shared service for consistent **Ready for delivery** projections. Do not move raw order lifecycle to In Production when no production occurs. Delivery, invoice, payment, and closure continue to require their own facts.
- [ ] Ensure customer routes receive customer-safe projections/links; no internal workers, bin addresses, allocation integer IDs, or internal notes leak. Avoid multiplying SQL queries for each list row: batch-load coverage sources for paginated records.
- [ ] Verify stock-only order cannot be marked delivered/paid by changing a display field. Partial shipment, replacement, cancelled order, draft invoice, and financial chain regression tests pass.

Representative chain assertions after allocating a stock-only confirmed order:

```php
$steps = collect(app(SalesOrderService::class)->chain($order))->keyBy('key');
$this->assertSame('done', $steps['in_production']['state']);
$this->assertSame('Ready from stock', $steps['in_production']['label']);
$this->assertSame('done', $steps['qc_outgoing']['state']);
$this->assertNotSame('done', $steps['delivered']['state']);
$this->assertSame(0, WorkOrder::where('sales_order_id', $order->id)->count());
```

## Task 7: Polish stock planning and fulfillment without extra clicks

**Modify:** Production create/list/detail/CrewPanel and API/types; CRM order detail/API/types; portal order list/detail; inventory overview and delivery detail.

**Create:** `spa/src/pages/production/work-orders/StockProductionPlanDialog.tsx` and its `.test.tsx`; `spa/src/pages/crm/sales-orders/FulfillmentDialog.tsx` and its `.test.tsx`; `spa/src/pages/production/work-orders/create.test.tsx`. Keep tests focused on actual branching/validation.

- [ ] Add **Produce for stock** to the Work orders list for existing authorized roles. Show explicit source badges/filter and correct the empty-state wording. Stock form asks product, quantity, stock-needed deadline, preferred day, and staffing range; no forced manual equipment selection.
- [ ] **Plan production** saves/reuses one Planned WO and opens existing proposal data in a combined review modal. The modal shows equipment, crew, recorder, start/finish, material gaps, and errors; **Confirm production plan** is its single commit action. Reuse CrewPanel proposal/apply logic rather than copying a second planner.
- [ ] Add **Review fulfillment** for PPC on editable confirmed orders. Show stock-first/default and produce-new per line, current approved availability, allocated stock, commitments, and resulting shortage. Disable save with stale revision or incompatible commitments; retain edits and let the user refresh explicitly.
- [ ] Show inventory totals separately: approved free, reserved, held, incoming. Expose lot/inspection explanations in expandable detail, not extra required screens. Customer chain explains **Supply ready** and source approval without manufacturing terminology overload.
- [ ] All forms use server validation plus live field errors, positive quantities, valid dates, and disabled duplicate submission. Preserve request keys for retry, trap modal focus, return focus on close, prevent accidental close while submitting, and support 390 px layouts with readable tables.
- [ ] Delivery retains one shipment plan, automatic stock reservation, driver-only proof, and customer receipt confirmation. Existing payment and removed sidebar behavior remain unchanged.

UI acceptance test sketch:

```tsx
fireEvent.click(await screen.findByRole('button', { name: 'Produce for stock' }));
expect(screen.queryByLabelText(/Sales Order/)).not.toBeInTheDocument();
fireEvent.change(screen.getByLabelText(/Quantity/), { target: { value: '-1' } });
expect(await screen.findByText('Enter a positive whole-piece quantity.')).toHaveClass('text-danger-fg');
expect(screen.getByRole('button', { name: 'Plan production' })).toBeDisabled();
```

Browser proof at 390 px and 1440 px: create proposal, inspect blockers, confirm valid plan in an isolated environment, choose stock/fresh for eligible orders, inspect mixed quantities, follow source evidence, and complete real delivery/receipt/payment flows using test fixtures.

## Task 8: Focused integrated verification and rollout

- [ ] Run migration preservation and rollback refusal tests first, then the focused backend suites sequentially. Resolve actual new failures without weakening existing Production/QC/financial guards.
- [ ] Run independent-process allocation, stock transfer/departure, and MRP replay races against dedicated test databases. Assert demand conservation, one source hold, one physical issue, and no extra production capitalization/COGS.
- [ ] Run connected React tests and production TypeScript/Vite build. Do one combined desktop/mobile browser inspection, repair material defects in one batch, and stop once acceptance passes.
- [ ] Produce a read-only readiness report before live rollout: eligible stock lots, evidence/spec gaps, mapping failures, safety stock, active reservations, internal unclassified jobs, duplicate production receipts, and active jobs without valid resource slots. Never backfill uncertain jobs as approved stock.
- [ ] Back up schema and affected reference counts, apply additive migration after approved implementation/deployment, keep existing orders on current production policy, and enable the new default only for newly created lines. No automatic reallocation of historical orders.
- [ ] Verify existing paid order `SO-202610-0025`, free replacement `SO-202610-0026`, resolved case `CASE-202610-0001`, and receipt `OR-202610-0001` remain unchanged using read-only comparisons.
- [ ] Record results and operational blockers. Replenishment forecasting/min-max automation remains outside this release.

### Verification commands

Use the current isolated runner after copying only feature files and migrations. The disposable configuration must name a task-specific PostgreSQL database. Example focused runs:

```bash
docker exec ogami-finish-first-20261008 php -d memory_limit=512M vendor/bin/phpunit -c phpunit.case-validation.xml --filter 'SalesOrderStockAllocationSchemaTest|StockProductionPlanningTest|SalesOrderStockFulfillmentTest|StockFirstPlanningTest|StockFulfillmentDeliveryTest'
docker exec ogami-finish-first-20261008 php -d memory_limit=512M vendor/bin/phpunit -c phpunit.case-validation.xml --filter 'WorkOrderCrewTest|WorkOrderOutputFgReceiptTest|CapacityPlanningServiceTest|MrpRerunSafetyTest|SubassemblyWorkOrderTest|SalesOrderChainStageTest|DeliveryDispatchProvenanceTest|DeliveryInventoryDecrementTest'
```

Run sequentially. Then, from `spa/`, run:

```bash
npx vitest run src/pages/production/work-orders/create.test.tsx src/pages/production/work-orders/StockProductionPlanDialog.test.tsx src/pages/crm/sales-orders/FulfillmentDialog.test.tsx src/pages/production/work-orders/CrewPanel.test.tsx src/pages/production/work-orders/StartProductionDialog.test.tsx src/pages/crm/sales-orders/detail.test.tsx src/pages/portal/customer/orders/detail.test.tsx src/lib/chains/o2c.test.ts
npm run build
```

Do not run live business POSTs to manufacture proof.

## Completion checklist

The implementation is delivered. Unchecked detailed test sketches above remain additional verification ideas rather than claims that every proposed browser scenario or numeric fixture was executed. Tests are consolidated in the CRM stock fulfillment suite; the existing assignment, quality, delivery and accounting pipelines are reused. No separate request class or generic planner was added.

- [x] Stock job can be planned/confirmed with automatic equipment/crew suggestion without a Sales Order.
- [x] Real production/material/trial/process/final-QC gates still hold.
- [x] Approved internal output remains inventory until a real order allocates it.
- [ ] 500-stock order produces zero new pieces; 300-stock order produces 200; produce-new preserves stock.
- [x] Stock-only fulfillment does not need today's BOM but does need current usable source evidence.
- [x] Two orders and a competing manufacturing job cannot spend the same pieces.
- [ ] Preview, MRP rerun, queue retry, cancellation, and policy change preserve quantities and ownership.
- [x] Transfer and dispatch consume stock/cost once, and preserve actual output/inspection/lot provenance.
- [ ] Chain, lists, portal, tracer, notifications, and delivery agree on supply/quality readiness.
- [x] Historical production/QC, returns, free replacements, invoices, receipts, and paid orders remain correct.
- [ ] Validation, action visibility, keyboard behavior, mobile layout, API permissions, and proof ownership pass.

Stop after these conditions pass. Do not add forecasting, automatic overtime, or a second production/quality pipeline as part of this implementation.
