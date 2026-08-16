import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import type { ReactNode } from 'react';
import { LiveDashboardWidget } from './registry';
import type { DashboardLayoutItem } from '@/api/dashboard-layout';

function item(overrides: Partial<DashboardLayoutItem>): DashboardLayoutItem {
  return {
    key: 'test.widget',
    name: 'Test Widget',
    description: null,
    module: 'platform',
    permission: null,
    render_kind: 'scalar',
    link_path: '/self-service',
    data: null,
    x: 0,
    y: 0,
    w: 12,
    h: 4,
    source: 'role',
    ...overrides,
  };
}

const wrap = (ui: ReactNode) => render(<MemoryRouter>{ui}</MemoryRouter>);

describe('LiveDashboardWidget', () => {
  it('renders a scalar summary as a single figure', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({})}
        summary={{
          key: 'test.widget',
          value: '42',
          kind: 'number',
          helper: 'things counted',
          available: true,
          updated_at: new Date().toISOString(),
        }}
        loading={false}
      />,
    );

    expect(screen.getByText('42')).toBeInTheDocument();
    expect(screen.getByText('things counted')).toBeInTheDocument();
  });

  it('renders breakdown segments with their labels and values', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({
          render_kind: 'breakdown',
          data: {
            total: 9,
            segments: [
              { label: 'in_progress', value: 6, tone: 'success' },
              { label: 'paused', value: 3, tone: 'warning' },
            ],
          },
        })}
        loading={false}
      />,
    );

    expect(screen.getByText('in_progress')).toBeInTheDocument();
    expect(screen.getByText('paused')).toBeInTheDocument();
    expect(screen.getByText('6')).toBeInTheDocument();
  });

  it('renders a table widget as rows', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({
          render_kind: 'table',
          data: {
            columns: [
              { key: 'machine', label: 'Machine' },
              { key: 'hours', label: 'Hours', numeric: true },
            ],
            rows: [{ machine: 'IMM-04', hours: '12.50' }],
            total_count: 1,
          },
        })}
        loading={false}
      />,
    );

    expect(screen.getByText('IMM-04')).toBeInTheDocument();
    expect(screen.getByText('Machine')).toBeInTheDocument();
  });

  it('renders a gauge widget against its target', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({
          render_kind: 'gauge',
          data: { value: 72.5, target: 85, min: 0, max: 100, kind: 'percent' },
        })}
        loading={false}
      />,
    );

    expect(screen.getByText('72.5%')).toBeInTheDocument();
    expect(screen.getByText(/85/)).toBeInTheDocument();
  });

  /**
   * The backend sends data => null whenever a rich provider degrades. The tile
   * must fall back to its scalar summary rather than render an empty panel.
   */
  it('falls back to the scalar summary when rich data is absent', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({ render_kind: 'breakdown', data: null })}
        summary={{
          key: 'test.widget',
          value: '9',
          kind: 'number',
          helper: 'open work orders',
          available: true,
          updated_at: new Date().toISOString(),
        }}
        loading={false}
      />,
    );

    expect(screen.getByText('9')).toBeInTheDocument();
    expect(screen.getByText('open work orders')).toBeInTheDocument();
  });

  it('shows the unavailable state when neither rich data nor summary arrives', () => {
    wrap(<LiveDashboardWidget widget={item({ render_kind: 'trend' })} loading={false} />);

    expect(screen.getByText('Live data unavailable')).toBeInTheDocument();
  });

  /**
   * "Open →" comes from the widget row, not from a map in this file. A 51-entry
   * `WIDGET_LINKS` literal used to live here with nothing binding it to the
   * seeder, so a widget added on the backend rendered a tile with no way out.
   */
  it('takes the Open link from the widget row', () => {
    wrap(<LiveDashboardWidget widget={item({ link_path: '/quality/ncrs' })} loading={false} />);

    expect(screen.getByRole('link', { name: /open/i })).toHaveAttribute('href', '/quality/ncrs');
  });

  it('omits the Open link when the widget has no deeper page', () => {
    wrap(<LiveDashboardWidget widget={item({ link_path: null })} loading={false} />);

    expect(screen.queryByRole('link', { name: /open/i })).not.toBeInTheDocument();
  });

  /** A KPI trend reads only against its target, and status is words, not colour. */
  it('renders a KPI trend with its target and status label', () => {
    wrap(
      <LiveDashboardWidget
        widget={item({
          render_kind: 'trend',
          data: {
            points: [
              { label: '2026-07', value: 82.5 },
              { label: '2026-08', value: 88 },
            ],
            delta: 6.7,
            kind: 'percent',
            target: 85,
            status: 'on_target',
          },
        })}
        loading={false}
      />,
    );

    expect(screen.getByText('88.0%')).toBeInTheDocument();
    expect(screen.getByText(/Target 85\.0%/)).toBeInTheDocument();
    expect(screen.getByText(/On target/)).toBeInTheDocument();
  });
});
