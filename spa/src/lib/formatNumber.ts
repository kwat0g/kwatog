const peso = new Intl.NumberFormat('en-PH', {
  style: 'currency',
  currency: 'PHP',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

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
  return n === null ? fallback : peso.format(n);
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
