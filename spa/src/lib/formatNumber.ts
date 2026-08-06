import { getFunctionalCurrency } from './runtimeCurrency';

const integer = new Intl.NumberFormat('en-PH', {
 minimumFractionDigits: 0,
 maximumFractionDigits: 0,
});

const decimal = new Intl.NumberFormat('en-PH', {
 minimumFractionDigits: 2,
 maximumFractionDigits: 2,
});

const percent = new Intl.NumberFormat('en-PH', {
 style: 'percent',
 minimumFractionDigits: 1,
 maximumFractionDigits: 1,
});

const toNumber = (value: number | string | null | undefined): number | null => {
 if (value === null || value === undefined || value === '') return null;
 const n = typeof value === 'number' ? value : Number(value);
 return Number.isFinite(n) ? n : null;
};

export function formatPeso(value: number | string | null | undefined, fallback = '—'): string {
 const n = toNumber(value);
 if (n === null) return fallback;
 const currency = getFunctionalCurrency();
 if (!currency) return decimal.format(n);
 return new Intl.NumberFormat('en', {
 style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2,
 }).format(n);
}

/** Compact currency display that still uses the configured functional currency. */
export function formatCompactCurrency(value: number | string | null | undefined, divisor: number, suffix: string, fallback = '—'): string {
 const n = toNumber(value);
 if (n === null) return fallback;
 const scaled = n / divisor;
 const currency = getFunctionalCurrency();
 return `${currency ? `${currency} ` : ''}${scaled.toFixed(2)}${suffix}`;
}

export function formatInt(value: number | string | null | undefined, fallback = '—'): string {
 const n = toNumber(value);
 return n === null ? fallback : integer.format(n);
}

export function formatDecimal(value: number | string | null | undefined, fallback = '—'): string {
 const n = toNumber(value);
 return n === null ? fallback : decimal.format(n);
}

export function formatPercent(value: number | string | null | undefined, fallback = '—'): string {
 const n = toNumber(value);
 return n === null ? fallback : percent.format(n);
}
