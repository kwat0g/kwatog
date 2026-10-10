# BOM, Inventory and connected module audit

Audited 2026-10-04. Scope: BOM definition/costing, Inventory quantities and valuation, MRP supply/demand, Production material/output handoffs, Purchasing costs, QC holds, and product selling prices. This is a focused connection audit, not a claim that every ERP module is exhaustively audited. Production code in these modules was not changed by this audit.

## Findings, ordered by practical impact

### 1. Critical: core products have no finished-goods inventory counterpart

Live read-only inspection found all eight core products (WB-001, WB-002, PC-001, PC-002, RC-001, RC-002, BB-001, BU-001) have no Inventory item whose code matches the product part number. Production output receipt relies on that code match and finished-good item type. Output can be recorded with a recovery handoff, but cannot become normal finished-goods stock until the mapping is fixed. This will block a clean SO-to-delivery test.

Evidence: `api/app/Modules/Production/Services/WorkOrderOutputService.php:575` and live product/item join. Recommendation: create reviewed finished-good counterparts and show mapping readiness on Products/BOM. Longer-term explicit product-to-item linkage is safer than mutable codes, but a validated existing code convention is the smaller first fix.

### 2. Critical: production receipt valuation can be zero despite a real product cost

Normal new production output passes a null unit cost into the stock movement. The stock service inherits the destination bin's weighted average; a new bin begins at zero. BOM cost and product standard cost do not determine that receipt's value. Inventory and cost of goods sold can be understated, making profit appear higher than reality.

Evidence: `api/app/Modules/Production/Services/WorkOrderOutputService.php:505`, `:609`; `api/app/Modules/Inventory/Services/StockMovementService.php:118`. An isolated characterization probe recorded 10 good pieces at zero unit cost even though the product standard cost was 50.00. Repair receipts deliberately preserve the source lot's cost; they should not be treated like new manufacturing receipts.

Recommendation: for new production, freeze an approved per-unit manufacturing cost on the WO/output and pass it explicitly to the receipt. Preserve existing repair cost behavior. Handle missing cost visibly rather than posting an unnoticed zero. This is estimated standard valuation unless actual material, labor and overhead are reconciled.

### 3. High: conversion edits bypass BOM cost freshness

BOM freshness checks line, item and nested BOM timestamps. It does not check the alternate-unit conversion record. Conversion edits do not touch the item or invalidate dependent BOM snapshots. The BOM explosion can therefore use a new conversion while its money snapshot still uses the old one. Conversion removal can also leave a stale snapshot until a later explosion fails.

Evidence: `api/app/Modules/MRP/Services/BomCostingService.php:67`; `api/app/Modules/Inventory/Controllers/UomController.php:156`. Isolated probe: two bags, 10% waste, cost 4/KG initially cost 220 at 25 KG/bag. After changing the factor to 50, `ensureFreshForPlanning` still returned 220; explicit recost returned 440.

Recommendation: invalidate/touch the affected item or dependent BOMs in the existing conversion write transaction, including deletes. Reuse the existing freshness/replan paths. No new scheduling framework is needed.

### 4. High: cost completeness is not established

Live data has eight active BOMs; four have no cost snapshot. Of four costed BOMs, three have material-only `standard_cost` basis and one includes routing. Product standard cost and BOM cost also differ sharply: WB-001 product cost 18.50 versus BOM 2.90; WB-002 24.50 versus 3.79; PC-001 28.00 versus 3.85. These differences do not establish which cost is right. They show that using the lowest figure to advertise profit is unsafe.

No routing returns zero conversion costs. Routing with missing rates can also appear complete because cost basis depends on routing existence, not rate completeness. Warnings currently cover zero component costs only.

Evidence: `api/app/Modules/MRP/Services/BomCostingService.php:195`, `:224`; live cost/readiness inspection. Recommendation: show Not costed, Materials only, or Reviewed manufacturing estimate; warn about missing routing/rates and zero costs. Define the approved cost basis before configuring profit floors.

### 5. High: inventory edits can break existing BOM/manufacturing links

Product part number and inventory item code remain editable; manufactured-component lookup joins those strings. Product-to-item output matching also uses that convention without validating equal base units or the item's active status in that lookup. A code change can silently turn a manufactured subassembly into a purchased-material fallback, or make finished-goods receipt mapping disappear.

Inventory item deletion/deactivation does not prevent an active BOM reference. The BOM integrity guard catches the problem later, preventing under-planning, but a normal master-data edit can still stop the production chain.

Evidence: `api/app/Modules/MRP/Services/BomManufacturedComponentResolver.php:22`; `api/app/Modules/CRM/Services/ProductService.php:102`; `api/app/Modules/Inventory/Services/ItemService.php:156`, `:162`; `api/app/Modules/Inventory/Models/Item.php:165`. Recommendation: protect codes and unit semantics after linked use; validate active finished-good mapping and matching output units. Block retiring an item used by an active BOM, with a link to the dependency.

### 6. Medium: displayed available stock differs from production-ready stock

Inventory item aggregates total stock and subtract reservations. They do not exclude quarantine, scrap zones or outgoing-QC-held production lots. MRP excludes quarantine/scrap zones, while production/material issue paths also consult QC holds. The user can consequently see stock as available while production cannot use it. MRP supply can include production lots awaiting outgoing release if those lots remain in an ordinary finished-goods bin and the hold is not reflected in reservations.

Evidence: `api/app/Modules/Inventory/Models/Item.php:150`; `api/app/Modules/Inventory/Services/ItemService.php:95`; `api/app/Modules/MRP/Services/MrpEngineService.php:1253`; `api/app/Modules/Quality/Services/QualityStockHoldService.php:29`. Existing issue guards protect release; this finding concerns planning/readiness and UI consistency. Recommendation: separately show On hand, Reserved, QC held, and Usable now. Distinguish planned incoming supply from physically usable stock.

### 7. Medium: BOM publication can fail under concurrency

BOM creation locks the previous BOM, but not the stable product before selecting the latest version. Concurrent first versions have no previous row to coordinate on. Concurrent replacements may both derive the same next version or leave a conflicting active version. The unique active-BOM database index prevents invalid final data, but can expose an unhandled database conflict to the user.

Evidence: `api/app/Modules/MRP/Services/BomService.php:93`; existing unique-active-BOM migration. Recommendation: lock the product first, then read the authoritative latest version, retaining the database invariant. This is a code-path risk; no concurrent reproduction was run for this audit.

### 8. Medium: routing replan keys can collide within one second

Routing change outbox keys use the integer update timestamp. Two valid edits of the same routing/reason in one second can share a key, dropping the second automatic replan. BOM recost happens inline, so the immediate BOM cost may still change while the pending plan remains based on the first edit.

Evidence: `api/app/Modules/Production/Services/ProductionRoutingService.php:541`. Recommendation: use an existing immutable audit/change identifier or a transaction write identifier in the dedupe key. This is a code-path risk, not a reproduced race.

### 9. Medium: historical cost and traceability limitations

Manual recost can change an old BOM version's cost snapshot. WO material rows freeze quantities and standard costs, but no explicit BOM version reference was found on WorkOrder. A later reviewer cannot easily distinguish the BOM definition/version used at production start from the current one. Actual material cost/usage variance exists, but does not by itself prove total actual product profit.

Recommendation: freeze BOM version and cost basis when creating/confirming a WO. Keep current BOM estimate separate from production's historical snapshot. Add a straightforward variance view before claiming actual profit.

## Connected module recommendations

| Module | Focused improvement |
| --- | --- |
| BOM | Readiness status, cost completeness, product/item mapping, version and costing timestamps, standard material quantities alongside base-unit quantities. |
| Inventory | Usable stock versus held/reserved stock, locked conversion history, active-BOM dependency links, reliable production valuation. |
| MRP | Same usable-stock policy as production, cost invalidation after conversion edits, planning assumptions and expected receipt dates visible. |
| Production | Frozen BOM/cost source, explicit new manufacturing receipt valuation, missing mapping/cost recovery, standard versus actual material usage. |
| Purchasing | Distinguish supplier quote, landed actual cost, inventory weighted average and standard estimate; normalize all comparisons to base units. Supplier prices and freight do not automatically update BOM standard costs. |
| QC | Show held/accepted/rejected stock against its source lot; keep release decisions reflected consistently in usable stock and planning. |
| CRM/pricing | Compare reviewed manufacturing estimate with base/agreed prices excluding VAT; warn when costs exceed the configured minimum. Do not silently reprice accepted orders. |
| Accounting | Reconcile production inventory valuation and cost of goods sold before presenting actual profit. |

## Is a profit view appropriate?

Yes: add **Estimated gross margin** once the cost basis is usable. Actual profit is a different result and should not be claimed from a BOM alone.

- Selling price: base selling price by default; optionally use the selected customer's server quote for quantity and delivery date.
- Estimated gross profit per unit: selling price excluding VAT minus reviewed unit manufacturing cost.
- Gross margin percentage: estimated gross profit divided by selling price, multiplied by 100. Leave unavailable if selling price is missing or zero.
- Keep markup separate: markup divides gross profit by cost instead of selling price.
- Display cost basis, costed timestamp and incompleteness warnings beside the numbers.
- With material-only costing, label **Material contribution estimate**; missing labor/overhead makes full gross margin unreliable.
- Do not multiply a missing or zero cost into a convincing 100% margin. Display a setup requirement instead.
- Do not automatically set product base/minimum prices from BOM calculations. Finance must review cost completeness and commercial thresholds.

Illustrative reviewed cost 20, selling price 30 excluding VAT: estimated gross profit 10/unit; gross margin 33.33%; markup 50%. Customer VAT does not count as profit.

## UI polish priorities

Put product, BOM version, active status and readiness at the top. Replace uniformly large cost figures with one clear total, supporting material/labor/machine/overhead breakdown, and a separate selling-price/margin panel. Missing costs must say Not costed; zero rates need visible context. Use neutral cost styling rather than green to imply profitability.

Keep components in a bounded table with searchable item selection, clear base-unit conversion and waste impact. Add links to the inventory item, supplier cost source, routing and blocked dependencies. Keep mobile tables scrollable within their container. Preserve historical version labels during edits and recosting.

Recommended implementation order: fix product/item readiness and production valuation; fix cost invalidation and master-data protections; align stock readiness; then add the estimated margin panel and polish BOM detail. This avoids polishing incorrect money data.

## Verification and limits

- 54 existing focused tests / 150 assertions passed for BOM costing, multi-level BOM, MRP netting and material reservation invariants.
- Two additional isolated characterization probes / 10 assertions passed, reproducing stale conversion costing and zero-value new production receipts. Probes live in `/tmp` and were not added as permanent assertions of defective behavior.
- Live reads established core product mapping gaps, missing BOM cost snapshots and cost-basis differences. No live BOM, Inventory, WO or valuation records were changed by the audit.
- Concurrency, routing dedupe and master-data-link concerns are supported by inspected code paths; they were not stress-tested.
- No profit panel or audit fixes were implemented by this audit. The pricing feature and agreement form were completed separately.

## Implementation update

The user approved this audit and its fixes were implemented on 2026-10-04. See [BOM and Inventory safety implementation](bom-inventory-safety.md) for the final behavior, deployment, verification and retained limits. The findings and read-only statement above describe the original audit stage.
