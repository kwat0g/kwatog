# Layout audit screenshots

Screenshots preserve the baseline, Phase 2 after state, and synthetic table stresses. Playwright captures are serial by route and viewport.

- `before/admin/`: baseline Stock Adjustments list at 375, 768, 1024, 1280, 1440, 1920, and 2560px. The baseline create form is under `before/admin/pilot-create/` at 375, 768, 1280, 1440, 1920, and 2560px. Baseline item detail is under `before/inventory-item-detail/` at those same six widths.
- `before/live-admin/`: actual local-stack Stock Adjustments admin data (one row) at 1440px.
- `after/pilot-final/light/` and `after/pilot-final/dark/`: each of the three pilot routes at 375, 768, 1024, 1280, 1440, 1920, and 2560px. The list fixture has 50 rows. Dark create-form captures also include open dropdowns. Reports and summaries contain per-route geometry, typography, button, theme, contrast, and overflow metrics.
- `after/default-compact/light/` and `after/default-compact/dark/`: final Stock Adjustments Compact default at the same seven widths, with 50 rows. The grouped JSON reports record selected density, row heights, aligned edges, overflow, and typography; alternating rows use the active theme's zebra token.
- `before/inventory-core/` and `after/inventory-core/`: Items, UOMs, Warehouse, and Stock Levels/Movements/Reservations at 375, 768, 1024, 1280, 1440, 1920, and 2560px in Light and Dark with 50 rows; `states/` contains 0, 1, 10, 50, and 500-row views at 1440px. UOM and Warehouse 500-row states also cover all seven widths. Expanded Reservations rows cover five widths in both themes. Dialog samples cover item categories, UOM, warehouse, zone, location, and material return at 375px and 1440px in both themes. Each after report includes measurements and strict audit results.
- `after/pilot-final/dark/work-order-links/`: dark Production Work Order detail link-color sample at 375 and 1440px; not a fourth layout migration.
- `before/button-status/` and `after/button-status/`: Production Work Orders “Produce for stock” before/after in Light and Dark at 375px and 1440px. This is the only route captured for the follow-up button/status color pass.
- `states/rows-0`, `rows-1`, `rows-10`, `rows-500`, and `states/dark-rows-*`: Stock Adjustments stress captures at 1440px. The 50-row state appears in each pilot theme report. The 500-row report records rendered row count and scroll metrics; screenshots show top, middle, and bottom table segments.

The before create-form captures do not yet match the full after viewport matrix. Other unrendered routes, roles, open overlays/tabs, print views, floor theme, accessibility states, and performance remain out of scope for this pilot; see `../PROGRESS.md`.
