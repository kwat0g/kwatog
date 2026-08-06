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
 * this project uses, and ignores @media and nested rules.
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
