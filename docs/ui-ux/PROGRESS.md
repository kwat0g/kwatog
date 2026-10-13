# OGAMI ERP — UI/UX Programme Progress

Single source of truth. A fresh session should be able to resume from this file alone.

**Current state: Phases 0–2 complete. Wave 1 PR 1 (shell/dashboard defects) shipped and verified; F-010 closed at 3 of its 4 sites. W1.4 quality gates built, enforced, and green locally — axe (6 surfaces + negative control), bundle budget (5 measured budgets), e2e typecheck. The three red suites the gates surfaced (F-007, F-013, F-014) were fixed the same day: `npm run lint` is green and the full suite passes 177 files / 1034 tests.**

- Branch: `feat/dss-w0-6-admin` (Phase 0–2 docs + Wave 1 PR 1, uncommitted at the time of writing)
- Date: 2026-10-13
- Decisions taken on the proposed defaults: **Wave 1 only** · **axe approved** · **docs get their own commit**
- Next unit: **the `SegmentedControl` narrow-width mode** (the one remaining F-010 site — 340px of control in 301px of header) and **F-009** (page-level scroll, shared figure component), then **W1.2/W1.3** (component gallery + the phone action surface). **W1.4 was done early** so every later wave is measured by a gate rather than asserted.

---

## Phase status

| Phase | State | Artifact |
|---|---|---|
| 0 — Discovery & baseline | **Done** | `00-discovery.md`, `screenshots/baseline/` (51 captures) |
| 1 — Design direction | **Done** | `01-direction.md` |
| 2 — Plan | **Done, awaiting review** | `02-plan.md` |
| 3 — Design-system foundation | **Started** | Wave 1 PR 1: `Panel.scrollBody`; both hand-rolled dashboard bars migrated to the shared `ProgressBar` |
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
  Six surfaces (sign-in, admin dashboard, employee table, QC list, QC form, floor tablet) plus a
  **negative control** that plants a nameless button and requires axe to see it — without it, a
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

## Wave 1 PR 1 — shipped and measured

| Defect | Fix | Proof (unfixed → fixed) |
|---|---|---|
| F-002 `w-12` label overflow | `min-w-20` (`admin.tsx`) | ✓ /dashboard `10 → 0` overflow ×3 viewports |
| F-003 unclamped progress bars | use shared `ProgressBar` (`quality.tsx`, 2 bars) | ✓ qc /dashboard `3 → 0` mobile, `1 → 0` tablet/desktop |
| F-004 Panel clipped tables | opt-in `scrollBody` prop (`Panel.tsx`) | ✓ the `357>333` element is gone; 5 rows still render |
| (withdrawn) unnamed a11y elements | none — probe defect | ✓ Chromium AX tree: **0** unnamed |
| F-006 hand-rolled bars ×7 | `clampPercent` in `lib/progress.ts`; `ProgressBar` uses it | ✓ 3 clamped, 4 proven safe; overflow `0 → 0`, DOM counts identical |
| F-010 Panel-clipping class (F-004's) | `scrollBody` opt-in at 3 panels (`purchasing.tsx` ×2, `finance.tsx`) | ✓ purchasing `/dashboard@375` `4 → 0`; finance `3 → 1`; round-trip verified |

Checks run: `npm run typecheck` (exit 0) · `eslint` on the 3 changed files (exit 0) ·
`vitest run src/components/dashboard src/components/ui` (**21 files / 89 tests passed**).
Captures: `screenshots/before2` (unfixed, corrected probe) vs `screenshots/after` (fixed,
corrected probe) — same probe both sides, **NO REGRESSIONS**, data rows unchanged.

## Wave 1 PR 4 — W1.4 quality gates (built and measured)

| Gate | Command | Result today | Enforcement |
|---|---|---|---|
| Accessibility — axe-core, WCAG 2.2 A/AA | `npm run test:a11y` | **8 passed (~28s)** · 6 surfaces · **0 violations at any impact level** | CI job `a11y` (firefox) |
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

## Full-role baseline (label `baseline-roles`)

162 captures · 16 roles · 34 routes · 3 viewports. Corrected probe throughout.

| Metric | Result |
|---|---|
| Non-200 responses | **0** |
| Interactive elements with no accessible name | **0** |
| Captures with a horizontal page scroll | **1** — sales `/crm/sales-orders@375` (F-009) |
| Captures with unintended overflow | **8** — finance ×6, purchasing ×1, sales ×1 (F-010) |

Admin and QC dashboards read **clean**, which is W1.1 and F-006 holding up under the corrected
probe at all three viewports.

**Next unit (unchanged):** F-009 (page-level scroll, shared figure component) and the `/budgeting`
header (the one F-010 site `scrollBody` cannot fix — see F-010 for the measurement). W1.4 landed
first — see Wave 1 PR 4 above.

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
- **F-005** — QC coverage `pct` can exceed 100 (two different quantities in the ratio); needs
  a business definition of the denominator before it can be fixed.
- **Unexplained, not credited:** in the same comparison `/accounting/invoices@375` and
  `/accounting/bills@375` went `1 → 0` with no change to those pages. Neither route was part of
  the stash round-trip, so live data is the likely cause; recorded here rather than claimed as a
  fix.
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
