/**
 * Client-side money helpers.
 *
 * Decimals cross the wire as strings so that `decimal(15,2)` precision
 * survives — parsing two of them into JS doubles and adding them reintroduces
 * exactly the binary-float error the column type exists to prevent. When the
 * client genuinely has to combine money values (a progress bar, a running
 * subtotal shown before the server confirms it), do the arithmetic in integer
 * centavos and convert only at the display boundary.
 */

/**
 * Parse a `decimal(15,2)` string into whole centavos without ever passing
 * through a fractional double.
 *
 * `'1234.56'` → `123456`. `'1234.5'` → `123450`. `'1234'` → `123400`.
 * `'-0.01'` → `-1`. Empty/null/undefined → `0`.
 *
 * More than two decimal places are truncated rather than rounded: a value with
 * extra precision did not come from a money column, so silently rounding it
 * would hide the upstream defect.
 */
export function toCentavos(value: string | number | null | undefined): number {
  const raw = String(value ?? '0').trim();
  if (raw === '') return 0;

  const [whole = '0', fraction = ''] = raw.split('.');
  const sign = whole.startsWith('-') ? -1 : 1;
  const wholeDigits = whole.replace(/^[+-]/, '') || '0';
  const centavoDigits = `${fraction}00`.slice(0, 2);

  const magnitude = Number(wholeDigits) * 100 + Number(centavoDigits);
  return Number.isFinite(magnitude) ? sign * magnitude : 0;
}

/** Format whole centavos back to a `decimal(15,2)`-shaped string. */
export function fromCentavos(centavos: number): string {
  const sign = centavos < 0 ? '-' : '';
  const abs = Math.abs(Math.trunc(centavos));
  return `${sign}${Math.trunc(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}
