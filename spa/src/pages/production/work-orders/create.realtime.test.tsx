import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { workOrdersApi } from '@/api/production/workOrders';
import type { WorkOrder } from '@/types/production';
import CreateWorkOrderPage from './create';

vi.mock('@/api/production/workOrders', () => ({
  workOrdersApi: { create: vi.fn(), formOptions: vi.fn() },
}));
vi.mock('@/hooks/useUnsavedChangesGuard', () => ({ useUnsavedChangesGuard: vi.fn() }));

const createdWorkOrder = {
  id: 'wo-new',
  wo_number: 'WO-202611-0001',
  status: 'planned',
  version: 1,
  quantity_target: 1,
} as unknown as WorkOrder;

describe('Work Order create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(workOrdersApi.formOptions).mockResolvedValue({
      products: [{
        id: 'product-1',
        part_number: 'P-001',
        name: 'Test product',
        stock_production: { eligible: true, blockers: [] },
      }],
      machines: [],
      molds: [],
    } as never);
    vi.mocked(workOrdersApi.create).mockResolvedValue(createdWorkOrder);
  });

  it('seeds the detail cache with the create response before navigating', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/production/work-orders/create']}>
          <Routes>
            <Route path="/production/work-orders/create" element={<CreateWorkOrderPage />} />
            <Route path="/production/work-orders/:id" element={<span>Work Order detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByRole('option', { name: /P-001/ });
    const productSelect = screen.getByRole('combobox', { name: 'Product' });
    fireEvent.change(productSelect, {
      target: { value: 'product-1' },
    });
    const quantityInput = screen.getByRole('spinbutton', { name: 'Quantity' });
    fireEvent.change(quantityInput, {
      target: { value: '1' },
    });
    const planButton = screen.getByRole('button', { name: 'Plan production' });
    expect(productSelect).toHaveValue('product-1');
    expect(quantityInput).toHaveValue(1);
    expect(planButton).toBeEnabled();
    fireEvent.click(planButton);

    await waitFor(() => expect(workOrdersApi.create).toHaveBeenCalledOnce());
    expect(await screen.findByText('Work Order detail route')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData(['production', 'work-orders', 'detail', 'wo-new']))
      .toEqual(createdWorkOrder));
    queryClient.clear();
  });
});
