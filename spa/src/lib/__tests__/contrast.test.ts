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
