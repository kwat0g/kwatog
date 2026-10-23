import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, it, expect } from 'vitest';

/**
 * Narrow-width contracts for two shared primitives (F-009, F-010).
 *
 * These two defects are GEOMETRY defects, and jsdom has no layout engine — a
 * render test cannot see a figure painting past its box or a flex item refusing
 * to shrink, which is exactly how both survived until a real-browser capture
 * measured them. So this test locks the source decision that fixes them, the
 * way `styles/__tests__/blueprint-tokens.test.ts` locks a token contract: a
 * revert to the old class strings fails here, in CI, instead of silently
 * bringing back a horizontally draggable page on a phone.
 *
 * The measurement itself lives in `docs/ui-ux/FINDINGS.md` (F-009, F-010), with
 * the before/after captures in `docs/ui-ux/screenshots/w2-ctl` (edits stashed)
 * vs `w2-after` (edits applied), same probe on both sides.
 */
const read = (rel: string) => readFileSync(join(process.cwd(), 'src', rel), 'utf8');

const statCard = read('components/ui/StatCard.tsx');
const segmented = read('components/ui/SegmentedControl.tsx');
const panel = read('components/ui/Panel.tsx');

/**
 * Source from an anchor onward.
 *
 * The call sites build their class list through `cn(...)` across several
 * string arguments and an interpolated tail, so a single-className regex reads
 * only the first fragment. A window covers the whole element (and, harmlessly,
 * the element that follows it).
 */
const from = (source: string, anchor: string, chars = 1200) => {
  const at = source.indexOf(anchor);
  expect(at, `anchor not found: ${anchor}`).toBeGreaterThan(-1);
  return source.slice(at, at + chars);
};

describe('StatCard figure (F-009)', () => {
  it('sizes the figure against the card, not the viewport', () => {
    // `@container` on the wrapper + `cqw` in the font size: the step follows the
    // card's own width, which is the only thing correct in every grid this card
    // is dropped into (2-up at 375px leaves 132px of content).
    expect(statCard).toContain('@container');
    expect(statCard).toMatch(/text-\[clamp\([^)]*cqw[^)]*\)\]/);
  });

  it('does not pin the figure to a fixed size that can outgrow its box', () => {
    // The old `text-2xl` (26px) ate 175px of a 132px slot for `₱576,912.00`.
    const valueBlock = statCard.slice(statCard.indexOf('font-mono tabular-nums text-primary'));
    expect(valueBlock.slice(0, 200)).not.toMatch(/className="text-2xl /);
  });

  it('keeps the never-overflow backstop, so every digit stays on screen', () => {
    // Wrapping is the fallback for a figure too long even at the 16px floor;
    // truncating or clipping money would hide digits instead.
    expect(statCard).toMatch(/overflow-wrap:anywhere/);
  });
});

describe('SegmentedControl narrow mode (F-010)', () => {
  it('can be narrower than its labels and wraps instead of hiding options', () => {
    // `max-width: 100%` is what lets `flex-wrap` engage at all: a shrink-to-fit
    // box otherwise sizes to max-content and the options can only overflow.
    const root = from(segmented, 'role="radiogroup"');
    expect(root).toContain('max-w-full');
    expect(root).toContain('flex-wrap');
  });

  it('scrolls as the second stage, for an option that cannot fit even alone', () => {
    const root = from(segmented, 'role="radiogroup"');
    expect(root).toContain('overflow-x-auto');
    expect(root).toContain('overflow-y-hidden');
  });

  it('never squashes an option below its label', () => {
    expect(segmented).toContain('shrink-0 whitespace-nowrap');
  });
});

describe('Panel header slot (F-010)', () => {
  it('lets a wide meta control shrink, on both links of the flex chain', () => {
    // A flex item's automatic minimum size is its content's, so without
    // `min-w-0` the 340px control forced the whole header to 411px inside a
    // 333px panel — where `overflow-hidden` cut the last options off entirely.
    expect(panel).toContain('flex min-w-0 items-baseline gap-2');
    expect(panel).toContain('min-w-0 text-xs text-muted');
  });

  it('keeps clipping the rounded corners for everything that declares no narrow mode', () => {
    expect(panel).toContain('rounded-md overflow-hidden');
  });
});
