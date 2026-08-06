# Atelier — Frontend Brand & Visual Redesign

**Date:** 2026-08-07
**Status:** Approved design, not yet planned
**Scope:** `spa/` token + primitive layer, brand assets, hero screens, PDF templates
**Supersedes:** the grayscale/indigo/glassmorphic design language in `docs/DESIGN-SYSTEM.md` and the *Design System Quick Reference* block in `CLAUDE.md`

---

## 1. Why this shape

The request began as "rebuild the whole frontend." A scan of `spa/` argued against a rewrite.

| Stated driver | What the code shows | Conclusion |
|---|---|---|
| Code quality / consistency debt | 14 of 355 pages exceed 500 LOC (~4%). Rest follow `docs/PATTERNS.md`. | Refactor 14 files. Not a rewrite. |
| Stack is dated | Tailwind v3→v4 is a config migration; React 18→19 a version bump; shadcn/ui adopts per-file. | Incremental. Not a rewrite. |
| Looks generic / no identity | `pages/` contains **0** hardcoded palette classes and **0** `dark:` variants across 355 files, against 3,029 semantic token classes. Every colour decision resolves to `spa/src/styles/tokens.css` (232 lines). | **A token-layer reskin is the precise fix.** |
| Performance | Heavy deps are isolated: `three` 3 files, `gsap` 12, `recharts` 13, `leaflet` 1, `lenis` 1. | Lazy-load 16 files. Not a rewrite. |

The generic appearance is a 232-line file, not a 105,415-line codebase. Rewriting 105k LOC to change 232 lines is the wrong trade, and it would re-open every SPA flow currently held down by the Playwright specs, route audits and RBAC audits.

**Decision:** rebrand and redesign at the token + primitive layer. Pages, routing, guards, `api/`, `hooks/`, `stores/` and the Laravel backend are not touched.

## 2. Non-goals

- No stack change in this spec (no Tailwind v4, React 19, or shadcn/ui migration — separate spec if wanted)
- No information-architecture change: same routes, same navigation tree, same screen count
- No API, permission, or business-logic change
- No new features

## 3. Brand: Atelier

Warm paper canvas, espresso ink, clay accent. Editorial and calm rather than corporate-neutral. Chosen over three alternatives (a Stripe-lineage "Precision" and a Vercel-lineage "Kiln") for warmth and distinctiveness.

Two deliberate reversals of the current design language, both approved:

1. **Glassmorphism is dropped.** Today's surfaces are semi-transparent (`rgba(250,250,250,.85)`). Atelier surfaces are opaque. This also removes a class of layering bugs.
2. **Grayscale canvas + indigo accent is dropped.** Canvas is warm; accent is clay. Semantic hues are re-derived from the brand rather than borrowed from Tailwind defaults — the generic `#10b981 / #f59e0b / #ef4444 / #3b82f6` set is a significant part of why the UI reads as a template.

### 3.1 Type

| Role | Family | Weights | Use |
|---|---|---|---|
| Display | Instrument Serif | 400, 400 italic | Page titles, section headings, landing |
| UI / body | Public Sans | 400, 500, 600 | Everything interface |
| Mono | Spline Sans Mono | 400, 500 | IDs, money, quantities, all table numerals |

Replaces Geist + Geist Mono + Bricolage Grotesque. Self-hosted `.woff2` in `spa/public/fonts/`, `font-display: swap`, declared via `@font-face` in `tokens.css` as today. The `@fontsource-variable/bricolage-grotesque` dependency is removed.

Scale (unchanged except the top end, which the serif needs):

```
2xs 10  xs 11  sm 12  base 13  md 14  lg 16  xl 20  2xl 26  3xl 32
```

Base stays 13px — density is load-bearing for an ERP and must not regress.

### 3.2 Form language

| Aspect | Spec | Change from today |
|---|---|---|
| Radius | `sm 8` / `md 10` / `lg 14` / `full` | down from 10 / 16 / 24 |
| Elevation | Borders carry hierarchy. Shadow only for true overlays (menu, modal, toast), warm-tinted `rgba(31,27,22,…)` — never neutral black. | shadows de-emphasised |
| Density | 32px table rows in office palettes; 48px rows and 44×44 minimum hit targets in floor palette | floor is new |
| Motion | Keeps `150 / 250 / 400ms` and `cubic-bezier(.16,1,.3,1)`. State changes only, no decorative movement. | unchanged |
| Surfaces | Opaque | was translucent |

### 3.3 Palettes

Three palettes, not four. Floor is a distinct high-contrast palette rather than a light/dark × office/floor matrix, keeping authoring cost at three.

**Atelier Light — office, `[data-theme="light"]`**

| Token | Value | | Token | Value |
|---|---|---|---|---|
| `--bg-canvas` | `#FDFCFA` | | `--accent` | `#B4542A` |
| `--bg-surface` | `#F7F4EF` | | `--accent-hover` | `#96461F` |
| `--bg-elevated` | `#F2EDE4` | | `--accent-fg` | `#FDFCFA` |
| `--bg-subtle` | `#F5F1E9` | | `--success` | `#3F6D54` |
| `--bg-zebra-odd` | `transparent` | | `--success-bg` | `#E3ECE7` |
| `--bg-zebra-even` | `#F7F4EF` | | `--success-fg` | `#2C4E3B` |
| `--bg-row-hover` | `#F2EDE4` | | `--warning` | `#B07A22` |
| `--bg-thead` | `#F7F4EF` | | `--warning-bg` | `#F7EEDC` |
| `--border-subtle` | `#F0EAE0` | | `--warning-fg` | `#7A5314` |
| `--border-default` | `#E8E2D8` | | `--danger` | `#A8392F` |
| `--border-strong` | `#D6CEC1` | | `--danger-bg` | `#F6E4E1` |
| `--text-primary` | `#1F1B16` | | `--danger-fg` | `#7A2820` |
| `--text-secondary` | `#4A4239` | | `--info` | `#3D5A80` |
| `--text-muted` | `#6B6259` | | `--info-bg` | `#E3E9F0` |
| `--text-subtle` | `#8A8078` | | `--info-fg` | `#2A4059` |
| `--text-link` | `#B4542A` | | `--purple` | `#75558C` |
| `--text-link-hover` | `#96461F` | | `--purple-bg` | `#EDE7F1` |
| `--ring` | `#B4542A` | | `--purple-fg` | `#4C3862` |
| `--ring-offset` | `#FDFCFA` | | | |

Links become clay. Today they are ink-coloured (`--text-link: #18181b`) because the old system forbade colour; Atelier permits an accent link.

**Atelier Dark — office, `[data-theme="dark"]`**

Espresso, not black. Semantic hues lift for legibility on dark; semantic `-bg` values are the hue at ~18% alpha.

| Token | Value | | Token | Value |
|---|---|---|---|---|
| `--bg-canvas` | `#17140F` | | `--accent` | `#D97848` |
| `--bg-surface` | `#1F1B16` | | `--accent-hover` | `#E8926A` |
| `--bg-elevated` | `#2A251E` | | `--accent-fg` | `#17140F` |
| `--bg-subtle` | `#221D17` | | `--success` | `#6FA688` |
| `--bg-zebra-even` | `#1C1812` | | `--warning` | `#D9A441` |
| `--bg-row-hover` | `#2A251E` | | `--danger` | `#D9645A` |
| `--bg-thead` | `#1F1B16` | | `--info` | `#7A9BC4` |
| `--border-subtle` | `#241F19` | | `--purple` | `#A98CC2` |
| `--border-default` | `#332C24` | | `--text-primary` | `#F5F1EA` |
| `--border-strong` | `#4A4137` | | `--text-secondary` | `#D6CEC1` |
| `--ring` | `#D97848` | | `--text-muted` | `#A89F93` |
| `--ring-offset` | `#17140F` | | `--text-subtle` | `#7D7468` |

**Atelier Floor — shop floor, `[data-theme="floor"]`**

Same identity, contrast budget raised. Clay pushed to safety-orange; every semantic hue clears AAA on canvas.

| Token | Value | | Token | Value |
|---|---|---|---|---|
| `--bg-canvas` | `#0D0B08` | | `--accent` | `#FF8A4C` |
| `--bg-surface` | `#17140F` | | `--accent-fg` | `#0D0B08` |
| `--bg-elevated` | `#241F19` | | `--success` | `#4ADE80` |
| `--border-default` | `#4A4137` | | `--warning` | `#FBBF24` |
| `--border-strong` | `#6B6156` | | `--danger` | `#F87171` |
| `--text-primary` | `#FFFDF8` | | `--info` | `#93C5FD` |
| `--text-secondary` | `#E8E2D8` | | `--purple` | `#C4B5FD` |
| `--text-muted` | `#A89F93` | | `--row-height` | `48px` |

Floor also overrides density tokens: row height 48px, minimum hit target 44×44, body 15px. `--row-height` and `--hit-min` are **new** tokens — the office palettes must declare them too (`32px` / `28px`) so `DataTable` and friends read them unconditionally instead of branching on theme.

The `--landing-*` namespace is re-authored in Atelier as its own pass (§6, T10) and keeps its separate variable set.

## 4. Architecture

Change is confined to the token and primitive layers.

```
UNTOUCHED   355 pages · routing · guards · api/ · hooks/ · stores/
            TanStack Query · RHF+Zod · Laravel API · 1,242 backend tests
                            ▲ inherits, no edits
RETUNED     components/ui/ (~50) · components/layout/ · components/charts/ (2)
            layouts/ (3 of 8)
                            ▲ reads
REWRITTEN   styles/tokens.css  232 → ~380 lines
            styles/globals.css 168 lines, retuned
            tailwind.config.ts token map, type scale, radius
            public/fonts/ + brand marks
```

This works because primitives name roles, not colours. `Button.tsx:31` reads `bg-accent text-accent-fg border-default rounded-md`. Redefining the role changes the whole app.

### 4.1 Theming

`stores/themeStore.ts` currently resolves `light | dark | system` and sets `<html data-theme>`. It gains a **route-forced override**: routes under `pages/factory`, `pages/driver` and `pages/maintenance/mobile` (9 pages, 3 layouts) set `data-theme="floor"` regardless of user preference, and restore the user's choice on exit.

Implementation: the three floor layouts (`FactoryFloorLayout`, `DriverLayout`, `MaintenanceMobileLayout`) declare the override; `themeStore` exposes `pushOverride(theme) / popOverride()` so nesting and back-navigation are correct. The existing light/dark toggle and its API persistence are unchanged — floor is never a user-selectable mode.

## 5. Prerequisite

The working tree is dirty: 44 modified files, 1,142 insertions, including **`spa/src/styles/tokens.css` itself** (10 insertions / 10 deletions), plus an untracked `scripts/fix_buttons.py` that rewrites `<Button>` props across all of `spa/src/`.

T1 rewrites `tokens.css` wholesale. That work must be committed or stashed before any track starts, or it is clobbered. `scripts/fix_buttons.py` should be committed or deleted — leaving an untracked codemod that rewrites the whole tree next to a restyling effort is a foot-gun.

## 6. Work breakdown

Each track is independently shippable and independently revertible. T1 alone changes how the entire product looks.

| ID | Track | Touches | Depends on |
|---|---|---|---|
| **T0** | Commit or stash working tree; resolve `fix_buttons.py` | repo | — |
| **T1** | Author `tokens.css` — 3 palettes, type, radius, motion, shadows. Update `tailwind.config.ts` token map, `fontSize`, `borderRadius`. Retune `globals.css`. | 3 files | T0 |
| **T2** | Install fonts: Instrument Serif, Public Sans, Spline Sans Mono as self-hosted `.woff2`. Remove Geist files and the `@fontsource-variable/bricolage-grotesque` dep. | `public/fonts/`, `package.json` | **lands with T1** |

T1 and T2 must land in the same commit: `tokens.css` declares the `@font-face` sources, so T1 alone would reference font files that do not exist yet and the app would render in fallback faces.
| **T3** | Remove glassmorphism — 10 `backdrop-blur` sites: `ui/Skeleton`, `ui/DataTable`, `layout/Topbar`, `layouts/PortalLayout`, and 6 under `pages/landing/`. | 10 files | T1 |
| **T4** | Detokenize `components/mrp/MoldShotMeter.tsx` — the only file in the app using raw Tailwind palette classes (`bg-amber-500`, `text-emerald-600`, `dark:` variants). Silently breaks under a third palette. | 1 file | T1 |
| **T5** | Chart tokens — replace stale fallbacks in `charts/DowntimeParetoChart.tsx` and `charts/OeeGaugeChart.tsx` (`var(--token, #e5e7eb)` etc.) with Atelier values. | 2 files | T1 |
| **T6** | Primitive sweep — walk all ~50 `components/ui/` primitives against the new form language. Most need no edit; verify rather than assume. | ~50 files | T1, T3 |
| **T7** | Floor palette wiring — `themeStore` override API; `FactoryFloorLayout`, `DriverLayout`, `MaintenanceMobileLayout` adopt it; density tokens applied. | 4 files | T1 |
| **T8** | Brand assets — wordmark, app mark, `favicon.svg`, `driver-icon-192.png`, `driver-icon-512.png`, `driver-manifest.webmanifest`, `factory-manifest.webmanifest`. | `public/` | T1 |
| **T9** | Hero screens — auth (4 pages), role dashboards (12), `ShopFloorMap`, MRP II Gantt, Quality Pareto, OEE gauge. | ~20 files | T1–T7 |
| **T10** | Landing — re-author the `--landing-*` namespace in Atelier; 27 files under `pages/landing/`. | 27 files | T1, T3 |
| **T11** | PDF templates — `api/resources/views/pdf/` is Blade with its own styling and inherits nothing. Payslip first. | Blade views | T1 |
| **T12** | Docs re-sync — rewrite `docs/DESIGN-SYSTEM.md` and the *Design System Quick Reference* block in `CLAUDE.md`; both currently mandate grayscale + indigo + glass. | 2 files | T1 |
| **T13** | Targeted debt (optional, independent) — split the 14 pages over 500 LOC; lazy-load `three` (3), `gsap` (12), `recharts` (13), `leaflet` (1), `lenis` (1). | ~30 files | — |

T13 is unrelated to the rebrand and can run at any time, including never.

## 7. Verification

A reskin is behaviour-neutral, so verification is mostly proving nothing moved.

| Gate | Command | Expectation |
|---|---|---|
| Types | `npm run typecheck` | clean |
| Lint | `npm run lint` | clean, `--max-warnings 0` |
| Unit | `npm run test:run` | all pass, incl. `components/ui/__tests__` |
| Routes | `npm run audit:live-routes`, `npm run audit:dynamic-routes` | every route still renders |
| RBAC | `npm run audit:rbac`, `npm run audit:role-permissions` | unchanged |
| E2E | `npm run test:e2e` | all pass |
| Backend | api suite | sanity pass only — no API change |

Two new gates:

- **Contrast.** Every text/background pair in each palette checked against WCAG AA for office palettes and AAA for floor. This is the one thing that can genuinely regress: warm, low-contrast palettes are easy to get wrong, and `--text-subtle #8A8078` on `--bg-surface #F7F4EF` is the kind of pair that needs measuring rather than eyeballing.
- **Token discipline (CI grep).** Fail the build on raw Tailwind palette classes (`bg-gray-500`, `text-emerald-600`, …) or hex literals anywhere outside `styles/tokens.css`. The codebase is at zero today in `pages/` and one offender in `components/`; a gate keeps it there.

Visual verification is by screenshot walk — `npm run test:defense` already drives a scripted walk and produces `docs/defense-screenshots/`. Capture a before set at T0 and diff after each track.

## 8. Risks

| Risk | Mitigation |
|---|---|
| Warm low-contrast palette fails accessibility in places | Contrast gate before T6; treat AA as a hard failure, not a warning |
| Removing glass breaks layering in the 10 affected files | T3 is its own track with its own screenshot diff |
| Density regression — serif display tempts larger spacing | Base stays 13px, rows stay 32px in office palettes; these are spec, not preference |
| Floor override leaks into office routes on back-navigation | `pushOverride/popOverride` rather than a bare setter; covered by a unit test |
| Landing (27 files) is the largest single surface and has bespoke GSAP/three.js work | T10 is last and independently revertible |
| Thesis documentation drifts | T12; `docs/DEFENSE-TRACEABILITY.md`, `docs/QA-MATRIX.md`, `docs/USER-MANUAL.md` contain screenshots and design claims — audit after T9 |

## 9. Open items

- Wordmark and app-mark design (T8) is not specified here; it needs its own visual pass.
- The 3xl/32px display step is specified but no page currently uses it — T9 decides where the serif gets to be large.
- `docs/USER-MANUAL.md` screenshot count is unaudited; T12 may be larger than it looks.

## 10. Explicitly not decided by this spec

Whether to later migrate to Tailwind v4, React 19, or shadcn/ui. Atelier is authored as plain CSS variables plus a Tailwind v3 token map, so it survives any of those migrations unchanged.


