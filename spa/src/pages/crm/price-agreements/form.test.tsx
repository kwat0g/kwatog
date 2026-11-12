import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { crmCustomersApi } from '@/api/crm/customers';
import { productsApi } from '@/api/crm/products';
import type { PriceAgreement } from '@/types/crm';
import { PriceAgreementForm } from './form';

vi.mock('@/api/crm/pricingRequests', () => ({
  pricingRequestsApi: { create: vi.fn(), createReplacement: vi.fn(), offer: vi.fn() },
}));
vi.mock('@/api/crm/priceAgreements', () => ({ priceAgreementsApi: { forCustomer: vi.fn() } }));
vi.mock('@/api/crm/products', () => ({ productsApi: { list: vi.fn(), show: vi.fn() } }));
vi.mock('@/api/crm/customers', () => ({ crmCustomersApi: { list: vi.fn(), show: vi.fn() } }));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({ draftState: { hasDraft: false }, allowNavigationAfterSave: vi.fn() }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('@/lib/realtime/invalidationMap', () => ({ invalidateRealtimeChange: vi.fn() }));

const product = {
  id: 'product-hash',
  part_number: 'PC-001',
  name: 'Pivot Cap Cover Type A',
  unit_of_measure: 'pcs',
  base_selling_price: '35.00',
  minimum_selling_price: '15.00',
};
const customer = { id: 'customer-hash', name: 'Summit Components' };
const agreement = {
  id: 'agreement-hash',
  product,
  customer,
  price: '35.00',
  effective_from: '2026-11-12',
  effective_to: '2027-11-12',
  pricing_method: 'tiered',
  pricing_method_label: 'Volume tiers',
  tiers: [{ min_qty: 100, unit_price: '34.00' }],
  is_currently_active: true,
  created_at: '2026-11-12T00:00:00Z',
  updated_at: '2026-11-12T00:00:00Z',
} as PriceAgreement;

function renderReplacementForm(initialAgreement = agreement) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={queryClient}>
        <PriceAgreementForm mode="edit" initial={initialAgreement} />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.mocked(productsApi.list).mockResolvedValue({ data: [product] } as never);
  vi.mocked(productsApi.show).mockResolvedValue(product as never);
  vi.mocked(crmCustomersApi.list).mockResolvedValue({ data: [customer] } as never);
  vi.mocked(crmCustomersApi.show).mockResolvedValue(customer as never);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('replacement price agreement parties', () => {
  it('shows the existing customer and product as read-only references', async () => {
    const { container } = renderReplacementForm();

    expect(screen.queryByRole('combobox', { name: 'Customer' })).not.toBeInTheDocument();
    expect(screen.queryByRole('combobox', { name: 'Product' })).not.toBeInTheDocument();
    expect(screen.getByRole('textbox', { name: 'Customer' })).toHaveAttribute('readonly');
    expect(screen.getByRole('textbox', { name: 'Customer' })).toHaveValue('Summit Components');
    expect(screen.getByRole('textbox', { name: 'Product' })).toHaveAttribute('readonly');
    expect(screen.getByRole('textbox', { name: 'Product' })).toHaveValue('PC-001 · Pivot Cap Cover Type A');
    expect(container.querySelector('input[name="customer_id"]')).toHaveValue('customer-hash');
    expect(container.querySelector('input[name="product_id"]')).toHaveValue('product-hash');
  });

  it('defaults a replacement to a date after the source agreement starts', () => {
    const { container } = renderReplacementForm({
      ...agreement,
      effective_from: '2099-12-31',
      effective_to: '2100-12-31',
    } as PriceAgreement);
    const effectiveFrom = container.querySelector<HTMLInputElement>('input[name="effective_from"]');
    const effectiveTo = container.querySelector<HTMLInputElement>('input[name="effective_to"]');

    expect(effectiveFrom).toHaveValue('2100-01-01');
    expect(effectiveFrom).toHaveAttribute('min', '2100-01-01');
    expect(effectiveTo).toHaveValue('2100-12-31');
    expect(effectiveTo).toHaveAttribute('min', '2100-01-01');
  });
});
