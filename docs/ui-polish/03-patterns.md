# Phase 4 — page pattern templates

These templates define how Phase 5 pages compose the shared foundation. They are composition rules, not a new layer of one-off page wrappers: use existing layout and UI components, then migrate each module in its own unit. No new color tokens are introduced here.

## Shared page frame

- Use `PageShell` for content width and vertical rhythm. Use its `grid` variant for sections that need the standard gap; `narrow` is for prose or short forms, not tables.
- A page owns one `PageHeader`. When `PageShell` owns the horizontal gutter, set `PageHeader inset={false}` and use the matching inset option on controls that provide one.
- Put the page title at upper left. Keep one primary action in the header action group; place secondary actions after it and rare or destructive actions in an overflow menu.
- Group sections with headings, spacing, and hairline borders. Do not put every section in a `Panel`, and never nest panels.
- Reuse existing tokens for density and targets. Office pages may choose compact rows where scan volume warrants it. Floor pages keep `min-h-hit`.
- Every data surface defines loading, error, and empty states. Loading skeletons keep final row or section dimensions; errors offer retry when retry is safe; empty states say what to do next when permissions allow an action.

## List

**Order:** `PageHeader` → `StatusTabs` when records have meaningful lifecycle states → `FilterBar` when filters exist → optional real-data analytics → `DataTable` → `DataTablePagination`.

- Keep the table close to the header. Do not insert a zero-heavy `StatCard` grid above it.
- Use status counts only when they describe the same result set and respect the current user's access. Keep zero counts visible and dimmed.
- Put filter names and selected values in the shared filter vocabulary. Show removable active filters and a clear-all path.
- Emphasize one identifying column. Right-align numeric values. Use avatars for people, `Tag` for grouping, and `Chip` or `StatusDot` for state only when those cues add information.
- Add analytics only when real data answers a question a user needs for this list. Do not derive summary counts from a paginated subset and label them as totals.
- Row actions remain available by pointer and keyboard. Selection exposes bulk actions only when current permissions and selection support them.

**Use:** `PageHeader`, `StatusTabs`, `FilterBar`, `DataTable`, `DataTablePagination`, `Avatar`, `Tag`, `Chip`, `StatusDot`.

## Detail

**Order:** `PageHeader` with record identity, status, and one primary action → `ChainHeader` only for a chain document → compact metrics when they answer a decision → tabs and primary content, with related records and activity in a supporting column or section.

- Keep the record identifier and its state in the header. Do not repeat the same status as a separate banner unless it communicates an urgent consequence.
- Show `ChainHeader` only for Order-to-Cash, Procure-to-Pay, Hire-to-Retire, or another defined process chain. Keep `LinkedRecords` and `ActivityStream` in supporting context.
- On narrow screens, put main record content before supporting panels. Side previews use `SidePanel`; they do not replace the deep link to the full record.
- Long editable details use the form rules below. Destructive actions name the record and consequence in confirmation copy.

**Use:** `PageHeader`, `Chip`, `ChainHeader`, `Tabs`, `LinkedRecords`, `ActivityStream`, `SidePanel`, `FormActions` where editing is present.

## Dashboard

**Order:** `PageHeader` with only supported range or refresh controls → needs-attention content when real exception data exists → compact KPI row → chart and table panels.

- Use `DashboardShell` for query-backed loading, retryable error, empty, stale-data, and responsive states. Role dashboards keep permission-filtered widgets; dedicated dashboards compose their own panels.
- Use `KpiStrip` or existing metric components for measures with a label and useful context. Show a delta only when its comparison period is valid; show a sparkline only when real history exists.
- Put urgent exceptions before general metrics. Do not invent a needs-attention panel when the data source has no exception signal.
- Every chart names the question it answers, uses the existing chart palette, labels its series, and exposes a text/table alternative. No chart earns space solely for color or motion.
- A range selector belongs in the header only when the query supports that range. Do not add page-level export to a multi-panel dashboard without one honest export dataset.

**Use:** `DashboardShell`, `PageHeader`, `KpiStrip`, `MetricPanel`, `StatCard`, `ChartFigure` wrappers, `Panel`.

## Form

**Order:** `PageHeader` with a clear back destination → draft or unsaved-change notice when supported → related fields grouped into named sections → inline validation near each field → `FormActions` with one primary submit and a secondary cancel/back action.

- Keep labels, units, helper text, and validation next to their fields. Put a concise error summary at the top only when a form can produce multiple errors.
- Relational inputs display searchable names and stable context, never raw database IDs.
- Preserve entered values after validation or request failure. Use `FormDraftBanner` only when that page has a real draft contract; do not imply autosave otherwise.
- Use `FormActions` for long office forms so actions stay reachable on narrow screens. Floor/PWA forms use the `TouchShell` bottom-tab offset when an action bar must clear that navigation.
- Keep the primary action explicit and stable. Disable it while saving, show progress, then show inline success or an actionable error.

**Use:** `PageHeader`, `Input`, `Select` or searchable relation picker, `Textarea`, `FormErrorSummary`, `FormDraftBanner`, `FormActions`, `StickyActionBar` where the shell requires a specific offset.

## Report

**Order:** `PageHeader` → visible filter/date summary → KPI strip only for meaningful measures → grouped tables or charts → export actions for represented data.

- Keep applied filters visible so a screenshot or printout retains scope.
- Group rows and subtotals by business meaning; preserve tabular numerals and numeric alignment.
- Export only the dataset represented in the view. State when a table or widget is truncated; do not silently export the visible page as a full report.
- Print is a separate state: review page breaks, repeated table headers, hidden controls, and all totals in print layout.

**Use:** shared `PageHeader`, filter controls, `StatCard`/`KpiStrip` only when earned, `DataTable`, chart wrappers, existing export actions.

## Settings and admin

**Order:** `PageHeader` → grouped settings sections → shared controls → explicit save or inline save feedback.

- Group related controls under short section headings and hairlines. Avoid a card for every setting and cards inside cards.
- Explain permission and system consequences beside the setting that changes them.
- Toggles expose their current value and accessible label. Save feedback names the setting group and reports failure where the user can act on it.
- Separate read-only system status from editable controls; do not make display-only data look like a form.

**Use:** `PageHeader`, `Panel` only where a boundary improves grouping, `Input`, `Select`, `Switch`, `Checkbox`, `Button`, inline feedback.

## Floor and PWA

**Order:** `TouchShell` → the immediate task and current state → the next permitted action → only the context needed to complete the task.

- Keep the forced floor palette, high contrast, large status text, and at least `min-h-hit` targets. Use row and spacing tokens; do not lower targets to fit more controls.
- Keep minimal chrome and make the current order, work stage, or exception visible at a glance. Pair color with text or an icon/shape.
- Do not carry office-only `StatusTabs`, dense filter chips, hover-revealed actions, or compact analytics strips onto the shop floor. Use explicit touch controls and preserve keyboard operation.
- Sticky actions clear the `TouchShell` bottom navigation. Avoid panels that require precise hover or tiny close targets.

**Use:** `TouchShell`, `PageHeader` only when it helps locate the task, shared touch-safe controls, semantic status cues, `StickyActionBar` with the shell's tab offset.

## Adoption and limits

Phase 5 applies these patterns one module or tight workflow group at a time. A page may omit a slot when its data or permissions do not support it; it must not fabricate counts, history, export scope, stage progress, autosave, or actions to appear complete. Record omissions and unreachable states in the coverage report. Keep functional defects in `FINDINGS.md` for separate fixes.

The current foundation supplies the components named above. It does not supply a universal analytics-strip component or a server-backed saved-view contract; those stay deferred until a real data question and persistence contract exist. Pattern adoption does not waive theme, responsive, keyboard, contrast, or route-level visual review.
