# Phase 0 — UI polish audit

**Snapshot:** 2026-10-25. **Scope:** current SPA worktree, static route and component inventory, selected local renders, existing screenshots, token and accessibility gates. This is an audit, not a visual redesign. No UI source, token, Tailwind, or API code was changed for this mission. `DESIGN-SYSTEM.md` received documentation-only corrections where its numeric descriptions contradicted `tokens.css`.

The worktree already contained extensive uncommitted changes when this audit began. Findings describe the files as they exist now; they do not attribute those changes to this mission.

## A. Route and surface inventory

The static router inventory contains **361 route declarations**: 360 explicit paths and the `*` not-found route, across **42 route modules**. `route-inventory.csv` has one row per declaration with module, route pattern, static page pattern, route guard, roles with access where resolvable, direct table/tab/overlay counts, source component, chart count, shared-component usage, and render coverage. Rows are sorted by module, then page pattern. `module-pattern-counts.csv` groups the same routes by module and pattern; `module-surface-counts.csv` summarizes table/chart references by module.

| Static page pattern | Routes |
|---|---:|
| List / table | 115 |
| Form / action | 80 |
| Detail | 73 |
| Dashboard | 22 |
| Report | 4 |
| Settings | 2 |
| Auth / public | 13 |
| Redirect / alias | 13 |
| Other | 38 |
| Error / not found | 1 |
| **Total** | **361** |

The route inventory includes **17 configured application roles** and separate portal/public access notes. Its role set is expanded from route guards and the local role-permission catalog. This is static reachability evidence, not a complete role walkthrough. The existing role screenshot run covered 16 roles and 34 routes; it did not crawl every route as every role.

`surface-inventory.csv` records **598 source-level surfaces**: the prior 595-row scan plus three custom drawer/panel states found during manual review. The source scan records 162 native tables, 109 `DataTable` mounts, 153 `ConfirmDialog` mounts, 127 `Modal` mounts, 24 `ReasonDialog` mounts, 11 `Tabs`, 4 `TabNavigation`, 3 `BottomSheet`, 1 print marker, and one note that no named shared `Drawer`/`SideDrawer` primitive exists. Some confirm dialogs wrap a modal, so counts are component declarations and must not be added as unique on-screen windows.

Across route declarations, 189 have direct table markers (253 markers total), 11 have direct tab markers (14 total), and 104 have direct modal/dialog markers (259 total). The print marker is the Inventory item stock card. Manual drawer review found the app-shell mobile navigation drawer, the conditional unsaved-permission-changes panel on `/admin/roles/:id/permissions`, and the selected-machine detail panel in the production dashboard. The mobile navigation is shell-level, so it does not repeat in every route row. All of these are inventoried by source; their open states were not all rendered.

The scan found 19 direct chart-component instances across 17 route declarations. Recharts 2.15.4 is the chart library. Nested dashboard widgets and chart calls inside imported children are not recursively attributed to routes, so this direct count is a lower bound. Component usage in the route CSV uses direct JSX references to the shared-component list; it does not expand the full import tree.

The full module and route breakdowns are in [route-inventory.csv](route-inventory.csv), [module-pattern-counts.csv](module-pattern-counts.csv), and [module-surface-counts.csv](module-surface-counts.csv). All table, tab, modal, drawer, and print declarations are in [surface-inventory.csv](surface-inventory.csv).

## B. Evidence for the four visual problems

### Flat hierarchy

- The Employees list places six same-sized status cards between filters and the table. In the 1440px local capture, the status band is 270px tall; it spans y=194–464 at a 900px viewport. The table toolbar begins below it. The cards share headline emphasis and most of the same framing, so the data table’s purpose loses the first visual contest.
- The current fixture shows 202 Active and five zero counts. Each card repeats “all matching employees.” This is **5 of 6** low-information zero tiles, not the four-zero case in the original brief. The local screenshot uses eight visible synthetic rows and a 202 aggregate; the historical HR baseline screenshot also shows 202 Active and five zeros.
- One mostly-zero KPI grid is confirmed in the current rendered baseline: Employees, with five zeros of six cards. The application-wide total is unknown; other role dashboards were not crawled with live role data.
- The Employee table gives all six columns similar treatment. Name is rendered as a two-line cell, but no employee avatar or distinct key-column treatment anchors the rows. Status is the only consistently coloured field.
- The current screenshot’s table starts after the 270px status band; all status tiles, filters, and table controls compete for primary position. The PageHeader does have a clear clay primary action when the caller has create permission.
- The page adds a bordered card grid and a separate full-width table region. Other patterns also vary between boxed panels and unboxed sections; the route inventory enables this to be reviewed module by module.

### Text-heavy surfaces

- The Employees list is mostly labels and text values: employee number, name/position, department, pay type, hired date, and status. Current page-source scan finds one direct `Avatar` JSX use across route sources; it is not in the Employee list. Department is plain text rather than a grouping tag. The status chip is a useful state cue, but its small footprint is easy to miss beside long text rows.
- Shared chart series and progress components exist, but their direct usage is sparse: 19 chart-component calls across 17 route declarations, six direct `ProgressBar` calls, and two direct `StageBreakdown` calls across unique page sources. Dashboard and module-level nested children can raise the rendered counts beyond this source scan.
- Numbers are not consistently paired with trend, target, or comparison context. `StatCard` supports deltas and polarity, and dashboard `SparkLine` exists, but the Employees status cards have neither.

### Muted colour

- Atelier’s clay accent and five semantic roles exist and are used. A raw source scan of `spa/src` found 577 accent, 293 success, 484 warning, 701 danger, 90 info, 4 purple, and 23 chart-role class/variable references. These are code references, not rendered element counts; they include shared components and styles.
- The Employee list uses soft success/warning/danger tints in the status-card grid and green status chips on every visible row. Those hues carry status meaning. There is no dedicated categorical-tag component or category token set, so departments remain uncoloured plain text. The neutral Avatar background also does not add category identity.
- The eight-token `--chart-1..8` palette already exists across all three palettes and is checked for contrast and red-green colour-vision separation. A second chart series palette is not justified by the current source.
- In the saved 2026-10-13 HR baseline screenshot, the topbar brand truncates to “Ogami Manufacturing Co…”. The local mock capture shows “OGAMI ERP” because that fixture uses a shorter legal name. The HR sidebar labels fit at 1440px in the reviewed screenshot; narrower sidebar label behavior was not independently verified. Badge count meaning is owned by the separate badge effort and is outside this audit’s visual scope.

### Static interaction and feedback

- Static source scan found 639 `hover:` utilities, 330 focus utilities, 34 active utilities, and 33 disabled utilities. This confirms many controls already have feedback; it does not prove state coverage for every route or component. The shared `Button`, `Input`, `Chip`, and `DataTable` include focus/hover/pressed/disabled or selected states. The Employee table has sortable headers, keyboard-focusable rows, row selection, sticky-header markup, and loading/error/empty states.
- The shared table has no hover-revealed action area. A keyboard-accessible row context menu is implemented, but only one direct `rowContextMenu` call site was found. It is not a substitute for a discoverable row action affordance across list pages.
- Table-cue source scan: zero direct Avatar cells in the Employees table, one direct Avatar JSX use across unique route sources, no category `Tag` component, six direct `ProgressBar` uses, two direct `StageBreakdown` uses, one `rowContextMenu` call site, and no shared hover-action reveal. These counts do not identify every hand-built action column inside native tables.
- Skeletons, retry states, mutation toasts, and optimistic updates exist. There are 146 direct `SkeletonTable` JSX uses across unique route sources, 828 page-source toast call references, and three explicit `onMutate` callbacks across `spa/src`; these are source counts, not proof of consistent coverage. `useOptimisticStatusAction` provides a shared optimistic status pattern with rollback.
- The current table toolbar uses a native density `<select>` and a separate Columns popover button. See [DataTableToolbar.tsx](../../spa/src/components/ui/DataTableToolbar.tsx). Filter controls on Employees show “All types” and “Pay type: All”; the “1 active filter” indicator is monospace. The dense toolbar’s interaction affordances are inconsistent with the requested compact, keyboard-friendly filter vocabulary.
- Source scan found 28 `animate-pulse` references in 16 TSX files and 25 `animate-spin` references in 17 files. Reduced-motion CSS exists globally and in map styles. Pulse is not on the system’s allowed animation list; individual animated surfaces still need review against the allowed list and reduced-motion behavior.

## C. Employees known-issue verification

| Brief issue | Finding | Evidence |
|---|---|---|
| Six large KPI cards; most values zero; repeated helper copy | Confirmed. Six `STATUS_TILES`, all with “all matching employees”; current fixture has five zeros. The 270px strip precedes the table. | [employees/index.tsx](../../spa/src/pages/hr/employees/index.tsx#L43), local screenshots in `screenshots/before/employees-list/` |
| Inconsistent filters; monospace active-filter count | Confirmed. Filter options say “All types” and “All”; the shared toolbar adds field labels, resulting in “Pay type: All.” The screenshot renders the active-filter count in monospace. | `employees/index.tsx` lines 228–260; local light/dark 1440 captures |
| Density is native select; Columns is a lone button | Confirmed. `DataTableToolbar` renders a native select and a separate Columns button that opens a menu. | [DataTableToolbar.tsx](../../spa/src/components/ui/DataTableToolbar.tsx#L76) |
| Sidebar labels truncated | Not confirmed at 1440px: labels fit in the reviewed HR screenshot. Narrow desktop widths and collapsed-rail labels need a separate runtime check. | Historical 1440px HR screenshot and current local capture |
| Brand name truncated in topbar | Confirmed in the historical HR screenshot, where the longer company name is ellipsized. Not reproduced by the local mock’s short “OGAMI ERP” name. | `screenshots/before` historical baseline is indexed in [screenshots/README.md](screenshots/README.md); original at `docs/ui-ux/screenshots/baseline-roles/hr/hr-employees-1440.png` |
| Rows lack avatars/visual anchors; status chips are low-emphasis | Confirmed. Employee columns render no avatar and department is plain text; repeated Active chips provide the only row-level colour anchor. | `employees/index.tsx` lines 184–225; reviewed local capture |
| Sidebar badge numbers have unclear meaning | Visible, but badge semantics are owned by the separate notifications/badges effort. Visual style is in scope later; meaning is excluded here. | Sidebar screenshot; see mission scope |

## D. Quantified baseline and visual coverage

### Source and render measurements

| Measure | Baseline |
|---|---|
| Distinct Tailwind text-size utilities found in TSX | 17 named utilities |
| Font-weight utilities found in TSX | 4 (`light`, `normal`, `medium`, `semibold`) |
| Literal text-size/weight class pairings found | 21; source scan, not a computed-style census |
| Employee list default row token | 48px office; current local two-line fixture rows measure 58.75px |
| Employee list table header | 48px; six scoped column headers; three sortable headers |
| Employee list at 1280/1440/1920 | Light and dark captured at all three widths; no horizontal page overflow; eight synthetic visible rows |
| Runtime text contrast sample | 13 valid captures across light (6), dark (6), and floor (1) had no sampled text below 4.5:1. Floor AAA was not checked by this probe; this is not an axe scan. |
| Floor screenshot | One route-forced `/factory` capture at 768px; applied theme is `floor`; one synthetic active order |
| Existing role-baseline screenshot corpus | 162 PNGs, 16 roles, 34 routes; light palette only |
| Existing layout screenshot corpus | 220 PNGs, 79 dark; selected list/form/detail routes; no floor captures |
| Current task screenshots | 13 valid captures in `screenshots/before/`; no after screenshots |

Current captures also include light/dark 1440px views of a role dashboard, work-order detail, and stock-adjustment form. The dashboard fixture has no configured widgets and shows the real empty-dashboard state. A second synthetic work-order fixture populated four process stages so ChainHeader, LinkedRecords, and Activity are all visible. The form screenshot includes a draft-restore banner. The stock-card/print route could not be rendered with the available local fixture and is excluded as a valid screenshot. Report, auth, settings, open modal/drawer, and broad floor-PWA states remain unrendered.

Current task screenshots were made with a temporary patched copy of the existing serial layout-audit script. The user-owned script was not edited. It used local mock data, one route and one viewport per browser run. The failed Employee render attempts were discarded and are recorded in the screenshot README. Screenshot index and provenance: [screenshots/README.md](screenshots/README.md).

### Contrast, axe, token, and performance gates

- `npx vitest run src/styles/__tests__/palette-contrast.test.ts`: **132 tests passed** across all token palettes and chart-palette checks.
- `npm run audit:tokens`: **fails one violation**, `spa/src/components/crm/ProductPhoto.tsx:31` uses `text-white`. No fix was made because UI source changes are gated on pilot approval.
- `npm run test:a11y`: **not runnable in this worktree**. Playwright reports that `@axe-core/playwright` is invalid/missing its package entry; npm reports the dependency as invalid. A no-save repair attempt failed with `EACCES` while renaming `spa/node_modules/.bin/acorn`. The older `docs/ui-ux/PROGRESS.md` axe pass is historical and covered selected surfaces only; it is not current Phase 0 evidence.
- The contrast test validates token pairs, not every rendered text pair on every route. The local screenshot probe sampled employee text at AA and found no <4.5:1 samples; floor runtime AAA was not measured by that probe.
- Lighthouse and CLS were not measured. Heavy-route performance remains unquantified.

### Visual and accessibility limitations

Only the Employees list, one generic empty dashboard, one production detail, one inventory form, and one floor work-order route were rendered from the current worktree. Screenshots were visually inspected for those representatives. The other 356 route declarations and conditional states were inventoried statically but not all rendered or visually reviewed. Role crawl coverage, axe, keyboard-only walkthroughs, screen-reader checks, report/print output, and all open dialogs/drawers remain incomplete. This audit does not claim whole-project visual or accessibility coverage.

## E. Foundations check

### Source-of-truth tokens

`spa/src/styles/tokens.css` currently defines three palettes and the existing `--chart-1..8` series palette. It has no avatar tint set and no distinct category-tag token set. The contrast fixture reads the token file and passed all 132 tests. The chart palette already includes contrast and deuteranopia/protanopia difference checks, plus a label requirement.

There was a numeric conflict: the mission describes density as 13px body text and 32px office rows, while the current token source defines 14px office body text, 48px default rows, 36px compact rows, 56px spacious rows, and a 28px office minimum hit target. Floor sets 15px body text, 48px rows, and 44px hit targets. As instructed, `tokens.css` was treated as truth and `DESIGN-SYSTEM.md` was corrected; the token values were not changed. The stale explanatory density comment in `tailwind.config.ts` still says 32px/28px office values and should be corrected with the related foundation work.

### Shared components and libraries

| Component / library | Current state |
|---|---|
| `Button`, `Input`, `Chip`, `DataTable`, `Panel`, `StatCard` | Shared primitives exist; current implementation has token-backed palette roles and several interaction states. |
| `Avatar` | Exists with initials and photo support; only neutral `bg-elevated` tint, no deterministic tint set. Employee list does not use it. |
| `StatusDot`, category `Tag`, `AvatarGroup`, `InlineBar`, `Delta`, `StageDots`, preview `SidePanel` | No shared implementation found. |
| `ProgressBar`, `StageBreakdown`, `SparkLine` | Existing pieces; `StageBreakdown` has both `ui` and `chain` implementations with different layout/styling. |
| `ChainHeader`, `LinkedRecords`, `ActivityStream` | Implementations exist under both `components/ui/` and `components/chain/`; caller ownership and visual drift need consolidation analysis. |
| `FilterBar`, `SegmentedControl`, `SearchableSelect`, `Tooltip`, `Modal`, `BottomSheet`, toast, empty/error/skeleton components | Existing shared pieces, with uneven route adoption. |
| Icons | `@tabler/icons-react` through `spa/src/lib/icons.ts`; semantic icon aliases and compatibility names are exported there. |
| Charts | Recharts 2.15.4; shared wrappers include DonutBreakdown, BarComparison, AreaTrend, SparkLine, OeeGaugeChart, and DowntimeParetoChart. |

Direct shared-component JSX occurrences across 320 unique route source files include: `PageHeader` 322, `DataTable` 102, `FilterBar` 74, `Panel` 566, `StatCard` 214, `Chip` 512, `Avatar` 1, `ChainHeader` 17, `LinkedRecords` 10, `ActivityStream` 8, `Modal` 84, `ConfirmDialog` 135, `ReasonDialog` 22, `BottomSheet` 1, `Tabs` 11, `TabNavigation` 1, `SegmentedControl` 15, `SearchableSelect` 12, `Select` 214, `Input` 432, `Textarea` 107, `Button` 1,383, `SkeletonTable` 146, `EmptyState` 466, `ListEmptyState` 35, `ProgressBar` 6, and `StageBreakdown` 2. Counts are direct JSX tags in route sources; shared shell and nested child usage can be absent or repeated elsewhere.

### Motion and route themes

Allowed motion in the current design-system document is button press, dropdown/modal transitions, skeleton shimmer, progress fill, status-color transition, and toast slide-in. The mission has approved row-action reveal, sliding tab indicator, and slide-in preview panels for later phases. The current source contains pulse/spin uses that require a per-surface audit. Global `prefers-reduced-motion` CSS collapses animation duration and iteration count while preserving state transitions; no global transition removal was found.

`TouchShell` forces the floor palette for floor routes and restores the user preference when it unmounts. The driver route remains an office-shell surface with touch-sized controls. The floor screenshot in this audit is a route-forced factory work-order screen; no dense office list patterns were applied to that PWA.

## Independent design review

Impeccable is installed and was used for a design critique and `detect --json` on the Employees list. The detector returned zero findings; this does not imply a clean visual review. The independent heuristic critique scored the Employee page 26/40 on Nielsen’s heuristics (“Acceptable”) and found that the six status tiles make a structurally sound page read like a generic admin list. A second reviewer checked source and capture evidence but could not inspect a valid live render through the original fixture.

The critique also found three functional/accessibility issues, recorded separately in [FINDINGS.md](FINDINGS.md): status-card drilldowns drop active filters; filtered zero-result states can show first-run copy; and all row checkboxes share the same “Select row” accessible name. The Anthropic `frontend-design` skill is not installed in the available skill catalog; the same audit/normalize/harden/clarify/distill/adapt/optimize/polish disciplines were applied manually.

## Phase 0 status

Static route, module, role, shared-component, and surface inventories are drafted and complete for the current source snapshot. Selected current-worktree screenshots and gate results are saved. Full route-by-role rendering, all-pattern/all-palette visual review, axe, print, Lighthouse/CLS, and conditional overlay-state coverage remain incomplete. Phase 1 and UI implementation have not started.
