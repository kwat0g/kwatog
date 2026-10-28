# UI polish screenshot evidence

## Current worktree captures

These are pre-polish captures of the current worktree, made on 2026-10-25 with a temporary copy of the existing serial layout-audit harness and local API fixtures. The user-owned `spa/scripts/layout-audit.mjs` was not edited. Browser work ran one route and one viewport at a time. Mock data is clearly synthetic.

- `before/employees-list/`: 1280, 1440, 1920 in light and dark; eight fixture rows, status aggregate total 202.
- `before/dashboard/`: 1440 light and dark; the HR fixture has no configured widgets, so these show the real empty-dashboard state rather than populated analytics.
- `before/work-order-detail/`: 1440 light and dark; one synthetic production order with four populated chain stages, linked records, and activity events.
- `before/stock-adjustment-form/`: 1440 light and dark; synthetic form state with a visible draft-restore banner.
- `before/floor-factory/768.png`: route-forced floor theme on a tablet viewport, one synthetic active order.

The local stock-card/print capture reached an application load-error state because its API fixture was incomplete; it is retained under `evidence/stock-card-report-light-1440/` and excluded as a valid pattern screenshot. Two earlier employee attempts were also discarded: one showed “Module disabled”; one showed “Page not found” because the fixture lacked route permission.

## Approved pilot baselines

After pilot approval on 2026-10-26, a temporary copy of the serial layout audit harness captured
four pilot routes at 1280, 1440, and 1920px in light and dark. Each browser visited one route and
one viewport at a time. Mock records are synthetic; capture reports are stored beside each set.

- `before/pilot-employees/`: `/hr/employees`, HR fixture, eight status counts and four visible rows.
- `before/pilot-sales-order/`: `/crm/sales-orders/layout-so-001`, synthetic order with seven chain
  stages, linked MRP/work-order/QC records, and activity history.
- `before/pilot-dashboard/`: `/dashboard` dispatching to the admin dashboard, synthetic populated
  security/queue/alert data.
- `before/pilot-stock-adjustment/`: `/inventory/stock-adjustments/create`, warehouse fixture with
  draft banner and real form states.

The temporary harness is not part of the repository. These captures are baselines, not live data.

## Pilot after captures

Captured 2026-10-26 after the Phase 2 pilot changes. Each of the four office routes has light and
dark screenshots at 1280, 1440, and 1920px: 24 before and 24 after captures total. Browser work
ran serially, one route and viewport at a time. All pilot reports show HTTP 200 and no horizontal
page overflow. The dashboard report correctly records zero tables; it is a dashboard, not a list.

- `after/pilot-employees/`: status tabs, shorter filter controls, employee avatars, department
  tags, emphasized name column, and table density controls. Each palette also has 1440px
  row-hover and deep-linked side-panel preview captures.
- `after/pilot-sales-order/`: sales order detail with process chain, linked records, and activity.
- `after/pilot-dashboard/`: populated synthetic admin dashboard with needs-attention content first.
- `after/pilot-stock-adjustment/`: grouped stock-adjustment form with synthetic stock context;
  each palette also has a 1440px selected-item capture showing the reorder-point meter.
- `after/floor-factory/factory-768.png`: route-forced floor theme on a tablet viewport, one
  synthetic active work order, and large touch targets. This is a shared-component regression
  check; the four office pilots do not use the floor palette.

Screenshots were visually reviewed in both office palettes, including the open preview, selected
item, hover action, and floor state. Fixtures use synthetic data. Report, auth, settings, all
conditional overlays, and the full role-by-route matrix are not covered by these captures.
The temporary Vite server logged an HMR WebSocket 308 because its local host defaulted to
`localhost`; page requests returned 200 and the captures rendered. No browser-console clean bill is
claimed from this harness run.

## Phase 3 shared-foundation captures

`after/foundation-cues/{light,dark,floor}/` contains the Component Gallery at 390 and 1280px,
plus section crops for status and record patterns. Current gallery covers searchable `FilterBar`
pickers with active chips, `DataTable` loading rows at compact density, labelled `SparkLine`,
`StatusDot`, `StageDots`, and the record patterns. The floor palette is applied to the gallery
fixture to inspect token rendering; it is not a floor-route behavior claim. Captures returned
HTTP 200 with no horizontal overflow.

`after/chart-wrappers/gallery-{light,dark,floor}/` contains full Component Gallery captures at
390 and 1280px after chart-wrapper changes. The charts section shows category and state donuts,
labelled series bars, and a labelled forecast. Reports show HTTP 200 and no horizontal page overflow. The
floor captures check palette rendering only; chart behavior on a route-forced floor screen was not
claimed. Playwright baselines at `spa/e2e/__screenshots__/gallery.spec.ts/charts-{390,1280}.png`
cover the chart section in the default light palette. There is no directly comparable pre-change
chart-gallery capture because the gallery had no charts section before this unit.

Dashboard call sites were updated in `/dashboard/{quality,purchasing,finance,warehouse,ppc,plant-manager,hr}`,
`/quality/dashboard`, and `/maintenance/downtime`. They were not individually captured: the local
layout harness only has a populated dashboard fixture for the admin route. The gallery verifies
shared chart states, not those route layouts. Direct page-owned Recharts charts remain for module
rollout.

## Phase 5 direct chart routes

`after/chart-routes/{light,dark}/` contains 40 after screenshots: five office routes at 375, 1280,
1440, and 1920px in both office palettes. Every screenshot uses synthetic local API fixtures; the
capability page runs a study so its populated chart and success feedback are visible. No floor
captures apply to these office report routes. The chart route with preserved layout-audit reports is
the capability study: `quality-capability-report.json` and `quality-capability-summary.md` in each
palette folder. The other route reports were not retained, though each capture logged route,
viewport, table count, and page-overflow result during the serial run.

All five routes rendered without document-level horizontal overflow at all four widths. Budget and
capability tables retain local horizontal scrolling at 375px. The charts’ screen-reader tables are
wrapped in a clipped `sr-only` container; leaving intrinsic-width tables directly positioned caused
mobile document overflow. The updated `ChartFigure` test covers that structure.

There are no matching populated pre-change screenshots for these routes. The older capability
baseline is an empty form, so it is not a valid visual comparison. Demand-chart data-order and
missing-forecast-series defects are recorded separately in `../FINDINGS.md`; the style rollout did
not repair those behaviors.

## Phase 5 HR profile update requests

`after/profile-update-requests/{hr,finance}/{light,dark}/` contains 16 base screenshots: the HR and
Finance queue views at 375, 1280, 1440, and 1920px in both office palettes. Each role/palette
directory has an audit report. The 1280px views also include an expanded request and its named
approval confirmation, for eight state captures total. Synthetic employee and bank details are
used. HR and Finance queue permissions render separately; Finance-only captures do not expose an
employee link or HR approval action. All base captures returned HTTP 200 and reported no document
horizontal overflow. Screenshots were reviewed in both palettes, including expanded and
confirmation states. No comparable populated pre-change capture exists for this page.

## Phase 5 recruitment applications list

`after/recruitment-applications/hr/{light,dark}/` contains eight populated synthetic-fixture
screenshots at 375, 1280, 1440, and 1920px, plus one layout-audit report per palette. All eight
captures returned HTTP 200 and reported no document-level horizontal overflow. Light and dark
screenshots were reviewed at mobile, 1280, and both wide desktop sizes. The 375px view keeps the
applicant and application number visible while the remaining columns scroll locally. The route is
office-only; no floor capture applies. No comparable populated pre-change screenshot exists.

## Phase 5 recruitment postings list

`after/recruitment-postings/hr/{light,dark}/` contains eight populated synthetic-fixture screenshots
at 375, 1280, 1440, and 1920px, with one layout-audit report per palette. All eight captures
returned HTTP 200 and reported no document-level horizontal overflow. Light and dark captures were
visually reviewed at all four widths. At 375px, title and posting number remain visible while the
department and remaining columns use local table scrolling. The route is office-only; no floor
capture applies. No comparable populated pre-change screenshot exists.

The gallery visual gate passes ten targeted `status`, `records`, `tables`, `controls`, and `panels`
screenshots at 390 and 1280px after updating those baselines. A broader gallery run exposed stale
baselines in other sections and was stopped after repeated diffs; it was not a full pass.

## Existing screenshot corpora

- `docs/ui-ux/screenshots/baseline-roles/`: 162 PNGs, 16 roles, 34 routes, light palette only. The Employee baseline was captured 2026-10-13 UTC.
- `docs/layout/screenshots/`: 220 PNGs, including 79 dark captures; selected stock-adjustment list/form and inventory/production detail states. No floor captures.

These older corpora supplement, but do not replace, current-worktree captures. Phase 0 gaps remain:
full role-by-route crawl, axe, Lighthouse/CLS, print output, report/auth/settings patterns, and
conditional modal/drawer states remain unrendered or unverified. The current floor capture covers
one tablet route, not the full floor PWA.
