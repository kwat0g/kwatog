import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { dashboardsApi } from '@/api/dashboards';
import { forecastingApi } from '@/api/forecasting';
import WarehouseDashboard from './warehouse';

vi.mock('@/api/dashboards', () => ({ dashboardsApi: { warehouse: vi.fn() }, DASHBOARD_REFRESH_MS: 60_000 }));
vi.mock('@/api/forecasting', () => ({
  forecastingApi: { list: vi.fn(), stockOut: vi.fn(), accuracySummary: vi.fn(), options: vi.fn() },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/components/dashboard/KpiStrip', () => ({ KpiStrip: () => null }));

const zones = [
  { warehouse_code: 'WH-A', warehouse_name: 'Main', zone: 'A', name: 'Raw Materials', percent: 50 },
  { warehouse_code: 'WH-B', warehouse_name: 'Overflow', zone: 'A', name: 'Raw Materials', percent: 50 },
];

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(dashboardsApi.warehouse).mockResolvedValue({
    kpis: [],
    panels: { incoming_queue: [], outgoing_queue: [], low_stock_alerts: [], zone_utilization: zones },
  });
  vi.mocked(forecastingApi.list).mockResolvedValue([]);
  vi.mocked(forecastingApi.stockOut).mockResolvedValue({
    data: [],
    meta: {
      horizon_days: 60,
      default_horizon_days: 60,
      minimum_horizon_days: 7,
      maximum_horizon_days: 180,
      demand_history_days: 30,
      generated_at: '2026-10-11T00:00:00Z',
      risk_options: [],
    },
  });
  vi.mocked(forecastingApi.accuracySummary).mockResolvedValue({
    mape: null,
    bias: null,
    periods_evaluated: 0,
    monthly: [],
  });
  vi.mocked(forecastingApi.options).mockResolvedValue({
    methods: [],
    demand_sources: [],
    accuracy_policy: { excellent_mape: 5, acceptable_mape: 15 },
  });

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <WarehouseDashboard />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('WarehouseDashboard', () => {
  afterEach(() => vi.restoreAllMocks());

  it('keeps same-code zones from different warehouses distinct in the rendered page', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});

    renderPage();

    expect(await screen.findByRole('progressbar', { name: 'Main (WH-A), Raw Materials (A): 50% occupied' })).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: 'Overflow (WH-B), Raw Materials (A): 50% occupied' })).toBeInTheDocument();
    expect(screen.getAllByText('WH-A/A — Raw Materials')).toHaveLength(2);
    expect(screen.getAllByText('WH-B/A — Raw Materials')).toHaveLength(2);
    expect(error.mock.calls.flat().join(' ')).not.toContain('Encountered two children with the same key');
  });

  it('mounts the demand and accuracy forecast panels alongside stock-out risk', async () => {
    renderPage();

    // Demand panel empty state keeps forecasting discoverable; accuracy panel
    // shows its pre-reconciliation empty; the stock panel hides when empty
    // (the Low Stock Alerts panel already covers the all-clear case).
    expect(await screen.findByText('No forecasts yet')).toBeInTheDocument();
    expect(screen.getByText('No reconciled periods')).toBeInTheDocument();
    expect(screen.queryByText('Stock-out Risk')).not.toBeInTheDocument();
  });
});
