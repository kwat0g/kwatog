/**
 * CSV writing for client-side exports.
 *
 * One implementation for every "download what is on screen" affordance. The
 * repo had three hand-rolled copies (role comparison, import previews, template
 * downloads) and each escaped differently — two of them quoted every cell, one
 * quoted none — so the same number exported twice could land in Excel as text
 * one time and a figure the next.
 *
 * Two rules are non-negotiable here:
 *
 * 1. **Numbers stay numbers.** Quoting `1234.50` turns it into text in Excel
 *    and every downstream SUM silently ignores it. Raw values, not formatted
 *    strings: `₱1,234.50` is for the screen, not the file.
 * 2. **A cell is data, never a formula.** Excel/Sheets execute a cell that
 *    starts with `=`, `+`, `-` or `@`, so a customer name like `=cmd|...` can
 *    become a command. Prefixing a leading apostrophe forces text. This is the
 *    CSV-injection guard; it is applied to strings only, because `-5` and
 *    `+3.5` are legitimate figures.
 */

export type CsvCell = string | number | null | undefined;

/** Characters that make quoting mandatory (RFC 4180 plus CR). */
const NEEDS_QUOTING = /[",\r\n]/;

/** Leading characters a spreadsheet treats as the start of a formula. */
const FORMULA_START = /^[=+\-@\t\r]/;

function escapeCell(value: CsvCell): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'number') return Number.isFinite(value) ? String(value) : '';

  // Neutralise formula injection BEFORE quoting, so the apostrophe lands inside
  // the quoted field and Excel still reads the whole cell as text.
  const text = FORMULA_START.test(value) ? `'${value}` : value;

  return NEEDS_QUOTING.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/** Renders a header row plus data rows. Lines are CRLF per RFC 4180. */
export function toCsv(header: readonly string[], rows: ReadonlyArray<readonly CsvCell[]>): string {
  return [header as readonly CsvCell[], ...rows]
    .map((row) => row.map(escapeCell).join(','))
    .join('\r\n');
}

/**
 * Slugifies a display name for use as a filename stem.
 *
 * Windows rejects `\ / : * ? " < > |`, and a `/` in a download attribute can be
 * read as a path. Anything not obviously safe is dropped rather than replaced
 * with a separator soup.
 */
export function slugifyFilename(name: string): string {
  const slug = name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');

  return slug || 'export';
}

/**
 * Triggers a CSV download.
 *
 * The anchor is appended to the document before `click()`: Firefox ignores a
 * click on a detached anchor, which is precisely the browser this project's
 * e2e gate runs.
 */
export function downloadCsv(
  filename: string,
  header: readonly string[],
  rows: ReadonlyArray<readonly CsvCell[]>,
): void {
  const blob = new Blob([toCsv(header, rows)], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');

  link.href = url;
  link.download = filename;
  link.style.visibility = 'hidden';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  // Deferred: some engines abort a download whose object URL is revoked in the
  // same tick as the click. The blob is garbage either way.
  setTimeout(() => URL.revokeObjectURL(url), 0);
}
