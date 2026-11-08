import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { productsApi } from '@/api/crm/products';
import type { Product } from '@/types/crm';
import ProductDetailPage from './detail';

vi.mock('@/api/crm/products', () => ({ productsApi: {
  show: vi.fn(), customerOptions: vi.fn(), updateCustomerAccess: vi.fn(), updateSellingPrices: vi.fn(),
  proposeFinancial: vi.fn(), decideFinancial: vi.fn(),
} }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn(), useRealtimeProductBom: vi.fn() }));

const product: Product = {
  id: 'product-1', part_number: 'P-001', name: 'Molded Part', description: null, image_url: null,
  unit_of_measure: 'pc', standard_cost: '10.00', base_selling_price: '20.00', minimum_selling_price: '15.00',
  portal_visibility: 'restricted', portal_customers: [], revenue_account_id: null, is_active: true,
  include_forecast_in_mrp: false, has_bom: false, active_bom: null, inspection_spec: null,
  created_at: '2026-11-02T08:00:00Z', updated_at: '2026-11-02T08:00:00Z',
};

function open() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/crm/products/product-1']}>
        <Routes><Route path="/crm/products/:id" element={<ProductDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return queryClient;
}

beforeEach(() => {
  vi.resetAllMocks();
  vi.mocked(productsApi.show).mockResolvedValue(product);
});

it('uses the full selling-price API response while the follow-up Product read is pending', async () => {
  const updated = { ...product, base_selling_price: '125.00', minimum_selling_price: '110.00', updated_at: '2026-11-02T09:00:00Z' };
  vi.mocked(productsApi.show)
    .mockResolvedValueOnce(product)
    .mockImplementation(() => new Promise(() => {}));
  vi.mocked(productsApi.updateSellingPrices).mockResolvedValue(updated);
  const queryClient = open();
  const productKey = ['crm', 'products', 'detail', product.id] as const;

  fireEvent.click(await screen.findByRole('button', { name: 'Edit selling prices' }));
  fireEvent.change(screen.getByLabelText('Base selling price'), { target: { value: '125.00' } });
  fireEvent.change(screen.getByLabelText('Minimum selling price'), { target: { value: '110.00' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save prices' }));

  await waitFor(() => expect(productsApi.updateSellingPrices).toHaveBeenCalledWith('product-1', {
    base_selling_price: '125.00', minimum_selling_price: '110.00',
  }));
  await waitFor(() => expect(queryClient.getQueryData<Product>(productKey)?.base_selling_price).toBe('125.00'));
});
