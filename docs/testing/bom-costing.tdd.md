# BOM costing

## Costing contract

An active BOM stores a per-unit cost snapshot with these components:

- material cost from each BOM line's base-UOM quantity and item standard cost;
- subassembly material cost rolled up from the active BOM whose product part number matches the component item code;
- labor, machine, and overhead cost from the finished product's active routing;
- total cost equal to the sum of the four components.

Each BOM line stores its calculated quantity, unit cost, extended cost, and source (`standard_cost` or `bom_rollup`). A zero standard cost is retained as a warning instead of being silently replaced.

Routing cycle time is treated as per-unit time. Setup time is allocated across the BOM's configured cost batch size, so charging setup to every unit is avoided while the assumption remains visible and editable.

## Freshness and safety

Costing is recursive and rejects circular BOM dependencies. Creating, updating, or duplicating an active routing recalculates the product's active BOM snapshot so routing rates do not leave stale conversion costs.

## Verification

The focused feature coverage includes direct material costing, version snapshots, nested subassembly roll-up, routing conversion costs, and routing-triggered recalculation. The isolated API test database passed the MRP feature suite after the full-costing changes.
