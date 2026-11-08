import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  payload: vi.fn(),
  useProductionDashboardRealtime: vi.fn(),
}));

vi.mock('@/api/production/dashboard', () => ({
  productionDashboardApi: { payload: mocks.payload },
}));
vi.mock('@/hooks/useProductionDashboardRealtime', () => ({
  useProductionDashboardRealtime: mocks.useProductionDashboardRealtime,
}));
vi.mock('@/components/dashboard/MetricPanel', () => ({
  MetricPanel: () => null,
}));

import ProductionDashboardPage from './dashboard';

describe('ProductionDashboardPage freshness', () => {
  beforeEach(() => {
    mocks.payload.mockResolvedValue({
      generated_at: new Date().toISOString(),
      kpis: {
        today_output_good: 0,
        today_output_total: 0,
        today_output_reject: 0,
        active_work_orders: 0,
        machines_running: 0,
        machines_total: 0,
        machines_idle: 0,
        machines_breakdown: 0,
        avg_oee_today: null,
      },
      chain_stage_breakdown: [],
      machine_utilization: [],
      display_policy: {},
      alerts: [],
      defect_history_days: 7,
      defect_pareto: [],
    });
    mocks.useProductionDashboardRealtime.mockClear();
  });

  it('uses the realtime subscription and shared provider fallback without page polling', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    const { unmount } = render(
      <MemoryRouter>
        <QueryClientProvider client={client}>
          <ProductionDashboardPage />
        </QueryClientProvider>
      </MemoryRouter>,
    );

    await waitFor(() => expect(mocks.payload).toHaveBeenCalled());
    expect(mocks.useProductionDashboardRealtime).toHaveBeenCalledTimes(1);
    const query = client.getQueryCache().find({ queryKey: ['production', 'dashboard'] });
    const observerOptions = query?.options as { refetchInterval?: number | false } | undefined;
    expect(observerOptions?.refetchInterval).toBeUndefined();

    unmount();
    client.clear();
  });
});
