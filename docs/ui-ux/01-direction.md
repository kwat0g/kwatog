# OGAMI ERP — Design Direction (Phase 1)

Phase 0 established the decisive fact: **this product already has one deliberate visual
language — Atelier — and it is documented and enforced** (`docs/DESIGN-SYSTEM.md`,
`spa/src/styles/tokens.css`, `npm run audit:tokens` clean across 1043 files, a
palette-contrast test, 60 shared components).

So this phase does **not** propose two competing directions. The brief only asks for two
when the existing look has no clear identity. It has one. What follows is that direction,
named, with its principles written down and its open decisions surfaced for the review.

---

## 1. The product, in plain language

OGAMI ERP is the operating system of a Japanese-owned plastic injection-molding plant in
Dasmariñas, Cavite: 200+ employees, IATF 16949, making wiper bushings, pivot caps and relay
covers for Toyota, Nissan, Honda, Suzuki and Yamaha. It runs three end-to-end chains —
Order-to-Cash, Procure-to-Pay, Hire-to-Retire — with 17 roles, from a driver in a cab to a
vice-president approving spend, plus warehouse and QC staff on shared terminals.

**What it must feel like:** an instrument, not a toy. Calm, warm, precise, information-dense
without being noisy. It is used all day, every day, by people whose real work is at a machine
or on a loading dock, not in this app. Familiarity and speed beat novelty every time.

**What it must not feel like:** a consumer SaaS dashboard grafted onto a factory; a generic
"AI-generated UI" (default gradients, random purple, glassmorphism, card-in-card-in-card,
decorative icon confetti, emoji as UI).

## 2. Design principles

Derived from the existing system and the Phase 0 baseline, not invented:

1. **Data first; decoration never earns its keep.** Hierarchy comes from borders and type
   weight, not shadows. Shadows are for true overlays only and are warm-tinted.
2. **One language, three densities.** Light (office), Dark (office), and Floor (shop-floor
   PWA, route-forced, never user-selectable). Same components, different density tokens —
   never a forked component.
3. **Numbers are typography.** Money, IDs, quantities, dates are monospace with tabular
   figures. A `decimal(15,2)` never becomes a JS float; decimals arrive as **strings**.
4. **Tokens or nothing.** No colour, size, radius, or duration outside `tokens.css`.
   Enforced in CI, and the enforcement stays.
5. **Fit for the hand holding it.** Office tables are dense; floor and phone screens are
   large-target and scan-first. `--hit-min` is 28px office / 44px floor.
6. **Speed for the hundredth repetition.** The highest-frequency path (create, receive,
   approve, record a defect) should cost fewer clicks and less retyping than the first
   version did.
7. **Errors are recoverable and honest.** States are designed, not left to the happy path:
   empty, loading, error, stale, partial-permission, long text, 500+ rows.
8. **Permissions are UX, not walls.** The frontend mirrors backend RBAC exactly — never a
   button that always fails, never a hidden one the user is allowed to use. The frontend is
   never the security boundary.
9. **Accessible by construction.** WCAG 2.2 AA, keyboard-first, visible focus, never
   colour-only meaning, `prefers-reduced-motion` respected.
10. **No dead ends.** Every record links to its related records and its chain.

## 3. Visual language (as-built, confirmed)

- **Palette.** Warm paper canvas `#fdfcfa` + espresso ink `#1f1b16`; clay accent `#b4542a`.
  Semantics are brand-hued — moss, ochre, oxide red, slate blue, plum — **not** Tailwind
  defaults. Three palettes as above. Contrast is gated by test, not aspiration.
- **Type.** Instrument Serif (display / page titles) · Public Sans (UI) · Spline Sans Mono
  (numbers, IDs, tables), all self-hosted via `@fontsource`.
- **Space.** 8px grid with 4px increments. **Radius** 8 / 10 / 14, plus full for pills.
- **Surfaces.** Opaque. No translucency, no `backdrop-blur`.
- **Motion.** Minimal and purposeful — loading, progress, status change only. Durations
  150/250/400ms on one ease (`cubic-bezier(0.16, 1, 0.3, 1)`), fully disabled under
  reduced-motion.
- **Icons.** Tabler, re-exported through `lib/icons.ts` so the set stays switchable.

## 4. What this phase actually adds

`PRODUCT.md` at the repo root is the one real gap: it currently describes **only** the
customer-portal order-entry surface, so nothing in the repo states what the whole product is
for. This document is that statement; `PRODUCT.md` should be widened to match it (see Plan
task D1) rather than a second file being created.

Everything else Phase 1 normally produces already exists:

| Phase 1 deliverable | Status |
|---|---|
| Product purpose, audiences, feel | **Missing** → this document + widened `PRODUCT.md` |
| Design principles | **Missing as a list** → § 2 above |
| Colour / type / spacing / radius / elevation / icons / motion | **Exists** → `docs/DESIGN-SYSTEM.md` (kept as the source of truth) |
| Density modes | **Exists** → `--row-height/--hit-min/--font-size-body` × 3 palettes |
| Dark mode | **Exists** → `[data-theme="dark"]` |
| Chart palette | **Partial** → `docs/dashboards/phase0/E-frontend.md` names a categorical, colourblind-safe `--chart-1..8` per palette as an open gap |
| Two directions to choose between | **Not applicable** — identity is established; forking it would be the defect |

## 5. Open decisions for the review

1. **Density default.** Office ships 32px rows / 13px body. Confirm the default stays
   comfortable, or make compact a per-user preference remembered server-side (the brief
   lists this as an extra feature).
2. **Serif page titles.** Instrument Serif titles are a deliberate editorial choice. Keep
   everywhere, or confine to landing/dashboards?
3. **Chart palette.** Approve adding `--chart-1..8` per palette (tokens only, colourblind-safe,
   contrast-gated) so dashboards stop inventing categorical colour.
4. **`PRODUCT.md` scope.** Widen the existing root file (recommended) vs. a new
   product document.

Defaults proposed in `02-plan.md` § 8 so nothing is blocked either way.
