# Phase 2 — plan and pilot proposal

**Status: approved for pilot implementation on 2026-10-26.** Current baselines are under
[`screenshots/before/`](screenshots/README.md). This file remains the agreed scope; implementation
and visual verification are in progress.

## Ranked findings

Ranks use route volume, table/chart surface counts, workflow consequence, and observed audit pain.
They are prioritization proxies, not production traffic analytics; no route-usage telemetry was
available.

| Rank | Finding | Evidence | Planned response |
|---|---|---|---|
| 1 | Low-information status cards delay the primary work surface. | Employees has six repeated tiles, five with zero in the current fixture; the band is 270px high and table starts below it. | Replace the list-page KPI grid with URL-synced StatusTabs and visible counts, including dimmed zeros. Keep the table above the fold sooner. |
| 2 | Table rows carry little visual identity or hierarchy. | Employee rows have no avatar, department grouping tag, or distinct key column; all six columns read similarly. | Add avatar/tag cells where data exists; emphasize the person/document/item column; preserve compact density. |
| 3 | Filters and table controls use inconsistent vocabulary and control patterns. | “All types” vs “Pay type: All”; active filter count is monospace; density is a native select; Columns is a separate button. | Standardize FilterBar labels/chips and replace density/columns controls with keyboard-accessible popovers/segments. |
| 4 | Chain progress is concentrated on details. | StageBreakdown has two direct page-source uses; chain lists/dashboard summaries lack a shared compact stage cue. | Pilot StageDots on chain-document rows and real exception summaries. Do not add to unrelated lists. |
| 5 | Dashboard and list numeric context is uneven. | Existing StatCard supports delta and SparkLine exists, but status tiles lack comparison; current dashboard capture is an empty configured-widget state. | Keep only real data; add comparison period/delta when source data supports it; needs-attention first. |
| 6 | Shared feedback is inconsistent across a large route surface. | Static scan: 639 hover, 330 focus, 34 active, 33 disabled markers; these counts do not prove state completeness. Row hover actions are absent from the shared table. | Harden shared states once, including hover and keyboard action reveal, loading/error/empty, inline confirmation, and reduced motion. |
| 7 | Token and accessibility evidence has known gaps. | `audit:tokens` finds `text-white` in ProductPhoto; current axe run is blocked by invalid/missing `@axe-core/playwright`; full role/route coverage and Lighthouse/CLS are incomplete. | Log the unrelated ProductPhoto fix in FINDINGS for its own focused PR. Repair the isolated axe environment before accessibility rollout; do not weaken thresholds. |

## Pattern templates (Phase 4)

| Pattern | Composition and rules |
|---|---|
| List | PageHeader → StatusTabs → FilterBar → optional real-data analytics strip → DataTable → pagination. No zero-heavy KPI card grid. Table reaches the viewport quickly. |
| Detail | PageHeader with status and one primary action → ChainHeader when the record belongs to a process chain → compact metrics row → tabs/content; LinkedRecords and ActivityStream in the supporting column or panel. Long forms use a sticky action bar. |
| Dashboard | PageHeader with range selector → compact KPI row (value, delta, sparkline only with real history) → panels. Needs-attention content leads. Each chart answers a stated question and includes direct labels plus text/table alternative. |
| Form | Sectioned fields with clear primary/secondary actions, inline validation, searchable name-based relations, preserved draft/unsaved-change behavior. No raw-ID entry. |
| Report | Filter summary → KPI strip only for meaningful measures → grouped tables → export. Print layout is separately reviewed. |
| Settings/admin | Grouped sections, shared toggles/inputs, clear save result, no nested cards. Permission consequences remain explicit. |
| Floor/PWA | Large targets, high contrast, minimal chrome, glanceable status. Adopt only changes that improve gloved touch use. |

## Pilot scope

| Pilot | Route and pattern | Proposed application |
|---|---|---|
| Employees list | `/hr/employees` · list | Replace six cards with StatusTabs; standardize filters and density/column controls; avatar and category tag for employee/department; key-column emphasis; retain permission-aware actions and query behavior. |
| Chain detail | `/crm/sales-orders/:id` · detail | Refine PageHeader, ChainHeader, linked-record and activity hierarchy; add compact stage cue only where it conveys the current process state; preview panel remains permission-aware and deep-linkable. |
| Main dashboard | `/dashboard` dispatcher to the system-admin dashboard target · dashboard | Use needs-attention first, compact metric row and charts only with populated real fixture data. `/dashboard` redirects server-side; capture the resolved target, not the redirect skeleton. |
| Form | `/inventory/stock-adjustments/create` · form | Group fields and stock context, searchable item/location selection, inline feedback, draft banner, clear primary action; preserve validation, confirmation and API behavior. |

Before/after capture matrix: each office pilot at 1280, 1440, and 1920px in light and dark. Floor
is not applicable to these four office routes; separately recheck the route-forced `/factory`
floor screen at the tablet baseline because shared components can affect it. Before and after
captures are complete: 24 each across the four office pilots. Captures ran
serially, one browser route and viewport at a time. The route-forced `/factory` floor screen was
also rechecked at 768px. Dashboard fixtures are permission-valid and populated, but synthetic;
screenshots show layout and interaction states rather than production values. Reports show HTTP
200 and no horizontal page overflow at the pilot widths.

## Token proposal — approved for the pilot

### Avatar tint set: six deterministic roles

**Proposed names:** `--avatar-1..6`, `--avatar-1-bg..6-bg`, and `--avatar-1-fg..6-fg`.
Purpose: repeatable, soft person tints with legible initials. Existing neutral surfaces do not
provide stable identity cues. `--avatar-n` repeats an existing chart mark hue; background tint is a
theme-specific mix of that hue into the existing canvas/surface; foreground repeats
`--text-primary`. Assign by stable user-ID hash, falling back to normalized name hash. Photo data
continues to take precedence.

Each cell lists `--avatar-n / --avatar-n-bg / --avatar-n-fg`. These are the approved pilot values.

| ID (source chart role) | Light | Dark | Floor |
|---|---|---|---|
| 1 (chart 1) | `#643e2b / #e6e0db / #1f1b16` | `#bc8062 / #423127 / #f5f1ea` | `#c59177 / #3d3026 / #fffdf8` |
| 2 (chart 3) | `#568137 / #e4eadd / #1f1b16` | `#abe085 / #3e462e / #f5f1ea` | `#bbea9a / #3b432e / #fffdf8` |
| 3 (chart 4) | `#358d64 / #dfebe4 / #1f1b16` | `#74dcac / #324537 / #f5f1ea` | `#9cdebf / #344036 / #fffdf8` |
| 4 (chart 5) | `#1d6a87 / #dbe6e9 / #1f1b16` | `#81c2da / #354041 / #f5f1ea` | `#9dd3e7 / #343e3f / #fffdf8` |
| 5 (chart 6) | `#2a25b1 / #dddcef / #1f1b16` | `#5256e0 / #2a2842 / #f5f1ea` | `#6c70e5 / #2a283e / #fffdf8` |
| 6 (chart 8) | `#642b47 / #e6dddf / #1f1b16` | `#bc628f / #422b31 / #f5f1ea` | `#c5779e / #3d2a2e / #fffdf8` |

**Tailwind mapping:** `avatar.1` through `avatar.6`, each exposing `DEFAULT`, `bg`, and `fg` to
support `bg-avatar-1-bg`, `text-avatar-1-fg`, and mark usage. Mapping only; no `dark:` override.
The tint foreground/background WCAG minima are 12.68:1 light, 8.80:1 dark, and 10.18:1
floor. Base-mark minima against canvas are 3.98:1 light, 3.07:1 dark, and 4.76:1 floor;
these clear current 3:1 office and 4.5:1 floor mark thresholds. Ratios use the same WCAG relative
luminance formula as `lib/contrast.ts`; the official token test now asserts both pairings across
all three palettes. Initials/accessibility names carry identity; colour does not stand alone.

### Category Tags: reuse current chart palette, add no hue tokens

No new category colour token is justified: `--chart-1..8` already provides eight identity hues and
the palette gate checks canvas contrast and pairwise separation under normal vision,
deuteranopia, and protanopia. Add a Tailwind `category-1..8` mapping to those existing values.
Category Tag uses a square-corner body and leading square/edge mark; status Chip remains rounded
and uses only semantic tokens. The category label is always present. Chart palette contrast
fixture result from Phase 0: **132/132 passed** across all three palettes and existing palette
checks. This reuses the existing gate; it is not a request to add a second series palette.

### Data-viz series and heatmaps

Reuse `--chart-1..8` through `chartColor(i)` and existing Recharts wrappers. The current series
palette already passes the contrast/CVD gate. No new series values or Tailwind colors proposed.
No heatmap use was found in the direct chart inventory, so a sequential ramp is deferred until a
real heatmap question exists.

### Gate updates after approval

Add avatar tokens to `tokens.css` for all palettes, map them in Tailwind, and extend
`palette-contrast.test.ts` with initials-on-tint and base-mark-on-canvas assertions using existing
AA office / AAA floor limits. Category marks stay on the existing chart gate. Update
`DESIGN-SYSTEM.md` with tint assignment, category-vs-status treatment, and the component rule in
the same token PR. Do not lower any threshold.

## Shared foundation component list

| Work | Existing pieces to reuse or harden | New shared pieces proposed |
|---|---|---|
| List navigation/filtering | PageHeader, FilterBar, SegmentControl, URL filter hook, DataTableToolbar | StatusTabs with URL sync, arrow-key navigation, visible dimmed zero counts, and sliding underline; FilterChip/popover controls with `Field: Value`, × removal, Clear all, saved views, result count, overflow summary, and searchable relational options |
| Identity/state/quantity | Avatar, Chip, ProgressBar, SparkLine, StageBreakdown, `chipVariantForStatus` | AvatarGroup; category Tag; StatusDot; InlineBar/ProgressMini; Delta; StageDots |
| Table | DataTable, pagination, skeleton/empty/error states, context menu | Extend shared DataTable for key-column style, avatar cells, hover/focus row actions with a keyboard path, bulk-action bar, density segment, Columns popover, sticky-header border, row-height skeletons, and consistent empty/error states |
| Preview and navigation | Modal, BottomSheet, Tabs, Tooltip, toast | SidePanel with focus trap, Escape, deep link, full-page action; sliding Tabs; shared Popover/Tooltip/Toast patterns only where current pieces fall short |
| Page and dashboard | PageHeader, StatCard, Panel, Recharts wrappers, `chartColor` | PageHeader subtitle/live count and optional last-updated value; Analytics strip (real status mix, distribution, or activity only; collapsible and earned by a question); chart wrappers standardized on current library and palette |
| Shell and feedback | AppLayout, sidebar, topbar, FormDraftBanner, inline form errors | Resolve truncation/label width and rail behavior; shared badge presentation only (badge semantics stay separate); inline success/error feedback |

Component duplication (notably `StageBreakdown`, `ChainHeader`, `LinkedRecords`,
`ActivityStream`) needs caller and visual-drift review before consolidation; no blind rewrite.

Component behavior contracts: Avatar keeps initials/photo support, accessible names and
deterministic tint; Tag remains visibly distinct from status Chip; StatusDot always pairs with a
label; InlineBar distinguishes quantity/target from stage completion; Delta names its comparison;
SparkLine is flat, directly labelled, and has a text alternative; StageDots names completed,
current, blocked, and remaining stages accessibly. SidePanel has a focus trap, Escape close, deep
link, and “open full page.” PageHeader keeps a serif title, one primary action, and optional live
count/last-updated meta. Hover actions also appear on keyboard focus. All new/revised states cover
hover, focus-visible, pressed, selected, disabled, loading, and error; reduced motion preserves the
state change without movement.

## Other proposed ideas

| Idea | Plan |
|---|---|
| Saved views and per-user table layouts | Saved filter views belong in FilterBar. Defer server-persisted table layouts until existing preference API support is confirmed. |
| Pinned/recent records and quick peek | SidePanel is in the foundation; later extend it to linked records, notifications, and search after permission-aware record lookup is proven. |
| Needs-attention dashboard strip | Include only from real exception data; place before general metrics. |
| Inline/bulk edit with preview and undo | Defer until current API permission and rollback behavior can support it safely; DataTable bulk-action bar is included. |
| Number formatting | Reuse existing `formatNumber` helpers; inventory remaining inconsistent money/quantity formats during module rollout. |
| Component gallery | Defer until core component states stabilize; add as a visual QA surface, not a substitute for page review. |
| Design-system lint rules | Evaluate after audit:tokens coverage is understood; current token audit already catches raw colors and Tailwind palette classes. |
| Command palette and keyboard help overlay | Phase 6 follow-up. Must perform permission-aware page/record search and real quick actions; do not ship a decorative or fake command surface. |

## Module roadmap: traffic proxy × pain

Order uses route count, table/chart surfaces, and workflow consequence as a traffic proxy. Actual
traffic is unavailable; severity/approval screens rank high even with fewer routes. S = small, M =
medium, L = large. Each row is one router module from the Phase 0 inventory; patterns within it
follow that module’s route inventory.

| Wave | Module | Routes | Effort | Focus |
|---|---|---:|:---:|---|
| 1 | hr | 36 | L | Employees, payroll-adjacent HR, approvals and profile requests |
| 1 | accounting | 32 | L | Bills, invoices, payments, review and reports |
| 1 | inventory | 27 | L | Lists, stock, receiving, adjustments, item detail |
| 1 | crm | 24 | L | Customers, products, sales orders, chain detail |
| 1 | admin | 23 | L | Users, roles, permissions, settings and admin dashboards |
| 1 | portal/customer | 22 | M | Customer orders, invoices, pricing and dashboard |
| 1 | quality | 20 | L | Inspections, NCR, traceability, quality dashboard |
| 1 | self-service | 19 | M | Employee records, requests, settings |
| 1 | dashboard | 18 | L | Role dashboards, needs-attention and chart wrappers |
| 1 | purchasing | 17 | M | Purchase requests, RFQs, purchase orders, chain stages |
| 1 | portal/supplier | 17 | M | Supplier order, invoice and dashboard surfaces |
| 2 | mrp | 15 | M | Plans, recommendations and material detail |
| 2 | production | 11 | L | Work-order entry, schedule, output, OEE and floor boundary |
| 2 | maintenance | 11 | M | Work orders, parts, recovery, tablet controls |
| 2 | budgeting | 9 | M | Budget lists, detail and variance context |
| 2 | supply-chain | 8 | M | Cross-module chain and detail views |
| 2 | payroll | 7 | M | Payroll runs, statutory forms, review states |
| 2 | return-management | 7 | M | Returns, case detail and form feedback |
| 2 | assets | 4 | S | Asset list/detail and maintenance links |
| 2 | forecasting | 3 | M | Forecast charts and scenario context |
| 2 | driver | 3 | M | Route/dispatch detail and touch targets |
| 2 | factory | 3 | M | Floor-only PWA, large targets and glanceable state |
| 3 | careers | 3 | S | Public/authenticated recruitment views |
| 3 | chains | 2 | M | Chain tracker and recovery, stage cues |
| 3 | approvals | 2 | M | Decision clarity, consequence, keyboard review |
| 3 | decision-center | 2 | M | Decision queue and record context |
| 3 | action-center | 1 | S | Cross-module exceptions and next actions |
| 3 | alerts | 1 | S | Severity text/state and alert actions |
| 3 | calendar | 1 | S | Date context and modal states |
| 4 | notifications | 1 | S | Visual badge/notification state; semantics owned separately |
| 4 | change-password | 1 | S | Password form and inline validation |
| 4 | forgot-password | 1 | S | Auth recovery form |
| 4 | login | 1 | S | Sign-in state and responsive review |
| 4 | sign-in | 1 | S | Alias/auth entry validation |
| 4 | reset-password | 1 | S | Password reset form |
| 4 | portal/:type | 1 | S | Portal dispatch/entry state |
| 4 | portal/password-reset | 1 | S | Portal password reset |
| 4 | public | 1 | S | Public entry surface |
| 4 | privacy | 1 | S | Legal content readability |
| 4 | terms | 1 | S | Legal content readability |
| 4 | cookies | 1 | S | Consent/preferences if stateful |
| 4 | error | 1 | S | Not-found/error recovery action |

Wave 1 gets first release capacity after pilot approval. Wave 2 covers high-consequence operational
and floor workflows. Waves 3–4 cover smaller, lower-frequency modules and static/auth surfaces.
The deadline was not provided; the commit point for “before deadline” is therefore: foundation and
four pilots first, then Wave 1 pages that fit the confirmed deadline; Waves 2–4 follow in separate
revertible PRs. Do not promise every route before the date is known.

## Risks and release boundaries

- Shared DataTable/PageHeader changes touch many routes. Ship foundation and each module in its own
  PR; migrate through shared components before page-specific overrides.
- Token additions need all three palettes and every pairing checked. Existing chart tokens already
  cover categorical series; avoid duplicating them.
- Role-permission data and route fixtures are incomplete. Keep actions permission-aware and report
  routes/states that cannot be reached rather than fabricating access.
- Existing screenshot harness has incomplete fixtures and current dashboard baseline is empty.
  Establish deterministic, permission-valid data before visual comparisons.
- Floor theme is forced by TouchShell and is neither dark nor light. Verify shared changes on a
  tablet route; do not transfer office-only density patterns to floor.
- Axe is currently unavailable because the Playwright package entry is invalid/missing. Fix the
  test environment before accessibility acceptance; no thresholds may be relaxed.
- Functional issues F-EMP-01..03 and the ProductPhoto token violation remain in FINDINGS. They are
  not silently bundled into visual work; each gets a focused fix/PR and regression proof.

## Open questions and defaults

1. **Avatar hash seed:** stable ID or name? Default: stable user ID, normalized name fallback.
2. **Dashboard target for pilot:** which role’s `/dashboard` dispatch target? Default: system admin,
   with a populated permission-valid dashboard fixture; choose another role if executive attention
   is the priority.
3. **Traffic ranking:** can route analytics be supplied? Default: use the documented route-count /
   workflow-risk proxy until real telemetry exists.
4. **Deadline:** what date defines “before deadline”? Default: none supplied; make no date-based
   commitment, deliver foundation and pilots first.
5. **Category mapping:** who owns the department/category-to-series assignment? Default: stable
   category ID hash into the existing eight `--chart-*` roles, persisted only in UI deterministically.

## Approval gate and remaining evidence

Approved scope: four pilots and six avatar token triples above. Category colours and chart series
reuse the current palette; no heatmap ramp is proposed. Pilot implementation, before/after
screenshots, and contrast gate are complete. The user approved the pilot implementation on
2026-10-26 and then instructed “proceed” after review. Phase 3 foundation work has started.

The palette contrast test now passes 168 assertions across light, dark, and floor, including the
avatar triples. Focused component suite passes 193 tests. Remaining gates: full typecheck is blocked
by unrelated existing errors listed in `PROGRESS.md`; `audit:tokens` reports the separate
ProductPhoto literal; axe cannot load because `@axe-core/playwright` is invalid/missing. Full role
crawl, Lighthouse/CLS, report/auth/settings, print, and all conditional overlay coverage remain
open.

The Phase 0 route/surface inventory covers all 361 static route declarations, all 598 inventoried
surface declarations/notes, and the listed role/pattern metadata. It is not runtime coverage: 356
routes and many conditional states have not been rendered. The later coverage report must mark each
route as migrated, explicitly deferred with a reason, or unreachable/unverified with evidence; no
route is considered complete by inventory alone.
