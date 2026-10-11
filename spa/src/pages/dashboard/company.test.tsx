import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { dashboardLayoutApi, type DashboardLayoutItem } from '@/api/dashboard-layout';
import { COMPANY_KPIS } from '@/lib/dashboardKpis';
import CompanyDashboardPage from './company';

vi.mock('@/api/dashboard-layout', () => ({
  dashboardLayoutApi: { layout: vi.fn(), data: vi.fn() },
  WIDGET_STALE_AFTER_MS: 120_000,
  isStaleTimestamp: () => false,
}));
// The strip is asserted by what it is HANDED, so stub it: this page's job is to
// pass the company reading list (four of whose modules the viewer holds no
// dashboard for — the point of the set-based gate), not to render a sparkline.
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
    module: 'accounting',
    permission: null,
    permission_any: null,
    render_kind: 'breakdown',
    link_path: null,
    data: null,
    x: 0,
    y: 0,
    w: 6,
    h: 4,
    source: 'role',
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <CompanyDashboardPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

it('mounts the company health strip and the permission-derived blocks', async () => {
  vi.mocked(dashboardLayoutApi.layout).mockResolvedValue({
    version: 'v1',
    items: [block('finance.ar_aging'), block('chain.stage_breakdown')],
  });
  vi.mocked(dashboardLayoutApi.data).mockResolvedValue({});

  renderPage();

  // The six company readings, including the four modules the viewer holds no
  // dashboard permission for — readable only because the KPI gate is a set.
  expect(await screen.findByTestId('kpi-strip')).toHaveTextContent(COMPANY_KPIS.join(','));

  const widgets = await screen.findAllByTestId('widget');
  expect(widgets.map((node) => node.textContent)).toEqual([
    'finance.ar_aging',
    'chain.stage_breakdown',
  ]);
});

it('says which blocks are missing instead of drawing an empty grid', async () => {
  // A viewer with no default blocks — the state the vice president was in
  // before this page existed.
  vi.mocked(dashboardLayoutApi.layout).mockResolvedValue({ version: 'v1', items: [] });
  vi.mocked(dashboardLayoutApi.data).mockResolvedValue({});

  renderPage();

  expect(await screen.findByText('Nothing on this overview yet')).toBeInTheDocument();
  expect(screen.queryAllByTestId('widget')).toHaveLength(0);
});

it('keeps the blocks visible while warning that their values are stale', async () => {
  vi.mocked(dashboardLayoutApi.layout).mockResolvedValue({
    version: 'v1',
    items: [block('alerts')],
  });
  vi.mocked(dashboardLayoutApi.data).mockRejectedValue(new Error('Unavailable'));

  renderPage();

  expect(await screen.findByRole('alert')).toHaveTextContent(
    'Dashboard values could not be refreshed',
  );
  expect(screen.getAllByTestId('widget')).toHaveLength(1);
});
