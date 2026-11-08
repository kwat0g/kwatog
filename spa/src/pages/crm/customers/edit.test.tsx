import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { crmCustomersApi } from '@/api/crm/customers';
import type { Customer } from '@/types/accounting';
import CrmCustomerEditPage from './edit';

vi.mock('@/api/crm/customers', () => ({
  crmCustomersApi: { show: vi.fn(), update: vi.fn() },
}));

const customer = (isActive: boolean): Customer => ({
  id: 'customer-1',
  name: 'Test customer',
  code: 'C-001',
  contact_person: null,
  email: null,
  phone: null,
  address: null,
  delivery_address: null,
  delivery_contact_person: null,
  delivery_contact_phone: null,
  tin: null,
  credit_limit: null,
  credit_used: '0.00',
  credit_available: null,
  payment_terms_days: 30,
  allowed_payment_methods: [],
  shipping_payer: null,
  agreement_starts_on: null,
  agreement_ends_on: null,
  warranty_duration_days: null,
  warranty_report_window_days: null,
  warranty_start_basis: null,
  warranty_covered_issues: [],
  warranty_remedies: [],
  is_active: isActive,
});

describe('CrmCustomerEditPage mutation freshness', () => {
  beforeEach(() => vi.clearAllMocks());

  it('keeps the updated customer response in the exact detail cache before navigation', async () => {
    const before = customer(true);
    const updated = customer(false);
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    vi.mocked(crmCustomersApi.show).mockResolvedValue(before);
    vi.mocked(crmCustomersApi.update).mockResolvedValue(updated);

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/crm/customers/customer-1/edit']}>
          <Routes>
            <Route path="/crm/customers/:id/edit" element={<CrmCustomerEditPage />} />
            <Route path="/crm/customers/:id" element={<span>Customer profile</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByRole('textbox', { name: /Customer name/ });
    vi.mocked(crmCustomersApi.show).mockImplementation(() => new Promise(() => undefined));
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(queryClient.getQueryData(['crm', 'customers', 'detail', 'customer-1']))
      .toMatchObject({ is_active: false }));
  });
});
