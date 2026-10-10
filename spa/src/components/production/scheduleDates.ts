/** Production calendars use the server's plant timezone, independent of the browser. */
export function scheduleDateKey(iso: string, timezone: string): string {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date(iso));
  const value = (type: string) => parts.find((part) => part.type === type)!.value;
  return `${value('year')}-${value('month')}-${value('day')}`;
}

export function scheduleDate(iso: string, timezone: string): string {
  return new Intl.DateTimeFormat('en-PH', {
    timeZone: timezone,
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(iso));
}

export function scheduleTime(iso: string, timezone: string): string {
  return new Intl.DateTimeFormat('en-PH', {
    timeZone: timezone,
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  }).format(new Date(iso));
}

export function productionDateTime(iso: string): string {
  return `${scheduleDate(iso, 'Asia/Manila')} · ${scheduleTime(iso, 'Asia/Manila')}`;
}

/** Philippine plant time has a fixed UTC+08:00 offset. */
export function productionInputIso(value: string): string {
  return new Date(`${value}+08:00`).toISOString();
}

/** Native datetime inputs require 24-hour values even when displayed labels use AM/PM. */
export function productionInputDateTime(iso: string): string {
  const time = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Manila',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(new Date(iso));
  return `${scheduleDateKey(iso, 'Asia/Manila')}T${time}`;
}

export function offsetScheduleDate(date: string, days: number): string {
  const parsed = new Date(`${date}T00:00:00Z`);
  parsed.setUTCDate(parsed.getUTCDate() + days);
  return `${parsed.getUTCFullYear()}-${String(parsed.getUTCMonth() + 1).padStart(2, '0')}-${String(parsed.getUTCDate()).padStart(2, '0')}`;
}
