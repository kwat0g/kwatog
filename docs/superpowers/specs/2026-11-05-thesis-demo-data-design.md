# Thesis demo data design

**Date:** 2026-11-05

## Intent

Prepare a repeatable thesis-demo fixture using the existing Philippines/Cavite, PHP, employee, vendor, item, payroll, and machine defaults. The walkthrough should make payroll deductions, raw-material sourcing, finished-goods prices, and maintenance spare-part availability easy to demonstrate.

## Goals

- Preserve the existing seeded employee population, attendance history, leave, loans, and payroll-engine output.
- Populate fictional base and minimum selling prices for the eight demo finished goods. Label the values as fictional; do not derive them from standard costs or customer agreements.
- Ensure those finished goods also have inventory item identities and opening quantities in the finished-goods zone so they appear in inventory and stock cards.
- Link every seeded raw material to an existing demo vendor through a preferred, approved supplier row with supplier code and lead time. Procurement can use the existing standard-cost estimate when a supplier quote has not been entered.
- Add a small set of spare parts with cost, reorder details, preferred supplier, and on-hand stock in the existing spare-parts zone so a technician can select them on a maintenance work order.
- Add incoming QC plans to existing inventory items that lack effective default coverage; preserve existing plans and create no item records in this QC step.
- Keep the fixture deterministic and safe to run by itself after demo prerequisites exist.

## Non-goals

- Changing payroll calculation, deductions, statutory tables, or the existing attendance pattern generator.
- Rebuilding or truncating existing demo transactions.
- Claiming any synthetic price, supplier term, or spare part reflects OGAMI's actual commercial data.
- Creating an issued-part usage or stock movement outside the existing maintenance workflow.

## Approaches

1. **Edit the shared catalog seeders.** Add prices and suppliers to the existing product, item, and comprehensive seeders. This follows their current ownership, but makes shared master-data seeders responsible for thesis-specific values and still leaves maintenance stock dependent on execution order.
2. **Add a focused, additive thesis demo seeder (selected).** Run it last in the opt-in demo seed path and allow it to be invoked alone after demo setup. It uses stable demo codes and `firstOrCreate`/fill-if-empty behavior, adds only missing demo rows, and does not call the comprehensive transaction seeder. This keeps the scenario easy to repeat without triggering its table truncation.
3. **Rely on the existing dataset only.** This avoids code changes, but finished goods remain without base or minimum prices and no spare-part stock is seeded for maintenance.

## Data choices

- Keep the seeded 2026 Philippines holidays and use dates relative to the seeding date where records need to remain current.
- Use the eight existing finished goods and add explicitly fictional price pairs: WB-001 ₱23.00/₱20.50; WB-002 ₱30.50/₱27.00; PC-001 ₱35.00/₱31.00; PC-002 ₱35.50/₱31.50; RC-001 ₱47.50/₱42.00; BB-001 ₱27.50/₱24.00; BU-001 ₱26.50/₱23.00; RC-002 ₱70.00/₱61.50 (base/minimum).
- Add fallback finished-goods inventory balances in Zone C only when an item has no stock level or movement. The normal full demo seed keeps its existing deterministic stock distribution.
- Use existing demo vendors for raw-material sourcing: Megaplast Industries for ABS resin; Asia Pacific Polymers for PP, PA, and POM resin; Oriental Chemical for colorants; Cavite Precision Tools for metal inserts; and Manila Industrial Packaging for packaging.
- Add two demo spare-part items (hydraulic filter and heater band), with synthetic costs and opening stock in the existing Zone D spare-parts locations. Their supplier links use the existing tooling and industrial vendors.
- Keep existing master values when they are already populated; add the fixture's values only where the demo setup is missing them. Do not manufacture attendance or payroll rows.

## Constraints and risks

- The project pricing design requires business-provided values for real base/floor prices. The user authorized fictional demo prices for this fixture only.
- `ComprehensiveDemoSeeder` truncates transaction tables on execution. The new fixture must not invoke it and must run after the ordinary demo seed prerequisites.
- Stock availability must be tied to a spare-parts-zone stock-level row. Part consumption remains a normal work-order action that creates the real stock movement.

## Builder calls

- Use a dedicated `ThesisDemoSeeder`, registered after existing demo seeders and guarded by the existing opt-in demo-data configuration.
- Run the existing `ManufacturedProductInventorySeeder` before the demo transactions so finished goods receive inventory identities before stock setup; the focused fixture also calls it so standalone use remains complete.
- Use stable item/vendor codes and deterministic quantities, costs, supplier lead times, and product prices.
- Do not run any seeder against the current database as part of implementing this fixture.
