/**
 * The categorical chart palette, in one place.
 *
 * `--chart-1..8` are declared per palette in styles/tokens.css and gated by
 * styles/__tests__/palette-contrast.test.ts (contrast on canvas + pairwise
 * separation, including simulated deuteranopia/protanopia). This module is the
 * only consumer-facing entry point, so a chart never names a colour itself and
 * never reaches for a semantic token to mean "series 3".
 *
 * Order is fixed, and identical across palettes: clay, ochre, olive, jade,
 * teal, indigo, violet, wine. A series keeps its identity when the user
 * switches theme.
 */
export const CHART_SERIES = [
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-3)',
  'var(--chart-4)',
  'var(--chart-5)',
  'var(--chart-6)',
  'var(--chart-7)',
  'var(--chart-8)',
] as const;

/**
 * Colour for series `index`, wrapping after eight.
 *
 * Wrapping is deliberate rather than an error: a ninth category should reuse
 * the palette and be told apart by its label, which every chart here already
 * draws. Past eight a palette stops being legible long before it runs out of
 * hues, so cycling is the honest failure mode.
 */
export function chartColor(index: number): string {
  const safe = Number.isFinite(index) ? Math.trunc(index) : 0;
  return CHART_SERIES[((safe % CHART_SERIES.length) + CHART_SERIES.length) % CHART_SERIES.length];
}
