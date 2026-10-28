# OGAMI layout programme progress

Resume from this file. The programme has a review gate after the three-route pilot.

## Current state

| Phase | Status | Evidence |
|---|---|---|
| 0 — Audit | Static architecture, route, and surface inventory complete. Full runtime inspection of every route is still open. | [`00-audit.md`](00-audit.md), 361-row `route-inventory.csv`, 361-row `page-issue-matrix.csv`, `surface-inventory.csv`, baseline screenshots. |
| 1 — Findings | Findings documented for the static inventory and the rendered Stock Adjustments baseline. Other routes are explicitly unmeasured. | [`01-findings.md`](01-findings.md). |
| 2 — Spec/pilot | Three pilot routes plus one Work Order link-color sample implemented and visually inspected. Awaiting user review. | [`02-spec.md`](02-spec.md), [`00-audit.md`](00-audit.md), after screenshots/reports. |
| 3 — Foundation rollout | Not started. Wait for pilot review. | — |
| 4 — Page rollout | Not started. Wait for pilot review. | — |
| 5 — Verification | Pilot browser/layout checks completed; automated application tests, accessibility, performance, and full role walkthrough are not yet recorded here. | `spa/scripts/layout-audit.mjs`; reports under `screenshots/after/pilot-final/` and `screenshots/states/`. |

**Review gate:** present the pilot evidence and collect approval before beginning Phase 3/4. Do not treat pilot metrics as proof for all routes.

## Pilot coverage

- Layout routes: `/inventory/stock-adjustments`, `/inventory/stock-adjustments/create`, `/inventory/items/:id`.
- Each route rendered in light and dark at 375, 768, 1024, 1280, 1440, 1920, and 2560px. Browser runs were serial. The list used 50 synthetic rows at all widths.
- Stock list stress states: 0, 1, 10, and 500 synthetic rows in both themes at 1440px; 50 rows also covered at all seven widths. The local admin list rendered one real row in the baseline at 1440px.
- Dark native dropdown was captured on the create form. Work Order detail with five synthetic linked IDs was separately rendered dark at 375 and 1440px to verify link color.
- Pilot strict measurement summaries report zero findings. They check content edges, sidebar clearance, page overflow, scroll behavior, table/type/contrast samples, dark color-scheme, dropdown colors, and linked-ID visibility. A zero pilot score is not a whole-project result.
- 500-row fixture produced 500 DOM rows and no page-level horizontal overflow. Runtime performance was not timed.

## Whole-project coverage

- Static inventory: 360 unique explicit route paths in 24 feature route files plus the `App.tsx` catch-all `*`, totaling 361 entries.
- Runtime layout coverage beyond the three pilots: only the dark Work Order link-color sample. The other routes, per-role content, nested tabs, modal/drawer open states, floor shell, print states, keyboard navigation, and axe checks remain open.
- The 17 configured role definitions are listed, but no complete role walkthrough has run. Portal sessions remain distinct from the office shell.
- Static button review found shared `Button` variants use theme tokens. `secondary` and `ghost` are deliberately transparent with themed text/borders; primary and danger carry fills. Page-authored native buttons and all route-level button states remain visually unclassified. Do not convert every transparent secondary/ghost button into a filled action; review action hierarchy per module.
- Follow-up source scan assigned semantic filled variants to identified primary/destructive actions and semantic chips to plain status values across the modules listed in [`FINDINGS.md`](FINDINGS.md#f-004--action-buttons-and-status-labels-lacked-semantic-surfaces). Only `/production/work-orders` was rendered for this pass, in Light/Dark at 375px and 1440px. This does not establish route-wide visual coverage or prove every unlabeled/native button and status display was classified.
- Date/number status and remaining gaps are in [`FINDINGS.md`](FINDINGS.md). `formatDateTime` is Manila-aware; `formatTime` remains browser-local for Date/ISO input. Money is two decimals, quantities up to three, and unit cost four; formatter edge cases still need broader coverage.

## Known pilot risks

- Global per-table density is still stored in browser `localStorage`, not scoped to an authenticated user. Decide ownership before rollout.
- Floor palette is not covered by the light/dark captures.
- Six small action controls on the Work Order detail sample are below 44px at 375px; this page has not received the layout migration. Resolve touch targets in its module rollout.
- A source scan across SPA TSX found zero `<Button type="submit">` instances without an explicit variant. Non-submit actions, native buttons, remaining module action hierarchy, and route-level appearance still need visual review.
- The Production Work Orders “Produce for stock” action now uses the existing primary fill in both themes and has a 44px minimum target at 375px. Additional identified submit/approval/destructive actions and unbadged statuses received semantic variants; see F-004. Transparent secondary/ghost controls remain intentional.
- Button/status capture reports are in `screenshots/{before,after}/button-status/`. Changed SPA sources pass focused ESLint; `git diff --check` passes. Vite production build passes (23.49s). Focused verification passes 5 files / 64 tests, including updated timezone/token expectations. The last full suite run had 4 failing files / 7 failed tests before those expectation fixes; the product-detail proposal-flow test still fails, and 3 export API failures were unrelated. No full suite has run after the expectation fixes.
- Existing user changes were present in the workspace before this layout work. Preserve unrelated files and changes.

## Next action

Review [`02-spec.md`](02-spec.md), the screenshots and reports under [`screenshots/after/pilot-final/`](screenshots/after/pilot-final/), and the unresolved choices. Once approved, begin Phase 3 as a separate unit of work, then migrate modules in traffic × severity order with per-module evidence.
