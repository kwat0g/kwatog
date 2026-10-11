import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { dashboardsApi } from '@/api/dashboards';
import { forecastingApi } from '@/api/forecasting';
import PurchasingDashboard from './purchasing';

vi.mock('@/api/dashboards', () => ({ dashboardsApi: { purchasing: vi.fn() }, DASHBOARD_REFRESH_MS: 60_000 }));
vi.mock('@/api/forecasting', () => ({
  forecastingApi: { list: vi.fn(), stockOut: vi.fn(), accuracySummary: vi.fn(), options: vi.fn() },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/components/dashboard/KpiStrip', () => ({ KpiStrip: () => null }));

const supplierRisk = {
  at_risk_count: 2,
  critical_count: 1,
  high_count: 1,
  horizon_days: 60,
  mape: 12.34,
  bias: 3.21,
  periods_evaluated: 18,
  top_items: [
    { item_id: 'h1', code: 'RES-001', name: 'Resin PP', days_until_stockout: 3, risk: 'critical' },
    { item_id: 'h2', code: 'PIG-002', name: 'Pigment Black', days_until_stockout: 12, risk: 'high' },
  ],
};

function renderPage(panels: Record<string, unknown>) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(dashboardsApi.purchasing).mockResolvedValue({
    kpis: [],
    panels: {
      pr_action_queue: [],
      po_pipeline: [],
      supplier_performance: [],
      upcoming_deliveries: [],
      ...panels,
    },
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

  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <PurchasingDashboard />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('PurchasingDashboard supplier risk', () => {
  afterEach(() => vi.restoreAllMocks());

  it('renders the read-only supplier-risk composition with accuracy and link-outs', async () => {
    renderPage({ supplier_risk: supplierRisk });

    expect(await screen.findByText('Supplier Risk')).toBeInTheDocument();
    expect(screen.getByText('RES-001')).toBeInTheDocument();
    expect(screen.getByText(/Demand MAPE 12\.3% · bias \+3\.2% · 18 periods/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /stock-out outlook/i })).toHaveAttribute(
      'href',
      '/forecasting/stock-out',
    );
    // Read-only: no override inputs anywhere in the panel.
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  });

  it('omits the panel entirely when the server strips it', async () => {
    renderPage({});

    await screen.findByText('PR Pipeline by Urgency');
    expect(screen.queryByText('Supplier Risk')).not.toBeInTheDocument();
  });
});
