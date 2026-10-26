import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { productsApi } from '@/api/crm/products';
import { useAuthStore } from '@/stores/authStore';
import type { Product } from '@/types/crm';
import ProductDetailPage from './detail';

vi.mock('react-router-dom', async (original) => ({
  ...(await original<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'product-1' }),
}));
vi.mock('@/api/crm/products', () => ({
  productsApi: { show: vi.fn(), proposeFinancial: vi.fn(), decideFinancial: vi.fn(), updateSellingPrices: vi.fn() },
}));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ permissions: new Set(), user: null });
});
const product: Product = {
  id: 'product-1',
  part_number: 'WB-001',
  name: 'Wiper Bushing',
  description: 'Automotive component',
  unit_of_measure: 'PCS',
  standard_cost: '18.50',
  base_selling_price: '25.00',
  minimum_selling_price: '22.00',
  portal_visibility: 'restricted',
  portal_customers: [{ id: 'customer-1', name: 'Toyota' }],
  revenue_account_id: null,
  is_active: true,
  include_forecast_in_mrp: true,
  has_bom: true,
  active_bom: { id: 'bom-1', version: 2 },
  inspection_spec: { id: 'spec-1', version: 1, updated_at: null },
  finished_good_readiness: { ready: true, reason: null, item_id: 'item-1', item_code: 'WB-001' },
  created_at: '2026-10-01',
  updated_at: '2026-10-04',
};
function show(data = product, permissions = ['crm.products.manage', 'crm.products.view', 'inventory.costs.review']) {
  useAuthStore.setState({ permissions: new Set(permissions) });
  vi.mocked(productsApi.show).mockResolvedValue(data);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <ProductDetailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
it('keeps pricing, customer access and production setup visible without unauthorized links', async () => {
  show(product, ['crm.products.view']);
  await screen.findByRole('heading', { name: 'Pricing' });
  expect(screen.getByRole('heading', { name: 'Production setup' })).toBeVisible();
  expect(screen.getByText('Toyota')).toBeVisible();
  expect(screen.getByText('Prices exclude VAT.')).toBeVisible();
  expect(screen.queryByRole('link', { name: /View BOM/ })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Edit product' })).not.toBeInTheDocument();
});
it('submits a standard cost proposal and retains entries after failure', async () => {
  show();
  vi.mocked(productsApi.proposeFinancial).mockRejectedValue(new Error('Offline'));
  fireEvent.click(await screen.findByRole('button', { name: 'Propose standard cost change' }));
  fireEvent.change(screen.getByLabelText(/Proposed standard cost/), { target: { value: '20.00' } });
  fireEvent.change(screen.getByLabelText(/Standard cost change reason/), {
    target: { value: 'Material cost review' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Submit standard cost for Finance review' }));
  await waitFor(() =>
    expect(productsApi.proposeFinancial).toHaveBeenCalledWith('product-1', {
      standard_cost: '20.00',
      notes: 'Material cost review',
    }),
  );
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not record financial review.');
  expect(screen.getByLabelText(/Proposed standard cost/)).toHaveValue(20);
});
