import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { customersApi } from '@/api/accounting/customers';
import { vendorsApi } from '@/api/accounting/vendors';
import { portalAccessApi } from '@/api/b2b/portal-access';
import PortalAccessPage from './portal-access';

const { permissions } = vi.hoisted(() => ({ permissions: new Set<string>() }));

vi.mock('@/api/accounting/customers', () => ({ customersApi: { list: vi.fn() } }));
vi.mock('@/api/accounting/vendors', () => ({ vendorsApi: { list: vi.fn() } }));
vi.mock('@/api/b2b/portal-access', () => ({
  portalAccessApi: {
    listCustomers: vi.fn(), listSuppliers: vi.fn(),
    inviteCustomer: vi.fn(), inviteSupplier: vi.fn(),
    resendCustomer: vi.fn(), resendSupplier: vi.fn(),
    deactivateCustomer: vi.fn(), deactivateSupplier: vi.fn(),
    reactivateCustomer: vi.fn(), reactivateSupplier: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: (permission: string) => permissions.has(permission) }) }));
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
    permissions.clear();
    permissions.add('b2b.portal_access.view');
    permissions.add('b2b.portal_access.manage');
    permissions.add('accounting.customers.manage');
    vi.mocked(portalAccessApi.listCustomers).mockResolvedValue(emptyAccessPage as never);
    vi.mocked(portalAccessApi.listSuppliers).mockResolvedValue(emptyAccessPage as never);
    vi.mocked(customersApi.list).mockResolvedValue(customerPage([]) as never);
    vi.mocked(vendorsApi.list).mockResolvedValue(customerPage([]) as never);
    vi.mocked(portalAccessApi.inviteCustomer).mockResolvedValue({} as never);
    vi.mocked(portalAccessApi.inviteSupplier).mockResolvedValue({} as never);
  });

  it('uses read-only customer contact details and allows the operator to designate a signatory', async () => {
    vi.mocked(portalAccessApi.listCustomers).mockResolvedValue({
      data: [{
        id: 'customer-portal-1', name: 'Mika Sato', email: 'mika@honda.example', is_active: true,
        is_authorized_signatory: false, must_change_password: true, resends_remaining_today: 2,
        status: 'pending', deleted_at: null, customer: { id: 'customer-1', name: 'Honda Philippines' },
        last_login_at: null, created_at: '2026-11-11T00:00:00Z',
      }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    } as never);
    vi.mocked(customersApi.list).mockResolvedValue(customerPage([{
      id: 'customer-1', name: 'Honda Philippines', contact_person: 'Mika Sato', email: 'mika@honda.example',
    }]) as never);
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Invite customer' }));
    await screen.findByRole('option', { name: 'Honda Philippines' });
    fireEvent.change(await screen.findByRole('combobox', { name: 'Customer' }), { target: { value: 'customer-1' } });

    expect(screen.getByRole('textbox', { name: 'Contact name' })).toHaveProperty('value', 'Mika Sato');
    expect(screen.getByRole('textbox', { name: 'Email' })).toHaveProperty('value', 'mika@honda.example');
    expect(screen.getByRole('textbox', { name: 'Contact name' })).toHaveProperty('readOnly', true);
    expect(screen.getByRole('textbox', { name: 'Email' })).toHaveProperty('readOnly', true);
    expect(screen.queryAllByRole('button', { name: 'Revoke sessions' })).toHaveLength(0);
    expect(screen.getAllByRole('button', { name: 'Resend (2 left today)' }).length).toBeGreaterThan(0);

    fireEvent.click(screen.getByRole('checkbox', { name: /authorized signatory/i }));
    fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }));

    await waitFor(() => expect(portalAccessApi.inviteCustomer).toHaveBeenCalledWith('customer-1', {
      name: 'Mika Sato', email: 'mika@honda.example', is_authorized_signatory: true,
    }));
  });

  it('requires missing customer contact details to be added to the customer record before inviting', async () => {
    vi.mocked(customersApi.list).mockResolvedValue(customerPage([
      { id: 'customer-filled', name: 'Honda Philippines', contact_person: 'Mika Sato', email: 'mika@honda.example' },
      { id: 'customer-empty', name: 'Honda Parts', contact_person: null, email: null },
    ]) as never);
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Invite customer' }));
    await screen.findByRole('option', { name: 'Honda Parts' });
    const customerSelect = screen.getByRole('combobox', { name: 'Customer' });
    fireEvent.change(customerSelect, { target: { value: 'customer-filled' } });
    expect(screen.getByRole('textbox', { name: 'Contact name' })).toHaveProperty('value', 'Mika Sato');
    fireEvent.change(customerSelect, { target: { value: 'customer-empty' } });

    expect(screen.getByRole('textbox', { name: 'Contact name' })).toHaveProperty('value', '');
    expect(screen.getByRole('textbox', { name: 'Email' })).toHaveProperty('value', '');
    expect(screen.getByRole('textbox', { name: 'Contact name' })).toHaveProperty('readOnly', true);
    expect(screen.getByRole('textbox', { name: 'Email' })).toHaveProperty('readOnly', true);
    expect(screen.getByText('Add a contact name and email to the selected customer record before sending an invitation.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Send invitation' })).toBeDisabled();
  });

  it('prefills supplier contact details from the selected vendor', async () => {
    vi.mocked(portalAccessApi.listSuppliers).mockResolvedValue({
      data: [{
        id: 'supplier-portal-1', name: 'Lee Santos', email: 'lee@supplier.example', is_active: true,
        is_authorized_signatory: false, must_change_password: true, resends_remaining_today: 1,
        status: 'pending', deleted_at: null, vendor: { id: 'vendor-1', name: 'Supplier One' },
        last_login_at: null, created_at: '2026-11-11T00:00:00Z',
      }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    } as never);
    vi.mocked(vendorsApi.list).mockResolvedValue(customerPage([{
      id: 'vendor-1', name: 'Supplier One', contact_person: 'Lee Santos', email: 'lee@supplier.example',
    }]) as never);
    renderPage('suppliers');

    fireEvent.click(await screen.findByRole('button', { name: 'Invite supplier' }));
    await screen.findByRole('option', { name: 'Supplier One' });
    fireEvent.change(await screen.findByRole('combobox', { name: 'Vendor' }), { target: { value: 'vendor-1' } });

    expect(screen.getByRole('textbox', { name: 'Contact name' })).toHaveProperty('value', 'Lee Santos');
    expect(screen.getByRole('textbox', { name: 'Email' })).toHaveProperty('value', 'lee@supplier.example');
    expect(screen.getByRole('textbox', { name: 'Contact name' })).toHaveProperty('readOnly', true);
    expect(screen.getByRole('textbox', { name: 'Email' })).toHaveProperty('readOnly', true);
    expect(screen.queryAllByRole('button', { name: 'Revoke sessions' })).toHaveLength(0);
    expect(screen.getAllByRole('button', { name: 'Resend (1 left today)' }).length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('button', { name: 'Send invitation' }));

    await waitFor(() => expect(portalAccessApi.inviteSupplier).toHaveBeenCalledWith('vendor-1', {
      name: 'Lee Santos', email: 'lee@supplier.example', is_authorized_signatory: false,
    }));
  });

  it('offers capped resends only to active accounts that still need first sign-in', async () => {
    vi.mocked(portalAccessApi.listSuppliers).mockResolvedValue({
      data: [
        {
          id: 'supplier-pending', name: 'Pending Contact', email: 'pending@supplier.example',
          is_active: true, must_change_password: true, resends_remaining_today: 0,
          is_authorized_signatory: false, status: 'pending', deleted_at: null,
          vendor: { id: 'vendor-pending', name: 'Pending Supplier' }, last_login_at: null, created_at: '2026-11-11T00:00:00Z',
        },
        {
          id: 'supplier-active', name: 'Active Contact', email: 'active@supplier.example',
          is_active: true, must_change_password: false, resends_remaining_today: 3,
          is_authorized_signatory: false, status: 'active', deleted_at: null,
          vendor: { id: 'vendor-active', name: 'Active Supplier' }, last_login_at: null, created_at: '2026-11-11T00:00:00Z',
        },
        {
          id: 'supplier-inactive-parent', name: 'Inactive Supplier Contact', email: 'inactive@supplier.example',
          is_active: true, must_change_password: true, resends_remaining_today: 3,
          is_authorized_signatory: false, status: 'inactive', deleted_at: null,
          vendor: { id: 'vendor-inactive', name: 'Inactive Supplier' }, last_login_at: null, created_at: '2026-11-11T00:00:00Z',
        },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 },
    } as never);
    renderPage('suppliers');

    const limitedResendButtons = await screen.findAllByRole('button', { name: 'Resend limit reached' });
    // DataTable renders responsive desktop and mobile action buttons.
    expect(limitedResendButtons).toHaveLength(2);
    limitedResendButtons.forEach((button) => expect(button).toBeDisabled());
    expect(screen.queryByRole('button', { name: 'Resend (3 left today)' })).not.toBeInTheDocument();
  });

  it('does not show delivery schedules as a Portal Access section', () => {
    renderPage();

    expect(screen.queryByRole('tab', { name: 'Delivery Schedules' })).not.toBeInTheDocument();
  });

  it('limits CRM customer managers to customer invitations and pending resends', async () => {
    permissions.clear();
    permissions.add('accounting.customers.manage');
    vi.mocked(portalAccessApi.listCustomers).mockResolvedValue({
      data: [{
        id: 'customer-pending', name: 'Mika Sato', email: 'mika@honda.example', is_active: true,
        is_authorized_signatory: false, must_change_password: true, resends_remaining_today: 2,
        status: 'pending', deleted_at: null, customer: { id: 'customer-1', name: 'Honda Philippines' },
        last_login_at: null, created_at: '2026-11-11T00:00:00Z',
      }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    } as never);
    renderPage('suppliers');

    expect(screen.getByRole('tab', { name: 'Customers' })).toHaveAttribute('aria-selected', 'true');
    expect(screen.queryByRole('tab', { name: 'Suppliers' })).not.toBeInTheDocument();
    expect(screen.queryByRole('tab', { name: 'Contract templates' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Invite customer' })).toBeInTheDocument();
    expect((await screen.findAllByRole('button', { name: 'Resend (2 left today)' })).length).toBeGreaterThan(0);
    expect(screen.queryAllByRole('button', { name: 'Deactivate' })).toHaveLength(0);
    expect(screen.queryAllByRole('button', { name: 'Reactivate' })).toHaveLength(0);
    expect(portalAccessApi.listSuppliers).not.toHaveBeenCalled();
  });
});
