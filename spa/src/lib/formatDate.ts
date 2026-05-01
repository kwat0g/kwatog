import { format, parseISO, formatDistanceToNow, isValid } from 'date-fns';

const toDate = (value: string | Date | null | undefined): Date | null => {
  if (!value) return null;
  const d = value instanceof Date ? value : parseISO(value);
  return isValid(d) ? d : null;
};

export function formatDate(value: string | Date | null | undefined, fallback = '—'): string {
  const d = toDate(value);
  return d ? format(d, 'MMM d, yyyy') : fallback;
}

export function formatDateLong(value: string | Date | null | undefined, fallback = '—'): string {
  const d = toDate(value);
  return d ? format(d, 'MMMM d, yyyy') : fallback;
}

export function formatDateIso(value: string | Date | null | undefined, fallback = '—'): string {
  const d = toDate(value);
  return d ? format(d, 'yyyy-MM-dd') : fallback;
}

export function formatDateTime(value: string | Date | null | undefined, fallback = '—'): string {
  const d = toDate(value);
  return d ? format(d, 'MMM d, yyyy · HH:mm') : fallback;
}

export function formatRelative(value: string | Date | null | undefined, fallback = '—'): string {
  const d = toDate(value);
  return d ? formatDistanceToNow(d, { addSuffix: true }) : fallback;
}
