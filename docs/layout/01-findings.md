# Layout findings and spec inputs

Evidence source: [`00-audit.md`](00-audit.md), [`route-inventory.csv`](route-inventory.csv), [`page-issue-matrix.csv`](page-issue-matrix.csv), [`surface-inventory.csv`](surface-inventory.csv), and the before/after screenshots/reports. The ranked table below records Phase 1 baseline findings; pilot outcomes are listed after it. Other routes remain unmeasured.

## Ranked issues

Ranking reflects severity from current evidence. Route traffic analytics were not available, so this is not a traffic-weighted ranking. Only Stock Adjustments received the new route-level render/measurement pass; shared-component exposure is a code-level risk until each consumer is rendered.

| Rank | Finding | Impact | Evidence and root cause | Coverage |
|---:|---|---|---|---|
| 1 | Header/filter edges differ from toolbar/table/pagination by 20px per side | High; users scan separate left/right rails, table touches sidebar | Exact at all seven widths. `main` has no gutter; `PageHeader` and `FilterBar` use `px-5`; table siblings do not. | Confirmed Stock Adjustments; shared components make other list pages high-risk, not yet measured. |
| 2 | Shared sticky header does not stick during document scroll | High for long data lists | `<thead>` declares sticky, but table’s horizontal overflow ancestor constrains it. At seven widths, header moved to about -80px after scroll. | Confirmed Stock Adjustments; shared `DataTable` code. Native tables unmeasured. |
| 3 | Table body/header/secondary text is below proposed minimum | High for long daily-use lists | Pilot computes 11px cells/subtitles and 10px headers. In 50 rows, 703 sampled text nodes are below 13px. | Confirmed Stock Adjustments; shared `DataTable` rule affects its consumers. Dark/floor scan pending. |
| 4 | Business timestamps can display in browser-local time instead of Manila time | High data-trust risk | Shared `formatDateTime` calls date-fns without a time-zone override. In UTC browser, `2026-10-01T09:14:00+08:00` displays as 1:14 AM, eight hours early. | Synthetic Stock Adjustments proof; other formatter callers not rendered. Logged in [`FINDINGS.md`](FINDINGS.md). |
| 5 | Narrow tables hide width behind an inner horizontal scroller without a visible cue | Medium; users may miss right-side columns/actions | Stock table content is 1198px; viewport 375/768/1024/1280 has inner scroll, while page overflow remains false. | Confirmed Stock Adjustments. Page-specific priority columns/card behavior remains unmeasured elsewhere. |
| 6 | Row density and multiline content produce very tall, uneven rows | Medium; scanning and row-to-row comparison slow down | Default office row token is 32px, but long values yield 68.92–124.13px rows at 1440. The table uses dividers but no zebra striping. | 10/50/500 synthetic rows; light theme only. |
| 7 | Short lists leave large blank area; pagination is not held at viewport bottom | Medium; short-list pages look unfinished and controls move by row count | Live one-row list reaches pagination near y=349 in a 900px viewport, leaving about 551px below it. Shell/main is normal document flow. | Confirmed Stock Adjustments. |
| 8 | Main action and small controls have weak visual/touch hierarchy | Medium; action discovery and touch operation suffer | `New adjustment` defaults to outlined secondary at 24px. Density/column/pagination buttons are 28×28px. Icon controls have accessible names/titles, but no visible labels. | Confirmed Stock Adjustments. Other call sites need inventory review. |
| 9 | Table columns have no reusable content-type widths | Medium; long content can crowd other columns or expand rows | At 1440, Reason is 280px; requested date and requester are 85px each. The brief’s wide requester column was not reproduced. No shared min/max policy exists. | Confirmed Stock Adjustments at 7 widths; other tables have static per-column classes or native layout. |
| 10 | Compact currency helper omits grouping; direct precision varies by context | Medium, pending data confirmation | `formatCompactCurrency` uses `.toFixed(2)` without `Intl` grouping. Source scan found 235 `.toFixed()` calls at 0/1/2/3/4/6 or dynamic precision; many are charts/math, not proven defects. | Code scan only; budgeting views not rendered. Logged separately as a formatting candidate. |

## Page × issue matrix

[`page-issue-matrix.csv`](page-issue-matrix.csv) has one row for each of the 360 explicit routes plus the `*` catch-all. Columns cover edge alignment, sidebar clearance, cramped controls, overflow, wasted space, row separation, column sizing, typography, contrast, and double vertical scroll. Stock Adjustments contains measured values. Redirects are marked not applicable as independent visual pages. Every other route is explicitly `unmeasured` with its coverage reason; no inherited component risk is misreported as a rendered failure.

The exact routes, page sources, role gates/roles, tabs, tables, overlays, print markers, and measurement status are in [`route-inventory.csv`](route-inventory.csv). Surface declarations are in [`surface-inventory.csv`](surface-inventory.csv). Role assignment was expanded from the current local DB’s 17 configured roles. Public and portal sessions remain distinct. No full role-by-route walkthrough has been run.

## Shell breakpoints and proposal

Measured/current behavior:

| Width | Current sidebar | Main-content behavior |
|---|---|---|
| <768px | Desktop sidebar hidden; 288px overlay drawer | Main begins at viewport edge; pilot header/filter add 20px; table itself scrolls horizontally. |
| 768–1279px | 56px rail | Pilot header/filter have 20px inset; table has 0px clearance from rail. |
| ≥1280px | 240px expanded by default; user can collapse to 56px | Same 20px vs 0px edge mismatch. |

Proposed gutters for review: 16px below 640px, 24px from 640–1439px, and 32px at 1440px+. Apply the same content-box edges to page header, filter/toolbar, section/card, table, and pagination. Measure clearance from the active sidebar edge so the rule remains identical when sidebar collapses. Keep the current sidebar breakpoints unless the pilot proves a usability issue; do not change collapse behavior as part of a spacing fix.

## Components to reuse or classify

| Area | Current reusable pieces | Finding / next classification |
|---|---|---|
| Header/container | `PageHeader`; no shared page container | Header owns its own inset; list siblings do not. Add one shared shell/container after pilot approval. |
| Search/filter | `FilterBar`, `ArchiveFilter`, `SegmentedControl` | Some pages compose controls locally. Route-level source inventory identifies candidates; render before consolidating. |
| Table/toolbar/footer | `DataTable`, `DataTableToolbar`, `DataTablePagination` | One shared table exists; 109 direct uses. Fix shared root once. 162 native `<table>` tags need classification before migration; many may be statement/entry layouts. |
| Sections/details/forms | `Panel`, `Modal`, `FormActions`, `StickyActionBar`, `SearchableSelect` | Reuse existing components; form/detail grid is mainly route-authored. No shared `FormGrid` or `DetailLayout`. |
| Dialogs/sheets/drawers | `Modal`, `ConfirmDialog`, `ReasonDialog`, `BottomSheet` | 127/153/24/3 direct occurrences. No named Drawer primitive; identify custom right panels in surface inventory before treating them as drawers. |
| Density/settings | `tablePrefsStore`, table-key density and column visibility | Stored by table key in browser localStorage, not scoped by account. No global density default or settings control. |

Existing `docs/DESIGN-SYSTEM.md` documents zebra colors as a table behavior, but current `DataTable` row renderer applies only dividers, hover, selected, and focus classes; zebra tokens are not applied. Keep this mismatch visible for the pilot decision rather than silently changing defaults.

## Page families that need special layout review

- **Dense list/table:** 115 statically classified routes; full width, inner table behavior, filter combinations, long data, and pagination.
- **Forms/actions:** 80 routes; multi-column field groups on wide screens, narrow single-column, sticky actions only where a long form benefits.
- **Detail:** 73 routes; summary/metadata and line-item table combinations, role actions, tabs, and candidate preview drawers.
- **Dashboards:** 22 routes; widget grids and charts; preserve per-role dashboard composition, use fluid columns at wide widths.
- **Reports:** 4 routes plus table/report surfaces classified as `other`; verify print/export and ultra-wide behavior.
- **Settings/admin:** 2 settings-class routes plus admin tools; inspect dense tabs, forms, and dialogs individually.
- **Auth/public and portals:** 13 auth/public routes plus supplier/customer portal routes use separate shells and session rules.
- **Floor/mobile/maintenance:** route-forced floor palette, touch targets, tablet/terminal use, offline/error states, and kiosk-like operations. No separate route named “kiosk” was found; deployment usage needs confirmation.
- **Print:** one frontend `window.print()` marker at Inventory item stock card. Backend PDF exports are outside this frontend print-marker scan.

## Pilot decisions requested

The draft in [`02-spec.md`](02-spec.md) proposes concrete defaults while preserving Atelier colors and font families. Please approve or adjust these before any UI changes:

1. Zebra: default off with optional toggle, or default on for dense lists?
2. Density: Comfortable 48px default, Compact 36px, Spacious 56px, or preserve current 32/28/40px office defaults?
3. Gutters: proposed 16 / 24 / 32px at <640 / 640–1439 / ≥1440px?
4. Table header case: sentence case at 13px, or retain uppercase with increased size/contrast?
5. Mono: reserve for IDs/codes; show dates and ordinary numbers in Public Sans with tabular figures?
6. Main CTA: use existing primary clay button variant for create/commit actions; keep danger red for destructive actions?

## Phase 2 pilot outcome

The user authorized implementation of the spec and three-route pilot by saying “proceed.” The pilot and shared foundation are now implemented; Phase 3 foundation rollout and Phase 4 route migration remain gated on review of the evidence. Final reports cover the three pilot routes in light and dark at 375, 768, 1024, 1280, 1440, 1920, and 2560px, with 50 synthetic rows on the list route. Every route×viewport has zero strict findings. Additional list states cover 0/1/10/500 rows at 1440px in both themes.

| Baseline finding | Pilot status | Remaining scope |
|---|---|---|
| Split page edges and no sidebar clearance | Resolved in all three measured pilot routes. | Other routes and alternate shells need the same measurement. |
| Sticky table header left viewport during document scroll | Fixed and passes pilot scroll check. | Native tables and other `DataTable` compositions remain unmeasured. |
| 10–11px table and supporting text; thin dark body text | Pilot now uses a 14px body/table baseline; dark body weight is 450. | Floor mode and all non-pilot text combinations remain open. |
| Dark native select options stayed white | Resolved by theme-aware `color-scheme` and option colors. | Custom popovers and other form pages need rendered checks. |
| Link-colored IDs were indistinguishable from ordinary text | Shared linked-record components and identified ID links now use link color, hover, and focus treatments. | Five linked Work Order references measured; every link-producing module remains unrendered. |
| Main CTA/button color was ambiguous | Pilot primary actions use the existing primary token in light/dark. Shared secondary/ghost variants keep their intended transparent fill with themed border/text. | Page-specific/native buttons have not been visually audited route by route. A source scan found variantless submit controls; review those during module rollout rather than changing action hierarchy before pilot review. |
| Monetary grouping and decimals | Money displays two decimals; quantities up to three; unit cost four; compact currency now groups. | Remaining formatter call sites, rounding boundaries, and formats on non-pilot pages need review. |

Canonical evidence is in [`screenshots/after/pilot-final/`](screenshots/after/pilot-final/) and [`screenshots/states/`](screenshots/states/). The Work Order linked-record sample is included as a theme/link check, not as a fourth layout migration. Full route/role/tab/modal/drawer/print coverage remains a later phase; do not infer it from the pilot.
