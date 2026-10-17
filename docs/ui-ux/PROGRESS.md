# OGAMI ERP — UI/UX Programme Progress

Single source of truth. A fresh session should be able to resume from this file alone.

**Current state: Phases 0–2 complete. Wave 1 PR 1 (shell/dashboard defects) shipped and verified; every finding from the shell pass is now closed — F-007/F-013/F-014 the same day, and F-009 + F-010 (the width pass) in the unit below. There is no route in the compared set with a horizontal page scroll and none with unintended overflow. W1.4 quality gates are built, enforced, and green locally — axe (7 surfaces + negative control, the gallery among them), bundle budget (5 measured budgets), e2e typecheck. W1.2 shipped: the shared component kit now has a gallery page and 16 committed visual baselines, both in CI.**

- Branch: `fix/ui-ux-wave1-shell` (Wave 1 so far, uncommitted at the time of writing)
- Date: 2026-10-13
- Decisions taken on the proposed defaults: **Wave 1 only** · **axe approved** · **docs get their own commit**
- Next unit: **W1.3** (the phone action surface), then **W1.5** (chart palette tokens + per-widget export) — the plan's Wave 1 remainder. The F-009/F-010 work below sat outside the W1.x numbering because both are findings, not planned work. **W1.4 was done early** so every later wave is measured by a gate rather than asserted; **W1.2 is now closed** (see below).

---

## Phase status

| Phase | State | Artifact |
|---|---|---|
| 0 — Discovery & baseline | **Done** | `00-discovery.md`, `screenshots/baseline/` (51 captures) |
| 1 — Design direction | **Done** | `01-direction.md` |
| 2 — Plan | **Done, awaiting review** | `02-plan.md` |
| 3 — Design-system foundation | **In progress** | Wave 1 PR 1: `Panel.scrollBody`; both hand-rolled dashboard bars migrated to the shared `ProgressBar`. Wave 1 PR 2: the component gallery + visual baselines (W1.2) |
| 4 — Shared patterns | Not started | — |
| 5 — Module polish | Not started | — |
| 6 — Responsive / cross-device | Not started | — |
| 7 — A11y / perf / robustness | **Started** | W1.4 — `e2e/a11y.spec.ts` (6 surfaces + negative control), `audit:bundle` (5 budgets), `typecheck:e2e`; all enforced in `spa-tests.yml` |
| 8 — Verification | Not started | — |

## Inventory coverage

| Measure | Count |
|---|---|
| Route paths in the inventory | 356 |
| Roles | 17 |
| Rendered (full-role baseline) | **16 roles · 34 routes · 162 captures** (`screenshots/baseline-roles`) |
| Not rendered | `maintenance_tech` only — no account with a derivable password (F-008) |
| Explicitly deferred | 336 paths, 13 roles — see `02-plan.md` § 5 wave order |

## Patterns

| Pattern | State |
|---|---|
| Tokens / palette | Established, CI-enforced (`audit:tokens` clean, 1043 files) |
| Shared component kit | Exists (60 components, 70 files); needs gallery + missing variants |
| DataTable | Standardised (119 uses); needs sticky/virtual/saved-views pass |
| Modal / BottomSheet | **Imbalanced** (212 vs 3) — Wave 1 PR 3 |
| Empty/error/stale states | Primitives exist; per-route coverage unverified |
| Permission-aware UI | Single `usePermission()`; 1 open mismatch (M1) |
| Command palette | Exists, shares sidebar visibility |

## Tooling added by this programme

- `spa/scripts/ui-ux-baseline-capture.mjs` — real sign-in, 3 viewports, PNG + JSON report.
  ```
  cd spa && node scripts/ui-ux-baseline-capture.mjs --role admin --label baseline
  ```
  Output: `docs/ui-ux/screenshots/<label>/<role>/`
- Flags are **space-separated** (`--role admin`, never `--role=admin`). C-003: the harness used
  to ignore the `=` form *silently* and capture the default role instead; it now throws, and it
  prints the resolved role, label and output path **before** the first capture.
- The captured origin **is the working tree**: the `spa` service runs `vite dev` with `./spa`
  bind-mounted (`docker-compose.yml`), so a capture reflects uncommitted edits with no rebuild
  step. That allows a true A/B — `git stash push -- <files>` for the "before" side, capture,
  `git stash pop`, capture again — which is how F-010's fix was proven (the stashed run
  re-measured the baseline numbers *exactly*).
- Each capture's JSON now records `overflowing` (in-flow overflow, every entry **naming its
  enclosing Panel**), `a11y` (Chromium's accessibility tree), `counts`, and `panels`
  (panel-header height + y). Compare geometry by **title + height + y, never width** — a live
  count in `meta` moves the width between two captures of identical code.
- Background sweeps: killing the wrapper shell does **not** kill the `node` child. An orphaned
  sweep kept writing captures — and interleaving NUL-padded output into its log — after its
  parent was reported gone. Check `ps -eo pid,cmd | grep [u]i-ux-baseline-capture` before
  trusting a "finished" sweep.
- `spa/e2e/a11y.spec.ts` — the axe gate (`npm run test:a11y`, 8 tests, ~28s).
  ```
  cd spa && npm run test:a11y
  ```
  Seven surfaces (sign-in, admin dashboard, employee table, QC list, QC form, floor tablet, the
  component gallery) plus a **negative control** that plants a nameless button and requires axe to
  see it — without it, a
  rule-less or mis-tagged run reports every page clean, which is the C-001…C-003 failure shape.
  `critical`/`serious` fail the run; `moderate`/`minor` are attached and printed.
  `INTENTIONAL_EXCLUSIONS` is empty, and a test refuses an exclusion without a stated reason.
  Enforced in CI (firefox — the config's default project).
- `spa/scripts/check-bundle-budget.mjs` — gzipped-size budgets over the build artifact
  (`npm run audit:bundle`; budgets and each number's reasoning in `scripts/bundle-budget.json`).
  It fails in **three** cases, not one: no build to measure, a measured metric with no budget,
  and a budget exceeded. Enforced in CI **after** `npm run build`.
- `npm run typecheck:e2e` — `tsconfig.e2e.json` extends the base config over `e2e/` and
  `playwright.config.ts`. Before this, `npm run typecheck` covered only `src/` and
  `vite.config.ts`, so all 51 Playwright specs were uncompiled (F-012).
- `spa/e2e/gallery.spec.ts` + `/admin/component-gallery` — the shared kit's visual
  baselines (`npm run test:gallery`, 16 PNGs + an overflow assertion + a negative
  control). Re-baseline with `npm run test:gallery:update`, then review the diff.
  Enforced in CI (`gallery` job).

## Wave 1 PR 1 — shipped and measured

| Defect | Fix | Proof (unfixed → fixed) |
|---|---|---|
| F-002 `w-12` label overflow | `min-w-20` (`admin.tsx`) | ✓ /dashboard `10 → 0` overflow ×3 viewports |
| F-003 unclamped progress bars | use shared `ProgressBar` (`quality.tsx`, 2 bars) | ✓ qc /dashboard `3 → 0` mobile, `1 → 0` tablet/desktop |
| F-004 Panel clipped tables | opt-in `scrollBody` prop (`Panel.tsx`) | ✓ the `357>333` element is gone; 5 rows still render |
| (withdrawn) unnamed a11y elements | none — probe defect | ✓ Chromium AX tree: **0** unnamed |
| F-006 hand-rolled bars ×7 | `clampPercent` in `lib/progress.ts`; `ProgressBar` uses it | ✓ 3 clamped, 4 proven safe; overflow `0 → 0`, DOM counts identical |
| F-010 Panel-clipping class (F-004's) | `scrollBody` opt-in at 3 panels (`purchasing.tsx` ×2, `finance.tsx`); the 4th is a **header**, fixed in the width pass below | ✓ purchasing `/dashboard@375` `4 → 0`; finance `3 → 1`; round-trip verified |

Checks run: `npm run typecheck` (exit 0) · `eslint` on the 3 changed files (exit 0) ·
`vitest run src/components/dashboard src/components/ui` (**21 files / 89 tests passed**).
Captures: `screenshots/before2` (unfixed, corrected probe) vs `screenshots/after` (fixed,
corrected probe) — same probe both sides, **NO REGRESSIONS**, data rows unchanged.

## Wave 1 PR 4 — W1.4 quality gates (built and measured)

| Gate | Command | Result today | Enforcement |
|---|---|---|---|
| Accessibility — axe-core, WCAG 2.2 A/AA | `npm run test:a11y` | **9 passed (~46s)** · 7 surfaces (the gallery joined in W1.2) · **0 violations at any impact level** | CI job `a11y` (firefox) |
| Bundle budget (gzip) | `npm run audit:bundle` | **all 5 budgets hold** — initialJs 184.8/205 · entryJs 83.3/95 · totalJs 1686.1/1900 (579 chunks) · largestJs 123.8/140 · totalCss 27.4/32 kB | CI, after `npm run build` |
| E2E spec types | `npm run typecheck:e2e` | **0 errors** (8 on the day it was added — F-012) | CI, beside `typecheck` |
| Token discipline | `npm run audit:tokens` | clean — 1044 files | already in CI |

Every budget number was **measured on the 2026-10-13 build before it was written**, with 10–14%
headroom, and each one names the mistake it exists to catch (`bundle-budget.json`). The checker's
three failure paths were each exercised: missing `dist/`, a metric with no budget, and a budget
exceeded (the latter two exit 1 with the table printed).

The axe gate earned its keep on its first run — it found **F-011** (two WCAG 2.2 AA failures on
`/sign-in`, one of them a 6px tap target), fixed in this unit. It cannot be fooled by a
mis-configured run: the negative control fails if axe misses a planted violation.

Checks run: `typecheck` **PASS** · `typecheck:e2e` **PASS** · `audit:tokens` **clean** ·
`test:a11y` **8 passed** · `audit:bundle` **5/5 hold**. At the time, `lint` and `vitest` were **red
on committed code outside this PR** — F-014 and F-013, both reproduced with this PR's changes
stashed. Both were fixed the same day (see **Known gaps**), after which `lint` exits 0 and the
full suite is green.

## Wave 1 — F-009 + F-010 width pass (the two remaining shell findings)

Both defects are width contracts on **shared primitives**, so neither was fixed on the page that
reported it. Evidence is the Phase 0 harness as an A/B: `screenshots/w2-ctl` (the three edits
stashed) vs `screenshots/w2-after` (applied), **same probe, same accounts, same day**, 8 roles ×
1–3 routes × 3 viewports = 36 rows / 264 panel instances (3 rows differ, all improvements).

| Defect | Fix | Proof |
|---|---|---|
| **F-009** `/crm/sales-orders@375` — the only page scroll in 162 captures; the KPI figure (`175px` of monospace in a `132px` slot) painted out of its box | `StatCard` figure sizes against **its own card**: `@container` + `text-[clamp(1rem,12cqw,1.625rem)]`, `overflow-wrap: anywhere` as the backstop | ✓ page scroll **`true → false`**; figure `175>132 → 132=132`; 26px kept wherever the card has room (768/1440) |
| **F-009** second site: `/accounting/invoices@375` figure `143>132` | same component change, no page edit | ✓ overflow **`1 → 0`** |
| **F-010** `/budgeting@375` panel **and** header `411` inside a `333px` panel (options clipped, no scrollbar) | `SegmentedControl` narrow mode (`max-w-full` + `flex-wrap` + `overflow-x-auto` + `shrink-0 whitespace-nowrap`) **and** `Panel` header `min-w-0` on both flex links | ✓ `333 = 333` at 375/768/1440; `optionsOutsideBox: 0`; control wraps to 2 rows in a 58px box |

**No collateral movement:** 33 of 36 rows byte-identical; **263 of 264** panel instances keep their
exact height. The single change is the intended one — `/budgeting@375` "Budgets" header
**55 → 83px**, the room six options need to stay visible. No row gained overflow and no new page
scroll appeared anywhere in the set.

**Every `y` shift is accounted for** rather than waved at: the QC dashboard (38px at 375) and
`/budgeting@768` (45px) move because the KPI figures step down — at 768 four `₱ 1.23M` figures were
each wrapping to **two lines** (figure height 65px) in a 110px slot, and now sit on one (20px).
The admin dashboard's shifts did **not** reproduce when both sides were re-captured minutes apart
(main 2308px in both, figures 26px in both): data, not layout. All four are recorded in
`FINDINGS.md` F-009/F-010 with their numbers.

Not fixed, and explicitly out of this unit: `finance/dashboard`'s recharts `<text> (64>52)` —
present in **both** sides of the A/B, so the earlier reading of it as gone was data drift.

**Regression guard:** `spa/src/components/ui/__tests__/narrow-width.test.ts` (8 tests) locks the
source decisions. This defect class is invisible to jsdom — no layout engine — which is why a
render test would not have caught it; the file asserts the classes that fix it, like
`styles/__tests__/blueprint-tokens.test.ts` asserts a token contract. **6 of the 8 fail against
the pre-fix components** (negative control: the three files stashed, the test file kept).

Checks run: `vitest` **178 files / 1042 tests passed** (was 177/1034) · `lint` **exit 0** ·
`typecheck` **exit 0** · `audit:tokens` **clean, 1045 files** · `test:a11y` **8 passed** ·
`audit:bundle` **5/5 hold** (`npm run build` after the change; the four new declarations —
`clamp(…cqw…)`, `container-type`, `overflow-wrap`, `flex-wrap` — are present in the built CSS,
so dev and production carry the same rules).

One test needed updating rather than the fix backing down
(`pages/maintenance/work-orders/index.test.tsx`): it located the two figures by `.text-2xl`,
which is exactly the class that is now responsive. It now reads them inside their labelled card
(`within(...)`) — class-independent **and** a stronger assertion, since it also proves the number
landed in the right card.

## Wave 1 PR 2 — W1.2 component gallery + visual baselines

**The gap:** ~60 shared components in `components/ui/`, and no way to look at one
without finding a page that uses it. Two consequences: a component's *states*
(focused, disabled, error, empty) were reviewed only if some page happened to
render them, and a visual regression on a primitive had no check of its own — it
surfaced as "this page looks off" weeks later.

**The unit:**

| Piece | What it is |
|---|---|
| `spa/src/pages/admin/component-gallery/index.tsx` | `/admin/component-gallery` — 9 sections, ~45 components, every state given its own tile. Literal fixtures only: no query, no clock, no random id. `Administration → Component Gallery` in the sidebar, `admin.settings.manage`, lazy-loaded (the chunk costs the app nothing until opened) |
| `spa/e2e/gallery.spec.ts` | 8 sections × 2 viewports = **16 committed PNGs** in `spa/e2e/__screenshots__/gallery.spec.ts/`, plus a page-level **horizontal-overflow** assertion at 390 and a **negative control** |
| `spa/src/pages/admin/component-gallery/index.test.tsx` | 5 tests: every section exists with its baseline id, overlays are excluded (a portal renders outside the section box), the F-009/F-010 preview cases stay, the page stays deterministic (no `Date.now`/`Math.random`/`useQuery`), and no icon-only control is nameless |
| `spa/e2e/a11y.spec.ts` | the gallery joins the axe gate as its 7th surface — the one place the kit is audited in states no single page exercises |
| `spa-tests.yml` | new `gallery` job (Firefox, same base the PNGs were generated on); uploads `-actual`/`-diff` images on failure |

**No snapshot flake, by construction:** `snapshotPathTemplate` drops Playwright's
default browser/platform suffix, so exactly one reviewed file per shot exists;
the topbar (`header.sticky`) and sidebar (`aside`) are `visibility: hidden` during
capture (an element screenshot paints the page region, and a sticky bar would
cover the top of a scrolled section); fonts are awaited before every shot;
`maxDiffPixelRatio: 0.002` absorbs antialiasing and nothing else.

**The gate is proven, not asserted.** Two independent controls:

1. **In the spec:** a planted 40px indent on the buttons section must produce a
   difference — if it stops, the comparison is dead and the test says so.
2. **Against real code:** `StatCard`'s base padding moved one step (`p-3.5` →
   `p-4`). The panels baseline failed: **expected 1040×780, received 1040×819 —
   7,937 pixels (1% of the image) different**, five times the tolerance. Restored,
   re-ran green. That is the class of change the suite exists to catch.

Checks run: `test:gallery` **18 passed** · `test:a11y` **9 passed** (was 8) ·
`vitest` **179 files / 1047 tests** (was 178/1042) · `lint` **exit 0** ·
`typecheck` **exit 0** · `typecheck:e2e` **exit 0** · `audit:tokens` **clean, 1047
files** · `build` **ok** · `audit:bundle` **5/5 hold** (totalJs 1696.4/1900 — the
16th lazy chunk, +10.3 kB gzip, is the gallery itself).

**Not claimed:** the baselines are Firefox/Linux; a Mac or a WebKit run is a
re-baseline, not a pass. Overlays and tooltips are deliberately not photographed
(portal + hover), so their appearance is reviewed by hand.

## Full-role baseline (label `baseline-roles`)

162 captures · 16 roles · 34 routes · 3 viewports. Corrected probe throughout.

| Metric | Result |
|---|---|
| Non-200 responses | **0** |
| Interactive elements with no accessible name | **0** |
| Captures with a horizontal page scroll | **1** — sales `/crm/sales-orders@375` (F-009, **resolved**; page scroll **0** after the fix) |
| Captures with unintended overflow | **8** — finance ×6, purchasing ×1, sales ×1 (F-010; **0** on every route re-measured after the width pass, except the recharts `<text>` noted in F-010) |

Admin and QC dashboards read **clean**, which is W1.1 and F-006 holding up under the corrected
probe at all three viewports.

**Next unit:** W1.3 (phone action surface), then W1.5 (chart palette tokens + per-widget
export). W1.2 shipped above. Both shell findings that were still open when this baseline was
taken — F-009 and the `/budgeting` header — are closed above.

## Known gaps / blockers

- **F-001** — any `127.0.0.1` origin 419s on sign-in (`SANCTUM_STATEFUL_DOMAINS` pins the
  session to `APP_URL`). Capture must use the `APP_URL` origin.
- **F-007 / F-013 / F-014 — all three resolved 2026-10-13**, the day they were filed. Details and
  proofs in `FINDINGS.md`; the short version:
  - **F-007:** `maintenance.parts_handed_over` added to `BY_TYPE` (`LuPackageCheck`, system group).
  - **F-013:** six production work-order tests hardcoded Manila days that had since passed — now
    derived from the clock; the CRM price-agreement Archive gate used the *viewer's* zone — now
    `manilaIsoDate()`, matching the backend, and the test **fails against the old code**.
  - **F-014:** the five `toISOString()` sites in return-management now use `manilaIsoDate()`.
  - Result: `npm run lint` **exit 0**; suite **177 files / 1034 tests passed**; `typecheck`,
    `typecheck:e2e`, `audit:tokens`, `test:a11y` (8) and `audit:bundle` (5/5) all green.
- **F-009 / F-010 — resolved 2026-10-13**, the second unit of the day (the width pass above).
  The only part of either still open is F-010's `finance/dashboard` recharts `<text> (64>52)`,
  which reproduces identically in the control and the fixed capture.
- **F-005** — QC coverage `pct` can exceed 100 (two different quantities in the ratio); needs
  a business definition of the denominator before it can be fixed.
- **Drift, not credited:** the width pass settled two earlier readings as data rather than code —
  `/accounting/invoices@375` and `/accounting/bills@375` `1 → 0` (no change to those pages then,
  and none now), and `finance/dashboard`'s recharts text appearing to clear on one capture. Both
  are in the A/B above with the same numbers on both sides, which is why they are not claimed as
  fixes.
- **Not measured:** Lighthouse, LCP/INP/CLS, full-suite duration (axe and bundle size are now
  gated — see Wave 1 PR 4).
- **Tier B/C findings** (approval board-blindness, notification gaps, index debt,
  uncached hot paths) come from `docs/dashboards/phase0/*` and were not re-verified here.

## Prior art to consume, not duplicate

- `docs/dashboards/` — six-section Phase 0 (`phase0/A..F`), `FINDINGS.md`, `METRICS.md`,
  `01-matrix.md`. Dashboards are coordinate-and-polish, not greenfield.
- `docs/DESIGN-SYSTEM.md`, `docs/PATTERNS.md`, `PRODUCT.md`
- `docs/SYSTEM-IMPROVEMENT-ROADMAP-2026-08-13.md`, `docs/e2e-audit.md`,
  `docs/AUTO-BROWSER-TESTS.md`, `docs/QA-MATRIX.md`
- Existing audits: `spa/scripts/*.mjs`, `scripts/*.js|mjs`

## Resume instructions

1. Read this file, then `02-plan.md` § 9.
2. Do not start Wave 1 until Q1/Q3 are answered.
3. Keep one PR per unit; re-run the capture harness before/after and update this file.
