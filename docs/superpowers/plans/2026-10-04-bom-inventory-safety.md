# Approved BOM and Inventory safety work

The user approved the focused audit in `docs/testing/bom-inventory-audit.md`. Deliver the nine reported connection fixes and polish existing BOM, Product, Inventory and WO detail views.

1. Create missing finished-good identities for active manufactured products, idempotently, without creating stock or changing existing item costs. Keep the existing code convention, validate matching active type and base units, and protect identities after linked use.
2. Add nullable WO BOM/version and product-standard-cost valuation snapshots, plus output receipt unit cost. New WOs freeze their current definition and valuation basis. Legacy WOs freeze current cost only when needed; never guess their historical BOM. New production requires positive valuation. Repair preserves original lot value. Do not rewrite posted receipts.
3. Invalidate active cost estimates after item-cost or conversion changes, including nested BOMs and same-second writes. Reuse scoped replanning. Preserve historical BOM costs and serialize publication using the stable Product and component rows.
4. Warn about missing routing, times and rates. Expose estimate completeness; never claim review or actual profit. Prevent same-second routing replan deduplication collisions.
5. Reuse the existing QC hold ledger for per-bin usable stock. Exclude inactive and quarantine/scrap bins, subtract reservations and holds, and clamp each bin. Align Inventory display, filters and MRP diagnostics.
6. Add estimated margin from base price excluding VAT. Material-only results are material contribution, missing cost data suppresses results. Prices and accepted orders remain unchanged.
7. Verify focused isolated API tests, fresh frontend build, role-based headless browser checks and historical-data preservation. No new dependencies, explicit mapping table, actual-cost accounting project or automatic selling-price changes.

Rollback: revert code and the additive snapshot migration together only after assessing newly created snapshot use. Inventory identities created by the setup seeder remain ordinary master records and are not automatically deleted during rollback. Existing commercial and stock history must be preserved.
