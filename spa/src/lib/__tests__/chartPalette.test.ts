import { describe, it, expect } from 'vitest';
import { CHART_SERIES, chartColor } from '../chartPalette';

describe('chartColor', () => {
  it('walks the palette in declaration order', () => {
    expect(chartColor(0)).toBe('var(--chart-1)');
    expect(chartColor(7)).toBe('var(--chart-8)');
  });

  it('wraps past the eighth series rather than returning undefined', () => {
    expect(chartColor(8)).toBe('var(--chart-1)');
    expect(chartColor(17)).toBe('var(--chart-2)');
  });

  it('answers for negative and non-finite input instead of drawing nothing', () => {
    expect(chartColor(-1)).toBe('var(--chart-8)');
    expect(chartColor(Number.NaN)).toBe('var(--chart-1)');
  });

  it('declares exactly eight series, matching the gate', () => {
    expect(CHART_SERIES).toHaveLength(8);
    expect(new Set(CHART_SERIES).size).toBe(8);
  });
});
