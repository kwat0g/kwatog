import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { forecastingApi } from '@/api/forecasting';
import StockOutProjectionPage from './stock-out';

const { grants } = vi.hoisted(() => ({ grants: new Set<string>() }));
vi.mock('@/api/forecasting', () => ({
  forecastingApi: { stockOut: vi.fn(), options: vi.fn() },
}));
vi.mock('@/hooks/useDemandForecastRealtime', () => ({ useDemandForecastRealtime: vi.fn() }));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (permission: string) => grants.has(permission) }),
}));

describe('stock-out actions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    grants.clear();
    grants.add('production.work_orders.view');
    grants.add('purchasing.pr.create');
    vi.mocked(forecastingApi.stockOut).mockResolvedValue({
      data: [
        {
          item_id: 'finished-1',
          item_type: 'finished_good',
          code: 'FG-001',
          name: 'Finished part',
          unit_of_measure: 'PCS',
          available: '0.000',
          safety_stock: '10.000',
          reorder_point: '20.000',
          lead_time_days: 7,
          daily_demand: '1.000',
          demand_source: 'forecast',
          days_until_stockout: 0,
          reorder_date: '2026-11-11',
          suggested_qty: null,
          risk: 'critical',
        },
        {
          item_id: 'material-1',
          item_type: 'raw_material',
          code: 'RM-001',
          name: 'Raw resin',
          unit_of_measure: 'KG',
          available: '0.000',
          safety_stock: '10.000',
          reorder_point: '20.000',
          lead_time_days: 7,
          daily_demand: '1.000',
          demand_source: 'historical',
          days_until_stockout: 0,
          reorder_date: '2026-11-11',
          suggested_qty: '40.000',
          risk: 'critical',
        },
      ],
      meta: {
        horizon_days: 30,
        default_horizon_days: 30,
        minimum_horizon_days: 1,
        maximum_horizon_days: 365,
        demand_history_days: 30,
        generated_at: '2026-11-11T00:00:00Z',
        risk_options: [{ value: 'critical', label: 'Critical' }],
      },
    });
    vi.mocked(forecastingApi.options).mockResolvedValue({
      methods: [],
      demand_sources: [
        { value: 'forecast', label: 'Forecast' },
        { value: 'historical', label: 'Historical' },
        { value: 'none', label: 'No demand' },
      ],
      accuracy_policy: { excellent_mape: 10, acceptable_mape: 20 },
    });
  });

  it('routes finished goods to production review and purchased items to purchase requests', async () => {
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter>
          <StockOutProjectionPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    const productionReview = await screen.findByRole('link', { name: 'Review production' });
    const purchaseRequest = await screen.findByRole('link', { name: 'Create purchase request' });
    expect(productionReview).toHaveAttribute('href', '/production/work-orders');
    expect(purchaseRequest).toHaveAttribute('href', '/purchasing/purchase-requests/create');
    expect(screen.getByRole('columnheader', { name: 'Usable stock' })).toBeVisible();
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('does not offer an action route when the viewer lacks the owning permission', async () => {
    grants.clear();
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter>
          <StockOutProjectionPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByText('FG-001');
    expect(screen.queryByRole('link', { name: 'Review production' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Create purchase request' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Plan production' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Review purchasing' })).not.toBeInTheDocument();
  });
});
