/** Exact cent arithmetic for journal-entry form totals. */

export function toCents(value: string | null | undefined): bigint {
 const normalized = (value ?? '').trim();
 if (normalized === '') return 0n;

 const [whole, fraction = ''] = normalized.split('.');
 return BigInt(whole || '0') * 100n + BigInt((fraction + '00').slice(0, 2));
}

export function fromCents(value: bigint): string {
 const negative = value < 0n;
 const absolute = negative ? -value : value;
 const whole = absolute / 100n;
 const fraction = String(absolute % 100n).padStart(2, '0');
 return `${negative ? '-' : ''}${whole}.${fraction}`;
}
