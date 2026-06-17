/**
 * Landing layout contract — the single source of truth for spacing, containers,
 * cards, and grids across the public marketing site.
 *
 * Why this exists: sections had drifted to a mix of paddings (py-16/20/24/32),
 * radii (rounded-xl/2xl/[2rem]), card paddings (p-6/7/9), and gaps (gap-4/5/6).
 * That inconsistency is most of what made the page feel "busy". Every section
 * now composes these tokens instead of hand-rolling its own, so the vertical
 * rhythm and card language are identical end-to-end.
 *
 * Usage:
 *   <section className={section()}>           // standard section padding
 *   <section className={section('surface')}>  // + raised background
 *   <div className={container}>               // max-width + center
 *   <article className={card()}>              // standard card
 *   <article className={card('interactive')}> // + hover border lift
 */

import { cn } from '@/lib/cn';

/** Page max-width + horizontal centering. Pair with `sectionPadX` on the section. */
export const container = 'mx-auto w-full max-w-6xl';

/** Horizontal page gutter — identical on every section. */
export const sectionPadX = 'px-5 sm:px-8';

/** Vertical section rhythm — ONE scale for the whole page. */
export const sectionPadY = 'py-20 sm:py-28';

/**
 * Standard section shell. Pass a background variant to alternate the rhythm:
 *   'canvas'  — base page background (default)
 *   'surface' — raised panel background, with top/bottom hairline
 */
export function section(
  background: 'canvas' | 'surface' = 'canvas',
  className?: string,
): string {
  return cn(
    'relative',
    sectionPadX,
    sectionPadY,
    background === 'surface'
      ? 'border-y border-landing-border bg-landing-surface'
      : 'bg-landing-canvas',
    className,
  );
}

/** Space between a SectionHeading and the content block beneath it. */
export const headingGap = 'mt-14';

/** Standard grid gap between cards — one value everywhere. */
export const cardGap = 'gap-4';

/**
 * Standard card. ONE radius (xl), ONE padding scale, ONE border language.
 *   'static'      — flat panel, no hover affordance (informational)
 *   'interactive' — border lifts to accent on hover (clickable/feature cards)
 */
export function card(
  variant: 'static' | 'interactive' = 'static',
  className?: string,
): string {
  return cn(
    'relative rounded-xl border border-landing-border bg-landing-surface p-6 sm:p-7',
    variant === 'interactive' &&
      'transition-colors duration-300 hover:border-landing-accent/40',
    className,
  );
}

/** Mono eyebrow/label run used for tags and small caps text. */
export const monoLabel =
  'font-mono text-[11px] uppercase tracking-[0.16em] text-landing-muted';
