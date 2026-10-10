# Warehouse Integrity and Workflow Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. User permits at most two subagents: GPT-5.6-Luna and GPT-6.1-Sol; root reviews both. Inline execution is sufficient.

**Goal:** Make Warehouse stock, lot evidence, role boundaries and physical handovers agree across receiving, Production, Quality, Procurement, Returns and Accounting, then reduce input work using existing UI components.

**Architecture:** Keep StockMovementService as the authoritative posting boundary. Reuse source movements, StockLocationSummaryService, existing alerts/outbox and maker/checker controls; extend caller allocation only where one business request legitimately spans lots. Preserve physical confirmation for handovers, returns, counts and transfers while automating defaults, grouping, calculations and reminders.

**Tech Stack:** Laravel/PHP 8.3, PostgreSQL, React/TypeScript, TanStack Query, existing Atelier components, PHPUnit, Vitest and headless Playwright.

**Spec:** `docs/testing/warehouse-deep-audit-20261005.md`

## Global Constraints

- Audit findings are proposals; this document does not authorize product implementation or business data changes.
- At most two subagents, GPT-5.6-Luna and GPT-6.1-Sol; root reviews their changes and tests.
- Preserve unrelated dirty workspace changes; stage only reviewed task files if commits are requested.
- All backend tests run serially against `ogami_test_pricing_20261004` through `api/phpunit.pricing.20261004.xml`; never RefreshDatabase on the live database.
- Browser business mutations use disposable fixtures; live checks are read-only.
- Keep independent stock adjustment approval, count actor separation, transfer creator/executor separation and Quality maker/checker rules.
- Never fabricate physical receipt, count quantity, unused return quantity, employee signature, QC result or retrospective timestamp.
- No new dependency, generic workflow engine or replacement stock ledger.
- Preserve base UOM, lot/cost lineage, replay guards, count freezes, held-stock exclusions and frozen historic WO valuation.
- Use the configured business timezone. Debounced server search uses 300 ms.
- Preserve existing Atelier design system and shared PageHeader, Panel, DataTable, SearchableSelect, Modal and semantic tokens.
- Unknown lot material is not automatically treated as traceable production stock. Show a specific reconciliation requirement when source lot cannot be established.

## Working files and verification entry points

Primary backend ownership:

- `Inventory/Services/StockMovementService.php`: final movement safety, expiry, lot-specific quantity/cost.
- `Inventory/Services/StockLocationSummaryService.php`: usable balances and lot history.
- `Inventory/Services/AutoReplenishmentService.php`, `InventoryDashboardService.php`, `Common/Services/AlertEngineService.php`: shortages and visible blocked automations.
- `Inventory/Services/QuarantineService.php`, `Requests/StoreMrbRequest.php`: suspect-lot custody.
- `Inventory/Services/StockCountService.php`, `StockAdjustmentService.php`: aggregate count, variance allocation and value.
- `Inventory/Services/MaterialIssueService.php`, `StockTransferService.php`: atomic split allocations, cancellation/return reconciliation.
- `Inventory/Controllers/MaterialHandoverController.php`, `Services/PickingListService.php`: exact issued evidence and physical handover.
- `Inventory/Controllers/StockMovementController.php`, `Models/StockMovement.php`: source lineage and WO filtering.
- `Inventory/Controllers/WarehouseScanController.php`, `Services/BarcodeScanResolverService.php`: actor-aware context/data/actions.

Primary frontend ownership: existing `spa/src/pages/inventory/` and `spa/src/pages/warehouse/`; change shared primitives only for a verified shared problem.

Baseline reproduction:

```bash
docker cp docs/testing/evidence/warehouse-deep-20261005/WarehouseDeepAuditProbeTest.php ogami-api:/tmp/WarehouseDeepAuditProbeTest.php
docker exec ogami-api php -d memory_limit=-1 vendor/bin/phpunit -c phpunit.pricing.20261004.xml /tmp/WarehouseDeepAuditProbeTest.php
```

Expected baseline: 8 probes pass by asserting undesirable behavior. Copy relevant fixtures into genuine regression tests, invert the assertions below, verify they fail before fixing. Do not keep “defect still exists” assertions as the final product test.

## Task 1: repair Warehouse test fixtures without weakening WO crew gates

**Files:** Modify `api/tests/Feature/Inventory/MaterialReturnServiceTest.php:620`; inspect other Inventory fixtures invoking WorkOrderService::confirm. Reuse valid assignment setup from `api/tests/Feature/Production/WorkOrderMaterialIssueHandoffTest.php`.

**Interfaces:** Consume existing WorkOrderService::confirm and WorkOrderAssignmentService assignment behavior. Produce executable fixtures with eligible crew, recorder, shift and production window; no production API change.

- [ ] Run the known failing test and capture its current setup error:

```bash
docker exec ogami-api php -d memory_limit=-1 vendor/bin/phpunit -c phpunit.pricing.20261004.xml --filter test_auto_issue_return_replays_after_response_loss_and_cannot_cross_recorded_production_floor
```

- [ ] Update only fixture setup to assign an active eligible Production employee and one recorder through the existing assignment path before confirmation. Preserve the return-floor and replay assertions.
- [ ] Run that test, then the whole MaterialReturnServiceTest file. Review whether each test actually reaches its intended return/cancellation assertion.
- [ ] Check the task diff. Do not remove crew validation or use a privileged bypass to make an old fixture pass.

**Acceptance:** fixture reaches return behavior; original expected-returned, floor and original-cost assertions remain meaningful.

## Task 2: expiry and one usable-stock contract

**Files:** Modify `api/app/Modules/Inventory/Services/StockMovementService.php`, `StockLocationSummaryService.php`, `AutoReplenishmentService.php`, `InventoryDashboardService.php`, `api/app/Common/Services/AlertEngineService.php`, `Inventory/Resources/StockLevelResource.php`; test `api/tests/Feature/Inventory/StockMovementLocationAndLotTest.php`, `ReplenishmentInTransitNettingTest.php`, `InventoryDashboardBatchingTest.php`.

**Interfaces:** Retain `StockLocationSummaryService::availability(Collection $stockLevels): array`. Extend its response with `expired_quantity` and keep `usable_quantity` as the execution metric. AutoReplenishment consumes it rather than the Item fallback accessor. Stock Levels retains legacy `available` for compatibility and adds explicit usable/held quantities.

- [ ] Copy the expired-lot fixture from audit probes and replace the success assertions with rejection before posting:

```php
$before = StockMovement::query()->count();
try {
    app(StockMovementService::class)->move(new StockMovementInput(
        type: StockMovementType::MaterialIssue,
        itemId: $item->id, fromLocationId: $bin->id, quantity: '1.000',
    ));
    $this->fail('Expired material must not be issued to Production.');
} catch (BusinessRuleException $e) {
    $this->assertStringContainsString('expired', strtolower($e->getMessage()));
}
$this->assertSame($before, StockMovement::query()->count());
```

- [ ] Add cases for expiry today under the configured business-date policy, explicit expired-lot selection, alternate nonexpired lot, return of expired material, quarantine transfer and disposal. Preserve physical on-hand totals.
- [ ] Add the quarantine fixture to replenishment/dashboard tests: physical 10, usable 0, demand 5, open supply 0. Assert actionable shortage and one draft replenishment record when valid actor/cost exist. Repeat with valid future supply to assert no duplicate demand.
- [ ] Implement central expiry rejection for material issue/delivery and normal allocation exclusion. Quarantine/disposal/physical return paths remain allowed when otherwise authorized. Compute usable balances using existing ledger/hold helpers; subtract overlapping exclusions once.
- [ ] Add fields without changing legacy physical totals. Label actionable metrics “Usable now,” physical metrics “On hand,” and older quantity-minus-reservations “Unreserved.”
- [ ] Run the three relevant test files serially and inspect query-count expectations. Batch read calculations before introducing new materialized stock tables.

**Acceptance:** items, dashboard, alerts, MRP readiness and replenishment classify the same stock as executable; expired/held/quarantine quantities remain visible but cannot be consumed as normal goods.

## Task 3: correct suspect-lot quarantine

**Files:** Modify `StoreMrbRequest.php`, `QuarantineService.php`, `MrbController.php`, `spa/src/pages/inventory/mrb/index.tsx`, `spa/src/api/inventory/mrb.ts`, existing MRB types; tests `QuarantineMrbTest.php`, `StockMovementLocationAndLotTest.php`.

**Interfaces:** Public hold adds optional `lot_number`; selected source expiry is derived server-side. Existing QuarantineService::hold receives the selected authoritative lot. Existing quality-options remains item-scoped; enrich source selection only as needed.

- [ ] Seed good A=5 and suspect B=5 in one bin. POST public MRB with B and quantity 2; assert hold movement uses B, A remains 5 and B source becomes 3. Current request omits B, so this must fail first.
- [ ] Add wrong-lot/linked-quality tests and one-lot default. Sending a mismatched expiry must not alter the authoritative lot expiry.
- [ ] Add `lot_number` validation and source lot selection. Require explicit selection for ambiguous bins; verify linked Quality evidence’s source where available. Use existing net lot balances and movement guards.
- [ ] Replace separate search/dropdowns with existing SearchableSelect, 300 ms debounce, selected-label persistence and retry states. Show UOM and valid source quantity; keep optional target quarantine auto-selection.
- [ ] Run MRB tests and a mocked frontend selection test. Verify existing automatic GRN rework still preserves receipt lot.

**Acceptance:** manual and automatic holds quarantine the intended source lot, and operator cannot silently hold another receipt’s good material.

## Task 4: truthful counts and variance values

**Files:** Modify `StockCountService.php`, `StockAdjustmentService.php`, `StockCountController.php`, `StockCountItemResource.php`, `spa/src/pages/warehouse/stock-count.tsx`, inventory WMS types; tests `CycleCountWacTest.php`, `StockCountMakerCheckerTest.php`, `StockCountVarianceSoDTest.php`.

**Interfaces:** Keep count quantity at item/bin scope. Introduce `variance_allocations` only for actual nonzero adjustments with ambiguous lots; shape `[{lot_number: string|null, quantity: decimal-string}]`, quantities sum to absolute variance. Derive direction from locked count. Existing per-line approval and completion remain authoritative.

- [ ] Convert the A/B audit fixture into a test asserting confirmed B shortage posts B and leaves A unchanged. Add overage B, multiple-lot variance allocations, zero variance and missing allocation tests.
- [ ] Add exact value regression:

```php
$this->assertSame('200.00', (string) $session->fresh()->variance_value);
$this->assertSame('200.00', (string) $movement->total_cost);
```

- [ ] Replace misleading optional “Lot number” with an actual variance allocation step only when required; for one lot, preselect it. Show counted quantity/UOM, difference and total allocated difference. Ordinary zero-variance counts remain one quantity entry.
- [ ] Under existing locks, validate allocation sums, available source lot balances and count lineage before any posting. Pass each confirmed lot through StockMovementInput. Sum final adjustment values for session variance_value.
- [ ] Preserve maker/checker/completer restrictions and atomic rollback if any allocation fails. Run count, freeze and WAC tests serially.

**Acceptance:** count scope remains understandable, line evidence matches ledger lots, money report reflects posted value, no partial completion on failure.

## Task 5: split lot fulfillment without hidden shortage

**Files:** Modify `StockTransferService.php`, `MaterialIssueService.php`, Production callers of single-lot material issues and relevant reservation linkage; tests `TransferOrderRaceRegressionTest.php`, `MaterialIssueReservationLinkTest.php`, `LotTraceabilityTest.php`.

**Interfaces:** A request spanning lots produces one ledger movement per exact lot; material issue slip may have several source lines for one requested item. Keep current transfer return type if callers only require the final movement, but verify total linked movements represent the full order. Never force several source lots into one misleading movement.

- [ ] Invert audit fixture to require successful issue 8 from A=5/B=5 and total source decrease 8; assert separate movements A=5/B=3, quantity/cost conservation and source links.
- [ ] For known A=5 plus untracked=5, assert either safe transfer of both balances or an explicit “untracked stock needs reconciliation” outcome under the selected traceability policy. Never return generic available-lot shortage while showing 10 fully issuable units.
- [ ] Allocate using existing FEFO order and delivery/QC/count/reservation restrictions. Do not relax single-movement lot checks. Adapt each caller to split requests and preserve one transaction and replay identity.
- [ ] Run transfer and material issue tests, including insufficient aggregate, concurrent execution, reservation owned by another WO, held lot and response-loss replay.

**Acceptance:** source availability and next-step fulfillment agree; exact lot rows conserve total quantity/value and hold protections.

## Task 6: issued evidence, grouped handover and safe cancellation

**Files:** Modify `PickingListService.php`, `MaterialHandoverController.php`, `MaterialIssueService.php`, `spa/src/pages/warehouse/picking.tsx`, `MaterialHandoverButton.tsx`, `spa/src/pages/inventory/material-issues/detail.tsx`, `Inventory/routes.php`, WMS types; tests `WarehouseReservationHandoverTest.php`, `MaterialReturnServiceTest.php`, picking/handover frontend tests.

**Interfaces:** Issued picking line adds `issued_source: {location, lot_number, expiry_date, quantity}` from its original movement. Keep current handover endpoint for compatibility. Grouped handover endpoint accepts source IDs with expected quantities and one eligible recipient; internally applies the same locks and uniqueness checks, transactionally. No second issue movement.

- [ ] Invert picking probe: original source A must remain displayed after A was deducted; no instruction to pick B. Show pending/completed handover status.
- [ ] Invert cancellation probe: an existing handover blocks automatic cancellation/restock until actual returns are recorded. Add never-handed-over reversal, partial return, fully returned cancellation and retry tests.
- [ ] Generate issued_source from the immutable movement; remove current-stock pick suggestions for issued slips. Group pending handovers by WO/slip, default eligible recorder where present, and let Warehouse confirm selected exact lines once.
- [ ] Preserve one full handover per movement in this slice. Show author as “Recorded by Warehouse”; do not imply the recipient signed. Use current crew for eligibility, and explain unresolved historical evidence instead of recording false past time.
- [ ] Cancellation checks handed-over evidence under source locks. If physical returns remain outstanding, respond with specific pending quantities and an authorized return action. Full reconciled cancellation may complete automatically.
- [ ] Add pending handover prompt to WO readiness and completed-record exception display. Initially warn and record exceptions; hard gate is a policy choice requiring review, because legacy evidence is missing. Do not silently hard-block every historical WO.
- [ ] Run source-cost return, handover, picking and cancellation tests. Verify grouped retry records no duplicate receipt and no stock movement.

**Acceptance:** physical instructions match posted lot/bin; one handover confirmation reduces work; cancelling a record cannot invent physical returns.

## Task 7: complete WO lineage and scanner permissions

**Files:** Modify `Models/StockMovement.php`, `StockMovementController.php`, `BarcodeScanResolverService.php`, `WarehouseScanController.php`, `spa/src/pages/warehouse/scanner/index.tsx`, `spa/src/pages/inventory/movements/index.tsx`; tests `WarehouseMovementReferenceTest.php`, `WarehouseScanTest.php`, `WarehouseRoleBoundaryTest.php`.

**Interfaces:** workOrderReference resolves direct WO, output, linked MIS and source-linked return. Resolver takes an actor explicitly or controller filters through an actor-aware helper; update every call site. Stock lookup remains available under inventory.view; cross-module data and actions require target permission.

- [ ] Seed direct WO issue, linked manual issue, output and source return; GET movements with work_order_id and assert all four appear once. Query another WO and assert none leak.
- [ ] Inventory-only actor may resolve an Item but must not retrieve PO metadata or receive a Quality/output action. Seed a count context without manage permission and assert supplied context is denied before count record IDs are returned.
- [ ] Extend source relations and WO filters; use real document numbers and permission-aware Warehouse evidence links. Resolve returns to their original issue/WO without recursively following arbitrary malformed references.
- [ ] Validate supplied scanner context permission, existence and current state; filter actions for target permissions before returning href. Do not rely on hidden frontend buttons as authorization.
- [ ] Fix count-only map integration: map query enabled only for authorized map view; count-only navigation remains on an allowed count route.
- [ ] Run scanner/reference/role tests and frontend forbidden-action scenarios.

**Acceptance:** WO material chain is complete; scanner never offers an action that the current actor cannot use; unrelated metadata is not returned through a lookup shortcut.

## Task 8: visible replenishment failures and prepared reconciliation

**Files:** Modify `AutoReplenishmentService.php`, `Listeners/CheckReorderPoint.php`, existing AlertEngineService/AlertType or chain outcome infrastructure, existing alert notification routing, relevant bounded console command registration; tests `AutoReplenishmentAttributionTest.php`, `AutoReplenishmentOverlapTest.php`, `LowStockNotificationTest.php`.

**Interfaces:** Keep `checkAndReplenish(int $itemId): ?PurchaseRequest` for existing callers. On blocked actor/cost/quantity, persist an explicit deduplicated condition with reason and item. Healthy sufficient/covered conditions resolve that alert. Retry uses this same service, preserving item lock and commitment netting.

- [ ] Missing actor and zero cost fixtures still create no PR, but assert a visible blocked condition. Repeated events create one active condition, not a notification flood.
- [ ] Correct actor/cost then run bounded reconciliation; assert one PR is created and condition resolves. Repeat and assert PR count remains one.
- [ ] Reuse existing alerts/recovery rather than a new generic orchestration service. Route to users capable of fixing master data and Purchasing. If a recipient lacks PR read permission, link to authorized item/exception evidence.
- [ ] Add automatic preparation of unused return review on WO completion: prefill source quantity/lot/bin/cost and a reason selector. Require actual returned quantity; no automatic positive stock movement.
- [ ] Keep safety-stock’s existing nightly schedule; add ABC-based count drafts only after schedule and count-scope policy is configured. No automatic count numbers or variance approvals.
- [ ] Run replenishment/notifications tests. Verify retry failure remains visible and no duplicate financial or purchase record.

**Acceptance:** operators know why automation did nothing and what owner acts next; corrected setup can recover without an unrelated stock movement.

## Task 9: compact, accessible and bounded Warehouse UI

**Files:** Modify `spa/src/pages/warehouse/map.tsx`, `transfers.tsx`, `stock-count.tsx`, `pages/inventory/movements/index.tsx`, `material-issues/create.tsx`, `mrb/index.tsx`, `Inventory/services` list/controller files; shared Checkbox only for padded hit area; `StockCardController.php` for invalid filter handling.

**Interfaces:** Preserve established route/query names. Counts and transfers gain standard paginated responses; update both API client/types and list UI together. Source lookup search is server-side 300 ms debounced; selected record label survives pagination.

- [ ] Add mocked tests for unknown location filter, count-only navigation, slow/error lookups and selection beyond the first item page. Add browser expectation:

```ts
expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
```

- [ ] Warehouse Map uses SearchableSelect for warehouses and existing segmented Map/Count toggle. Persist warehouse/zone selection in URL; mixed bins show item count, never sum unlike units as inventory quantity.
- [ ] Movement table defaults to Date, Type, Item, Quantity, Reference, Warehouse status and one details action. Costs and GL remain available through optional columns/details and permission-aware status summaries. Use a contextual drawer/modal for handover and actual unused return.
- [ ] Material-issue rows use associated labels/ARIA names including line/item. Base UOM is source-derived and locked. Valid alternative units use a selector backed by existing conversions. No raw unit-code typing in normal flow.
- [ ] Combined searchable controls replace separate search/dropdowns in MRB; debounce item and Quality queries 300 ms. Preserve failed-entry work and provide inline retry.
- [ ] Add bounded standard pagination to count/transfer histories and server search to item lookups; stop requesting every catalog page on form mount. Stock card rejects malformed location instead of broadening scope; retain correct running opening balance.
- [ ] Increase checkbox target padding to at least 24 px; keep sensible 44 px shop-floor controls. Review focus, labels and error announcements, not just screenshot styling.
- [ ] Batch inspect desktop/mobile and light/dark once; fix findings together; one confirmation pass maximum. Run targeted Vitest and Playwright tests; build once after changes.

**Acceptance:** map has no phone page overflow; primary actions are easy to find; large catalogs remain searchable; errors preserve work; fields are named; workflow truth matches UI copy.

## Task 10: receiving attribution and cross-module final verification

**Files:** Modify `GoodsReceiptNoteController.php`, `GrnService.php`, `StockCardController.php` if not completed in Task 9; tests `ReceiveGoodsAuthorizationTest.php`, `GrnQcGateTest.php`, relevant role tests; create `spa/e2e/warehouse-chain-integrity.spec.ts` and update audit completion evidence.

**Interfaces:** inspector_id is actor-owned or a validated active Quality delegate; actual author remains the signed-in acting user. Existing terminal verdict permission and maker/checker remain unchanged.

- [ ] Add pending and terminal inline receipt tests with inactive/non-Quality delegated inspector; expect 422/403 without GRN or stock mutation. Active authorized Quality delegation preserves real author/inspector distinction.
- [ ] Implement the narrow attribution check; remove numeric-ID fallback for new client contracts while preserving explicit legacy compatibility where tested.
- [ ] Execute disposable role-based journey: customer SO, Sales confirmation, PPC MRP/crew/WO confirmation, Warehouse reservation/material issue and handover, Production recorder output, Quality batch review, Warehouse actual unused returns and lot custody, authorized outbound delivery, Accounting journal checks.
- [ ] Include negative cases: expired lot, wrong-lot MRB, mixed-lot issue, duplicate grouped handover, cancelled handed-over slip, shortage with blocked replenishment setup, count freeze, forbidden scanner context, stale return balance and duplicate dispatch.
- [ ] Verify exact quantity/cost invariants after each successful step; failed actions leave no partial writes. Compare direct WO, MIS and source return in movement queries.
- [ ] Run required tests serially to a complete summary. Resolve stale fixtures; never call an interrupted suite passing. Record browser route/account evidence and remaining physical-device limitations.
- [ ] Update the audit with each finding’s fixed/deferred status and proof; stop when acceptance conditions pass. Resume the user’s manual B2B walkthrough one step at a time.

## Self-review and stop condition

All W01–W14 and U01–U07 map to tasks above; receiving test fixture gap is Task 1. No fix is allowed to weaken existing approval, crew, lot, cost, replay or stock protections. Prepared automation must remain distinguishable from physical confirmation. Completion requires full targeted test summaries, disposable transaction E2E, desktop/mobile verification and explicit deferred policy choices, with no unrelated workspace changes.

Grouped handover, partial handover, hard production handover gate and ABC count scheduling have different operational tradeoffs. This plan recommends grouped full handover, prompts/visible exceptions and draft count scheduling; partial handovers and a strict start gate remain policy decisions to review before implementation. No extra approvals are required merely to perform the authorized audit or write this plan.


## Execution status — 2026-10-05

This section is the delivery record. The original task checklists above preserve the approved proposal and suggested test sequence; they are not an assertion that each optional mechanism or exact proposed test filename was implemented.

| Task | Status | Delivered result |
|---|---|---|
| 1 | Complete | Repaired production/return fixtures with crew, equipment, schedule and real material evidence; retained production gates. |
| 2 | Complete | Central expiry protection and common usable-stock calculation; dashboard/replenishment and Stock Levels use it. |
| 3 | Complete | Authoritative suspect-lot MRB selection, linked-source validation and compatible replay. |
| 4 | Complete | Additive variance allocations, exact lot posting and currency variance value with independent completion. |
| 5 | Complete | Atomic multi-lot fulfillment; explicit reconciliation for mixed untracked balances. |
| 6 | Complete | Immutable issued source, grouped full handover, safe cancellation and visible WO review prompts. |
| 7 | Complete | WO/MIS/output/return lineage and actor-aware scanner data/actions. |
| 8 | Complete within approved policy | Durable blocked replenishment, deduplicated owner notices, bounded nightly retry and prepared unused-return review. ABC scheduling remains conditional on an approved count policy. |
| 9 | Complete | Compact Warehouse UI, combined debounced selectors, named fields, mobile map, correct units, pagination and count-only access. |
| 10 | Complete | Receiving attribution, disposable customer-to-Warehouse/Production/QC HTTP journey, actual-cookie operational browser phases and final focused verification. Downstream order-to-cash is verified separately; no single full-chain browser claim. |

Evidence and remaining operational limits are recorded in `docs/testing/warehouse-deep-audit-20261005.md`, under approved implementation and verification. Physical custody remains confirmed by people; partial handovers, strict start enforcement and recipient signatures were intentionally not added. No additional approval was requested for already authorized implementation.

Final gates completed: customer/Warehouse chain 1 test / 29 assertions; downstream order-to-cash 5 tests / 369 assertions; final headless map/access/Stock Levels 3 tests; production build, focused formatting and diff whitespace checks passed.
