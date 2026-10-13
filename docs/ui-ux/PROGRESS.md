# OGAMI ERP — UI/UX Programme Progress

Single source of truth. A fresh session should be able to resume from this file alone.

**Current state: Phases 0–2 complete. Wave 1 PR 1 (shell/dashboard defects) shipped and verified.**

- Branch: `feat/dss-w0-6-admin` (Phase 0–2 docs + Wave 1 PR 1, uncommitted at the time of writing)
- Date: 2026-10-13
- Decisions taken on the proposed defaults: **Wave 1 only** · **axe approved** · **docs get their own commit**
- Next unit: **W1.2/W1.3** (component gallery + the phone action surface)

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
| 7 — A11y / perf / robustness | Not started | — |
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

## Wave 1 PR 1 — shipped and measured

| Defect | Fix | Proof (unfixed → fixed) |
|---|---|---|
| F-002 `w-12` label overflow | `min-w-20` (`admin.tsx`) | ✓ /dashboard `10 → 0` overflow ×3 viewports |
| F-003 unclamped progress bars | use shared `ProgressBar` (`quality.tsx`, 2 bars) | ✓ qc /dashboard `3 → 0` mobile, `1 → 0` tablet/desktop |
| F-004 Panel clipped tables | opt-in `scrollBody` prop (`Panel.tsx`) | ✓ the `357>333` element is gone; 5 rows still render |
| (withdrawn) unnamed a11y elements | none — probe defect | ✓ Chromium AX tree: **0** unnamed |
| F-006 hand-rolled bars ×7 | `clampPercent` in `lib/progress.ts`; `ProgressBar` uses it | ✓ 3 clamped, 4 proven safe; overflow `0 → 0`, DOM counts identical |

Checks run: `npm run typecheck` (exit 0) · `eslint` on the 3 changed files (exit 0) ·
`vitest run src/components/dashboard src/components/ui` (**21 files / 89 tests passed**).
Captures: `screenshots/before2` (unfixed, corrected probe) vs `screenshots/after` (fixed,
corrected probe) — same probe both sides, **NO REGRESSIONS**, data rows unchanged.

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

**Next unit:** F-009 (page-level scroll, shared figure component) and F-010 (three panels opt
into the existing `scrollBody`; the budgeting header needs a different fix).

## Known gaps / blockers

- **F-001** — any `127.0.0.1` origin 419s on sign-in (`SANCTUM_STATEFUL_DOMAINS` pins the
  session to `APP_URL`). Capture must use the `APP_URL` origin.
- **F-007** — pre-existing red test: `notificationMeta.test.ts` fails on the backend type
  `maintenance.parts_handed_over` (reproduced with this PR stashed). One-line fix, own PR.
- **F-005** — QC coverage `pct` can exceed 100 (two different quantities in the ratio); needs
  a business definition of the denominator before it can be fixed.
- **Not measured:** axe/Lighthouse, bundle size, LCP/INP/CLS, full-suite duration.
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
