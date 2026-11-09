import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { crmCustomersApi } from '@/api/crm/customers';
import { productsApi } from '@/api/crm/products';
import { usePermission } from '@/hooks/usePermission';
import { CustomerProductAccessManager } from './CustomerProductAccessManager';
import { CustomerWarrantyOptionsManager } from './CustomerWarrantyOptionsManager';

vi.mock('@/api/crm/customers', () => ({ crmCustomersApi: {
  productAccess: vi.fn(), updateProductAccess: vi.fn(), warrantyOptions: vi.fn(), createWarrantyOption: vi.fn(), deleteWarrantyOption: vi.fn(),
} }));
vi.mock('@/api/crm/products', () => ({ productsApi: { options: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: vi.fn() }));

function renderWithQuery(ui: React.ReactNode) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(usePermission).mockReturnValue({ can: () => true } as never);
  vi.mocked(crmCustomersApi.productAccess).mockResolvedValue({
    portal_catalog_mode: 'restricted', product_ids: ['product-1'], products: [
      { id: 'product-1', part_number: 'PN-1', name: 'Honda Lamp', unit_of_measure: 'pcs', image_url: null, portal_visibility: 'standard', is_explicitly_authorized: true, is_authorized: true },
      { id: 'product-2', part_number: 'PN-2', name: 'Honda Mirror', unit_of_measure: 'pcs', image_url: null, portal_visibility: 'restricted', is_explicitly_authorized: false, is_authorized: false },
    ],
  });
  vi.mocked(crmCustomersApi.updateProductAccess).mockResolvedValue({ portal_catalog_mode: 'restricted', product_ids: ['product-1', 'product-2'], products: [] });
  vi.mocked(crmCustomersApi.warrantyOptions).mockResolvedValue([]);
  vi.mocked(crmCustomersApi.createWarrantyOption).mockResolvedValue({
    id: 'warranty-1', product: { id: 'product-1', part_number: 'PN-1', name: 'Honda Lamp', unit_of_measure: 'pcs' },
    label: 'One year', warranty_duration_days: 365, warranty_report_window_days: 30, warranty_start_basis: 'delivery',
    warranty_covered_issues: ['manufacturing_defect'], warranty_remedies: ['repair'], additional_terms: null,
  });
  vi.mocked(productsApi.options).mockResolvedValue({ data: [{ id: 'product-1', part_number: 'PN-1', name: 'Honda Lamp', unit_of_measure: 'pcs' }] } as never);
});
afterEach(cleanup);

describe('customer agreement setup', () => {
  it('lets staff choose the exact restricted catalog products', async () => {
    renderWithQuery(<CustomerProductAccessManager customerId="customer-1" />);

    const mirror = await screen.findByRole('checkbox', { name: /Honda Mirror/ });
    fireEvent.click(mirror);
    fireEvent.click(screen.getByRole('button', { name: 'Save access' }));

    await waitFor(() => expect(crmCustomersApi.updateProductAccess).toHaveBeenCalledWith('customer-1', {
      portal_catalog_mode: 'restricted', product_ids: ['product-1', 'product-2'],
    }));
  });

  it('adds a customer and product-specific warranty choice with free-form other terms', async () => {
    renderWithQuery(<CustomerWarrantyOptionsManager customerId="customer-1" />);
    const form = screen.getByRole('form', { name: 'Add product warranty choice' });
    await within(form).findByRole('option', { name: /PN-1/ });
    const productSelects = await within(form).findAllByRole('combobox');
    fireEvent.change(productSelects[0], { target: { value: 'product-1' } });
    expect(productSelects[0]).toHaveValue('product-1');
    fireEvent.change(screen.getByLabelText(/Warranty option name/), { target: { value: 'One year' } });
    fireEvent.change(screen.getByLabelText('Warranty duration (days)'), { target: { value: '365' } });
    fireEvent.change(screen.getByLabelText('Report window (days)'), { target: { value: '30' } });
    fireEvent.change(screen.getByLabelText('Other agreement terms (optional)'), { target: { value: 'Replacement parts included.' } });
    await waitFor(() => expect(screen.getByRole('button', { name: 'Add warranty choice' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Add warranty choice' }));

    await waitFor(() => expect(crmCustomersApi.createWarrantyOption).toHaveBeenCalledWith('customer-1', 'product-1', expect.objectContaining({
      label: 'One year', warranty_duration_days: 365, warranty_report_window_days: 30,
      warranty_start_basis: 'delivery', additional_terms: 'Replacement parts included.',
    })));
  });
});
