import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { accountsApi } from '@/api/accounting/accounts';
import { customersApi } from '@/api/accounting/customers';
import { invoicesApi } from '@/api/accounting/invoices';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { uomsApi } from '@/api/inventory/uoms';
import type { Invoice } from '@/types/accounting';
import InvoiceFormPage from './create';

vi.mock('@/api/accounting/accounts', () => ({ accountsApi: { list: vi.fn() } }));
vi.mock('@/api/accounting/customers', () => ({ customersApi: { list: vi.fn() } }));
vi.mock('@/api/accounting/invoices', () => ({ invoicesApi: { create: vi.fn() } }));
vi.mock('@/api/businessPolicies', () => ({ businessPoliciesApi: { get: vi.fn() } }));
vi.mock('@/api/inventory/uoms', () => ({ uomsApi: { list: vi.fn() } }));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({
    allowNavigationAfterSave: vi.fn(),
    draftState: { hasDraft: false, draftAge: 0, restore: vi.fn(), discard: vi.fn() },
  }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdInvoice = {
  id: 'invoice-new',
  invoice_number: null,
  date: '2026-11-03',
  due_date: '2026-12-03',
  lifecycle_type: 'prebill',
  is_vatable: false,
  subtotal: '10.00',
  vat_amount: '0.00',
  total_amount: '10.00',
  amount_paid: '0.00',
  balance: '10.00',
  status: 'draft',
  display_status: 'Pending prebill approval',
  is_overdue: false,
  aging_bucket: 'current',
} as Invoice;

describe('Invoice create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(customersApi.list).mockResolvedValue({
      data: [{ id: 'customer-1', name: 'Test customer' }],
    } as never);
    vi.mocked(accountsApi.list).mockResolvedValue({
      data: [{ id: 'account-1', code: '4000', name: 'Sales revenue' }],
    } as never);
    vi.mocked(businessPoliciesApi.get).mockResolvedValue({
      vat_status: 'Not VAT Registered',
      vat_rate: null,
    } as never);
    vi.mocked(uomsApi.list).mockResolvedValue([]);
    vi.mocked(invoicesApi.create).mockResolvedValue(createdInvoice);
  });

  it('seeds the detail cache with the created invoice before navigating', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/accounting/invoices/create']}>
          <Routes>
            <Route path="/accounting/invoices/create" element={<InvoiceFormPage />} />
            <Route path="/accounting/invoices/:id" element={<span>Invoice detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Show Customer' }));
    fireEvent.click(await screen.findByRole('option', { name: 'Test customer' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Prebill approval reason' }), {
      target: { value: 'Immediate API response cache regression' },
    });
    fireEvent.change(screen.getByLabelText('Description for line 1'), {
      target: { value: 'Test item' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Show Revenue account for line 1' }));
    fireEvent.click(await screen.findByRole('option', { name: /4000.*Sales revenue/ }));
    fireEvent.change(screen.getByLabelText('Quantity for line 1'), { target: { value: '1' } });
    fireEvent.change(screen.getByLabelText('Unit price for line 1'), { target: { value: '10' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));

    await waitFor(() => expect(invoicesApi.create).toHaveBeenCalledOnce());
    expect(await screen.findByText('Invoice detail route')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData(['accounting', 'invoices', 'invoice-new']))
      .toEqual(createdInvoice));
    queryClient.clear();
  });
});
