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

export function formatDateFull(value: string | Date | null | undefined, fallback = '—'): string {
 const d = toDate(value);
 return d ? format(d, 'EEEE, MMMM d, yyyy') : fallback;
}

export function formatDateIso(value: string | Date | null | undefined, fallback = '—'): string {
 const d = toDate(value);
 return d ? format(d, 'yyyy-MM-dd') : fallback;
}

export function formatDateTime(value: string | Date | null | undefined, fallback = '—'): string {
 const d = toDate(value);
 return d ? format(d, 'MMM d, yyyy · h:mm a') : fallback;
}

export function formatTime(value: string | Date | null | undefined, fallback = '—'): string {
 const d = toDate(value);
 return d ? format(d, 'h:mm a') : fallback;
}

export function formatRelative(value: string | Date | null | undefined, fallback = '—'): string {
 const d = toDate(value);
 return d ? formatDistanceToNow(d, { addSuffix: true }) : fallback;
}

/**
 * Local calendar date as YYYY-MM-DD. Never `toISOString().slice(0, 10)`: that is
 * the UTC date, so in Manila (UTC+8) local midnight lands on the previous day —
 * Jan 1 reads as Dec 31 — and "today" is yesterday until 08:00.
 */
export function localIsoDate(d: Date = new Date()): string {
 return format(d, 'yyyy-MM-dd');
}
