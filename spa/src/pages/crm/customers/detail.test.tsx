import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmCustomersApi } from '@/api/crm/customers';
import { usePermission } from '@/hooks/usePermission';
import CrmCustomerDetailPage from './detail';

vi.mock('@/api/crm/customers', () => ({ crmCustomersApi: { show: vi.fn(), productAccess: vi.fn(), warrantyOptions: vi.fn() } }));
vi.mock('@/api/crm/salesOrders', () => ({ salesOrdersApi: { list: vi.fn() } }));
vi.mock('@/api/crm/complaints', () => ({ complaintsApi: { list: vi.fn() } }));
vi.mock('@/api/crm/priceAgreements', () => ({ priceAgreementsApi: { forCustomer: vi.fn() } }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn() }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: vi.fn() }));
vi.mock('@/components/crm/CustomerProductAccessManager', () => ({ CustomerProductAccessManager: () => <div>Product access setup</div> }));
vi.mock('@/components/crm/CustomerWarrantyOptionsManager', () => ({ CustomerWarrantyOptionsManager: () => <div>Product warranty setup</div> }));
vi.mock('@/components/contracts/AgreementDocumentManager', () => ({ AgreementDocumentManager: ({ previewValues }: { previewValues: Record<string, string | null | undefined> }) => <div><span>Agreement documents</span><pre data-testid="agreement-preview">{JSON.stringify(previewValues)}</pre></div> }));

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/crm/customers/customer-1']}>
        <Routes><Route path="/crm/customers/:id" element={<CrmCustomerDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('CRM customer detail', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(usePermission).mockReturnValue({ can: () => false } as never);
    vi.mocked(crmCustomersApi.show).mockResolvedValue({
      id: 'customer-1', name: 'Northstar Mobility', is_active: true,
      contact_person: 'Mina Santos', contact_person_role: 'Procurement Manager',
      email: 'mina@example.test', phone: '+63 2 8123 4567', address: 'Billing address, Makati',
      delivery_address: 'Receiving gate, Laguna', delivery_latitude: 14.25, delivery_longitude: 121.1,
      payment_terms_days: 30, credit_limit: '0.00', allowed_payment_methods: null, shipping_payer: 'customer',
      warranty_duration_days: null, warranty_report_window_days: null,
      agreement_starts_on: null, agreement_ends_on: null,
    } as never);
    vi.mocked(crmCustomersApi.productAccess).mockResolvedValue({
      portal_catalog_mode: 'restricted', order_limits_supported: false, product_ids: ['product-1'], products: [
        { id: 'product-1', part_number: 'PN-900', name: 'Rear lamp assembly', unit_of_measure: 'pcs', image_url: null, portal_visibility: 'restricted', is_explicitly_authorized: true, is_authorized: true, minimum_order_quantity: '1', maximum_order_quantity: null },
      ],
    });
    vi.mocked(crmCustomersApi.warrantyOptions).mockResolvedValue([{
      id: 'warranty-1', product: { id: 'product-1', part_number: 'PN-900', name: 'Rear lamp assembly', unit_of_measure: 'pcs' },
      label: '12-month standard', warranty_duration_days: 365, warranty_report_window_days: 30,
      warranty_start_basis: 'delivery', warranty_covered_issues: ['manufacturing_defect'],
      warranty_remedies: ['repair', 'replace'], additional_terms: null,
    }]);
  });

  it('shows the customer contact role and the saved map pin beside delivery details', async () => {
    renderPage();

    expect(await screen.findByText('Procurement Manager')).toBeVisible();
    const creditLimitLabel = screen.getByText('Credit limit (optional)');
    expect(creditLimitLabel.parentElement).toHaveTextContent('Not set');
    expect(creditLimitLabel.parentElement).not.toHaveTextContent('₱0.00');
    expect(screen.getByRole('link', { name: 'Open delivery pin in map' })).toHaveAttribute(
      'href',
      'https://www.openstreetmap.org/?mlat=14.25&mlon=121.1#map=17/14.25/121.1',
    );
  });

  it('keeps setup tools collapsed until staff opens the ordering section', async () => {
    renderPage();

    expect((await screen.findAllByText('Product ordering setup'))[0]).toBeVisible();
    expect(screen.getByText('Product access setup')).not.toBeVisible();
    expect(screen.getByText('Product warranty setup')).not.toBeVisible();
    fireEvent.click(screen.getAllByText('Product ordering setup')[0]);
    expect(screen.getByText('Product access setup')).toBeVisible();
    expect(screen.getByText('Product warranty setup')).toBeVisible();
  });

  it('previews the mapped delivery, product, warranty, payment, and freight values for agreement generation', async () => {
    renderPage();

    fireEvent.click((await screen.findAllByText('Agreement documents'))[0]);
    const preview = await screen.findByTestId('agreement-preview');
    const values = JSON.parse(preview.textContent ?? '{}') as Record<string, string | null>;
    expect(values.delivery_map_pin).toBe('14.2500000, 121.1000000');
    expect(values.allowed_products_summary).toContain('PN-900 · Rear lamp assembly');
    expect(values.warranty_summary).toContain('12-month standard');
    expect(values.allowed_payment_methods).toBe('All supported payment methods');
    expect(values.shipping_payer).toBe('Customer');
  });
});
