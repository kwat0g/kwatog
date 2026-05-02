/**
 * Philippine government ID and contact formatters.
 *
 * Storage rule: backend stores canonical digits-only.
 * Display rule: frontend masks/formats on render and on input.
 *
 * Length references:
 *  - SSS:        10 digits   XX-XXXXXXX-X
 *  - PhilHealth: 12 digits   XX-XXXXXXXXX-X
 *  - Pag-IBIG:   12 digits   XXXX-XXXX-XXXX
 *  - TIN:        9 or 12 digits XXX-XXX-XXX(-XXX)
 *  - PH mobile:  11 digits starting with 09 -> 0917 123 4567
 */

export const PH_ID_LENGTHS = {
  sss: 10,
  philhealth: 12,
  pagibig: 12,
  tinMin: 9,
  tinMax: 12,
  mobile: 11,
} as const;

/** Keep digits only. */
export function digitsOnly(value: string | null | undefined): string {
  if (value == null) return '';
  return String(value).replace(/\D+/g, '');
}

/** SSS: 10 digits → XX-XXXXXXX-X */
export function formatSss(value: string | null | undefined): string {
  const d = digitsOnly(value).slice(0, 10);
  if (d.length <= 2) return d;
  if (d.length <= 9) return `${d.slice(0, 2)}-${d.slice(2)}`;
  return `${d.slice(0, 2)}-${d.slice(2, 9)}-${d.slice(9, 10)}`;
}

/** PhilHealth: 12 digits → XX-XXXXXXXXX-X */
export function formatPhilHealth(value: string | null | undefined): string {
  const d = digitsOnly(value).slice(0, 12);
  if (d.length <= 2) return d;
  if (d.length <= 11) return `${d.slice(0, 2)}-${d.slice(2)}`;
  return `${d.slice(0, 2)}-${d.slice(2, 11)}-${d.slice(11, 12)}`;
}

/** Pag-IBIG: 12 digits → XXXX-XXXX-XXXX */
export function formatPagIbig(value: string | null | undefined): string {
  const d = digitsOnly(value).slice(0, 12);
  if (d.length <= 4) return d;
  if (d.length <= 8) return `${d.slice(0, 4)}-${d.slice(4)}`;
  return `${d.slice(0, 4)}-${d.slice(4, 8)}-${d.slice(8, 12)}`;
}

/** TIN: 9 or 12 digits → XXX-XXX-XXX(-XXX) */
export function formatTin(value: string | null | undefined): string {
  const d = digitsOnly(value).slice(0, 12);
  if (d.length <= 3) return d;
  if (d.length <= 6) return `${d.slice(0, 3)}-${d.slice(3)}`;
  if (d.length <= 9) return `${d.slice(0, 3)}-${d.slice(3, 6)}-${d.slice(6)}`;
  return `${d.slice(0, 3)}-${d.slice(3, 6)}-${d.slice(6, 9)}-${d.slice(9, 12)}`;
}

/** PH mobile: 11 digits starting with 09 → 0917 123 4567 */
export function formatMobile(value: string | null | undefined): string {
  const d = digitsOnly(value).slice(0, 11);
  if (d.length <= 4) return d;
  if (d.length <= 7) return `${d.slice(0, 4)} ${d.slice(4)}`;
  return `${d.slice(0, 4)} ${d.slice(4, 7)} ${d.slice(7, 11)}`;
}

/** PH landline-ish: keep digits, group as XX-XXXX-XXXX or pass-through. */
export function formatLandline(value: string | null | undefined): string {
  const d = digitsOnly(value).slice(0, 12);
  if (d.length <= 2) return d;
  if (d.length <= 6) return `${d.slice(0, 2)}-${d.slice(2)}`;
  return `${d.slice(0, 2)}-${d.slice(2, 6)}-${d.slice(6)}`;
}

// Validators — operate on raw digits.
export function isValidSss(value: string | null | undefined): boolean {
  return digitsOnly(value).length === PH_ID_LENGTHS.sss;
}
export function isValidPhilHealth(value: string | null | undefined): boolean {
  return digitsOnly(value).length === PH_ID_LENGTHS.philhealth;
}
export function isValidPagIbig(value: string | null | undefined): boolean {
  return digitsOnly(value).length === PH_ID_LENGTHS.pagibig;
}
export function isValidTin(value: string | null | undefined): boolean {
  const n = digitsOnly(value).length;
  return n >= PH_ID_LENGTHS.tinMin && n <= PH_ID_LENGTHS.tinMax;
}
export function isValidMobile(value: string | null | undefined): boolean {
  const d = digitsOnly(value);
  return d.length === PH_ID_LENGTHS.mobile && d.startsWith('09');
}

/** Generic id mask used in form inputs. */
export type PhIdKind = 'sss' | 'philhealth' | 'pagibig' | 'tin' | 'mobile' | 'landline';

export function formatByKind(kind: PhIdKind, value: string | null | undefined): string {
  switch (kind) {
    case 'sss':       return formatSss(value);
    case 'philhealth':return formatPhilHealth(value);
    case 'pagibig':   return formatPagIbig(value);
    case 'tin':       return formatTin(value);
    case 'mobile':    return formatMobile(value);
    case 'landline':  return formatLandline(value);
  }
}

export function placeholderFor(kind: PhIdKind): string {
  switch (kind) {
    case 'sss':        return '34-5678901-2';
    case 'philhealth': return '12-345678901-2';
    case 'pagibig':    return '1234-5678-9012';
    case 'tin':        return '123-456-789-000';
    case 'mobile':     return '0917 123 4567';
    case 'landline':   return '02-1234-5678';
  }
}

/**
 * Mask all but the last `visibleTrailing` digits while preserving the
 * separator pattern of the formatted ID. Used for PII display.
 *
 *   maskFormatted("12-3456789-0", 2) => "**-*******8-0"
 */
export function maskFormatted(formatted: string, visibleTrailing = 2): string {
  if (!formatted) return formatted;
  const digitIdx: number[] = [];
  for (let i = 0; i < formatted.length; i++) {
    if (/\d/.test(formatted[i])) digitIdx.push(i);
  }
  const cutoff = digitIdx.length - visibleTrailing;
  const out = formatted.split('');
  digitIdx.forEach((pos, i) => {
    if (i < cutoff) out[pos] = '•';
  });
  return out.join('');
}

export function maskByKind(kind: PhIdKind, value: string | null | undefined, visible = false): string {
  const formatted = formatByKind(kind, value);
  if (!formatted) return '';
  return visible ? formatted : maskFormatted(formatted, kind === 'mobile' ? 4 : 2);
}

export function helperFor(kind: PhIdKind): string {
  switch (kind) {
    case 'sss':        return '10 digits';
    case 'philhealth': return '12 digits';
    case 'pagibig':    return '12 digits';
    case 'tin':        return '9 or 12 digits';
    case 'mobile':     return '11 digits, starts with 09';
    case 'landline':   return 'Optional area + number';
  }
}
