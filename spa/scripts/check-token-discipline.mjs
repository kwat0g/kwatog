#!/usr/bin/env node
/**
 * Fails if any source file outside styles/tokens.css hard-codes a colour.
 *
 * The Atelier rebrand works because every colour decision resolves through a
 * token — that is the property that let 355 pages restyle without being
 * edited. This gate is what keeps it true.
 *
 * Run: npm run audit:tokens
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { relative, join } from 'node:path';

const PALETTE =
  /\b(?:bg|text|border|ring|from|to|via|divide|outline|shadow|fill|stroke|decoration|accent|caret)-(?:gray|slate|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/;
const HEX = /#[0-9a-fA-F]{6}\b/;

/**
 * Hardcoded neutrals — the hole this gate had until 2026-08-10.
 *
 * PALETTE above only catches Tailwind's numbered ramps (`text-red-500`), and
 * `white` / `black` carry no number, so they sailed through. That is how
 * `bg-danger-bg text-white` shipped as the destructive CTA in Button.tsx: a
 * token background paired with a hardcoded foreground. Neither this gate nor
 * styles/__tests__/palette-contrast.test.ts could see it — the test compares
 * token against token, and `white` is not a token. It rendered at 1.23:1 in the
 * office palette, in the 63 files that use ConfirmDialog plus 113 direct
 * `variant="danger"` call sites.
 *
 * `white` and `black` are palette-invariant by definition, so any component
 * using one has opted out of theming: it cannot follow :root → dark → floor.
 * Use --accent-fg (paper-on-ink, declared per palette) for ink on a saturated
 * fill, or --text-primary / --bg-canvas for ordinary surfaces.
 */
const HARDCODED_NEUTRAL =
  /\b(?:text|bg|border|ring|fill|stroke|divide|outline|from|to|via|decoration|caret|shadow|accent|placeholder)-(?:white|black)(?:\/\d{1,3})?\b/;

/**
 * The one documented exception: a modal scrim.
 *
 * `bg-black/<alpha>` is the overlay wash behind Modal, BottomSheet,
 * CommandPalette and the mobile Sidebar. It is deliberately palette-invariant
 * for the same reason as --plant-map-*: a scrim must darken the page under
 * every theme, so a token that inverts in dark mode would wash the backdrop
 * OUT instead of down. Alpha over an unknown backdrop is also uncontrastable
 * by construction, and no text ever sits directly on a scrim.
 *
 * Narrow on purpose — background only, black only, alpha REQUIRED. An opaque
 * `bg-black`, any `bg-white`, and every `text-*` / `border-*` neutral stay
 * violations, because those are the ones that pair with a token and break
 * contrast.
 */
const SCRIM_EXCEPTION = /\bbg-black\/\d{1,3}\b/;

/**
 * Retired namespace. `--landing-*` was landing's parallel palette; it is gone,
 * and a stale `var(--landing-canvas)` resolves to nothing rather than erroring,
 * so a leftover reference is invisible at runtime — the element just renders
 * transparent. Caught here instead.
 *
 * Two namespaces are deliberately landing-adjacent and permitted:
 *   --blueprint-*  derived from ink, follows every palette
 *   --plant-map-*  theme-invariant, sits on the always-light desaturated sheet
 */
const RETIRED = /var\(\s*(--landing-[a-z-]+)/;

/**
 * Exemptions, each with a reason. Landing used to be exempt while it ran a
 * parallel --landing-* palette; it now resolves through the shared Atelier
 * tokens like every other page, so the gate covers it.
 */
const EXEMPT = [
  /^src\/styles\/tokens\.css$/, // the one place colour values belong
  /^src\/lib\/__tests__\/contrast\.test\.ts$/, // hex fixtures under test
  /^src\/styles\/__tests__\//, // reads tokens.css by design
  /^src\/pages\/landing\/three\//, // WebGL: three.js needs real colour values, not classes
  /^src\/pages\/landing\/components\/HeroCanvas\.tsx$/, // ditto — canvas materials
];

/**
 * Recursive walk, sorted for deterministic output.
 * Deliberately avoids fs.globSync (Node 22+) — CI pins Node 20.
 */
function walk(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (/\.(ts|tsx|css)$/.test(entry)) out.push(p);
  }
  return out;
}

const files = walk('src').sort();
const violations = [];

for (const file of files) {
  const rel = relative('.', file);
  if (EXEMPT.some((re) => re.test(rel))) continue;

  const lines = readFileSync(file, 'utf8').split('\n');
  lines.forEach((line, i) => {
    const trimmed = line.trimStart();
    if (trimmed.startsWith('//') || trimmed.startsWith('*')) return;
    const palette = PALETTE.exec(line);
    if (palette) violations.push(`${rel}:${i + 1}  raw palette class  ${palette[0]}`);
    const hex = HEX.exec(line);
    if (hex) violations.push(`${rel}:${i + 1}  hex literal  ${hex[0]}`);
    const retired = RETIRED.exec(line);
    if (retired) violations.push(`${rel}:${i + 1}  retired token  ${retired[1]}`);
    // Matched globally, then filtered — one line can hold both a scrim and a
    // real violation, so exempting the whole line would reopen the hole.
    for (const m of line.matchAll(new RegExp(HARDCODED_NEUTRAL, 'g'))) {
      if (SCRIM_EXCEPTION.test(m[0])) continue;
      violations.push(`${rel}:${i + 1}  hardcoded neutral  ${m[0]}`);
    }
  });
}

if (violations.length > 0) {
  console.error(`\n✗ ${violations.length} token-discipline violation(s):\n`);
  for (const v of violations) console.error(`  ${v}`);
  console.error(
    '\nUse a semantic token instead (bg-accent, text-danger, border-default).' +
      '\nColour values belong only in src/styles/tokens.css.' +
      '\ntext-white / bg-black etc. are palette-invariant: use --accent-fg for ink' +
      '\non a saturated fill, --text-primary / --bg-canvas otherwise. Only a modal' +
      '\nscrim (bg-black/<alpha>) is exempt.' +
      '\n--landing-* is retired: use the shared Atelier tokens, or --blueprint-*' +
      '\nfor line-work and --plant-map-* for map chrome.\n',
  );
  process.exit(1);
}

console.log(`✓ token discipline clean — ${files.length} files checked`);
