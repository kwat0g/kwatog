import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SupplierInvoicesPage from './index';
import { supplierPortalApi } from '@/api/b2b/supplier';

vi.mock('@/api/b2b/supplier', () => ({
  supplierPortalApi: {
    listInvoices: vi.fn(),
  },
}));

function renderPage(): void {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/portal/supplier/invoices']}>
        <SupplierInvoicesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('supplier invoice status filter', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(supplierPortalApi.listInvoices).mockResolvedValue({
      data: [],
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
        from: null,
        to: null,
      },
      links: { first: '', last: '', prev: null, next: null },
    });
  });

  it('offers only statuses from the supplier-visible invoice contract', async () => {
    renderPage();

    const statusFilter = await screen.findByRole('combobox', { name: 'Status' });
    const options = within(statusFilter).getAllByRole('option');

    expect(options.map((option) => ({
      value: option.getAttribute('value'),
      label: option.textContent,
    }))).toEqual([
      { value: '', label: 'Status: All' },
      { value: 'submitted', label: 'Submitted' },
      { value: 'unpaid', label: 'Unpaid' },
      { value: 'partial', label: 'Partially paid' },
      { value: 'paid', label: 'Paid' },
      { value: 'requires_correction', label: 'Requires correction' },
      { value: 'voided', label: 'Voided' },
    ]);
    expect(screen.queryByRole('option', { name: 'Draft' })).not.toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Cancelled' })).not.toBeInTheDocument();
  });

  it('shows one supplier invoice for two receipt-level AP matches', async () => {
    vi.mocked(supplierPortalApi.listInvoices).mockResolvedValue({
      data: [{
        id: 'supplier-invoice-1', bill_number: '2 AP bills', supplier_invoice_number: 'SUP-100',
        supplier_invoice_date: '2026-10-08', date: '2026-10-08', total_amount: '11200.00',
        amount_paid: '0.00', balance: '11200.00', status: 'submitted', due_date: '2026-11-08',
        purchase_order: { id: 'po-1', po_number: 'PO-1' }, invoice_attachment: null,
        bills: [
          { id: 'bill-1', bill_number: 'AP-1', grn_number: 'GRN-1', total_amount: '6720.00', balance: '6720.00', status: 'draft' },
          { id: 'bill-2', bill_number: 'AP-2', grn_number: 'GRN-2', total_amount: '4480.00', balance: '4480.00', status: 'draft' },
        ],
      }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
      links: { first: '', last: '', prev: null, next: null },
    });
    renderPage();

    expect(await screen.findByText('SUP-100')).toBeInTheDocument();
    expect(screen.getByText('2 AP bills')).toBeInTheDocument();
    expect(screen.getByText(/1 invoices you have submitted/)).toBeInTheDocument();
  });
});
