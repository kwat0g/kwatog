# Layout audit: functional and formatting findings

This file separates display/data-correctness findings from visual layout work. Status reflects the Phase 2 pilot implementation and its limited verification.

## F-001 — Business timestamps use browser-local timezone

- **Status:** partially fixed. `formatDateTime` now pins `Asia/Manila`; `formatTime` still uses browser-local time for `Date` and ISO timestamp inputs. Other date-only helpers use date-fns local formatting and need caller-by-caller review before changing semantics.
- **Route:** `/inventory/stock-adjustments`.
- **Evidence:** the baseline browser ran in `UTC`; fixture `2026-10-01T09:14:00+08:00` rendered `Oct 1, 2026 · 1:14 AM`. `formatDateTime` now uses `Intl.DateTimeFormat` with `timeZone: 'Asia/Manila'` and its new test checks equivalent `+08:00` and UTC timestamps. `formatTime` still calls date-fns without a time-zone override for `Date`/ISO inputs; plain `HH:mm` shift-time values use their supplied clock time.
- **Impact:** approval and inventory timestamps can be displayed in a different business day/time zone. This is a display accuracy defect, not a change to stored/API timestamps.
- **Required verification:** add date-only and midnight-boundary cases; inspect all shared formatter callers before changing `formatTime` or date-only helpers. Do not reinterpret pure date/time inputs as instants without checking API semantics.

## F-002 — Compact currency helper does not group scaled amounts

- **Status:** fixed in the shared helper; positive grouping and precision covered by a unit test. Negative values and K/M boundary formatting remain untested.
- **Evidence:** `formatCompactCurrency` now formats scaled values through the shared `Intl.NumberFormat` decimal formatter. Its test checks `PHP 1,234.57K`; callers remain `spa/src/pages/budgeting/detail.tsx`, `spa/src/pages/budgeting/create.tsx`, and budgeting reports.
- **Impact:** scaled values at or above 1,000 may lose thousands separators. The ordinary Stock Adjustments currency path uses `formatPeso` and grouped output correctly.
- **Required verification:** render compact-currency K/M boundary and negative values in budgeting surfaces; preserve existing suffix and rounding conventions.

## F-003 — Product detail can loop when customer-access query is disabled

- **Status:** render loop fixed with a stable empty customer-options fallback. The proposal test had an exact-label mismatch because the required-field marker is included in label text; its query now matches the label correctly.
- **Route:** `/crm/products/:id`, including users with view permission but without customer-access management permission.
- **Evidence:** the full SPA suite spent over an hour in `crm/products/detail.test.tsx` test `keeps pricing, customer access and production setup visible without unauthorized links`. Inspector stack showed the test stuck while React rendered `ProductDetailPage`. The disabled `useQuery` returned `undefined`; destructuring `data = []` created a fresh array each render, and the effect at `ProductDetailPage` used that array in its dependency list while always setting a fresh state array. This caused an unbounded render loop.
- **Change:** replaced the per-render empty literal with a module-scoped empty array. Query enablement, permissions, API calls, and customer data remain unchanged.
- **Verification:** both product-detail tests pass after matching the required label despite its visible `*` marker. A fresh run against clean `main` reproduces the former unbounded render on the unauthorized-links case; the patched fallback prevents it. Full SPA suite on the pilot branch: 198 files passed, 1 failed; 1,237 tests passed, 3 failed. All failures are `src/api/exports.test.ts` because `buildExportUrl` is not exported; they reproduce on clean `main` and are outside this layout task.

## F-004 — Action buttons and status labels lacked semantic surfaces

- **Status:** targeted source-level pass applied; route-wide visual classification remains open.
- **Root cause:** `Button` defaults to the intentionally transparent `secondary` variant, so primary submit/commit actions without an explicit variant looked like text controls. Several status values were rendered as plain text, even where sibling modules used the shared semantic `Chip` palette.
- **Change:** added explicit primary or danger variants to identified commit, approval, and destructive actions across Production, Quality, Maintenance, Inventory, Finance, HR, CRM, Purchasing, and Return Management. Wrapped identified status values in existing themed `Chip` variants across Action Center, Production reports, Purchasing, Driver, HR self-service, Customer orders, Tooling, Accounting, Inventory, CRM, MRP, Quality, and Returns. Shared theme tokens and business behavior remain unchanged.
- **Visual evidence:** Work Orders “Produce for stock” captured before and after in Light and Dark at 375px and 1440px under [`screenshots/before/button-status/`](screenshots/before/button-status/) and [`screenshots/after/button-status/`](screenshots/after/button-status/). It changed from transparent to the existing primary fill in both themes. At 375px the target increased from 32px to 44px and page horizontal overflow remained zero.
- **Limits:** only `/production/work-orders` was browser-rendered for this follow-up. The source review is not proof that every route, native button, modal, status display, or role is visually correct. Secondary and ghost buttons remain transparent by design; don't fill every control.

## F-005 — Table preferences were not user-scoped and density was inert on unkeyed tables

- **Status:** per-user persistence fixed in the shared preference endpoint; compact density and zebra are now shared defaults.
- **Root cause:** `tablePrefsStore` used one browser-wide `localStorage` object. `DataTable` showed a density selector on every table, but only wrote density when a page supplied `tableKey`; unkeyed tables had no state update. `DataTable` did not consume the already-defined zebra color tokens. Sidebar section expansion existed only in component state.
- **Change:** density and visible columns hydrate from and save to each authenticated user's preference record. Unkeyed `DataTable` instances use a route-scoped React ID. Compact is the default (36px office, 44px Floor); Comfortable and Spacious remain selectable. Existing zebra tokens now stripe alternating unselected rows in Light, Dark, and Floor. Sidebar Expand all is stored with the user's preferences. Existing browser-wide preferences are ignored because shared-device values have no reliable owner.
- **Verification:** API preference tests pass 2/2; SPA preference/sidebar tests pass 40/40. Stock Adjustments rendered with 50 rows in Light and Dark at 375, 768, 1024, 1280, 1440, 1920, and 2560px; strict layout checks pass at all 14 route×theme×viewport combinations. Reports confirm Compact selected, aligned page blocks, no page overflow, and no double scroll. Multi-line cells expand rows to 57.25px where needed; screenshots show alternating token-backed rows in Light and Dark.
- **Limits:** only the shared `DataTable` component and tables using shared row-cell helpers inherit Compact defaults. Native/page-authored tables without those shared components remain outside this preference rollout and need table-by-table classification. Floor-specific 44px Compact rows are defined by token but were not browser-rendered. Existing browser-only preferences were not assigned to accounts.
