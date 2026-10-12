import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { chainApi } from '@/api/chain';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { useAuthStore } from '@/stores/authStore';
import ChainTrackerPage from './index';

function renderPage(permissions: string[], path = '/chains') {
  useAuthStore.setState({ permissions: new Set(permissions), user: null });
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}>
        <ChainTrackerPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  useAuthStore.setState({ permissions: new Set(), user: null });
});

describe('ChainTrackerPage permissions', () => {
  it('keeps sales lookup available without requesting bottleneck data', async () => {
    const salesList = vi.spyOn(salesOrdersApi, 'list').mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 8, total: 0, from: null, to: null },
      links: { first: '', last: '', prev: null, next: null },
    });
    const bottlenecks = vi.spyOn(chainApi, 'bottlenecks').mockResolvedValue({ total: 0, groups: [] });

    renderPage(['crm.sales_orders.view']);

    expect(await screen.findByText('Start typing to find an order')).toBeInTheDocument();
    await waitFor(() => expect(salesList).toHaveBeenCalled());
    expect(screen.queryByText('Stuck across the plant')).not.toBeInTheDocument();
    expect(bottlenecks).not.toHaveBeenCalled();
  });

  it('shows bottlenecks without requesting sales-order data or honoring a deep link', async () => {
    const salesList = vi.spyOn(salesOrdersApi, 'list');
    const salesShow = vi.spyOn(salesOrdersApi, 'show');
    const salesChain = vi.spyOn(salesOrdersApi, 'chain');
    const bottlenecks = vi.spyOn(chainApi, 'bottlenecks').mockResolvedValue({ total: 0, groups: [] });

    renderPage(['dashboard.view_bottlenecks'], '/chains?id=private-order');

    expect(await screen.findByText('Nothing stuck')).toBeInTheDocument();
    expect(screen.queryByText('Track a sales order')).not.toBeInTheDocument();
    expect(bottlenecks).toHaveBeenCalled();
    expect(salesList).not.toHaveBeenCalled();
    expect(salesShow).not.toHaveBeenCalled();
    expect(salesChain).not.toHaveBeenCalled();
  });
});
