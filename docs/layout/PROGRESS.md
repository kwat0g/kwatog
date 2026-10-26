# OGAMI layout programme progress

Resume from this file. The user approved the three-route pilot and Phase 3 on 2026-10-25.

## Current state

| Phase | Status | Evidence |
|---|---|---|
| 0 — Audit | Static architecture, route, and surface inventory complete. Full runtime inspection of every route is still open. | [`00-audit.md`](00-audit.md), 361-row `route-inventory.csv`, 361-row `page-issue-matrix.csv`, `surface-inventory.csv`, baseline screenshots. |
| 1 — Findings | Findings documented for the static inventory and the rendered Stock Adjustments baseline. Other routes are explicitly unmeasured. | [`01-findings.md`](01-findings.md). |
| 2 — Spec/pilot | Approved. Three pilot routes plus Work Order link, button, and status color follow-ups implemented. | [`02-spec.md`](02-spec.md), [`00-audit.md`](00-audit.md), screenshots/reports. |
| 3 — Foundation rollout | Merged to `main` on 2026-10-26 through PRs #356 and #360. Shared layout primitives, preferences, Compact default, zebra behavior, gallery examples, and layout guard are in `main`. | [PR #356](https://github.com/kwat0g/kwatog/pull/356), [PR #360](https://github.com/kwat0g/kwatog/pull/360). Full API/SPA checks were not complete when merged. Phase 4 route migrations remain open. |
| 4 — Page rollout | In progress. Production Work Orders list, create, detail, and record-output routes migrated. Remaining modules are open. | [`FINDINGS.md`](FINDINGS.md#f-007--production-work-orders-layout-lacked-one-shared-page-boundary), four updated inventory rows, Light/Dark screenshots and reports. |
| 5 — Verification | Pilot browser/layout checks completed; automated application tests, accessibility, performance, and full role walkthrough are not yet recorded here. | `spa/scripts/layout-audit.mjs`; reports under `screenshots/after/pilot-final/` and `screenshots/states/`. |

**Review gate:** passed. User approved the pilot and Phase 3 on 2026-10-25. Pilot metrics remain limited to the rendered routes; do not treat them as proof for all routes.

## Pilot coverage

- Layout routes: `/inventory/stock-adjustments`, `/inventory/stock-adjustments/create`, `/inventory/items/:id`.
- Each route rendered in light and dark at 375, 768, 1024, 1280, 1440, 1920, and 2560px. Browser runs were serial. The list used 50 synthetic rows at all widths.
- Stock list stress states: 0, 1, 10, and 500 synthetic rows in both themes at 1440px; 50 rows also covered at all seven widths. The local admin list rendered one real row in the baseline at 1440px.
- Dark native dropdown was captured on the create form. Work Order detail with five synthetic linked IDs was separately rendered dark at 375 and 1440px to verify link color.
- Pilot strict measurement summaries report zero findings. They check content edges, sidebar clearance, page overflow, scroll behavior, table/type/contrast samples, dark color-scheme, dropdown colors, and linked-ID visibility. A zero pilot score is not a whole-project result.
- 500-row fixture produced 500 DOM rows and no page-level horizontal overflow. Runtime performance was not timed.

## Phase 3 foundation unit

- Added `PageContainer`, `FormGrid`, `DetailLayout`, and canonical `Toolbar` primitives. Existing `FilterBar` and `DataTableToolbar` compose `Toolbar`; pagination has canonical `TablePagination` with a compatibility export. Existing route gutters remain intact until their Phase 4 migrations.
- Added named line-height and content-layout tokens; extended the component gallery with spacing/type scales, density examples, page containers, form/detail grids, and toolbar. Fixed the gallery screenshot helper so it hides only the app sidebar, leaving demo asides visible.
- Added `npm run audit:layout` and a CI step. It rejects new arbitrary size/spacing classes and raw CSS/inline layout values, and requires changed inventoried route pages to use a shared shell or document a special-layout exception. It checks additions against the PR base; existing debt remains.
- Stock Adjustments was re-rendered with 50 synthetic rows at 375px and 1280px, in Light and Dark. Strict measurement score: 0 at all four captures. Before/after images and reports are under `screenshots/{before,after}/layout-foundation/`. Gallery baselines cover all eight sections and both dialog states at 390px and 1280px; all were visually reviewed and checked.
- The changed Stock Adjustments toolbar and table layout remained visually stable; the main change consolidates the markup behind shared primitives. The gallery now demonstrates the primitives directly.
- Gallery inspection exposed one pre-existing shared-component issue: `ChainHeader` labels overlap at 390px. Logged as F-006 for the detail-route rollout; not changed in this foundation unit.
- Focused SPA tests: 7 files / 17 tests passed. Gallery Playwright: 21 passed with one worker. E2E TypeScript check passed. Changed TypeScript/TSX ESLint, token audit (1,097 files), layout audit (14 changed source files), `git diff --check`, and Vite production build passed.
- Full SPA typecheck was rerun: it reports only existing unrelated failures in exports, missing `lucide-react`, Action Center, stock transfers, purchase-order duplicate props, and profile indexing. No errors point to this foundation diff.
- API cleanup PR #460 also merged to `main`. Its full API workflow was canceled after 48 minutes without a PHPUnit result. PR #356 and #360 were merged with checks pending after CI/CD verification was deprioritized. No migrations were run against the application database; the user-preferences migration is present in source only.

## Whole-project coverage

- Static inventory: 360 unique explicit route paths in 24 feature route files plus the `App.tsx` catch-all `*`, totaling 361 entries.
- Runtime layout coverage beyond the three pilots: the four Production Work Orders routes now render with mocked production-role fixtures in Light and Dark, at 375, 768, 1024, 1280, 1440, 1920 and 2560px. The 0/1/10/50/500-row list states are captured in both themes at 1440px. Other routes, per-role content, nested tabs, modal/drawer open states, floor shell, print states, keyboard navigation, and axe checks remain open.
- The 17 configured role definitions are listed, but no complete role walkthrough has run. Portal sessions remain distinct from the office shell.
- Static button review found shared `Button` variants use theme tokens. `secondary` and `ghost` are deliberately transparent with themed text/borders; primary and danger carry fills. Page-authored native buttons and all route-level button states remain visually unclassified. Do not convert every transparent secondary/ghost button into a filled action; review action hierarchy per module.
- Follow-up source scan assigned semantic filled variants to identified primary/destructive actions and semantic chips to plain status values across the modules listed in [`FINDINGS.md`](FINDINGS.md#f-004--action-buttons-and-status-labels-lacked-semantic-surfaces). Only `/production/work-orders` was rendered for this pass, in Light/Dark at 375px and 1440px. This does not establish route-wide visual coverage or prove every unlabeled/native button and status display was classified.
- Date/number status and remaining gaps are in [`FINDINGS.md`](FINDINGS.md). `formatDateTime` is Manila-aware; `formatTime` remains browser-local for Date/ISO input. Money is two decimals, quantities up to three, and unit cost four; formatter edge cases still need broader coverage.

## Known pilot risks

- Density and visible-column choices now save to the authenticated user's preferences. Existing unscoped browser preferences are not imported because they cannot be safely assigned to an account on shared terminals.
- Sidebar Expand all now persists per authenticated user. The established narrow-screen sidebar rail behavior remains unchanged.
- Compact is now the default across shared DataTable and table-cell helpers: 36px office, 44px Floor; Comfortable 48px and Spacious 56px remain available in DataTable. Row stripe colors use the existing Light/Dark/Floor tokens.
- Phase 3 focused SPA verification: 4 files, 40 tests passed. Authenticated preferences API test: 2 tests / 9 assertions passed in an isolated copy of the running API container. Light/Dark Stock Adjustments browser audit: 14 serial route/theme/viewport captures; all strict checks passed with 50 rows.
- Whole-project TypeScript check remains blocked by existing errors outside changed files: missing `lucide-react`/exports exports, stock-transfer API export, ActionCenter type, duplicate `type` attributes, and profile null-index errors. No reported type errors point to this preference/table diff.
- Floor palette is not covered by the light/dark captures.
- Six small action controls on the Work Order detail sample are below 44px at 375px; this page has not received the layout migration. Resolve touch targets in its module rollout.
- A source scan across SPA TSX found zero `<Button type="submit">` instances without an explicit variant. Non-submit actions, native buttons, remaining module action hierarchy, and route-level appearance still need visual review.
- The Production Work Orders “Produce for stock” action now uses the existing primary fill in both themes and has a 44px minimum target at 375px. Additional identified submit/approval/destructive actions and unbadged statuses received semantic variants; see F-004. Transparent secondary/ghost controls remain intentional.
- Button/status capture reports are in `screenshots/{before,after}/button-status/`. Changed SPA sources pass focused ESLint; `git diff --check` passes. Vite production build passes (21.35s). Focused verification passes 6 files / 66 tests. Full SPA suite: 198 files passed, 1 failed; 1,237 tests passed, 3 failed. All 3 failures are `src/api/exports.test.ts` because `buildExportUrl` is not exported; the same failures reproduce on clean `main`. No layout failures surfaced.
- Existing user changes were present in the workspace before this layout work. Preserve unrelated files and changes.

## Shared table sizing unit (in review preparation)

- `DataTable` now supports bounded pointer and keyboard column resizing, per-user persisted widths, and reset widths while preserving density/visibility preferences. Widths live in the existing `table_preferences` JSON; no schema migration or application data operation was made.
- Focused SPA tests cover resize/reset, page-authored minimum-width overrides, clamping stale saved widths, and user-scoped persistence (2 files, 9 tests passed). The Playwright layout audit passed for Stock Adjustments and Production Work Orders in Light and Dark at seven widths; one resize interaction measured 128px → 176px and reset successfully. The mobile reset target measured at least 44px at 375px. Serial production-role Work Orders captures also passed.
- Before/after captures and reports are under `screenshots/{before,after}/table-column-sizing/`. All 28 strict captures passed across two routes, two themes and seven widths. Resize/reset interaction passed at 375px and 1440px. Changed-file ESLint, layout discipline audit, `git diff --check`, and Vite production build passed. Build output retains existing third-party annotation and chunk warnings. The Laravel preference test is authored but could not run locally because PHP and API containers are unavailable.
- `/tmp` filled during one rerun; redirecting Playwright temp files to `/var/tmp` restored the full capture pass. Stale-width clamping remains component-tested without a matching browser fixture.

## Phase 4: Production Work Orders

- Migrated `/production/work-orders`, `/production/work-orders/create`, `/production/work-orders/:id`, and `/production/work-orders/:id/record-output` to the shared page shell. Header, filters, attention section, table, and pagination share the shell's responsive gutter; detail alerts, tabs, and content no longer double-pad the shell.
- The list now bounds/truncates long work-order, product, sales-order, and machine identifiers with native titles; numeric target/progress remain right-aligned with tabular figures. The Work Order column stays pinned while the rest of the table scrolls inside its border. This changes presentation only; filtering, sorting, pagination, selection, row navigation, and row actions are unchanged.
- Column defaults are sized per this route. Shared drag-resize/reset-to-default is implemented in the separate table sizing unit above; it is not merged yet.
- Baseline and after captures include four routes × seven widths × two themes. After reports show zero strict findings, zero page overflow, zero double vertical scroll, all block edges aligned within 1px, no sampled contrast below 4.5:1, and touch controls >=44px through 1024px. At 1440px the page gutter grew from 20px to 32px. The 500-row fixture renders 500 rows; Compact row height is a stable 69.25px with the multi-line product and status content, and the sticky header remains inside the table scroller.
- The 0-row state renders the intentional empty state; 1, 10, 50 and 500 rows were captured in both themes. At 1920px and wider all columns fit without page overflow; at narrower widths any horizontal movement remains inside the table container.
- Verification: changed-file ESLint, layout discipline audit, and 48 focused create/detail/record-output tests pass. Browser fixture and screenshots use local mocks; no application database was changed or migrated. Impeccable detector returned no findings. Full SPA suite, axe, actual role walkthrough, real production data/crew state, and all other modules remain unchecked.
- Screenshots and machine-readable reports: [`screenshots/before/production-work-orders/`](screenshots/before/production-work-orders/) and [`screenshots/after/production-work-orders/`](screenshots/after/production-work-orders/).

## Next action

After the shared table sizing unit is merged, continue Phase 4 with the next highest-severity module. The current merged work does not prove all 361 routes. Full route rendering, per-role walkthroughs, modal/drawer states, print views, axe, and performance checks remain open.
