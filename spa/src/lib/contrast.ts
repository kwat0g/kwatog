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
  return 0.2126 * linearChannel(r) + 0.7152 * linearChannel(g) + 0.0722 * linearChannel(b);
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

/** Linear sRGB → CIE L*a*b* (D65). */
export function rgbToLab({ r, g, b }: Rgb): [number, number, number] {
  const R = linearChannel(r);
  const G = linearChannel(g);
  const B = linearChannel(b);

  const X = (0.4124564 * R + 0.3575761 * G + 0.1804375 * B) / 0.95047;
  const Y = 0.2126729 * R + 0.7151522 * G + 0.072175 * B;
  const Z = (0.0193339 * R + 0.119192 * G + 0.9503041 * B) / 1.08883;

  const f = (t: number) => (t > 0.008856 ? Math.cbrt(t) : 7.787 * t + 16 / 116);
  const fx = f(X);
  const fy = f(Y);
  const fz = f(Z);

  return [116 * fy - 16, 500 * (fx - fy), 200 * (fy - fz)];
}

function linearChannel(c: number): number {
  const s = c / 255;
  return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
}

function encodeChannel(c: number): number {
  const s = c <= 0.0031308 ? c * 12.92 : 1.055 * Math.pow(c, 1 / 2.4) - 0.055;
  return Math.round(Math.max(0, Math.min(1, s)) * 255);
}

/**
 * CIE76 colour difference. Crude next to CIEDE2000, but it is monotonic with
 * perceived difference and needs no lookup tables — enough to hold a
 * *categorical* palette to "these two series are visibly not the same colour".
 */
export function deltaE(a: string, b: string): number {
  const ca = hexToRgb(a);
  const cb = hexToRgb(b);
  if (!ca || !cb) throw new Error(`deltaE: unparseable colour ${!ca ? a : b}`);

  const [l1, a1, b1] = rgbToLab(ca);
  const [l2, a2, b2] = rgbToLab(cb);

  return Math.hypot(l1 - l2, a1 - a2, b1 - b2);
}

/**
 * Dichromacy simulation — Machado, Oliveira & Fernandes (2009), severity 1.0.
 *
 * Coefficients are applied in LINEAR sRGB, which is the space the published
 * matrices are defined in. Applying them to gamma-encoded values (a common
 * shortcut) exaggerates lightness and makes a palette look safer than it is.
 *
 * Only deuteranopia and protanopia are modelled: they are ~99% of red-green
 * colour vision deficiency and the two that collapse a chart legend into
 * indistinguishable swatches. Tritanopia is rare enough that supporting it
 * would cost series count without buying real legibility.
 */
const DEUTERANOPIA = [
  [0.367322, 0.860646, -0.227968],
  [0.280085, 0.672501, 0.047413],
  [-0.01182, 0.04294, 0.968881],
] as const;

const PROTANOPIA = [
  [0.152286, 1.052583, -0.204868],
  [0.114503, 0.786281, 0.099216],
  [-0.003882, -0.048116, 1.051998],
] as const;

function applyMatrix(matrix: ReadonlyArray<ReadonlyArray<number>>, hex: string): string {
  const rgb = hexToRgb(hex);
  if (!rgb) throw new Error(`simulate: unparseable colour ${hex}`);

  const linear = [linearChannel(rgb.r), linearChannel(rgb.g), linearChannel(rgb.b)];
  const out = matrix.map((row) => row[0] * linear[0] + row[1] * linear[1] + row[2] * linear[2]);

  return `#${out.map((c) => encodeChannel(c).toString(16).padStart(2, '0')).join('')}`;
}

/** How a colour looks to a deuteranope (green cones absent). */
export function simulateDeuteranopia(hex: string): string {
  return applyMatrix(DEUTERANOPIA, hex);
}

/** How a colour looks to a protanope (red cones absent). */
export function simulateProtanopia(hex: string): string {
  return applyMatrix(PROTANOPIA, hex);
}

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
