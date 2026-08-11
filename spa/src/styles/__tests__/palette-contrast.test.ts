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

/**
 * Saturated fills that carry ink directly, and the component that does it.
 *
 * SEMANTIC_PAIRS above covers the pale `-bg` chip surfaces with their matching
 * `-fg` ink. This table covers the other half of the system: a SATURATED token
 * used as a button or marker fill with `--accent-fg` (paper-on-ink) on top.
 *
 * It exists because nothing here checked that half, and a component is free to
 * combine a token with a literal. Button.tsx shipped `bg-danger-bg text-white`
 * as the destructive CTA — a chip surface used as a fill — and rendered at
 * 1.23:1 in the office palette across the 63 files that use ConfirmDialog. Both
 * halves of that pairing were invisible here: `--danger-bg` was only ever
 * measured against `--danger-fg`, and `white` is not a token at all.
 *
 * scripts/check-token-discipline.mjs now rejects the literal. This table is the
 * other side of the same gate: it pins the pairing the components actually use,
 * so a rebrand that desaturates one of these fills fails here rather than in
 * production.
 */
const INK_ON_FILL = [
  ['--accent', 'Button variant="primary"; ApprovalTimeline active dot'],
  ['--danger', 'Button variant="danger" — every ConfirmDialog destructive CTA'],
  ['--success', 'ApprovalTimeline approved dot; shipment + careers step markers'],
] as const;

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

  describe.each(['--bg-canvas', '--bg-surface', '--bg-elevated'] as const)('on %s', (bgToken) => {
    it.each(ESSENTIAL_TEXT)(`%s clears ${limits.essential}:1`, (fg) => {
      expect(contrastRatio(t[fg], t[bgToken])).toBeGreaterThanOrEqual(limits.essential);
    });

    it.each(DECORATIVE_TEXT)(`%s clears ${limits.decorative}:1`, (fg) => {
      expect(contrastRatio(t[fg], t[bgToken])).toBeGreaterThanOrEqual(limits.decorative);
    });
  });

  it.each(SEMANTIC_MARKS)(`%s clears ${limits.mark}:1 on canvas`, (mark) => {
    expect(contrastRatio(t[mark], t['--bg-canvas'])).toBeGreaterThanOrEqual(limits.mark);
  });

  it.each(SEMANTIC_PAIRS)('--%s-fg is legible on --%s-bg', (name) => {
    expect(contrastRatio(t[`--${name}-fg`], t[`--${name}-bg`])).toBeGreaterThanOrEqual(
      limits.essential,
    );
  });

  it('--accent-fg is legible on --accent', () => {
    expect(contrastRatio(t['--accent-fg'], t['--accent'])).toBeGreaterThanOrEqual(limits.essential);
  });

  it.each(INK_ON_FILL)('--accent-fg is legible on %s (%s)', (fill) => {
    expect(contrastRatio(t['--accent-fg'], t[fill])).toBeGreaterThanOrEqual(limits.essential);
  });

  /**
   * The pale `-bg` tints are chip SURFACES, not fills. Asserting they fail
   * against --accent-fg is what makes the INK_ON_FILL table above load-bearing:
   * without it, a rebrand could quietly collapse the two ramps together and the
   * "use the saturated token" rule would still pass while meaning nothing.
   */
  it.each(['--danger-bg', '--success-bg'] as const)(
    '%s is a chip surface, not a fill — --accent-fg is NOT legible on it',
    (tint) => {
      expect(contrastRatio(t['--accent-fg'], t[tint])).toBeLessThan(limits.essential);
    },
  );

  it('declares density tokens', () => {
    // Only :root and floor declare these; dark inherits :root via the cascade.
    if (theme === 'dark') return;
    expect(t['--row-height']).toBeDefined();
    expect(t['--hit-min']).toBeDefined();
    expect(t['--font-size-body']).toBeDefined();
  });
});
