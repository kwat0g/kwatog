# OGAMI ERP — UI/UX Execution Plan (Phase 2)

**STOP POINT.** No UI code has been changed. On disk for review:
[`00-discovery.md`](00-discovery.md) · [`01-direction.md`](01-direction.md) · this file ·
[`PROGRESS.md`](PROGRESS.md) · [`FINDINGS.md`](FINDINGS.md).

Evidence tiers, because "we measured it" and "another document says so" are not the same
claim:

- **A — measured in Phase 0** against the running app or the tree, with a reproducible command.
- **B — documented by prior work** (`docs/dashboards/phase0/*`, `docs/e2e-audit.md`,
  `docs/SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md`), not re-verified in this phase.
- **C — hypothesis from structure**, needs rendering to confirm.

---

## 1. Findings — top 20 by user impact

| # | Issue | Tier | Evidence | Why it matters |
|---|---|---|---|---|
| 1 | **Fixed — fixed-width slots could not hold their content** — `<span class="… w-12">` measured 78px of text in a 48px box; **10 → 0 after the fix** | A | `FINDINGS.md → F-002` | Dashboard labels collided with neighbours on every dashboard. Fixed in `admin.tsx` (`min-w-20`) |
| 2 | **Fixed — a progress track whose fill was ≈2× the track** (`1276>638`, `1080>540`); **1 → 0 after the fix** | A | `FINDINGS.md → F-003` | A bar reading >100% is a wrong number on a screen people trust. Page now uses the shared clamping `ProgressBar`; the underlying 200% value is F-005 |
| 3 | **Fixed — Panel clipped a table with no way to scroll** (`357>333` at 375px); gone after the fix | A | `FINDINGS.md → F-004` | Silent data loss on the smallest screen. `Panel` gained an opt-in `scrollBody` |
| 4 | **~~20 interactive elements with no accessible name~~ — WITHDRAWN, probe defect.** Chromium's accessibility tree reports **0** on the same pages/viewports | A | `FINDINGS.md → C-001` | Nothing to fix. Kept in the list so the correction is visible, not quietly deleted |
| 5 | **Phone actions are the thinnest layer**: `<BottomSheet>` 3 uses vs `<Modal>` 212 | A | Phase 0 § A.4 | `vice_president` approves from a phone and `employee` self-services on one; the mobile action pattern barely exists. **Next up (W1.3)** |
| 6 | **`Modal` is the only action surface across 94 files** | A | same | Every mobile fix currently starts by converting a desktop modal. Standardise the phone path once |
| 7 | **Permission-UI mismatch M1 (finance)** — page guard `dashboard.accounting.view` vs data guard `accounting.dashboard.view` vs widget permission are three different answers | B | `docs/dashboards/phase0/A-roles-access.md` | The brief's "permission inaccuracies" is *one live* case, not a systemic one; fix and prove with a test |
| 8 | **Approval centre is board-blind for payroll + 14 custom approval types** (they write no ledger rows) | B | `docs/dashboards/phase0/C-approvals.md` | Approvers cannot see work waiting on them. Highest-impact correctness issue in the product, and it is partly backend |
| 9 | **Notification darkness** — bill payment, salary adjustment, asset disposal, budget/transfers, invoice prebill, cost review fire nothing on submit/decide | B | same | A queue nobody is told about reads as idle, not blocked |
| 10 | **No i18n at all** (`grep` for `i18next|useTranslation` → 0 files) | A | Phase 0 § B.1 | Not urgent (single locale today) but it is structural: retrofitting later costs more. Decide, don't drift |
| 11 | **`employee` session dies at 15 min with no work preservation** | C | `CLAUDE.md` session policy + `useUnsavedChangesGuard`/`useFormDraftAutosave` exist but coverage is unmeasured | Losing a half-typed form is the classic ERP grievance |
| 12 | **356 route paths, 4 roles crawled** — long tail unverified (hr 36, accounting 32, inventory 28, crm 23, quality 21) | A | Phase 0 § A.2 + capture inventory | Coverage honesty: nothing can be called "done" that was never rendered |
| 13 | **Raw-ID FK inputs unverified** | C | Phase 0 § A.7 | `SearchableSelect` exists; whether every FK uses it is unmeasured |
| 14 | **Categorical chart colour does not exist**; dashboards risk inventing it | B | `docs/dashboards/phase0/E-frontend.md` | Colourblind-safe `--chart-1..8` per palette; tokens only |
| 15 | **No per-widget/chart export**; server CSV/XLSX and doc PDFs exist, dashboard export absent | B | same | Executives screenshot dashboards; they should not have to |
| 16 | **Uncached hot paths**: widget-data + rich dashboard payloads recompute live on every 30–60s poll (R15), plus R1–R14 query risks | B | `docs/dashboards/phase0/F-realtime-perf.md` | Load scales with users × keys; felt as slow tiles |
| 17 | **Index debt on fact tables** (returns, budgets, `wo_operations`, `production_logs`, PR/PO dates, delivery actuals, `grn_items`) | B | `docs/dashboards/phase0/B-modules-data.md` | Aggregating before indexing makes every dashboard slower, not faster |
| 18 | **KPI snapshots are monthly-only** (`kpi:compute-monthly`); sub-month widgets read source tables | B | same | A "missing" KPI early in the month is expected — must be explained in UI, not left as an empty tile |
| 19 | **Landing/dashboards carry zero bundle budget** — no measured LCP/INP/CLS, no size ceiling | A | Phase 0 § C.4 | "Fast" is currently an aspiration; `manualChunks` splits exist but are unenforced |
| 20 | **No component gallery**; 60 shared components and their states cannot be reviewed in isolation | A | `spa/src/components/dev/` holds only `DevErrorPanel` | Blocks safe refactoring and makes every future review a page hunt |

**Progress:** I1–I3 shipped in Wave 1 PR 1 (see `PROGRESS.md`). I4 was withdrawn as a probe
artifact. I5 is W1.3, the next unit of work.

Tier-C rows 11, 13 and 18's UI treatment are the ones the brief's own "verify, don't trust"
rule forbids me from asserting as defects. They are listed as *verification tasks* whose
outcome may add or remove items from this list.

## 2. Design direction and proposed system

Direction: **consolidate Atelier** — see [`01-direction.md`](01-direction.md). No second
visual language, no redesign of a system that is already documented and CI-enforced.

Design-system work is therefore **additive and enforcement-focused**:

1. `--chart-1..8` per palette (colourblind-safe, contrast-gated) + a categorical chart doc.
2. A density preference (comfortable/compact) as a token swap, persisted per user.
3. Missing variants that currently cause page-level inlining: the phone action surface
   (BottomSheet standardisation), export affordances, wizard/stepper.
4. Keep `audit:tokens` green and add: an axe gate, a bundle budget, and a saved-baseline
   visual-regression gate on the component gallery.

Component inventory to standardise is Phase 0 § B.3 — it already exists; the work is
variants + documentation + a gallery, not a rebuild.

## 3. Information architecture

The IA is already codified and enforced: every URL starts with its umbrella module slug,
sub-resources nest (`/hr/attendance/overtime`), and the sidebar's longest-prefix match
depends on it (`CLAUDE.md` → URL Routing Convention). Do **not** restructure URLs.

Proposed changes, all local:

1. **Group the sidebar by chain**, not only by module: Order-to-Cash / Procure-to-Pay /
   Hire-to-Retire, alongside the current module taxonomy. `SECTIONS` already sorts by
   section priority and `ROLE_SECTION_ORDER` already re-orders per role — this is an
   extension of a mechanism that works, not a new navigation model.
2. **Keep the "Work" group** (Dashboard, Action Center, Decision Center, Chains, Approvals,
   Notifications) as the cross-module urgent set. It is a good idea; polish it.
3. **Breadcrumbs on detail pages** — verify uniformity across the 356 paths (Tier C).
4. **Command palette (⌘K)** exists and already shares visibility logic with the sidebar;
   extend it with record search (global search) rather than adding a second search UI.
5. **Quick-create** with prefilled context ("create PO from this PR") — the brief's extra;
   check first whether `PageActionsContext` already covers it.

## 4. Pattern catalog to standardise

Each with its current-state problem (Tier A unless noted):

| Pattern | Current state | Fix once, benefit everywhere |
|---|---|---|
| DataTable | 119 uses, one primitive (good) | Sticky header/first column, saved views, density toggle, virtualisation for 500+ rows, responsive fallback (card view exists in some places only) |
| Modal / BottomSheet | 212 / 3 | Decide the rule by breakpoint, implement it in the shared layer |
| FilterBar | present, per-page config | Persist filters per user; `useUrlFilters` already exists — enforce |
| Form layout | 94 files on RHF+Zod | Error summary + jump-to-field, blur-then-change validation timing, draft autosave coverage |
| Detail page | `LinkedRecords`, `ActivityStream`, `ChainHeader` exist | Make uniform: header facts, chain stepper, approvals panel, audit trail on every detail page |
| Status chip | `lib/statusVariants.ts`, typed | Extend the typed map to every enum; never a bare string |
| Empty / error / stale | `EmptyState`, `QueryErrorState`, `ListEmptyState`, `WidgetErrorBoundary` | Audit that every one of the 356 routes actually renders all five states |
| Approval panel | exists for ledger types | Surface preconditions (budget ack, vendor SoD, PPAP, truck-return guard) and the two leave arms |
| Print / export | server PDFs + CSV exist | One export affordance in the page header, everywhere |

## 5. Module roadmap

Ordered by traffic × visibility × pain. Effort: S ≤ 1 day, M ≤ 3 days, L > 3 days.

**Wave 1 — foundation and shell (blocks everything, highest leverage)**

| # | Work | Effort | PR |
|---|---|---|---|
| W1.1 | Shell + dashboard shared fixes: clipped `w-12` slots, the 2×-wide progress track, the 375px card, accessible names | S | PR 1 |
| W1.2 | **Done** — component gallery at `/admin/component-gallery`; 16 saved visual baselines + overflow assert + negative control (`npm run test:gallery`, CI job `gallery`). Audit: `PROGRESS.md` § Wave 1 PR 2 | M | PR 2 |
| W1.3 | Phone action surface: decide and implement Modal/BottomSheet rule; convert approval + self-service paths | M | PR 3 |
| W1.4 | **Done** — axe gate (`spa/e2e/a11y.spec.ts`, 6 surfaces + negative control), bundle budget (5 measured budgets), `typecheck:e2e`; all enforced in `spa-tests.yml`. Audit: `PROGRESS.md` § Wave 1 PR 4 | S | PR 4 |
| W1.5 | Chart palette tokens + docs; per-widget export component | M | PR 5 |

**Wave 2 — chains, highest-traffic modules (one PR each)**

| # | Module | Why here | Effort |
|---|---|---|---|
| W2.1 | Quality (inspections, NCR, specs) | Thesis differentiator; tablet/glove use; 21 routes | M |
| W2.2 | Inventory + Warehouse (28 routes) | Floor terminals, scan-first, `--hit-min: 44px` | L |
| W2.3 | Purchasing + RFQ + PO (17 routes) | Money, comparison legibility, bulk line work | L |
| W2.4 | CRM / Sales Orders (23 routes) | Chain 1 entry point | M |
| W2.5 | Production + MRP (26 routes) | Gantt legibility, WO lifecycle | L |
| W2.6 | Approvals + Action Center + Notifications | Where every role spends its attention | L |
| W2.7 | Accounting + Budgeting (32 routes) | Dense finance tables; M1 permission fix | L |
| W2.8 | HR + Attendance + Leave + Loans (36+16 routes) | Largest route surface, sensitive data | L |
| W2.9 | Payroll (7 routes) | Maker-checker; safe confirmations | M |
| W2.10 | Supply Chain + Fleet + Driver + Impex | Driver PWA is phone-only | M |
| W2.11 | Maintenance (9 + 2 mobile) | Mobile-first, `BottomSheet` beneficiary | M |
| W2.12 | Assets, Forecasting, Returns | Lower traffic | M |
| W2.13 | Admin + Settings + Portals + Landing + Error pages | Neglected-but-messy set; portals have their own shells | L |

**Achievable before a deadline:** Wave 1 plus W2.1–W2.3 is a coherent, shippable story
(foundation, the thesis differentiator, and the two chain workhorses). Everything after is
additive and independently revertable.

## 6. Risk register

| Risk | Mitigation |
|---|---|
| Visual regression while consolidating | Saved component baselines (W1.2) + before/after captures per PR via the Phase 0 harness |
| Breaking behaviour | UI-only changes; no API/permission/data-flow edits. Every deviation documented in `FINDINGS.md` |
| Scope creep (356 paths × 3 viewports) | Wave-ordered; each PR names the routes it covers; uncovered routes stay labelled "not yet rendered" in `PROGRESS.md` |
| Duplicating `docs/dashboards` work | Consume those six sections as prior art; coordinate rather than rebuild dashboards |
| Accessibility regressions | axe gate in PR 4; the existing contrast test stays |
| Partial rollout inconsistency | Tokens + shared components first (Wave 1) so modules inherit the fix |
| Test-DB collisions | One suite at a time, or a private `ogami_test_*` database (per `CLAUDE.md`) |
| Churn on a branch that also carries DSS work | Document-only in Phases 0–2; Wave work gets its own branch/PR per unit |

## 7. Verification strategy and success criteria

**Every PR:** before/after captures at 375/768/1440 for the routes it touches
(`node scripts/ui-ux-baseline-capture.mjs --role <r> --label after`), the module's own
Playwright specs, `npm run typecheck`, `npm run typecheck:e2e`, `npm run audit:tokens`,
`npm run test:a11y` and `npm run audit:bundle` (the last two landed in W1.4).

**Per phase:** full Vitest suite and the relevant Playwright specs; metrics into
`docs/ui-ux/METRICS.md` alongside the Phase 0 numbers.

**Success criteria (measurable):**

| Metric | Now | Target |
|---|---|---|
| Unintended-overflow captures | 12 / 51 | 0 on covered routes |
| Unnamed interactive elements | 20 / 51 | 0 |
| Horizontal page scroll | 0 / 51 | 0 (hold) |
| Serious/critical axe violations | not measured (no axe) | 0 on covered routes |
| Bundle budget | not measured | set and enforced; landing/dashboard split verified |
| Routes rendered at 3 widths | 4 roles / 12 routes | 100% of inventory, or explicitly deferred with a reason |
| Every-FK-is-searchable audit | not run | complete, zero raw-ID inputs |
| Permission-UI mismatches | 1 open (M1) | 0, with a test per fix |

## 8. Open questions (each with a proposed default)

| # | Question | Proposed default |
|---|---|---|
| Q1 | Scope/cadence: Wave 1 only, or Wave 1 + W2.1–W2.3 in this engagement? | Wave 1, then re-review — it unblocks everything and is cheap |
| Q2 | `PRODUCT.md`: widen the existing root file, or add a separate product doc? | **Widen** the existing file (it is currently portal-only) |
| Q3 | May I add dependencies — `@axe-core/playwright`, possibly `@tanstack/react-virtual`? | Yes to axe (dev-only, testing); decide virtualisation after measuring the largest table |
| Q4 | Density: keep comfortable default, or per-user compact preference? | Per-user preference, remembered server-side |
| Q5 | Serif page titles everywhere, or landing/dashboards only? | Keep everywhere — it is the identity |
| Q6 | Do I fix functional bugs found along the way (e.g. F-001 419 on `127.0.0.1`), or only log them? | **Log only** in `FINDINGS.md`, separate PR — per the brief's separate-tracks rule |
| Q7 | Is the mock/authenticated path acceptable for verification (no production data vending machine)? | Use seeded demo accounts + `AuditE2E*` seeders; never touch production data |
| Q8 | Who signs off the top-20 ranking? | You — items 11, 13, 16, 17 are Tier B/C and may reorder once rendered |

## 9. Immediate next actions after approval

1. W1.1 (shell/dashboard defects 1–4) — smallest change, visible everywhere.
2. Capture the full per-role baseline (remaining 13 roles) so Wave 2 has before-images.
3. W1.2 gallery, then W1.4 gates, so later waves are measured rather than asserted.
