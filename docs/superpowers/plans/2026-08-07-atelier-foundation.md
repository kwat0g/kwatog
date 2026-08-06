# Atelier Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Ogami ERP visual identity with the Atelier brand at the token + primitive layer, so all 355 pages restyle without being edited.

**Architecture:** Every colour, radius, and type decision in `spa/` already resolves through CSS custom properties in `styles/tokens.css`, mapped into Tailwind by `tailwind.config.ts`. Primitives name roles (`bg-accent`, `border-default`), never colours. Rewriting the token file therefore restyles the whole app. This plan rewrites that file with three palettes (light, dark, floor), swaps the type system, and fixes the small set of files that bypass tokens and would otherwise break.

**Tech Stack:** React 18, TypeScript 5.6, Vite 7, Tailwind CSS 3.4, Vitest 4, Playwright 1.60, Zustand 5, `@fontsource` for self-hosted webfonts.

**Source spec:** `docs/superpowers/specs/2026-08-07-atelier-frontend-redesign.md`

**Scope:** This plan covers spec tracks **T0–T8 and T12**. It ends with a completely reskinned, shippable application. Tracks **T9** (hero screens), **T10** (landing, 27 files), **T11** (Blade PDF templates) and **T13** (unrelated tech debt) each get their own plan — they are independent surfaces and none of them block this one.

## Global Constraints

- Base body font size stays **13px**; office table rows stay **32px**. Density is load-bearing for an ERP and must not regress.
- Radius scale is exactly `sm 8px` / `md 10px` / `lg 14px` / `full 9999px`.
- Motion stays `--duration-fast 150ms` / `--duration-normal 250ms` / `--duration-slow 400ms`, easing `cubic-bezier(0.16, 1, 0.3, 1)`.
- **All surfaces are opaque.** No `rgba()` background values, no `backdrop-blur`. Semantic `-bg` tokens are pre-composited hex.
- **No hex literals and no raw Tailwind palette classes** (`bg-gray-500`, `text-emerald-600`, …) anywhere outside `spa/src/styles/tokens.css`. Task 10 adds a CI gate enforcing this.
- Office palettes must pass **WCAG AA** (4.5:1 body, 3:1 large/UI); the floor palette must pass **AAA** (7:1 body). Task 4 adds the checker.
- `--landing-*` tokens are **out of scope** — track T10 re-authors them. Leave them exactly as they are; they must keep compiling.
- Every task ends green on `npm run typecheck` and `npm run lint` (`--max-warnings 0`).
- Task 10's checker uses `fs.globSync`, which requires **Node ≥ 22**. This machine runs v24.14.0. If CI pins an older Node, swap it for a recursive `readdirSync` walk.
- Run all commands from `spa/` unless stated otherwise.

---

### Task 1: Clear the working tree and capture a visual baseline

The tree currently holds 44 modified files — **including `spa/src/styles/tokens.css` itself** — plus an untracked `scripts/fix_buttons.py` that rewrites `<Button>` props across all of `spa/src/`. Task 3 overwrites `tokens.css` wholesale. Landing on top of uncommitted work loses it, and leaving an untracked whole-tree codemod next to a restyling effort invites an accidental run.

**Files:**
- Modify: repository working tree only
- Create: `docs/atelier-baseline/` (screenshots, gitignored)

- [ ] **Step 1: See exactly what is uncommitted**

```bash
cd /home/kwat0g/Desktop/kwatog
git status --short
git diff --stat
```

- [ ] **Step 2: Commit the in-flight work on its own**

Review the diff, then commit it. Do **not** mix it with Atelier work — it must stay separately revertible.

```bash
git add -A spa/ api/
git commit -m "chore: commit in-flight working-tree changes before Atelier rebrand"
```

- [ ] **Step 3: Resolve the untracked codemod**

`scripts/fix_buttons.py` rewrites every `.ts`/`.tsx` under `spa/src`. It has already served its purpose (`size="xl"` → `size="lg"`, `variant="success"` → `variant="primary"`). Delete it.

```bash
rm scripts/fix_buttons.py
```

- [ ] **Step 4: Confirm the tree is clean**

```bash
git status --short
```

Expected: no output.

- [ ] **Step 5: Capture the "before" screenshots**

```bash
cd spa && npm run test:defense
cp -r ../docs/defense-screenshots ../docs/atelier-baseline
```

These are the reference for every visual diff in this plan. If `test:defense` fails for reasons unrelated to styling, note the failure and continue — a partial baseline is still useful.

- [ ] **Step 6: Keep the baseline out of git**

Append to `/home/kwat0g/Desktop/kwatog/.gitignore`:

```
docs/atelier-baseline/
```

- [ ] **Step 7: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog
git add .gitignore
git commit -m "chore: ignore Atelier visual baseline screenshots"
```

---

### Task 2: Install the Atelier typefaces

Atelier uses Instrument Serif (display), Public Sans (UI), Spline Sans Mono (numerals). All three ship on npm via `@fontsource`, which the project already uses for Bricolage Grotesque. This replaces the hand-managed `.woff2` files in `public/fonts/`, so `tokens.css` no longer needs `@font-face` blocks at all.

Fonts land **before** tokens so that the moment Task 3 renames the families, real faces exist.

**Files:**
- Modify: `spa/package.json`
- Modify: `spa/src/main.tsx:11`
- Modify: `spa/src/pages/landing/LandingPage.tsx:15`
- Modify: `spa/src/layouts/AuthLayout.tsx:25`
- Delete: `spa/public/fonts/geist-400.woff2`, `geist-500.woff2`, `geist-mono-400.woff2`, `geist-mono-500.woff2`

**Interfaces:**
- Produces: CSS font families `'Instrument Serif'`, `'Public Sans Variable'`, `'Spline Sans Mono Variable'` available globally. Task 3 references these exact strings in `--font-display`, `--font-sans`, `--font-mono`.

- [ ] **Step 1: Install the three families**

```bash
cd spa
npm install @fontsource/instrument-serif @fontsource-variable/public-sans @fontsource-variable/spline-sans-mono
```

- [ ] **Step 2: Remove the old family**

```bash
npm uninstall @fontsource-variable/bricolage-grotesque
```

- [ ] **Step 3: Swap the import in `main.tsx`**

Replace line 11 of `spa/src/main.tsx`:

```typescript
import '@fontsource-variable/bricolage-grotesque/wght.css';
```

with:

```typescript
import '@fontsource/instrument-serif/400.css';
import '@fontsource/instrument-serif/400-italic.css';
import '@fontsource-variable/public-sans';
import '@fontsource-variable/spline-sans-mono';
```

Instrument Serif has no variable axis — it ships as static 400 and 400-italic only. The other two are variable and import their full weight range from the package root.

- [ ] **Step 4: Remove the now-duplicate imports**

`LandingPage.tsx:15` and `AuthLayout.tsx:25` each import Bricolage separately. `main.tsx` now loads every Atelier face globally, so both lines are dead. Delete this line from both files:

```typescript
import '@fontsource-variable/bricolage-grotesque/wght.css';
```

- [ ] **Step 5: Delete the hand-managed Geist files**

```bash
rm spa/public/fonts/geist-400.woff2 \
   spa/public/fonts/geist-500.woff2 \
   spa/public/fonts/geist-mono-400.woff2 \
   spa/public/fonts/geist-mono-500.woff2
rmdir spa/public/fonts
```

- [ ] **Step 6: Verify nothing still references the deleted files or package**

```bash
cd spa
grep -rn 'geist\|bricolage' src/ index.html
```

Expected: the only remaining hits are the `@font-face` blocks at the top of `src/styles/tokens.css` (lines 7–39) and the `--font-sans` / `--font-mono` / `--font-display` declarations. Task 3 deletes all of them. Any hit in a `.tsx` file is a miss — fix it now.

- [ ] **Step 7: Verify the build still compiles**

```bash
npm run typecheck && npm run build
```

Expected: PASS. The app renders in fallback faces at this point — `tokens.css` still names Geist, which no longer exists. That is expected and Task 3 fixes it. Do not "fix" it here.

- [ ] **Step 8: Commit**

```bash
git add spa/package.json spa/package-lock.json spa/src/main.tsx \
        spa/src/pages/landing/LandingPage.tsx spa/src/layouts/AuthLayout.tsx spa/public/
git commit -m "feat: install Atelier typefaces, drop Geist and Bricolage Grotesque"
```

---

### Task 3: Author the Atelier token file

The centrepiece. Replaces all of `tokens.css` — three palettes, new type families, new radius scale, warm shadows, new density tokens. `@font-face` blocks are deleted because Task 2 moved font loading to `@fontsource`.

**Files:**
- Rewrite: `spa/src/styles/tokens.css` (currently 232 lines)

**Interfaces:**
- Produces: CSS custom properties consumed by `tailwind.config.ts` (Task 5) and read directly by `components/charts/*` (Task 8). New tokens introduced here and used later: `--row-height`, `--hit-min`, `--font-size-body`.
- Preserves unchanged: every `--landing-*` property, both light and dark. Track T10 owns those. Copy them across verbatim.

- [ ] **Step 1: Preserve the landing block and the autofill/hairline rules**

Before rewriting, copy these out of the current file so they survive:
- the `--landing-*` declarations in `:root` (lines 118–142) and in `[data-theme='dark']` (lines 209–228)
- the `input:-webkit-autofill` rule block (lines 216–229 of the tail)
- the `@media (min-resolution: 2dppx)` `.border-hairline` rule at the end

- [ ] **Step 2: Write the `:root` (light) palette**

Replace the whole head of the file — the four `@font-face` blocks and the `:root` block — with:

```css
/*
 * OGAMI ERP — Atelier design tokens.
 * Warm paper canvas, espresso ink, clay accent. Opaque surfaces.
 * Font faces are loaded via @fontsource in src/main.tsx.
 * Spec: docs/superpowers/specs/2026-08-07-atelier-frontend-redesign.md
 */

:root {
  /* ─── Canvas ─── */
  --bg-canvas: #fdfcfa;
  --bg-surface: #f7f4ef;
  --bg-elevated: #f2ede4;
  --bg-subtle: #f5f1e9;

  /* ─── Table zebra & header ─── */
  --bg-zebra-odd: transparent;
  --bg-zebra-even: #f7f4ef;
  --bg-row-hover: #f2ede4;
  --bg-thead: #f7f4ef;

  /* ─── Borders ─── */
  --border-subtle: #f0eae0;
  --border-default: #e8e2d8;
  --border-strong: #d6cec1;

  /* ─── Text ─── */
  --text-primary: #1f1b16;
  --text-secondary: #4a4239;
  --text-muted: #6b6259;
  --text-subtle: #8a8078;

  /* ─── Accent (clay) ─── */
  --accent: #b4542a;
  --accent-hover: #96461f;
  --accent-fg: #fdfcfa;

  /* Links — Atelier permits an accent link; the old system forbade colour. */
  --text-link: #b4542a;
  --text-link-hover: #96461f;

  /* ─── Semantic ─── */
  --success: #3f6d54;
  --success-bg: #e3ece7;
  --success-fg: #2c4e3b;

  --warning: #b07a22;
  --warning-bg: #f7eedc;
  --warning-fg: #7a5314;

  --danger: #a8392f;
  --danger-bg: #f6e4e1;
  --danger-fg: #7a2820;

  --info: #3d5a80;
  --info-bg: #e3e9f0;
  --info-fg: #2a4059;

  --purple: #75558c;
  --purple-bg: #ede7f1;
  --purple-fg: #4c3862;

  /* ─── Focus ring ─── */
  --ring: #b4542a;
  --ring-offset: #fdfcfa;

  /* ─── Shadows — warm-tinted, overlays only ─── */
  --shadow-focus: 0 0 0 4px rgba(180, 84, 42, 0.18);
  --shadow-menu: 0 16px 32px -8px rgba(31, 27, 22, 0.14),
    0 8px 16px -4px rgba(31, 27, 22, 0.09);

  /* ─── Type ─── */
  --font-sans: 'Public Sans Variable', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
  --font-mono: 'Spline Sans Mono Variable', 'SF Mono', Menlo, Monaco, Consolas, monospace;
  --font-display: 'Instrument Serif', Georgia, 'Times New Roman', serif;

  /* ─── Radius ─── */
  --radius-sm: 8px;
  --radius-md: 10px;
  --radius-lg: 14px;
  --radius-full: 9999px;

  /* ─── Density — office. Floor overrides all three. ─── */
  --row-height: 32px;
  --hit-min: 28px;
  --font-size-body: 13px;

  /* ─── Motion ─── */
  --duration-fast: 150ms;
  --duration-normal: 250ms;
  --duration-slow: 400ms;
  --ease-default: cubic-bezier(0.16, 1, 0.3, 1);

  /* ─── Landing page palette — OUT OF SCOPE, track T10 owns these ─── */
  /* Paste the existing --landing-* block from :root here verbatim. */
}
```

- [ ] **Step 3: Write the `[data-theme='dark']` palette**

Semantic `-bg` values are **opaque**, pre-composited at ~18% of the hue over canvas. Alpha would defeat the Task 4 contrast checker, which cannot resolve `rgba()` against an unknown backdrop.

```css
[data-theme='dark'] {
  --bg-canvas: #17140f;
  --bg-surface: #1f1b16;
  --bg-elevated: #2a251e;
  --bg-subtle: #221d17;

  --bg-zebra-odd: transparent;
  --bg-zebra-even: #1c1812;
  --bg-row-hover: #2a251e;
  --bg-thead: #1f1b16;

  --border-subtle: #241f19;
  --border-default: #332c24;
  --border-strong: #4a4137;

  --text-primary: #f5f1ea;
  --text-secondary: #d6cec1;
  --text-muted: #a89f93;
  --text-subtle: #7d7468;

  --accent: #d97848;
  --accent-hover: #e8926a;
  --accent-fg: #17140f;

  --text-link: #d97848;
  --text-link-hover: #e8926a;

  --success: #6fa688;
  --success-bg: #272e25;
  --success-fg: #9ed2b4;

  --warning: #d9a441;
  --warning-bg: #3a2e18;
  --warning-fg: #f0cb86;

  --danger: #d9645a;
  --danger-bg: #3a221d;
  --danger-fg: #f0a9a2;

  --info: #7a9bc4;
  --info-bg: #292c30;
  --info-fg: #b6cde6;

  --purple: #a98cc2;
  --purple-bg: #312a2f;
  --purple-fg: #d3c2e4;

  --ring: #d97848;
  --ring-offset: #17140f;

  --shadow-focus: 0 0 0 4px rgba(217, 120, 72, 0.28);
  --shadow-menu: 0 16px 32px -8px rgba(0, 0, 0, 0.6),
    0 8px 16px -4px rgba(0, 0, 0, 0.45);

  /* ─── Landing page palette — OUT OF SCOPE, track T10 owns these ─── */
  /* Paste the existing --landing-* block from [data-theme='dark'] here verbatim. */
}
```

- [ ] **Step 4: Write the `[data-theme='floor']` palette**

Same clay identity, contrast budget raised. Density tokens change here — this is the only palette that overrides them.

```css
/*
 * Shop-floor PWAs (factory / driver / maintenance mobile). Route-forced by
 * TouchShell, never user-selectable. Tablet under fluorescent light, gloved
 * hands: AAA body contrast, 48px rows, 44px hit targets.
 */
[data-theme='floor'] {
  --bg-canvas: #0d0b08;
  --bg-surface: #17140f;
  --bg-elevated: #241f19;
  --bg-subtle: #1c1812;

  --bg-zebra-odd: transparent;
  --bg-zebra-even: #17140f;
  --bg-row-hover: #241f19;
  --bg-thead: #17140f;

  --border-subtle: #332c24;
  --border-default: #4a4137;
  --border-strong: #6b6156;

  --text-primary: #fffdf8;
  --text-secondary: #e8e2d8;
  --text-muted: #a89f93;
  --text-subtle: #8a8078;

  --accent: #ff8a4c;
  --accent-hover: #ffa473;
  --accent-fg: #0d0b08;

  --text-link: #ff8a4c;
  --text-link-hover: #ffa473;

  --success: #4ade80;
  --success-bg: #12301f;
  --success-fg: #a7f3c8;

  --warning: #fbbf24;
  --warning-bg: #3a2c0d;
  --warning-fg: #fde196;

  --danger: #f87171;
  --danger-bg: #3a1a1a;
  --danger-fg: #fcc0c0;

  --info: #93c5fd;
  --info-bg: #17293d;
  --info-fg: #c7e0fe;

  --purple: #c4b5fd;
  --purple-bg: #262040;
  --purple-fg: #ded5fe;

  --ring: #ff8a4c;
  --ring-offset: #0d0b08;

  --shadow-focus: 0 0 0 4px rgba(255, 138, 76, 0.35);
  --shadow-menu: 0 16px 32px -8px rgba(0, 0, 0, 0.7),
    0 8px 16px -4px rgba(0, 0, 0, 0.5);

  /* Density — the only palette that raises these. */
  --row-height: 48px;
  --hit-min: 44px;
  --font-size-body: 15px;
}
```

The floor palette deliberately declares **no** `--landing-*` values. Landing is never rendered inside a floor route, and inheriting the light values is correct if it somehow were.

- [ ] **Step 5: Re-append the autofill and hairline rules**

Paste the `input:-webkit-autofill` block and the `@media (min-resolution: 2dppx)` `.border-hairline` rule back at the end of the file, unchanged. The autofill rule already references `var(--bg-canvas)` and `var(--text-primary)`, so it follows Atelier automatically.

- [ ] **Step 6: Verify no translucent surfaces or stale families remain**

```bash
cd spa
grep -nE 'rgba\([0-9]' src/styles/tokens.css | grep -viE 'shadow|landing'
grep -nc 'font-face' src/styles/tokens.css
grep -n 'Geist' src/styles/tokens.css
```

Expected: first command prints nothing (only shadows and the untouched landing block may use `rgba`); second prints `0`; third prints nothing.

- [ ] **Step 7: Verify it compiles**

```bash
npm run build
```

Expected: PASS. The app will look half-migrated until Task 5 updates `tailwind.config.ts` — Tailwind still maps the old type scale and radius. Expected; do not fix it here.

- [ ] **Step 8: Commit**

```bash
git add spa/src/styles/tokens.css
git commit -m "feat: author Atelier tokens — light, dark and floor palettes"
```

---

### Task 4: Contrast checker (TDD)

The one thing in this rebrand that can genuinely regress. Warm, low-contrast palettes fail WCAG easily, and `--text-subtle #8a8078` on `--bg-surface #f7f4ef` is exactly the kind of pair that needs measuring rather than eyeballing.

Built as a vitest test that reads `tokens.css` from disk, so `npm run test:run` becomes the gate — no separate CLI to remember, and CI already runs it.

**Tiered thresholds**, because not all text is essential:

| Role | Office (light/dark) | Floor |
|---|---|---|
| `--text-primary`, `--text-secondary`, `--text-muted`, all `-fg` | AA 4.5:1 | AAA 7:1 |
| `--text-subtle` (decorative, non-essential) | AA-large 3:1 | AA 4.5:1 |
| `--accent`, `--success`, `--warning`, `--danger`, `--info`, `--purple` on canvas (UI marks, chips, bars) | 3:1 | 4.5:1 |

**Files:**
- Create: `spa/src/lib/contrast.ts`
- Create: `spa/src/lib/__tests__/contrast.test.ts`
- Create: `spa/src/styles/__tests__/palette-contrast.test.ts`

**Interfaces:**
- Produces: `hexToRgb(hex: string): {r,g,b} | null`, `relativeLuminance(rgb): number`, `contrastRatio(a: string, b: string): number`, `parseThemeBlocks(css: string): Record<string, Record<string, string>>`. Task 10's CI gate does not depend on these; nothing else consumes them.

- [ ] **Step 1: Write the failing unit test**

Create `spa/src/lib/__tests__/contrast.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { hexToRgb, contrastRatio, parseThemeBlocks } from '../contrast';

describe('hexToRgb', () => {
  it('parses 6-digit hex', () => {
    expect(hexToRgb('#fdfcfa')).toEqual({ r: 253, g: 252, b: 250 });
  });

  it('parses 3-digit shorthand', () => {
    expect(hexToRgb('#fff')).toEqual({ r: 255, g: 255, b: 255 });
  });

  it('returns null for non-hex values', () => {
    expect(hexToRgb('transparent')).toBeNull();
    expect(hexToRgb('rgba(0,0,0,.5)')).toBeNull();
  });
});

describe('contrastRatio', () => {
  it('gives 21:1 for black on white', () => {
    expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 1);
  });

  it('gives 1:1 for a colour against itself', () => {
    expect(contrastRatio('#b4542a', '#b4542a')).toBeCloseTo(1, 2);
  });

  it('is order-independent', () => {
    expect(contrastRatio('#1f1b16', '#fdfcfa')).toBeCloseTo(
      contrastRatio('#fdfcfa', '#1f1b16'),
      5,
    );
  });
});

describe('parseThemeBlocks', () => {
  const css = `
:root {
  --bg-canvas: #fdfcfa;
  --text-primary: #1f1b16;
}
[data-theme='dark'] {
  --bg-canvas: #17140f;
}
[data-theme='floor'] {
  --bg-canvas: #0d0b08;
}
`;

  it('extracts one record per theme', () => {
    const blocks = parseThemeBlocks(css);
    expect(Object.keys(blocks).sort()).toEqual(['dark', 'floor', 'light']);
  });

  it('reads token values within a theme', () => {
    expect(parseThemeBlocks(css).light['--text-primary']).toBe('#1f1b16');
  });

  it('keeps themes independent', () => {
    expect(parseThemeBlocks(css).dark['--bg-canvas']).toBe('#17140f');
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd spa && npx vitest run src/lib/__tests__/contrast.test.ts
```

Expected: FAIL — `Failed to resolve import "../contrast"`.

- [ ] **Step 3: Implement `contrast.ts`**

Create `spa/src/lib/contrast.ts`:

```typescript
/**
 * WCAG 2.1 contrast maths + a minimal tokens.css parser.
 * Used by the palette contrast gate — see styles/__tests__/palette-contrast.test.ts.
 */

export interface Rgb {
  r: number;
  g: number;
  b: number;
}

/** Parses `#rgb` / `#rrggbb`. Returns null for anything else (transparent, rgba, var). */
export function hexToRgb(hex: string): Rgb | null {
  const v = hex.trim();
  const short = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.exec(v);
  if (short) {
    return {
      r: parseInt(short[1] + short[1], 16),
      g: parseInt(short[2] + short[2], 16),
      b: parseInt(short[3] + short[3], 16),
    };
  }
  const long = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(v);
  if (long) {
    return {
      r: parseInt(long[1], 16),
      g: parseInt(long[2], 16),
      b: parseInt(long[3], 16),
    };
  }
  return null;
}

/** WCAG relative luminance. */
export function relativeLuminance({ r, g, b }: Rgb): number {
  const channel = (c: number): number => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

/** WCAG contrast ratio, 1..21. Throws on unparseable input so a typo fails loudly. */
export function contrastRatio(a: string, b: string): number {
  const ca = hexToRgb(a);
  const cb = hexToRgb(b);
  if (!ca || !cb) throw new Error(`contrastRatio: unparseable colour ${!ca ? a : b}`);
  const la = relativeLuminance(ca);
  const lb = relativeLuminance(cb);
  const [hi, lo] = la > lb ? [la, lb] : [lb, la];
  return (hi + 0.05) / (lo + 0.05);
}

const THEME_SELECTORS: ReadonlyArray<[RegExp, string]> = [
  [/^:root$/, 'light'],
  [/^\[data-theme=['"]dark['"]\]$/, 'dark'],
  [/^\[data-theme=['"]floor['"]\]$/, 'floor'],
];

/**
 * Extracts `--token: value` pairs per theme block from a tokens.css source.
 * Deliberately dumb — it only understands the three top-level theme selectors
 * this project uses, and ignores @font-face, @media and nested rules.
 */
export function parseThemeBlocks(css: string): Record<string, Record<string, string>> {
  const out: Record<string, Record<string, string>> = {};
  const blockRe = /([^{}]+)\{([^{}]*)\}/g;
  let match: RegExpExecArray | null;

  while ((match = blockRe.exec(css)) !== null) {
    const selector = match[1].replace(/\/\*[\s\S]*?\*\//g, '').trim();
    const theme = THEME_SELECTORS.find(([re]) => re.test(selector))?.[1];
    if (!theme) continue;

    const tokens: Record<string, string> = out[theme] ?? {};
    const declRe = /(--[a-z0-9-]+)\s*:\s*([^;]+);/gi;
    let decl: RegExpExecArray | null;
    while ((decl = declRe.exec(match[2])) !== null) {
      tokens[decl[1]] = decl[2].trim();
    }
    out[theme] = tokens;
  }
  return out;
}
```

- [ ] **Step 4: Run the unit test — it must pass**

```bash
cd spa && npx vitest run src/lib/__tests__/contrast.test.ts
```

Expected: PASS, 9 tests.

- [ ] **Step 5: Write the palette gate**

Create `spa/src/styles/__tests__/palette-contrast.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { contrastRatio, parseThemeBlocks } from '@/lib/contrast';

const css = readFileSync(resolve(__dirname, '../tokens.css'), 'utf8');
const themes = parseThemeBlocks(css);

/** Text roles that carry meaning — full AA/AAA. */
const ESSENTIAL_TEXT = ['--text-primary', '--text-secondary', '--text-muted'] as const;
/** Decorative only (placeholder hints, disabled captions) — AA-large is enough. */
const DECORATIVE_TEXT = ['--text-subtle'] as const;
/** Non-text marks: chip fills, progress bars, icon strokes. WCAG 1.4.11 applies. */
const SEMANTIC_MARKS = [
  '--accent',
  '--success',
  '--warning',
  '--danger',
  '--info',
  '--purple',
] as const;
/** Paired semantic surfaces: foreground must be legible on its own background. */
const SEMANTIC_PAIRS = ['success', 'warning', 'danger', 'info', 'purple'] as const;

const THRESHOLDS = {
  light: { essential: 4.5, decorative: 3, mark: 3 },
  dark: { essential: 4.5, decorative: 3, mark: 3 },
  floor: { essential: 7, decorative: 4.5, mark: 4.5 },
} as const;

describe.each(['light', 'dark', 'floor'] as const)('%s palette', (theme) => {
  const t = themes[theme];
  const limits = THRESHOLDS[theme];

  it('is present in tokens.css', () => {
    expect(t, `no [data-theme="${theme}"] block found`).toBeDefined();
  });

  describe.each(['--bg-canvas', '--bg-surface', '--bg-elevated'] as const)(
    'on %s',
    (bgToken) => {
      it.each(ESSENTIAL_TEXT)(`%s clears ${limits.essential}:1`, (fg) => {
        expect(contrastRatio(t[fg], t[bgToken])).toBeGreaterThanOrEqual(limits.essential);
      });

      it.each(DECORATIVE_TEXT)(`%s clears ${limits.decorative}:1`, (fg) => {
        expect(contrastRatio(t[fg], t[bgToken])).toBeGreaterThanOrEqual(limits.decorative);
      });
    },
  );

  it.each(SEMANTIC_MARKS)(`%s clears ${limits.mark}:1 on canvas`, (mark) => {
    expect(contrastRatio(t[mark], t['--bg-canvas'])).toBeGreaterThanOrEqual(limits.mark);
  });

  it.each(SEMANTIC_PAIRS)('--%s-fg is legible on --%s-bg', (name) => {
    expect(
      contrastRatio(t[`--${name}-fg`], t[`--${name}-bg`]),
    ).toBeGreaterThanOrEqual(limits.essential);
  });

  it('--accent-fg is legible on --accent', () => {
    expect(contrastRatio(t['--accent-fg'], t['--accent'])).toBeGreaterThanOrEqual(
      limits.essential,
    );
  });

  it('declares density tokens', () => {
    expect(t['--row-height']).toBeDefined();
    expect(t['--hit-min']).toBeDefined();
    expect(t['--font-size-body']).toBeDefined();
  });
});
```

Note `--row-height` is asserted per-theme, but only `:root` and `floor` declare it. `dark` inherits from `:root` at runtime — CSS cascade, not token duplication. **This assertion will fail for `dark`.** That is intentional: Step 6 is where you decide.

- [ ] **Step 6: Run it and resolve the density assertion**

```bash
cd spa && npx vitest run src/styles/__tests__/palette-contrast.test.ts
```

The dark block does not redeclare density tokens, so that one assertion fails. Fix the **test**, not the CSS — duplicating identical values into `dark` would be redundant. Change the density block to:

```typescript
  it('declares density tokens', () => {
    // Only :root and floor declare these; dark inherits :root via the cascade.
    if (theme === 'dark') return;
    expect(t['--row-height']).toBeDefined();
    expect(t['--hit-min']).toBeDefined();
    expect(t['--font-size-body']).toBeDefined();
  });
```

- [ ] **Step 7: Re-run and fix any real contrast failures**

```bash
cd spa && npx vitest run src/styles/__tests__/palette-contrast.test.ts
```

Every remaining failure is a genuine palette bug. Fix it in `tokens.css` by darkening or lightening the offending token — **never** by lowering a threshold. Record any value you change so Task 12 can update the spec's palette tables to match reality.

- [ ] **Step 8: Commit**

```bash
git add spa/src/lib/contrast.ts spa/src/lib/__tests__/contrast.test.ts \
        spa/src/styles/__tests__/palette-contrast.test.ts spa/src/styles/tokens.css
git commit -m "test: WCAG contrast gate for all three Atelier palettes"
```

---

### Task 5: Wire the tokens into Tailwind

`tailwind.config.ts` maps every token through `color-mix(...)` so opacity modifiers (`bg-accent/10`) work — a plain `var(--accent)` makes those classes silently vanish from the stylesheet. That mechanism is correct and stays. What changes: the `fontSize` scale gains a `3xl` step for the serif, `darkMode` must not break under the new `floor` theme, and the config picks up the new density tokens.

**Files:**
- Modify: `spa/tailwind.config.ts`

**Interfaces:**
- Consumes: every custom property from Task 3.
- Produces: utility classes `text-3xl`, `h-row`, `min-h-hit`, `min-w-hit`, `font-display`. Task 7 uses `h-row`/`min-h-hit`; Task 9 uses `font-display`.

- [ ] **Step 1: Extend the type scale**

In `theme.extend.fontSize`, keep `2xs` through `lg` exactly as they are and replace the top three:

```typescript
      fontSize: {
        '2xs': ['10px', { lineHeight: '1.4' }],
        xs: ['11px', { lineHeight: '1.4' }],
        sm: ['12px', { lineHeight: '1.4' }],
        base: ['13px', { lineHeight: '1.5' }],
        md: ['14px', { lineHeight: '1.4' }],
        lg: ['16px', { lineHeight: '1.3' }],
        xl: ['20px', { lineHeight: '1.25' }],
        '2xl': ['26px', { lineHeight: '1.15' }],
        '3xl': ['32px', { lineHeight: '1.1' }],
      },
```

`xl` moves 18px → 20px and `2xl` 22px → 26px. Instrument Serif has a small x-height relative to Public Sans; at the old sizes it reads as smaller than the sans text beside it.

- [ ] **Step 2: Add the density tokens**

Add two new keys inside `theme.extend`, after `borderRadius`:

```typescript
      height: {
        row: 'var(--row-height)',
      },

      minHeight: {
        hit: 'var(--hit-min)',
      },

      minWidth: {
        hit: 'var(--hit-min)',
      },
```

- [ ] **Step 3: Leave `darkMode` alone, and understand why**

The config declares:

```typescript
  darkMode: ['selector', '[data-theme="dark"]'],
```

Do **not** change this to also match `floor`. `dark:` variants are a page-authoring mechanism, and `pages/` uses zero of them. Floor styling comes entirely from the token values, which is why floor works without Tailwind knowing it exists. If a `dark:` variant is ever needed on a floor route, that is a bug in the component — it should read a token instead.

- [ ] **Step 4: Verify the font family names match Task 2**

Confirm `theme.extend.fontFamily` reads:

```typescript
      fontFamily: {
        sans: ['Public Sans Variable', 'system-ui', 'sans-serif'],
        mono: ['Spline Sans Mono Variable', 'SF Mono', 'Menlo', 'monospace'],
        display: ['Instrument Serif', 'Georgia', 'serif'],
      },
```

These strings must match the families `@fontsource` registers. A typo here fails silently — the browser falls back and nothing errors.

- [ ] **Step 5: Update the file's header comment**

The comment block at the top still describes the old system. Replace the first line with:

```typescript
/**
 * All token values come from spa/src/styles/tokens.css (CSS variables).
 * Three palettes: :root (light), [data-theme="dark"], [data-theme="floor"].
 * NEVER hard-code colors or fonts in components — extend here instead.
 *
```

Keep the existing `color-mix` explanation below it — it documents a real trap and is still accurate.

- [ ] **Step 6: Verify the build and check a generated class**

```bash
cd spa && npm run build
```

Expected: PASS. Then confirm opacity modifiers still compile — search the built CSS for an accent-with-alpha rule:

```bash
grep -o 'color-mix[^;]*--accent[^;]*' dist/assets/*.css | head -3
```

Expected: at least one match. Empty output means the `color-mix` wrapper broke and every `bg-accent/10` in the app is now a no-op.

- [ ] **Step 7: Look at it**

```bash
npm run dev
```

Open the app and click through two or three pages. This is the first moment Atelier is actually visible. Expect: warm paper canvas, serif page titles, clay buttons. Expect **also** some over-translucent panels — Task 6 removes those. Note anything else that looks broken; do not fix it yet.

- [ ] **Step 8: Commit**

```bash
git add spa/tailwind.config.ts
git commit -m "feat: map Atelier tokens, serif type scale and density into Tailwind"
```

---

### Task 6: Remove glassmorphism and retune `globals.css`

Atelier surfaces are opaque. Ten files apply `backdrop-blur`, which was doing real work when surfaces were `rgba(...)` translucent and now just costs a compositing layer for no visual effect. `globals.css` also hard-codes `font-size: 13px` on `body`, which would pin the floor PWAs to office density regardless of `--font-size-body`.

Four of the ten files are in `pages/landing/` — **skip those**, track T10 owns landing. Six are in scope.

**Files:**
- Modify: `spa/src/styles/globals.css`
- Modify: `spa/src/components/ui/Skeleton.tsx`
- Modify: `spa/src/components/ui/DataTable.tsx`
- Modify: `spa/src/components/layout/Topbar.tsx`
- Modify: `spa/src/layouts/PortalLayout.tsx`
- Modify: `spa/src/pages/landing/components/CookieBanner.tsx` *(only if trivial — see Step 4)*

- [ ] **Step 1: Make body typography follow the palette**

In `spa/src/styles/globals.css`, inside `@layer base`, replace the `body` rule:

```css
  body {
    background-color: var(--bg-canvas);
    color: var(--text-primary);
    font-size: var(--font-size-body);
    line-height: 1.5;
    margin: 0;
  }
```

Only `font-size` changes. Office palettes declare `--font-size-body: 13px`, so office rendering is byte-identical; floor gets 15px for free.

- [ ] **Step 2: Drop the stale font-feature settings**

The `html` rule sets `font-feature-settings: 'cv11', 'ss01'` — those are Geist stylistic sets. Public Sans has no `cv11`, and its `ss01` is unrelated. Replace the `html` rule:

```css
  html {
    font-family: var(--font-sans);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }
```

- [ ] **Step 3: Find every blur site in scope**

```bash
cd spa
grep -rn 'backdrop-blur' src/components/ src/layouts/
```

Expected: 4 files — `ui/Skeleton.tsx`, `ui/DataTable.tsx`, `layout/Topbar.tsx`, `layouts/PortalLayout.tsx`.

- [ ] **Step 4: Strip the blur classes**

In each of those four files, delete the `backdrop-blur-*` class from the `className` string. Leave every other class alone — the surrounding `bg-surface` / `bg-elevated` tokens are already opaque after Task 3, so removing the blur is a pure subtraction.

If a class list also carries an explicit alpha modifier on a surface (for example `bg-surface/80`), drop the `/80` too. Translucent surfaces are what glassmorphism was; Atelier has none.

Landing files (`pages/landing/**`) are **out of scope**. Leave their blur in place — it will be revisited with the rest of landing in T10, and touching it now creates a conflict with that track.

- [ ] **Step 5: Verify**

```bash
cd spa
grep -rn 'backdrop-blur' src/components/ src/layouts/
npm run typecheck && npm run lint && npm run test:run
```

Expected: the grep prints nothing; all three commands pass.

- [ ] **Step 6: Look at the affected surfaces**

```bash
npm run dev
```

Check specifically: the sticky `Topbar`, a long `DataTable` with a sticky header, any loading `Skeleton`, and a portal route using `PortalLayout`. Each should read as flat opaque paper with a hairline border. If a sticky header now looks like it is *missing* separation from the rows scrolling under it, that is a real regression — add `border-b border-default`, not blur.

- [ ] **Step 7: Commit**

```bash
git add spa/src/styles/globals.css spa/src/components/ui/Skeleton.tsx \
        spa/src/components/ui/DataTable.tsx spa/src/components/layout/Topbar.tsx \
        spa/src/layouts/PortalLayout.tsx
git commit -m "feat: drop glassmorphism from app chrome, make body type follow palette"
```

---

### Task 7: Floor palette wiring (TDD)

The three shop-floor PWAs — factory, driver, maintenance mobile — all render through a single shared component, `components/layout/TouchShell.tsx`. The spec estimated four files for this track; it is really two. `FactoryFloorLayout`, `DriverLayout` and `MaintenanceMobileLayout` are each a ten-line wrapper around `TouchShell` and need no changes.

Floor is **never user-selectable**. It is forced by route and must restore the user's real preference on exit — including when a user navigates factory → office → factory, or has two tabs open.

**Files:**
- Modify: `spa/src/stores/themeStore.ts`
- Modify: `spa/src/components/layout/TouchShell.tsx`
- Create: `spa/src/stores/__tests__/themeStore.test.ts`

**Interfaces:**
- Consumes: `[data-theme='floor']` from Task 3.
- Produces: `useThemeStore.getState().pushOverride(theme: 'floor'): void` and `popOverride(): void`. Nothing outside `TouchShell` calls these.

- [ ] **Step 1: Write the failing test**

Create `spa/src/stores/__tests__/themeStore.test.ts`:

```typescript
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useThemeStore } from '../themeStore';

vi.mock('@/api/auth', () => ({
  authApi: { updatePreferences: vi.fn().mockResolvedValue(undefined) },
}));

const attr = () => document.documentElement.getAttribute('data-theme');

describe('themeStore floor override', () => {
  beforeEach(() => {
    useThemeStore.getState().popOverride();
    useThemeStore.getState().init('light');
  });

  it('applies floor to the document when pushed', () => {
    useThemeStore.getState().pushOverride('floor');
    expect(attr()).toBe('floor');
  });

  it('restores the user preference when popped', () => {
    useThemeStore.getState().pushOverride('floor');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('light');
  });

  it('restores a dark preference, not a hardcoded light', () => {
    useThemeStore.getState().setMode('dark');
    useThemeStore.getState().pushOverride('floor');
    expect(attr()).toBe('floor');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('dark');
  });

  it('keeps mode unchanged while overridden — floor is not a user choice', () => {
    useThemeStore.getState().setMode('dark');
    useThemeStore.getState().pushOverride('floor');
    expect(useThemeStore.getState().mode).toBe('dark');
  });

  it('ignores a preference change made while overridden, but honours it after', () => {
    useThemeStore.getState().pushOverride('floor');
    useThemeStore.getState().setMode('light');
    expect(attr()).toBe('floor');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('light');
  });

  it('is idempotent — a second push does not corrupt the saved preference', () => {
    useThemeStore.getState().setMode('dark');
    useThemeStore.getState().pushOverride('floor');
    useThemeStore.getState().pushOverride('floor');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('dark');
  });

  it('popping without a push is a no-op', () => {
    useThemeStore.getState().setMode('dark');
    useThemeStore.getState().popOverride();
    expect(attr()).toBe('dark');
  });
});
```

The fifth and sixth cases are the ones that matter. A naive implementation that stores the previous theme on every `pushOverride` call corrupts it on the second call; one that lets `apply()` run while overridden flickers the office palette onto a factory tablet.

- [ ] **Step 2: Run it to confirm it fails**

```bash
cd spa && npx vitest run src/stores/__tests__/themeStore.test.ts
```

Expected: FAIL — `pushOverride is not a function`.

- [ ] **Step 3: Implement the override in `themeStore.ts`**

Add `'floor'` to the applied-theme type, then add the override field and two actions to the interface:

```typescript
export type ThemeMode = 'light' | 'dark' | 'system';
/** What actually lands on <html data-theme>. `floor` is route-forced, never chosen. */
export type AppliedTheme = 'light' | 'dark' | 'floor';

interface ThemeState {
  mode: ThemeMode;
  resolvedTheme: 'light' | 'dark';
  /** Non-null while a route forces a palette. Suppresses mode-driven application. */
  override: AppliedTheme | null;
  setMode: (mode: ThemeMode) => void;
  init: (initialMode?: ThemeMode) => void;
  apply: () => void;
  /** Forces a palette for the current route. Idempotent. */
  pushOverride: (theme: AppliedTheme) => void;
  /** Releases the override and restores the user's preference. Safe to call unpaired. */
  popOverride: () => void;
}
```

Widen `applyToDocument` to accept `AppliedTheme`, then add a single helper that every code path goes through — this is what keeps `mode` and the DOM from disagreeing:

```typescript
const applyToDocument = (theme: AppliedTheme) => {
  if (typeof document !== 'undefined') {
    document.documentElement.setAttribute('data-theme', theme);
  }
};
```

In the store body, set `override: null` in the initial state and add:

```typescript
  pushOverride: (theme) => {
    // Idempotent: re-pushing the same override must not disturb anything.
    if (get().override === theme) return;
    applyToDocument(theme);
    set({ override: theme });
  },

  popOverride: () => {
    if (get().override === null) return;
    set({ override: null });
    // resolvedTheme already tracks the user's preference — setMode and the
    // system listener keep it current even while overridden.
    applyToDocument(get().resolvedTheme);
  },
```

Then make the three existing actions respect the override. In `setMode`, `init` and `apply`, replace each bare `applyToDocument(resolvedTheme)` call with a guarded one:

```typescript
    if (get().override === null) applyToDocument(resolvedTheme);
```

`set({ ... resolvedTheme })` still runs unconditionally in all three — the preference stays current underneath the override, which is exactly what makes `popOverride` restore the right palette.

- [ ] **Step 4: Run the test — it must pass**

```bash
cd spa && npx vitest run src/stores/__tests__/themeStore.test.ts
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Wire it into `TouchShell`**

`TouchShell` is the single component all three floor PWAs render through. Add the effect near the top of the component body:

```typescript
import { useEffect } from 'react';
import { useThemeStore } from '@/stores/themeStore';

// …inside the component:
  useEffect(() => {
    const { pushOverride, popOverride } = useThemeStore.getState();
    pushOverride('floor');
    return () => popOverride();
  }, []);
```

Reading via `getState()` rather than a selector hook keeps the actions out of the dependency array — they are stable, and subscribing would re-run the effect on unrelated theme changes.

- [ ] **Step 6: Verify the whole suite still passes**

```bash
cd spa && npm run typecheck && npm run lint && npm run test:run
```

Expected: PASS.

- [ ] **Step 7: Check it by hand**

```bash
npm run dev
```

Walk this exact path: open an office route in dark mode → navigate to `/factory` → confirm near-black canvas, safety-orange accent, taller rows → navigate back to an office route → confirm dark (**not** light) returns. Repeat with the office preference set to light.

- [ ] **Step 8: Commit**

```bash
git add spa/src/stores/themeStore.ts spa/src/stores/__tests__/themeStore.test.ts \
        spa/src/components/layout/TouchShell.tsx
git commit -m "feat: route-forced floor palette for the shop-floor PWAs"
```

---

### Task 8: Detokenize the three token-bypassing components

Three files hard-code colour and would render wrong — or invisibly — under the floor palette.

`components/mrp/MoldShotMeter.tsx` is the only file in the entire app using raw Tailwind palette classes: `bg-rose-500`, `bg-amber-500`, `bg-emerald-500`, plus `text-*-600 dark:text-*-400` pairs. It already computes a `statusVariant` (`'danger' | 'warning' | 'success'`) two lines below the colours — the semantic mapping exists and is simply not used for the bar.

The two chart files read tokens correctly but their CSS fallbacks are stale Tailwind grays (`var(--text-muted, #6b7280)`). Fallbacks only fire if a token is missing, so they are latent — but they are hex literals outside `tokens.css`, which Task 10's CI gate will reject.

**Files:**
- Modify: `spa/src/components/mrp/MoldShotMeter.tsx`
- Modify: `spa/src/components/charts/DowntimeParetoChart.tsx`
- Modify: `spa/src/components/charts/OeeGaugeChart.tsx`

- [ ] **Step 1: Replace the bar colour in `MoldShotMeter.tsx`**

Around line 31, replace the `barColor` ternary:

```typescript
  const barColor = isExceeded
    ? 'bg-danger'
    : isNearing
      ? 'bg-warning'
      : 'bg-success';
```

- [ ] **Step 2: Replace the percentage text colour**

Around line 55, the `dark:` pairs collapse to a single token — the token already differs per palette, which is the whole point:

```typescript
          className={`font-mono text-2xs font-medium ${
            isExceeded ? 'text-danger' : isNearing ? 'text-warning' : 'text-success'
          }`}
```

- [ ] **Step 3: Replace the remaining palette classes in that file**

```bash
cd spa
grep -nE '\b(bg|text|border|ring)-(rose|amber|emerald)-[0-9]{2,3}' src/components/mrp/MoldShotMeter.tsx
```

Work through every hit. The mapping is mechanical:

| Raw class | Token class |
|---|---|
| `bg-amber-500` | `bg-warning` |
| `bg-amber-500/10` | `bg-warning/10` |
| `border-amber-500/30` | `border-warning/30` |
| `text-amber-500`, `text-amber-600` | `text-warning` |
| `text-amber-800 dark:text-amber-200` | `text-warning-fg` |
| `bg-emerald-500`, `text-emerald-500`, `text-emerald-600` | `bg-success`, `text-success` |
| `bg-rose-500`, `text-rose-600` | `bg-danger`, `text-danger` |

Delete every `dark:` variant you encounter — the token resolves per palette, so a `dark:` override is now actively wrong.

- [ ] **Step 4: Fix the chart fallbacks**

In `charts/DowntimeParetoChart.tsx` and `charts/OeeGaugeChart.tsx`, every `var(--token, #hex)` keeps its token and loses its hex fallback. Recharts takes plain strings, so `'var(--text-muted)'` is fine on its own.

| Current | Replace with |
|---|---|
| `var(--border-subtle, #e5e7eb)` | `var(--border-subtle)` |
| `var(--text-muted, #6b7280)` | `var(--text-muted)` |
| `var(--border-default, #e5e7eb)` | `var(--border-default)` |
| `var(--danger, #ef4444)` | `var(--danger)` |
| `var(--warning, #f59e0b)` | `var(--warning)` |
| `var(--success, #22c55e)` | `var(--success)` |
| `var(--bg-elevated, #f3f4f6)` | `var(--bg-elevated)` |

- [ ] **Step 5: Verify no hex or raw palette classes remain in `src/`**

```bash
cd spa
grep -rnE '\b(bg|text|border|ring|from|to|via)-(gray|slate|zinc|neutral|stone|red|green|blue|amber|emerald|indigo|purple|yellow|orange|pink|teal|cyan|rose)-[0-9]{2,3}' src/ \
  | grep -v '^src/pages/landing/'
grep -rnE '#[0-9a-fA-F]{6}\b' src/components/ src/layouts/ src/stores/ src/lib/ \
  | grep -v '^src/lib/__tests__/'
```

Expected: both print nothing. Landing is excluded (T10 owns it); `lib/__tests__/contrast.test.ts` legitimately contains hex fixtures.

- [ ] **Step 6: Verify**

```bash
npm run typecheck && npm run lint && npm run test:run
```

Expected: PASS.

- [ ] **Step 7: Look at the components in all three palettes**

```bash
npm run dev
```

Open an MRP page showing `MoldShotMeter` at each state — under 80% (moss green), 80–100% (ochre), over 100% (oxide red) — in light and dark. Then open the OEE gauge and the downtime Pareto chart. Verify the chart axes and grid are visible against the warm canvas; stale-fallback bugs surface here.

- [ ] **Step 8: Commit**

```bash
git add spa/src/components/mrp/MoldShotMeter.tsx spa/src/components/charts/
git commit -m "fix: route MoldShotMeter and charts through semantic tokens"
```

---

### Task 9: Primitive sweep

All ~50 primitives in `components/ui/` already name roles, so most inherit Atelier correctly and need no edit. This task is a **verification pass**, not a rewrite — the deliverable is confidence plus a short list of real fixes. Resist the urge to redesign; the design language is settled and anything beyond the checklist below is scope creep.

**Files:**
- Modify: files under `spa/src/components/ui/` — only where the checklist finds a genuine fault

- [ ] **Step 1: Build an inventory to work through**

```bash
cd spa && ls src/components/ui/*.tsx | wc -l && ls src/components/ui/*.tsx
```

- [ ] **Step 2: Run the four mechanical checks first**

These find every fault that greps can find, so hand-inspection only has to cover what they cannot:

```bash
cd spa
# a. leftover hard radii — should be tokens
grep -rnE 'rounded-\[' src/components/ui/
# b. hardcoded pixel heights on interactive elements — should be h-row / min-h-hit
grep -rnE 'className="[^"]*\bh-\[[0-9]+px\]' src/components/ui/
# c. any surviving alpha on a surface — glassmorphism remnant
grep -rnE '\bbg-(surface|elevated|canvas|subtle)/[0-9]' src/components/ui/
# d. dark: variants — tokens handle this, so these are now wrong
grep -rn 'dark:' src/components/ui/
```

Fix each hit. For (a) use `rounded-sm|md|lg|full`. For (b) use `h-row` on table rows and `min-h-hit min-w-hit` on touch targets. For (c) delete the alpha modifier. For (d) delete the variant and rely on the token.

- [ ] **Step 3: Check the four primitives that carry the most brand weight**

Open each and confirm it reads correctly in light, dark and floor:

- `Button.tsx` — `primary` is clay with `--accent-fg` text; `secondary` is a hairline border on transparent; the `active:scale-[0.98]` press is intact.
- `Chip.tsx` — every variant uses its `-bg`/`-fg` pair, not `-DEFAULT` on canvas. This is the token pair the contrast gate checks, so a mismatch here means the gate is testing something the UI does not use.
- `DataTable.tsx` — rows use `h-row`; zebra uses `--bg-zebra-even`; the sticky header uses `--bg-thead` **plus a border** (Task 6 removed its blur).
- `Input.tsx` / `Select.tsx` / `Textarea.tsx` — focus state uses `--ring` and `--shadow-focus`, both now clay.

- [ ] **Step 4: Give page titles the display face**

Instrument Serif is loaded and mapped to `font-display` but nothing uses it yet. `components/layout/PageHeader.tsx` renders the title on essentially every page — the single highest-leverage place for it.

In `PageHeader.tsx`, the title is an `<h1>` at line 62. Change its classes from `text-xl font-medium` to the serif and the new scale:

```tsx
          <h1 className="font-display text-2xl text-primary truncate">
```

Drop `font-medium` — Instrument Serif ships only at 400, so a weight class here would trigger synthetic bolding, which looks smeared. Leave `truncate` and the subtitle/breadcrumb/action elements on `font-sans`. Mixing serif into small UI text is the fastest way to make an editorial identity look like a mistake.

- [ ] **Step 5: Verify**

```bash
cd spa && npm run typecheck && npm run lint && npm run test:run
```

Expected: PASS.

- [ ] **Step 6: Screenshot-diff against the baseline**

```bash
npm run test:defense
```

Compare `docs/defense-screenshots/` against `docs/atelier-baseline/` from Task 1. Every difference should be explainable as an intended Atelier change. Anything you cannot explain is a bug — chase it now, while the change set is still small.

- [ ] **Step 7: Commit**

```bash
git add spa/src/components/ui/ spa/src/components/layout/PageHeader.tsx
git commit -m "feat: Atelier primitive sweep, serif page titles"
```

---

### Task 10: Token discipline CI gate

The codebase reached zero raw-palette classes in `pages/` on its own; that is the property this whole rebrand rests on. Make it structural so it survives the next contributor — including future you at 2am.

**Files:**
- Create: `spa/scripts/check-token-discipline.mjs`
- Modify: `spa/package.json`

- [ ] **Step 1: Write the checker**

Create `spa/scripts/check-token-discipline.mjs`:

```javascript
#!/usr/bin/env node
/**
 * Fails if any source file outside styles/tokens.css hard-codes a colour.
 * The Atelier rebrand works because every colour decision resolves through a
 * token; this gate is what keeps that true.
 *
 * Run: npm run audit:tokens
 */
import { readFileSync, globSync } from 'node:fs';
import { relative } from 'node:path';

const PALETTE = /\b(?:bg|text|border|ring|from|to|via|divide|outline|shadow|fill|stroke|decoration|accent|caret)-(?:gray|slate|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/;
const HEX = /#[0-9a-fA-F]{6}\b/;

/** Landing (track T10) and the contrast test's hex fixtures are exempt for now. */
const EXEMPT = [
  /^src\/styles\/tokens\.css$/,
  /^src\/pages\/landing\//,
  /^src\/lib\/__tests__\/contrast\.test\.ts$/,
  /^src\/styles\/__tests__\//,
];

const files = globSync('src/**/*.{ts,tsx,css}', { cwd: process.cwd() });
const violations = [];

for (const file of files) {
  const rel = relative('.', file);
  if (EXEMPT.some((re) => re.test(rel))) continue;

  const lines = readFileSync(file, 'utf8').split('\n');
  lines.forEach((line, i) => {
    if (line.trimStart().startsWith('//') || line.trimStart().startsWith('*')) return;
    const palette = PALETTE.exec(line);
    if (palette) violations.push(`${rel}:${i + 1}  raw palette class  ${palette[0]}`);
    const hex = HEX.exec(line);
    if (hex) violations.push(`${rel}:${i + 1}  hex literal  ${hex[0]}`);
  });
}

if (violations.length > 0) {
  console.error(`\n✗ ${violations.length} token-discipline violation(s):\n`);
  for (const v of violations) console.error(`  ${v}`);
  console.error(
    '\nUse a semantic token instead (bg-accent, text-danger, border-default).' +
      '\nColour values belong only in src/styles/tokens.css.\n',
  );
  process.exit(1);
}

console.log(`✓ token discipline clean — ${files.length} files checked`);
```

- [ ] **Step 2: Add the script**

In `spa/package.json`, add to `"scripts"`:

```json
    "audit:tokens": "node scripts/check-token-discipline.mjs",
```

- [ ] **Step 3: Run it — it must pass**

```bash
cd spa && npm run audit:tokens
```

Expected: `✓ token discipline clean`. If it reports violations, they are real — Task 8 missed something. Fix the source, not the regex.

- [ ] **Step 4: Prove the gate actually catches things**

Temporarily add `className="bg-emerald-500"` to any component, re-run, and confirm it fails with that file and line. Then revert. A gate that has never failed is a gate you cannot trust.

```bash
npm run audit:tokens   # expect: exit 1, pointing at your temporary line
git checkout -- <that file>
npm run audit:tokens   # expect: clean
```

- [ ] **Step 5: Commit**

```bash
git add spa/scripts/check-token-discipline.mjs spa/package.json
git commit -m "test: CI gate rejecting hardcoded colours outside tokens.css"
```

---

### Task 11: Brand assets

The app currently ships generic PWA icons and a placeholder favicon. Three PWAs (factory, driver, maintenance) install to home screens, so these are the brand's most visible surface on a phone.

**Files:**
- Modify: `spa/public/favicon.svg`
- Modify: `spa/public/driver-icon-192.png`, `spa/public/driver-icon-512.png`
- Modify: `spa/public/factory-manifest.webmanifest`, `spa/public/driver-manifest.webmanifest`
- Modify: `spa/index.html:24`

- [ ] **Step 1: Find every brand asset and its references**

```bash
cd spa
ls public/
grep -rn 'theme_color\|background_color\|"icons"' public/*.webmanifest
grep -n 'favicon\|theme-color\|apple-touch' index.html
```

- [ ] **Step 2: Draw the mark as SVG**

The wordmark is "OGAMI" in Instrument Serif; the app icon is a monogram **O** in Instrument Serif, clay `#b4542a` on warm paper `#fdfcfa`. Write `public/favicon.svg` as a square viewBox with the glyph centred:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="Ogami">
  <rect width="64" height="64" rx="14" fill="#fdfcfa"/>
  <text x="32" y="45" text-anchor="middle"
        font-family="Instrument Serif, Georgia, serif" font-size="42" fill="#b4542a">O</text>
</svg>
```

`rx="14"` matches `--radius-lg`, so the icon shares the app's form language.

**Text in an SVG icon depends on the viewer having the font.** Favicons render outside the page, so `@fontsource` does not apply. Before shipping, convert the glyph to a path — open the SVG in any vector editor and outline the text, or run it through an SVG text-to-path tool. Verify the committed file contains a `<path>` and no `<text>` element.

- [ ] **Step 3: Generate the PNG icons**

The two `driver-icon-*.png` files need to be the same monogram rasterised. Any of these works — pick what is installed:

```bash
# ImageMagick
magick -background none public/favicon.svg -resize 192x192 public/driver-icon-192.png
magick -background none public/favicon.svg -resize 512x512 public/driver-icon-512.png

# or rsvg-convert
rsvg-convert -w 192 -h 192 public/favicon.svg -o public/driver-icon-192.png
rsvg-convert -w 512 -h 512 public/favicon.svg -o public/driver-icon-512.png
```

If neither tool is available, note it and move on — the PNGs are cosmetic and do not block anything. Do not add an npm dependency for this.

- [ ] **Step 4: Update the manifest colours**

In **both** `public/factory-manifest.webmanifest` and `public/driver-manifest.webmanifest`, set:

```json
  "background_color": "#0d0b08",
  "theme_color": "#ff8a4c",
```

Both of these are floor-palette apps — factory and driver PWAs render under `[data-theme="floor"]`, so their splash screens must use the floor canvas. A warm-paper splash flashing before a near-black app is a visible seam on every launch.

- [ ] **Step 5: Update `index.html`**

Line 24 currently reads `<meta name="theme-color" content="#ffffff" />`. The default manifest linked on line 23 is the factory (floor) one, but `index.html` also serves every office route, so the meta tag should match the office canvas:

```html
    <meta name="theme-color" content="#fdfcfa" />
```

`sw-register.ts` already swaps the manifest by URL path at runtime; if it does not also swap `theme-color`, that is a pre-existing cosmetic gap — note it and leave it. Do not expand this task into service-worker work.

- [ ] **Step 6: Verify**

```bash
cd spa && npm run build && npm run audit:tokens
```

Expected: PASS. `audit:tokens` only scans `src/`, so the hex values in `public/` and `index.html` are legitimately outside its scope — those files are where brand constants belong.

- [ ] **Step 7: Check the installed appearance**

```bash
npm run dev
```

Confirm the favicon renders in the browser tab (not a broken-image glyph — that means Step 2's text-to-path conversion was skipped). Then open DevTools → Application → Manifest and confirm both manifests parse with the new colours.

- [ ] **Step 8: Commit**

```bash
git add spa/public/ spa/index.html
git commit -m "feat: Atelier brand marks, favicon and PWA manifest colours"
```

---

### Task 12: Re-sync the design documentation

Three documents currently mandate the system Atelier replaces. `CLAUDE.md` is read automatically on every Claude Code command, so a stale design section there actively misdirects future work — it is the highest-value fix in this task, not the lowest.

**Files:**
- Modify: `CLAUDE.md` — the "DESIGN SYSTEM QUICK REFERENCE" section
- Modify: `docs/DESIGN-SYSTEM.md`
- Modify: `docs/PATTERNS.md` — any styling guidance that names old tokens
- Modify: `docs/superpowers/specs/2026-08-07-atelier-frontend-redesign.md` — only if Task 4 changed palette values

- [ ] **Step 1: Find every stale claim**

```bash
cd /home/kwat0g/Desktop/kwatog
grep -rniE 'geist|bricolage|glassmorphic|glassmorphism|grayscale|indigo' CLAUDE.md docs/DESIGN-SYSTEM.md docs/PATTERNS.md
```

- [ ] **Step 2: Rewrite the `CLAUDE.md` design section**

Replace the whole "DESIGN SYSTEM QUICK REFERENCE" block with:

```markdown
## DESIGN SYSTEM QUICK REFERENCE

Full spec in `docs/DESIGN-SYSTEM.md`. Brand: **Atelier** — editorial, warm, unhurried.

- **Font:** Instrument Serif (display/page titles) + Public Sans (UI) + Spline Sans Mono (numbers, IDs, tables)
- **Canvas:** Warm paper (`#fdfcfa`) with espresso ink (`#1f1b16`). Opaque surfaces — no translucency, no backdrop-blur
- **Accent:** Clay (`#b4542a`). Semantics are brand-hued: moss, ochre, oxide red, slate blue, plum
- **Three palettes:** `:root` light · `[data-theme="dark"]` espresso · `[data-theme="floor"]` high-contrast for shop-floor PWAs (route-forced by `TouchShell`, never user-selectable)
- **Tables:** 32px rows office / 48px floor via `--row-height`; monospace tabular figures for numbers
- **Sidebar:** Collapsible (240px ↔ 56px rail)
- **Radius:** 8px / 10px / 14px (`sm` / `md` / `lg`)
- **Hierarchy comes from borders, not shadows.** Shadows are for overlays only
- **Animations:** Minimal — loading, progress, status changes only
- **Never hardcode a colour.** All values live in `spa/src/styles/tokens.css`; `npm run audit:tokens` enforces it
```

- [ ] **Step 3: Rewrite `docs/DESIGN-SYSTEM.md`**

Replace its palette, typography and form-language sections with §3 of the spec. Where the spec and this plan's Task 3 disagree — because Task 4's contrast gate forced a value change — **the shipped `tokens.css` is the source of truth.** Copy from the code, not from the spec.

- [ ] **Step 4: Update `docs/PATTERNS.md`**

Only fix styling guidance that is now wrong: token names, font names, radius values, any example using a raw palette class. Do **not** restructure the page templates — they are unaffected by a reskin and rewriting them here is scope creep.

- [ ] **Step 5: Reconcile the spec with reality**

If Task 4 changed any palette value, update the tables in §3.3 of the spec to match `tokens.css`, and add a line under each changed table noting it was adjusted to clear the contrast gate. A spec that disagrees with shipped code is worse than no spec.

- [ ] **Step 6: Full verification**

```bash
cd spa
npm run typecheck && npm run lint && npm run test:run && npm run audit:tokens
npm run build
npm run audit:live-routes && npm run audit:dynamic-routes
npm run test:e2e
```

Expected: all PASS. Route audits confirm all 355 pages still render; e2e confirms flows still work. If e2e has failures, check them against the Task 1 baseline before assuming Atelier caused them.

- [ ] **Step 7: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog
git add CLAUDE.md docs/DESIGN-SYSTEM.md docs/PATTERNS.md docs/superpowers/specs/
git commit -m "docs: re-sync design documentation to the Atelier system"
```

---

## Done when

- [ ] `npm run typecheck`, `lint`, `test:run`, `audit:tokens`, `build` all pass
- [ ] `audit:live-routes` and `audit:dynamic-routes` pass — all 355 pages render
- [ ] `test:e2e` passes, or every failure is traced to a pre-existing baseline failure
- [ ] Contrast gate green for all three palettes
- [ ] Light, dark and floor verified by hand; floor confirmed to restore the user's real preference on exit
- [ ] No hex literal or raw Tailwind palette class anywhere in `src/` outside `tokens.css` and `pages/landing/`
- [ ] `CLAUDE.md` no longer describes a grayscale, glassmorphic, indigo, Geist-based system

## Follow-on plans

| Track | Scope | Depends on |
|---|---|---|
| T9 | Hero screens — auth (4), role dashboards (12), ShopFloorMap, MRP II Gantt, Quality Pareto/OEE | this plan |
| T10 | Landing page — 27 files, re-authors the `--landing-*` namespace, removes its blur, lifts its exemption from `audit:tokens` | this plan |
| T11 | Blade PDF templates in `api/resources/views/pdf/` — payslip, CoC. Server-rendered, inherits nothing from `tokens.css` | independent |
| T13 | Tech debt — split the 14 pages over 500 LOC, lazy-load `three`/`gsap`/`recharts`/`leaflet` (16 files), optional Tailwind v4 migration | independent |


