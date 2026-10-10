# Inventory, warehouse, maintenance, and supply-chain audit

Approved repairs and verification: [operations cross-module hardening](operations-cross-module-hardening.md). The reproductions below remain the original audit baseline.

Date: 2026-10-04. Scope: current workspace, including existing uncommitted changes.

The audit found **20 operational defects: 9 high-priority and 11 medium-priority findings**, plus one accounting-policy risk. These are audit findings, not implemented fixes. Existing pricing and WO/QC remediations were not reopened solely because older audit documents described a problem.

## Evidence and boundaries

- The isolated PHP probe run completed with **29 tests and 90 assertions**, exit code 0. Twenty-six probes assert observed unsafe behavior; three assert existing safeguards. Passing these probes proves reproduction, not that the application is safe.
- PHP probes use `DatabaseTransactions`, assert the exact isolated database name `ogami_test_pricing_20261004`, and roll back their fixtures. Events are faked. They do not prove queue, notification, email, or journal-delivery behavior.
- Headless Chromium checked three existing role accounts on the audit application at `http://localhost:18080`: maintenance technician, warehouse staff, and ImpEx officer. Page navigation and lookup requests were read-only. Selecting Mold on the maintenance form produced an actual 403; parts and stock lookups also returned 403. Warehouse receiving and delivery lookups, and ImpEx shipment and fleet lookups, returned 200.
- Race probes simulate interleaving by loading a model, committing the competing update within the isolated test transaction, and invoking the original action. This proves stale-state acceptance; it is not a simultaneous two-connection stress test.
- No product code or production business records were changed by this audit. Browser sign-in created normal sessions. No build or application-wide regression suite was run because no application implementation changed.

Probe source and browser results: [operations-20261004](probes/operations-20261004/).

## High-priority findings

| ID | Module connection | Current behavior and consequence | Evidence | Smallest correction |
|---|---|---|---|---|
| OPS-01 | Warehouse transfer / MRB / production issue | Hold five units in quarantine, transfer them to a good bin through an ordinary transfer order, then issue all five. The MRB record remains Held with no release movement. Material can bypass the Quality release decision. | `StockMovementService.php:503`, `TransferOrderService.php:72`; `test_transfer_order_removes_held_quarantine_stock_without_mrb_release` | Enforce held-stock release authorization in the shared movement service. Preserve the explicit MRB disposition path. |
| OPS-02 | Item UoM / approved PO / receiving | Before the first receipt, an administrator can change or delete a conversion used by an approved PO. A two-bag order originally representing 10 kg can become 100 kg when the factor changes from 5 to 50. Deleting the conversion blocks receipt conversion. | `UomController.php:149,179,217`, `GrnService.php:2252`; conversion-change and conversion-deletion probes | Protect conversions used by open purchasing commitments. Reuse existing purchasing dependency checks; snapshot factors on order lines only if legitimate future conversion changes must coexist with open orders. |
| OPS-03 | PO quantities / warehouse GRN form | `quantity_remaining` subtracts accepted base units from ordered purchase units. Two bags at five kg per bag report remaining quantity 2 alongside an item measured in kg. After accepting five kg, the resource reports zero remaining although another five kg is due. The form filters out that line, defaults receiving UoM to blank, and validates entered quantity against the mismatched remaining quantity. | `PurchaseOrderItem.php:68,75`, `ReceivablePurchaseOrderResource.php:24`, `spa/src/pages/inventory/grn/create.tsx:101,143`; receivable-resource probe | Return explicit base-unit ordered/received/remaining quantities; initialize and validate entered receiving quantities consistently with their selected UoM. |
| OPS-04 | Mold maintenance / production scheduling | Starting mold maintenance leaves an available mold available for production assignment. Maintenance can also start on an in-use production mold; completing it resets its shot counter while the production WO remains in progress. | `MaintenanceWorkOrderService.php:252,299`, `WorkOrderService.php:349,963`; two mold ownership probes | Lock and claim the mold at maintenance start, reject production ownership conflicts, and release only the maintenance-owned mold. |
| OPS-05 | Machine maintenance / sibling maintenance orders | Two machine maintenance orders can start. Completing the first releases the machine to Idle while the second remains In Progress. Cancelling a second order that never started can also release a machine owned by another active order. | `MaintenanceWorkOrderService.php:223,335,371`; sibling-completion and unstarted-cancellation probes | Make machine release conditional on maintenance ownership and other active orders under the machine lock. Reject duplicate starts unless concurrent maintenance is deliberately supported. |
| OPS-06 | Maintenance role / inventory and mold lookups | The technician can create and complete maintenance work orders but cannot read the mold picker or the inventory lookups used to record spare parts. Required requests return 403. The mobile stock picker also expects `quantity_on_hand`; the resource returns `quantity` and `available`. | `RolePermissionSeeder.php:888`, `spa/src/pages/maintenance/work-orders/create.tsx:42`, `mobile/work-order.tsx:101,117,492`, `StockLevelResource.php:26`; role-based PHP and headless browser checks | Provide narrow maintenance target, spare-part, and usable-stock lookups. Display the resource's available quantity and show lookup errors instead of an empty picker. |
| OPS-07 | Repair completion / production runtime / preventive maintenance | A paused WO records two hours of production before breakdown. Runtime recomputation reports 2.00 while downtime is open. Maintenance completion closes the downtime but leaves the WO paused; recomputation then skips the entire open WO and reports 0.00. Runtime history and hour-based maintenance thresholds regress. | `MachineHoursService.php:63`, `MaintenanceWorkOrderService.php:287`; paused-runtime probe | Preserve elapsed production time up to the pause boundary while preventing runtime from accruing during a pause. Reuse the existing production/downtime history. |
| OPS-08 | Shipment costs / GRN / inventory valuation | Calculating freight at 100 then editing it to 200 leaves the allocation at 100. Accepting stock uses the stale allocation. Skipping calculation accepts nonzero freight with zero landed cost. First acceptance then prevents recalculation. | `ShipmentService.php:248`, `GrnService.php:2140`, `LandedCostService.php:65`; stale-cost and missing-cost acceptance probes | Recalculate or invalidate allocations when inputs change. Before first acceptance, require current cost inputs and allocations to reconcile, including an explicit calculated zero-cost case. |
| OPS-09 | Driver ownership / delivery reassignment and rescheduling | Driver ownership and the loading date are checked on the route-bound model. The shared lifecycle service locks and reloads the delivery without rechecking those driver constraints. A stale request can start a delivery reassigned to another driver or rescheduled five days into the future. | `DriverDeliveryService.php:89`, `DeliveryService.php:698`; reassignment and future-date interleaving probes | Recheck driver identity and loading date against the locked delivery inside the lifecycle transaction. Keep current lock ordering. |

Paths above are relative to `api/app/Modules/` unless prefixed with `spa/`; the role seeder is under `api/database/seeders/`.

## Medium-priority findings

| ID | Module connection | Current behavior and consequence | Evidence | Smallest correction |
|---|---|---|---|---|
| OPS-10 | Multi-lot stock / transfer order | A bin contains two lots of five units each. A six-unit transfer without a selected lot chooses one five-unit lot and fails despite aggregate stock of ten. The ordinary transfer UI cannot resolve the split. | `Inventory/Services/StockMovementService.php:135,457`; mixed-lot transfer probe | Execute per-lot movements or expose explicit per-lot transfer lines. Preserve lot identity. |
| OPS-11 | Multi-lot stock / picking suggestions | The same bin suggests picking six units from Lot A although Lot A has only five. The suggestion attaches a single lot to aggregate bin availability. This creates impossible instructions and weakens FEFO/FIFO guidance. | `Inventory/Services/PickingListService.php:147`; picking probe compares suggested quantity with the authoritative lot ledger | Suggest quantities per lot, account for reservations and Quality holds, and split picks where needed. |
| OPS-12 | Preventive maintenance completion / runtime baseline | Completion takes the cached `running_hours_total` as its baseline. Production since the last runtime sweep is later counted as post-service runtime, potentially making the freshly serviced machine immediately due again. Probe: two pre-service hours, cached zero, one-hour maintenance interval. | `Maintenance/Services/MaintenanceScheduleService.php:190`; stale-hour baseline probe | Compute current runtime at service completion before capturing the baseline. Use the same authoritative runtime calculation. |
| OPS-13 | Spare-part usage / stock ledger precision | The request accepts any positive numeric quantity. Issuing 0.001 decreases stock by 0.001, while the usage record stores 0.00 because its quantity column and cast have two decimal places. Its unit-cost precision also differs from stock movements. | `Maintenance/Requests/RecordSparePartUsageRequest.php:34`, `Maintenance/Models/SparePartUsage.php:31`, migration `0103:22`; fractional-usage probe | Match stock-ledger quantity and unit-cost precision. Validate whole pieces where the item's UoM requires them. |
| OPS-14 | Inventory master archive / open procurement | An item with an approved PO but no stock or movement history can be archived. Receiving then throws `ModelNotFoundException` when converting that PO line, blocking the open order until the item is restored. Open PR references are also absent from the archive guard. | `Inventory/Services/ItemService.php:198`, `Inventory/Models/Item.php:168`, `Inventory/Services/GrnService.php:2213`; approved-PO archive probe | Reuse open procurement dependency checks before archive or deactivation. Keep historical document references readable. |
| OPS-15 | Landed-cost rounding / per-item valuation | Ten equal lines sharing 0.05 freight allocate 0.01 to the first nine and −0.04 to the last. The header reconciles, but the last line has a negative charge. | `SupplyChain/Services/LandedCostService.php:247`; exact cent-allocation probe | Allocate integer cents with bounded remainder distribution so all line allocations stay nonnegative and sum to the total. |
| OPS-16 | Return-pending delivery / fleet lifecycle | Fleet status and archive guards omit Return Pending. A truck with an unresolved return can be marked Available or archived. Depot reconciliation searches only unarchived vehicles, so an archived In Use truck skips release; restoration does not reconcile its status. Dispatch still prevents reuse while the return is active. | `SupplyChain/Controllers/VehicleController.php:101,127,150`, `DeliveryAttemptOutcomeService.php:297`; role-based status/archive probe plus static reconciliation trace | Include Return Pending in the existing fleet guards. Historical display already uses `withTrashed()` and remains intact. |
| OPS-17 | Partial shipment content / landed costs | Calculation allocates costs across every PO line, including lines absent from this shipment's GRN. With two equal PO lines and only the first received, freight 100 gives the received line 50; the other 50 belongs to an unreceived line and is not capitalized in this receipt. Recalculation freezes after acceptance. | `SupplyChain/Services/LandedCostService.php:79`, `Inventory/Services/GrnService.php:2146`; partial-content receipt probe | Allocate against actual shipment receipt content before acceptance. Do not assign this shipment's freight to absent or zero-received lines. |
| OPS-18 | Landed-cost option / operator understanding | Selecting Manual performs an equal split. The endpoint accepts no per-line amounts. The recorded method promises a decision the operator cannot make. | `SupplyChain/Services/LandedCostService.php:186`, `ShipmentController.php:249`; manual-allocation probe | Rename the option to Equal Split. Add explicit manual amounts only if the business needs them. |
| OPS-19 | Delivery demand / automated replenishment | Days-of-supply demand counts material issues and scrap, but not finished-goods deliveries. A finished-good item with 3,000 units delivered over a 30-day window, seven-day lead time, and 1.5 coverage buffer proposes only the reorder point of 10 instead of 1,050. Item lead time zero also ignores the configured default when calculating quantity even though the PR date uses it. | `Inventory/Services/AutoReplenishmentService.php:154`; delivery-demand and default-lead-time probes | Define relevant demand movement types by item usage and use one effective lead time for both quantity and required date. |
| OPS-20 | Usage value / ABC priorities | Classification adds an item's entire value before choosing its band. An item representing all positive usage becomes C, and a dominant item crossing a threshold can receive a lower priority than intended. This distorts stock-control priorities. | `Inventory/Services/AbcClassificationService.php:89`; single-positive-usage probe | Apply the agreed threshold boundary convention so the highest-value item is retained in A, and preserve C for zero-demand items. |

## Accounting-policy risk

**ACC-01 — maintenance parts use the production consumption account.** `Maintenance/Services/SparePartUsageService.php:62` creates a Material Issue referencing the maintenance work order. `Inventory/Services/MovementGlPostingService.php:331` maps all Material Issue movements to `accounting.accounts.material_consumption_code`, whose seeded default is **5010 Direct Materials**. The chart also contains **6100 Repairs & Maintenance Expense**. Maintenance consumption may therefore be classified as production material expense.

This is a static mapping finding, not a fresh journal-posting reproduction. Confirm the accounting policy, then use the maintenance reference context in the existing posting service if repair expense requires a separate account. No additional accounting workflow is needed.

## Controls verified and findings excluded

- Direct material issue from quarantine is blocked. The remaining defect is the generic transfer escape, not direct consumption.
- PO received-quantity precision matches GRN totals: four receipts of 10.005 total 40.020. The older two-decimal receipt-total report is excluded.
- A stale description-only maintenance schedule edit preserves the new completion date through Eloquent dirty-column behavior. That specific proposed race was falsified and excluded; other stale schedule edits were not concurrency-tested.
- Current delivery dispatch checks reservations, shared vehicle/driver availability, and Return Pending conflicts. The fleet archive gap does not prove double-booking.
- Vehicle history remains visible after archive because `Delivery::vehicle()` uses `withTrashed()`.
- Shipment GRN uniqueness prevents attaching multiple receipts to one shipment. Duplicate capitalization through multiple GRNs on that shipment is excluded.
- Existing customs evidence, receiving handoff, delivery stock decrement, proof protection, CoC/invoice retries, incoming QC gates, maintenance terminal-state guards, and linked-asset maintenance ownership were present in inspected code. This audit did not rerun every historical regression test for those controls.
- A constructed zero-received GRN line without incoming QC was correctly rejected. OPS-17 instead reproduces a receipt containing only the actually received PO line; it does not depend on bypassing that QC gate.

## Recommended repair order and polish

1. Close quarantine release, maintenance ownership, and driver authorization gaps first. These require shared lifecycle guards, not a new workflow engine.
2. Make UoM, receivable quantities, and landed-cost snapshots consistent before stock acceptance. Preserve immutable history after posting.
3. Restore technician task access with narrow lookups. Show actionable lookup failures, actual available stock, and locked item UoM on maintenance forms.
4. Fix multi-lot transfers and picks, runtime baselines, spare-part precision, and Return Pending fleet guards. Explain split picks and active assignment blockers in the existing screens.
5. Correct replenishment demand and ABC boundary behavior. Show the demand window, effective lead time, and calculation basis on existing item/planning views; avoid adding a separate automation dashboard for these two calculations.

## Reproduction commands

Run PHP probes sequentially against the existing dedicated test configuration:

```bash
docker exec ogami-api mkdir -p /tmp/operations-audit
docker cp docs/testing/probes/operations-20261004/. ogami-api:/tmp/operations-audit/
docker exec ogami-api php vendor/bin/phpunit -c phpunit.pricing.20261004.xml /tmp/operations-audit
```

Run the read-only browser check from the SPA directory with the existing seeded audit accounts:

```bash
cd spa
node ../docs/testing/probes/operations-20261004/operations-role-audit.cjs
```

The browser script writes its fresh JSON to `/tmp/operations-role-audit.json`. It records observed statuses; it is an audit capture, not a passing E2E acceptance suite. The PHP probe assertions intentionally preserve current defects and must be converted to desired-behavior regression assertions when fixes are implemented.
