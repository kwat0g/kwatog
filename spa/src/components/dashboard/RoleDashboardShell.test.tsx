import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { dashboardLayoutApi, type DashboardLayoutItem } from '@/api/dashboard-layout';
import { RoleDashboardShell } from './RoleDashboardShell';

vi.mock('@/api/dashboard-layout', () => ({
  dashboardLayoutApi: { layout: vi.fn(), data: vi.fn() },
  WIDGET_STALE_AFTER_MS: 120_000,
  isStaleTimestamp: () => false,
}));
// The strip is asserted by what it is HANDED — this shell's job is to decide
// whether a strip exists at all (four of the six role pages hold no KPI gate),
// not to draw a sparkline.
vi.mock('@/components/dashboard/KpiStrip', () => ({
  KpiStrip: ({ codes }: { codes: string[] }) => (
    <div data-testid="kpi-strip">{codes.join(',')}</div>
  ),
}));
vi.mock('@/components/dashboard/registry', () => ({
  LiveDashboardWidget: ({ widget }: { widget: { key: string } }) => (
    <div data-testid="widget">{widget.key}</div>
  ),
}));

afterEach(() => vi.clearAllMocks());

function block(key: string): DashboardLayoutItem {
  return {
    key,
    name: key,
    description: null,
    module: 'maintenance',
    permission: null,
    permission_any: null,
    render_kind: 'scalar',
    link_path: null,
    data: null,
    x: 0,
    y: 0,
    w: 6,
    h: 4,
    source: 'role',
  };
}

function renderShell(props: Partial<Parameters<typeof RoleDashboardShell>[0]> = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <RoleDashboardShell
          title="Maintenance"
          subtitle="Breakdowns and preventive windows."
          kpiCodes={[]}
          emptyTitle="No maintenance blocks configured"
          emptyDescription="Your work orders appear here."
          {...props}
        />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('RoleDashboardShell', () => {
  it('omits the KPI strip when the page role holds no KPI gate', async () => {
    // Three of the six pages pass [] because their role holds no kpi.* gate —
    // a strip rendered anyway would show one empty message per code, which
    // reads as broken data rather than as "this role reads no KPIs".
    vi.mocked(dashboardLayoutApi.layout).mockResolvedValue({ version: 'v1', items: [block('machine.status')] });
    vi.mocked(dashboardLayoutApi.data).mockResolvedValue({});

    renderShell();

    await screen.findAllByTestId('widget');
    expect(screen.queryByTestId('kpi-strip')).toBeNull();
  });

  it('renders the strip it is handed, in order', async () => {
    vi.mocked(dashboardLayoutApi.layout).mockResolvedValue({ version: 'v1', items: [block('supply.overdue_deliveries')] });
    vi.mocked(dashboardLayoutApi.data).mockResolvedValue({});

    renderShell({ kpiCodes: ['on_time_delivery'] });

    expect(await screen.findByTestId('kpi-strip')).toHaveTextContent('on_time_delivery');
  });

  it('renders the permission-derived blocks the layout service returned', async () => {
    vi.mocked(dashboardLayoutApi.layout).mockResolvedValue({
      version: 'v1',
      items: [block('maintenance.open_wos'), block('machine.status')],
    });
    vi.mocked(dashboardLayoutApi.data).mockResolvedValue({});

    renderShell();

    const widgets = await screen.findAllByTestId('widget');
    expect(widgets.map((node) => node.textContent)).toEqual([
      'maintenance.open_wos',
      'machine.status',
    ]);
  });

  it('uses the page own empty copy rather than a generic message', async () => {
    vi.mocked(dashboardLayoutApi.layout).mockResolvedValue({ version: 'v1', items: [] });
    vi.mocked(dashboardLayoutApi.data).mockResolvedValue({});

    renderShell();

    expect(await screen.findByText('No maintenance blocks configured')).toBeInTheDocument();
    expect(screen.getByText('Your work orders appear here.')).toBeInTheDocument();
  });

  it('keeps blocks visible while warning that their values are stale', async () => {
    vi.mocked(dashboardLayoutApi.layout).mockResolvedValue({ version: 'v1', items: [block('approvals.pending')] });
    vi.mocked(dashboardLayoutApi.data).mockRejectedValue(new Error('Unavailable'));

    renderShell();

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Dashboard values could not be refreshed',
    );
    expect(screen.getAllByTestId('widget')).toHaveLength(1);
  });
});
