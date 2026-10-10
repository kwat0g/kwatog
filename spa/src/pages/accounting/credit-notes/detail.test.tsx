import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { billsApi } from '@/api/accounting/bills';
import { creditNotesApi } from '@/api/accounting/credit-notes';
import { invoicesApi } from '@/api/accounting/invoices';
import type { PaginatedResponse } from '@/types';
import type { CreditNote, Invoice } from '@/types/accounting';
import CreditNoteDetailPage from './detail';

vi.mock('@/api/accounting/credit-notes', () => ({
  creditNotesApi: { show: vi.fn(), finalize: vi.fn(), apply: vi.fn() },
}));
vi.mock('@/api/accounting/invoices', () => ({ invoicesApi: { list: vi.fn() } }));
vi.mock('@/api/accounting/bills', () => ({ billsApi: { list: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));

const creditNote = {
  id: 'credit-1',
  credit_note_number: 'CN-1',
  type: 'customer',
  type_label: 'Customer credit',
  status: 'finalized',
  status_label: 'Finalized',
  date: '2026-10-02',
  is_vatable: false,
  subtotal: '100.00',
  vat_amount: '0.00',
  total_amount: '100.00',
  applied_amount: '0.00',
  balance: '100.00',
  reason: 'Returned goods',
  customer: { id: 'customer-1', name: 'Test customer' },
  invoice: { id: 'paid-invoice', invoice_number: 'INV-1' },
  lines: [],
  applications: [],
} satisfies CreditNote;

const supplierCreditNote = {
  ...creditNote,
  type: 'supplier',
  type_label: 'Supplier credit',
  customer: null,
  invoice: null,
  vendor: { id: 'vendor-1', name: 'Test vendor' },
  bill: { id: 'paid-bill', bill_number: 'BILL-1' },
} satisfies CreditNote;

const page = <T,>(data: T[]): PaginatedResponse<T> => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length, from: null, to: null },
  links: { first: '', last: '', prev: null, next: null },
});

function renderPage(value: CreditNote = creditNote) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  vi.mocked(creditNotesApi.show).mockResolvedValue(value);

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/accounting/credit-notes/credit-1']}>
        <Routes>
          <Route path="/accounting/credit-notes/:id" element={<CreditNoteDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('customer credit without open invoices', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(invoicesApi.list).mockResolvedValue(page<Invoice>([]));
    vi.mocked(billsApi.list).mockResolvedValue(page([]));
  });

  it.each([
    [creditNote, 'Apply to invoice', /No finalized or partially paid invoices are available for this customer/],
    [supplierCreditNote, 'Apply to bill', /No unpaid or partially paid bills are available for this vendor/],
  ] as const)('explains when %s has no eligible document', async (value, action, message) => {
    renderPage(value);

    fireEvent.click(await screen.findByRole('button', { name: action }));

    expect(await screen.findByText(message)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Apply' })).not.toBeInTheDocument();
  });

  it('shows a loading state while fetching eligible documents', async () => {
    vi.mocked(invoicesApi.list).mockReturnValue(new Promise<PaginatedResponse<Invoice>>(() => {}));
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Apply to invoice' }));

    expect(await screen.findByRole('status')).toHaveTextContent('Loading eligible documents');
  });

  it('shows an error and retries the eligible-document request', async () => {
    const openInvoice = {
      id: 'invoice-2',
      invoice_number: 'INV-2',
      date: '2026-10-02',
      due_date: '2026-11-01',
      is_vatable: false,
      subtotal: '25.00',
      vat_amount: '0.00',
      total_amount: '25.00',
      amount_paid: '0.00',
      balance: '25.00',
      status: 'finalized',
      display_status: 'Finalized',
      is_overdue: false,
      aging_bucket: 'current',
      remarks: null,
    } satisfies Invoice;
    vi.mocked(invoicesApi.list)
      .mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce(page([openInvoice]));
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Apply to invoice' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load eligible documents');

    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(await screen.findByRole('option', { name: /INV-2/ })).toBeInTheDocument();
  });
  it('keeps one operation key across a failed application retry', async () => {
    const invoice = {
      id: 'invoice-retry', invoice_number: 'INV-RETRY', date: '2026-10-02', due_date: '2026-11-01',
      is_vatable: false, subtotal: '100.00', vat_amount: '0.00', total_amount: '100.00', amount_paid: '0.00',
      balance: '100.00', status: 'finalized', display_status: 'Finalized', is_overdue: false, aging_bucket: 'current', remarks: null,
    } satisfies Invoice;
    vi.mocked(invoicesApi.list).mockResolvedValue(page([invoice]));
    vi.mocked(creditNotesApi.apply).mockRejectedValue(new Error('Response lost'));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Apply to invoice' }));
    await screen.findByRole('option', { name: /INV-RETRY/ });
    fireEvent.change(screen.getByLabelText(/Open invoice/), { target: { value: invoice.id } });
    fireEvent.change(screen.getByLabelText(/Amount/), { target: { value: '10' } });
    fireEvent.click(screen.getByRole('button', { name: /^Apply$/ }));
    await waitFor(() => expect(creditNotesApi.apply).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.getByRole('button', { name: /^Apply$/ })).not.toBeDisabled());
    fireEvent.click(screen.getByRole('button', { name: /^Apply$/ }));
    await waitFor(() => expect(creditNotesApi.apply).toHaveBeenCalledTimes(2));
    const first = vi.mocked(creditNotesApi.apply).mock.calls[0][1];
    const retry = vi.mocked(creditNotesApi.apply).mock.calls[1][1];
    expect(first.operation_key).toMatch(/^[0-9a-f-]{36}$/i);
    expect(retry).toEqual(first);
  });

});
