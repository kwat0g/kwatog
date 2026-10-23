import { describe, it, expect } from 'vitest';
import {
  breakdownTable,
  gaugeTable,
  metricExportTable,
  scalarTable,
  tableTable,
  trendTable,
  widgetExportFilename,
  widgetExportTable,
} from '../widgetExport';
import type { DashboardWidgetSummary } from '@/api/dashboard-layout';

const summary = (overrides: Partial<DashboardWidgetSummary> = {}): DashboardWidgetSummary => ({
  key: 'approvals.pending',
  value: '7.00',
  kind: 'count',
  helper: 'Awaiting your decision',
  available: true,
  updated_at: '2026-10-17T08:30:00Z',
  ...overrides,
});

describe('breakdownTable', () => {
  it('writes one row per segment plus the total', () => {
    const table = breakdownTable({
      total: 8,
      segments: [
        { label: 'Incoming', value: 4, tone: 'neutral' },
        { label: 'In process', value: 3, tone: 'warning' },
      ],
    });

    expect(table.header).toEqual(['Segment', 'Value', 'Share of total (%)']);
    expect(table.rows).toEqual([
      ['Incoming', 4, 50],
      ['In process', 3, 37.5],
      ['Total', 8, ''],
    ]);
  });

  it('leaves share blank when there is no total to divide by', () => {
    const table = breakdownTable({
      total: 0,
      segments: [{ label: 'Incoming', value: 0, tone: 'neutral' }],
    });

    expect(table.rows[0]).toEqual(['Incoming', 0, '']);
  });
});

describe('trendTable', () => {
  it('omits the target column when the series has no target', () => {
    const table = trendTable({ points: [{ label: '2026-08', value: 12 }], delta: null, kind: 'count' });

    expect(table.header).toEqual(['Period', 'Value']);
    expect(table.rows).toEqual([['2026-08', 12]]);
  });

  it('adds the target column for scorecard KPIs', () => {
    const table = trendTable({
      points: [{ label: '2026-08', value: 12 }],
      delta: 1,
      kind: 'count',
      target: 15,
    });

    expect(table.header).toEqual(['Period', 'Value', 'Target']);
    expect(table.rows).toEqual([['2026-08', 12, 15]]);
  });
});

describe('tableTable', () => {
  const columns = [
    { key: 'number', label: 'Number' },
    { key: 'amount', label: 'Amount', numeric: true },
  ];

  it('maps every column, blanks a missing cell rather than dropping the row', () => {
    const table = tableTable({
      columns,
      rows: [{ number: 'PR-1', amount: 100 }, { number: 'PR-2', amount: null }],
      total_count: 2,
    });

    expect(table.header).toEqual(['Number', 'Amount']);
    expect(table.rows).toEqual([
      ['PR-1', 100],
      ['PR-2', ''],
    ]);
  });

  it('states that the list is truncated when the tile showed fewer rows', () => {
    const table = tableTable({
      columns,
      rows: [{ number: 'PR-1', amount: 100 }],
      total_count: 300,
    });

    expect(table.rows[1]).toEqual(['Showing 1 of 300', '']);
  });

  it('does not add the note row when everything is present', () => {
    const table = tableTable({ columns, rows: [{ number: 'PR-1', amount: 1 }], total_count: 1 });

    expect(table.rows).toHaveLength(1);
  });
});

describe('gaugeTable / scalarTable', () => {
  it('names the gauge metric and carries the bounds', () => {
    expect(gaugeTable('OEE', { value: 72, target: 80, min: 0, max: 100, kind: 'percent' }).rows).toEqual([
      ['OEE', 72, 80, 0, 100],
    ]);
  });

  it('exports the scalar with its unit, freshness and helper', () => {
    const table = scalarTable('Pending approvals', summary());

    expect(table.header).toEqual(['Metric', 'Value', 'Unit', 'Updated', 'Note']);
    expect(table.rows[0]).toEqual([
      'Pending approvals',
      '7.00',
      'count',
      '2026-10-17T08:30:00Z',
      'Awaiting your decision',
    ]);
  });

  it('renders a null scalar as blank, never as the word "null"', () => {
    const table = scalarTable('Pending approvals', summary({ value: null }));

    expect(table.rows[0][1]).toBe('');
  });
});

describe('widgetExportTable', () => {
  it('prefers the rich payload the tile drew over the scalar summary', () => {
    const table = widgetExportTable({
      name: 'Inspection queue',
      renderKind: 'breakdown',
      data: { total: 2, segments: [{ label: 'Incoming', value: 2, tone: 'neutral' }] },
      summary: summary(),
    });

    expect(table?.header).toEqual(['Segment', 'Value', 'Share of total (%)']);
  });

  it('falls back to the summary when the rich payload is absent', () => {
    const table = widgetExportTable({
      name: 'Pending approvals',
      renderKind: 'scalar',
      data: null,
      summary: summary(),
    });

    expect(table?.rows[0][0]).toBe('Pending approvals');
  });

  it('returns null when the widget has nothing available', () => {
    expect(
      widgetExportTable({
        name: 'Pending approvals',
        renderKind: 'scalar',
        data: null,
        summary: summary({ available: false }),
      }),
    ).toBeNull();

    expect(widgetExportTable({ name: 'Empty', renderKind: 'scalar' })).toBeNull();
  });
});

describe('widgetExportFilename', () => {
  it('stamps the data date in Manila, not the UTC date', () => {
    // 23:00Z on the 1st is 07:00 on the 2nd in Cavite. A filename is a calendar
    // date; slicing the UTC string would label this export a day early.
    expect(widgetExportFilename('Open NCRs', '2026-10-01T23:00:00Z', new Date('2026-10-17'))).toBe(
      'open-ncrs-2026-10-02.csv',
    );
  });

  it('falls back to today when there is no freshness stamp', () => {
    expect(widgetExportFilename('Open NCRs', null, new Date('2026-10-17T12:00:00Z'))).toBe(
      'open-ncrs-2026-10-17.csv',
    );
  });

  it('ignores an unparseable stamp', () => {
    expect(widgetExportFilename('Open NCRs', 'not-a-date', new Date('2026-10-17T12:00:00Z'))).toBe(
      'open-ncrs-2026-10-17.csv',
    );
  });
});

describe('metricExportTable', () => {
  it('exports trend points as a series', () => {
    const table = metricExportTable({
      name: 'WO lead time',
      renderKind: 'trend',
      unit: 'days',
      value: { points: [{ label: '2026-09', value: 4.2 }] },
    });

    expect(table).toEqual({ header: ['Period', 'Value'], rows: [['2026-09', 4.2]] });
  });

  it('exports a breakdown with its total', () => {
    const table = metricExportTable({
      name: 'Returns pipeline',
      renderKind: 'breakdown',
      unit: 'count',
      value: { total: 4, segments: [{ label: 'Open', value: 4, tone: 'info' }] },
    });

    expect(table?.rows).toEqual([
      ['Open', 4, 100],
      ['Total', 4, ''],
    ]);
  });

  it('exports a scalar with its unit, and null when there is no figure', () => {
    expect(
      metricExportTable({ name: 'Lead time', renderKind: 'scalar', unit: 'days', value: { value: 3 } })?.rows,
    ).toEqual([['Lead time', 3, 'days']]);

    expect(
      metricExportTable({ name: 'Lead time', renderKind: 'scalar', unit: 'days', value: { other: 3 } }),
    ).toBeNull();
  });
});
