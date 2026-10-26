# OGAMI ERP layout audit

Status: Phase 0 architecture and route inventory completed statically. Phase 1 findings recorded. Phase 2 pilot changes and measurements are complete for three pilot routes; a Production Work Order detail was rendered for the linked-record color request. Full-route runtime coverage remains open.

This pass reuses the existing Atelier system in [`docs/DESIGN-SYSTEM.md`](../DESIGN-SYSTEM.md), [`docs/PATTERNS.md`](../PATTERNS.md), and `spa/src/styles/tokens.css`. It does not introduce a second visual system. The existing `docs/ui-ux/` programme remains active; its latest progress records 16 role sessions, 34 routes, and 162 captures. That corpus is useful background, but it does not cover all current routes or the edge, sticky-header, typography, and table measurements added here.

## A. Layout architecture

### Shells, scrolling, and layers

| Surface | Current code behavior |
|---|---|
| Office app shell | `AppLayout`: `min-h-screen` flex column; sticky 48px `Topbar`; `Sidebar`; `<main class="flex-1 min-w-0">` with no horizontal padding and no fixed-height internal scroll region. The document scrolls vertically. |
| Sidebar | Expanded 240px (`w-60`), collapsed rail 56px (`w-14`); auto-collapses below 1280px during session initialization. Hidden below 768px; menu opens as a 288px overlay. Sidebar navigation has its own `overflow-y-auto`. |
| Floor/mobile shells | `FactoryFloorLayout` and `MaintenanceMobileLayout` use `TouchShell`; forced floor palette, 48px row token, 44px hit token, 15px body token. Separate route shell from office pages. |
| Other shell files | `AuthLayout`, `SelfServiceLayout`, `PortalLayout`, `SupplierPortalLayout`, and `CustomerPortalLayout` have separate chrome and access/session rules. Eight layout files plus `TouchShell`/route variants; not all use the office sidebar. |
| Scroll contexts | Office pages use document scroll. The table wrapper uses horizontal `overflow-x-auto`; it has no fixed vertical height. Browser computes a vertical auto overflow on that wrapper, but it does not produce a second main-content scroll in the measured pilot. Sidebar navigation can scroll independently at 768px and 1024px. |
| Sticky offsets | Office top bar: `top: 0`, `z-40`, height 48px. Desktop sidebar: `top: 48px`, height `calc(100vh - 48px)`. Table header declares `position: sticky; top: 0; z-10`. Modal/mobile drawer uses `z-50`; focused skip link uses `z-[100]`. |
| Scrollbar policy | No `scrollbar-gutter: stable` or global custom scrollbar rule found. Narrow pilot table scrolls horizontally inside its card; no explicit visual scroll affordance appears. |

Source: `spa/src/layouts/AppLayout.tsx`, `spa/src/components/layout/{Topbar,Sidebar,TouchShell,PageHeader}.tsx`, `spa/src/components/ui/{DataTable,Modal}.tsx`, `spa/src/stores/sidebarStore.ts`.

### Page wrappers and spacing expression

There is no shared `PageShell` or `PageContainer` with `fluid`, `grid`, or `narrow` variants. The main wrapper is unpadded. The recurring list-page patterns are:

1. `PageHeader`: `px-5 py-4` (20px horizontal inset).
2. `FilterBar`: `px-5 py-3` (same 20px inset).
3. `DataTableToolbar`, table card, and pagination: full width of their parent, with no matching horizontal inset.
4. Forms/details: route-specific `max-w-*`, `mx-auto`, grid, panel, and padding utilities; no single page-level layout contract.
5. Dashboard, portal, auth, and floor pages: their own shell/card grids.

A static utility scan across `spa/src` found 25 distinct padding values/spellings, 18 margin values, 19 gap values, 20 font-size values/expressions, and 9 line-height variants. The scan includes arbitrary values and `clamp()` classes; it is a source scan, not a complete computed-style census. Examples include `px-5`, `p-4`, `gap-1`, `gap-8`, `max-w-*`, `12vw`, `calc(...)`, and `env(safe-area-inset-*)`. Most spacing lives in Tailwind utility classes. Global CSS is small; CSS modules and CSS-in-JS are not used. A prior repository scan recorded 95 inline `style={{...}}` props, mostly not spacing tokens.

The current token file already owns palette, font roles, radii, shadows, motion, and density values. The existing token-discipline check is documented as clean in `docs/ui-ux/PROGRESS.md`; this audit did not run it. The finding is inconsistent composition around shared components, not absence of a design system.

### Shared components and direct usage counts

Current static JSX scan, production source only (tests excluded):

| Surface | Direct source occurrences |
|---|---:|
| `DataTable` | 109 |
| Native `<table>` | 162 |
| `Modal` | 127 |
| `ConfirmDialog` | 153 |
| `ReasonDialog` | 24 |
| `BottomSheet` | 3 |
| `Tabs` / `TabNavigation` | 11 / 4 |
| Named `Drawer` / `SideDrawer` primitive | 0 |
| Print marker (`window.print`, print CSS, or print hook) | 1 |

Route-source scan finds 189 routes with direct table markers (253 occurrences), 11 with direct tab markers (14 occurrences), and 104 with direct modal/dialog markers (259 occurrences). These are source references, not rendered open-state counts. [`surface-inventory.csv`](surface-inventory.csv) lists each direct declaration with source file and line. [`route-inventory.csv`](route-inventory.csv) records every route, direct table/tab/overlay counts, page source, role gate, and coverage reason. Nested component imports are not recursively expanded into route-to-overlay ownership; custom side panels remain to classify. The only frontend print marker is in the Inventory item stock-card page.

Shared layout components found: `PageHeader`, `FilterBar`, `DataTable`, `DataTableToolbar`, `DataTablePagination`, `Panel`, `Modal`, `ConfirmDialog`, `ReasonDialog`, `BottomSheet`, `Tabs`, `TabNavigation`, `EmptyState`, and `SkeletonTable`. There is no canonical shared `Toolbar`, `PageContainer`, `FormGrid`, or `DetailLayout`.

### Typography and visual tokens

The three font roles are deliberate Atelier choices: Instrument Serif for display titles, Public Sans Variable for interface text, and Spline Sans Mono for code-like/numeric content. The pilot title’s serif treatment is intentional. The mismatch is size and use: the pilot applies mono to an ordinary date, and its table header/body text measures below the mission’s proposed minimum.

Static global CSS provides a 13px office body token and 15px floor body token. Pilot computed sizes are 10px table headers, 11px body/subtitle text, 13px UI text, and a 26px page title. The 10px header is uppercase, tracked sans (not monospace). The screenshot’s secondary status lines are also 11px. Fontsource CSS uses `font-display: swap`; the live fixture renders the peso glyph. Non-Latin glyph coverage was not tested.

### Button colors and sizing

`Button` variants already use semantic tokens; no new palette is proposed.

| Variant | Light | Dark | Floor | Use |
|---|---|---|---|---|
| Primary | `--accent #b4542a`, `--accent-fg #fdfcfa`; hover `#96461f` | `#d97848` / `#17140f`; hover `#e8926a` | `#ff8a4c` / `#0d0b08`; hover `#ffa473` | Main commit/create action |
| Danger | `--danger #a8392f`; foreground `--accent-fg` | `#d9645a`; foreground `#17140f` | `#f87171`; foreground `#0d0b08` | Destructive action |
| Secondary | Transparent, `--border-default #e8e2d8`, primary ink | Transparent, `#332c24` border | Transparent, `#4a4137` border | Reversible/secondary action |
| Ghost | Transparent, primary ink | Transparent, primary ink | Transparent, primary ink | Low-emphasis action |

Token foreground/fill contrast ratios calculate to 4.83:1 / 6.22:1 / 8.42:1 for light primary/danger, 5.89:1 / 5.18:1 dark, and 8.42:1 / 7.11:1 floor. The baseline probe sampled zero text pairs below 4.5:1 on the light pilot. Dark/floor runtime contrast was not scanned at baseline. Pilot `New adjustment` omitted `variant`, so it used the shared `secondary` default despite being the page’s main action; `size="xs"` made it 24px tall. Toolbar density/column controls and pagination buttons were 28×28px. Icon-only toolbar controls had `title` and `aria-label`, but remained visually similar without visible labels. The stock row actions used primary and danger fills.

The baseline computed pilot scan found no sampled AA text failures in light mode. This does not replace dark/floor or full-state contrast review.

## B. Route, role, and surface inventory

Static router scan found 24 feature route files and 360 unique explicit paths, plus the `App.tsx` catch-all (`*`): **361 inventory entries**. The older `docs/ui-ux/00-discovery.md` count of 356 came from a prior source snapshot and is superseded by this scan. `route-inventory.csv` includes route file/line, page component/import, static page type, route guard/permission, role set where resolvable, direct table/tab/dialog/drawer counts, print marker, page source, and capture/defer reason.

| Static page type | Routes |
|---|---:|
| List/table | 115 |
| Form/action | 80 |
| Detail | 73 |
| Dashboard | 22 |
| Report | 4 |
| Settings | 2 |
| Auth/public | 13 |
| Redirect/alias | 13 |
| Other | 38 |
| Catch-all not found | 1 |

Role membership for explicit permission gates was expanded from the current local role-permission database: 17 configured application roles. Portal routes use separate supplier/customer sessions. Public/auth routes use guest/session policies. Route-level permission expansion is not a user walkthrough: route-specific feature flags, row ownership, record IDs, tabs, and modal open states still need runtime verification. Five route declarations use inherited/page-level policies rather than a direct permission gate; their best-known shell/access rule is recorded in the CSV, with runtime behavior still unverified.

## C. Programmatic measurement and rendered coverage

Reusable serial Playwright probe: [`spa/scripts/layout-audit.mjs`](../../spa/scripts/layout-audit.mjs). It emits per-route/per-viewport PNGs, `report.json`, and ranked `summary.md`; it never visits more than one route×viewport at once. It records page-block edges/alignment, sidebar clearance per block, main direct-child bounds/margins/padding/sibling gaps, page and nested overflow, scroll contexts, table row heights/cell padding/column widths and shares/header-cell alignment, sticky declaration and page-scroll behavior, truncation, table semantics, sampled typography/contrast, and button labels/size/colors/targets.

Commands used:

```sh
cd spa
node scripts/layout-audit.mjs --role admin --routes /inventory/stock-adjustments --rows 50
OGAMI_BASE_URL=https://ogamiph.dev LAYOUT_AUDIT_LOCAL_STACK=1 node scripts/layout-audit.mjs --role admin --routes /inventory/stock-adjustments --viewports 1440
node scripts/layout-audit.mjs --role admin --routes /inventory/stock-adjustments --viewports 1440 --rows 0
node scripts/layout-audit.mjs --role admin --routes /inventory/stock-adjustments --viewports 1440 --rows 1
node scripts/layout-audit.mjs --role admin --routes /inventory/stock-adjustments --viewports 1440 --rows 10
node scripts/layout-audit.mjs --role admin --routes /inventory/stock-adjustments --viewports 1440 --rows 500
```

The baseline run measured synthetic 50-row data at **375, 768, 1024, 1280, 1440, 1920, and 2560px**. Separate 1440px runs covered synthetic **0, 1, 10, and 500** rows; the 50-row run is the fourth requested state. The real local stack rendered admin data (one row) at 1440px. Baseline images and JSON live under [`screenshots/before/`](screenshots/before/) and [`screenshots/states/`](screenshots/states/).

**Coverage boundary:** the new geometry/typography probe rendered only `/inventory/stock-adjustments`. The other 359 declared paths and the unmatched-route fallback are in the static inventory, with explicit not-rendered/redirect reasons, but were not rendered by this pass. The older 162-capture UI/UX baseline covers 34 routes and 16 roles; it does not fill the remaining route gaps or provide the new measurements. This audit therefore does not claim whole-project visual coverage or completion of Phase 0C/D runtime checks.

### Stock Adjustments pilot measurements

Synthetic screenshots were inspected at all seven widths; the live one-row screenshot was inspected at 1440px. At 375–1280px, the inner table scrolls horizontally (1198px table content); the page itself has no horizontal overflow. At 1440px and above, all ten columns fit the available main width. At 1920px and 2560px, the table uses the available width; no `max-width` cap is present.

At every measured width with a table, header and filter content edges align at x=260..1420 on the 1440px case, while toolbar/table/pagination edges are x=240..1440. That is exactly **20px wider on both sides**. On desktop widths, sidebar ends at x=240; header/filter clearance is 20px and table/toolbar/pagination clearance is 0px. At 375px, the sidebar is hidden and the content’s 20px inset remains. Header→filter and filter→toolbar gaps are 0px; toolbar→table is 8px; table→pagination is 13.5px.

At 1440px the ten measured column widths are: Requested 85px, Requested by 85px, Direction 80px, Item 153px, Location 74px, Quantity 125px, Value 152px, Reason 280px, Status 83px, Actions 82px. **The brief’s “wide Requested by” example was not reproduced:** that column equals Requested at 85px (7.1% each); Reason is widest at 280px (23.4%). Widths are content-driven, without content-type min/max defaults. Numeric header and cell alignment match and are right-aligned.

| Fixture | Measured result at 1440px |
|---|---|
| 0 rows | Empty state; no table/pagination; page CTA remains in header. |
| 1 synthetic row | 53.53px row; pagination below table. |
| 1 live row | 38.14px row; pagination at y≈321px; about 551px remains below it in a 900px viewport. |
| 10 rows | 68.92–124.13px row range with deliberately long values/names. |
| 50 rows | 68.92–124.13px row range; table height about 5,059px. |
| 500 rows | 68.92–124.13px row range; table body about 49,926px tall; one document scroll context; 500 DOM rows rendered. Performance was not timed. |

One-pixel edge tolerance fails at all seven widths: toolbar, table, and pagination differ from the header edges by 20px per side. The table has 1px row dividers, hover/selected/focus styles in shared code, and no zebra class in its row renderer even though zebra tokens/spec text exist. Row heights expand for wrapped multi-line cells. The default office row-height token is 32px; Compact is 28px; Spacious is 40px. Density/column settings persist by table key in browser `localStorage`, shared across signed-in users on that browser rather than account-scoped. The visible toolbar offers three icon-only density buttons.

Sticky behavior was tested by scrolling the document. The table header declares sticky, but at all seven widths its top moved to approximately -80px and left the viewport. The horizontal-overflow ancestor prevents the header from sticking to the page scroll. The page had no main-content double vertical scrollbar; sidebar navigation scrolled independently at 768px and 1024px.

Computed pilot typography: 10px headers, 11px body/subtitle/table cells, 13px interface labels, and 26px serif title. There were 703 sampled text nodes below 13px in the 50-row fixture; none of the sampled light-theme pairs fell below 4.5:1. Buttons below 44px were counted, but this count mixes desktop pointer controls and touch controls; the responsive touch-target rule needs a viewport-aware pass. Browser timezone was UTC. Fixture timestamp `2026-10-01T09:14:00+08:00` appeared as `1:14 AM`, eight hours earlier than Manila time.

## D. Table-specific code audit

The shared `DataTable` provides row separators, hover/selected/focus styles, a sticky header declaration, density switching, per-table column visibility, sortable buttons when callers provide sorting, `scope="col"`, `aria-sort`, a compact empty state, row selection, and keyboard handling for interactive rows. `DataTableToolbar` provides `Customize columns` and Compact/Default/Spacious icon buttons. Pagination is rendered below the table with 28px controls; it is not sticky. Column-resizing/reordering, first-column pinning, keyboard cell navigation, and server-saved views are not global capabilities. Existing 119-use count in the prior UI/UX notes differs from the current direct source scan (109 `DataTable` tags); this current count excludes tests and aliases/wrappers.

Pilot-specific results: table row border exists; zebra is not applied; header is 10px uppercase; body is 11px; short viewport scroll is contained horizontally but has no visible cue; very long values wrap or truncate by per-cell class, not a shared column policy. Stock Adjustments has no sortable columns, so sort behavior was not exercised. Selection/keyboard actions and open column menus were not screenshot-tested. The empty state is visible at 0 rows; skeleton/error states were not triggered. `aria-sort` and `scope` are present in the shared renderer, but no axe or screen-reader pass was run in this audit.

## E. Number/date formatting scan

The pilot table uses shared helpers: `formatPeso` renders currency to exactly two decimal places with grouping; `formatQuantity` renders 0–3 decimals; `formatDateTime` renders a month/day/year time. The stress value `999999999999.99` rendered grouped as `₱999,999,999,999.99`; quantity `123456789.125` rendered grouped as `123,456,789.125`. This pilot therefore passes the basic money/quantity decimal check.

At baseline, shared numeric helpers in `spa/src/lib/formatNumber.ts` defined: money/decimal exactly 2 places; integers 0; quantities 0–3; percentages 1. The repository contained 235 source `.toFixed()` calls across precisions 0, 1, 2, 3, 4, 6, and a dynamic precision expression; many are chart math or percentages, so this is a locator count, not 235 confirmed defects. At baseline, `formatCompactCurrency` used `.toFixed(2)` without thousands grouping and was used in budgeting surfaces; those pages were not rendered. Date/time helper `formatDateTime` used browser-local time; the UTC synthetic fixture proved an 8-hour display shift in this browser. The Phase 2 formatting changes and remaining limits are documented below and in [`FINDINGS.md`](FINDINGS.md).

## Root-cause summary

1. **Misaligned list edges:** `AppLayout` gives `<main>` no page gutter. `PageHeader` and `FilterBar` independently add `px-5`; `DataTableToolbar`, the table card, and `DataTablePagination` are full-width. Stock Adjustments composes these siblings directly, so the table begins at the sidebar edge while title/filter start 20px in.
2. **Sticky header failure:** `DataTable` puts `overflow-x-auto` on the table’s ancestor and `sticky top-0` on `<thead>`. Computed sticky style is present, but document-scroll probe shows the head moves out of view (~80px above viewport).
3. **Cramped type and controls:** `DataTable` hardcodes `text-xs`; header uses `text-2xs`; current Tailwind scale computes these to 11px and 10px. Toolbar/pagination icons are fixed 28px. No shared density/type tokens raise these by context.
4. **No shared viewport page layout:** normal pages flow in document scroll; table/pagination do not fill or reserve viewport height. A one-row live list ends near y=349 and leaves a large blank canvas; a 500-row stress fixture creates a 50,000px document.
5. **Column sizing:** `Column.className` permits per-column widths, but there are no shared content-type defaults. The pilot has no explicit width classes; browser table layout distributes space by content.
6. **Button hierarchy:** `Button` defaults to `secondary`; the Stock Adjustments page’s primary `New adjustment` call omits a variant and uses 24px `xs` size. This is a page call-site issue, not a token/color-system defect.
7. **Date zone:** `formatDateTime` formats in the browser’s local timezone; the shared formatter does not pin Manila time.

Further route-level findings, ranked issues, and the evidence matrix are in [`01-findings.md`](01-findings.md). Candidate display-accuracy issues are logged separately in [`FINDINGS.md`](FINDINGS.md).

## Phase 2 pilot findings and verification

The approved pilot scope uses three routes: `/inventory/stock-adjustments`, `/inventory/stock-adjustments/create`, and `/inventory/items/:id`. The reusable probe was extended for synthetic item and Work Order fixtures, light/dark themes, dark native-select state, link contrast, and dark body typography. Final evidence is under [`screenshots/after/pilot-final/`](screenshots/after/pilot-final/); the measurements remain limited to these routes and the explicit Work Order link sample.

| Baseline issue | Pilot result | Evidence / boundary |
|---|---|---|
| Header/filter/table/pagination had separate left edges and zero sidebar clearance | Shared `PageShell` now gives pilot blocks the same edge; all 21 route×viewport captures per theme report zero strict findings. | 3 pilot routes × 7 widths × light/dark. Does not prove other routes. |
| Native select options stayed white in dark mode | Root `color-scheme` and native `option` styles follow theme canvas/text tokens. | Dark create-form dropdown captured at 375px and 1440px; options compute to dark surface `rgb(23, 20, 15)` and light text `rgb(245, 241, 234)`. |
| Pilot body/table text was small; dark text looked thin | Common utility sizes rose modestly; default body/table text is 14px, dark regular body weight 450. | Dark report confirms Public Sans Variable, 14px/450/21px. Pilot samples passed the configured minimum-size/contrast checks. Floor theme remains unverified. |
| Stock Adjustments primary action appeared secondary; button color varied by theme | `New adjustment` and `Review adjustment` now explicitly use the shared primary variant. Existing primary/danger fills and foregrounds resolve to theme tokens in light/dark; secondary/ghost remain intentionally outlined/transparent with themed text and borders. | Computed light/dark button colors are in each pilot `report.json`. No palette-token change. App-wide visual classification of page-authored native buttons remains open; this pilot is not proof that every route has been rendered. |
| Clickable record IDs were black | Shared linked-record components and identified code-ID call sites use link tokens, hover underline, and focus treatment. | Work Order detail fixture rendered at 375px and 1440px; all five sampled linked IDs are distinct from body text and pass 5.89:1 dark contrast. Other consumers still need route rendering. |
| Monetary/quantity precision and compact currency grouping | Shared helpers now group scaled compact currency; money uses two decimals, quantity uses up to three, unit cost uses four. `formatDateTime` uses Asia/Manila. | Formatter unit tests added. `formatTime` can still use browser-local time for Date/ISO inputs; see [`FINDINGS.md`](FINDINGS.md). |

The after probe covered **21 route×viewport captures in light and 21 in dark**: each pilot route at 375, 768, 1024, 1280, 1440, 1920, and 2560px. The list fixture used 50 rows at each width; additional 0/1/10/500-row light and dark states were measured at 1440px. All strict pilot reports have zero recorded findings. The 500-row fixture has 500 DOM rows, one list scroll owner, and no page-level horizontal overflow; performance timing was not run.

The dark Work Order link sample is a separate fifth route-state check at 375px and 1440px, not a migrated page. Dark floor palette, every other route, role-specific content, open modal/drawer/tab states, keyboard/axe/print coverage, and app-wide button visuals remain unverified. Static inventory still has 361 entries; this Phase 2 evidence must not be presented as complete project coverage.
