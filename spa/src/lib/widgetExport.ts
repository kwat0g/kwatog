/**
 * Dashboard widget → CSV table.
 *
 * Finding 15 of the UI/UX discovery: executives screenshot dashboards because
 * there is no way to take the numbers with them. Server-side CSV/XLSX exists
 * for list pages, but a widget is a *view*, not a resource — the data is
 * already on screen, so the export is built from the payload the tile drew.
 * That also means the file cannot disagree with the picture: both read the same
 * object.
 *
 * Rendering rules, all deliberate:
 *
 * - **Raw values, not formatted ones.** `1,234.50` is what the tile shows;
 *   `1234.5` is what a spreadsheet can add up.
 * - **The file names its own truncation.** A table widget shows six of three
 *   hundred rows, and a note row says so rather than letting a partial list
 *   pass for the whole set.
 * - **Nothing is invented.** Absent fields become blank cells; a breakdown
 *   share is only emitted when a total exists to divide by.
 */
import type {
  DashboardWidgetSummary,
  WidgetBreakdownData,
  WidgetData,
  WidgetGaugeData,
  WidgetRenderKind,
  WidgetTableData,
  WidgetTrendData,
  WidgetValueKind,
} from '@/api/dashboard-layout';
import { type CsvCell, slugifyFilename } from './csv';
import { manilaIsoDate } from './formatDate';

export interface CsvTable {
  header: string[];
  rows: CsvCell[][];
}

export interface WidgetExportSource {
  /** Display name — used for the filename and the accessible label. */
  name: string;
  renderKind: WidgetRenderKind;
  /** Rich payload, when the layout was fetched with `rich: true`. */
  data?: WidgetData | null;
  /** Scalar summary, for tiles that draw from `widget-data` instead. */
  summary?: DashboardWidgetSummary | null;
  /** Freshness stamp; the filename's date prefers it over "today". */
  updatedAt?: string | null;
}

function share(value: number, total: number): number | '' {
  return total > 0 ? Number(((value / total) * 100).toFixed(1)) : '';
}

export function breakdownTable(data: WidgetBreakdownData): CsvTable {
  const total = data.total;

  return {
    header: ['Segment', 'Value', 'Share of total (%)'],
    rows: [
      ...data.segments.map((segment) => [segment.label, segment.value, share(segment.value, total)]),
      // The total is a row, not a second header: readers sort and filter this in
      // a spreadsheet, and an out-of-band total disappears when they do.
      ['Total', total, ''],
    ],
  };
}

export function trendTable(data: WidgetTrendData): CsvTable {
  const hasTarget = data.target !== null && data.target !== undefined;

  return {
    header: hasTarget ? ['Period', 'Value', 'Target'] : ['Period', 'Value'],
    rows: data.points.map((point) => (hasTarget ? [point.label, point.value, data.target] : [point.label, point.value])),
  };
}

export function tableTable(data: WidgetTableData): CsvTable {
  const header = data.columns.map((column) => column.label);
  const rows: CsvCell[][] = data.rows.map((row) =>
    data.columns.map((column) => row[column.key] ?? ''),
  );

  if (data.total_count > data.rows.length) {
    rows.push([`Showing ${data.rows.length} of ${data.total_count}`, ...data.columns.slice(1).map(() => '')]);
  }

  return { header, rows };
}

export function gaugeTable(name: string, data: WidgetGaugeData): CsvTable {
  return {
    header: ['Metric', 'Value', 'Target', 'Min', 'Max'],
    rows: [[name, data.value, data.target ?? '', data.min, data.max]],
  };
}

export function scalarTable(name: string, summary: DashboardWidgetSummary): CsvTable {
  return {
    header: ['Metric', 'Value', 'Unit', 'Updated', 'Note'],
    rows: [
      [
        name,
        summary.value === null ? '' : summary.value,
        summary.kind,
        summary.updated_at,
        summary.helper ?? '',
      ],
    ],
  };
}

/**
 * The one entry point a tile uses. Returns null when there is nothing worth
 * exporting, so the affordance can hide rather than download an empty file.
 */
export function widgetExportTable(source: WidgetExportSource): CsvTable | null {
  const { renderKind, data, summary } = source;

  // Rich payload first — it is what the tile actually drew.
  if (data) {
    if (renderKind === 'breakdown' && 'segments' in data) return breakdownTable(data);
    if (renderKind === 'trend' && 'points' in data) return trendTable(data);
    if (renderKind === 'table' && 'columns' in data) return tableTable(data);
    if (renderKind === 'gauge' && 'value' in data) return gaugeTable(source.name, data);
  }

  // Scalar tiles (and any rich widget whose payload was unavailable).
  if (summary?.available) return scalarTable(source.name, summary);

  return null;
}

/**
 * `<slug>-<YYYY-MM-DD>.csv`, dated by the data rather than by the machine
 * clock when the payload carries a timestamp — two exports of the same window
 * then overwrite each other instead of piling up, and the date in the name
 * describes the numbers.
 */
export function widgetExportFilename(
  name: string,
  updatedAt?: string | null,
  now: Date = new Date(),
): string {
  const parsed = updatedAt ? new Date(updatedAt) : null;
  // Manila, not UTC: the timestamp is an instant, but a filename date is a
  // calendar date, and 23:00Z is already tomorrow in Cavite (F-014's lesson).
  const stamp = parsed && Number.isFinite(parsed.getTime())
    ? manilaIsoDate(parsed)
    : manilaIsoDate(now);

  return `${slugifyFilename(name)}-${stamp}.csv`;
}

/** Export of a metric panel that is not a widget row (production/returns pages). */
export function metricExportTable({
  name,
  renderKind,
  unit,
  value,
}: {
  name: string;
  renderKind: WidgetRenderKind;
  /**
   * Metric units (`days`, `ratio`) are wider than the widget vocabulary, so the
   * parameter accepts any unit and only the two builders that carry a `kind`
   * field fall back to the widget spelling.
   */
  unit: string;
  value: Record<string, unknown>;
}): CsvTable | null {
  const points = Array.isArray(value.points) ? value.points : null;
  const segments = Array.isArray(value.segments) ? value.segments : null;

  if (renderKind === 'trend' && points) {
    return trendTable({
      points: points as WidgetTrendData['points'],
      delta: null,
      kind: unit as WidgetValueKind,
    });
  }

  if (renderKind === 'breakdown' && segments) {
    return breakdownTable({
      total: typeof value.total === 'number' ? value.total : 0,
      segments: segments as WidgetBreakdownData['segments'],
      kind: unit as WidgetValueKind,
    });
  }

  const scalar = typeof value.value === 'number' ? value.value : value.total;

  if (typeof scalar !== 'number') return null;

  return {
    header: ['Metric', 'Value', 'Unit'],
    rows: [[name, scalar, unit]],
  };
}

/** Re-exported so callers do not import two CSV modules. */
export type { CsvCell };
