import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  summary: vi.fn(),
  useInventoryStockRealtime: vi.fn(),
}));

vi.mock('@/api/inventory/dashboard', () => ({
  inventoryDashboardApi: { summary: mocks.summary },
}));
vi.mock('@/hooks/useInventoryStockRealtime', () => ({
  useInventoryStockRealtime: mocks.useInventoryStockRealtime,
}));

import InventoryDashboardPage from './dashboard';

describe('InventoryDashboardPage freshness', () => {
  beforeEach(() => {
    mocks.summary.mockResolvedValue({
      total_stock_value: 0,
      items_below_reorder: 0,
      items_critical: 0,
      pending_grns: 0,
      low_stock_alerts: [],
      recent_movements: [],
      consumption_history_days: 30,
      top_consumed_materials: [],
    });
    mocks.useInventoryStockRealtime.mockClear();
  });

  it('relies on the realtime hook and provider fallback instead of a page polling interval', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    const { unmount } = render(
      <MemoryRouter>
        <QueryClientProvider client={client}>
          <InventoryDashboardPage />
        </QueryClientProvider>
      </MemoryRouter>,
    );

    await waitFor(() => expect(mocks.summary).toHaveBeenCalled());
    expect(mocks.useInventoryStockRealtime).toHaveBeenCalledTimes(1);
    const query = client.getQueryCache().find({ queryKey: ['inventory', 'dashboard'] });
    const observerOptions = query?.options as { refetchInterval?: number | false } | undefined;
    expect(observerOptions?.refetchInterval).toBeUndefined();

    unmount();
    client.clear();
  });
});
