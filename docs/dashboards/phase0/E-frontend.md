# Phase 0 — Section E: Frontend Foundation (as-built)

Read-only audit of `spa/` primary sources on branch `docs/dss-discovery-phase0`.
Every claim cites a path (`:line` where useful). No code was changed.

Scope: `spa/package.json`, `spa/src/styles/tokens.css`, `spa/tailwind.config.ts`,
`spa/src/components/charts/*`, `spa/src/components/dashboard/*`, table/UI
primitives, routing + guards, `AppLayout`/`Sidebar`, API clients, Hook Form + Zod
patterns, theme/dark/floor, i18n, toasts, `spa/e2e`, token-audit script.

---

## 1. Library inventory (installed versions verified via `node_modules/*/package.json`)

Package ranges live in `spa/package.json:25-53` (prod) and `:54-83` (dev).
Installed versions below were read from `spa/node_modules/<pkg>/package.json`
on the audit machine, so they are exact, not `^` ranges.

### Production dependencies — 27 total

| Category | Package | Range (`package.json`) | Installed |
|---|---|---|---|
| Charts | `recharts` | `^2.15.4` | **2.15.4** |
| Data fetching | `@tanstack/react-query` | `^5.59.0` | **5.100.8** |
| Tables | `@tanstack/react-table` | `^8.20.5` | **8.21.3** |
| Forms | `react-hook-form` | `^7.53.0` | **7.75.0** |
| Forms | `@hookform/resolvers` | `^3.9.0` | **3.10.0** |
| Forms | `zod` | `^3.23.8` | **3.25.76** |
| Router | `react-router-dom` | `7.18.2` (pinned) | **7.18.2** |
| Core | `react` / `react-dom` | `^18.3.1` | **18.3.1** |
| HTTP | `axios` | `^1.18.0` | **1.20.0** |
| State | `zustand` | `^5.0.0-rc.2` | **5.0.12** |
| Toasts | `react-hot-toast` | `^2.4.1` | **2.6.0** |
| Icons | `@tabler/icons-react` | `^3.46.0` | **3.46.0** |
| Dates | `date-fns` | `^4.1.0` | **4.1.0** |
| Maps | `leaflet` / `react-leaflet` / `@types/leaflet` | `^1.9.4` / `^4.2.1` | **1.9.4** / **4.2.1** |
| Realtime | `laravel-echo` / `pusher-js` | `^1.16.1` / `^8.4.0` | **1.19.0** / **8.5.0** |
| Media | `qrcode` (+ `@types/qrcode`) | `1.5.4` (pinned) | **1.5.4** |
| Landing-only | `three` (+ `@types/three`) | `^0.184.0` | **0.184.0** |
| Landing-only | `gsap` / `@gsap/react` / `lenis` | `^3.12.7` / `^2.1.2` / `^1.3.23` | **3.15.0** / **2.1.2** / **1.3.23** |
| Fonts | `@fontsource/instrument-serif`, `@fontsource-variable/public-sans`, `@fontsource-variable/spline-sans-mono` | `^5.3.0` | **5.3.0** ×3 |
| Util | `clsx` | `^2.1.1` | **2.1.1** |

Notable **absences** (no chart/table/export/i18n beyond the above): no
`d3`/`echarts`/`chart.js`, no `ag-grid`, no `react-i18next`/`lingui`,
no `jspdf`/`html2canvas`/`file-saver`, no `react-grid-layout`/`dnd-kit`,
no headless-UI/Radix/MUI. Confirmed by reading `spa/package.json:25-53`
in full — the list above is exhaustive.

### Hand-rolled vs library

- **Hand-rolled:** all chart widgets are thin `recharts` wrappers
  (`spa/src/components/charts/*` — `AreaTrend.tsx:1`, `BarComparison.tsx:1`,
  `DonutBreakdown.tsx:1`, `SparkLine.tsx:1-2`, `OeeGaugeChart.tsx:1`,
  `DowntimeParetoChart.tsx:1-10`); gauge/breakdown/table tiles are custom
  div/CSS (`registry.tsx:53-74` `RichGauge` uses `ProgressBar`, not a chart
  lib); `DataTable.tsx:1-21` composes `@tanstack/react-table`-adjacent
  hand state (selection, expansion, pinned columns, keyboard nav
  `DataTable.tsx:333-386`) rather than delegating to a grid vendor;
  dashboard grid is CSS grid (`DashboardGrid.tsx:20-22`), notifications are
  `react-hot-toast` with a custom renderer (`main.tsx:29-110`).
- **Library:** data fetching (`@tanstack/react-query` + shared
  `src/lib/queryClient.ts:11-23`), validation (`zod` + `zodResolver`,
  100+ call sites — e.g. `src/pages/auth/login.tsx:125`,
  `src/pages/crm/sales-orders/create.tsx:102`), routing
  (`react-router-dom` v7, `src/App.tsx:1-7`), icons via a single boundary
  (`src/lib/icons.ts:1-9`, Tabler only), toasts (`react-hot-toast`).
- **Bundle discipline is structural:** `spa/vite.config.ts:39-84`
  `manualChunks` splits `vendor-react`, `vendor-query`, `vendor-forms`,
  `vendor-charts` (recharts), `vendor-three` / `vendor-motion` (landing-only,
  lazy `/` route), `vendor-realtime`. `chunkSizeWarningLimit: 600`
  (`vite.config.ts:35`). Any new dep should get a chunk entry here.

---

## 2. Dashboard framework as-built

### 2a. Reusable widget/component count — 30 (6 chart primitives + 24 dashboard-level)

Chart primitives (`spa/src/components/charts/`, barrel `index.ts:1-4`
re-exports 4 of 6 — `OeeGaugeChart` and `DowntimeParetoChart` are imported
directly, not via the barrel):

| # | Component | File | Props contract |
|---|---|---|---|
| 1 | `AreaTrend` | `charts/AreaTrend.tsx:3-23` | `{ data, dataKey='value', forecastKey?, xKey='period', color='var(--accent)', height=200, unit, formatValue? }` — actual + optional dashed forecast series |
| 2 | `BarComparison` | `charts/BarComparison.tsx:9-16` | `{ data, bars: {dataKey,color,label}[], xKey='label', height=200, stacked?, formatValue? }` |
| 3 | `DonutBreakdown` | `charts/DonutBreakdown.tsx:9-15` | `{ data: {name,value,color}[], height=180, innerRadius=50, centerLabel?, centerValue? }` — legend is hand-rendered below (`:58-66`) |
| 4 | `SparkLine` | `charts/SparkLine.tsx:3-8` | `{ data: number[], color='var(--accent)', height=24, width=80 }` — axis-free `LineChart` |
| 5 | `OeeGaugeChart` | `charts/OeeGaugeChart.tsx:3-9` | `{ oee, availability, performance, quality (0–1\|null), displayPolicy?: {world_class_ratio, on_track_ratio} }` — half-donut `RadialBarChart` + A×P×Q split |
| 6 | `DowntimeParetoChart` | `charts/DowntimeParetoChart.tsx:18-29,110-124` | `{ data: ParetoEntry[] ({category,minutes,cumulative_pct}), height=260, valueLabel? }` + exported `buildParetoData()` sorter; `valueLabel` re-targets it to defect counts without relabelling |

Dashboard-level reusables (`spa/src/components/dashboard/` + shared UI):

| # | Component | File | Props contract |
|---|---|---|---|
| 7 | `DashboardShell` (+ `KpiGrid`, `PanelRow`, `DashboardBody`) | `dashboard/DashboardShell.tsx:46-91,163-264` | `{ title, subtitle?, actions?, header? (KpiStrip slot), query: {data,isLoading,isError,refetch}, kpiCount=4, refreshingQueryKey?, updatedAt?, staleAfterMs=WIDGET_STALE_AFTER_MS, isEmpty?, emptyTitle?, emptyDescription?, children(data) }` — owns loading/error/empty/stale branches; header renders in every state (`:207-209`) |
| 8 | `DashboardGrid` / `DashboardGridItem` | `dashboard/DashboardGrid.tsx:20-54` | Grid: 12-col CSS; Item `{ width 1-12→lg:col-span, height (×24px min-height), onRetry?, resetKeys? }` — per-cell `WidgetErrorBoundary` (`:51`) |
| 9 | `LiveDashboardWidget` | `dashboard/registry.tsx:232-297` | `{ widget: DashboardLayoutItem, summary?: DashboardWidgetSummary, loading, onRetry? }` — rich (`breakdown/trend/table/gauge`) → scalar → `EmptyState` fallback chain (`:266-280`) + freshness footer with words-only `Stale` Chip (`:283-293`) |
| 10 | `WidgetBreakdown` | `dashboard/WidgetBreakdown.tsx:31` | `WidgetBreakdownData { total, segments[{label,value,tone}], kind? }` — bars, not donut, by documented decision (`:5-12`) |
| 11 | `WidgetTable` | `dashboard/WidgetTable.tsx:12` | `WidgetTableData { columns[{key,label,numeric?,align?}], rows, total_count, sla_hours? }` — deliberately NOT `DataTable` (`:5-11`); truncation footnoted (`:59-63`) |
| 12 | `ApprovalsPendingTable` (internal) | `dashboard/registry.tsx:139-230` | `{ data: WidgetTableData }` — top-8 queue w/ type filter, delegation badges, overdue math, row-link navigation |
| 13 | `KpiStrip` | `dashboard/KpiStrip.tsx:66-75` | `{ codes: string[], linkByCode?, compact? }` — scorecard + 6-pt `trends` sparkline (compact only, `:92-102`); permission-stripped codes render a status line, never crash (`:131-137`) |
| 14 | `DashboardPicker` | `dashboard/DashboardPicker.tsx:105` | `{ layout, layoutVersion }` — add/remove/reorder modal; `pack()` auto-flows x/y (`:56-79`); 409-aware error copy (`:126-131`) |
| 15 | `RoleDashboard` (legacy) | `dashboard/RoleDashboard.tsx:33-78` | `{ role: 'plantManager'\|'hr'\|'ppc'\|'accounting' }` — fixed 4-KPI + `Panel` grid; superseded by bespoke `src/pages/dashboard/*.tsx` but still mounted |
| 16 | `ChainBottleneckWidget` | `dashboard/ChainBottleneckWidget.tsx:21-37` | `{ audience?, title?, hideWhenEmpty? }` — `GET /chain/bottlenecks`, 60s poll |
| 17 | `ForecastPanel` | `dashboard/ForecastPanel.tsx:23-44` | `{ data?, isLoading, isError, onRetry?, title?, formatValue?, unitLabel?, hideWhenEmpty?, showKpi?, detailsHref? }` — manual-refresh only (see §2e) |
| 18 | `DemandForecastPanel` | `dashboard/DemandForecastPanel.tsx` | same shell chrome as 17 (skeleton/retry/empty + link-out) |
| 19 | `ForecastAccuracyPanel` | `dashboard/ForecastAccuracyPanel.tsx` | same shell chrome as 17 |
| 20 | `CapacityPanel` | `dashboard/CapacityPanel.tsx:23-51` | `{ data: CapacityForecastData?, isLoading, isError, onRetry?, title?, detailsHref? }` — utilisation bars + overload chip |
| 21 | `CashFlowPanel` | `dashboard/CashFlowPanel.tsx` (+`.test.tsx`) | same shell chrome (skeleton/retry/empty, confidence, Open link, no inputs — per `CapacityPanel.tsx:1-9` doc) |
| 22 | `AttendanceRiskPanel` | `dashboard/AttendanceRiskPanel.tsx` | panel w/ risk rows (mirrors 17–21 chrome) |
| 23 | `StockOutPanel` | `dashboard/StockOutPanel.tsx` | stock-out list panel |
| 24 | `FinanceSection` | `dashboard/FinanceSection.tsx` | legacy Sprint-4 finance block kept for `accounting.dashboard.view` (`pages/dashboard/default.tsx:141-142`) |
| 25–28 | `SeverityBadge`, `LeadStat`, `StatBand`, `DashSection`/`DashTail`/`PanelEmphasis` | `dashboard/hierarchy.tsx:45-63` (+`severity.ts:52-103`) | hierarchy primitives: severity = icon + words + tint (`hierarchy.tsx:41-44`); `SEVERITY` table maps 5 states → `{chart, fill, dot, edge, chip, text, label, rank}` |
| 29 | `PeriodControl` / `PeriodNote` / `rangeLabel` | `dashboard/period.tsx:51-70,95-124` | `DashboardRange='today'\|'week'\|'month'\|'quarter'`; control only where backend honours `range` (`period.tsx:24-26`); `PeriodNote` prints server-reported window to stop title drift |
| 30 | `StatCard` + `Panel` (shared UI) | `components/ui/StatCard.tsx:5-26`, `components/ui/Panel.tsx:4-12` | `StatCard { label, value, delta?{value,direction}, helper?, linkTo?, polarity='higher-is-better'\|'lower-is-better' }`; `Panel { title?, meta?, actions?, noPadding? }` |

Plus server-contract types implementers must import, not re-declare:
`WidgetRenderKind='scalar'|'breakdown'|'trend'|'table'|'gauge'`,
`WidgetValueKind`, `DashboardLayoutItem{key,name,module,permission,render_kind,link_path,data,x,y,w,h,source}`,
`DashboardWidgetSummary{key,value,kind,helper,available,updated_at}`
(all `src/api/dashboard-layout.ts:8-127`).

Bespoke pages composing the above: `src/pages/dashboard/` —
`plant-manager.tsx` (4-row opinionated layout, `:34-43`),
`hr/ppc/finance/purchasing/warehouse/quality/admin/default/index.tsx`
(9 role pages + router + widget-home; `default.tsx:37-48` doc).

### 2b. Grid engine

CSS grid, not a grid library: `DashboardGrid` = `grid grid-cols-1 gap-3
lg:grid-cols-12` (`DashboardGrid.tsx:21`); widths are `lg:col-span-N`
(`:5-18`); height is `minHeight = h*24px` (`:42`); mobile stacks to 1 col
by construction. KPI rows use `kpiGridCols()` (`DashboardShell.tsx:36-44`,
1→6+ cards, always collapsing). No drag, no resize, no per-user sizing:
**the picker only adds/removes/reorders** (`DashboardPicker.tsx:150-159`
`move()`); `pack()` re-flows `x/y` server-side-bound (`:56-79`), and `w/h`
are clamped to seeded `default_w/default_h` (`:61-62`, `draftFromMeta :94-103`).
`react-grid-layout` was an explicit cut-scope item (CLAUDE.md "NOT BUILDING:
customizable dashboards (react-grid-layout)").

### 2c. Drill-down linking

Three link layers, all read-only (tiles navigate; act lives on target pages):
1. **Widget tile "Open →"** — server-owned `link_path` per widget row
(`dashboard-layout.ts:78-92`; was a 51-entry client literal, deleted for
drift — `:87-91` comment). Rendered in `LiveDashboardWidget` (`registry.tsx:250-258`).
2. **KPI strip** — per-code override `linkByCode`, else owning-dashboard
anchor `#kpi-{code}` via `kpiDashboardLink()` (`KpiStrip.tsx:155-158`,
`lib/dashboardKpis.ts:37-54`); code→dashboard map `KPI_DASHBOARD_PATH`
(`dashboardKpis.ts:27-35`).
3. **Legacy panels** — label/key→filtered-list maps `kpiLink /
chainStageLink / alertLink / alertRefLink` (`lib/dashboardLinks.ts:34-183`);
`StatCard linkTo` must be pre-built with query params (`StatCard.tsx:12-15`).
List pages must honour URL query params at mount (`dashboardLinks.ts:7-11`).

### 2d. Customize / persist

`DashboardPicker` modal (`DashboardPicker.tsx:105-131`): catalog from
`GET /dashboard/widgets` (permission-filtered, `:111-116`), draft add/remove/
reorder, `PUT /dashboard/layout {widgets, layout_version}` with optimistic-
concurrency 409 copy (`:118-131`), reset-to-role-default
(`default.tsx:66-73`, gated `dashboard.layout.reset`, `:42,94-104`).
Persisted server-side; layout source flagged `'role'|'user'`
(`dashboard-layout.ts:99-100`). Gaps: no drag/resize, no per-widget size
edit, no multi-layout/saved views (cut scope), no share/duplicate.

### 2e. Refresh / poll

- Bespoke dashboards: `refetchInterval: DASHBOARD_REFRESH_MS (60s)`
(`api/dashboards.ts:16-17` contract + precedent note) — e.g.
`plant-manager.tsx:99`, `default.tsx:55` (widget-data), `RoleDashboard.tsx:37`,
`ChainBottleneckWidget.tsx:35`.
- Fast surfaces: `DASHBOARD_REFRESH_FAST_MS (30s)` (`dashboards.ts:17`) —
`admin.tsx:52`, approvals board `pages/approvals/index.tsx:66` (comment:
"websocket upgrade is a future task").
- Forecasts **never poll** — manual Refresh only (contract in
`dashboards.ts:10-17`); `KpiStrip` scorecard/trends use `staleTime: 5min`
(`KpiStrip.tsx:89,100`).
- Badges/notifications poll 60s **plus** Echo push (`hooks/useBadges.ts:6-16,22-28`;
`NotificationBell.tsx:57-58`) — the one place realtime exists; dashboards
do not subscribe.
- Staleness is explicit, not silent: `WIDGET_STALE_AFTER_MS = 2min`
(`dashboard-layout.ts:135`), `isStaleTimestamp()` (`:138-147`); shell stale
bar (`DashboardShell.tsx:194-204`) + per-tile `Stale` Chip
(`registry.tsx:283-293`); `updatedAt` = newest widget-data timestamp
(`default.tsx:59-64`).

### 2f. States (skeleton / empty / error / stale)

Every dashboard goes through `DashboardShell` (`DashboardShell.tsx:158-162`
doc: "loading skeleton, retryable error, empty message, data — plus Stale"):
loading = KPI skeletons + `SkeletonDetail` (`:214-223`); error = `EmptyState` +
retry (`:225-236`); empty = `isEmpty`/`data==null` (`:237-240`); stale badge
(`:194-204`). Tiles repeat the pattern: `LiveDashboardWidget` skeleton →
`WidgetErrorBoundary` → `EmptyState` "Live data unavailable"
(`registry.tsx:259-294`); panels own `{data,isLoading,isError,onRetry}`
tri-state (e.g. `CapacityPanel.tsx:23-31`, `ForecastPanel.tsx:23-33`).
Isolation is cell-level: `DashboardGridItem` wraps each widget in
`WidgetErrorBoundary` (`DashboardGrid.tsx:46-52` comment), shell body too
(`DashboardShell.tsx:242-251`); `WidgetErrorBoundary` retries the owning
query, else reloads (`WidgetErrorBoundary.tsx:8-21`).
Primitives: `Skeleton{Block,Table,Card,Grid,Detail,Form,Panel,Page,Dashboard,Kanban}`
(`components/ui/Skeleton.tsx:12-26,42-74,350-390` — rows use `h-row` token so
floor density doesn't jump, `:36-41`); `EmptyState{size,icon,title,description,action}`
(`EmptyState.tsx:50+`); `QueryErrorState`, `WidgetErrorBoundary`.

### 2g. Export (CSV / PNG / PDF?)

- **Server list exports (exist):** `exportsApi` (`api/exports.ts:26-61`) —
column discovery, preview, `download(module,{format,columns,filters})` via
`downloadAuthenticatedFile()` blob→`<a download>` (`api/download.ts:40-69`,
single-flight guard `:8-12`); formats **`csv` | `xlsx` only**
(`types/exports.ts:5`); scheduled exports CRUD (`exports.ts:75-101`).
- **Server PDFs (exist, documents only):** `bulkPrint()` purchase_order/bill/
invoice, ≤50 docs (`api/print.ts:6-38`); per-record PDF/photo blobs via
`createObjectURL` in ~10 detail pages (e.g. `pages/return-management/cases/detail.tsx:376`,
`pages/portal/supplier/purchase-orders/detail.tsx:65`).
- **Dashboard/chart export (absent):** zero widget-level CSV/PNG/PDF;
no `html2canvas`/`jspdf`/`file-saver` in `package.json`; no export button on
any dashboard page or `DashboardShell` actions slot — despite the design-system
dashboard pattern naming "export" in the header (`docs/DESIGN-SYSTEM.md:528-532`,
see §5). Only precedent is role-compare CSV via `Blob` + `createObjectURL`
(`pages/admin/roles/compare.tsx:88-89`).

### Phase-6 SaaS checklist deltas (exists → gap)

| Capability | Status |
|---|---|
| Permission-filtered widget catalog + layout persist + reset | exists (§2d) |
| Poll + stale signalling + per-cell error isolation | exists (§2e–2f) |
| Drill-down links (tile / KPI / panel) | exists (§2c) |
| Date-range control | partial: `PeriodControl` today/week/month/quarter on plant-manager only (`plant-manager.tsx:92,109`); other windows frozen in titles, stated via `PeriodNote` (`period.tsx:86-95`); no per-shift/payroll-cutoff ranges (explicitly refused, `period.tsx:34-43`) |
| Per-widget export (CSV/PNG/PDF), scheduled dashboard mail-outs | **missing** (§2g) |
| Drag/resize, saved views, multi-layout | **missing**, cut scope (§2b) |
| Cross-widget filtering / global dashboard filters | **missing** (only URL-param drill-down) |
| Realtime push for tiles | **missing** (poll only; Echo used for badges, §2e) |
| Custom scripts / calculated metrics / alert subscriptions on charts | **missing** (no rule-builder UI — cut scope) |

---

## 3. Design tokens + theming

### Tokens

Single source: `spa/src/styles/tokens.css:1-124` (`:root` light),
`[data-theme='dark']` (`:132-185`), `[data-theme='floor']` (`:195-255`).
Tailwind maps every token via `color-mix(... <alpha-value> ...)` so opacity
modifiers work on `var()` colours (`tailwind.config.ts:9-14,20-98`); fonts
(`:128-132`: Public Sans UI / Spline Sans Mono numbers / Instrument Serif
display), radii 8/10/14 (`tokens.css:77-80`), density `h-row`/`min-h-hit`
(`tailwind.config.ts:154-168`), shadows overlays-only (`tokens.css:66-70`).
Enforcement: `npm run audit:tokens` (`package.json:21`,
`scripts/check-token-discipline.mjs:1-10`) fails on hex literals, raw Tailwind
palette classes, `text-white/black` neutrals, retired `--landing-*`
(`check-token-discipline.mjs:14-66`); scrim `bg-black/<alpha>` is the one
documented exception (`:53-63`); three.js/landing-canvas exempt (`:72-78`).

### Palettes

| Palette | Selector | Canvas / ink / accent | Use |
|---|---|---|---|
| Light | `:root` | `#fdfcfa` / `#1f1b16` / clay `#b4542a` | office default (`tokens.css:8-40`) |
| Dark | `[data-theme='dark']` | espresso `#17140f` / `#f5f1ea` / `#d97848`; semantic `-bg` opaque pre-composites (`tokens.css:126-131`) | office opt-in |
| Floor | `[data-theme='floor']` | `#0d0b08` / `#fffdf8` / `#ff8a4c`; rows 48px, hits 44px, body 15px (`tokens.css:195-255`) | shop-floor PWAs only |

### Dark / floor mechanics

User mode `light|dark|system` in `stores/themeStore.ts:4-25`; `system` resolves
via `matchMedia('(prefers-color-scheme: dark)')` with a live listener
(`:27-66`); applied as `<html data-theme>` (`:36-43`). **Floor is
route-forced, never chosen** (`themeStore.ts:5,22-24`): `TouchShell`
`pushOverride('floor')` on mount / `popOverride()` on unmount
(`components/layout/TouchShell.tsx:70-79`). No `dark:` variants by rule
(DESIGN-SYSTEM working-rule §3; only 3 `dark:` hits in `src`, all
`color-scheme`/logo-invert: `BrandLogo.tsx:27`,
`price-agreements/form.tsx:244`, `portal/customer/orders/new.tsx:167`).
Tailwind `darkMode: ['selector','[data-theme="dark"]']`
(`tailwind.config.ts:17`) exists but components must not use it.

### Chart palette + colorblind-safety

Charts resolve to the **same semantic tokens** — `var(--accent)` defaults
(`AreaTrend.tsx:19`, `SparkLine.tsx:12`), `var(--success/warning/danger)`
thresholds (`OeeGaugeChart.tsx:11-12`), `var(--danger)` bars +
`var(--warning)` cumulative line (`DowntimeParetoChart.tsx:85-99`) —
via the single `SEVERITY` table (`severity.ts:1-28` doc: saturated tokens
clear 3.30–10.58:1 on tracks; `-bg` tints forbidden as fills). There is **no
categorical/data-viz palette** (no 8-colour colourblind-safe set, no pattern
fills, no shape encoding inside charts). Colourblind-safety is carried by
**adjacent redundant encoding**: every severity renders icon + words
(`hierarchy.tsx:41-63` `SeverityBadge`; `registry.tsx:96-103` KPI status
words; donut values labelled `:58-66`; `Chip` text in `Chip.tsx:12-19`).
A greyscale printout of a bare `DowntimeParetoChart` bar/line pair or
`DonutBreakdown` slices is distinguishable only by legend/label, not by hue
choice. No chart-a11y test; the contrast gate covers token pairs
(`styles/__tests__/palette-contrast.test.ts:33` context).

### Number formatting (₱ / compact / tabular)

`src/lib/formatNumber.ts:1-68`: `formatPeso()` (Intl currency with runtime
functional currency, `:30-38`), `formatCompactCurrency(v,divisor,suffix)`
(`:41-47`), `formatInt/formatDecimal/formatQuantity(3dp)/formatPercent`
(`:49-68`); `en-PH` locale. Currency code is a module singleton fed by
`businessPolicies.functional_currency_code`; `AppLayout` **holds route content
until it is set** (`layouts/AppLayout.tsx:46-68`) so figures never render
bare. Widget scalar paths duplicate lightweight formatters (`registry.tsx:34-51`
`formatValue/formatRichValue`; `WidgetBreakdown.tsx:23-29`) — same rules,
three implementations. Tabular figures are a convention, not a helper:
`font-mono tabular-nums` on every numeric surface (`StatCard.tsx:58`,
`registry.tsx:60,83,275`, `WidgetBreakdown.tsx:38`, `DataTable.tsx:648-650`
`NumCell`).

---

## 4. Routing + guards + data-fetching conventions (implementer rules)

### Route layout

`src/App.tsx:36-96`: public `landing/careers/legal/authRoutes` → authed
`<AuthGuard><AppLayout/>` block (`:50-56`) mounting all module route groups
(`:57-75`) → `factoryRoutes` (own floor shell) → `portalRoutes` (separate
supplier/customer sessions, **never inside main AuthGuard**, `:82-88`) →
`*` 404. Module files `src/routes/*Routes.tsx` (e.g. `dashboardRoutes.tsx:48-126`):
**every page lazy** (`dashboardRoutes.tsx:6-42`) with **skeleton Suspense
fallbacks per page type** (`SkeletonDashboard/SkeletonTable/SkeletonKanban`,
`:52-76`); **every route guarded** (`PermissionGuard permission=…`, `:56-76`).
SPA URLs nest under umbrella slugs (`/hr/attendance/overtime`, never bare);
backend `/api/v1/*` paths are independent (CLAUDE.md routing rules;
`Sidebar.tsx:1001-1013` longest-prefix active match depends on it).

### Guard stack (3 layers + portal exception)

1. `AuthGuard` (`components/guards/AuthGuard.tsx:12-42`): bootstrap-once,
`FullPageLoader` while loading, `SessionRetry` on bootstrap error, else
`→ /sign-in`; forces `/change-password` when `must_change_password` (`:37-39`).
2. `ModuleGuard` (`ModuleGuard.tsx:11-27`): feature-flag gate → "Module
disabled" `EmptyState`; renders `children ?? <Outlet/>`.
3. `PermissionGuard` (`PermissionGuard.tsx:12-23`): denial renders **404-like**,
never 403-shaped (`:16-21` comment — must not leak route existence);
`standalone` variant for embedded use. `CanDo` for inline affordances
(`guards/CanDo.tsx`); `usePermission().can()` mirrors backend incl.
system_admin bypass (`Sidebar.tsx:786-812` doc).
Backend enforces independently; frontend is UX only. Row scoping is
server-side (`dashboard-layout.ts` permission stripping; `RoleDashboard`
renders only arrived panels — `plant-manager.tsx:113-123` doc).

### Data-fetching (TanStack Query + axios cookie client)

- **Client:** `api/client.ts:25-33` — `baseURL /api/v1`, **`withCredentials:
true` mandatory** (Sanctum cookie auth, `:16-24` doc); **no Bearer tokens, no
localStorage auth**. Global error interceptor: timeout/429/423/5xx toasts with
stable ids (`:74-105`), 401 session-probe-then-redirect (`:176-204`), 419 CSRF
replay (`:223-238`), 422/404 page-owned (`:219-241`); `wasReportedGlobally()`
de-dupes page toasts (`:59-65`). `unwrappingClient` only for migrated files
(`:305-324` warning). Defaults: `staleTime 30s, retry 1,
refetchOnWindowFocus false, offlineFirst` (`lib/queryClient.ts:11-23`).
- **Queries:** `useQuery({ queryKey: ['dashboard','plant-manager',range],
queryFn: client.get…then(r=>r.data.data), refetchInterval:
DASHBOARD_REFRESH_MS, placeholderData: prev=>prev })` (`plant-manager.tsx:93-101`);
widget-data keyed by live key list with `enabled: keys.length>0`
(`default.tsx:50-56`); `KpiStrip` joins code arrays into string keys to avoid
refetch loops (`KpiStrip.tsx:92-95` comment).
- **Mutations:** `useMutation` + `toast.success/error` + `invalidateQueries`
(e.g. layout save `DashboardPicker.tsx:118-131`, reset `default.tsx:66-73`);
submit buttons `loading={…isPending}` + `disabled` while pending
(`DashboardPicker.tsx:285-288`); offline mutations queue (`TouchShell.tsx:275-279`
`useTouchSubmitLabel`; `OfflineBanner` in `AppLayout.tsx:93`,
`TouchShell.tsx:121`).
- **Forms:** React Hook Form + `zodResolver(schema)` mirroring the backend
FormRequest (100+ sites; canonical minimal: `pages/auth/login.tsx:119-126`
`useForm({resolver})` + 422→`setError` mapping `:150-159`); server error text
via `reportMutationError` (`default.tsx:72`); primitives `Input/Select/
SearchableSelect/Textarea/CurrencyInput/MaskedInput/FormErrorSummary/
FormActions` (`components/ui/`); decimals stay strings end-to-end (backend
`decimal(15,2)`; `formatNumber.ts:24-28` parses at render).
- **Realtime:** Echo on demand (`useBadges.ts:30-40` `getEcho()`); dashboards
poll — do not add tile-level sockets without revisiting the poll contract
(`dashboards.ts:10-17`).

### Layout shell

`AppLayout` (`layouts/AppLayout.tsx:31-154`): skip-link, `OfflineBanner`,
`Topbar`, `Sidebar` (240px↔56px rail, `Sidebar.tsx:1273-1277`), `<main
#main-content>`, page-level `ErrorBoundary` (`AppLayout.tsx:126-143` comment:
a page crash must not take navigation), currency gate (`:128-142`),
`DevErrorPanel`, `⌘K` palette + shortcuts. `Sidebar` visibility =
permission + feature flag, never role name (`Sidebar.tsx:786-812`);
sections priority-sorted with per-role reading order (`:120-155,934-984`);
badges poll 60s + push (`useBadges.ts:6-16`).

---

## 5. Gaps justifying new deps or shared components (bundle-aware)

1. **Per-widget export (CSV/PNG) — shared component, no new dep.**
Justification: dashboard header spec already promises export
(DESIGN-SYSTEM §5 discrepancy below); server CSV/XLSX + blob-download
(`exports.ts`, `download.ts`) cover lists, but tiles have no affordance.
Build: a `WidgetExportMenu` reusing `downloadAuthenticatedFile()` for a
server `widget-export` endpoint (CSV), plus SVG-serialize→PNG for recharts
nodes (no `html2canvas` — keeps `vendor-charts` chunk lean). Defer PDF:
`bulkPrint()` proves server-PDF is the house pattern.
2. **Categorical colourblind-safe chart palette — tokens, no new dep.**
Justification: 6+ slice donuts/bars on one semantic ramp will collide and
fail print/greyscale; current safety is adjacent text, not in-chart encoding.
Add `--chart-1..8` tokens ×3 palettes + Tailwind map + `SEVERITY`-style
single table; gate with a contrast test like `palette-contrast.test.ts`.
No `echarts`/`d3`: recharts covers the needed marks; a second chart lib
doubles `vendor-charts` for unproven gain.
3. **Dashboard date-range standard — shared component + backend handoff, no dep.**
Justification: `PeriodControl` exists but mounts on one page; every other
window is frozen prose that already drifted once (`period.tsx:13-18`).
Promote `PeriodControl+PeriodNote` into `DashboardShell` header behind a
`range?: {value,onChange,serverDays?}` prop; per-shift/payroll-cutoff presets
need `rangeBounds()` backend support first (`period.tsx:34-43` says offering
them now would lie).
4. **i18n — new dep only if/when locales are committed.**
Justification: zero i18n infra (no `react-i18next` in `package.json`, no
`useTranslation` hits in `src`) and hardcoded English throughout; adding
`react-i18next` + ICU + locale bundles before a second locale ships is
speculative weight on every route. If Phase 6 requires it: `react-i18next`
(small, lazy namespaces per module) — **not** a full ICU-date overhaul since
`date-fns` + `Intl` already localise numbers/dates (`formatNumber.ts:3-22`).
5. **Tile-level realtime — wiring, no new dep.**
Justification: `laravel-echo`+`pusher-js` are installed and proven for badges
(`useBadges.ts:30-40`) while tiles poll; pushing KPI/widget invalidation over
the existing socket removes 60s staleness without touching the poll contract's
safety-net role. Do not add `socket.io-client`/GraphQL subs — Echo/Reverb is
the stack (`docker` runs Reverb; `playwright.config.ts:41-44`).

Explicit non-gaps (do not add): drag/resize grid (`react-grid-layout` cut
scope; picker suffices), second chart lib, ag-grid (`DataTable` + `WidgetTable`
cover both densities), Radix/MUI (Chip/Panel/Modal primitives exist and are
token-clean), `file-saver` (3-line anchor pattern in `download.ts:60-66`).

### Discrepancies vs `docs/DESIGN-SYSTEM.md` (file wins per p.3-5)

- D1: `--text-subtle` light: doc says `#8a8078` (`DESIGN-SYSTEM.md:70`) but
`tokens.css:30` ships `#6f665d`; dark: doc `#7d7468` (`:117`) vs shipped
`#a89f93` (`tokens.css:150`). Floor `--text-muted` was deliberately lifted
(`tokens.css:211-213` comment). Doc is the bug; patch the doc, not the file.
- D2: Dashboard pattern header reads "(title + range selector + export)"
(`DESIGN-SYSTEM.md:528-532`) — as-built: range selector on one page
(`plant-manager.tsx:109`), **export on none** (§2g); the real pattern is
`DashboardShell` + `header={<KpiStrip/>}` slot, which the doc never names.
- D3 (resolved, informational): pre-Atelier indigo `rgba(79,70,229,.55)` in
`tailwind.config.ts` keyframes was re-pointed to `var(--accent)`
(`tailwind.config.ts:197-215` comment) — the token gate still can't see raw
`rgba()` in config; noted so nobody reintroduces it.
- D4 (minor): `Skeleton` shimmer uses `bg-gradient-to-r`
(`Skeleton.tsx:20`, self-documented `:16-20`) under a system that bans
translucency for surfaces — skeletons are loading chrome, not surfaces, and
no `backdrop-blur` exists in `src` (grep: zero hits); acceptable, worth one
line in the doc.

### Blockers

None structural. Implementers have: token + audit gate, guard stack, query
client, form/table/skeleton/error primitives, widget registry with server
contracts, drill-down maps, poll/stale contracts, chunk-split bundle policy.
Only sequencing constraint: per-shift/payroll-cutoff ranges and any new
`render_kind` need backend (`rangeBounds()`, `WidgetAnalyticsService`
provider + `WidgetSeedIntegrityTest` registration) before SPA work.
