import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { purchaseOrderQueryKeys } from '@/lib/queryKeys/purchasing';
import type { PurchaseOrder } from '@/types/purchasing';
import CreatePurchaseOrderPage from './create';

vi.mock('@/api/purchasing/purchase-orders', () => ({
  purchaseOrdersApi: { create: vi.fn() },
}));
vi.mock('@/api/purchasing/purchase-requests', () => ({ purchaseRequestsApi: { show: vi.fn() } }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdOrder = {
  id: 'po-new',
  po_number: 'PO-202611-0001',
  status: 'draft',
  version: 1,
  actions: { can_submit: true, can_approve: false },
} as unknown as PurchaseOrder;

describe('Purchase Order create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(purchaseOrdersApi.create).mockResolvedValue(createdOrder);
  });

  it('seeds the authorized detail cache with the create response before navigating', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/purchasing/purchase-orders/create']}>
          <Routes>
            <Route path="/purchasing/purchase-orders/create" element={<CreatePurchaseOrderPage />} />
            <Route path="/purchasing/purchase-orders/:id" element={<span>PO detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(screen.getAllByRole('textbox')[0], { target: { value: 'vendor-1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create PO' }));

    await waitFor(() => expect(purchaseOrdersApi.create).toHaveBeenCalledOnce());
    expect(await screen.findByText('PO detail route')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData(purchaseOrderQueryKeys.detail('po-new')))
      .toEqual(createdOrder));
    queryClient.clear();
  });
});
