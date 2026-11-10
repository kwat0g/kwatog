import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { customersApi } from '@/api/accounting/customers';
import { vendorsApi } from '@/api/accounting/vendors';
import { portalAccessApi } from '@/api/b2b/portal-access';
import PortalAccessPage from './portal-access';

vi.mock('@/api/accounting/customers', () => ({ customersApi: { list: vi.fn() } }));
vi.mock('@/api/accounting/vendors', () => ({ vendorsApi: { list: vi.fn() } }));
vi.mock('@/api/b2b/portal-access', () => ({
  portalAccessApi: {
    listCustomers: vi.fn(), listSuppliers: vi.fn(),
    inviteCustomer: vi.fn(), inviteSupplier: vi.fn(),
    resendCustomer: vi.fn(), resendSupplier: vi.fn(),
    deactivateCustomer: vi.fn(), deactivateSupplier: vi.fn(),
    reactivateCustomer: vi.fn(), reactivateSupplier: vi.fn(),
    revokeCustomerTokens: vi.fn(), revokeTokens: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useFeature', () => ({ useFeature: () => true }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn(), useRealtimeRecord: vi.fn() }));
vi.mock('@/hooks/useRealtimeSignal', () => ({ useRealtimeSignal: vi.fn() }));

const customerPage = (customers: Array<{ id: string; name: string; contact_person: string | null; email: string | null }>) => ({
  data: customers,
  meta: { current_page: 1, last_page: 1, per_page: 200, total: customers.length },
});

const emptyAccessPage = { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } };

function renderPage(tab: 'customers' | 'suppliers' = 'customers') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/accounting/portal-access?tab=${tab}`]}>
        <PortalAccessPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Portal access invitations', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(portalAccessApi.listCustomers).mockResolvedValue(emptyAccessPage as never);
    vi.mocked(portalAccessApi.listSuppliers).mockResolvedValue(emptyAccessPage as never);
    vi.mocked(customersApi.list).mockResolvedValue(customerPage([]) as never);
    vi.mocked(vendorsApi.list).mockResolvedValue(customerPage([]) as never);
    vi.mocked(portalAccessApi.inviteCustomer).mockResolvedValue({} as never);
    vi.mocked(portalAccessApi.inviteSupplier).mockResolvedValue({} as never);
  });

  it('requires manually entered customer details and allows the operator to designate a signatory', async () => {
    vi.mocked(customersApi.list).mockResolvedValue(customerPage([{
      id: 'customer-1', name: 'Honda Philippines', contact_person: 'Mika Sato', email: 'mika@honda.example',
    }]) as never);
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Invite customer' }));
    await screen.findByRole('option', { name: 'Honda Philippines' });
    fireEvent.change(await screen.findByRole('combobox', { name: 'Customer' }), { target: { value: 'customer-1' } });

    expect(screen.getByRole('textbox', { name: 'Contact name' })).toHaveProperty('value', '');
    expect(screen.getByRole('textbox', { name: 'Email' })).toHaveProperty('value', '');
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact name' }), { target: { value: 'Mika Sato' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Email' }), { target: { value: 'mika@honda.example' } });

    fireEvent.click(screen.getByRole('checkbox', { name: /authorized signatory/i }));
    fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }));

    await waitFor(() => expect(portalAccessApi.inviteCustomer).toHaveBeenCalledWith('customer-1', {
      name: 'Mika Sato', email: 'mika@honda.example', is_authorized_signatory: true,
    }));
  });

  it('keeps name and email editable when the selected customer record has no contact details', async () => {
    vi.mocked(customersApi.list).mockResolvedValue(customerPage([{
      id: 'customer-empty', name: 'Honda Parts', contact_person: null, email: null,
    }]) as never);
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Invite customer' }));
    await screen.findByRole('option', { name: 'Honda Parts' });
    fireEvent.change(await screen.findByRole('combobox', { name: 'Customer' }), { target: { value: 'customer-empty' } });

    expect(screen.getByRole('textbox', { name: 'Contact name' })).toHaveProperty('value', '');
    expect(screen.getByRole('textbox', { name: 'Email' })).toHaveProperty('value', '');
    expect(screen.getByRole('textbox', { name: 'Contact name' })).not.toHaveProperty('disabled', true);
    expect(screen.getByRole('textbox', { name: 'Email' })).not.toHaveProperty('disabled', true);
  });

  it('requires manually entered supplier details even when the vendor has contact data', async () => {
    vi.mocked(vendorsApi.list).mockResolvedValue(customerPage([{
      id: 'vendor-1', name: 'Supplier One', contact_person: 'Lee Santos', email: 'lee@supplier.example',
    }]) as never);
    renderPage('suppliers');

    fireEvent.click(await screen.findByRole('button', { name: 'Invite supplier' }));
    await screen.findByRole('option', { name: 'Supplier One' });
    fireEvent.change(await screen.findByRole('combobox', { name: 'Vendor' }), { target: { value: 'vendor-1' } });

    expect(screen.getByRole('textbox', { name: 'Contact name' })).toHaveProperty('value', '');
    expect(screen.getByRole('textbox', { name: 'Email' })).toHaveProperty('value', '');
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact name' }), { target: { value: 'Lee Santos' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Email' }), { target: { value: 'lee@supplier.example' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }));

    await waitFor(() => expect(portalAccessApi.inviteSupplier).toHaveBeenCalledWith('vendor-1', {
      name: 'Lee Santos', email: 'lee@supplier.example', is_authorized_signatory: false,
    }));
  });
});
