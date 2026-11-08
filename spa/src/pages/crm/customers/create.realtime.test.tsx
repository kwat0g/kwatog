import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { crmCustomersApi } from '@/api/crm/customers';
import type { Customer } from '@/types/accounting';
import CrmCustomerCreatePage from './create';

vi.mock('@/api/businessPolicies', () => ({ businessPoliciesApi: { get: vi.fn() } }));
vi.mock('@/api/crm/customers', () => ({ crmCustomersApi: { create: vi.fn() } }));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({
    allowNavigationAfterSave: vi.fn(),
    draftState: { hasDraft: false, draftAge: 0, restore: vi.fn(), discard: vi.fn() },
  }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdCustomer = {
  id: 'customer-new',
  name: 'Test customer',
  is_active: true,
  payment_terms_days: 30,
  warranty_duration_days: 180,
  warranty_report_window_days: 30,
} as Customer;

describe('CRM customer create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(businessPoliciesApi.get).mockResolvedValue({
      customer_payment_terms_days: 30,
    } as never);
    vi.mocked(crmCustomersApi.create).mockResolvedValue(createdCustomer);
  });

  it('seeds the CRM detail cache with the create response before navigating', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/create']}>
          <Routes>
            <Route path="/crm/customers/create" element={<CrmCustomerCreatePage />} />
            <Route path="/crm/customers/:id" element={<span>CRM customer detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(screen.getByRole('textbox', { name: 'Customer name' }), {
      target: { value: 'Test customer' },
    });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Warranty duration (days)' }), {
      target: { value: '180' },
    });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Report within (days)' }), {
      target: { value: '30' },
    });
    await waitFor(() => expect(screen.getByRole('spinbutton', { name: 'Payment terms (days)' }))
      .toHaveValue(30));
    fireEvent.click(screen.getByRole('button', { name: 'Create customer' }));

    await waitFor(() => expect(crmCustomersApi.create).toHaveBeenCalledOnce());
    expect(await screen.findByText('CRM customer detail route')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData([
      'crm', 'customers', 'detail', 'customer-new',
    ])).toEqual(createdCustomer));
    queryClient.clear();
  });
});
