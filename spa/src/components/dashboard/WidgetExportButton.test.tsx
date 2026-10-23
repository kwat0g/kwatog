import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { WidgetExportButton } from './WidgetExportButton';
import { downloadCsv } from '@/lib/csv';

vi.mock('@/lib/csv', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/csv')>();
  return { ...actual, downloadCsv: vi.fn() };
});

const summary = {
  key: 'approvals.pending',
  value: '7',
  kind: 'count' as const,
  helper: 'Awaiting your decision',
  available: true,
  updated_at: '2026-10-17T08:30:00Z',
};

describe('WidgetExportButton', () => {
  beforeEach(() => {
    vi.mocked(downloadCsv).mockClear();
  });

  it('names the widget in its accessible label', () => {
    render(
      <WidgetExportButton name="Pending approvals" renderKind="scalar" summary={summary} />,
    );

    expect(screen.getByRole('button', { name: 'Export Pending approvals as CSV' })).toBeTruthy();
  });

  it('downloads the same rows the tile draws, stamped with the data date', () => {
    render(
      <WidgetExportButton
        name="Inspection queue"
        renderKind="breakdown"
        data={{ total: 4, segments: [{ label: 'Incoming', value: 4, tone: 'neutral' }] }}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Export Inspection queue as CSV' }));

    expect(downloadCsv).toHaveBeenCalledWith(
      expect.stringMatching(/^inspection-queue-\d{4}-\d{2}-\d{2}\.csv$/),
      ['Segment', 'Value', 'Share of total (%)'],
      [
        ['Incoming', 4, 100],
        ['Total', 4, ''],
      ],
    );
  });

  it('renders nothing when the widget has no data to export', () => {
    render(
      <WidgetExportButton
        name="Pending approvals"
        renderKind="scalar"
        summary={{ ...summary, available: false }}
      />,
    );

    expect(screen.queryByRole('button')).toBeNull();
  });
});
