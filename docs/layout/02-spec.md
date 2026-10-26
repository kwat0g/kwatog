# OGAMI layout and table spec — Phase 2 pilot

Status: **Approved for Phase 3.** “Proceed” authorized the Phase 2 spec and three-route pilot; the user approved the pilot and Phase 3 on 2026-10-25. The dark-mode, link-color, button-color, decimal, and modest type-size additions are included. This spec extends the existing Atelier tokens and shared components.

Pilot routes:

1. `/inventory/stock-adjustments` — list/table.
2. `/inventory/stock-adjustments/create` — form/action.
3. `/inventory/items/:id` — detail.

The Production Work Order detail is an additional link-color check, not a fourth layout pilot. It was rendered with synthetic linked records at 375px and 1440px.

The item detail route rendered with a synthetic fixture (`layout-item-001`) through local API mocks.

## 1. Existing identity and token ownership

Keep Atelier’s warm canvas, clay accent, semantic status palette, Instrument Serif display face, Public Sans interface face, Spline Sans Mono for genuine IDs/codes, existing radii, and existing contrast tests. Add spacing/type/density aliases to `spa/src/styles/tokens.css` and Tailwind mapping; do not add page-local values or a competing token file.

### Proposed spacing scale

| Token | Value | Primary use |
|---|---:|---|
| `space-1` | 4px | Icon/text micro-gap, badge interior |
| `space-2` | 8px | Label/control, button icon/label, compact control group |
| `space-3` | 12px | Toolbar group, stacked metadata, compact row vertical padding |
| `space-4` | 16px | Mobile page gutter, form field gap, mobile card/modal padding |
| `space-5` | 20px | Card/section interior padding where 16px is too tight |
| `space-6` | 24px | Tablet/laptop gutter; page header-to-toolbar; section group gap |
| `space-8` | 32px | Wide-screen gutter; major section separation |
| `space-10` | 40px | Large section separation or spacious component grouping |
| `space-12` | 48px | Major page-family separation only |
| `space-16` | 64px | Intentional dashboard/landing composition only |

Rules: use only these spacing values for layout. `0` is allowed where elements are intentionally joined; no arbitrary margin/padding/gap utilities in page code. Preserve safe-area insets as environment values. Use `gap` for sibling layout before adding margins to individual children.

## 2. Shell, gutters, widths, and scroll

### Page gutters and shell offsets

| Viewport | Content gutter on each side |
|---|---:|
| <640px | 16px |
| 640–1439px | 24px |
| ≥1440px | 32px |

Every block in one page—header, toolbar/filters, cards, table, pagination, empty state, and page actions—uses the same content edges. Clearance begins after the active sidebar edge, whether 240px expanded or 56px rail. Below 768px the sidebar overlays content and does not consume layout width; preserve the 16px page gutter beneath it. At 2560px, data pages remain fluid; forms/detail content uses grid columns and controlled prose width.

- Top bar stays 48px high, sticky at `top: 0`, with page content beginning below its 48px block.
- Sidebar remains 240px expanded, 56px collapsed, and 288px mobile overlay unless pilot review identifies a separate defect.
- Data/list/report pages use `fluid`: no arbitrary `max-width` cap.
- Forms/settings use a responsive grid: 1 column below 768px, 2 at 768px, 3 at 1440px, 4 at 1920px+. Keep help/prose at `max-width: 72ch`; group related fields into sections rather than stretching one field across the screen.
- Detail pages use a flexible main column and responsive metadata/summary columns. At 1920px+, allow a 400–520px preview drawer where the workflow benefits.
- Auth and portal pages keep their own shell and readable maximum width; do not force them into the office content grid.
- Factory/floor pages keep a kiosk/touch layout, 44px minimum targets, and floor palette/density.

### Scroll contract

Use one vertical scroll owner per page. For a standard list, the top bar/page title and toolbar stay visible; a flex table region with `min-height: 0` takes remaining viewport height and owns long-list vertical/horizontal scrolling. Pagination sits outside that scroller and remains visible. Short lists keep an intentional empty/short state in the table region instead of leaving a dead void. Main document must not scroll vertically at the same time as the list region. Use `scrollbar-gutter: stable` on the selected scroll owner. Style scrollbars with existing border/surface tokens; preserve a visible horizontal-scroll cue.

For non-list pages, document scroll remains the single vertical owner. Modals and drawers each have one internal body scroller, with body scroll locked while open.

## 3. Vertical rhythm and shared page components

`PageShell` owns the viewport/content contract and one of `fluid`, `grid`, or `narrow` content modes. `PageHeader`, `Toolbar`, `Section`/`Card`, `FormGrid`, `DetailLayout`, `DataTable`, and `TablePagination` consume the same gutter and spacing tokens.

| Relationship / component | Value and rule |
|---|---|
| Page header → toolbar/filter region | 24px |
| Toolbar/filter region → table | 16px |
| Table → pagination | 16px |
| Major section → section | 24px; 32px on spacious dashboard layouts |
| Card/section padding | 20px desktop; 16px mobile |
| Form label → control | 8px |
| Form field → field | 16px |
| Form group → group | 24px |
| Buttons within group | 8px; toolbar groups 12px |
| Tabs | 44px minimum height; consistent baseline and overflow cue |
| Page title | One `h1`; logical `h2`/`h3` below |

PageHeader title, subtitle, and action align in one row when space permits. On narrow screens they wrap in title → subtitle → action order; title remains readable and no action clips.

## 4. Table specification

### Container, states, and density

- Outer table surface uses the existing canvas/surface, default border, and `radius-md`; its outer edge matches page content edges. First/last cell text inset matches page gutter/header text.
- Quiet 1px `--border-subtle` dividers on every row. Hover, selected, and keyboard-focus remain distinct and use current semantic tokens. Zebra is on by default using `--bg-zebra-even`; existing Light, Dark, and Floor token values control the stripe. No zebra toggle is included.
- Compact minimum: 36px office / 44px Floor default; Comfortable minimum: 48px; Spacious minimum: 56px. Rows may grow for permitted multi-line content. The user requested Compact as the default for all tables while keeping Floor rows above the 44px touch minimum. Cell vertical padding: 8px Compact, 12px Comfortable, 16px Spacious; horizontal padding 12px standard, 16px for wide text columns.
- Persist density and visible-column choices per authenticated user and table key through the existing preferences API. Explicit table keys remain stable; unkeyed tables receive an automatic route-scoped key so density always works and persists. A global default in Settings remains out of scope.
- Skeleton rows match selected density. Empty state has a next action where the user can create data; error state has retry; loading overlay reserves the same dimensions.
- Selection, filter, sorting, pagination, and row-action business behavior remain unchanged.

### Header, columns, and responsive behavior

- Header is sticky within the list’s table scroller. The scroller starts below the persistent top bar/toolbar, so the header never sits under the 48px top bar. Check by scrolling the actual viewport, not only computed CSS.
- Header uses readable Public Sans, sentence case, 13px minimum, weight 600, line-height 18px. Sort control has visible direction and `aria-sort`; headers retain `scope="col"`.
- Comfortable table body is 14px Public Sans; Compact may use 13px; Spacious 15px. Numeric/date values use proportional text plus tabular figures; mono stays for IDs/codes only if approved.
- Default widths by content: date 160–180px; person 180–220px; short enum/status 100–140px; number/money 100–140px; reference/code 120–160px. Flexible descriptions absorb remaining width. Each column declares min/max and wrap/truncate policy. A long value cannot expand every other column. Provide resize and reset-to-default in the table system; drag-reorder/saved views are follow-on features.
- Text columns left-align; numbers/money right-align; status stays in a consistent column; actions right-align. Header alignment matches cell alignment. Use `tabular-nums` for numbers and dates. Currency decimals and quantities follow § 7.
- Multi-line cells have defined line heights; secondary text is 12px minimum with 4.5:1 contrast. Truncate with a tooltip when a field is not meant to wrap; wrap names/descriptions only where the column policy allows.
- At 375/768/1024px, no page-level horizontal overflow. Keep priority columns visible, use the table scroller’s edge fade and keyboard-focusable scroll region as the inner-scroll affordance, and pin the first column only where it helps. A phone card layout is a per-table choice, not a forced conversion.
- Footer has adequate touch targets, row range, rows-per-page, and page controls. On long lists it remains outside the scrolling body and visible; do not add a second vertical scrollbar.
- Actions stay visually quiet until hover/focus where safe; approval/destructive actions remain discoverable and labeled.
- Numeric stress check includes 0/1/10/50/500+ rows, long names/codes/reasons, large amounts, and many columns. Do not virtualize until scroll performance is measured; if needed, preserve fixed row metrics and keyboard semantics.

### Semantics and keyboard

Use native `table`, `thead`, `tbody`, and `th scope="col"`. Sort controls are keyboard buttons and expose `aria-sort`. Preserve selection/row actions with visible focus, accessible names, Enter/Space support, and correct event boundaries. Table semantics, row keyboard navigation, truncation tooltips, and density persistence get component tests after the pilot is approved.

## 5. Form, detail, modal, and other surfaces

| Surface | Rule |
|---|---|
| Inputs/selects | 40px desktop, 44px touch; labels 14px; field group gap 16px; focus ring remains visible. |
| Form cards | 20px desktop / 16px mobile padding; 24px between groups; 1–4 responsive columns by viewport. |
| Detail panels | 20px card padding, 24px panel gap; key-value pairs use a consistent two-column label/value grid; prose max 72ch. |
| Modal | Existing size intent retained; proposed widths: small 480px, medium 640px, large 800px, extra-large 960px, capped by viewport; 24px desktop/16px mobile padding; one body scroller; header/footer remain visible. |
| Drawer | 480px standard, 640px wide; 100vw on phones; right-side preview uses remaining ultra-wide space without hiding list context. |
| Pagination and icon buttons | Minimum 44×44px on touch/floor; desktop may remain compact if focus/name/spacing pass. |
| Badge/chip | Existing semantic palette; sentence case; never color alone for status. |
| Tooltip | 12px minimum, visible delay, keyboard/focus access; no essential information only on hover. |
| Empty/error/alert | 16px interior spacing, readable title/body, clear next action or retry. |
| Reduced motion | Preserve existing reduced-motion behavior for shell/density transitions. |

## 6. Typography

| Token | Size / weight / line-height | Letter spacing / usage |
|---|---|---|
| Page title | 28px / 400 / 36px | Instrument Serif; one `h1` |
| Section title | 20px / 600 / 28px | Public Sans; `h2` |
| Subsection | 16px / 600 / 24px | Public Sans; `h3` |
| Body | 14px / 400 / 21px | Public Sans; normal interface prose |
| Body small | 14px / 400 / 20px | Public Sans; supporting copy |
| Caption | 13px / 400 / 18px | Public Sans; secondary metadata, AA contrast |
| Label | 14px / 500 / 18px | Public Sans; sentence case |
| Table header | 14px / 600 / 18px | Public Sans; sentence case, no monospace or tiny all-caps |
| Table cell | 14px Comfortable / 13px Compact / 15px Spacious; 400 / 20px | Public Sans; 13px is dense-table floor |
| Numeric/tabular | Same as table cell / 400 / 20px | Public Sans + `font-variant-numeric: tabular-nums`; right aligned |
| Code/ID | 14px / 500 / 18px | Spline Sans Mono only for genuine IDs/codes/references |

Common utility sizes: `2xs` 12px, `xs` 12px, `sm` 13px, `base` 14px. This raises ordinary text by 1px and small utility text to a 12px floor. Table cells remain 14px Comfortable, 13px Compact, and 15px Spacious. Dark mode sets regular Public Sans body weight to 450 on its variable 100–900 axis; light mode stays 400. This adds a small stroke increase without moving to a bold weight. Body/table text is at least 14px by default and never below 13px in Compact. Secondary text is at least 12px and must pass 4.5:1 on its actual background. Do not transform user-entered names, descriptions, or multilingual text. Preserve `font-display: swap` and existing close metric fallbacks; verify ₱ and non-Latin glyphs with fixtures.

Case: sentence case for buttons, labels, and table headers. Dates are not IDs and should use the proportional font with tabular figures. Keeping mono dates or uppercase table headers requires explicit pilot approval.

## 7. Date, number, currency, and button rules

### Number and decimal policy

- Money: configured functional currency, grouping separators, exactly 2 decimal places. Formatting only; calculations stay in integer centavos or backend decimal strings.
- Quantity: use unit/domain precision, up to the supported 3 decimals in inventory; do not add or drop significant quantity precision. Group large values.
- Whole counts: 0 decimals. Display percentages: 1 decimal by default; use domain-specific precision only when the field requires it. Do not call `.toFixed()` ad hoc in cell renderers.
- Compact currency: keep K/M suffix but still group the scaled number and use exactly 2 decimals. The shared helper now groups the scaled value.
- Business date/time: format explicitly in `Asia/Manila`; test timestamp-with-offset, date-only, and midnight-boundary cases.
- Preserve null/fallback behavior and do not change amounts, calculations, API data, or rounding semantics as part of a layout change.

### Button color and hierarchy

Keep current token colors and contrast-tested semantic mapping:

| Variant | Light | Dark | Floor |
|---|---|---|---|
| Primary | fill `#b4542a`, text `#fdfcfa`, hover `#96461f` | fill `#d97848`, text `#17140f`, hover `#e8926a` | fill `#ff8a4c`, text `#0d0b08`, hover `#ffa473` |
| Danger | fill `#a8392f`, text `#fdfcfa` | fill `#d9645a`, text `#17140f` | fill `#f87171`, text `#0d0b08` |
| Secondary | transparent, border `#e8e2d8` | transparent, border `#332c24` | transparent, border `#4a4137` |
| Ghost | transparent, primary text | transparent, primary text | transparent, primary text |

Primary actions use existing clay `primary`; destructive actions use existing red `danger`; reversible actions use `secondary`; low-emphasis actions use `ghost`. One primary action per surface. Stock Adjustments `New adjustment` and the create form's `Review adjustment` use the existing primary variant. Computed button colors were inspected in light and dark pilot captures; outlined/ghost controls keep their token text and border colors. No button color tokens changed.

Clickable record identifiers use the existing `--text-link`/`--text-link-hover` colors, visible hover underline, and keyboard focus treatment. Do not color a non-clickable identifier as a link. Shared chain-linked records use this treatment across their consuming detail pages.

## 8. Scrollbar, print, dark mode, and viewport validation

- One vertical scroll owner per screen; `scrollbar-gutter: stable` on that owner; no hidden content behind top bar/sidebar; no double main scrollbars.
- Table horizontal scroll stays inside the table card with a visible edge/scrollbar cue and persistent page gutters.
- Native selects/options follow `color-scheme` and canvas/text tokens in Dark; custom listboxes keep their existing theme tokens. A 375px and 1440px dropdown capture confirms dark surfaces and readable option text.
- Dark mode keeps the same warm Atelier palette. Body text uses 450 regular weight, common text utility sizes rise modestly, and all sampled pilot text passes AA.
- Test Light, Dark, and Floor themes; existing semantic tokens must retain AA/AAA contracts and focus rings. Floor mode remains unverified in this pilot.
- Test responsive widths 375, 768, 1024, 1280, 1440, 1920, and 2560; pilot screenshot deliverable uses 375, 768, 1280, 1440, 1920, and 2560. Run one page and one viewport at a time. Include real route data and synthetic 0/1/10/50/500 rows.
- Print the Inventory item stock-card route: correct page width, table columns, repeated header, and page breaks. Backend PDF output is separate.
- Compare before/after screenshots and the JSON geometry report. Require 1px edge alignment, minimum gutter, no page overflow, no double main scroll, row/column/type/contrast checks, and no functional behavior changes.

## 9. Approved pilot defaults and rollout boundary

Defaults applied in the pilot and follow-up: zebra on by user request; Compact 36px default with Comfortable 48px and Spacious 56px available; gutters 16/24/32px at <640/640–1439/≥1440px; sentence-case sans headers; mono reserved for codes/IDs; existing clay primary and red danger button colors retained; dark regular text weight 450; common utility text sizes raised modestly (2xs to 12px); clickable IDs use link tokens. Density, visible-column choices, sidebar width, and sidebar Expand all preference persist per authenticated user. Secondary and ghost buttons intentionally keep transparent fills with theme-aware text and borders; primary and danger buttons use theme-aware fills and foregrounds. Pilot CTA colors were measured in both themes. Page-authored native buttons and submit actions outside the pilot still need route-level review.

The user approved these defaults on 2026-10-25. A source scan across SPA TSX found zero `<Button type="submit">` calls without an explicit variant. Non-submit action hierarchy, native buttons, and route-level appearance still need review during module rollout. Phase 3 foundation work is authorized; Phase 4 page migration remains separate and evidence-led.
