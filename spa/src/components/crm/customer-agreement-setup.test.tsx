import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { crmCustomersApi } from '@/api/crm/customers';
import { productsApi } from '@/api/crm/products';
import { usePermission } from '@/hooks/usePermission';
import { CustomerProductAccessManager } from './CustomerProductAccessManager';
import { CustomerWarrantyOptionsManager } from './CustomerWarrantyOptionsManager';

vi.mock('@/api/crm/customers', () => ({ crmCustomersApi: {
  productAccess: vi.fn(), updateProductAccess: vi.fn(), productOrderLimitCapability: vi.fn(), warrantyOptions: vi.fn(), createWarrantyOption: vi.fn(), updateWarrantyOption: vi.fn(), deleteWarrantyOption: vi.fn(),
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
    portal_catalog_mode: 'restricted', order_limits_supported: true, product_ids: ['product-1'], products: [
      { id: 'product-1', part_number: 'PN-1', name: 'Honda Lamp', unit_of_measure: 'pcs', image_url: null, portal_visibility: 'standard', is_explicitly_authorized: true, is_authorized: true, minimum_order_quantity: '1.000', maximum_order_quantity: null },
      { id: 'product-2', part_number: 'PN-2', name: 'Honda Mirror', unit_of_measure: 'pcs', image_url: null, portal_visibility: 'restricted', is_explicitly_authorized: false, is_authorized: false, minimum_order_quantity: '1.000', maximum_order_quantity: null },
    ],
  });
  vi.mocked(crmCustomersApi.updateProductAccess).mockResolvedValue({ portal_catalog_mode: 'restricted', order_limits_supported: true, product_ids: ['product-1', 'product-2'], products: [] });
  vi.mocked(crmCustomersApi.warrantyOptions).mockResolvedValue([]);
  vi.mocked(crmCustomersApi.createWarrantyOption).mockResolvedValue({
    id: 'warranty-1', product: { id: 'product-1', part_number: 'PN-1', name: 'Honda Lamp', unit_of_measure: 'pcs' },
    label: 'One year', warranty_duration_days: 365, warranty_report_window_days: 30, warranty_start_basis: 'delivery',
    warranty_covered_issues: ['manufacturing_defect'], warranty_remedies: ['repair'], additional_terms: null,
  });
  vi.mocked(crmCustomersApi.updateWarrantyOption).mockImplementation(async (_customerId, _productId, _optionId, data) => ({
    id: 'warranty-1', product: { id: 'product-1', part_number: 'PN-1', name: 'Honda Lamp', unit_of_measure: 'pcs' }, ...data,
  }));
  vi.mocked(productsApi.options).mockResolvedValue({ data: [
    { id: 'product-1', part_number: 'PN-1', name: 'Honda Lamp', unit_of_measure: 'pcs' },
    { id: 'product-2', part_number: 'PN-2', name: 'Honda Mirror', unit_of_measure: 'pcs' },
  ] } as never);
});
afterEach(cleanup);

describe('customer agreement setup', () => {
  it('lets staff choose the exact restricted catalog products', async () => {
    renderWithQuery(<CustomerProductAccessManager customerId="customer-1" />);

    const mirror = await screen.findByRole('checkbox', { name: /Honda Mirror/ });
    fireEvent.click(mirror);
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Minimum order quantity for PN-1' }), { target: { value: '5' } });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Maximum order quantity for PN-2 (optional)' }), { target: { value: '80' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save access' }));

    await waitFor(() => expect(crmCustomersApi.updateProductAccess).toHaveBeenCalledWith('customer-1', {
      portal_catalog_mode: 'restricted', product_ids: ['product-1', 'product-2'], product_order_limits: [
        { product_id: 'product-1', minimum_order_quantity: 5, maximum_order_quantity: null },
        { product_id: 'product-2', minimum_order_quantity: 1, maximum_order_quantity: 80 },
      ],
    }));
  });

  it('adds a customer and product-specific warranty choice with free-form other terms', async () => {
    renderWithQuery(<CustomerWarrantyOptionsManager customerId="customer-1" />);
    fireEvent.click(await screen.findByRole('button', { name: 'Add warranty choice' }));
    const form = screen.getByRole('form', { name: 'Add product warranty choice' });
    await within(form).findByRole('option', { name: /PN-1/ });
    expect(within(form).queryByRole('option', { name: /PN-2/ })).not.toBeInTheDocument();
    expect(await screen.findByRole('status')).toHaveTextContent(/1 of 1 authorized product has no product-specific warranty choice yet/);
    const productSelects = await within(form).findAllByRole('combobox');
    fireEvent.change(productSelects[0], { target: { value: 'product-1' } });
    expect(productSelects[0]).toHaveValue('product-1');
    fireEvent.change(screen.getByLabelText(/Warranty option name/), { target: { value: 'One year' } });
    fireEvent.change(screen.getByLabelText('Warranty duration (days)'), { target: { value: '365' } });
    fireEvent.change(screen.getByLabelText('Report window (days)'), { target: { value: '30' } });
    fireEvent.click(within(form).getByRole('checkbox', { name: 'Other' }));
    expect(within(form).getByRole('textbox', { name: 'Describe other covered issue' })).toBeRequired();
    expect(within(form).getByRole('button', { name: 'Add warranty choice' })).toBeDisabled();
    fireEvent.change(within(form).getByRole('textbox', { name: 'Describe other covered issue' }), { target: { value: 'Thread corrosion' } });
    fireEvent.change(screen.getByLabelText('Other agreement terms (optional)'), { target: { value: 'Replacement parts included.' } });
    await waitFor(() => expect(screen.getByRole('button', { name: 'Add warranty choice' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Add warranty choice' }));

    await waitFor(() => expect(crmCustomersApi.createWarrantyOption).toHaveBeenCalledWith('customer-1', 'product-1', expect.objectContaining({
      label: 'One year', warranty_duration_days: 365, warranty_report_window_days: 30,
      warranty_start_basis: 'delivery', additional_terms: 'Other covered issue: Thread corrosion\nReplacement parts included.',
    })));
  });

  it('keeps the add form collapsed when all products already have warranty choices and allows editing a saved choice', async () => {
    vi.mocked(crmCustomersApi.warrantyOptions).mockResolvedValue([{
      id: 'warranty-1', product: { id: 'product-1', part_number: 'PN-1', name: 'Honda Lamp', unit_of_measure: 'pcs' },
      label: 'One year', warranty_duration_days: 365, warranty_report_window_days: 30, warranty_start_basis: 'delivery',
      warranty_covered_issues: ['manufacturing_defect'], warranty_remedies: ['repair'],
      additional_terms: 'Other covered issue: Thread corrosion\nReplacement parts included.',
    }]);
    renderWithQuery(<CustomerWarrantyOptionsManager customerId="customer-1" />);

    expect(await screen.findByText('One year · 365 days · starts from delivery')).toBeVisible();
    expect(screen.queryByRole('form', { name: 'Add product warranty choice' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add another warranty choice' })).toBeVisible();

    fireEvent.click(screen.getByRole('button', { name: 'Edit One year for Honda Lamp' }));
    const form = screen.getByRole('form', { name: 'Edit product warranty choice' });
    expect(within(form).getByRole('textbox', { name: 'Warranty option name' })).toHaveValue('One year');
    expect(within(form).getByRole('spinbutton', { name: 'Warranty duration (days)' })).toHaveValue(365);
    expect(within(form).getByRole('checkbox', { name: 'Other' })).toBeChecked();
    expect(within(form).getByRole('textbox', { name: 'Describe other covered issue' })).toHaveValue('Thread corrosion');
    expect(within(form).getByRole('textbox', { name: 'Other agreement terms (optional)' })).toHaveValue('Replacement parts included.');

    fireEvent.change(within(form).getByRole('textbox', { name: 'Warranty option name' }), { target: { value: 'Two years' } });
    fireEvent.change(within(form).getByRole('spinbutton', { name: 'Warranty duration (days)' }), { target: { value: '730' } });
    fireEvent.click(within(form).getByRole('button', { name: 'Save warranty changes' }));

    await waitFor(() => expect(crmCustomersApi.updateWarrantyOption).toHaveBeenCalledWith('customer-1', 'product-1', 'warranty-1', expect.objectContaining({
      label: 'Two years', warranty_duration_days: 730, warranty_report_window_days: 30,
      warranty_start_basis: 'delivery', warranty_covered_issues: ['manufacturing_defect'], warranty_remedies: ['repair'],
      additional_terms: 'Other covered issue: Thread corrosion\nReplacement parts included.',
    })));
  });

  it('prefills a product warranty form from an agreement clause only when its part number matches an authorized product', async () => {
    const agreementSuggestions = [{
      productReference: 'PN-1', label: 'One year standard', durationDays: 365, reportWindowDays: 30,
      startBasis: 'delivery' as const, coveredIssues: ['manufacturing_defect' as const], remedies: ['repair' as const],
    }];
    renderWithQuery(<CustomerWarrantyOptionsManager customerId="customer-1" agreementSuggestions={agreementSuggestions} />);
    const useSuggestion = await screen.findByRole('button', { name: 'Use detected warranty for PN-1' });
    fireEvent.click(useSuggestion);

    const form = screen.getByRole('form', { name: 'Add product warranty choice' });
    const productSelect = within(form).getByRole('combobox', { name: 'Product' });
    expect(productSelect).toHaveValue('product-1');
    expect(within(form).getByRole('textbox', { name: 'Warranty option name' })).toHaveValue('One year standard');
    expect(within(form).getByRole('spinbutton', { name: 'Warranty duration (days)' })).toHaveValue(365);
    expect(within(form).getByRole('spinbutton', { name: 'Report window (days)' })).toHaveValue(30);

    fireEvent.click(within(form).getByRole('button', { name: 'Add warranty choice' }));
    await waitFor(() => expect(crmCustomersApi.createWarrantyOption).toHaveBeenCalledWith('customer-1', 'product-1', expect.objectContaining({
      label: 'One year standard', warranty_duration_days: 365, warranty_report_window_days: 30,
      warranty_start_basis: 'delivery', warranty_covered_issues: ['manufacturing_defect'], warranty_remedies: ['repair'],
    })));
  });

  it('does not match different part numbers after punctuation is changed', async () => {
    renderWithQuery(<CustomerWarrantyOptionsManager customerId="customer-1" agreementSuggestions={[{
      productReference: 'PN_1', label: 'One year standard', durationDays: 365,
      startBasis: 'delivery', coveredIssues: [], remedies: [],
    }]} />);

    expect(await screen.findByText(/No authorized catalog product matches this part number/)).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Use detected warranty for PN_1' })).not.toBeInTheDocument();
  });
});
