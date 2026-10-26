import { format, parseISO, formatDistanceToNow, isValid } from 'date-fns';

const manilaDateTime = new Intl.DateTimeFormat('en-US', {
 timeZone: 'Asia/Manila',
 month: 'short', day: 'numeric', year: 'numeric',
 hour: 'numeric', minute: '2-digit', hour12: true,
});

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
 if (!d) return fallback;
 const parts = Object.fromEntries(manilaDateTime.formatToParts(d).map(({ type, value: part }) => [type, part]));
 return `${parts.month} ${parts.day}, ${parts.year} · ${parts.hour}:${parts.minute} ${parts.dayPeriod}`;
}

export function formatTime(value: string | Date | null | undefined, fallback = '—'): string {
 if (typeof value === 'string' && /^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(value)) {
  const [hour, minute] = value.split(':');
  const hours = Number(hour);
  return `${hours % 12 || 12}:${minute} ${hours < 12 ? 'AM' : 'PM'}`;
 }
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

export function manilaIsoDate(d: Date = new Date()): string {
 return new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
 }).format(d);
}

export function tomorrowIsoDate(): string {
 return manilaIsoDate(new Date(Date.now() + 86_400_000));
}

/**
 * Local date-and-time for `<input type="datetime-local">`,
 * `YYYY-MM-DDTHH:mm`. Same UTC trap as {@link localIsoDate}: building it
 * from `toISOString()` shifts Manila's 00:30 to the previous day's 16:30.
 */
export function localIsoDateTime(d: Date = new Date()): string {
 return format(d, "yyyy-MM-dd'T'HH:mm");
}
